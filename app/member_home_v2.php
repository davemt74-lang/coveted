<?php
declare(strict_types=1);

require_once __DIR__ . '/member_sample_data.php';
require_once __DIR__ . '/events.php';

/**
 * Home v2 is introduced without changing the global bootstrap contract yet.
 * Install its CSS/JS through one output-buffer pass so the current page shell
 * can be upgraded safely while the remaining member templates migrate.
 */
function coveted_member_home_v2_install_assets(): void
{
    static $installed = false;
    if ($installed || PHP_SAPI === 'cli') {
        return;
    }
    $installed = true;

    $cssVersion = coveted_asset_version('assets/css/member-v2.css');
    $jsVersion = coveted_asset_version('assets/js/member-v2.js');
    $cssTag = '<link rel="stylesheet" href="/assets/css/member-v2.css?v=' . coveted_e($cssVersion) . '">';
    $jsTag = '<script src="/assets/js/member-v2.js?v=' . coveted_e($jsVersion) . '" defer></script>';

    ob_start(static function (string $html) use ($cssTag, $jsTag): string {
        if (str_contains($html, '/assets/css/member-v2.css')) {
            return $html;
        }

        if (str_contains($html, '</head>')) {
            $html = str_replace('</head>', '    ' . $cssTag . "\n</head>", $html);
        }
        if (str_contains($html, '</body>')) {
            $html = str_replace('</body>', $jsTag . "\n</body>", $html);
        }

        return $html;
    });
}

/** @return array<string,mixed> */
function coveted_member_home_v2_data(array $user, ?PDO $pdo = null): array
{
    coveted_member_home_v2_install_assets();
    $pdo ??= coveted_db();

    if (coveted_member_sample_mode($user, $pdo)) {
        $sample = coveted_member_sample_data();
        return [
            'sample_mode' => true,
            'next_event' => $sample['events'][0] ?? null,
            'invitation' => $sample['events'][1] ?? null,
            'events' => $sample['events'],
            'groups' => $sample['groups'],
            'benefits' => $sample['benefits'],
            'reconnects' => $sample['reconnects'],
        ];
    }

    $userId = (int)$user['id'];
    $nextEvent = null;
    $invitation = null;
    $groups = [];
    $benefits = [];

    try {
        $stmt = $pdo->prepare(
            "SELECT e.public_id, e.title, e.event_type, e.audience, e.timezone, e.starts_at,
                    er.response, g.name AS group_name
             FROM event_rsvps er
             JOIN events e ON e.id = er.event_id
             JOIN social_groups g ON g.id = e.group_id
             WHERE er.user_id = ?
               AND er.response = 'attending'
               AND e.status IN ('published','closed')
               AND e.starts_at >= UTC_TIMESTAMP()
             ORDER BY e.starts_at ASC
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) {
            $nextEvent = [
                'public_id' => (string)$row['public_id'],
                'title' => (string)$row['title'],
                'event_type' => (string)$row['event_type'],
                'timezone' => (string)$row['timezone'],
                'starts_at' => (string)$row['starts_at'],
                'location' => null,
                'city' => null,
                'group' => (string)$row['group_name'],
                'image' => null,
                'description' => null,
                'rsvp' => (string)$row['response'],
            ];
        }
    } catch (Throwable $e) {
        error_log('Coveted Home v2 next event unavailable: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT e.public_id, e.title, e.event_type, e.timezone, e.starts_at, g.name AS group_name
             FROM event_invitations ei
             JOIN events e ON e.id = ei.event_id
             JOIN social_groups g ON g.id = e.group_id
             WHERE ei.user_id = ?
               AND ei.status = 'pending'
               AND e.status = 'published'
               AND e.starts_at > UTC_TIMESTAMP()
             ORDER BY e.starts_at ASC
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) {
            $invitation = [
                'public_id' => (string)$row['public_id'],
                'title' => (string)$row['title'],
                'event_type' => (string)$row['event_type'],
                'timezone' => (string)$row['timezone'],
                'starts_at' => (string)$row['starts_at'],
                'group' => (string)$row['group_name'],
                'image' => null,
            ];
        }
    } catch (Throwable $e) {
        error_log('Coveted Home v2 invitation unavailable: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT g.public_id, g.name,
                    (SELECT COUNT(*) FROM group_memberships gm2
                     WHERE gm2.group_id = g.id AND gm2.membership_status = 'active') AS members
             FROM group_memberships gm
             JOIN social_groups g ON g.id = gm.group_id
             WHERE gm.user_id = ?
               AND gm.membership_status = 'active'
               AND g.status = 'active'
             ORDER BY g.name ASC
             LIMIT 3"
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $groups[] = [
                'id' => (string)$row['public_id'],
                'name' => (string)$row['name'],
                'members' => (int)$row['members'],
                'next' => null,
                'image' => null,
            ];
        }
    } catch (Throwable $e) {
        error_log('Coveted Home v2 groups unavailable: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT ri.public_id, ri.status, rt.title, rt.description, rt.value_text
             FROM reward_issuances ri
             JOIN reward_templates rt ON rt.id = ri.reward_template_id
             WHERE ri.user_id = ?
               AND ri.status NOT IN ('cancelled','expired')
               AND (ri.expires_at IS NULL OR ri.expires_at > UTC_TIMESTAMP())
             ORDER BY ri.issued_at DESC, ri.id DESC
             LIMIT 2"
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $benefits[] = [
                'id' => (string)$row['public_id'],
                'title' => (string)$row['title'],
                'partner' => null,
                'value' => (string)($row['value_text'] ?: $row['description'] ?: 'Member benefit'),
                'status' => (string)$row['status'],
                'image' => null,
            ];
        }
    } catch (Throwable $e) {
        error_log('Coveted Home v2 benefits unavailable: ' . $e->getMessage());
    }

    return [
        'sample_mode' => false,
        'next_event' => $nextEvent,
        'invitation' => $invitation,
        'events' => $nextEvent ? [$nextEvent] : [],
        'groups' => $groups,
        'benefits' => $benefits,
        'reconnects' => [],
    ];
}
