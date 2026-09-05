<?php
declare(strict_types=1);

require_once __DIR__ . '/events.php';

/**
 * Return the latest due reveal for the member's next accepted mystery gathering.
 *
 * This is a read model only. It mirrors the same reveal-time rule used by the
 * event experience and never fetches unrevealed content or skips ahead to a
 * later mystery gathering just because that later event has a due reveal.
 *
 * @return array<string,mixed>|null
 */
function coveted_member_home_mystery_reveal(int $userId): ?array
{
    if ($userId < 1) {
        return null;
    }

    $pdo = coveted_db();
    $eventStmt = $pdo->prepare(
        "SELECT e.id, e.public_id, e.title, e.starts_at, e.timezone
         FROM event_rsvps er
         JOIN events e ON e.id = er.event_id
         WHERE er.user_id = ?
           AND er.response = 'attending'
           AND e.event_type = 'mystery'
           AND e.status IN ('published','closed')
           AND e.starts_at >= NOW()
         ORDER BY e.starts_at ASC, e.id ASC
         LIMIT 1"
    );
    $eventStmt->execute([$userId]);
    $event = $eventStmt->fetch();
    if (!$event) {
        return null;
    }

    $revealStmt = $pdo->prepare(
        "SELECT reveal_type, title, content, reveal_at
         FROM event_mystery_reveals
         WHERE event_id = ?
           AND reveal_at <= NOW()
         ORDER BY reveal_at DESC, id DESC
         LIMIT 1"
    );
    $revealStmt->execute([(int)$event['id']]);
    $reveal = $revealStmt->fetch();
    if (!$reveal) {
        return null;
    }

    return [
        ...$reveal,
        'event_public_id' => (string)$event['public_id'],
        'event_title' => (string)$event['title'],
        'starts_at' => (string)$event['starts_at'],
        'timezone' => (string)$event['timezone'],
    ];
}
