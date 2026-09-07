<?php
declare(strict_types=1);

require_once __DIR__ . '/event_communications.php';
require_once __DIR__ . '/admin_agent_tasks.php';

/** @return array<string,mixed>|null */
function coveted_event_communications_agent_source_task(array $admin, string $sourceKey, ?PDO $pdo = null): ?array
{
    coveted_event_communications_require_admin($admin, $pdo);
    $pdo ??= coveted_db();
    if (!coveted_admin_agent_tasks_schema_available($pdo)) return null;
    $sourceKey = trim($sourceKey);
    if ($sourceKey === '' || strlen($sourceKey) > 120) return null;

    $stmt = $pdo->prepare(
        "SELECT id,public_id,title,detail,priority,status,source_key,source_href,created_at,updated_at
         FROM admin_agent_tasks
         WHERE owner_user_id=? AND source_type='opportunity' AND source_key=?
           AND status IN ('suggested','approved','in_progress')
         ORDER BY FIELD(status,'in_progress','approved','suggested'),updated_at DESC,id DESC
         LIMIT 1"
    );
    $stmt->execute([(int)$admin['id'], $sourceKey]);
    $task = $stmt->fetch();
    return $task ?: null;
}

/** @return array<string,mixed>|null */
function coveted_event_communications_agent_task(array $admin, string $eventRef, ?PDO $pdo = null): ?array
{
    return coveted_event_communications_agent_source_task(
        $admin,
        'event-communications-' . trim($eventRef),
        $pdo
    );
}

/** @return array<string,mixed> */
function coveted_event_communications_queue_metrics(PDO $pdo, array $event): array
{
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(notification_type='event.rsvp_reminder') AS rsvp_reminders,
            SUM(notification_type='event.confirmation') AS confirmations,
            SUM(notification_type='event.location') AS locations,
            SUM(notification_type='event.mystery_reveal') AS reveals,
            MAX(created_at) AS latest_at,
            MAX(CASE WHEN notification_type='event.rsvp_reminder' THEN created_at END) AS latest_rsvp_reminder_at,
            MAX(CASE WHEN notification_type='event.confirmation' THEN created_at END) AS latest_confirmation_at
         FROM notifications
         WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.event_id')) = ?
           AND notification_type IN ('event.rsvp_reminder','event.confirmation','event.location','event.mystery_reveal')"
    );
    $stmt->execute([(string)$event['public_id']]);
    $row = $stmt->fetch() ?: [];
    return [
        'total'=>(int)($row['total'] ?? 0),
        'rsvp_reminders'=>(int)($row['rsvp_reminders'] ?? 0),
        'confirmations'=>(int)($row['confirmations'] ?? 0),
        'locations'=>(int)($row['locations'] ?? 0),
        'reveals'=>(int)($row['reveals'] ?? 0),
        'latest_at'=>(string)($row['latest_at'] ?? ''),
        'latest_rsvp_reminder_at'=>(string)($row['latest_rsvp_reminder_at'] ?? ''),
        'latest_confirmation_at'=>(string)($row['latest_confirmation_at'] ?? ''),
    ];
}

/** @return array{ready:int,already_queued:int} */
function coveted_event_communications_reminder_queue_state(array $admin, array $event, PDO $pdo): array
{
    if ((string)$event['status'] !== 'published') return ['ready'=>0,'already_queued'=>0];
    $candidate = coveted_event_communications_reminder_candidates($admin, $event, $pdo);
    $ready = 0;
    $queued = 0;
    $find = $pdo->prepare('SELECT 1 FROM notifications WHERE user_id=? AND dedupe_key=? LIMIT 1');
    foreach ((array)$candidate['rows'] as $recipient) {
        $dedupe = 'event-rsvp-reminder:' . (string)$recipient['invitation_ref'] . ':' . (string)$recipient['cycle_token'];
        $find->execute([(int)$recipient['user_id'], $dedupe]);
        if ($find->fetchColumn()) $queued++; else $ready++;
    }
    return ['ready'=>$ready,'already_queued'=>$queued];
}

