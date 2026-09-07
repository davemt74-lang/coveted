<?php
declare(strict_types=1);

require_once __DIR__ . '/event_rsvp_followup.php';
require_once __DIR__ . '/notifications.php';

function coveted_event_communications_require_admin(array $admin, ?PDO $pdo = null): PDO
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin, $pdo)) {
        throw new InvalidArgumentException('Full System Sample Mode cannot queue live Event communications.');
    }
    return $pdo;
}

/** @return array<string,mixed> */
function coveted_event_communications_event(array $admin, string $eventRef, ?PDO $pdo = null): array
{
    $pdo = coveted_event_communications_require_admin($admin, $pdo);
    $eventRef = trim($eventRef);
    if ($eventRef === '' || strlen($eventRef) > 64) {
        throw new InvalidArgumentException('Event not found.');
    }

    $stmt = $pdo->prepare(
        "SELECT e.*, g.name AS group_name, g.status AS group_status
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         WHERE e.public_id = ? OR CAST(e.id AS CHAR) = ?
         LIMIT 1"
    );
    $stmt->execute([$eventRef, $eventRef]);
    $event = $stmt->fetch();
    if (!$event) {
        throw new InvalidArgumentException('Event not found.');
    }
    if (!in_array((string)$event['status'], ['published', 'closed'], true)) {
        throw new InvalidArgumentException('Event communications are available only for published or closed Events.');
    }
    if (coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() <= time()) {
        throw new InvalidArgumentException('Pre-Event communications are no longer available after the Event starts.');
    }
    return $event;
}

