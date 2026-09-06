<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_agent_brain.php';

/** @return array{category:string,label:string} */
function coveted_admin_agent_briefing_event_label(string $eventType, string $entityType = ''): array
{
    $eventType = strtolower(trim($eventType));
    $entityType = strtolower(trim($entityType));

    $category = match (true) {
        str_starts_with($eventType, 'role.'),
        str_starts_with($eventType, 'admin.role_'),
        str_starts_with($eventType, 'user.'),
        str_starts_with($eventType, 'admin.user_'),
        $entityType === 'user' => 'People',

        str_starts_with($eventType, 'event.'),
        in_array($entityType, ['event', 'event_invitation'], true) => 'Events',

        str_starts_with($eventType, 'group.'),
        $entityType === 'group' => 'Community',

        str_starts_with($eventType, 'business.'),
        str_starts_with($eventType, 'location.'),
        str_starts_with($eventType, 'venue.'),
        in_array($entityType, ['business', 'location', 'venue_relationship'], true) => 'Partners',

        str_starts_with($eventType, 'artist.'),
        $entityType === 'artist' => 'Artists',

        str_starts_with($eventType, 'campaign.'),
        str_starts_with($eventType, 'reward.'),
        str_starts_with($eventType, 'claim.'),
        in_array($entityType, ['campaign', 'reward_template', 'reward_claim'], true) => 'Value',

        str_starts_with($eventType, 'admin.invite_request_'),
        $entityType === 'invite_request' => 'CRM',

        default => 'Platform',
    };

    $exact = [
        'role.requested' => 'New role request',
        'admin.role_request_approved' => 'Role request approved',
        'admin.role_request_declined' => 'Role request declined',
        'admin.user_created' => 'Member account created',
        'event.created' => 'Event created',
        'event.status_changed' => 'Event status changed',
        'event.host_assigned' => 'Event host assigned',
        'event.rsvp_updated' => 'Event RSVP updated',
        'event.invitation_response' => 'Event invitation response',
        'event.attendance_recorded' => 'Event attendance recorded',
        'event.waitlist_promoted' => 'Waitlist member promoted',
        'group.created' => 'Group created',
        'group.member_removed' => 'Group member removed',
        'business.created' => 'Business created',
        'business.admin_added' => 'Business Admin assigned',
        'location.created' => 'Business location created',
        'campaign.created' => 'Campaign created',
        'campaign.status_changed' => 'Campaign status changed',
        'reward_template.created' => 'Reward created',
        'reward_template.status_changed' => 'Reward status changed',
        'reward.claimed' => 'Reward claimed',
        'reward.refunded' => 'Reward refunded',
        'admin.invite_request_updated' => 'CRM record updated',
        'admin.invite_request_converted' => 'CRM prospect converted',
        'admin.city_created' => 'City added',
        'admin.city_status' => 'City status changed',
    ];

    $label = $exact[$eventType] ?? '';
    if ($label === '') {
        $label = preg_replace('/[._-]+/', ' ', $eventType) ?: $eventType;
        $label = preg_replace('/\s+/', ' ', trim($label)) ?: $label;
        $label = ucfirst($label);
    }

    return ['category' => $category, 'label' => $label];
}

function coveted_admin_agent_briefing_is_meaningful_event(string $eventType): bool
{
    $eventType = strtolower(trim($eventType));
    if ($eventType === '') {
        return false;
    }

    foreach ([
        'admin.agent_',
        'site_setting.',
        'admin.ai_provider_',
        'login',
        'logout',
        'password',
        'session',
        'csrf',
        'auth.',
    ] as $noise) {
        if (str_starts_with($eventType, $noise) || str_contains($eventType, $noise)) {
            return false;
        }
    }

    return true;
}

