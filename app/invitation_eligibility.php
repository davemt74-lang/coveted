<?php
declare(strict_types=1);

require_once __DIR__ . '/event_management.php';

/**
 * Invitation intelligence is deliberately split into two privacy domains:
 * - hosts receive only observable participation/invitation history and aggregates;
 * - members may see their own private feedback/reconnect signals.
 *
 * Individual event_feedback and reconnect choices must never appear in a host
 * candidate row or become a host-visible recommendation reason.
 */

/** @return array<string,mixed> */
function coveted_invitation_target_context(array $actor, string $eventRef): array
{
    $eventRef = trim($eventRef);
    if ($eventRef === '' || strlen($eventRef) > 64) {
        throw new InvalidArgumentException('Choose an event.');
    }

    $stmt = coveted_db()->prepare(
        "SELECT
            e.*,
            g.public_id AS group_public_id,
            g.name AS group_name,
            g.status AS group_status,
            el.location_id,
            l.public_id AS location_public_id,
            l.name AS location_name,
            l.city AS location_city,
            b.public_id AS business_public_id,
            b.name AS business_name,
            vr.relationship_status AS venue_relationship_status,
            COALESCE(vr.benefits_enabled, 0) AS venue_benefits_enabled,
            COALESCE(vr.mystery_events_enabled, 0) AS venue_mystery_enabled
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         LEFT JOIN event_locations el ON el.event_id = e.id
         LEFT JOIN locations l ON l.id = el.location_id
         LEFT JOIN businesses b ON b.id = l.business_id
         LEFT JOIN venue_relationships vr
           ON vr.group_id = e.group_id
          AND vr.location_id = el.location_id
         WHERE e.public_id = ? OR CAST(e.id AS CHAR) = ?
         LIMIT 1"
    );
    $stmt->execute([$eventRef, $eventRef]);
    $event = $stmt->fetch();

    if (!$event || !coveted_event_can_manage($event, $actor)) {
        throw new InvalidArgumentException('You cannot manage invitation eligibility for this event.');
    }

    $future = coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() > time();
    if (!$future || in_array((string)$event['status'], ['completed', 'cancelled'], true)) {
        throw new InvalidArgumentException('Next-invite planning is available only for a future active event.');
    }

    $event['can_send_invitations'] = (string)$event['status'] === 'published';
    $event['is_mystery'] = (string)$event['event_type'] === 'mystery';

    return $event;
}

/** @return array<int,array<string,mixed>> */
function coveted_invitation_host_events(array $actor, int $limit = 100): array
{
    $limit = max(1, min($limit, 250));
    $actorId = (int)($actor['id'] ?? 0);
    if ($actorId < 1 || !coveted_event_actor_has_host_approval($actor)) {
        return [];
    }

    $isSystemAdmin = coveted_is_system_admin($actor) ? 1 : 0;
    $stmt = coveted_db()->prepare(
        "SELECT
            e.id, e.public_id, e.group_id, e.title, e.description, e.event_type, e.audience,
            e.timezone, e.starts_at, e.ends_at, e.capacity, e.plus_one_allowed,
            e.location_visibility, e.status,
            g.public_id AS group_public_id, g.name AS group_name, g.status AS group_status,
            1 AS can_manage
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         WHERE e.starts_at > NOW()
           AND e.status IN ('draft','published','closed')
           AND (
                ? = 1
                OR EXISTS (
                    SELECT 1
                    FROM group_memberships gm
                    WHERE gm.group_id = e.group_id
                      AND gm.user_id = ?
                      AND gm.membership_status = 'active'
                      AND gm.group_role IN ('host','group_admin')
                )
                OR EXISTS (
                    SELECT 1
                    FROM event_hosts eh
                    WHERE eh.event_id = e.id
                      AND eh.user_id = ?
                      AND eh.host_role IN ('lead','cohost')
                )
           )
         ORDER BY e.starts_at ASC, e.id ASC
         LIMIT {$limit}"
    );
    $stmt->execute([$isSystemAdmin, $actorId, $actorId]);

    return $stmt->fetchAll();
}