function coveted_event_communications_attending_count(PDO $pdo, int $eventId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM event_rsvps er
         JOIN users u ON u.id=er.user_id AND u.status='active'
         WHERE er.event_id=? AND er.response='attending'"
    );
    $stmt->execute([$eventId]);
    return (int)$stmt->fetchColumn();
}

function coveted_event_communications_responses_since(PDO $pdo, int $eventId, string $since): int
{
    if ($since === '') return 0;
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM event_rsvps
         WHERE event_id=? AND updated_at>?"
    );
    $stmt->execute([$eventId, $since]);
    return (int)$stmt->fetchColumn();
}

/** @return array<string,mixed> */
function coveted_event_communications_lifecycle_snapshot(array $admin, string $eventRef, ?PDO $pdo = null): array
{
    $pdo = coveted_event_communications_require_admin($admin, $pdo);
    $event = coveted_event_communications_event($admin, $eventRef, $pdo);
    $queue = coveted_event_communications_queue_metrics($pdo, $event);
    $reminders = coveted_event_communications_reminder_queue_state($admin, $event, $pdo);
    $attending = coveted_event_communications_attending_count($pdo, (int)$event['id']);
    $responsesSinceReminder = coveted_event_communications_responses_since(
        $pdo,
        (int)$event['id'],
        (string)$queue['latest_rsvp_reminder_at']
    );

    $forecast = ['low'=>0,'expected'=>0,'high'=>0,'target'=>0,'gap'=>0];
    $waveDecision = 'closed';
    $waitlist = 0;
    $responseWindow = 48;
    $daysToEvent = max(0.0, (coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() - time()) / 86400);
    if ((string)$event['status'] === 'published') {
        $wave = coveted_event_invitation_wave_snapshot($admin, (string)$event['public_id'], $pdo);
        $state = (array)($wave['state'] ?? []);
        $waveForecast = (array)($wave['forecast'] ?? []);
        $decision = (array)($wave['decision'] ?? []);
        $rates = (array)($wave['rates'] ?? []);
        $forecast = [
            'low'=>(int)($waveForecast['low'] ?? 0),
            'expected'=>(int)($waveForecast['expected'] ?? 0),
            'high'=>(int)($waveForecast['high'] ?? 0),
            'target'=>(int)($state['desired_arrivals'] ?? 0),
            'gap'=>(int)($waveForecast['gap_to_target'] ?? 0),
        ];
        $waveDecision = (string)($decision['key'] ?? 'hold');
        $waitlist = (int)($state['waitlist_rsvps'] ?? 0);
        $responseWindow = max(12, (int)($rates['response_window_hours'] ?? 48));
        $daysToEvent = (float)($state['days_to_event'] ?? $daysToEvent);
    }

    $latestReminderAt = (string)$queue['latest_rsvp_reminder_at'];
    $hoursSinceReminder = null;
    if ($latestReminderAt !== '') {
        $hoursSinceReminder = max(0.0, (time() - coveted_utc_datetime($latestReminderAt)->getTimestamp()) / 3600);
    }

    $stateKey = 'hold';
    $title = 'Hold Event communications';
    $detail = 'Current RSVP and communication state does not require another Admin communication action.';
    $href = '/admin/event-communications.php?event=' . rawurlencode((string)$event['public_id']);
    $priority = 3;
    $actionable = false;
    $recommendationKey = '';
    $recommendationCategory = 'Event Communications';

    if ((string)$event['status'] === 'published' && $waitlist > 0 && $waveDecision === 'reconcile_waitlist') {
        $stateKey = 'waitlist_first';
        $title = 'Reconcile the waitlist before more Event communication';
        $detail = 'Existing waitlisted demand should be resolved through the canonical RSVP flow before another reminder or invitation wave.';
        $href = '/admin/event-invitation-waves.php?event=' . rawurlencode((string)$event['public_id']);
        $priority = $daysToEvent <= 3.0 ? 1 : 2;
        $actionable = true;
        $recommendationKey = 'invitation-wave-' . (string)$event['public_id'];
        $recommendationCategory = 'Invitations';
    } elseif ((int)$reminders['ready'] > 0) {
        $stateKey = 'review_followup';
        $title = 'Review RSVP reminders for nudge-ready members';
        $detail = 'Some pending invitations are beyond the learned response window and have not yet received a reminder for their current invitation cycle.';
        $href .= '&type=rsvp_reminder';
        $priority = $daysToEvent <= 3.0 ? 1 : 2;
        $actionable = true;
        // Phase 1 already owns this recommendation/task. Reuse its source key
        // instead of creating a competing Event Communications task before send.
        $recommendationKey = 'rsvp-followup-' . (string)$event['public_id'];
        $recommendationCategory = 'RSVP Follow-Up';
    } elseif ($latestReminderAt !== '' && $responsesSinceReminder > 0) {
        $stateKey = 'recalculate_after_responses';
        if (str_starts_with($waveDecision, 'open_wave_')) {
            $title = 'RSVP responses changed the forecast — review the next wave';
            $detail = 'Members responded after the latest reminder. The canonical RSVP forecast has been recalculated and now recommends another invitation-wave review.';
            $href = '/admin/event-invitation-waves.php?event=' . rawurlencode((string)$event['public_id']);
            $priority = $daysToEvent <= 3.0 ? 1 : 2;
            $actionable = true;
            $recommendationKey = 'invitation-wave-' . (string)$event['public_id'];
            $recommendationCategory = 'Invitations';
        } else {
            $title = 'RSVP responses changed the forecast — hold and monitor';
            $detail = 'Members responded after the latest reminder and the refreshed canonical forecast currently recommends holding additional outreach.';
        }
    } elseif ($latestReminderAt !== '' && $hoursSinceReminder !== null && $hoursSinceReminder < $responseWindow) {
        $stateKey = 'waiting_response';
        $title = 'Wait for the current RSVP reminder cycle';
        $detail = 'The latest reminder cycle is still inside the learned response window. Avoid stacking another communication on top of it.';
    } elseif ((string)$event['status'] === 'published' && str_starts_with($waveDecision, 'open_wave_')) {
        $stateKey = 'next_wave';
        $title = 'Review the next invitation wave';
        $detail = 'No reminder cycle needs action, and the current canonical attendance forecast still recommends another invitation wave.';
        $href = '/admin/event-invitation-waves.php?event=' . rawurlencode((string)$event['public_id']);
        $priority = $daysToEvent <= 3.0 ? 1 : 2;
        $actionable = true;
        $recommendationKey = 'invitation-wave-' . (string)$event['public_id'];
        $recommendationCategory = 'Invitations';
    } elseif ($attending > 0 && (int)$queue['confirmations'] === 0 && $daysToEvent <= 3.0) {
        $stateKey = 'confirmation_ready';
        $title = 'Review attendee confirmations';
        $detail = 'The Event is approaching and current attending RSVPs have not yet received a canonical attendance confirmation.';
        $href .= '&type=confirmation';
        $priority = 2;
        $actionable = true;
        $recommendationKey = 'event-communications-' . (string)$event['public_id'];
    }

    $recommendation = null;
    if ($actionable && $recommendationKey !== '') {
        $recommendation = [
            'priority'=>$priority,
            'key'=>$recommendationKey,
            'category'=>$recommendationCategory,
            'title'=>$title,
            'detail'=>$detail,
            'evidence'=>(int)$reminders['ready'] . ' reminder-ready · ' . (int)$reminders['already_queued'] . ' current-cycle reminder already queued · ' . $responsesSinceReminder . ' RSVP change' . ($responsesSinceReminder === 1 ? '' : 's') . ' after latest reminder · forecast ' . $forecast['expected'] . '/' . $forecast['target'] . ' · ' . $attending . ' attending.',
            'href'=>$href,
        ];
    }

    $task = coveted_event_communications_agent_task($admin, (string)$event['public_id'], $pdo);
    return [
        'event'=>[
            'event_ref'=>(string)$event['public_id'],
            'title'=>(string)$event['title'],
            'group'=>(string)$event['group_name'],
            'status'=>(string)$event['status'],
            'starts_at'=>(string)$event['starts_at'],
        ],
        'state'=>$stateKey,
        'title'=>$title,
        'detail'=>$detail,
        'actionable'=>$actionable,
        'href'=>$href,
        'queue'=>$queue,
        'reminders'=>$reminders,
        'attending'=>$attending,
        'responses_since_latest_reminder'=>$responsesSinceReminder,
        'hours_since_latest_reminder'=>$hoursSinceReminder !== null ? round($hoursSinceReminder, 1) : null,
        'response_window_hours'=>$responseWindow,
        'forecast'=>$forecast,
        'wave_decision'=>$waveDecision,
        'waitlist'=>$waitlist,
        'days_to_event'=>round($daysToEvent,1),
        'recommendation'=>$recommendation,
        'agent_task'=>$task ? [
            'task_ref'=>(string)$task['public_id'],
            'status'=>(string)$task['status'],
            'priority'=>(int)$task['priority'],
        ] : null,
        'privacy'=>'Agent context contains aggregate communication, RSVP and forecast counts only. Recipient names, emails, user IDs and reminder recipient lists are excluded.',
        'authority'=>'The Agent can recommend and track communication work. System Admin must explicitly choose recipients and queue every communication; the Agent never sends autonomously.',
    ];
}