/** @return array<int,array<string,mixed>> */
function coveted_event_communications_live_reveals(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, event_id, reveal_at, reveal_type, title, content, notified_at
         FROM event_mystery_reveals
         WHERE event_id = ? AND reveal_at <= UTC_TIMESTAMP()
         ORDER BY reveal_at DESC, id DESC"
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

/** @return array<string,mixed>|null */
function coveted_event_communications_reveal(PDO $pdo, int $eventId, int $revealId): ?array
{
    if ($revealId < 1) return null;
    $stmt = $pdo->prepare(
        "SELECT id, event_id, reveal_at, reveal_type, title, content, notified_at
         FROM event_mystery_reveals
         WHERE id = ? AND event_id = ? AND reveal_at <= UTC_TIMESTAMP()
         LIMIT 1"
    );
    $stmt->execute([$revealId, $eventId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return array<string,mixed>|null */
function coveted_event_communications_location(PDO $pdo, array $event): ?array
{
    if ((string)$event['location_visibility'] !== 'immediate') return null;
    $stmt = $pdo->prepare(
        "SELECT el.private_location_label, l.name AS location_name,
                l.address1, l.address2, l.city, l.region, l.postal_code, l.country,
                b.name AS business_name
         FROM event_locations el
         LEFT JOIN locations l ON l.id = el.location_id
         LEFT JOIN businesses b ON b.id = l.business_id
         WHERE el.event_id = ? LIMIT 1"
    );
    $stmt->execute([(int)$event['id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function coveted_event_communications_location_text(array $location): string
{
    $name = trim((string)($location['location_name'] ?? ''));
    if ($name === '') $name = trim((string)($location['private_location_label'] ?? ''));
    if ($name === '') $name = trim((string)($location['business_name'] ?? ''));

    $street = array_values(array_filter([
        trim((string)($location['address1'] ?? '')),
        trim((string)($location['address2'] ?? '')),
    ], static fn(string $value): bool => $value !== ''));
    $cityLine = trim(implode(', ', array_values(array_filter([
        trim((string)($location['city'] ?? '')),
        trim((string)($location['region'] ?? '')),
    ], static fn(string $value): bool => $value !== ''))));
    $postal = trim((string)($location['postal_code'] ?? ''));
    if ($cityLine !== '' && $postal !== '') $cityLine .= ' ' . $postal;

    return implode(' · ', array_values(array_filter([
        $name,
        implode(', ', $street),
        $cityLine,
    ], static fn(string $value): bool => $value !== '')));
}

/** @return array<int,array<string,mixed>> */
function coveted_event_communications_attending(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(
        "SELECT u.id AS user_id, u.display_name, er.responded_at, er.updated_at, er.guest_count
         FROM event_rsvps er
         JOIN users u ON u.id = er.user_id AND u.status = 'active'
         WHERE er.event_id = ? AND er.response = 'attending'
         ORDER BY u.display_name, u.id"
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

/** @return array<string,mixed> */
function coveted_event_communications_reminder_candidates(array $admin, array $event, PDO $pdo): array
{
    if ((string)$event['status'] !== 'published') {
        return ['rows' => [], 'followup' => null];
    }
    $followup = coveted_event_rsvp_followup_snapshot($admin, (string)$event['public_id'], $pdo);
    $rows = [];
    foreach ((array)$followup['pending'] as $row) {
        if ((string)($row['followup_status'] ?? '') !== 'nudge_ready') continue;
        $cycle = $pdo->prepare(
            "SELECT MAX(ae.id)
             FROM audit_events ae
             WHERE ae.event_type = 'event.user_invited'
               AND ae.entity_type = 'event'
               AND ae.entity_id = ?
               AND JSON_UNQUOTE(JSON_EXTRACT(ae.metadata_json, '$.invitation_id')) = ?"
        );
        $cycle->execute([(string)$event['public_id'], (string)$row['invitation_ref']]);
        $auditId = (int)($cycle->fetchColumn() ?: 0);
        $rows[] = $row + [
            'cycle_token' => $auditId > 0
                ? 'audit-' . $auditId
                : 'created-' . preg_replace('/[^0-9]/', '', (string)$row['invited_at']),
        ];
    }
    return ['rows' => $rows, 'followup' => $followup];
}

function coveted_event_communications_reveal_title(array $reveal, array $event): string
{
    $title = trim((string)($reveal['title'] ?? ''));
    if ($title === '') {
        $title = match ((string)$reveal['reveal_type']) {
            'location' => 'Location revealed',
            'artist' => 'Artist revealed',
            'area' => 'Your Event area is ready',
            'experience' => 'Experience revealed',
            'instructions' => 'New Event instructions',
            default => 'Event update',
        };
    }
    return $title . ': ' . (string)$event['title'];
}

/**
 * Build the current execution preview. Exact identities are intentionally
 * returned only to the System Admin workspace, never to broad Agent context.
 *
 * @return array<string,mixed>
 */
function coveted_event_communications_preview(
    array $admin,
    string $eventRef,
    string $communicationType,
    int $revealId = 0,
    ?PDO $pdo = null
): array {
    $pdo = coveted_event_communications_require_admin($admin, $pdo);
    $event = coveted_event_communications_event($admin, $eventRef, $pdo);
    $communicationType = strtolower(trim($communicationType));
    if (!in_array($communicationType, ['rsvp_reminder','confirmation','location','reveal'], true)) {
        throw new InvalidArgumentException('Choose a supported Event communication.');
    }

    $recipients = [];
    $title = '';
    $body = '';
    $notificationType = '';
    $priority = 'normal';
    $source = '';
    $reveal = null;

    if ($communicationType === 'rsvp_reminder') {
        $candidate = coveted_event_communications_reminder_candidates($admin, $event, $pdo);
        $recipients = (array)$candidate['rows'];
        $title = 'RSVP reminder: ' . (string)$event['title'];
        $body = (string)$event['group_name'] . ' is finalizing the guest list. Please update your RSVP when you can.';
        $notificationType = 'event.rsvp_reminder';
        $priority = 'normal';
        $source = 'Phase 1 nudge-ready recipients only; hold and stop-contact recipients are excluded.';
    } elseif ($communicationType === 'confirmation') {
        $recipients = coveted_event_communications_attending($pdo, (int)$event['id']);
        $title = 'Confirmed: ' . (string)$event['title'];
        $body = 'You’re on the guest list for ' . coveted_event_format($event, 'M j, Y \\a\\t g:i A') . '. Check your Event page for current details.';
        $notificationType = 'event.confirmation';
        $priority = 'normal';
        $source = 'Current active attending RSVPs only.';
    } elseif ($communicationType === 'location') {
        if ((string)$event['location_visibility'] === 'host_only') {
            throw new InvalidArgumentException('Host-only Event locations cannot be sent to attendees.');
        }
        $recipients = coveted_event_communications_attending($pdo, (int)$event['id']);
        $notificationType = 'event.location';
        $priority = 'high';
        if ((string)$event['location_visibility'] === 'scheduled_reveal') {
            $reveal = coveted_event_communications_reveal($pdo, (int)$event['id'], $revealId);
            if (!$reveal || (string)$reveal['reveal_type'] !== 'location') {
                throw new InvalidArgumentException('Scheduled locations can be sent only from an already-live canonical location reveal.');
            }
            $title = coveted_event_communications_reveal_title($reveal, $event);
            $body = (string)$reveal['content'];
            $notificationType = 'event.mystery_reveal';
            $source = 'Existing live canonical location reveal; no private location data is read into the attendee communication before reveal time.';
        } else {
            $location = coveted_event_communications_location($pdo, $event);
            $body = $location ? coveted_event_communications_location_text($location) : '';
            if ($body === '') {
                throw new InvalidArgumentException('This Event does not have a sendable immediate location yet.');
            }
            $title = 'Location: ' . (string)$event['title'];
            $source = 'Canonical Event location with immediate attendee visibility.';
        }
    } else {
        $reveal = coveted_event_communications_reveal($pdo, (int)$event['id'], $revealId);
        if (!$reveal) {
            throw new InvalidArgumentException('Choose an already-live canonical Event reveal.');
        }
        if ((string)$reveal['reveal_type'] === 'location') {
            if ((string)$event['location_visibility'] === 'host_only') {
                throw new InvalidArgumentException('Host-only Event locations cannot be sent to attendees.');
            }
            if ((string)$event['location_visibility'] === 'scheduled_reveal' && coveted_utc_datetime((string)$reveal['reveal_at'])->getTimestamp() > time()) {
                throw new InvalidArgumentException('That location reveal is not live yet.');
            }
        }
        $recipients = coveted_event_communications_attending($pdo, (int)$event['id']);
        $title = coveted_event_communications_reveal_title($reveal, $event);
        $body = (string)$reveal['content'];
        $notificationType = 'event.mystery_reveal';
        $priority = in_array((string)$reveal['reveal_type'], ['location','instructions'], true) ? 'high' : 'normal';
        $source = 'Existing live canonical Event reveal; content and reveal timing remain Event configuration authority.';
    }

    return [
        'event' => $event,
        'communication_type' => $communicationType,
        'reveal' => $reveal,
        'reveals' => coveted_event_communications_live_reveals($pdo, (int)$event['id']),
        'recipients' => $recipients,
        'title' => $title,
        'body' => $body,
        'notification_type' => $notificationType,
        'priority' => $priority,
        'action_url' => $communicationType === 'rsvp_reminder' ? '/invitations.php' : '/events.php',
        'source' => $source,
        'authority' => 'System Admin explicitly selects recipients. Queueing creates canonical notifications only; it does not change Event, invitation, RSVP, reveal, or attendance state and does not invoke the global push dispatcher.',
    ];
}

function coveted_event_communications_dedupe_key(array $preview, array $recipient): string
{
    $event = (array)$preview['event'];
    $type = (string)$preview['communication_type'];
    $userId = (int)$recipient['user_id'];
    if ($type === 'rsvp_reminder') {
        return 'event-rsvp-reminder:' . (string)$recipient['invitation_ref'] . ':' . (string)$recipient['cycle_token'];
    }
    if ($type === 'confirmation') {
        $cycle = preg_replace('/[^0-9]/', '', (string)($recipient['responded_at'] ?? $recipient['updated_at'] ?? ''));
        return 'event-confirmation:' . (int)$event['id'] . ':user:' . $userId . ':' . $cycle;
    }
    $reveal = $preview['reveal'] ?? null;
    if (is_array($reveal) && !empty($reveal['id'])) {
        // Must exactly match app/notification_events.php so later reconciliation
        // cannot duplicate a manually queued canonical reveal notification.
        return 'mystery-reveal:' . (int)$reveal['id'] . ':user:' . $userId;
    }
    $contentHash = substr(hash('sha256', (string)$preview['title'] . "\n" . (string)$preview['body']), 0, 16);
    return 'event-location:' . (int)$event['id'] . ':user:' . $userId . ':' . $contentHash;
}

/** @return array<string,mixed> */
function coveted_event_communications_execute(
    array $admin,
    string $eventRef,
    string $communicationType,
    array $selectedUserIds,
    int $revealId = 0,
    ?PDO $pdo = null
): array {
    $pdo = coveted_event_communications_require_admin($admin, $pdo);
    $selectedUserIds = array_values(array_unique(array_filter(array_map('intval', $selectedUserIds), static fn(int $id): bool => $id > 0)));
    if (!$selectedUserIds) {
        throw new InvalidArgumentException('Select at least one current eligible recipient.');
    }
    if (count($selectedUserIds) > 100) {
        throw new InvalidArgumentException('Event communications are limited to 100 explicitly selected recipients per execution.');
    }

    // Rebuild immediately before execution. This is the authority check: RSVP,
    // invitation, response-window and reveal/location state may have changed since preview.
    $preview = coveted_event_communications_preview($admin, $eventRef, $communicationType, $revealId, $pdo);
    $eligible = [];
    foreach ((array)$preview['recipients'] as $recipient) {
        $eligible[(int)$recipient['user_id']] = $recipient;
    }

    $summary = ['selected'=>count($selectedUserIds),'queued'=>0,'duplicate'=>0,'skipped'=>0,'failed'=>0,'results'=>[]];
    foreach ($selectedUserIds as $userId) {
        if (!isset($eligible[$userId])) {
            $summary['skipped']++;
            $summary['results'][] = ['user_id'=>$userId,'status'=>'skipped','reason'=>'Recipient is no longer eligible under the live Event communication rules.'];
            continue;
        }

        $recipient = $eligible[$userId];
        $dedupeKey = coveted_event_communications_dedupe_key($preview, $recipient);
        $existing = $pdo->prepare('SELECT public_id FROM notifications WHERE user_id = ? AND dedupe_key = ? LIMIT 1');
        $existing->execute([$userId, $dedupeKey]);
        $existingRef = $existing->fetchColumn();
        if ($existingRef !== false) {
            // Re-enter through the canonical create function so any newly active
            // device receives a missing delivery row without duplicating the notification.
            coveted_notification_create(
                $userId,
                (string)$preview['notification_type'],
                (string)$preview['title'],
                (string)$preview['body'],
                (string)$preview['action_url'],
                ['event_id'=>(string)$preview['event']['public_id'],'communication_type'=>(string)$preview['communication_type']],
                (string)$preview['priority'],
                $dedupeKey,
                (int)$admin['id']
            );
            $summary['duplicate']++;
            $summary['results'][] = ['user_id'=>$userId,'status'=>'duplicate','notification_ref'=>(string)$existingRef];
            continue;
        }

        try {
            $payload = [
                'event_id' => (string)$preview['event']['public_id'],
                'communication_type' => (string)$preview['communication_type'],
            ];
            if (is_array($preview['reveal'] ?? null)) {
                $payload['reveal_type'] = (string)$preview['reveal']['reveal_type'];
            }
            $notification = coveted_notification_create(
                $userId,
                (string)$preview['notification_type'],
                (string)$preview['title'],
                (string)$preview['body'],
                (string)$preview['action_url'],
                $payload,
                (string)$preview['priority'],
                $dedupeKey,
                (int)$admin['id']
            );
            $summary['queued']++;
            $summary['results'][] = ['user_id'=>$userId,'status'=>'queued','notification_ref'=>(string)($notification['public_id'] ?? '')];
        } catch (Throwable $e) {
            $summary['failed']++;
            $summary['results'][] = ['user_id'=>$userId,'status'=>'failed','reason'=>'Canonical notification queueing failed.'];
            error_log('Coveted Event communication queue failed: ' . $e->getMessage());
        }
    }

    coveted_audit(
        'event.communication_queued',
        'event',
        (string)$preview['event']['public_id'],
        [
            'communication_type' => (string)$preview['communication_type'],
            'reveal_id' => is_array($preview['reveal'] ?? null) ? (int)$preview['reveal']['id'] : null,
            'selected' => $summary['selected'],
            'queued' => $summary['queued'],
            'duplicate' => $summary['duplicate'],
            'skipped' => $summary['skipped'],
            'failed' => $summary['failed'],
        ],
        (int)$admin['id']
    );

    return ['preview'=>$preview,'summary'=>$summary];
}