/** @return array{band:string,label:string,reasons:array<int,string>,cautions:array<int,string>} */
function coveted_invitation_candidate_classify(array $row, array $target): array
{
    $verified = (int)($row['verified_attendance_count'] ?? 0);
    $sameType = (int)($row['same_type_attendance_count'] ?? 0);
    $mystery = (int)($row['mystery_attendance_count'] ?? 0);
    $sameVenue = (int)($row['same_venue_attendance_count'] ?? 0);
    $accepted = (int)($row['accepted_invite_count'] ?? 0);
    $declined = (int)($row['declined_invite_count'] ?? 0);
    $noShows = (int)($row['no_show_count'] ?? 0);

    $strong = (bool)$target['is_mystery']
        ? ($mystery > 0 || $verified >= 2 || $sameVenue > 0)
        : ($sameType > 0 || $verified >= 2 || $sameVenue >= 2);

    if ($strong) {
        $band = 'recommended';
        $label = 'Recommended';
    } elseif ($verified > 0 || $accepted > 0) {
        $band = 'eligible';
        $label = 'Eligible';
    } else {
        $band = 'new';
        $label = 'New history';
    }

    $reasons = [];
    if ($sameType > 0 && empty($target['is_mystery'])) {
        $reasons[] = $sameType . ' verified ' . str_replace('_', ' ', (string)$target['event_type'])
            . ' gathering' . ($sameType === 1 ? '' : 's');
    }
    if (!empty($target['is_mystery']) && $mystery > 0) {
        $reasons[] = $mystery . ' prior mystery gathering' . ($mystery === 1 ? '' : 's');
    }
    if ($verified >= 2) {
        $reasons[] = $verified . ' verified gatherings with this group';
    } elseif ($verified === 1 && !$reasons) {
        $reasons[] = '1 verified gathering with this group';
    }
    if ($sameVenue > 0 && !empty($target['location_id'])) {
        $reasons[] = $sameVenue . ' prior verified visit' . ($sameVenue === 1 ? '' : 's') . ' at this venue';
    }
    if ($accepted > 0 && count($reasons) < 3) {
        $reasons[] = 'Accepted ' . $accepted . ' prior invitation' . ($accepted === 1 ? '' : 's');
    }
    if (!$reasons) {
        $reasons[] = 'Active group member with no verified gathering history yet';
    }

    $cautions = [];
    if ($noShows > 0) {
        $cautions[] = $noShows . ' prior no-show' . ($noShows === 1 ? '' : 's') . ' recorded';
    }
    if ($declined >= 2 && $declined > $accepted) {
        $cautions[] = 'More prior invitations declined than accepted';
    }

    return [
        'band' => $band,
        'label' => $label,
        'reasons' => array_slice($reasons, 0, 3),
        'cautions' => array_slice($cautions, 0, 2),
    ];
}

/**
 * Host-visible candidate list. This query intentionally does not touch
 * event_feedback or reconnect_requests.
 *
 * @return array<int,array<string,mixed>>
 */