/** @return array<string,mixed> */
function coveted_event_communications_agent_context(array $admin, int $limit = 12, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin,$pdo)) {
        return ['available'=>false,'reason'=>'sample_mode','events'=>[],'recommendations'=>[],'attention'=>0];
    }
    $limit = max(1,min(20,$limit));
    $stmt = $pdo->query(
        "SELECT public_id FROM events
         WHERE status IN ('published','closed')
           AND starts_at>UTC_TIMESTAMP()
           AND starts_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 45 DAY)
         ORDER BY starts_at,id LIMIT 30"
    );
    $events=[];$recommendations=[];$attention=0;
    foreach ($stmt->fetchAll() as $row) {
        if (count($events)>=$limit) break;
        try {
            $snap = coveted_event_communications_lifecycle_snapshot($admin,(string)$row['public_id'],$pdo);
        } catch (Throwable $e) {
            error_log('Event Communications Agent lifecycle unavailable: ' . $e->getMessage());
            continue;
        }
        $events[]=[
            'event_ref'=>(string)$snap['event']['event_ref'],
            'title'=>(string)$snap['event']['title'],
            'starts_at'=>(string)$snap['event']['starts_at'],
            'state'=>(string)$snap['state'],
            'queued_total'=>(int)$snap['queue']['total'],
            'reminder_ready'=>(int)$snap['reminders']['ready'],
            'reminders_queued'=>(int)$snap['reminders']['already_queued'],
            'responses_since_latest_reminder'=>(int)$snap['responses_since_latest_reminder'],
            'attending'=>(int)$snap['attending'],
            'forecast'=>(array)$snap['forecast'],
            'wave_decision'=>(string)$snap['wave_decision'],
            'task_status'=>(string)($snap['agent_task']['status'] ?? ''),
            'href'=>(string)$snap['href'],
        ];
        if (is_array($snap['recommendation'] ?? null)) {
            $attention++;
            if (count($recommendations)<12) $recommendations[]=$snap['recommendation'];
        }
    }
    return [
        'available'=>true,
        'events'=>$events,
        'recommendations'=>$recommendations,
        'attention'=>$attention,
        'privacy'=>'Aggregate Event communication/RSVP/forecast state only. No recipient identities or contact details are exposed.',
        'authority'=>'Read-only Agent lifecycle intelligence. System Admin retains recipient selection and communication queue authority.',
    ];
}

