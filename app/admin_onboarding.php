<?php
declare(strict_types=1);

require_once __DIR__ . '/admin.php';

/**
 * First-run System Admin onboarding is intentionally derived from canonical
 * product records rather than a second settings/state table. The only
 * persisted preference is an explicit "skip" audit event.
 *
 * @return array{
 *   users:int,businesses:int,groups:int,events:int,invitations:int,
 *   completed:int,total:int,percent:int,is_complete:bool,is_dismissed:bool,
 *   steps:array<int,array{key:string,title:string,description:string,href:string,done:bool}>
 * }
 */
function coveted_admin_onboarding_safe_count(PDO $pdo, string $sql, string $label, array &$issues): int
{
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        $issues[] = $label;
        error_log('Coveted Admin onboarding data unavailable [' . $label . ']: ' . $e->getMessage());
        return 0;
    }
}

function coveted_admin_onboarding_state(array $admin): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $pdo = coveted_db();
    $issues = [];
    $counts = [
        'users' => coveted_admin_onboarding_safe_count($pdo, "SELECT COUNT(*) FROM users WHERE status <> 'deleted'", 'users', $issues),
        'businesses' => coveted_admin_onboarding_safe_count($pdo, "SELECT COUNT(*) FROM businesses WHERE status <> 'archived'", 'businesses', $issues),
        'groups' => coveted_admin_onboarding_safe_count($pdo, "SELECT COUNT(*) FROM social_groups WHERE status <> 'archived'", 'groups', $issues),
        'events' => coveted_admin_onboarding_safe_count($pdo, "SELECT COUNT(*) FROM events WHERE status <> 'cancelled'", 'events', $issues),
        'invitations' => coveted_admin_onboarding_safe_count($pdo, "SELECT COUNT(*) FROM event_invitations WHERE status <> 'revoked'", 'invitations', $issues),
    ];

    $steps = [
        [
            'key' => 'business',
            'title' => 'Create your first business',
            'description' => 'Add a venue or partner that can host experiences and member benefits.',
            'href' => '/admin/?view=businesses#create-business',
            'done' => $counts['businesses'] > 0,
        ],
        [
            'key' => 'group',
            'title' => 'Create your first group',
            'description' => 'Create the private community that will receive invitations and experiences.',
            'href' => '/admin/?view=groups#create-group',
            'done' => $counts['groups'] > 0,
        ],
        [
            'key' => 'user',
            'title' => 'Add a host or member',
            'description' => 'Create another account and give it only the access it actually needs.',
            'href' => '/admin/?view=users#create-user',
            'done' => $counts['users'] > 1,
        ],
        [
            'key' => 'event',
            'title' => 'Create your first event',
            'description' => 'Build a gathering inside a group, then publish it when it is ready.',
            'href' => '/admin/?view=events#create-event',
            'done' => $counts['events'] > 0,
        ],
        [
            'key' => 'invite',
            'title' => 'Invite attendees',
            'description' => 'Send at least one event invitation so you can verify the member flow end-to-end.',
            'href' => '/admin/?view=events',
            'done' => $counts['invitations'] > 0,
        ],
    ];

    $completed = count(array_filter($steps, static fn(array $step): bool => $step['done']));
    $total = count($steps);
    $dismissed = false;

    try {
        $dismissedStmt = $pdo->prepare(
            "SELECT 1
             FROM audit_events
             WHERE actor_user_id = ?
               AND event_type = 'admin.onboarding_dismissed'
             ORDER BY id DESC
             LIMIT 1"
        );
        $dismissedStmt->execute([(int)$admin['id']]);
        $dismissed = (bool)$dismissedStmt->fetchColumn();
    } catch (Throwable $e) {
        $issues[] = 'audit_events';
        error_log('Coveted Admin onboarding dismissal state unavailable: ' . $e->getMessage());
    }

    return [
        ...$counts,
        'completed' => $completed,
        'total' => $total,
        'percent' => $total > 0 ? (int)round(($completed / $total) * 100) : 100,
        'is_complete' => $completed === $total,
        'is_dismissed' => $dismissed,
        'has_data_issues' => $issues !== [],
        'data_issues' => array_values(array_unique($issues)),
        'steps' => $steps,
    ];
}

function coveted_admin_should_show_onboarding(array $admin): bool
{
    $state = coveted_admin_onboarding_state($admin);
    return !$state['has_data_issues'] && !$state['is_complete'] && !$state['is_dismissed'];
}

function coveted_admin_dismiss_onboarding(array $admin): void
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    coveted_audit(
        'admin.onboarding_dismissed',
        'user',
        (string)$admin['public_id'],
        ['source' => 'admin_onboarding'],
        (int)$admin['id']
    );
}
