<?php
declare(strict_types=1);

require_once __DIR__ . '/member_pages_v2.php';
require_once __DIR__ . '/event_experience.php';
require_once __DIR__ . '/notifications.php';

/**
 * Member Event Pass IDs are display/check-in identifiers only. They are never
 * accepted as authentication or authorization and do not replace host-side
 * attendance authority.
 */
function coveted_attendee_event_pass_id(array $actor, array $event): string
{
    $userRef = trim((string)($actor['public_id'] ?? ''));
    $eventRef = trim((string)($event['public_id'] ?? ''));
    if ($userRef === '' || $eventRef === '') {
        throw new InvalidArgumentException('Event Pass identity is unavailable.');
    }

    $digest = strtoupper(hash('sha256', 'coveted-event-pass|' . $eventRef . '|' . $userRef));
    return implode('-', str_split(substr($digest, 0, 12), 4));
}

function coveted_attendee_event_can_rsvp(array $event): bool
{
    if ((string)($event['status'] ?? '') !== 'published') {
        return false;
    }
    if (isset($event['group_status']) && (string)$event['group_status'] !== 'active') {
        return false;
    }

    $startsAt = trim((string)($event['starts_at'] ?? ''));
    return $startsAt !== '' && coveted_utc_datetime($startsAt)->getTimestamp() > time();
}

function coveted_attendee_event_set_rsvp(
    array $actor,
    string $eventRef,
    string $decision,
    int $guestCount = 0
): string {
    if (!in_array($decision, ['attending', 'declined'], true)) {
        throw new InvalidArgumentException('Choose whether you are attending or declining.');
    }

    // The canonical event service owns eligibility, capacity, +1, waitlist,
    // invitation synchronization, auditing and all RSVP mutations.
    return coveted_event_set_rsvp($actor, $eventRef, $decision, $decision === 'attending' ? $guestCount : 0);
}

function coveted_attendee_event_has_verified_attendance(array $event): bool
{
    return in_array(
        (string)($event['attendance_status'] ?? ''),
        ['checked_in', 'attended', 'left_early'],
        true
    );
}

function coveted_attendee_event_location_visible(array $event): bool
{
    if (!empty($event['can_manage'])) {
        return true;
    }
    if (in_array((string)($event['assigned_host_role'] ?? ''), ['lead', 'cohost', 'checkin'], true)) {
        return true;
    }

    $visibility = (string)($event['location_visibility'] ?? 'immediate');
    if ($visibility === 'host_only') {
        return false;
    }
    if ($visibility === 'immediate') {
        return true;
    }

    return $visibility === 'scheduled_reveal'
        && (
            !empty($event['location_revealed'])
            || ((string)($event['status'] ?? '') === 'completed' && coveted_attendee_event_has_verified_attendance($event))
        );
}

function coveted_attendee_event_value_preview_visible(array $event): bool
{
    if ((string)($event['event_type'] ?? '') !== 'mystery') {
        return true;
    }
    if (!empty($event['can_manage'])) {
        return true;
    }
    if (in_array((string)($event['assigned_host_role'] ?? ''), ['lead', 'cohost', 'checkin'], true)) {
        return true;
    }

    $startsAt = trim((string)($event['starts_at'] ?? ''));
    return $startsAt !== '' && coveted_utc_datetime($startsAt)->getTimestamp() <= time();
}

/** @return array<int,array<string,mixed>> */
function coveted_attendee_event_active_perks(array $event, int $limit = 4, ?PDO $pdo = null): array
{
    $eventId = (int)($event['id'] ?? 0);
    if ($eventId < 1 || !coveted_attendee_event_value_preview_visible($event)) {
        return [];
    }

    $limit = max(1, min($limit, 8));
    $pdo ??= coveted_db();
    $stmt = $pdo->prepare(
        "SELECT c.public_id AS campaign_public_id, c.title AS campaign_title, c.trigger_key,
                rt.public_id AS reward_public_id, rt.title, rt.description, rt.reward_type,
                rt.value_amount, rt.value_text, rt.cover_url, rt.claim_mode,
                b.name AS business_name, ap.artist_name
         FROM campaign_event_links cel
         JOIN campaigns c ON c.id = cel.campaign_id
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         LEFT JOIN businesses b ON b.id = rt.business_id
         LEFT JOIN artist_profiles ap ON ap.id = rt.artist_id
         WHERE cel.event_id = ?
           AND c.status = 'active'
           AND rt.status = 'active'
           AND (c.starts_at IS NULL OR c.starts_at <= UTC_TIMESTAMP())
           AND (c.ends_at IS NULL OR c.ends_at > UTC_TIMESTAMP())
         ORDER BY c.id ASC
         LIMIT {$limit}"
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function coveted_attendee_event_benefit_count(int $userId, ?PDO $pdo = null): int
{
    if ($userId < 1) {
        return 0;
    }

    $pdo ??= coveted_db();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM reward_issuances
         WHERE user_id = ? AND status NOT IN ('cancelled','expired')"
    );
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/** @return array<string,mixed> */
function coveted_attendee_event_workspace(array $actor, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $sampleMode = coveted_member_sample_mode($actor, $pdo);
    $events = coveted_member_v2_events($actor, $pdo);
    $invitations = coveted_member_v2_invitations($actor, $pdo);
    $now = time();

    $upcoming = array_values(array_filter(
        $events,
        static fn(array $event): bool => coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() >= $now
            && !in_array((string)$event['status'], ['completed', 'cancelled'], true)
    ));
    usort($upcoming, static fn(array $a, array $b): int => strcmp((string)$a['starts_at'], (string)$b['starts_at']));

    $history = array_values(array_filter(
        $events,
        static fn(array $event): bool => coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() < $now
            || in_array((string)$event['status'], ['completed', 'cancelled'], true)
    ));
    usort($history, static fn(array $a, array $b): int => strcmp((string)$b['starts_at'], (string)$a['starts_at']));

    $waitingInvitations = array_values(array_filter(
        $invitations,
        static fn(array $invite): bool => coveted_member_v2_invitation_bucket($invite, $now) === 'waiting'
    ));

    $next = null;
    foreach ($upcoming as $event) {
        if ((string)($event['response'] ?? '') === 'attending') {
            $next = $event;
            break;
        }
        $next ??= $event;
    }

    $attendingCount = count(array_filter(
        $upcoming,
        static fn(array $event): bool => (string)($event['response'] ?? '') === 'attending'
    ));

    return [
        'sample_mode' => $sampleMode,
        'events' => $events,
        'upcoming' => $upcoming,
        'history' => $history,
        'waiting_invitations' => $waitingInvitations,
        'next_event' => $next,
        'attending_count' => $attendingCount,
        'benefit_count' => $sampleMode ? 0 : coveted_attendee_event_benefit_count((int)$actor['id'], $pdo),
        'unread_notifications' => $sampleMode ? 0 : coveted_notification_unread_count((int)$actor['id']),
    ];
}