/** @return array{created:int,updated:int,skipped:int}|null */
function coveted_event_communications_agent_sync_recommendation(array $admin, array $snapshot, ?PDO $pdo = null): ?array
{
    $pdo = coveted_event_communications_require_admin($admin, $pdo);
    $recommendation = $snapshot['recommendation'] ?? null;
    if (!is_array($recommendation) || !$recommendation || !coveted_admin_agent_tasks_schema_available($pdo)) return null;
    return coveted_admin_agent_tasks_sync_opportunities($admin, [$recommendation], $pdo);
}

function coveted_event_communications_agent_complete_source_task(
    array $admin,
    string $sourceKey,
    ?PDO $pdo = null
): void {
    $pdo = coveted_event_communications_require_admin($admin, $pdo);
    $task = coveted_event_communications_agent_source_task($admin,$sourceKey,$pdo);
    if (!$task) return;
    $status=(string)$task['status'];$ref=(string)$task['public_id'];
    if ($status==='suggested') {
        coveted_admin_agent_task_set_status($admin,$ref,'approved',$pdo,'suggested');
        $status='approved';
    }
    if ($status==='approved') {
        coveted_admin_agent_task_set_status($admin,$ref,'in_progress',$pdo,'approved');
        $status='in_progress';
    }
    if ($status==='in_progress') {
        coveted_admin_agent_task_set_status($admin,$ref,'completed',$pdo,'in_progress');
    }
}

