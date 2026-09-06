<?php
declare(strict_types=1);

require_once __DIR__ . '/invite_crm.php';

/**
 * Run one read-only analytics component without making the whole Agent context
 * unavailable when an optional table or one report fails.
 */
function coveted_admin_agent_live_business_safe(
    string $key,
    callable $reader,
    array &$issues,
    mixed $fallback = []
): mixed {
    try {
        return $reader();
    } catch (Throwable $e) {
        $issues[] = $key;
        error_log('Admin Agent live business ' . $key . ' unavailable: ' . $e->getMessage());
        return $fallback;
    }
}

/** @return array<int,array<string,mixed>> */
function coveted_admin_agent_live_business_city_demand(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT
            COALESCE(NULLIF(TRIM(c.name), ''), NULLIF(TRIM(ir.city_other), ''), 'Other / unknown') AS city,
            COALESCE(NULLIF(TRIM(c.region), ''), '') AS region,
            COUNT(*) AS total,
            SUM(ir.status IN ('new','contacted','qualified')) AS active,
            SUM(ir.status = 'new') AS new_count,
            SUM(ir.status = 'qualified') AS qualified_count,
            SUM(ir.status = 'converted') AS converted_count
         FROM invite_requests ir
         LEFT JOIN cities c ON c.id = ir.city_id
         GROUP BY city, region
         ORDER BY active DESC, total DESC, city ASC
         LIMIT 5"
    )->fetchAll();

    return array_map(static fn(array $row): array => [
        'city' => (string)$row['city'],
        'region' => (string)$row['region'],
        'active' => (int)$row['active'],
        'new' => (int)$row['new_count'],
        'qualified' => (int)$row['qualified_count'],
        'converted' => (int)$row['converted_count'],
        'total' => (int)$row['total'],
    ], $rows);
}

/** @return array<int,array<string,mixed>> */
function coveted_admin_agent_live_business_interest_demand(PDO $pdo): array
{
    $options = coveted_invite_event_interest_options();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(status = 'new') AS new_count,
                SUM(status = 'contacted') AS contacted_count,
                SUM(status = 'qualified') AS qualified_count
         FROM invite_requests
         WHERE status IN ('new','contacted','qualified')
           AND JSON_CONTAINS(event_interests_json, ?, '$')"
    );

    $items = [];
    foreach ($options as $key => $label) {
        $stmt->execute([coveted_json((string)$key)]);
        $row = $stmt->fetch() ?: [];
        $total = (int)($row['total'] ?? 0);
        if ($total < 1) {
            continue;
        }
        $items[] = [
            'key' => (string)$key,
            'label' => (string)$label,
            'active' => $total,
            'new' => (int)($row['new_count'] ?? 0),
            'contacted' => (int)($row['contacted_count'] ?? 0),
            'qualified' => (int)($row['qualified_count'] ?? 0),
        ];
    }

    usort($items, static fn(array $a, array $b): int =>
        ((int)$b['active'] <=> (int)$a['active']) ?: strcmp((string)$a['label'], (string)$b['label'])
    );
    return array_slice($items, 0, 8);
}

