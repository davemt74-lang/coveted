<?php
declare(strict_types=1);

require_once __DIR__ . '/invite_crm.php';

const COVETED_NEWSLETTER_SOURCE = 'Newsletter signup';

/**
 * Save a public newsletter opt-in as a normal CRM lead without pretending the
 * visitor submitted a membership request. Newsletter leads intentionally use
 * an empty event-interest list and can later be qualified or converted by
 * System Admin if the relationship develops.
 */
function coveted_newsletter_signup_submit(array $input, ?PDO $pdo = null): string
{
    $pdo ??= coveted_db();
    coveted_invite_crm_ensure_schema($pdo);

    // Honeypot: accept silently so bots do not learn which field caught them.
    if (trim((string)($input['company'] ?? '')) !== '') {
        return 'accepted';
    }

    $name = trim((string)($input['name'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $city = trim((string)($input['city'] ?? ''));

    if ($name === '' || mb_strlen($name) > 180 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
        throw new InvalidArgumentException('Enter your name.');
    }
    if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if (mb_strlen($city) > 180 || preg_match('/[\x00-\x1F\x7F]/u', $city) === 1) {
        throw new InvalidArgumentException('Enter a valid city.');
    }

    $ip = coveted_client_ip();
    $ipHash = $ip !== 'unknown' ? hash('sha256', 'newsletter|' . $ip) : null;

    if ($ipHash !== null) {
        $rate = $pdo->prepare(
            "SELECT COUNT(*)
             FROM invite_requests
             WHERE source_ip_hash = ?
               AND how_heard = ?
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
        );
        $rate->execute([$ipHash, COVETED_NEWSLETTER_SOURCE]);
        if ((int)$rate->fetchColumn() >= 8) {
            throw new InvalidArgumentException('Too many newsletter signups were submitted from this connection today. Try again tomorrow.');
        }
    }

    // Treat repeat opt-ins as idempotent. If Admin previously declined a
    // newsletter lead, a new explicit signup re-opens it as New while keeping
    // the original CRM record and notes intact.
    $existing = $pdo->prepare(
        "SELECT id, public_id, status
         FROM invite_requests
         WHERE email = ? AND how_heard = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 1"
    );
    $existing->execute([$email, COVETED_NEWSLETTER_SOURCE]);
    $row = $existing->fetch();

    if ($row) {
        if ((string)$row['status'] !== 'converted') {
            $pdo->prepare(
                "UPDATE invite_requests
                 SET full_name = ?,
                     city_other = CASE WHEN ? <> '' THEN ? ELSE city_other END,
                     status = 'new',
                     source_ip_hash = ?,
                     message = ?,
                     reviewed_by = NULL,
                     reviewed_at = NULL,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = ?"
            )->execute([
                $name,
                $city,
                $city,
                $ipHash,
                'Newsletter signup from the public landing page.',
                (int)$row['id'],
            ]);
        }

        try {
            coveted_audit(
                'newsletter_signup.refreshed',
                'invite_request',
                (string)$row['public_id'],
                ['email' => $email],
                null
            );
        } catch (Throwable $e) {
            error_log('Newsletter signup audit failed: ' . $e->getMessage());
        }

        return (string)$row['public_id'];
    }

    $publicId = coveted_uuid('lead');
    $stmt = $pdo->prepare(
        "INSERT INTO invite_requests
            (public_id,full_name,email,phone,city_id,city_other,event_interests_json,how_heard,message,status,source_ip_hash)
         VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?, 'new', ?)"
    );
    $stmt->execute([
        $publicId,
        $name,
        $email,
        $city !== '' ? $city : null,
        coveted_json([]),
        COVETED_NEWSLETTER_SOURCE,
        'Newsletter signup from the public landing page.',
        $ipHash,
    ]);

    try {
        coveted_audit(
            'newsletter_signup.created',
            'invite_request',
            $publicId,
            ['email' => $email, 'city' => $city !== '' ? $city : null],
            null
        );
    } catch (Throwable $e) {
        error_log('Newsletter signup audit failed: ' . $e->getMessage());
    }

    return $publicId;
}