/** @return array<string,mixed>|null */
function coveted_event_communications_agent_track_queue(
    array $admin,
    string $eventRef,
    string $communicationType,
    int $queuedCount,
    int $duplicateCount,
    ?PDO $pdo = null
): ?array {
    $pdo = coveted_event_communications_require_admin($admin, $pdo);
    if ($queuedCount < 1 || !coveted_admin_agent_tasks_schema_available($pdo)) return null;
    $event = coveted_event_communications_event($admin,$eventRef,$pdo);

    // Explicitly queueing an RSVP reminder fulfills the Phase 1 follow-up task.
    // Close that canonical stage before opening the response-tracking stage.
    if ($communicationType==='rsvp_reminder') {
        coveted_event_communications_agent_complete_source_task(
            $admin,
            'rsvp-followup-' . (string)$event['public_id'],
            $pdo
        );
    }

    $key = 'event-communications-' . (string)$event['public_id'];
    coveted_admin_agent_tasks_sync_opportunities($admin, [[
        'priority'=>2,
        'key'=>$key,
        'category'=>'Event Communications',
        'title'=>'Track responses after Event communication',
        'detail'=>'A System Admin explicitly queued a reviewed Event communication. Track canonical RSVP/forecast changes and surface the next safe action; do not send another message autonomously.',
        'evidence'=>$queuedCount . ' canonical notification' . ($queuedCount===1?'':'s') . ' queued · ' . $duplicateCount . ' already queued · communication type ' . $communicationType . '.',
        'href'=>'/admin/event-communications.php?event='.rawurlencode((string)$event['public_id']),
    ]],$pdo);

    $task = coveted_event_communications_agent_task($admin,(string)$event['public_id'],$pdo);
    if (!$task) return null;
    $status=(string)$task['status'];$ref=(string)$task['public_id'];
    if ($status==='suggested') {
        coveted_admin_agent_task_set_status($admin,$ref,'approved',$pdo,'suggested');
        $status='approved';
    }
    if ($status==='approved') {
        coveted_admin_agent_task_set_status($admin,$ref,'in_progress',$pdo,'approved');
    }
    coveted_audit(
        'admin.agent_event_communication_tracked',
        'admin_agent_task',
        $ref,
        [
            'event_ref'=>(string)$event['public_id'],
            'communication_type'=>$communicationType,
            'queued_count'=>$queuedCount,
            'duplicate_count'=>$duplicateCount,
            'recipient_identities_in_agent_payload'=>false,
        ],
        (int)$admin['id']
    );
    return coveted_admin_agent_task_by_ref($admin,$ref,$pdo);
}
