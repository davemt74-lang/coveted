<?php
declare(strict_types=1);

require_once __DIR__ . '/event_invitation_waves.php';
require_once __DIR__ . '/admin_agent_tasks.php';

/**
 * RSVP Follow-Up Intelligence is a derived planning layer over canonical
 * Event invitations / RSVPs and the learned Invitation Wave response model.
 * It never changes invitation or RSVP state and never sends a message.
 */
function coveted_event_rsvp_followup_require_admin(array $admin, ?PDO $pdo = null): PDO
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin, $pdo)) {
        throw new InvalidArgumentException('Full System Sample Mode does not expose live RSVP follow-up intelligence.');
    }
    return $pdo;
}

/** @return array<int,array<string,mixed>> */
function coveted_event_rsvp_followup_pending(PDO $pdo, array $event, int $responseWindowHours): array
{
    $stmt = $pdo->prepare(
        "SELECT
            ei.public_id AS invitation_ref,
            ei.user_id,
            ei.invite_type,
            ei.status,
            COALESCE((
                SELECT MAX(ae.created_at)
                FROM audit_events ae
                WHERE ae.event_type = 'event.user_invited'
                  AND ae.entity_type = 'event'
                  AND ae.entity_id = ev.public_id
                  AND JSON_UNQUOTE(JSON_EXTRACT(ae.metadata_json, '$.invitation_id')) = ei.public_id
            ), ei.created_at) AS invited_at,
            u.display_name,
            er.response,
            er.responded_at,
            TIMESTAMPDIFF(HOUR,
                COALESCE((
                    SELECT MAX(ae2.created_at)
                    FROM audit_events ae2
                    WHERE ae2.event_type = 'event.user_invited'
                      AND ae2.entity_type = 'event'
                      AND ae2.entity_id = ev.public_id
                      AND JSON_UNQUOTE(JSON_EXTRACT(ae2.metadata_json, '$.invitation_id')) = ei.public_id
                ), ei.created_at),
                UTC_TIMESTAMP()
            ) AS age_hours
         FROM event_invitations ei
         JOIN events ev ON ev.id = ei.event_id
         JOIN users u ON u.id = ei.user_id
         LEFT JOIN event_rsvps er ON er.event_id = ei.event_id AND er.user_id = ei.user_id
         WHERE ei.event_id = ?
           AND ei.status = 'pending'
           AND er.response IS NULL
           AND u.status = 'active'
         ORDER BY invited_at ASC, ei.id ASC"
    );
    $stmt->execute([(int)$event['id']]);

    $eventTs = coveted_utc_datetime((string)$event['starts_at'])->getTimestamp();
    $hoursToEvent = max(0.0, ($eventTs - time()) / 3600);
    $closeWindowHours = max(72, $responseWindowHours * 2);
    $rows = [];

    foreach ($stmt->fetchAll() as $row) {
        $age = max(0, (int)($row['age_hours'] ?? 0));
        $status = 'hold';
        $reason = 'Still inside the learned response window.';

        if ($hoursToEvent <= 12.0) {
            $status = 'stop_contact';
            $reason = 'The Event is too close for another RSVP follow-up cycle.';
        } elseif ($age >= $closeWindowHours) {
            $status = 'stop_contact';
            $reason = 'This invitation is well beyond two learned response windows; stop repeated follow-up and let the Event forecast determine the next move.';
        } elseif ($age >= $responseWindowHours) {
            $status = 'nudge_ready';
            $reason = 'This invitation is beyond the learned response window and is ready for System Admin review before any reminder is sent.';
        }

        $rows[] = [
            'invitation_ref' => (string)$row['invitation_ref'],
            'user_id' => (int)$row['user_id'],
            'display_name' => (string)$row['display_name'],
            'invite_type' => (string)$row['invite_type'],
            'invited_at' => (string)$row['invited_at'],
            'age_hours' => $age,
            'followup_status' => $status,
            'reason' => $reason,
        ];
    }

    return $rows;
}