/** @return array<int,array<string,mixed>> */
function coveted_admin_agent_live_business_event_attention(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT e.public_id, e.title, e.status, e.starts_at, g.name AS group_name,
                (SELECT COUNT(*) FROM event_hosts eh WHERE eh.event_id = e.id) AS host_count,
                (SELECT COUNT(*) FROM event_locations el
                 WHERE el.event_id = e.id
                   AND (el.location_id IS NOT NULL OR NULLIF(TRIM(el.private_location_label), '') IS NOT NULL)) AS location_count,
                (SELECT COUNT(*) FROM event_invitations ei
                 WHERE ei.event_id = e.id AND ei.status <> 'revoked') AS invitation_count,
                (SELECT COUNT(*) FROM event_rsvps er
                 WHERE er.event_id = e.id AND er.response = 'attending') AS attending_count,
                (SELECT COUNT(*) FROM event_rsvps er
                 WHERE er.event_id = e.id AND er.response = 'waitlist') AS waitlist_count
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         WHERE e.status IN ('draft','published')
           AND (e.status = 'draft' OR e.starts_at >= UTC_TIMESTAMP())
           AND (
                e.status = 'draft'
                OR NOT EXISTS (SELECT 1 FROM event_hosts eh WHERE eh.event_id = e.id)
                OR NOT EXISTS (
                    SELECT 1 FROM event_locations el
                    WHERE el.event_id = e.id
                      AND (el.location_id IS NOT NULL OR NULLIF(TRIM(el.private_location_label), '') IS NOT NULL)
                )
                OR NOT EXISTS (
                    SELECT 1 FROM event_invitations ei
                    WHERE ei.event_id = e.id AND ei.status <> 'revoked'
                )
           )
         ORDER BY CASE WHEN e.status = 'published' THEN 0 ELSE 1 END, e.starts_at ASC, e.id ASC
         LIMIT 8"
    )->fetchAll();

    return array_map(static function (array $row): array {
        $needs = [];
        if ((string)$row['status'] === 'draft') {
            $needs[] = 'draft review';
        }
        if ((int)$row['host_count'] === 0) {
            $needs[] = 'host';
        }
        if ((int)$row['location_count'] === 0) {
            $needs[] = 'location';
        }
        if ((int)$row['invitation_count'] === 0) {
            $needs[] = 'invitations';
        }
        return [
            'event_ref' => (string)$row['public_id'],
            'title' => (string)$row['title'],
            'group' => (string)$row['group_name'],
            'status' => (string)$row['status'],
            'starts_at' => (string)$row['starts_at'],
            'needs' => $needs,
            'attending' => (int)$row['attending_count'],
            'waitlist' => (int)$row['waitlist_count'],
        ];
    }, $rows);
}

/** @return array<int,array<string,mixed>> */
function coveted_admin_agent_live_business_partner_coverage(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT b.public_id, b.name, b.status,
                (SELECT COUNT(*) FROM locations l
                 WHERE l.business_id = b.id AND l.status <> 'archived') AS locations,
                (SELECT COUNT(*) FROM business_admins ba
                 WHERE ba.business_id = b.id) AS admins,
                (SELECT COUNT(*) FROM campaigns c
                 WHERE c.business_id = b.id AND c.owner_type = 'business' AND c.status = 'active') AS active_campaigns,
                (SELECT COUNT(*) FROM reward_templates rt
                 WHERE rt.business_id = b.id AND rt.owner_type = 'business' AND rt.status = 'active') AS active_rewards
         FROM businesses b
         WHERE b.status <> 'archived'
           AND (
                NOT EXISTS (SELECT 1 FROM locations l WHERE l.business_id = b.id AND l.status <> 'archived')
                OR NOT EXISTS (SELECT 1 FROM business_admins ba WHERE ba.business_id = b.id)
                OR NOT EXISTS (SELECT 1 FROM campaigns c WHERE c.business_id = b.id AND c.owner_type = 'business' AND c.status = 'active')
                OR NOT EXISTS (SELECT 1 FROM reward_templates rt WHERE rt.business_id = b.id AND rt.owner_type = 'business' AND rt.status = 'active')
           )
         ORDER BY b.status = 'active' DESC, b.name ASC
         LIMIT 8"
    )->fetchAll();

    return array_map(static function (array $row): array {
        $missing = [];
        if ((int)$row['locations'] === 0) $missing[] = 'location';
        if ((int)$row['admins'] === 0) $missing[] = 'business admin';
        if ((int)$row['active_campaigns'] === 0) $missing[] = 'active campaign';
        if ((int)$row['active_rewards'] === 0) $missing[] = 'active reward';
        return [
            'business_ref' => (string)$row['public_id'],
            'name' => (string)$row['name'],
            'status' => (string)$row['status'],
            'missing' => $missing,
            'locations' => (int)$row['locations'],
            'admins' => (int)$row['admins'],
            'active_campaigns' => (int)$row['active_campaigns'],
            'active_rewards' => (int)$row['active_rewards'],
        ];
    }, $rows);
}

