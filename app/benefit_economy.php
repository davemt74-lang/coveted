<?php
declare(strict_types=1);

require_once __DIR__ . '/campaigns.php';
require_once __DIR__ . '/notifications.php';

const COVETED_MEMBERSHIP_BENEFIT_LOCK = 'coveted:membership-benefits:v1';

function coveted_membership_benefit_try_lock(PDO $pdo): bool
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $stmt->execute([COVETED_MEMBERSHIP_BENEFIT_LOCK]);
    return (int)$stmt->fetchColumn() === 1;
}

function coveted_membership_benefit_unlock(PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([COVETED_MEMBERSHIP_BENEFIT_LOCK]);
    } catch (Throwable $e) {
        error_log('Coveted membership benefit lock release failed: ' . $e->getMessage());
    }
}

/** @return array<int,array<string,mixed>> */
function coveted_membership_benefit_targets(int $limit = 250): array
{
    $limit = max(1, min($limit, 1000));

    return coveted_db()->query(
        "SELECT
            c.id AS campaign_id,
            c.public_id AS campaign_public_id,
            c.title AS campaign_title,
            c.group_id,
            c.quantity_limit,
            c.per_user_limit,
            rt.title AS reward_title,
            gm.user_id,
            g.public_id AS group_public_id,
            g.name AS group_name
         FROM campaigns c
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         JOIN social_groups g ON g.id = c.group_id AND g.status = 'active'
         JOIN group_memberships gm
           ON gm.group_id = c.group_id
          AND gm.membership_status = 'active'
         JOIN users u ON u.id = gm.user_id AND u.status = 'active'
         WHERE c.owner_type = 'group'
           AND c.trigger_key = 'membership'
           AND c.status = 'active'
           AND rt.status = 'active'
           AND (c.starts_at IS NULL OR c.starts_at <= NOW())
           AND (c.ends_at IS NULL OR c.ends_at > NOW())
           AND (rt.starts_at IS NULL OR rt.starts_at <= NOW())
           AND (rt.expires_at IS NULL OR rt.expires_at > NOW())
           AND (
               c.quantity_limit IS NULL OR
               (SELECT COUNT(*) FROM reward_issuances qri
                WHERE qri.campaign_id = c.id AND qri.status <> 'cancelled') < c.quantity_limit
           )
           AND (
               c.per_user_limit IS NULL OR
               (SELECT COUNT(*) FROM reward_issuances uri
                WHERE uri.campaign_id = c.id
                  AND uri.user_id = gm.user_id
                  AND uri.status <> 'cancelled') < c.per_user_limit
           )
           AND NOT EXISTS (
               SELECT 1 FROM reward_issuances ri
               WHERE ri.campaign_id = c.id
                 AND ri.user_id = gm.user_id
                 AND ri.status <> 'cancelled'
           )
         ORDER BY c.created_at ASC, c.id ASC, gm.created_at ASC, gm.id ASC
         LIMIT {$limit}"
    )->fetchAll();
}

function coveted_membership_benefit_expected_skip(string $message): bool
{
    return in_array($message, [
        'Campaign distribution limit has been reached.',
        'Member campaign limit has been reached.',
        'Campaign is not active.',
        'Campaign has ended.',
        'Reward has expired.',
    ], true);
}

function coveted_membership_benefit_notify(array $target, array $issuance): void
{
    $issuanceRef = trim((string)($issuance['public_id'] ?? ''));
    if ($issuanceRef === '') {
        return;
    }

    try {
        coveted_notification_create(
            (int)$target['user_id'],
            'reward.membership_unlocked',
            'New group perk · ' . (string)$target['group_name'],
            (string)$target['reward_title'],
            '/benefits.php?box=ready',
            [
                'reward_issuance_id' => $issuanceRef,
                'campaign_id' => (string)$target['campaign_public_id'],
                'group_id' => (string)$target['group_public_id'],
            ],
            'normal',
            'membership-reward:' . $issuanceRef
        );
    } catch (Throwable $e) {
        error_log('Coveted membership reward notification failed: ' . $e->getMessage());
    }
}

