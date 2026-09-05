<?php
declare(strict_types=1);

require_once __DIR__ . '/rewards.php';

/**
 * Resolve one video item through the member's canonical reward entitlement.
 * Raw media URLs are returned only after issuance ownership and availability
 * have been verified.
 *
 * @return array<string,mixed>
 */
function coveted_member_video_context(array $actor, string $issuanceRef, int $sortOrder): array
{
    $issuanceRef = trim($issuanceRef);
    $actorId = (int)($actor['id'] ?? 0);
    if ($actorId < 1 || $issuanceRef === '' || strlen($issuanceRef) > 64 || $sortOrder < 0 || $sortOrder > 100) {
        throw new InvalidArgumentException('That video is not available.');
    }

    $stmt = coveted_db()->prepare(
        "SELECT
            ri.id AS issuance_id,
            ri.public_id AS issuance_public_id,
            ri.status AS issuance_status,
            ri.expires_at AS issuance_expires_at,
            ri.event_id,
            rt.title AS reward_title,
            rt.description AS reward_description,
            rt.cover_url,
            rm.id AS media_id,
            rm.title AS media_title,
            rm.media_url,
            rm.mime_type,
            rm.duration_seconds,
            rm.sort_order,
            c.title AS campaign_title,
            e.public_id AS event_public_id,
            e.title AS event_title,
            ap.public_id AS artist_public_id,
            ap.artist_name
         FROM reward_issuances ri
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         JOIN campaigns c ON c.id = ri.campaign_id
         JOIN reward_media rm
           ON rm.reward_template_id = ri.reward_template_id
          AND rm.media_type = 'video'
          AND rm.sort_order = ?
         LEFT JOIN events e ON e.id = ri.event_id
         LEFT JOIN artist_profiles ap ON ap.id = COALESCE(ri.artist_id, rt.artist_id)
         WHERE ri.public_id = ?
           AND ri.user_id = ?
         ORDER BY rm.id ASC
         LIMIT 1"
    );
    $stmt->execute([$sortOrder, $issuanceRef, $actorId]);
    $video = $stmt->fetch();

    if (!$video || in_array((string)$video['issuance_status'], ['expired', 'cancelled'], true)) {
        throw new InvalidArgumentException('That video is not available.');
    }
    if (!empty($video['issuance_expires_at']) && strtotime((string)$video['issuance_expires_at']) <= time()) {
        throw new InvalidArgumentException('That video is no longer available.');
    }

    $mediaUrl = coveted_safe_url($video['media_url'] ?? null, false);
    if ($mediaUrl === null) {
        throw new InvalidArgumentException('That video is not available.');
    }

    $coverUrl = coveted_safe_url($video['cover_url'] ?? null, false);
    $mimeType = strtolower(trim((string)($video['mime_type'] ?? '')));
    if ($mimeType !== '' && !str_starts_with($mimeType, 'video/')) {
        $mimeType = '';
    }

    $video['media_url'] = $mediaUrl;
    $video['cover_url'] = $coverUrl;
    $video['mime_type'] = $mimeType;
    $video['duration_seconds'] = $video['duration_seconds'] !== null ? (int)$video['duration_seconds'] : null;
    $video['sort_order'] = (int)$video['sort_order'];

    return $video;
}

/**
 * Opening the Coveted viewer is the canonical media-use signal. The reward
 * service owns the mutation and already makes repeated calls idempotent.
 */
function coveted_member_video_mark_viewed(array $actor, string $issuanceRef): void
{
    $actorId = (int)($actor['id'] ?? 0);
    if ($actorId < 1) {
        throw new InvalidArgumentException('Member account is required.');
    }

    coveted_reward_mark_viewed($issuanceRef, $actorId);
}