/** @return array<string,int> */
function coveted_admin_agent_live_business_host_capacity(PDO $pdo): array
{
    $row = $pdo->query(
        "SELECT
            (SELECT COUNT(DISTINCT ur.user_id)
             FROM user_roles ur
             JOIN users u ON u.id = ur.user_id
             WHERE ur.role_key = 'attendee_host' AND u.status = 'active') AS approved_attendee_hosts,
            (SELECT COUNT(DISTINCT gm.user_id)
             FROM group_memberships gm
             WHERE gm.membership_status = 'active'
               AND gm.group_role IN ('host','group_admin')) AS active_group_leaders,
            (SELECT COUNT(DISTINCT eh.user_id)
             FROM event_hosts eh
             JOIN events e ON e.id = eh.event_id
             WHERE e.status = 'published' AND e.starts_at >= UTC_TIMESTAMP()) AS hosts_assigned_upcoming,
            (SELECT COUNT(*)
             FROM events e
             WHERE e.status = 'published' AND e.starts_at >= UTC_TIMESTAMP()
               AND NOT EXISTS (SELECT 1 FROM event_hosts eh WHERE eh.event_id = e.id)) AS published_events_without_hosts"
    )->fetch() ?: [];

    return [
        'approved_attendee_hosts' => (int)($row['approved_attendee_hosts'] ?? 0),
        'active_group_leaders' => (int)($row['active_group_leaders'] ?? 0),
        'hosts_assigned_upcoming' => (int)($row['hosts_assigned_upcoming'] ?? 0),
        'published_events_without_hosts' => (int)($row['published_events_without_hosts'] ?? 0),
    ];
}

/** @return array<string,int> */
function coveted_admin_agent_live_business_event_momentum(PDO $pdo): array
{
    $row = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM events e
             WHERE e.status = 'published' AND e.starts_at >= UTC_TIMESTAMP()) AS future_published_events,
            (SELECT COUNT(*) FROM event_rsvps er
             JOIN events e ON e.id = er.event_id
             WHERE e.status = 'published' AND e.starts_at >= UTC_TIMESTAMP()
               AND er.response = 'attending') AS attending_rsvps,
            (SELECT COALESCE(SUM(er.guest_count),0) FROM event_rsvps er
             JOIN events e ON e.id = er.event_id
             WHERE e.status = 'published' AND e.starts_at >= UTC_TIMESTAMP()
               AND er.response = 'attending') AS attending_guests,
            (SELECT COUNT(*) FROM event_rsvps er
             JOIN events e ON e.id = er.event_id
             WHERE e.status = 'published' AND e.starts_at >= UTC_TIMESTAMP()
               AND er.response = 'waitlist') AS waitlist_rsvps,
            (SELECT COUNT(*) FROM event_invitations ei
             JOIN events e ON e.id = ei.event_id
             WHERE e.status = 'published' AND e.starts_at >= UTC_TIMESTAMP()
               AND ei.status = 'pending') AS pending_invitations"
    )->fetch() ?: [];

    return [
        'future_published_events' => (int)($row['future_published_events'] ?? 0),
        'attending_rsvps' => (int)($row['attending_rsvps'] ?? 0),
        'attending_guests' => (int)($row['attending_guests'] ?? 0),
        'waitlist_rsvps' => (int)($row['waitlist_rsvps'] ?? 0),
        'pending_invitations' => (int)($row['pending_invitations'] ?? 0),
    ];
}

