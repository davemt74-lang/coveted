<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_onboarding.php';
require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/ai_providers.php';
require_once __DIR__ . '/site_settings.php';

/**
 * The Admin Agent brain is deliberately read-only. It derives its state from
 * Coveted's canonical tables/services so there is no second source of truth to
 * synchronize. Opportunities disappear automatically as the underlying data
 * becomes complete.
 */

/** @return array<string,int> */
function coveted_admin_agent_core_metrics(PDO $pdo, array &$issues): array
{
    try {
        $row = $pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM users WHERE status = 'active') AS active_users,
                (SELECT COUNT(*) FROM users WHERE status = 'suspended') AS suspended_users,
                (SELECT COUNT(*) FROM businesses WHERE status <> 'archived') AS businesses,
                (SELECT COUNT(*) FROM businesses WHERE status = 'active') AS active_businesses,
                (SELECT COUNT(*)
                 FROM businesses b
                 WHERE b.status <> 'archived'
                   AND NOT EXISTS (
                       SELECT 1 FROM locations l
                       WHERE l.business_id = b.id AND l.status <> 'archived'
                   )) AS businesses_without_locations,
                (SELECT COUNT(*)
                 FROM businesses b
                 WHERE b.status <> 'archived'
                   AND NOT EXISTS (
                       SELECT 1 FROM business_admins ba WHERE ba.business_id = b.id
                   )) AS businesses_without_admins,
                (SELECT COUNT(*) FROM locations WHERE status <> 'archived') AS locations,
                (SELECT COUNT(*) FROM social_groups WHERE status <> 'archived') AS groups,
                (SELECT COUNT(*) FROM social_groups WHERE status = 'active') AS active_groups,
                (SELECT COUNT(*)
                 FROM social_groups g
                 WHERE g.status <> 'archived'
                   AND NOT EXISTS (
                       SELECT 1 FROM group_memberships gm
                       WHERE gm.group_id = g.id
                         AND gm.membership_status = 'active'
                         AND gm.group_role IN ('host','group_admin')
                   )) AS groups_without_leadership,
                (SELECT COUNT(*) FROM group_memberships WHERE membership_status = 'active') AS active_group_memberships,
                (SELECT COUNT(*) FROM group_invitations WHERE status = 'pending') AS pending_group_invitations,
                (SELECT COUNT(*) FROM events WHERE status <> 'cancelled') AS events,
                (SELECT COUNT(*) FROM events WHERE status = 'draft') AS draft_events,
                (SELECT COUNT(*) FROM events WHERE status = 'published' AND starts_at >= UTC_TIMESTAMP()) AS published_future_events,
                (SELECT COUNT(*)
                 FROM events e
                 WHERE e.status = 'published'
                   AND e.starts_at >= UTC_TIMESTAMP()
                   AND NOT EXISTS (SELECT 1 FROM event_hosts eh WHERE eh.event_id = e.id)) AS published_without_hosts,
                (SELECT COUNT(*)
                 FROM events e
                 WHERE e.status = 'published'
                   AND e.starts_at >= UTC_TIMESTAMP()
                   AND NOT EXISTS (
                       SELECT 1 FROM event_locations el
                       WHERE el.event_id = e.id
                         AND (el.location_id IS NOT NULL OR NULLIF(TRIM(el.private_location_label), '') IS NOT NULL)
                   )) AS published_without_locations,
                (SELECT COUNT(*)
                 FROM events e
                 WHERE e.status = 'published'
                   AND e.starts_at >= UTC_TIMESTAMP()
                   AND NOT EXISTS (SELECT 1 FROM event_invitations ei WHERE ei.event_id = e.id AND ei.status <> 'revoked')) AS published_without_invitations,
                (SELECT COUNT(*) FROM event_invitations WHERE status <> 'revoked') AS event_invitations,
                (SELECT COUNT(*) FROM event_invitations WHERE status = 'pending') AS pending_event_invitations,
                (SELECT COUNT(*) FROM event_invitations WHERE status = 'accepted') AS accepted_event_invitations,
                (SELECT COUNT(*) FROM event_rsvps WHERE response = 'attending') AS attending_rsvps,
                (SELECT COUNT(*) FROM artist_profiles WHERE status <> 'archived') AS artists,
                (SELECT COUNT(*) FROM reward_templates WHERE status = 'active') AS active_rewards,
                (SELECT COUNT(*) FROM reward_templates WHERE status = 'draft') AS draft_rewards,
                (SELECT COUNT(*) FROM campaigns WHERE status = 'active') AS active_campaigns,
                (SELECT COUNT(*) FROM campaigns WHERE status = 'draft') AS draft_campaigns,
                (SELECT COUNT(*) FROM campaign_event_links) AS campaign_event_links,
                (SELECT COUNT(*) FROM venue_relationships WHERE relationship_status <> 'new') AS venue_relationships,
                (SELECT COUNT(*) FROM reward_claims WHERE claimed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)) AS claims_30d,
                (SELECT COUNT(*) FROM role_requests WHERE status = 'pending') AS pending_role_requests"
        )->fetch() ?: [];
    } catch (Throwable $e) {
        $issues[] = 'core_metrics';
        error_log('Admin Agent core metrics unavailable: ' . $e->getMessage());
        return [];
    }

    $metrics = [];
    foreach ($row as $key => $value) {
        $metrics[(string)$key] = (int)$value;
    }
    return $metrics;
}

