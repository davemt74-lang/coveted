<?php
declare(strict_types=1);

require_once __DIR__ . '/events.php';
require_once __DIR__ . '/rewards.php';

/** @return array<string,mixed> */
function coveted_event_experience_event_locked(PDO $pdo, string $eventRef): array
{
    $eventRef = trim($eventRef);
    if ($eventRef === '' || strlen($eventRef) > 64) {
        throw new InvalidArgumentException('That event is not available.');
    }

    $stmt = $pdo->prepare(
        "SELECT e.*, g.public_id AS group_public_id, g.name AS group_name, g.status AS group_status
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         WHERE e.public_id = ? OR CAST(e.id AS CHAR) = ?
         LIMIT 1 FOR UPDATE"
    );
    $stmt->execute([$eventRef, $eventRef]);
    $event = $stmt->fetch();

    if (!$event) {
        throw new InvalidArgumentException('That event is not available.');
    }

    return $event;
}

/** @return array<int,array<string,mixed>> */
function coveted_event_experience_reveals(int $eventId): array
{
    $stmt = coveted_db()->prepare(
        "SELECT id, reveal_at, reveal_type, title, content
         FROM event_mystery_reveals
         WHERE event_id = ? AND reveal_at <= NOW()
         ORDER BY reveal_at ASC, id ASC"
    );
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

/** @return array<string,mixed>|null */
function coveted_event_experience_location(array $event, bool $canManage, array $reveals): ?array
{
    $visibility = (string)($event['location_visibility'] ?? 'immediate');
    $completed = (string)($event['status'] ?? '') === 'completed';
    $locationRevealIsLive = false;
    foreach ($reveals as $reveal) {
        if (($reveal['reveal_type'] ?? '') === 'location') {
            $locationRevealIsLive = true;
            break;
        }
    }

    $visible = $canManage
        || $visibility === 'immediate'
        || ($visibility === 'scheduled_reveal' && ($completed || $locationRevealIsLive));

    // host_only and unrevealed scheduled locations are not fetched before authorization.
    if (!$visible) {
        return null;
    }

    $stmt = coveted_db()->prepare(
        "SELECT
            el.location_id,
            el.private_location_label,
            l.public_id AS location_public_id,
            l.name AS location_name,
            l.address1,
            l.address2,
            l.city,
            l.region,
            l.postal_code,
            l.country,
            b.public_id AS business_public_id,
            b.name AS business_name
         FROM event_locations el
         LEFT JOIN locations l ON l.id = el.location_id
         LEFT JOIN businesses b ON b.id = l.business_id
         WHERE el.event_id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$event['id']]);
    $location = $stmt->fetch();

    return $location ?: null;
}