function coveted_invitation_candidates(array $actor, string $eventRef, int $limit = 250): array
{
    $limit = max(1, min($limit, 250));
    $target = coveted_invitation_target_context($actor, $eventRef);
    $targetLocationId = $target['location_id'] !== null ? (int)$target['location_id'] : 0;

    $sql = "WITH attendance_history AS (
                SELECT
                    ea.user_id,
                    COUNT(DISTINCT CASE
                        WHEN he.status = 'completed'
                         AND ea.status IN ('checked_in','attended','left_early')
                        THEN he.id END) AS verified_attendance_count,
                    COUNT(DISTINCT CASE
                        WHEN he.status = 'completed'
                         AND he.event_type = ?
                         AND ea.status IN ('checked_in','attended','left_early')
                        THEN he.id END) AS same_type_attendance_count,
                    COUNT(DISTINCT CASE
                        WHEN he.status = 'completed'
                         AND he.event_type = 'mystery'
                         AND ea.status IN ('checked_in','attended','left_early')
                        THEN he.id END) AS mystery_attendance_count,
                    COUNT(DISTINCT CASE
                        WHEN ? > 0
                         AND he.status = 'completed'
                         AND hel.location_id = ?
                         AND ea.status IN ('checked_in','attended','left_early')
                        THEN he.id END) AS same_venue_attendance_count,
                    COUNT(DISTINCT CASE
                        WHEN he.status = 'completed' AND ea.status = 'no_show'
                        THEN he.id END) AS no_show_count,
                    MAX(CASE
                        WHEN he.status = 'completed'
                         AND ea.status IN ('checked_in','attended','left_early')
                        THEN he.starts_at END) AS last_attended_at
                FROM event_attendance ea
                JOIN events he ON he.id = ea.event_id
                LEFT JOIN event_locations hel ON hel.event_id = he.id
                WHERE he.group_id = ?
                GROUP BY ea.user_id
            ),
            invite_history AS (
                SELECT
                    ei.user_id,
                    COUNT(DISTINCT CASE
                        WHEN he.status = 'completed' AND ei.status = 'accepted'
                        THEN he.id END) AS accepted_invite_count,
                    COUNT(DISTINCT CASE
                        WHEN he.status = 'completed' AND ei.status = 'declined'
                        THEN he.id END) AS declined_invite_count
                FROM event_invitations ei
                JOIN events he ON he.id = ei.event_id
                WHERE he.group_id = ?
                GROUP BY ei.user_id
            )
            SELECT
                u.id AS user_id,
                u.public_id AS user_public_id,
                u.display_name,
                p.avatar_url,
                p.city,
                gm.group_role,
                COALESCE(ah.verified_attendance_count, 0) AS verified_attendance_count,
                COALESCE(ah.same_type_attendance_count, 0) AS same_type_attendance_count,
                COALESCE(ah.mystery_attendance_count, 0) AS mystery_attendance_count,
                COALESCE(ah.same_venue_attendance_count, 0) AS same_venue_attendance_count,
                COALESCE(ah.no_show_count, 0) AS no_show_count,
                ah.last_attended_at,
                COALESCE(ih.accepted_invite_count, 0) AS accepted_invite_count,
                COALESCE(ih.declined_invite_count, 0) AS declined_invite_count,
                ti.public_id AS target_invitation_public_id,
                ti.status AS target_invitation_status,
                ti.invite_type AS target_invite_type,
                tr.response AS target_rsvp_response
             FROM group_memberships gm
             JOIN users u ON u.id = gm.user_id AND u.status = 'active'
             LEFT JOIN profiles p ON p.user_id = u.id
             LEFT JOIN attendance_history ah ON ah.user_id = u.id
             LEFT JOIN invite_history ih ON ih.user_id = u.id
             LEFT JOIN event_invitations ti
               ON ti.event_id = ? AND ti.user_id = u.id
             LEFT JOIN event_rsvps tr
               ON tr.event_id = ? AND tr.user_id = u.id
             WHERE gm.group_id = ?
               AND gm.membership_status = 'active'
               AND u.id <> ?
               AND NOT EXISTS (
                   SELECT 1 FROM event_hosts eh
                   WHERE eh.event_id = ? AND eh.user_id = u.id
               )
             ORDER BY u.display_name, u.id
             LIMIT {$limit}";

    $stmt = coveted_db()->prepare($sql);
    $stmt->execute([
        (string)$target['event_type'],
        $targetLocationId,
        $targetLocationId,
        (int)$target['group_id'],
        (int)$target['group_id'],
        (int)$target['id'],
        (int)$target['id'],
        (int)$target['group_id'],
        (int)$actor['id'],
        (int)$target['id'],
    ]);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $classification = coveted_invitation_candidate_classify($row, $target);
        $row['band'] = $classification['band'];
        $row['band_label'] = $classification['label'];
        $row['reasons'] = $classification['reasons'];
        $row['cautions'] = $classification['cautions'];
        $row['already_invited'] = in_array(
            (string)($row['target_invitation_status'] ?? ''),
            ['pending', 'accepted', 'declined'],
            true
        ) || in_array(
            (string)($row['target_rsvp_response'] ?? ''),
            ['attending', 'waitlist', 'declined'],
            true
        );
        $row['can_invite'] = (bool)$target['can_send_invitations'] && !$row['already_invited'];
    }
    unset($row);

    $bandOrder = ['recommended' => 0, 'eligible' => 1, 'new' => 2];
    usort(
        $rows,
        static function (array $a, array $b) use ($bandOrder): int {
            $existingCompare = ((int)!empty($a['already_invited'])) <=> ((int)!empty($b['already_invited']));
            if ($existingCompare !== 0) {
                return $existingCompare;
            }
            $bandCompare = ($bandOrder[(string)$a['band']] ?? 9) <=> ($bandOrder[(string)$b['band']] ?? 9);
            if ($bandCompare !== 0) {
                return $bandCompare;
            }
            $recentA = (string)($a['last_attended_at'] ?? '');
            $recentB = (string)($b['last_attended_at'] ?? '');
            if ($recentA !== $recentB) {
                return strcmp($recentB, $recentA);
            }
            return strcasecmp((string)$a['display_name'], (string)$b['display_name']);
        }
    );

    return $rows;
}

