<?php
declare(strict_types=1);

require_once __DIR__ . '/businesses.php';
require_once __DIR__ . '/events.php';

/** @return array<int,array<string,mixed>> */
function coveted_business_host_businesses(array $actor): array
{
    return coveted_businesses_for_actor($actor);
}

function coveted_business_host_has_access(array $actor): bool
{
    if (coveted_is_system_admin($actor)) {
        return true;
    }

    $stmt = coveted_db()->prepare('SELECT 1 FROM business_admins WHERE user_id = ? LIMIT 1');
    $stmt->execute([(int)$actor['id']]);
    return (bool)$stmt->fetchColumn();
}

function coveted_business_host_require_access(array $actor): void
{
    if (!coveted_business_host_has_access($actor)) {
        throw new InvalidArgumentException('Business / Location Host access is required.');
    }
}

function coveted_business_host_resolve_business(array $actor, string $requestedRef = ''): ?array
{
    coveted_business_host_require_access($actor);
    return coveted_business_resolve_context($actor, trim($requestedRef));
}

/** @return array<int,array<string,mixed>> */
function coveted_business_host_events(array $actor, int $businessId, int $limit = 100): array
{
    if (!coveted_business_actor_can_view($actor, $businessId)) {
        throw new InvalidArgumentException('That business is unavailable to this account.');
    }

    $limit = max(1, min($limit, 250));
    $stmt = coveted_db()->prepare(
        "SELECT e.id, e.public_id, e.group_id, e.title, e.description, e.event_type, e.audience,
                e.timezone, e.starts_at, e.ends_at, e.capacity, e.plus_one_allowed,
                e.location_visibility, e.status,
                g.name AS group_name,
                l.id AS location_id, l.public_id AS location_public_id, l.name AS location_name,
                l.address1, l.address2, l.city, l.region, l.postal_code, l.country,
                l.timezone AS location_timezone, l.status AS location_status,
                (SELECT COUNT(*) FROM event_rsvps er WHERE er.event_id = e.id AND er.response = 'attending') AS attending_count,
                (SELECT COALESCE(SUM(er.guest_count),0) FROM event_rsvps er WHERE er.event_id = e.id AND er.response = 'attending') AS plus_one_count,
                (SELECT COUNT(*) FROM event_rsvps er WHERE er.event_id = e.id AND er.response = 'waitlist') AS waitlist_count,
                (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id = e.id AND ea.status IN ('checked_in','attended','left_early')) AS attendance_count,
                (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id = e.id AND ea.status = 'no_show') AS no_show_count,
                (SELECT eh.host_role FROM event_hosts eh WHERE eh.event_id = e.id AND eh.user_id = ? LIMIT 1) AS actor_host_role
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         JOIN event_locations el ON el.event_id = e.id
         JOIN locations l ON l.id = el.location_id
         WHERE l.business_id = ?
           AND l.status <> 'archived'
         ORDER BY e.starts_at ASC, e.id ASC
         LIMIT {$limit}"
    );
    $stmt->execute([(int)$actor['id'], $businessId]);
    return $stmt->fetchAll();
}