/** @return array{total_24h:int,recent:array<int,array<string,string>>,categories:array<string,int>,issue:bool} */
function coveted_admin_agent_briefing_recent_activity(PDO $pdo): array
{
    $meaningfulWhere = "ae.created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
        AND ae.event_type NOT LIKE 'admin.agent_%'
        AND ae.event_type NOT LIKE 'site_setting.%'
        AND ae.event_type NOT LIKE 'admin.ai_provider%'
        AND ae.event_type NOT LIKE 'auth.%'
        AND LOWER(ae.event_type) NOT LIKE '%login%'
        AND LOWER(ae.event_type) NOT LIKE '%logout%'
        AND LOWER(ae.event_type) NOT LIKE '%password%'
        AND LOWER(ae.event_type) NOT LIKE '%session%'
        AND LOWER(ae.event_type) NOT LIKE '%csrf%'";

    try {
        $total = (int)($pdo->query(
            "SELECT COUNT(*) FROM audit_events ae WHERE {$meaningfulWhere}"
        )->fetchColumn() ?: 0);

        $rows = $pdo->query(
            "SELECT ae.event_type, ae.entity_type, ae.entity_id, ae.created_at,
                    COALESCE(u.display_name, 'System') AS actor_name
             FROM audit_events ae
             LEFT JOIN users u ON u.id = ae.actor_user_id
             WHERE {$meaningfulWhere}
             ORDER BY ae.id DESC
             LIMIT 60"
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('Admin Agent briefing audit read unavailable: ' . $e->getMessage());
        return ['total_24h' => 0, 'recent' => [], 'categories' => [], 'issue' => true];
    }

    $recent = [];
    $categories = [];
    foreach ($rows as $row) {
        $eventType = (string)($row['event_type'] ?? '');
        if (!coveted_admin_agent_briefing_is_meaningful_event($eventType)) {
            continue;
        }

        $definition = coveted_admin_agent_briefing_event_label($eventType, (string)($row['entity_type'] ?? ''));
        $category = (string)$definition['category'];
        $categories[$category] = (int)($categories[$category] ?? 0) + 1;

        if (count($recent) >= 6) {
            continue;
        }

        $at = '';
        try {
            $at = coveted_utc_datetime((string)$row['created_at'])
                ->setTimezone(coveted_timezone())
                ->format('M j, g:i A');
        } catch (Throwable) {
            $at = '';
        }

        $recent[] = [
            'category' => $category,
            'label' => (string)$definition['label'],
            'actor' => mb_substr(trim((string)($row['actor_name'] ?? 'System')) ?: 'System', 0, 180),
            'entity' => mb_substr(trim((string)($row['entity_id'] ?? '')), 0, 190),
            'at' => $at,
        ];
    }

    arsort($categories);
    return [
        'total_24h' => $total,
        'recent' => $recent,
        'categories' => $categories,
        'issue' => false,
    ];
}

/** @return array<string,mixed> */
function coveted_admin_agent_briefing(array $admin, array $snapshot, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $pdo ??= coveted_db();
    $opportunities = array_values((array)($snapshot['opportunities'] ?? []));
    $priorityOne = array_values(array_filter(
        $opportunities,
        static fn(array $item): bool => (int)($item['priority'] ?? 0) === 1
    ));
    $crm = (array)($snapshot['crm'] ?? []);
    $operations = (array)($snapshot['operations']['summary'] ?? []);
    $activity = coveted_admin_agent_briefing_recent_activity($pdo);

    $crmReady = (int)($crm['new_count'] ?? 0) + (int)($crm['qualified_count'] ?? 0);
    $attention = (int)($operations['attention_count'] ?? 0);
    $readiness = (int)($snapshot['readiness']['percent'] ?? 0);
    $p1Count = count($priorityOne);

    if ($p1Count > 0) {
        $headline = $p1Count . ' priority item' . ($p1Count === 1 ? '' : 's') . ' need attention';
        $summary = 'Start with the highest-impact operational or access issue, then work the growth pipeline.';
    } elseif ($attention > 0) {
        $headline = $attention . ' operational item' . ($attention === 1 ? '' : 's') . ' need review';
        $summary = 'The platform has no P1 Agent opportunity, but the canonical Operations snapshot still has work to reconcile.';
    } elseif ($crmReady > 0) {
        $headline = $crmReady . ' CRM ' . ($crmReady === 1 ? 'opportunity is' : 'opportunities are') . ' ready';
        $summary = 'Core operations are clear enough to focus on invite conversion and relationship growth.';
    } elseif ($opportunities) {
        $headline = 'Coveted is ready for the next build-out move';
        $summary = 'No urgent issue is flagged. The Agent has lower-priority setup and growth opportunities ready to work.';
    } else {
        $headline = 'Coveted has no current Agent-flagged gap';
        $summary = 'Use the clear operating state to focus on member, partner, event and campaign growth.';
    }

    $generatedAt = '';
    try {
        $generatedAt = coveted_utc_datetime((string)($snapshot['generated_at'] ?? gmdate('Y-m-d H:i:s')))
            ->setTimezone(coveted_timezone())
            ->format('M j, g:i A');
    } catch (Throwable) {
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(coveted_timezone())
            ->format('M j, g:i A');
    }

    return [
        'headline' => $headline,
        'summary' => $summary,
        'generated_at' => $generatedAt,
        'readiness' => $readiness,
        'priority_count' => $p1Count,
        'crm_ready' => $crmReady,
        'operations_attention' => $attention,
        'changes_24h' => (int)$activity['total_24h'],
        'top_moves' => array_slice($opportunities, 0, 3),
        'recent' => (array)$activity['recent'],
        'activity_categories' => (array)$activity['categories'],
        'issues' => !empty($activity['issue']) ? ['audit_activity'] : [],
    ];
}
