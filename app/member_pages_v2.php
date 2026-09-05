<?php
declare(strict_types=1);

require_once __DIR__ . '/events.php';
require_once __DIR__ . '/member_sample_data.php';

/** @return array<int,array<string,mixed>> */
function coveted_member_v2_invitations(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();

    if (coveted_member_sample_mode($user, $pdo)) {
        $sample = coveted_member_sample_data();
        $events = (array)($sample['events'] ?? []);
        $states = ['accepted', 'pending', 'declined'];
        $responses = ['attending', null, null];

        $rows = [];
        foreach ($events as $index => $event) {
            $rows[] = [
                'public_id' => 'sample-invite-' . (string)$event['public_id'],
                'invite_type' => 'member',
                'status' => $states[$index] ?? 'pending',
                'title' => (string)$event['title'],
                'description' => (string)($event['description'] ?? ''),
                'event_type' => (string)$event['event_type'],
                'audience' => 'invitation_only',
                'timezone' => (string)$event['timezone'],
                'starts_at' => (string)$event['starts_at'],
                'plus_one_allowed' => $index !== 2,
                'location_visibility' => $index === 2 ? 'scheduled_reveal' : 'immediate',
                'event_status' => 'published',
                'group_name' => (string)$event['group'],
                'rsvp_response' => $responses[$index] ?? null,
                'guest_count' => $index === 0 ? 1 : 0,
                'location_name' => (string)$event['location'],
                'location_city' => (string)$event['city'],
                'image' => (string)$event['image'],
                'is_sample' => true,
            ];
        }

        return $rows;
    }

    $stmt = $pdo->prepare(
        "SELECT
            ei.public_id,
            ei.invite_type,
            ei.status,
            e.title,
            e.description,
            e.event_type,
            e.audience,
            e.timezone,
            e.starts_at,
            e.plus_one_allowed,
            e.location_visibility,
            e.status AS event_status,
            g.name AS group_name,
            er.response AS rsvp_response,
            er.guest_count,
            COALESCE(l.name, el.private_location_label) AS location_name,
            l.city AS location_city
         FROM event_invitations ei
         JOIN events e ON e.id = ei.event_id
         JOIN social_groups g ON g.id = e.group_id
         LEFT JOIN event_rsvps er ON er.event_id = e.id AND er.user_id = ei.user_id
         LEFT JOIN event_locations el ON el.event_id = e.id
         LEFT JOIN locations l ON l.id = el.location_id
         WHERE ei.user_id = ?
           AND e.status <> 'draft'
         ORDER BY FIELD(ei.status, 'pending', 'accepted', 'declined', 'expired', 'revoked'), e.starts_at ASC
         LIMIT 100"
    );
    $stmt->execute([(int)$user['id']]);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['image'] = null;
        $row['is_sample'] = false;
    }
    unset($row);

    return $rows;
}

function coveted_member_v2_invitation_bucket(array $invite, int $now): string
{
    $future = coveted_utc_datetime((string)$invite['starts_at'])->getTimestamp() > $now;
    if (!$future || in_array((string)$invite['event_status'], ['completed', 'cancelled'], true)) {
        return 'past';
    }

    if ((string)$invite['status'] === 'pending' && (string)$invite['event_status'] === 'published') {
        return 'waiting';
    }

    if ((string)$invite['status'] === 'accepted' || (string)($invite['rsvp_response'] ?? '') === 'attending') {
        return 'accepted';
    }

    return 'maybe';
}

/** @return array<int,array<string,mixed>> */
function coveted_member_v2_events(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();

    if (coveted_member_sample_mode($user, $pdo)) {
        $sample = coveted_member_sample_data();
        $people = (array)($sample['people'] ?? []);
        $events = [];

        foreach ((array)($sample['events'] ?? []) as $index => $event) {
            $attendees = array_map(
                static fn(array $person): string => (string)$person['image'],
                array_slice($people, $index * 2, 4)
            );

            $events[] = [
                'id' => 0,
                'public_id' => (string)$event['public_id'],
                'group_id' => 0,
                'title' => (string)$event['title'],
                'description' => (string)($event['description'] ?? ''),
                'event_type' => (string)$event['event_type'],
                'audience' => $index === 1 ? 'invitation_only' : 'group',
                'timezone' => (string)$event['timezone'],
                'starts_at' => (string)$event['starts_at'],
                'ends_at' => null,
                'capacity' => 24,
                'plus_one_allowed' => true,
                'location_visibility' => $index === 2 ? 'scheduled_reveal' : 'immediate',
                'status' => 'published',
                'group_name' => (string)$event['group'],
                'group_status' => 'active',
                'response' => $index === 0 ? 'attending' : null,
                'attendance_status' => null,
                'invitation_status' => $index === 1 ? 'pending' : null,
                'location_name' => (string)$event['location'],
                'location_city' => (string)$event['city'],
                'location_revealed' => $index !== 2,
                'can_manage' => false,
                'assigned_host_role' => '',
                'image' => (string)$event['image'],
                'attendee_images' => $attendees,
                'guest_count' => (int)($event['guest_count'] ?? 0),
                'is_sample' => true,
            ];
        }

        return $events;
    }

    $events = coveted_events_for_user($user, 100);
    $isSystemAdmin = coveted_is_system_admin($user);

    foreach ($events as &$event) {
        $event['assigned_host_role'] = $isSystemAdmin
            ? 'system_admin'
            : (coveted_event_assigned_host_role((int)$event['id'], (int)$user['id']) ?? '');
        $event['image'] = null;
        $event['attendee_images'] = [];
        $event['is_sample'] = false;
    }
    unset($event);

    return $events;
}