/** @return array<string,int> */
function coveted_admin_agent_crm_metrics(PDO $pdo, array &$issues): array
{
    try {
        $row = $pdo->query(
            "SELECT
                SUM(status = 'new') AS new_count,
                SUM(status = 'contacted') AS contacted_count,
                SUM(status = 'qualified') AS qualified_count,
                SUM(status = 'converted') AS converted_count,
                SUM(status = 'declined') AS declined_count
             FROM invite_requests"
        )->fetch() ?: [];
    } catch (Throwable $e) {
        // Invite CRM is migration-backed on older installs. Its absence must
        // never take down the Admin Agent.
        $issues[] = 'invite_crm';
        return [
            'new_count' => 0,
            'contacted_count' => 0,
            'qualified_count' => 0,
            'converted_count' => 0,
            'declined_count' => 0,
        ];
    }

    $result = [];
    foreach ($row as $key => $value) {
        $result[(string)$key] = (int)$value;
    }
    return $result;
}

/** @return array<int,array<string,string>> */
function coveted_admin_agent_recent_memory(PDO $pdo, array &$issues): array
{
    try {
        $rows = $pdo->query(
            "SELECT ae.event_type, ae.entity_type, ae.entity_id, ae.created_at,
                    COALESCE(u.display_name, 'System') AS actor_name
             FROM audit_events ae
             LEFT JOIN users u ON u.id = ae.actor_user_id
             ORDER BY ae.created_at DESC, ae.id DESC
             LIMIT 24"
        )->fetchAll();
    } catch (Throwable $e) {
        $issues[] = 'audit_memory';
        return [];
    }

    return array_map(static fn(array $row): array => [
        'event_type' => (string)($row['event_type'] ?? ''),
        'entity_type' => (string)($row['entity_type'] ?? ''),
        'entity_id' => (string)($row['entity_id'] ?? ''),
        'actor' => (string)($row['actor_name'] ?? 'System'),
        'at' => (string)($row['created_at'] ?? ''),
    ], $rows);
}