/** @return array<int,array<string,mixed>> */
function coveted_event_experience_artists(array $event, bool $canManage, array $reveals, bool $attended): array
{
    $artistRevealIsLive = false;
    foreach ($reveals as $reveal) {
        if (($reveal['reveal_type'] ?? '') === 'artist') {
            $artistRevealIsLive = true;
            break;
        }
    }

    $started = coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() <= time();
    $artistVisible = $canManage
        || (string)$event['event_type'] !== 'mystery'
        || $artistRevealIsLive
        || $started
        || $attended
        || (string)$event['status'] === 'completed';

    if (!$artistVisible) {
        return [];
    }

    $stmt = coveted_db()->prepare(
        "SELECT
            ap.id,
            ap.public_id,
            ap.artist_name,
            ap.bio,
            ap.avatar_url,
            ap.status,
            ea.appearance_type
         FROM event_artists ea
         JOIN artist_profiles ap ON ap.id = ea.artist_id
         WHERE ea.event_id = ?
         ORDER BY FIELD(ea.appearance_type, 'featured','session','dj','support','mystery'), ap.artist_name, ap.id"
    );
    $stmt->execute([(int)$event['id']]);
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_event_experience_benefits(int $userId, int $eventId): array
{
    $stmt = coveted_db()->prepare(
        "SELECT
            ri.id,
            ri.public_id,
            ri.reward_template_id,
            ri.status,
            ri.issued_at,
            ri.viewed_at,
            ri.claimed_at,
            ri.expires_at,
            rt.title,
            rt.description,
            rt.reward_type,
            rt.claim_mode,
            rt.value_amount,
            rt.value_text,
            rt.cover_url,
            rt.owner_type,
            c.title AS campaign_title,
            ap.artist_name,
            b.name AS business_name,
            il.name AS location_name
         FROM reward_issuances ri
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         JOIN campaigns c ON c.id = ri.campaign_id
         LEFT JOIN artist_profiles ap ON ap.id = COALESCE(ri.artist_id, rt.artist_id)
         LEFT JOIN businesses b ON b.id = rt.business_id
         LEFT JOIN locations il ON il.id = ri.location_id
         WHERE ri.user_id = ?
           AND ri.event_id = ?
           AND ri.status <> 'cancelled'
         ORDER BY ri.issued_at DESC, ri.id DESC"
    );
    $stmt->execute([$userId, $eventId]);
    $benefits = $stmt->fetchAll();
    if (!$benefits) {
        return [];
    }

    $templateIds = array_map(
        static fn(array $benefit): int => (int)$benefit['reward_template_id'],
        $benefits
    );
    $mediaByTemplate = coveted_reward_media_for_templates($templateIds);

    foreach ($benefits as &$benefit) {
        $benefit['media'] = $mediaByTemplate[(int)$benefit['reward_template_id']] ?? [];
    }
    unset($benefit);

    return $benefits;
}

function coveted_event_experience_phase(array $event, bool $attended): string
{
    $status = (string)($event['status'] ?? '');
    if ($status === 'cancelled') {
        return 'cancelled';
    }
    if ($status === 'completed') {
        return $attended ? 'memory' : 'completed';
    }
    if ($attended) {
        return 'arrived';
    }

    return coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() > time()
        ? 'upcoming'
        : 'in_progress';
}

function coveted_event_feedback_for_user(array $actor, string $eventRef): ?string
{
    $event = coveted_event_by_ref($eventRef);
    if (
        !$event
        || (string)$event['status'] !== 'completed'
        || !coveted_event_user_attended((int)$event['id'], (int)$actor['id'])
    ) {
        throw new InvalidArgumentException('Event feedback is available only after a gathering you attended.');
    }

    $stmt = coveted_db()->prepare(
        'SELECT response FROM event_feedback WHERE event_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([(int)$event['id'], (int)$actor['id']]);
    $response = $stmt->fetchColumn();

    return $response !== false ? (string)$response : null;
}

function coveted_event_feedback_set(array $actor, string $eventRef, string $response): bool
{
    $response = strtolower(trim($response));
    if (!in_array($response, ['yes', 'maybe', 'no'], true)) {
        throw new InvalidArgumentException('Choose Yes, Maybe, or No.');
    }

    $actorId = (int)($actor['id'] ?? 0);
    if ($actorId < 1) {
        throw new InvalidArgumentException('Member account is required.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $event = coveted_event_experience_event_locked($pdo, $eventRef);
        if ((string)$event['status'] !== 'completed') {
            throw new InvalidArgumentException('Event feedback opens after the gathering is completed.');
        }

        $attendance = $pdo->prepare(
            "SELECT status
             FROM event_attendance
             WHERE event_id = ? AND user_id = ?
             LIMIT 1 FOR UPDATE"
        );
        $attendance->execute([(int)$event['id'], $actorId]);
        $attendanceStatus = $attendance->fetchColumn();
        if (!in_array((string)$attendanceStatus, ['checked_in', 'attended', 'left_early'], true)) {
            throw new InvalidArgumentException('Event feedback is available only to verified attendees.');
        }

        $existing = $pdo->prepare(
            'SELECT response FROM event_feedback WHERE event_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
        );
        $existing->execute([(int)$event['id'], $actorId]);
        $previous = $existing->fetchColumn();
        if ($previous !== false && hash_equals((string)$previous, $response)) {
            $pdo->commit();
            return false;
        }

        if ($previous === false) {
            $pdo->prepare(
                'INSERT INTO event_feedback (event_id, user_id, response) VALUES (?, ?, ?)'
            )->execute([(int)$event['id'], $actorId, $response]);
        } else {
            $pdo->prepare(
                'UPDATE event_feedback SET response = ?, updated_at = NOW() WHERE event_id = ? AND user_id = ?'
            )->execute([$response, (int)$event['id'], $actorId]);
        }

        coveted_audit(
            'event.feedback_set',
            'event',
            (string)$event['public_id'],
            [
                'response' => $response,
                'previous_response' => $previous !== false ? (string)$previous : null,
            ],
            $actorId
        );

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return array{total:int,yes:int,maybe:int,no:int} */
function coveted_event_feedback_summary(array $actor, string $eventRef): array
{
    $event = coveted_event_by_ref($eventRef);
    if (
        !$event
        || (string)$event['status'] !== 'completed'
        || !coveted_event_can_manage($event, $actor)
    ) {
        throw new InvalidArgumentException('Private feedback summary is unavailable.');
    }

    $stmt = coveted_db()->prepare(
        "SELECT ef.response, COUNT(*) AS total
         FROM event_feedback ef
         JOIN event_attendance ea
           ON ea.event_id = ef.event_id
          AND ea.user_id = ef.user_id
          AND ea.status IN ('checked_in','attended','left_early')
         WHERE ef.event_id = ?
         GROUP BY ef.response"
    );
    $stmt->execute([(int)$event['id']]);

    $summary = ['total' => 0, 'yes' => 0, 'maybe' => 0, 'no' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $key = (string)$row['response'];
        $count = (int)$row['total'];
        if (array_key_exists($key, $summary)) {
            $summary[$key] = $count;
            $summary['total'] += $count;
        }
    }

    return $summary;
}

/** @return array<string,mixed> */
function coveted_event_experience_for_user(array $actor, string $eventRef): array
{
    $event = coveted_event_by_ref($eventRef);
    if (!$event || !coveted_event_can_view($event, $actor)) {
        throw new InvalidArgumentException('That event is not available.');
    }

    $eventId = (int)$event['id'];
    $userId = (int)$actor['id'];
    $canManage = coveted_event_can_manage($event, $actor);

    $state = coveted_db()->prepare(
        "SELECT
            er.response AS rsvp_response,
            er.guest_count,
            ei.status AS invitation_status,
            ea.status AS attendance_status,
            ea.checked_in_at
         FROM events e
         LEFT JOIN event_rsvps er ON er.event_id = e.id AND er.user_id = ?
         LEFT JOIN event_invitations ei ON ei.event_id = e.id AND ei.user_id = ?
         LEFT JOIN event_attendance ea ON ea.event_id = e.id AND ea.user_id = ?
         WHERE e.id = ?
         LIMIT 1"
    );
    $state->execute([$userId, $userId, $userId, $eventId]);
    $memberState = $state->fetch() ?: [];

    $attended = in_array(
        (string)($memberState['attendance_status'] ?? ''),
        ['checked_in', 'attended', 'left_early'],
        true
    );
    $phase = coveted_event_experience_phase($event, $attended);

    // Once attendance is verified, the member-facing experience is intentionally quiet.
    if ($phase === 'arrived') {
        return [
            'event' => $event,
            'phase' => $phase,
            'can_manage' => $canManage,
            'attended' => true,
            'member_state' => $memberState,
            'location' => null,
            'reveals' => [],
            'artists' => [],
            'benefits' => [],
            'feedback' => null,
            'feedback_summary' => null,
        ];
    }

    $reveals = coveted_event_experience_reveals($eventId);
    $location = coveted_event_experience_location($event, $canManage, $reveals);
    $artists = coveted_event_experience_artists($event, $canManage, $reveals, $attended);

    $benefits = [];
    $feedback = null;
    if ($phase === 'memory') {
        $benefits = coveted_event_experience_benefits($userId, $eventId);
        $feedback = coveted_event_feedback_for_user($actor, (string)$event['public_id']);
    }

    $feedbackSummary = null;
    if ((string)$event['status'] === 'completed' && $canManage) {
        $feedbackSummary = coveted_event_feedback_summary($actor, (string)$event['public_id']);
    }

    return [
        'event' => $event,
        'phase' => $phase,
        'can_manage' => $canManage,
        'attended' => $attended,
        'member_state' => $memberState,
        'location' => $location,
        'reveals' => $reveals,
        'artists' => $artists,
        'benefits' => $benefits,
        'feedback' => $feedback,
        'feedback_summary' => $feedbackSummary,
    ];
}
