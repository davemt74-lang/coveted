<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/** @return array{category:string,title:string,href:string}|null */
function coveted_admin_agent_activity_definition(string $eventType): ?array
{
    $eventType = strtolower(trim($eventType));
    if ($eventType === '') {
        return null;
    }

    // CRM submission creation already has its own richer live card. Agent/tool
    // audit and configuration churn are intentionally excluded to keep this
    // stream useful rather than self-referential/noisy.
    foreach ([
        'invite_request.created',
        'admin.agent_',
        'site_setting.updated',
        'admin.ai_provider_',
    ] as $excluded) {
        if ($eventType === $excluded || str_starts_with($eventType, $excluded)) {
            return null;
        }
    }

    // Authentication/password/session activity belongs in security logs, not
    // the operating feed. User/account creation and status changes still pass.
    foreach (['login', 'logout', 'password', 'session', 'csrf', 'auth.'] as $securityNoise) {
        if (str_contains($eventType, $securityNoise)) {
            return null;
        }
    }

    $exact = [
        'role.requested' => ['Access', 'New role request', '/admin/?view=requests'],
        'admin.role_request_approved' => ['Access', 'Role request approved', '/admin/?view=requests'],
        'admin.role_request_declined' => ['Access', 'Role request declined', '/admin/?view=requests'],
        'admin.user_created' => ['People', 'Member account created', '/admin/?view=users'],
        'event.rsvp_updated' => ['Events', 'Event RSVP updated', '/admin/?view=events'],
        'event.invitation_response' => ['Events', 'Event invitation response', '/admin/?view=events'],
        'event.waitlist_promoted' => ['Events', 'Waitlist member promoted', '/admin/?view=events'],
        'event.host_assigned' => ['Events', 'Event host assigned', '/admin/?view=events'],
        'event.attendance_recorded' => ['Events', 'Event attendance recorded', '/admin/?view=events'],
        'event.status_changed' => ['Events', 'Event status changed', '/admin/?view=events'],
        'event.created' => ['Events', 'Event created', '/admin/?view=events'],
        'business.created' => ['Partners', 'Business created', '/admin/?view=businesses'],
        'business.admin_added' => ['Partners', 'Business Admin assigned', '/admin/?view=businesses'],
        'business.admin_removed' => ['Partners', 'Business Admin removed', '/admin/?view=businesses'],
        'location.created' => ['Partners', 'Business location created', '/admin/?view=businesses'],
        'group.created' => ['Community', 'Group created', '/admin/?view=groups'],
        'group.member_removed' => ['Community', 'Group member removed', '/admin/?view=groups'],
        'group.guest_became_member' => ['Community', 'Guest became a member', '/admin/?view=groups'],
        'campaign.created' => ['Benefits', 'Campaign created', '/admin/?view=benefits'],
        'campaign.status_changed' => ['Benefits', 'Campaign status changed', '/admin/?view=benefits'],
        'campaign.event_linked' => ['Benefits', 'Campaign linked to event', '/admin/?view=benefits'],
        'reward.claimed' => ['Benefits', 'Reward claimed', '/admin/?view=benefits'],
        'reward.refunded' => ['Benefits', 'Reward refunded', '/admin/?view=benefits'],
        'admin.invite_request_updated' => ['CRM', 'CRM record updated', '/admin/crm.php'],
        'admin.invite_request_converted' => ['CRM', 'CRM prospect converted', '/admin/crm.php'],
    ];
    if (isset($exact[$eventType])) {
        [$category, $title, $href] = $exact[$eventType];
        return compact('category', 'title', 'href');
    }

    $prefixes = [
        'role.' => ['Access', '/admin/?view=requests'],
        'admin.role_' => ['Access', '/admin/?view=requests'],
        'user.' => ['People', '/admin/?view=users'],
        'admin.user_' => ['People', '/admin/?view=users'],
        'member.' => ['People', '/admin/?view=users'],
        'event.' => ['Events', '/admin/?view=events'],
        'group.' => ['Community', '/admin/?view=groups'],
        'business.' => ['Partners', '/admin/?view=businesses'],
        'location.' => ['Partners', '/admin/?view=businesses'],
        'venue.' => ['Partners', '/admin/?view=businesses'],
        'artist.' => ['Artists', '/admin/?view=artists'],
        'campaign.' => ['Benefits', '/admin/?view=benefits'],
        'reward.' => ['Benefits', '/admin/?view=benefits'],
        'claim.' => ['Benefits', '/admin/?view=benefits'],
        'distribution.' => ['Distribution', '/admin/?view=distribution'],
        'notification.' => ['Operations', '/admin/operations.php'],
        'delivery.' => ['Operations', '/admin/operations.php'],
        'push.' => ['Operations', '/admin/operations.php'],
        'operation.' => ['Operations', '/admin/operations.php'],
        'admin.invite_request_' => ['CRM', '/admin/crm.php'],
        'admin.city_' => ['Cities', '/admin/cities.php'],
    ];

    foreach ($prefixes as $prefix => [$category, $href]) {
        if (!str_starts_with($eventType, $prefix)) {
            continue;
        }
        $human = preg_replace('/[._-]+/', ' ', $eventType) ?: $eventType;
        $human = preg_replace('/\s+/', ' ', trim($human)) ?: $human;
        return [
            'category' => $category,
            'title' => ucfirst($human),
            'href' => $href,
        ];
    }

    return null;
}