function coveted_business_host_event(array $actor, int $businessId, string $eventRef): ?array
{
    $eventRef = trim($eventRef);
    if ($eventRef === '' || strlen($eventRef) > 64) {
        return null;
    }

    if (!coveted_business_actor_can_view($actor, $businessId)) {
        throw new InvalidArgumentException('That business is unavailable to this account.');
    }

    $stmt = coveted_db()->prepare(
        "SELECT e.id, e.public_id, e.group_id, e.title, e.description, e.event_type, e.audience,
                e.timezone, e.starts_at, e.ends_at, e.capacity, e.plus_one_allowed,
                e.location_visibility, e.status,
                g.name AS group_name,
                l.id AS location_id, l.public_id AS location_public_id, l.name AS location_name,
                l.address1, l.address2, l.city, l.region, l.postal_code, l.country,
                l.timezone AS location_timezone, l.status AS location_status,
                el.reveal_notes,
                (SELECT COUNT(*) FROM event_rsvps er WHERE er.event_id = e.id AND er.response = 'attending') AS attending_count,
                (SELECT COALESCE(SUM(er.guest_count),0) FROM event_rsvps er WHERE er.event_id = e.id AND er.response = 'attending') AS plus_one_count,
                (SELECT COUNT(*) FROM event_rsvps er WHERE er.event_id = e.id AND er.response = 'waitlist') AS waitlist_count,
                (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id = e.id AND ea.status IN ('checked_in','attended','left_early')) AS attendance_count,
                (SELECT COUNT(*) FROM event_attendance ea WHERE ea.event_id = e.id AND ea.status = 'no_show') AS no_show_count,
                (SELECT eh.host_role FROM event_hosts eh WHERE eh.event_id = e.id AND eh.user_id = ? LIMIT 1) AS actor_host_role
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         JOIN event_locations el ON el.event_id = e.id
         JOIN locations l ON l.id = el.location_id
         WHERE l.business_id = ?
           AND (e.public_id = ? OR CAST(e.id AS CHAR) = ?)
         LIMIT 1"
    );
    $stmt->execute([(int)$actor['id'], $businessId, $eventRef, $eventRef]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return array<int,array<string,mixed>> */
function coveted_business_host_guests(array $actor, int $businessId, int $eventId): array
{
    $event = coveted_business_host_event($actor, $businessId, (string)$eventId);
    if (!$event) {
        throw new InvalidArgumentException('Event not found for this business.');
    }

    $stmt = coveted_db()->prepare(
        "SELECT u.id AS user_id, u.public_id AS user_public_id, u.display_name,
                p.avatar_url,
                er.response, er.guest_count,
                ea.status AS attendance_status, ea.checked_in_at
         FROM event_rsvps er
         JOIN users u ON u.id = er.user_id AND u.status = 'active'
         LEFT JOIN profiles p ON p.user_id = u.id
         LEFT JOIN event_attendance ea ON ea.event_id = er.event_id AND ea.user_id = er.user_id
         WHERE er.event_id = ? AND er.response IN ('attending','waitlist')
         ORDER BY CASE er.response WHEN 'attending' THEN 0 ELSE 1 END, u.display_name, u.id"
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_business_host_campaigns(array $actor, int $businessId, int $eventId): array
{
    $event = coveted_business_host_event($actor, $businessId, (string)$eventId);
    if (!$event) {
        throw new InvalidArgumentException('Event not found for this business.');
    }

    $stmt = coveted_db()->prepare(
        "SELECT c.id, c.public_id, c.title, c.campaign_type, c.trigger_key, c.status,
                c.starts_at, c.ends_at, c.quantity_limit, c.per_user_limit,
                rt.title AS reward_title, rt.reward_type, rt.claim_mode
         FROM campaign_event_links cel
         JOIN campaigns c ON c.id = cel.campaign_id
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         WHERE cel.event_id = ?
         ORDER BY CASE c.status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END, c.title, c.id"
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_business_host_artists(array $actor, int $businessId, int $eventId): array
{
    $event = coveted_business_host_event($actor, $businessId, (string)$eventId);
    if (!$event) {
        throw new InvalidArgumentException('Event not found for this business.');
    }

    $stmt = coveted_db()->prepare(
        "SELECT ap.id, ap.public_id, ap.artist_name, ap.avatar_url, ea.appearance_type
         FROM event_artists ea
         JOIN artist_profiles ap ON ap.id = ea.artist_id
         WHERE ea.event_id = ? AND ap.status = 'active'
         ORDER BY FIELD(ea.appearance_type,'featured','support','dj','session','mystery'), ap.artist_name, ap.id"
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function coveted_business_host_can_checkin(array $actor, array $event): bool
{
    if (coveted_is_system_admin($actor)) {
        return true;
    }

    return in_array((string)($event['actor_host_role'] ?? ''), ['lead','cohost','checkin'], true);
}

function coveted_business_host_record_attendance(
    array $actor,
    int $businessId,
    string $eventRef,
    int $userId,
    string $status
): void {
    $event = coveted_business_host_event($actor, $businessId, $eventRef);
    if (!$event) {
        throw new InvalidArgumentException('Event not found for this business.');
    }
    if (!coveted_business_host_can_checkin($actor, $event)) {
        throw new InvalidArgumentException('Coveted Admin must assign you check-in access for this event.');
    }

    coveted_event_record_attendance($actor, (string)$event['public_id'], $userId, $status);
}

function coveted_business_host_expected_count(array $event): int
{
    return max(0, (int)($event['attending_count'] ?? 0) + (int)($event['plus_one_count'] ?? 0));
}

function coveted_business_host_attendance_rate(array $event): ?int
{
    $expected = coveted_business_host_expected_count($event);
    if ($expected < 1) {
        return null;
    }

    return (int)round(min(100, max(0, ((int)($event['attendance_count'] ?? 0) / $expected) * 100)));
}