/** @return array<int,array<string,mixed>> */
function coveted_admin_agent_capabilities(): array
{
    return [
        ['key' => 'people', 'label' => 'People & access', 'href' => '/admin/?view=users', 'can' => ['create users', 'grant or revoke platform roles', 'suspend or reactivate accounts', 'reset passwords']],
        ['key' => 'crm', 'label' => 'Invite CRM', 'href' => '/admin/crm.php', 'can' => ['review invite requests', 'qualify prospects', 'convert approved prospects into member accounts']],
        ['key' => 'businesses', 'label' => 'Businesses', 'href' => '/admin/?view=businesses', 'can' => ['create partner businesses', 'assign Business Admins', 'open business workspaces for locations, rewards and campaigns']],
        ['key' => 'groups', 'label' => 'Groups', 'href' => '/admin/?view=groups', 'can' => ['create private communities', 'manage status', 'open group membership and host workflows']],
        ['key' => 'events', 'label' => 'Events', 'href' => '/admin/?view=events', 'can' => ['create events inside groups', 'manage hosts, locations, invitations, RSVPs, attendance and lifecycle']],
        ['key' => 'artists', 'label' => 'Artist partners', 'href' => '/admin/?view=artists', 'can' => ['create artist identities', 'manage artist workspaces, appearances, media and rewards']],
        ['key' => 'benefits', 'label' => 'Benefits & campaigns', 'href' => '/admin/?view=benefits', 'can' => ['review reward templates and campaigns', 'connect campaign value to event and partner activity']],
        ['key' => 'distribution', 'label' => 'Distribution', 'href' => '/admin/?view=distribution', 'can' => ['preview eligible recipients', 'distribute event campaigns', 'send manual campaign rewards']],
        ['key' => 'cities', 'label' => 'Cities', 'href' => '/admin/cities.php', 'can' => ['manage supported city records used by acquisition and CRM']],
        ['key' => 'landing', 'label' => 'Landing page', 'href' => '/admin/landing.php', 'can' => ['control public upcoming-event visibility', 'switch synthetic landing preview events']],
        ['key' => 'operations', 'label' => 'Operations', 'href' => '/admin/operations.php', 'can' => ['inspect event lifecycle backlog', 'find location gaps', 'review delivery failures', 'review claims and audit history']],
        ['key' => 'pwa', 'label' => 'PWA & notifications', 'href' => '/admin/?view=pwa', 'can' => ['upload install artwork', 'inspect notification delivery', 'create test notifications']],
        ['key' => 'ai', 'label' => 'AI providers', 'href' => '/admin/ai-settings.php', 'can' => ['configure OpenAI and Anthropic chat providers', 'store ElevenLabs credentials for voice services']],
    ];
}

/** @return array{ready:int,total:int,percent:int,checks:array<int,array<string,mixed>>} */
function coveted_admin_agent_readiness(array $metrics, bool $chatReady): array
{
    $businesses = (int)($metrics['businesses'] ?? 0);
    $groups = (int)($metrics['groups'] ?? 0);
    $published = (int)($metrics['published_future_events'] ?? 0);

    $checks = [
        ['key' => 'ai', 'label' => 'Admin AI provider', 'done' => $chatReady],
        ['key' => 'people', 'label' => 'Member base started', 'done' => (int)($metrics['active_users'] ?? 0) > 1],
        ['key' => 'business', 'label' => 'Partner business added', 'done' => $businesses > 0],
        ['key' => 'business_location', 'label' => 'Business locations covered', 'done' => $businesses > 0 && (int)($metrics['businesses_without_locations'] ?? 0) === 0],
        ['key' => 'business_admin', 'label' => 'Business ownership covered', 'done' => $businesses > 0 && (int)($metrics['businesses_without_admins'] ?? 0) === 0],
        ['key' => 'group', 'label' => 'Community group added', 'done' => $groups > 0],
        ['key' => 'group_leadership', 'label' => 'Group leadership covered', 'done' => $groups > 0 && (int)($metrics['groups_without_leadership'] ?? 0) === 0],
        ['key' => 'event', 'label' => 'Event created', 'done' => (int)($metrics['events'] ?? 0) > 0],
        ['key' => 'published_event', 'label' => 'Future event published', 'done' => $published > 0],
        ['key' => 'event_host', 'label' => 'Published event hosts covered', 'done' => $published > 0 && (int)($metrics['published_without_hosts'] ?? 0) === 0],
        ['key' => 'event_location', 'label' => 'Published event locations covered', 'done' => $published > 0 && (int)($metrics['published_without_locations'] ?? 0) === 0],
        ['key' => 'invitations', 'label' => 'Invitation flow exercised', 'done' => (int)($metrics['event_invitations'] ?? 0) > 0],
        ['key' => 'value', 'label' => 'Active member value configured', 'done' => (int)($metrics['active_rewards'] ?? 0) > 0 && (int)($metrics['active_campaigns'] ?? 0) > 0],
    ];

    $ready = count(array_filter($checks, static fn(array $check): bool => !empty($check['done'])));
    $total = count($checks);

    return [
        'ready' => $ready,
        'total' => $total,
        'percent' => $total > 0 ? (int)round(($ready / $total) * 100) : 100,
        'checks' => $checks,
    ];
}