/** @return array<string,mixed> */
function coveted_event_rsvp_followup_snapshot(array $admin, string $eventRef, ?PDO $pdo = null): array
{
    $pdo = coveted_event_rsvp_followup_require_admin($admin, $pdo);
    $wave = coveted_event_invitation_wave_snapshot($admin, $eventRef, $pdo);
    $event = (array)($wave['event'] ?? []);
    $state = (array)($wave['state'] ?? []);
    $forecast = (array)($wave['forecast'] ?? []);
    $rates = (array)($wave['rates'] ?? []);
    $responseWindow = max(12, (int)($rates['response_window_hours'] ?? 48));
    $pending = coveted_event_rsvp_followup_pending($pdo, $event, $responseWindow);

    $counts = ['pending'=>count($pending),'hold'=>0,'nudge_ready'=>0,'stop_contact'=>0];
    foreach ($pending as $row) {
        $key = (string)($row['followup_status'] ?? 'hold');
        if (isset($counts[$key])) $counts[$key]++;
    }

    $capacity = max(0, (int)($event['capacity'] ?? 0));
    $attendingSeats = max(0, (int)($state['attending_seats'] ?? 0));
    $waitlist = max(0, (int)($state['waitlist_rsvps'] ?? 0));
    $waitlistReady = $waitlist > 0 && ($capacity === 0 || $attendingSeats < $capacity);
    $daysToEvent = (float)($state['days_to_event'] ?? 0.0);

    $recommendation = null;
    if ($waitlistReady) {
        $recommendation = [
            'priority' => $daysToEvent <= 3.0 ? 1 : 2,
            'key' => 'rsvp-followup-' . (string)$event['public_id'],
            'category' => 'RSVP Follow-Up',
            'title' => 'Reconcile the waitlist before follow-up outreach',
            'detail' => 'Existing waitlisted demand should be promoted through the canonical RSVP flow before contacting pending invitees again.',
            'evidence' => $waitlist . ' waitlisted RSVP' . ($waitlist === 1 ? '' : 's') . ' and ' . $attendingSeats . ' attending seat' . ($attendingSeats === 1 ? '' : 's') . ' against capacity ' . ($capacity > 0 ? $capacity : 'open') . '.',
            'href' => '/admin/event-rsvp-followup.php?event=' . rawurlencode((string)$event['public_id']),
        ];
    } elseif ($counts['nudge_ready'] > 0) {
        $recommendation = [
            'priority' => $daysToEvent <= 3.0 ? 1 : 2,
            'key' => 'rsvp-followup-' . (string)$event['public_id'],
            'category' => 'RSVP Follow-Up',
            'title' => 'Review stale pending RSVPs for follow-up',
            'detail' => 'Some pending invitations are beyond the learned response window. System Admin should review the exact recipients before deciding whether any reminder is appropriate.',
            'evidence' => $counts['nudge_ready'] . ' nudge-ready · ' . $counts['hold'] . ' still inside response window · learned window ' . $responseWindow . 'h · forecast expected ' . (int)($forecast['expected'] ?? 0) . ' / target ' . (int)($state['desired_arrivals'] ?? 0) . '.',
            'href' => '/admin/event-rsvp-followup.php?event=' . rawurlencode((string)$event['public_id']),
        ];
    } elseif ($counts['stop_contact'] > 0 && (int)($forecast['gap_to_target'] ?? 0) > 0) {
        $recommendation = [
            'priority' => $daysToEvent <= 2.0 ? 1 : 2,
            'key' => 'rsvp-followup-' . (string)$event['public_id'],
            'category' => 'RSVP Follow-Up',
            'title' => 'Close aged-out RSVP follow-up and recalculate outreach',
            'detail' => 'Aged-out pending invitations should not be repeatedly chased. Review the live Invitation Wave forecast for waitlist reconciliation or a safe new wave instead.',
            'evidence' => $counts['stop_contact'] . ' aged-out pending · forecast gap ' . (int)($forecast['gap_to_target'] ?? 0) . ' · learned response window ' . $responseWindow . 'h.',
            'href' => '/admin/event-rsvp-followup.php?event=' . rawurlencode((string)$event['public_id']),
        ];
    }

    return [
        'event' => $event,
        'wave' => $wave,
        'pending' => $pending,
        'counts' => $counts,
        'response_window_hours' => $responseWindow,
        'close_window_hours' => max(72, $responseWindow * 2),
        'waitlist_ready' => $waitlistReady,
        'recommendation' => $recommendation,
        'aggregate_context' => [
            'event_ref' => (string)($event['public_id'] ?? ''),
            'title' => (string)($event['title'] ?? ''),
            'starts_at' => (string)($event['starts_at'] ?? ''),
            'pending' => $counts['pending'],
            'inside_response_window' => $counts['hold'],
            'nudge_ready' => $counts['nudge_ready'],
            'stop_contact' => $counts['stop_contact'],
            'waitlist_ready' => $waitlistReady,
            'response_window_hours' => $responseWindow,
            'forecast_expected' => (int)($forecast['expected'] ?? 0),
            'forecast_target' => (int)($state['desired_arrivals'] ?? 0),
        ],
        'privacy' => 'Broad Agent context may use only aggregate counts and timing signals. Exact pending-member identities remain in this System Admin workspace.',
        'authority' => 'This engine only recommends hold, review-for-nudge, stop-contact, or waitlist-first actions. It never sends reminders, changes an RSVP, or alters an invitation.',
    ];
}

/**
 * Sync only the deterministic recommendation metadata into the existing Admin
 * Agent task queue. This does not mutate Event, invitation, RSVP or message state.
 *
 * @return array{created:int,updated:int,skipped:int}|null
 */
function coveted_event_rsvp_followup_sync_agent_task(array $admin, array $snapshot, ?PDO $pdo = null): ?array
{
    $pdo = coveted_event_rsvp_followup_require_admin($admin, $pdo);
    $recommendation = $snapshot['recommendation'] ?? null;
    if (!is_array($recommendation) || !$recommendation) return null;
    if (!coveted_admin_agent_tasks_schema_available($pdo)) return null;
    return coveted_admin_agent_tasks_sync_opportunities($admin, [$recommendation], $pdo);
}