/** @return array<string,int|bool> */
function coveted_membership_benefit_reconcile(int $limit = 250): array
{
    $limit = max(1, min($limit, 1000));
    $summary = [
        'issued' => 0,
        'already_issued' => 0,
        'limit_skips' => 0,
        'failures' => 0,
        'more_work_possible' => false,
        'skipped_locked' => false,
    ];

    $pdo = coveted_db();
    if (!coveted_membership_benefit_try_lock($pdo)) {
        $summary['skipped_locked'] = true;
        return $summary;
    }

    try {
        $targets = coveted_membership_benefit_targets($limit);
        foreach ($targets as $target) {
            $idempotencyKey = implode(':', [
                'membership',
                'campaign', (int)$target['campaign_id'],
                'user', (int)$target['user_id'],
            ]);

            $existing = coveted_reward_existing_idempotent($pdo, $idempotencyKey);
            if ($existing) {
                $summary['already_issued']++;
                coveted_membership_benefit_notify($target, $existing);
                continue;
            }

            try {
                $issuance = coveted_reward_issue(
                    (int)$target['campaign_id'],
                    (int)$target['user_id'],
                    null,
                    [
                        'automation' => 'membership_benefit',
                        'trigger_key' => 'membership',
                        'group_id' => (string)$target['group_public_id'],
                    ],
                    $idempotencyKey
                );
                $summary['issued']++;
                coveted_membership_benefit_notify($target, $issuance);
            } catch (InvalidArgumentException $e) {
                if (coveted_membership_benefit_expected_skip($e->getMessage())) {
                    $summary['limit_skips']++;
                    continue;
                }
                $summary['failures']++;
                error_log('Coveted membership benefit skipped unexpectedly: ' . $e->getMessage());
            } catch (Throwable $e) {
                $summary['failures']++;
                error_log('Coveted membership benefit distribution failed: ' . $e->getMessage());
            }
        }

        if (count($targets) >= $limit) {
            $summary['more_work_possible'] = (bool)coveted_membership_benefit_targets(1);
        }

        if ($summary['issued'] > 0 || $summary['failures'] > 0) {
            coveted_audit(
                'benefit.membership_reconciled',
                'platform',
                null,
                [
                    'issued' => $summary['issued'],
                    'already_issued' => $summary['already_issued'],
                    'limit_skips' => $summary['limit_skips'],
                    'failures' => $summary['failures'],
                ],
                0
            );
        }

        return $summary;
    } finally {
        coveted_membership_benefit_unlock($pdo);
    }
}