/** @return array<int,array<string,mixed>> */
function coveted_admin_agent_opportunities(
    array $metrics,
    array $crm,
    array $operations,
    bool $chatReady,
    bool $landingEventsEnabled,
    int $pwaAssetsReady,
    int $pwaAssetsTotal
): array {
    $items = [];
    $add = static function (
        int $priority,
        string $key,
        string $category,
        string $title,
        string $detail,
        string $href,
        string $evidence = ''
    ) use (&$items): void {
        $items[] = compact('priority', 'key', 'category', 'title', 'detail', 'href', 'evidence');
    };

    $summary = (array)($operations['summary'] ?? []);

    if (!$chatReady) {
        $add(1, 'ai-provider', 'Platform', 'Connect the Admin Agent to an AI provider', 'Add and enable OpenAI or Anthropic so the Agent can reason over this live Coveted state with you.', '/admin/ai-settings.php', 'No enabled chat provider is currently configured.');
    }
    if ((int)($summary['overdue_events'] ?? 0) > 0) {
        $count = (int)$summary['overdue_events'];
        $add(1, 'overdue-events', 'Operations', 'Close out overdue event lifecycle work', 'Published or closed events are past their lifecycle window and need review.', '/admin/operations.php', $count . ' overdue event' . ($count === 1 ? '' : 's') . '.');
    }
    if ((int)($summary['lifecycle_backlog'] ?? 0) > 0) {
        $count = (int)$summary['lifecycle_backlog'];
        $add(1, 'lifecycle-backlog', 'Operations', 'Reconcile lifecycle backlog', 'Invitation or Guest Pass state is stale enough to require the canonical lifecycle worker.', '/admin/operations.php', $count . ' stale lifecycle record' . ($count === 1 ? '' : 's') . '.');
    }
    if ((int)($summary['permanent_failures_24h'] ?? 0) > 0 || (int)($summary['stuck_deliveries'] ?? 0) > 0) {
        $count = (int)($summary['permanent_failures_24h'] ?? 0) + (int)($summary['stuck_deliveries'] ?? 0);
        $add(1, 'delivery-health', 'Operations', 'Review notification delivery failures', 'Push delivery has permanent failures or records stuck in the canonical queue.', '/admin/operations.php', $count . ' delivery item' . ($count === 1 ? '' : 's') . ' need attention.');
    }
    if ((int)($metrics['pending_role_requests'] ?? 0) > 0) {
        $count = (int)$metrics['pending_role_requests'];
        $add(1, 'role-requests', 'People', 'Review pending role requests', 'Members are waiting for an Admin decision on expanded platform access.', '/admin/?view=requests', $count . ' pending request' . ($count === 1 ? '' : 's') . '.');
    }
    if ((int)($crm['new_count'] ?? 0) > 0 || (int)($crm['qualified_count'] ?? 0) > 0) {
        $count = (int)($crm['new_count'] ?? 0) + (int)($crm['qualified_count'] ?? 0);
        $add(1, 'crm-pipeline', 'Growth', 'Work the People CRM pipeline', 'New or qualified prospects are ready for review, outreach or conversion.', '/admin/crm.php', $count . ' new/qualified CRM record' . ($count === 1 ? '' : 's') . '.');
    }

    if ((int)($metrics['businesses'] ?? 0) === 0) {
        $add(2, 'first-business', 'Partners', 'Add a partner business', 'Create the first venue or partner business so locations, partner rewards and business campaigns can be configured.', '/admin/?view=businesses#create-business');
    } else {
        if ((int)($metrics['businesses_without_locations'] ?? 0) > 0) {
            $count = (int)$metrics['businesses_without_locations'];
            $add(2, 'business-locations', 'Partners', 'Complete business locations', 'Some partner businesses cannot yet participate as canonical venues because they have no non-archived location.', '/admin/?view=businesses', $count . ' business' . ($count === 1 ? '' : 'es') . ' without a location.');
        }
        if ((int)($metrics['businesses_without_admins'] ?? 0) > 0) {
            $count = (int)$metrics['businesses_without_admins'];
            $add(2, 'business-admins', 'Partners', 'Assign Business Admin coverage', 'Give each partner business a scoped administrator so partner operations are not dependent on System Admin.', '/admin/?view=businesses', $count . ' business' . ($count === 1 ? '' : 'es') . ' without a Business Admin.');
        }
    }

    if ((int)($metrics['groups'] ?? 0) === 0) {
        $add(2, 'first-group', 'Community', 'Create the first Coveted group', 'Events belong to groups in the canonical schema, so a community context is needed before the first event can be created.', '/admin/?view=groups#create-group');
    } elseif ((int)($metrics['groups_without_leadership'] ?? 0) > 0) {
        $count = (int)$metrics['groups_without_leadership'];
        $add(2, 'group-leadership', 'Community', 'Add host or Group Admin coverage', 'Some groups have no active host or Group Admin membership.', '/admin/?view=groups', $count . ' group' . ($count === 1 ? '' : 's') . ' without active leadership.');
    }

    if ((int)($metrics['active_users'] ?? 0) <= 1) {
        $add(2, 'member-base', 'People', 'Add another member or host', 'The installation currently has only the initial active account, so group membership and invitation flows cannot be exercised end-to-end.', '/admin/?view=users#create-user');
    }

    if ((int)($metrics['events'] ?? 0) === 0 && (int)($metrics['groups'] ?? 0) > 0) {
        $add(2, 'first-event', 'Events', 'Create the first event', 'A group exists and the event workflow is ready to be exercised.', '/admin/?view=events#create-event');
    }
    if ((int)($metrics['draft_events'] ?? 0) > 0) {
        $count = (int)$metrics['draft_events'];
        $add(2, 'draft-events', 'Events', 'Review draft events for publishing', 'Draft gatherings exist but are not yet visible to their intended audience.', '/admin/?view=events', $count . ' draft event' . ($count === 1 ? '' : 's') . '.');
    }
    if ((int)($metrics['published_without_hosts'] ?? 0) > 0) {
        $count = (int)$metrics['published_without_hosts'];
        $add(1, 'event-hosts', 'Events', 'Assign hosts to published events', 'Future published events should have explicit operational ownership.', '/admin/?view=events', $count . ' published event' . ($count === 1 ? '' : 's') . ' without a host.');
    }
    if ((int)($metrics['published_without_locations'] ?? 0) > 0) {
        $count = (int)$metrics['published_without_locations'];
        $add(1, 'event-locations', 'Events', 'Finish published event location setup', 'Future published events are missing both a canonical venue and a private location label.', '/admin/operations.php', $count . ' published event' . ($count === 1 ? '' : 's') . ' without a location.');
    }
    if ((int)($metrics['published_without_invitations'] ?? 0) > 0) {
        $count = (int)$metrics['published_without_invitations'];
        $add(2, 'event-invitations', 'Events', 'Build attendance for published events', 'Future published events exist with no active invitation records.', '/admin/?view=events', $count . ' published event' . ($count === 1 ? '' : 's') . ' without invitations.');
    }

    if ((int)($metrics['active_rewards'] ?? 0) === 0 && ((int)($metrics['businesses'] ?? 0) > 0 || (int)($metrics['artists'] ?? 0) > 0)) {
        $add(3, 'active-reward', 'Member value', 'Activate a member reward', 'Partner or artist surfaces exist, but there is no active reward template available to create member value.', '/admin/?view=benefits');
    }
    if ((int)($metrics['active_campaigns'] ?? 0) === 0 && (int)($metrics['active_rewards'] ?? 0) > 0) {
        $add(3, 'active-campaign', 'Member value', 'Activate a campaign', 'Active rewards exist, but there is no active campaign deciding when or why those rewards are issued.', '/admin/?view=benefits');
    }
    if ((int)($metrics['active_campaigns'] ?? 0) > 0 && (int)($metrics['events'] ?? 0) > 0 && (int)($metrics['campaign_event_links'] ?? 0) === 0) {
        $add(3, 'campaign-event-links', 'Member value', 'Connect campaign value to event activity', 'Campaigns and events both exist, but no campaign is linked to an event yet.', '/admin/?view=distribution');
    }

    if (!$landingEventsEnabled && (int)($metrics['published_future_events'] ?? 0) > 0) {
        $add(3, 'landing-events', 'Public experience', 'Consider showing upcoming events publicly', 'Published group events exist while the public Upcoming Events section is disabled.', '/admin/landing.php', (int)$metrics['published_future_events'] . ' future published event' . ((int)$metrics['published_future_events'] === 1 ? '' : 's') . ' available.');
    }

    if ($pwaAssetsReady < $pwaAssetsTotal) {
        $missing = max(0, $pwaAssetsTotal - $pwaAssetsReady);
        $add(3, 'pwa-artwork', 'Installed app', 'Complete PWA artwork', 'Some install icon or splash slots are still missing.', '/admin/?view=pwa', $missing . ' artwork slot' . ($missing === 1 ? '' : 's') . ' incomplete.');
    }

    usort($items, static function (array $a, array $b): int {
        $priority = ((int)$a['priority']) <=> ((int)$b['priority']);
        return $priority !== 0 ? $priority : strcmp((string)$a['key'], (string)$b['key']);
    });

    return $items;
}

