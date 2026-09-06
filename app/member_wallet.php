<?php
declare(strict_types=1);

require_once __DIR__ . '/rewards.php';

/**
 * Read-only member wallet snapshot derived from canonical reward/campaign state.
 * No issuance or claim mutation happens while rendering the wallet.
 *
 * @return array<string,mixed>
 */
function coveted_member_wallet_snapshot(int $userId): array
{
    if ($userId < 1) {
        throw new InvalidArgumentException('Member is required.');
    }

    $pdo = coveted_db();

    $ready = coveted_reward_list_for_user($userId, [], 'inbox');
    $redeemed = coveted_reward_list_for_user($userId, [], 'claimed');

    $expiredStmt = $pdo->prepare(
        "SELECT
            ri.*,
            rt.title,
            rt.description,
            rt.reward_type,
            rt.claim_mode,
            rt.value_amount,
            rt.value_text,
            rt.cover_url,
            rt.owner_type,
            rt.group_id,
            rt.business_id,
            rt.artist_id AS template_artist_id,
            c.title AS campaign_title,
            c.campaign_type,
            c.trigger_key,
            e.title AS event_title,
            g.name AS group_name,
            b.name AS business_name,
            ap.artist_name,
            il.name AS location_name
         FROM reward_issuances ri
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         JOIN campaigns c ON c.id = ri.campaign_id
         LEFT JOIN events e ON e.id = ri.event_id
         LEFT JOIN social_groups g ON g.id = COALESCE(c.group_id, rt.group_id)
         LEFT JOIN businesses b ON b.id = COALESCE(c.business_id, rt.business_id)
         LEFT JOIN artist_profiles ap ON ap.id = COALESCE(ri.artist_id, c.artist_id, rt.artist_id)
         LEFT JOIN locations il ON il.id = ri.location_id
         WHERE ri.user_id = ?
           AND ri.status <> 'cancelled'
           AND ri.status <> 'claimed'
           AND (ri.status = 'expired' OR (ri.expires_at IS NOT NULL AND ri.expires_at <= NOW()))
         ORDER BY COALESCE(ri.expires_at, ri.updated_at) DESC, ri.id DESC
         LIMIT 100"
    );
    $expiredStmt->execute([$userId]);
    $expired = $expiredStmt->fetchAll();

    $upcomingStmt = $pdo->prepare(
        "SELECT
            c.public_id AS campaign_public_id,
            c.title AS campaign_title,
            c.starts_at AS campaign_starts_at,
            c.ends_at AS campaign_ends_at,
            c.quantity_limit,
            c.per_user_limit,
            rt.public_id AS reward_public_id,
            rt.title,
            rt.description,
            rt.reward_type,
            rt.value_amount,
            rt.value_text,
            rt.cover_url,
            rt.starts_at AS reward_starts_at,
            rt.expires_at,
            g.public_id AS group_public_id,
            g.name AS group_name,
            GREATEST(
                COALESCE(c.starts_at, '1970-01-01 00:00:00'),
                COALESCE(rt.starts_at, '1970-01-01 00:00:00')
            ) AS available_at
         FROM group_memberships gm
         JOIN social_groups g ON g.id = gm.group_id AND g.status = 'active'
         JOIN campaigns c ON c.group_id = g.id
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         WHERE gm.user_id = ?
           AND gm.membership_status = 'active'
           AND c.owner_type = 'group'
           AND c.trigger_key = 'membership'
           AND c.status = 'active'
           AND rt.status = 'active'
           AND (
                (c.starts_at IS NOT NULL AND c.starts_at > NOW())
                OR (rt.starts_at IS NOT NULL AND rt.starts_at > NOW())
           )
           AND (c.ends_at IS NULL OR c.ends_at > NOW())
           AND (rt.expires_at IS NULL OR rt.expires_at > NOW())
           AND NOT EXISTS (
               SELECT 1 FROM reward_issuances ri
               WHERE ri.campaign_id = c.id
                 AND ri.user_id = gm.user_id
                 AND ri.status <> 'cancelled'
           )
         ORDER BY available_at ASC, c.id ASC
         LIMIT 50"
    );
    $upcomingStmt->execute([$userId]);
    $upcoming = $upcomingStmt->fetchAll();

    $readyIds = array_values(array_filter(array_map(
        static fn(array $row): int => (int)($row['reward_template_id'] ?? 0),
        $ready
    )));
    $mediaByTemplate = coveted_reward_media_for_templates($readyIds);
    $eligibleLocations = coveted_reward_eligible_locations_for_rows($ready);

    $expiringSoon = 0;
    $returnReady = 0;
    $mediaReady = 0;
    $groupReady = 0;
    $businessReady = 0;
    $artistReady = 0;
    $eventReady = 0;

    foreach ($ready as $reward) {
        if (!empty($reward['expires_at']) && strtotime((string)$reward['expires_at']) <= strtotime('+7 days')) {
            $expiringSoon++;
        }
        if (in_array((string)($reward['trigger_key'] ?? ''), ['return_visit', 'guest_return'], true)) {
            $returnReady++;
        }
        if (!empty($mediaByTemplate[(int)$reward['reward_template_id']])) {
            $mediaReady++;
        }
        if ((string)($reward['owner_type'] ?? '') === 'group') {
            $groupReady++;
        }
        if ((string)($reward['owner_type'] ?? '') === 'business') {
            $businessReady++;
        }
        if ((string)($reward['owner_type'] ?? '') === 'artist') {
            $artistReady++;
        }
        if (!empty($reward['event_id'])) {
            $eventReady++;
        }
    }

    return [
        'ready' => $ready,
        'redeemed' => $redeemed,
        'expired' => $expired,
        'upcoming' => $upcoming,
        'media_by_template' => $mediaByTemplate,
        'eligible_locations' => $eligibleLocations,
        'summary' => [
            'ready' => count($ready),
            'redeemed' => count($redeemed),
            'expired' => count($expired),
            'upcoming' => count($upcoming),
            'expiring_soon' => $expiringSoon,
            'return_ready' => $returnReady,
            'media_ready' => $mediaReady,
            'group_ready' => $groupReady,
            'business_ready' => $businessReady,
            'artist_ready' => $artistReady,
            'event_ready' => $eventReady,
            'claimable' => count($eligibleLocations),
        ],
    ];
}

function coveted_member_wallet_source_label(array $reward): string
{
    if (!empty($reward['event_title'])) {
        return 'Event · ' . (string)$reward['event_title'];
    }
    if (!empty($reward['group_name'])) {
        return 'Group · ' . (string)$reward['group_name'];
    }
    if (!empty($reward['business_name'])) {
        return 'Business · ' . (string)$reward['business_name'];
    }
    if (!empty($reward['artist_name'])) {
        return 'Artist · ' . (string)$reward['artist_name'];
    }

    return 'Coveted';
}