/** @return array<int,string> */
function coveted_admin_agent_activity_metadata_lines(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') {
        return [];
    }

    try {
        $metadata = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return [];
    }
    if (!is_array($metadata)) {
        return [];
    }

    // Deliberately narrow: do not surface emails, notes, provider payloads,
    // private feedback or arbitrary metadata text in the always-visible feed.
    $labels = [
        'status' => 'Status',
        'response' => 'RSVP',
        'decision' => 'Decision',
        'role' => 'Role',
        'host_role' => 'Host role',
        'guest_count' => 'Guests',
        'enabled' => 'Enabled',
        'activity_type' => 'Activity',
    ];
    $lines = [];
    foreach ($labels as $key => $label) {
        if (!array_key_exists($key, $metadata) || is_array($metadata[$key]) || is_object($metadata[$key])) {
            continue;
        }
        $value = $metadata[$key];
        if (is_bool($value)) {
            $value = $value ? 'yes' : 'no';
        }
        $value = trim((string)$value);
        if ($value === '' || mb_strlen($value) > 120) {
            continue;
        }
        $lines[] = $label . ': ' . $value;
        if (count($lines) >= 3) {
            break;
        }
    }
    return $lines;
}

try {
    $admin = coveted_require_system_admin();
    unset($admin);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        echo coveted_json(['ok' => false, 'error' => 'GET required.']);
        exit;
    }

    $cursorRaw = trim((string)($_GET['cursor'] ?? '0'));
    if ($cursorRaw === '' || preg_match('/^\d{1,20}$/', $cursorRaw) !== 1) {
        throw new InvalidArgumentException('Invalid activity cursor.');
    }
    $cursor = max(0, (int)$cursorRaw);
    $pdo = coveted_db();

    try {
        $latest = (int)($pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_events')->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        echo coveted_json([
            'ok' => true,
            'available' => false,
            'cursor' => $cursor,
            'items' => [],
            'has_more' => false,
        ]);
        exit;
    }

    if ($cursor > $latest) {
        $cursor = $latest;
    }

    $stmt = $pdo->prepare(
        "SELECT ae.id, ae.event_type, ae.entity_type, ae.entity_id, ae.metadata_json, ae.created_at,
                COALESCE(u.display_name, 'System') AS actor_name
         FROM audit_events ae
         LEFT JOIN users u ON u.id = ae.actor_user_id
         WHERE ae.id > ?
         ORDER BY ae.id ASC
         LIMIT 101"
    );
    $stmt->execute([$cursor]);
    $rows = $stmt->fetchAll();

    $items = [];
    $scannedCursor = $cursor;
    $hasMore = false;
    foreach ($rows as $index => $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $scannedCursor = $id;
        $definition = coveted_admin_agent_activity_definition((string)($row['event_type'] ?? ''));
        if ($definition === null) {
            continue;
        }

        $entityType = trim((string)($row['entity_type'] ?? ''));
        $entityId = trim((string)($row['entity_id'] ?? ''));
        $detailParts = [];
        $actor = trim((string)($row['actor_name'] ?? 'System')) ?: 'System';
        $detailParts[] = 'By ' . mb_substr($actor, 0, 180);
        if ($entityType !== '' && $entityId !== '') {
            $detailParts[] = ucfirst(str_replace('_', ' ', $entityType)) . ': ' . mb_substr($entityId, 0, 190);
        }
        $detailParts = array_merge(
            $detailParts,
            coveted_admin_agent_activity_metadata_lines((string)($row['metadata_json'] ?? ''))
        );

        $occurredAt = '';
        try {
            $occurredAt = coveted_utc_datetime((string)$row['created_at'])
                ->setTimezone(coveted_timezone())
                ->format('M j, g:i A');
        } catch (Throwable) {
            $occurredAt = '';
        }

        $items[] = [
            'id' => $id,
            'event_type' => (string)$row['event_type'],
            'category' => (string)$definition['category'],
            'title' => (string)$definition['title'],
            'detail' => implode(' · ', array_slice($detailParts, 0, 4)),
            'occurred_at' => $occurredAt,
            'href' => (string)$definition['href'],
        ];

        if (count($items) >= 25) {
            $hasMore = $index < count($rows) - 1 || count($rows) === 101;
            break;
        }
    }

    if (count($items) < 25 && count($rows) === 101) {
        $hasMore = true;
    }

    echo coveted_json([
        'ok' => true,
        'available' => true,
        'cursor' => $scannedCursor,
        'items' => $items,
        'has_more' => $hasMore,
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo coveted_json(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Admin Agent operational activity failed: ' . $e->getMessage());
    http_response_code(500);
    echo coveted_json(['ok' => false, 'error' => 'Operational activity is temporarily unavailable.']);
}