/** @return array<string,mixed> */
function coveted_admin_agent_snapshot(array $admin, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $pdo ??= coveted_db();
    $issues = [];
    $metrics = coveted_admin_agent_core_metrics($pdo, $issues);
    $crm = coveted_admin_agent_crm_metrics($pdo, $issues);
    $memory = coveted_admin_agent_recent_memory($pdo, $issues);

    try {
        $providers = coveted_ai_provider_statuses($pdo);
    } catch (Throwable $e) {
        $providers = [];
        $issues[] = 'ai_providers';
    }
    $chatReady = count(array_filter($providers, static fn(array $provider): bool =>
        in_array((string)($provider['provider'] ?? ''), ['openai', 'anthropic'], true)
        && !empty($provider['enabled'])
        && !empty($provider['configured'])
    )) > 0;

    try {
        $operations = coveted_operations_snapshot($admin);
    } catch (Throwable $e) {
        $operations = ['summary' => []];
        $issues[] = 'operations';
        error_log('Admin Agent operations snapshot unavailable: ' . $e->getMessage());
    }

    $landingEventsEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_EVENTS, false, $pdo);
    $sampleEventsEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_SAMPLE_EVENTS, false, $pdo);

    $pwaFiles = [
        'icon-192.png',
        'icon-512.png',
        'icon-maskable-512.png',
        'apple-touch-icon.png',
        'splash-portrait.png',
        'splash-landscape.png',
    ];
    $pwaReady = 0;
    $pwaDir = dirname(__DIR__) . '/uploads/pwa';
    foreach ($pwaFiles as $file) {
        if (is_file($pwaDir . '/' . $file)) {
            $pwaReady++;
        }
    }

    $readiness = coveted_admin_agent_readiness($metrics, $chatReady);
    $opportunities = coveted_admin_agent_opportunities(
        $metrics,
        $crm,
        $operations,
        $chatReady,
        $landingEventsEnabled,
        $pwaReady,
        count($pwaFiles)
    );

    $app = coveted_config('app');

    return [
        'generated_at' => gmdate('Y-m-d H:i:s'),
        'installation' => [
            'name' => trim((string)($app['name'] ?? 'Coveted')) ?: 'Coveted',
            'environment' => (string)($app['environment'] ?? 'production'),
            'base_url' => (string)($app['base_url'] ?? ''),
            'timezone' => (string)($app['default_timezone'] ?? 'UTC'),
        ],
        'readiness' => $readiness,
        'metrics' => $metrics,
        'crm' => $crm,
        'operations' => ['summary' => (array)($operations['summary'] ?? [])],
        'public_experience' => [
            'landing_events_enabled' => $landingEventsEnabled,
            'sample_events_enabled' => $sampleEventsEnabled,
        ],
        'pwa' => ['assets_ready' => $pwaReady, 'assets_total' => count($pwaFiles)],
        'providers' => array_map(static fn(array $provider): array => [
            'provider' => (string)($provider['provider'] ?? ''),
            'configured' => !empty($provider['configured']),
            'enabled' => !empty($provider['enabled']),
            'model' => (string)($provider['model'] ?? ''),
        ], $providers),
        'opportunities' => $opportunities,
        'capabilities' => coveted_admin_agent_capabilities(),
        'memory' => $memory,
        'issues' => array_values(array_unique($issues)),
    ];
}