/**
 * Group-level, host-safe experience results. Feedback and reconnects are
 * aggregated before leaving the service; no member identities are selected.
 *
 * @return array<string,mixed>
 */
function coveted_invitation_experience_signals(array $actor, string $eventRef): array
{
    $target = coveted_invitation_target_context($actor, $eventRef);
    $pdo = coveted_db();

    $history = $pdo->prepare(
        "SELECT
            COUNT(DISTINCT e.id) AS completed_events,
            COUNT(DISTINCT CASE WHEN e.event_type = ? THEN e.id END) AS same_type_events,
            COUNT(DISTINCT CASE WHEN e.event_type = 'mystery' THEN e.id END) AS mystery_events,
            COUNT(CASE WHEN ea.status IN ('checked_in','attended','left_early') THEN 1 END) AS verified_visits,
            COUNT(DISTINCT CASE WHEN ea.status IN ('checked_in','attended','left_early') THEN ea.user_id END) AS unique_attendees,
            COUNT(CASE WHEN e.event_type = 'mystery' AND ea.status IN ('checked_in','attended','left_early') THEN 1 END) AS mystery_visits,
            MAX(e.starts_at) AS last_completed_at
         FROM events e
         LEFT JOIN event_attendance ea ON ea.event_id = e.id
         WHERE e.group_id = ? AND e.status = 'completed'"
    );
    $history->execute([(string)$target['event_type'], (int)$target['group_id']]);
    $summary = $history->fetch() ?: [];

    $repeat = $pdo->prepare(
        "SELECT COUNT(*)
         FROM (
             SELECT ea.user_id
             FROM event_attendance ea
             JOIN events e ON e.id = ea.event_id
             JOIN users u ON u.id = ea.user_id AND u.status = 'active'
             WHERE e.group_id = ?
               AND e.status = 'completed'
               AND ea.status IN ('checked_in','attended','left_early')
             GROUP BY ea.user_id
             HAVING COUNT(DISTINCT e.id) >= 2
         ) repeat_members"
    );
    $repeat->execute([(int)$target['group_id']]);
    $summary['repeat_attendees'] = (int)$repeat->fetchColumn();

    $feedback = $pdo->prepare(
        "SELECT ef.response, COUNT(*) AS total
         FROM event_feedback ef
         JOIN events e ON e.id = ef.event_id AND e.status = 'completed'
         JOIN event_attendance ea
           ON ea.event_id = ef.event_id
          AND ea.user_id = ef.user_id
          AND ea.status IN ('checked_in','attended','left_early')
         WHERE e.group_id = ?
         GROUP BY ef.response"
    );
    $feedback->execute([(int)$target['group_id']]);
    $feedbackSummary = ['total' => 0, 'yes' => 0, 'maybe' => 0, 'no' => 0];
    foreach ($feedback->fetchAll() as $row) {
        $key = (string)$row['response'];
        if (!array_key_exists($key, $feedbackSummary)) {
            continue;
        }
        $count = (int)$row['total'];
        $feedbackSummary[$key] = $count;
        $feedbackSummary['total'] += $count;
    }
    $summary['feedback'] = $feedbackSummary;

    $matches = $pdo->prepare(
        "SELECT COUNT(*)
         FROM reconnect_requests rr
         JOIN events e ON e.id = rr.event_id AND e.status = 'completed'
         JOIN event_attendance a1
           ON a1.event_id = rr.event_id
          AND a1.user_id = rr.requester_user_id
          AND a1.status IN ('checked_in','attended','left_early')
         JOIN event_attendance a2
           ON a2.event_id = rr.event_id
          AND a2.user_id = rr.target_user_id
          AND a2.status IN ('checked_in','attended','left_early')
         WHERE e.group_id = ?
           AND rr.status = 'mutual'
           AND rr.requester_user_id < rr.target_user_id"
    );
    $matches->execute([(int)$target['group_id']]);
    $summary['mutual_reconnects'] = (int)$matches->fetchColumn();

    $responses = $pdo->prepare(
        "SELECT
            COUNT(CASE WHEN ei.status = 'accepted' THEN 1 END) AS accepted,
            COUNT(CASE WHEN ei.status = 'declined' THEN 1 END) AS declined
         FROM event_invitations ei
         JOIN events e ON e.id = ei.event_id
         WHERE e.group_id = ? AND e.status = 'completed'"
    );
    $responses->execute([(int)$target['group_id']]);
    $responseSummary = $responses->fetch() ?: ['accepted' => 0, 'declined' => 0];
    $summary['accepted_invitations'] = (int)$responseSummary['accepted'];
    $summary['declined_invitations'] = (int)$responseSummary['declined'];

    $summary['completed_events'] = (int)($summary['completed_events'] ?? 0);
    $summary['same_type_events'] = (int)($summary['same_type_events'] ?? 0);
    $summary['mystery_events'] = (int)($summary['mystery_events'] ?? 0);
    $summary['verified_visits'] = (int)($summary['verified_visits'] ?? 0);
    $summary['unique_attendees'] = (int)($summary['unique_attendees'] ?? 0);
    $summary['mystery_visits'] = (int)($summary['mystery_visits'] ?? 0);
    $summary['venue_relationship_status'] = (string)($target['venue_relationship_status'] ?? '');
    $summary['venue_benefits_enabled'] = (int)($target['venue_benefits_enabled'] ?? 0);
    $summary['venue_mystery_enabled'] = (int)($target['venue_mystery_enabled'] ?? 0);
    $summary['location_name'] = (string)($target['location_name'] ?? '');
    $summary['business_name'] = (string)($target['business_name'] ?? '');

    return $summary;
}