/** @return array<string,mixed> */
function coveted_benefit_economy_snapshot(array $actor, int $limit = 12): array
{
    if (!coveted_is_system_admin($actor)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $limit = max(1, min($limit, 50));
    $pdo = coveted_db();

    $summary = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM reward_issuances WHERE status IN ('issued','viewed')) AS ready,
            (SELECT COUNT(*) FROM reward_claims WHERE status = 'claimed') AS claimed,
            (SELECT COUNT(*) FROM reward_issuances WHERE status = 'expired' OR (expires_at IS NOT NULL AND expires_at <= NOW() AND status NOT IN ('claimed','cancelled'))) AS expired,
            (SELECT COUNT(*) FROM reward_issuances WHERE issued_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS issued_30d,
            (SELECT COUNT(*) FROM reward_claims WHERE claimed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS claimed_30d,
            (SELECT COUNT(*)
             FROM reward_issuances ri
             JOIN campaigns c ON c.id = ri.campaign_id
             WHERE c.trigger_key = 'membership' AND ri.status <> 'cancelled') AS membership_issued,
            (SELECT COUNT(*)
             FROM reward_claims rc
             JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
             JOIN campaigns c ON c.id = ri.campaign_id
             WHERE c.trigger_key IN ('return_visit','guest_return')
               AND rc.status = 'claimed') AS return_claims,
            (SELECT COUNT(*)
             FROM reward_issuances ri
             WHERE ri.status IN ('issued','viewed')
               AND ri.expires_at IS NOT NULL
               AND ri.expires_at > NOW()
               AND ri.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)) AS expiring_7d"
    )->fetch() ?: [];
    foreach ($summary as $key => $value) {
        $summary[$key] = (int)$value;
    }
    $summary['claim_rate_30d'] = $summary['issued_30d'] > 0
        ? round(($summary['claimed_30d'] / $summary['issued_30d']) * 100, 1)
        : 0.0;

    $pools = $pdo->query(
        "SELECT
            c.public_id,
            c.title,
            c.status,
            c.quantity_limit,
            c.per_user_limit,
            c.starts_at,
            c.ends_at,
            g.public_id AS group_public_id,
            g.name AS group_name,
            rt.title AS reward_title,
            rt.reward_type,
            COUNT(DISTINCT ri.id) AS issued_count,
            COUNT(DISTINCT CASE WHEN rc.status = 'claimed' THEN rc.id END) AS claimed_count,
            GREATEST(c.quantity_limit - COUNT(DISTINCT CASE WHEN ri.status <> 'cancelled' THEN ri.id END), 0) AS remaining_count
         FROM campaigns c
         JOIN social_groups g ON g.id = c.group_id
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         LEFT JOIN reward_issuances ri ON ri.campaign_id = c.id
         LEFT JOIN reward_claims rc ON rc.reward_issuance_id = ri.id
         WHERE c.owner_type = 'group'
           AND c.trigger_key = 'membership'
           AND c.quantity_limit IS NOT NULL
           AND c.status <> 'archived'
         GROUP BY c.id, c.public_id, c.title, c.status, c.quantity_limit, c.per_user_limit,
                  c.starts_at, c.ends_at, g.public_id, g.name, rt.title, rt.reward_type
         ORDER BY FIELD(c.status, 'active','paused','draft'), remaining_count ASC, c.updated_at DESC
         LIMIT {$limit}"
    )->fetchAll();

    $eventAttribution = $pdo->query(
        "SELECT e.public_id, e.title,
                COUNT(DISTINCT ri.id) AS issued_count,
                COUNT(DISTINCT CASE WHEN rc.status = 'claimed' THEN rc.id END) AS claimed_count
         FROM reward_issuances ri
         JOIN events e ON e.id = ri.event_id
         LEFT JOIN reward_claims rc ON rc.reward_issuance_id = ri.id
         WHERE ri.issued_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
           AND ri.status <> 'cancelled'
         GROUP BY e.id, e.public_id, e.title
         ORDER BY claimed_count DESC, issued_count DESC, e.id DESC
         LIMIT {$limit}"
    )->fetchAll();

    $businessAttribution = $pdo->query(
        "SELECT b.public_id, b.name,
                COUNT(DISTINCT ri.id) AS issued_count,
                COUNT(DISTINCT CASE WHEN rc.status = 'claimed' THEN rc.id END) AS claimed_count,
                COUNT(DISTINCT CASE WHEN c.trigger_key IN ('return_visit','guest_return') AND rc.status = 'claimed' THEN rc.id END) AS return_claim_count
         FROM reward_issuances ri
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         JOIN campaigns c ON c.id = ri.campaign_id
         JOIN businesses b ON b.id = COALESCE(c.business_id, rt.business_id)
         LEFT JOIN reward_claims rc ON rc.reward_issuance_id = ri.id
         WHERE ri.issued_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
           AND ri.status <> 'cancelled'
         GROUP BY b.id, b.public_id, b.name
         ORDER BY return_claim_count DESC, claimed_count DESC, issued_count DESC
         LIMIT {$limit}"
    )->fetchAll();

    $groupAttribution = $pdo->query(
        "SELECT g.public_id, g.name,
                COUNT(DISTINCT ri.id) AS issued_count,
                COUNT(DISTINCT CASE WHEN rc.status = 'claimed' THEN rc.id END) AS claimed_count,
                COUNT(DISTINCT CASE WHEN c.trigger_key = 'membership' THEN ri.id END) AS membership_count
         FROM reward_issuances ri
         JOIN campaigns c ON c.id = ri.campaign_id
         LEFT JOIN events e ON e.id = ri.event_id
         JOIN social_groups g ON g.id = COALESCE(c.group_id, e.group_id)
         LEFT JOIN reward_claims rc ON rc.reward_issuance_id = ri.id
         WHERE ri.issued_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
           AND ri.status <> 'cancelled'
         GROUP BY g.id, g.public_id, g.name
         ORDER BY claimed_count DESC, issued_count DESC
         LIMIT {$limit}"
    )->fetchAll();

    $artistAttribution = $pdo->query(
        "SELECT ap.public_id, ap.artist_name,
                COUNT(DISTINCT ri.id) AS issued_count,
                COUNT(DISTINCT CASE WHEN rc.status = 'claimed' THEN rc.id END) AS claimed_count,
                COUNT(DISTINCT CASE WHEN rt.reward_type IN ('audio','video','media_pack') THEN ri.id END) AS media_count
         FROM reward_issuances ri
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         JOIN artist_profiles ap ON ap.id = COALESCE(ri.artist_id, rt.artist_id)
         LEFT JOIN reward_claims rc ON rc.reward_issuance_id = ri.id
         WHERE ri.issued_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
           AND ri.status <> 'cancelled'
         GROUP BY ap.id, ap.public_id, ap.artist_name
         ORDER BY media_count DESC, claimed_count DESC, issued_count DESC
         LIMIT {$limit}"
    )->fetchAll();

    return [
        'summary' => $summary,
        'pools' => $pools,
        'event_attribution' => $eventAttribution,
        'business_attribution' => $businessAttribution,
        'group_attribution' => $groupAttribution,
        'artist_attribution' => $artistAttribution,
        'membership_backlog' => count(coveted_membership_benefit_targets(1000)),
        'generated_at' => gmdate('Y-m-d H:i:s'),
    ];
}