/**
 * Compact server-supplied context for the provider. This contains operational
 * facts and navigation capabilities, never API credentials or private feedback
 * payloads.
 */
function coveted_admin_agent_context_message(array $snapshot): string
{
    $context = [
        'generated_at' => $snapshot['generated_at'] ?? null,
        'installation' => $snapshot['installation'] ?? [],
        'readiness' => $snapshot['readiness'] ?? [],
        'metrics' => $snapshot['metrics'] ?? [],
        'crm' => $snapshot['crm'] ?? [],
        'operations' => $snapshot['operations'] ?? [],
        'public_experience' => $snapshot['public_experience'] ?? [],
        'pwa' => $snapshot['pwa'] ?? [],
        'opportunities' => array_slice((array)($snapshot['opportunities'] ?? []), 0, 12),
        'capabilities' => $snapshot['capabilities'] ?? [],
        'recent_audit_memory' => array_slice((array)($snapshot['memory'] ?? []), 0, 12),
        'data_issues' => $snapshot['issues'] ?? [],
    ];

    $json = coveted_json($context);
    if (strlen($json) > 11000) {
        $context['recent_audit_memory'] = array_slice((array)$context['recent_audit_memory'], 0, 5);
        $context['opportunities'] = array_slice((array)$context['opportunities'], 0, 8);
        $json = coveted_json($context);
    }

    return "SERVER-SUPPLIED COVETED ADMIN CONTEXT\n"
        . "This block is generated by Coveted from canonical database/services, not typed by the administrator. Treat it as read-only current application state and historical audit memory. Do not invent relationships that are not represented here or in Coveted's capability catalog. When recommending work, prioritize listed opportunities and explain the underlying evidence. You may direct the administrator to the provided internal Admin routes, but do not claim you executed a mutation unless a future server tool explicitly confirms it.\n\n"
        . $json;
}