/** @return array<string,array<string,int>> */
function coveted_admin_agent_live_business_weekly_changes(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT
            CASE
                WHEN event_type LIKE 'event.%' OR entity_type LIKE 'event%' THEN 'Events'
                WHEN event_type LIKE 'group.%' OR entity_type LIKE 'group%' THEN 'Community'
                WHEN event_type LIKE 'business.%' OR event_type LIKE 'location.%' OR event_type LIKE 'venue.%'
                     OR entity_type IN ('business','location','venue_relationship') THEN 'Partners'
                WHEN event_type LIKE 'artist.%' OR entity_type LIKE 'artist%' THEN 'Artists'
                WHEN event_type LIKE 'campaign.%' OR event_type LIKE 'reward.%' OR entity_type LIKE 'campaign%'
                     OR entity_type LIKE 'reward%' THEN 'Member value'
                WHEN event_type LIKE 'admin.invite_request_%' OR event_type = 'invite_request.created'
                     OR entity_type = 'invite_request' THEN 'CRM'
                WHEN event_type LIKE 'role.%' OR event_type LIKE 'admin.role_%' OR event_type LIKE 'user.%'
                     OR event_type LIKE 'admin.user_%' OR entity_type IN ('user','role_request') THEN 'People'
                ELSE 'Platform'
            END AS category,
            SUM(created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)) AS current_7d,
            SUM(created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)) AS prior_7d
         FROM audit_events
         WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)
           AND event_type NOT LIKE 'admin.agent_%'
           AND event_type NOT LIKE 'site_setting.%'
           AND event_type NOT LIKE 'admin.ai_provider%'
           AND event_type NOT LIKE 'auth.%'
           AND LOWER(event_type) NOT LIKE '%login%'
           AND LOWER(event_type) NOT LIKE '%logout%'
           AND LOWER(event_type) NOT LIKE '%password%'
           AND LOWER(event_type) NOT LIKE '%session%'
           AND LOWER(event_type) NOT LIKE '%csrf%'
         GROUP BY category
         ORDER BY current_7d DESC, category ASC"
    )->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $current = (int)$row['current_7d'];
        $prior = (int)$row['prior_7d'];
        $result[(string)$row['category']] = [
            'current_7d' => $current,
            'prior_7d' => $prior,
            'delta' => $current - $prior,
        ];
    }
    return $result;
}

/**
 * Internal read-only analytics snapshot. This is intentionally not an HTTP
 * endpoint and is attached only from the already System-Admin-gated Agent
 * page/chat enrichment path. It does not accept model-authored SQL or filters.
 *
 * @return array<string,mixed>
 */
function coveted_admin_agent_live_business_snapshot(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $issues = [];
    return [
        'generated_at' => gmdate('Y-m-d H:i:s'),
        'privacy' => 'Person-level names, emails, phone numbers, notes and messages are intentionally excluded. For host-selection questions, report capacity and direct the System Admin to the People/Groups workspace rather than inventing a person.',
        'city_demand' => coveted_admin_agent_live_business_safe('city_demand', fn() => coveted_admin_agent_live_business_city_demand($pdo), $issues),
        'interest_demand' => coveted_admin_agent_live_business_safe('interest_demand', fn() => coveted_admin_agent_live_business_interest_demand($pdo), $issues),
        'event_attention' => coveted_admin_agent_live_business_safe('event_attention', fn() => coveted_admin_agent_live_business_event_attention($pdo), $issues),
        'partner_coverage' => coveted_admin_agent_live_business_safe('partner_coverage', fn() => coveted_admin_agent_live_business_partner_coverage($pdo), $issues),
        'host_capacity' => coveted_admin_agent_live_business_safe('host_capacity', fn() => coveted_admin_agent_live_business_host_capacity($pdo), $issues, [
            'approved_attendee_hosts' => 0,
            'active_group_leaders' => 0,
            'hosts_assigned_upcoming' => 0,
            'published_events_without_hosts' => 0,
        ]),
        'event_momentum' => coveted_admin_agent_live_business_safe('event_momentum', fn() => coveted_admin_agent_live_business_event_momentum($pdo), $issues, [
            'future_published_events' => 0,
            'attending_rsvps' => 0,
            'attending_guests' => 0,
            'waitlist_rsvps' => 0,
            'pending_invitations' => 0,
        ]),
        'weekly_changes' => coveted_admin_agent_live_business_safe('weekly_changes', fn() => coveted_admin_agent_live_business_weekly_changes($pdo), $issues),
        'issues' => $issues,
        'routes' => [
            'crm' => '/admin/crm.php',
            'events' => '/admin/?view=events',
            'people' => '/admin/?view=users',
            'groups' => '/admin/?view=groups',
            'businesses' => '/admin/?view=businesses',
            'benefits' => '/admin/?view=benefits',
            'operations' => '/admin/operations.php',
        ],
    ];
}