/**
 * Send one recommendation through the canonical invitation service. The
 * planner-specific policy is enforced inside the canonical transaction so a
 * concurrent membership, host, RSVP, or invitation change cannot be bypassed.
 */
function coveted_invitation_invite_candidate(array $actor, string $eventRef, int $userId): string
{
    if ($userId < 1 || $userId === (int)$actor['id']) {
        throw new InvalidArgumentException('Choose an eligible group member.');
    }

    $target = coveted_invitation_target_context($actor, $eventRef);
    if (empty($target['can_send_invitations'])) {
        throw new InvalidArgumentException('Publish the future event before sending invitations.');
    }

    return coveted_event_invite_user(
        $actor,
        (string)$target['public_id'],
        $userId,
        'member',
        [
            'require_active_group_member' => true,
            'reject_event_host' => true,
            'respect_existing_response' => true,
            'idempotent_pending' => true,
        ]
    );
}

/**
 * Member-private next-experience context. These values are returned only for
 * the current actor and are never consumed by coveted_invitation_candidates().
 *
 * @return array<int,array<string,mixed>>
 */
function coveted_next_experience_for_member(array $actor, int $limit = 6): array
{
    $limit = max(1, min($limit, 20));
    $actorId = (int)($actor['id'] ?? 0);
    if ($actorId < 1) {
        throw new InvalidArgumentException('Member account is required.');
    }

    $stmt = coveted_db()->prepare(
        "SELECT
            g.id AS group_id,
            g.public_id AS group_public_id,
            g.name AS group_name,
            gm.group_role,
            (
                SELECT COUNT(DISTINCT e.id)
                FROM events e
                JOIN event_attendance ea ON ea.event_id = e.id
                WHERE e.group_id = g.id
                  AND e.status = 'completed'
                  AND ea.user_id = ?
                  AND ea.status IN ('checked_in','attended','left_early')
            ) AS verified_attendance_count,
            (
                SELECT COUNT(DISTINCT e.id)
                FROM events e
                JOIN event_attendance ea ON ea.event_id = e.id
                WHERE e.group_id = g.id
                  AND e.status = 'completed'
                  AND e.event_type = 'mystery'
                  AND ea.user_id = ?
                  AND ea.status IN ('checked_in','attended','left_early')
            ) AS mystery_attendance_count,
            (
                SELECT MAX(e.starts_at)
                FROM events e
                JOIN event_attendance ea ON ea.event_id = e.id
                WHERE e.group_id = g.id
                  AND e.status = 'completed'
                  AND ea.user_id = ?
                  AND ea.status IN ('checked_in','attended','left_early')
            ) AS last_attended_at,
            (
                SELECT ef.response
                FROM event_feedback ef
                JOIN events e ON e.id = ef.event_id AND e.status = 'completed'
                JOIN event_attendance ea
                  ON ea.event_id = ef.event_id
                 AND ea.user_id = ef.user_id
                 AND ea.status IN ('checked_in','attended','left_early')
                WHERE ef.user_id = ? AND e.group_id = g.id
                ORDER BY e.starts_at DESC, ef.updated_at DESC, ef.id DESC
                LIMIT 1
            ) AS latest_private_feedback,
            (
                SELECT COUNT(*)
                FROM reconnect_requests rr
                JOIN events e ON e.id = rr.event_id AND e.status = 'completed'
                JOIN event_attendance mine
                  ON mine.event_id = rr.event_id
                 AND mine.user_id = ?
                 AND mine.status IN ('checked_in','attended','left_early')
                JOIN event_attendance theirs
                  ON theirs.event_id = rr.event_id
                 AND theirs.user_id = CASE
                    WHEN rr.requester_user_id = ? THEN rr.target_user_id
                    ELSE rr.requester_user_id END
                 AND theirs.status IN ('checked_in','attended','left_early')
                WHERE e.group_id = g.id
                  AND rr.status = 'mutual'
                  AND rr.requester_user_id < rr.target_user_id
                  AND (rr.requester_user_id = ? OR rr.target_user_id = ?)
            ) AS mutual_reconnect_count
         FROM group_memberships gm
         JOIN social_groups g ON g.id = gm.group_id AND g.status = 'active'
         WHERE gm.user_id = ? AND gm.membership_status = 'active'
         ORDER BY last_attended_at IS NULL, last_attended_at DESC, g.name
         LIMIT {$limit}"
    );
    $stmt->execute([
        $actorId,
        $actorId,
        $actorId,
        $actorId,
        $actorId,
        $actorId,
        $actorId,
        $actorId,
        $actorId,
    ]);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $verified = (int)$row['verified_attendance_count'];
        $mystery = (int)$row['mystery_attendance_count'];
        $feedback = (string)($row['latest_private_feedback'] ?? '');
        $mutual = (int)$row['mutual_reconnect_count'];

        if ($verified < 1) {
            $state = 'building';
            $label = 'Building history';
            $message = 'Attend a gathering and Coveted can begin shaping your next-experience context.';
        } elseif ($feedback === 'no') {
            $state = 'preference_recorded';
            $label = 'Preference recorded';
            $message = 'Your latest private answer was No. Coveted keeps that preference private from hosts.';
        } elseif ($feedback === 'maybe') {
            $state = 'selective';
            $label = 'Selective';
            $message = 'Your latest private answer was Maybe. Your next-experience context stays intentionally selective.';
        } elseif ($feedback === 'yes') {
            $state = 'open';
            $label = 'Open to more';
            $message = 'Your latest private answer was Yes. Coveted can use that only in your private next-experience context.';
        } elseif ($verified >= 2) {
            $state = 'open';
            $label = 'Open to more';
            $message = 'Your repeat verified attendance gives Coveted useful context for future invitations.';
        } else {
            $state = 'eligible';
            $label = 'Eligible';
            $message = 'You have verified history with this group and can build more context over time.';
        }

        $row['state'] = $state;
        $row['state_label'] = $label;
        $row['state_message'] = $message;
        $row['mystery_ready'] = $feedback !== 'no' && ($verified >= 2 || $mystery > 0);
        $row['connection_depth'] = $mutual > 0
            ? 'mutual_reconnect'
            : ($verified >= 2 ? 'repeat_attendance' : ($verified > 0 ? 'attendance' : 'none'));
    }
    unset($row);

    return $rows;
}
