<?php
declare(strict_types=1);

require_once __DIR__ . '/events.php';

function coveted_reconnect_assert_attendee_locked(PDO $pdo, int $eventId, int $userId): void
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM event_attendance
         WHERE event_id = ? AND user_id = ?
           AND status IN ('checked_in','attended','left_early')
         LIMIT 1"
    );
    $stmt->execute([$eventId, $userId]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('Reconnect is available only to verified event attendees.');
    }
}

/** @return array<int,array<string,mixed>> */
function coveted_reconnect_events_for_user(array $actor, int $limit = 100): array
{
    $limit = max(1, min($limit, 200));
    $stmt = coveted_db()->prepare(
        "SELECT e.id, e.public_id, e.title, e.starts_at, e.ends_at, e.timezone,
                g.public_id AS group_public_id, g.name AS group_name,
                ea.status AS attendance_status
         FROM event_attendance ea
         JOIN events e ON e.id = ea.event_id
         JOIN social_groups g ON g.id = e.group_id
         WHERE ea.user_id = ?
           AND ea.status IN ('checked_in','attended','left_early')
           AND e.status = 'completed'
         ORDER BY e.starts_at DESC, e.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([(int)$actor['id']]);
    return $stmt->fetchAll();
}

/**
 * Returns only verified attendees plus the current actor's own outgoing choice.
 * Incoming one-sided requests are intentionally never selected here.
 *
 * @return array<int,array<string,mixed>>
 */
function coveted_reconnect_attendees_for_event(array $actor, string $eventRef): array
{
    $event = coveted_event_by_ref($eventRef);
    if (!$event || $event['status'] !== 'completed') {
        throw new InvalidArgumentException('Reconnect opens after the event is completed.');
    }

    $pdo = coveted_db();
    coveted_reconnect_assert_attendee_locked($pdo, (int)$event['id'], (int)$actor['id']);

    $stmt = $pdo->prepare(
        "SELECT u.id AS user_id, u.display_name, p.avatar_url,
                rr.status AS my_request_status, rr.matched_at
         FROM event_attendance ea
         JOIN users u ON u.id = ea.user_id
         LEFT JOIN profiles p ON p.user_id = u.id
         LEFT JOIN reconnect_requests rr
           ON rr.event_id = ea.event_id
          AND rr.requester_user_id = ?
          AND rr.target_user_id = u.id
          AND rr.status IN ('pending','mutual')
         WHERE ea.event_id = ?
           AND ea.user_id <> ?
           AND ea.status IN ('checked_in','attended','left_early')
           AND u.status = 'active'
         ORDER BY u.display_name, u.id"
    );
    $stmt->execute([(int)$actor['id'], (int)$event['id'], (int)$actor['id']]);
    return $stmt->fetchAll();
}

/**
 * Privately select another verified attendee. The target is never told about a
 * one-sided request; the pair becomes visible only after a reverse request.
 *
 * @return array{mutual:bool,event_id:string}
 */
function coveted_reconnect_request(array $actor, string $eventRef, int $targetUserId): array
{
    if ($targetUserId < 1 || $targetUserId === (int)$actor['id']) {
        throw new InvalidArgumentException('Choose another attendee.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $eventStmt = $pdo->prepare(
            "SELECT * FROM events
             WHERE public_id = ? OR CAST(id AS CHAR) = ?
             LIMIT 1 FOR UPDATE"
        );
        $eventStmt->execute([$eventRef, $eventRef]);
        $event = $eventStmt->fetch();
        if (!$event || $event['status'] !== 'completed') {
            throw new InvalidArgumentException('Reconnect opens after the event is completed.');
        }

        coveted_reconnect_assert_attendee_locked($pdo, (int)$event['id'], (int)$actor['id']);
        coveted_reconnect_assert_attendee_locked($pdo, (int)$event['id'], $targetUserId);

        $target = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $target->execute([$targetUserId]);
        if ($target->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('That attendee is no longer available.');
        }

        $pdo->prepare(
            "INSERT INTO reconnect_requests
                (event_id, requester_user_id, target_user_id, status)
             VALUES (?, ?, ?, 'pending')
             ON DUPLICATE KEY UPDATE
                status = IF(status IN ('expired','cancelled'), 'pending', status),
                matched_at = IF(status IN ('expired','cancelled'), NULL, matched_at)"
        )->execute([(int)$event['id'], (int)$actor['id'], $targetUserId]);

        $reverse = $pdo->prepare(
            "SELECT id, status
             FROM reconnect_requests
             WHERE event_id = ? AND requester_user_id = ? AND target_user_id = ?
             LIMIT 1 FOR UPDATE"
        );
        $reverse->execute([(int)$event['id'], $targetUserId, (int)$actor['id']]);
        $reverseRow = $reverse->fetch();
        $mutual = $reverseRow && in_array((string)$reverseRow['status'], ['pending','mutual'], true);

        if ($mutual) {
            $pdo->prepare(
                "UPDATE reconnect_requests
                 SET status = 'mutual', matched_at = COALESCE(matched_at, NOW())
                 WHERE event_id = ?
                   AND ((requester_user_id = ? AND target_user_id = ?)
                     OR (requester_user_id = ? AND target_user_id = ?))"
            )->execute([
                (int)$event['id'],
                (int)$actor['id'], $targetUserId,
                $targetUserId, (int)$actor['id'],
            ]);
        }

        coveted_audit(
            $mutual ? 'reconnect.matched' : 'reconnect.requested',
            'event',
            (string)$event['public_id'],
            $mutual ? ['matched_user_id' => $targetUserId] : [],
            (int)$actor['id']
        );
        $pdo->commit();

        return ['mutual' => (bool)$mutual, 'event_id' => (string)$event['public_id']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_reconnect_cancel(array $actor, string $eventRef, int $targetUserId): bool
{
    if ($targetUserId < 1 || $targetUserId === (int)$actor['id']) {
        throw new InvalidArgumentException('Choose another attendee.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $eventStmt = $pdo->prepare(
            "SELECT id, public_id, status FROM events
             WHERE public_id = ? OR CAST(id AS CHAR) = ?
             LIMIT 1 FOR UPDATE"
        );
        $eventStmt->execute([$eventRef, $eventRef]);
        $event = $eventStmt->fetch();
        if (!$event || $event['status'] !== 'completed') {
            throw new InvalidArgumentException('Reconnect opens after the event is completed.');
        }

        coveted_reconnect_assert_attendee_locked($pdo, (int)$event['id'], (int)$actor['id']);

        $stmt = $pdo->prepare(
            "UPDATE reconnect_requests
             SET status = 'cancelled', matched_at = NULL
             WHERE event_id = ?
               AND requester_user_id = ?
               AND target_user_id = ?
               AND status = 'pending'"
        );
        $stmt->execute([(int)$event['id'], (int)$actor['id'], $targetUserId]);
        $cancelled = $stmt->rowCount() === 1;

        if ($cancelled) {
            coveted_audit(
                'reconnect.cancelled',
                'event',
                (string)$event['public_id'],
                [],
                (int)$actor['id']
            );
        }
        $pdo->commit();

        return $cancelled;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return array<int,array<string,mixed>> */
function coveted_reconnect_matches_for_user(array $actor, int $limit = 100): array
{
    $limit = max(1, min($limit, 200));
    $actorId = (int)$actor['id'];
    $stmt = coveted_db()->prepare(
        "SELECT rr.event_id, rr.matched_at,
                e.public_id AS event_public_id, e.title AS event_title,
                CASE WHEN rr.requester_user_id = ? THEN rr.target_user_id ELSE rr.requester_user_id END AS matched_user_id,
                u.display_name AS matched_display_name, p.avatar_url AS matched_avatar_url
         FROM reconnect_requests rr
         JOIN events e ON e.id = rr.event_id AND e.status = 'completed'
         JOIN event_attendance mine
           ON mine.event_id = rr.event_id
          AND mine.user_id = ?
          AND mine.status IN ('checked_in','attended','left_early')
         JOIN users u
           ON u.id = CASE WHEN rr.requester_user_id = ? THEN rr.target_user_id ELSE rr.requester_user_id END
          AND u.status = 'active'
         JOIN event_attendance theirs
           ON theirs.event_id = rr.event_id
          AND theirs.user_id = u.id
          AND theirs.status IN ('checked_in','attended','left_early')
         LEFT JOIN profiles p ON p.user_id = u.id
         WHERE rr.status = 'mutual'
           AND (rr.requester_user_id = ? OR rr.target_user_id = ?)
           AND rr.requester_user_id < rr.target_user_id
         ORDER BY rr.matched_at DESC, rr.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$actorId, $actorId, $actorId, $actorId, $actorId]);
    return $stmt->fetchAll();
}
