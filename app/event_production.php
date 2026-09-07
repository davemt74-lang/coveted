<?php
declare(strict_types=1);

require_once __DIR__ . '/event_management.php';

/** @return array<int,string> */
function coveted_event_production_phases(): array
{
    return ['planning','pre_event','arrival','live','closeout'];
}

/** @return array<int,string> */
function coveted_event_production_item_types(): array
{
    return ['task','checklist','timing','staffing','venue','artist','guest','benefit','safety','other'];
}

/** @return array<int,string> */
function coveted_event_production_statuses(): array
{
    return ['open','in_progress','blocked','completed','cancelled'];
}

function coveted_event_production_schema_available(?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    try {
        $pdo->query('SELECT id FROM event_production_items LIMIT 1');
        $pdo->query('SELECT id FROM event_production_notes LIMIT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** @return array<string,mixed> */
function coveted_event_production_require_event(array $actor, string $eventRef, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $eventRef = trim($eventRef);
    if ($eventRef === '' || strlen($eventRef) > 64) {
        throw new InvalidArgumentException('Event not found.');
    }

    $stmt = $pdo->prepare(
        "SELECT e.*, g.public_id AS group_ref, g.name AS group_name, g.status AS group_status
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         WHERE e.public_id = ? OR CAST(e.id AS CHAR) = ?
         LIMIT 1"
    );
    $stmt->execute([$eventRef, $eventRef]);
    $event = $stmt->fetch();
    if (!$event || !coveted_event_can_manage($event, $actor)) {
        throw new InvalidArgumentException('You cannot manage this event.');
    }
    return $event;
}

/** @return array<int,array<string,mixed>> */
function coveted_event_production_default_items(array $event): array
{
    $start = coveted_utc_datetime((string)$event['starts_at']);
    $pre = $start->modify('-3 days')->format('Y-m-d H:i:s');
    $arrival = $start->modify('-90 minutes')->format('Y-m-d H:i:s');
    $live = $start->format('Y-m-d H:i:s');
    $closeout = $start->modify('+4 hours')->format('Y-m-d H:i:s');

    return [
        ['phase'=>'planning','item_type'=>'venue','title'=>'Confirm venue operating details','detail'=>'Confirm access, service window, capacity assumptions and the primary venue contact.','priority'=>'high','due_at'=>$pre,'sort_order'=>10],
        ['phase'=>'planning','item_type'=>'staffing','title'=>'Assign the lead host','detail'=>'Ensure one approved Attendee Host owns the on-site experience. Event creation authority remains System Admin-only.','priority'=>'high','due_at'=>$pre,'sort_order'=>20],
        ['phase'=>'planning','item_type'=>'guest','title'=>'Review capacity and guest policy','detail'=>'Verify capacity, +1 policy, audience and invitation approach before publishing.','priority'=>'normal','due_at'=>$pre,'sort_order'=>30],
        ['phase'=>'planning','item_type'=>'benefit','title'=>'Confirm member value plan','detail'=>'Review linked rewards, campaigns, Partner Perks or post-event value. Benefits are optional but should be intentional.','priority'=>'normal','due_at'=>$pre,'sort_order'=>40],
        ['phase'=>'pre_event','item_type'=>'guest','title'=>'Review RSVP and invitation readiness','detail'=>'Check invitations, attending count, waitlist pressure and any guest-list exceptions.','priority'=>'high','due_at'=>$pre,'sort_order'=>50],
        ['phase'=>'pre_event','item_type'=>'venue','title'=>'Confirm venue arrival window','detail'=>'Confirm staff arrival, entrance, parking/access notes and check-in location.','priority'=>'normal','due_at'=>$pre,'sort_order'=>60],
        ['phase'=>'pre_event','item_type'=>'artist','title'=>'Confirm artist or entertainment timing','detail'=>'If the event includes an artist, verify arrival, set timing and appearance role. Mark not applicable by completing the item when no artist is planned.','priority'=>'normal','due_at'=>$pre,'sort_order'=>70],
        ['phase'=>'arrival','item_type'=>'staffing','title'=>'Host team arrival and briefing','detail'=>'Confirm lead/cohost/check-in roles and review the run of show before guests arrive.','priority'=>'high','due_at'=>$arrival,'sort_order'=>80],
        ['phase'=>'arrival','item_type'=>'guest','title'=>'Check-in station ready','detail'=>'Verify attendee list, claim/check-in tools and any private-location instructions.','priority'=>'high','due_at'=>$arrival,'sort_order'=>90],
        ['phase'=>'live','item_type'=>'guest','title'=>'Monitor arrivals and attendance','detail'=>'Keep canonical attendance current and surface any waitlist or guest issues.','priority'=>'normal','due_at'=>$live,'sort_order'=>100],
        ['phase'=>'live','item_type'=>'safety','title'=>'Track incidents or escalations','detail'=>'Record only meaningful operational incidents, venue issues or guest escalations.','priority'=>'normal','due_at'=>$live,'sort_order'=>110],
        ['phase'=>'closeout','item_type'=>'guest','title'=>'Finalize attendance','detail'=>'Resolve checked-in, attended, left-early and no-show states before completing the event.','priority'=>'high','due_at'=>$closeout,'sort_order'=>120],
        ['phase'=>'closeout','item_type'=>'benefit','title'=>'Review reward and claim follow-through','detail'=>'Confirm post-event reward automation/value delivery and note any partner follow-up required.','priority'=>'normal','due_at'=>$closeout,'sort_order'=>130],
        ['phase'=>'closeout','item_type'=>'venue','title'=>'Close partner follow-up','detail'=>'Capture venue feedback, relationship notes and the next recommended action in Partner CRM.','priority'=>'normal','due_at'=>$closeout,'sort_order'=>140],
    ];
}

function coveted_event_production_seed_defaults(array $admin, string $eventRef, ?PDO $pdo = null): int
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    if (!coveted_event_production_schema_available($pdo)) {
        throw new RuntimeException('Import the Event Production migration before creating production items.');
    }
    $event = coveted_event_production_require_event($admin, $eventRef, $pdo);

    $existing = $pdo->prepare('SELECT COUNT(*) FROM event_production_items WHERE event_id = ?');
    $existing->execute([(int)$event['id']]);
    if ((int)$existing->fetchColumn() > 0) {
        return 0;
    }

    $insert = $pdo->prepare(
        "INSERT INTO event_production_items
            (public_id,event_id,phase,item_type,title,detail,priority,status,due_at,sort_order,created_by_user_id)
         VALUES (?,?,?,?,?,?,?,'open',?,?,?)"
    );

    $created = 0;
    $pdo->beginTransaction();
    try {
        foreach (coveted_event_production_default_items($event) as $item) {
            $insert->execute([
                coveted_uuid('prod'), (int)$event['id'], (string)$item['phase'], (string)$item['item_type'],
                (string)$item['title'], (string)$item['detail'], (string)$item['priority'],
                (string)$item['due_at'], (int)$item['sort_order'], (int)$admin['id'],
            ]);
            $created++;
        }
        coveted_audit('event.production_seeded', 'event', (string)$event['public_id'], ['items' => $created], (int)$admin['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $created;
}

/** @return array<string,mixed> */
function coveted_event_production_create_item(array $admin, string $eventRef, array $data, ?PDO $pdo = null): array
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    if (!coveted_event_production_schema_available($pdo)) {
        throw new RuntimeException('Import the Event Production migration before adding production items.');
    }
    $event = coveted_event_production_require_event($admin, $eventRef, $pdo);

    $phase = strtolower(trim((string)($data['phase'] ?? 'planning')));
    $type = strtolower(trim((string)($data['item_type'] ?? 'task')));
    $priority = strtolower(trim((string)($data['priority'] ?? 'normal')));
    $title = trim((string)($data['title'] ?? ''));
    $detail = trim((string)($data['detail'] ?? ''));
    $assigned = (int)($data['assigned_user_id'] ?? 0);
    $dueAt = trim((string)($data['due_at'] ?? ''));

    if (!in_array($phase, coveted_event_production_phases(), true)) throw new InvalidArgumentException('Choose a valid production phase.');
    if (!in_array($type, coveted_event_production_item_types(), true)) throw new InvalidArgumentException('Choose a valid production item type.');
    if (!in_array($priority, ['low','normal','high','critical'], true)) throw new InvalidArgumentException('Choose a valid priority.');
    if ($title === '' || mb_strlen($title) > 255) throw new InvalidArgumentException('Enter a production item title.');
    if (mb_strlen($detail) > 5000) throw new InvalidArgumentException('Production item detail is too long.');

    if ($assigned > 0) {
        $host = $pdo->prepare(
            "SELECT 1 FROM event_hosts eh JOIN users u ON u.id = eh.user_id
             WHERE eh.event_id = ? AND eh.user_id = ? AND u.status = 'active' LIMIT 1"
        );
        $host->execute([(int)$event['id'], $assigned]);
        if (!$host->fetchColumn()) throw new InvalidArgumentException('Production items may be assigned only to an active event host.');
    }
    if ($dueAt !== '') {
        try { $dueAt = coveted_utc_datetime($dueAt)->format('Y-m-d H:i:s'); }
        catch (Throwable) { throw new InvalidArgumentException('Enter a valid production due date/time.'); }
    }

    $publicId = coveted_uuid('prod');
    $stmt = $pdo->prepare(
        "INSERT INTO event_production_items
            (public_id,event_id,phase,item_type,title,detail,priority,status,assigned_user_id,due_at,sort_order,created_by_user_id)
         VALUES (?,?,?,?,?,?,?,'open',?,?,?,?)"
    );
    $stmt->execute([
        $publicId, (int)$event['id'], $phase, $type, $title, $detail !== '' ? $detail : null,
        $priority, $assigned > 0 ? $assigned : null, $dueAt !== '' ? $dueAt : null,
        (int)($data['sort_order'] ?? 500), (int)$admin['id'],
    ]);
    coveted_audit('event.production_item_created', 'event', (string)$event['public_id'], ['item_ref'=>$publicId,'phase'=>$phase,'type'=>$type], (int)$admin['id']);
    return ['public_id'=>$publicId];
}

function coveted_event_production_update_item(array $admin, string $eventRef, string $itemRef, array $data, ?PDO $pdo = null): void
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    $event = coveted_event_production_require_event($admin, $eventRef, $pdo);
    $status = strtolower(trim((string)($data['status'] ?? '')));
    $assigned = (int)($data['assigned_user_id'] ?? 0);
    if (!in_array($status, coveted_event_production_statuses(), true)) throw new InvalidArgumentException('Choose a valid production status.');

    $item = $pdo->prepare('SELECT id FROM event_production_items WHERE event_id = ? AND public_id = ? LIMIT 1');
    $item->execute([(int)$event['id'], trim($itemRef)]);
    $itemId = (int)$item->fetchColumn();
    if ($itemId < 1) throw new InvalidArgumentException('Production item not found.');

    if ($assigned > 0) {
        $host = $pdo->prepare(
            "SELECT 1 FROM event_hosts eh JOIN users u ON u.id = eh.user_id
             WHERE eh.event_id = ? AND eh.user_id = ? AND u.status = 'active' LIMIT 1"
        );
        $host->execute([(int)$event['id'], $assigned]);
        if (!$host->fetchColumn()) throw new InvalidArgumentException('Production items may be assigned only to an active event host.');
    }

    $completed = $status === 'completed' ? gmdate('Y-m-d H:i:s') : null;
    $pdo->prepare(
        'UPDATE event_production_items SET status = ?, assigned_user_id = ?, completed_at = ?, updated_at = NOW() WHERE id = ?'
    )->execute([$status, $assigned > 0 ? $assigned : null, $completed, $itemId]);
    coveted_audit('event.production_item_updated', 'event', (string)$event['public_id'], ['item_ref'=>$itemRef,'status'=>$status,'assigned_user_id'=>$assigned ?: null], (int)$admin['id']);
}

function coveted_event_production_add_note(array $admin, string $eventRef, string $noteType, string $body, ?PDO $pdo = null): string
{
    coveted_event_require_system_admin($admin);
    $pdo ??= coveted_db();
    $event = coveted_event_production_require_event($admin, $eventRef, $pdo);
    $noteType = strtolower(trim($noteType));
    $body = trim($body);
    if (!in_array($noteType, ['general','run_of_show','venue','artist','guest','incident','closeout'], true)) throw new InvalidArgumentException('Choose a valid production note type.');
    if ($body === '' || mb_strlen($body) > 8000) throw new InvalidArgumentException('Enter a production note under 8,000 characters.');

    $publicId = coveted_uuid('pnote');
    $pdo->prepare(
        'INSERT INTO event_production_notes (public_id,event_id,note_type,body,created_by_user_id) VALUES (?,?,?,?,?)'
    )->execute([$publicId, (int)$event['id'], $noteType, $body, (int)$admin['id']]);
    coveted_audit('event.production_note_added', 'event', (string)$event['public_id'], ['note_ref'=>$publicId,'note_type'=>$noteType], (int)$admin['id']);
    return $publicId;
}

/** @return array<string,mixed> */
function coveted_event_production_snapshot(array $actor, string $eventRef, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $event = coveted_event_production_require_event($actor, $eventRef, $pdo);
    $schemaAvailable = coveted_event_production_schema_available($pdo);

    $summaryStmt = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM event_hosts WHERE event_id = e.id) AS host_count,
            (SELECT COUNT(*) FROM event_hosts WHERE event_id = e.id AND host_role = 'lead') AS lead_host_count,
            (SELECT COUNT(*) FROM event_locations WHERE event_id = e.id AND (location_id IS NOT NULL OR NULLIF(TRIM(private_location_label),'') IS NOT NULL)) AS location_count,
            (SELECT COUNT(*) FROM event_artists WHERE event_id = e.id) AS artist_count,
            (SELECT COUNT(*) FROM campaign_event_links WHERE event_id = e.id) AS campaign_count,
            (SELECT COUNT(*) FROM event_invitations WHERE event_id = e.id AND status <> 'revoked') AS invite_count,
            (SELECT COUNT(*) FROM event_rsvps WHERE event_id = e.id AND response = 'attending') AS attending_count,
            (SELECT COUNT(*) FROM event_rsvps WHERE event_id = e.id AND response = 'waitlist') AS waitlist_count,
            (SELECT COUNT(*) FROM event_attendance WHERE event_id = e.id AND status IN ('checked_in','attended','left_early')) AS verified_attendance
         FROM events e WHERE e.id = ? LIMIT 1"
    );
    $summaryStmt->execute([(int)$event['id']]);
    $canonical = $summaryStmt->fetch() ?: [];

    $items = [];
    $notes = [];
    if ($schemaAvailable) {
        $itemsStmt = $pdo->prepare(
            "SELECT epi.*, u.display_name AS assigned_name
             FROM event_production_items epi
             LEFT JOIN users u ON u.id = epi.assigned_user_id
             WHERE epi.event_id = ?
             ORDER BY FIELD(epi.phase,'planning','pre_event','arrival','live','closeout'), epi.sort_order, epi.id"
        );
        $itemsStmt->execute([(int)$event['id']]);
        $items = $itemsStmt->fetchAll();

        $notesStmt = $pdo->prepare(
            "SELECT epn.*, u.display_name AS author_name
             FROM event_production_notes epn
             JOIN users u ON u.id = epn.created_by_user_id
             WHERE epn.event_id = ? ORDER BY epn.created_at DESC, epn.id DESC LIMIT 100"
        );
        $notesStmt->execute([(int)$event['id']]);
        $notes = $notesStmt->fetchAll();
    }

    $open = count(array_filter($items, static fn(array $row): bool => in_array((string)$row['status'], ['open','in_progress'], true)));
    $blocked = count(array_filter($items, static fn(array $row): bool => (string)$row['status'] === 'blocked'));
    $complete = count(array_filter($items, static fn(array $row): bool => (string)$row['status'] === 'completed'));
    $activeItems = count(array_filter($items, static fn(array $row): bool => (string)$row['status'] !== 'cancelled'));

    $checks = [
        ['key'=>'location','label'=>'Venue/location configured','done'=>(int)($canonical['location_count'] ?? 0) > 0,'required'=>true],
        ['key'=>'lead_host','label'=>'Lead host assigned','done'=>(int)($canonical['lead_host_count'] ?? 0) > 0,'required'=>true],
        ['key'=>'guest_plan','label'=>'Invitations/guest list started','done'=>(int)($canonical['invite_count'] ?? 0) > 0 || (int)($canonical['attending_count'] ?? 0) > 0,'required'=>true],
        ['key'=>'production','label'=>'Production checklist started','done'=>$activeItems > 0,'required'=>true],
        ['key'=>'blocked','label'=>'No blocked production items','done'=>$blocked === 0,'required'=>true],
        ['key'=>'benefits','label'=>'Benefit/campaign plan linked','done'=>(int)($canonical['campaign_count'] ?? 0) > 0,'required'=>false],
        ['key'=>'artist','label'=>'Artist/entertainment configured','done'=>(int)($canonical['artist_count'] ?? 0) > 0,'required'=>false],
    ];
    $required = array_values(array_filter($checks, static fn(array $row): bool => !empty($row['required'])));
    $ready = count(array_filter($required, static fn(array $row): bool => !empty($row['done'])));
    $readiness = count($required) > 0 ? (int)round(($ready / count($required)) * 100) : 100;

    return [
        'event' => $event,
        'schema_available' => $schemaAvailable,
        'canonical' => array_map(static fn($v): int => (int)$v, $canonical),
        'items' => $items,
        'notes' => $notes,
        'checks' => $checks,
        'readiness' => $readiness,
        'production' => ['open'=>$open,'blocked'=>$blocked,'completed'=>$complete,'total'=>$activeItems],
    ];
}

/** @return array<string,mixed> */
function coveted_event_production_agent_context(array $admin, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if (!coveted_event_production_schema_available($pdo)) {
        return ['unavailable'=>true,'reason'=>'migration_not_installed','events'=>[],'attention'=>0];
    }

    $events = $pdo->query(
        "SELECT public_id FROM events
         WHERE status IN ('draft','published','closed')
           AND starts_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
         ORDER BY starts_at ASC LIMIT 16"
    )->fetchAll();

    $rows = [];
    $attention = 0;
    foreach ($events as $eventRow) {
        try {
            $snapshot = coveted_event_production_snapshot($admin, (string)$eventRow['public_id'], $pdo);
        } catch (Throwable) {
            continue;
        }
        $event = (array)$snapshot['event'];
        $blockers = array_values(array_map(
            static fn(array $check): string => (string)$check['label'],
            array_filter((array)$snapshot['checks'], static fn(array $check): bool => !empty($check['required']) && empty($check['done']))
        ));
        if ((int)$snapshot['readiness'] < 100 || (int)$snapshot['production']['blocked'] > 0) $attention++;
        $rows[] = [
            'event_ref'=>(string)$event['public_id'],
            'title'=>(string)$event['title'],
            'status'=>(string)$event['status'],
            'starts_at'=>(string)$event['starts_at'],
            'group'=>(string)$event['group_name'],
            'readiness'=>(int)$snapshot['readiness'],
            'open_items'=>(int)$snapshot['production']['open'],
            'blocked_items'=>(int)$snapshot['production']['blocked'],
            'attending'=>(int)$snapshot['canonical']['attending_count'],
            'verified_attendance'=>(int)$snapshot['canonical']['verified_attendance'],
            'blockers'=>$blockers,
            'href'=>'/admin/event.php?event=' . rawurlencode((string)$event['public_id']) . '&tab=production',
        ];
    }
    return ['unavailable'=>false,'events'=>$rows,'attention'=>$attention];
}
