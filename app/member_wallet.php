<?php
declare(strict_types=1);

require_once __DIR__ . '/rewards.php';

/** @return array<int,array<string,mixed>> */
function coveted_member_wallet_rewards(int $userId, string $state): array
{
    if ($userId < 1) {
        throw new InvalidArgumentException('Member is required.');
    }
    if (!in_array($state, ['ready', 'redeemed', 'expired'], true)) {
        throw new InvalidArgumentException('Invalid wallet state.');
    }

    $where = match ($state) {
        'ready' => "ri.status IN ('issued','viewed') AND (ri.expires_at IS NULL OR ri.expires_at > NOW())",
        'redeemed' => "rc.id IS NOT NULL",
        'expired' => "ri.status <> 'cancelled' AND ri.status <> 'claimed' AND (ri.status = 'expired' OR (ri.expires_at IS NOT NULL AND ri.expires_at <= NOW()))",
    };
    $claimJoin = $state === 'redeemed'
        ? 'JOIN reward_claims rc ON rc.reward_issuance_id = ri.id'
        : 'LEFT JOIN reward_claims rc ON 1 = 0';

    $sql = "SELECT
                ri.*,
                rt.title,
                rt.description,
                rt.reward_type,
                rt.claim_mode,
                rt.value_amount,
                rt.value_text,
                rt.cover_url,
                rt.owner_type,
                rt.group_id AS template_group_id,
                rt.business_id AS template_business_id,
                rt.artist_id AS template_artist_id,
                c.public_id AS campaign_public_id,
                c.title AS campaign_title,
                c.campaign_type,
                c.trigger_key,
                c.group_id AS campaign_group_id,
                c.business_id AS campaign_business_id,
                c.artist_id AS campaign_artist_id,
                e.public_id AS event_public_id,
                e.title AS event_title,
                g.public_id AS group_public_id,
                g.name AS group_name,
                b.public_id AS business_public_id,
                b.name AS business_name,
                ap.public_id AS artist_public_id,
                ap.artist_name,
                il.public_id AS location_public_id,
                il.name AS location_name,
                rc.public_id AS claim_public_id,
                rc.status AS claim_status,
                rc.claimed_at AS claim_recorded_at,
                rc.refunded_at AS claim_refunded_at,
                rc.refund_reason AS claim_refund_reason,
                rc.claim_code_type,
                rc.claim_code_label
            FROM reward_issuances ri
            JOIN reward_templates rt ON rt.id = ri.reward_template_id
            JOIN campaigns c ON c.id = ri.campaign_id
            LEFT JOIN events e ON e.id = ri.event_id
            LEFT JOIN social_groups g ON g.id = COALESCE(c.group_id, rt.group_id, e.group_id)
            {$claimJoin}
            LEFT JOIN locations il ON il.id = COALESCE(rc.location_id, ri.location_id)
            LEFT JOIN businesses b ON b.id = COALESCE(c.business_id, rt.business_id, il.business_id)
            LEFT JOIN artist_profiles ap ON ap.id = COALESCE(ri.artist_id, c.artist_id, rt.artist_id)
            WHERE ri.user_id = ?
              AND {$where}
            ORDER BY " . ($state === 'redeemed' ? 'rc.claimed_at' : 'ri.issued_at') . " DESC, ri.id DESC
            LIMIT 150";

    $stmt = coveted_db()->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_member_wallet_upcoming(int $userId): array
{
    $stmt = coveted_db()->prepare(
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
         LIMIT 75"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/** @return array<string,mixed> */
function coveted_member_wallet_snapshot(int $userId): array
{
    $ready = coveted_member_wallet_rewards($userId, 'ready');
    $redeemed = coveted_member_wallet_rewards($userId, 'redeemed');
    $expired = coveted_member_wallet_rewards($userId, 'expired');
    $upcoming = coveted_member_wallet_upcoming($userId);

    $mediaByTemplate = coveted_reward_media_for_templates(array_values(array_filter(array_map(
        static fn(array $row): int => (int)($row['reward_template_id'] ?? 0),
        $ready
    ))));
    $eligibleLocations = coveted_reward_eligible_locations_for_rows($ready);

    $summary = [
        'ready' => count($ready),
        'redeemed' => count($redeemed),
        'expired' => count($expired),
        'upcoming' => count($upcoming),
        'expiring_soon' => 0,
        'return_ready' => 0,
        'media_ready' => 0,
        'group_ready' => 0,
        'business_ready' => 0,
        'artist_ready' => 0,
        'event_ready' => 0,
        'claimable' => count($eligibleLocations),
    ];

    $sevenDays = strtotime('+7 days');
    foreach ($ready as $reward) {
        if (!empty($reward['expires_at']) && strtotime((string)$reward['expires_at']) <= $sevenDays) {
            $summary['expiring_soon']++;
        }
        if (in_array((string)$reward['trigger_key'], ['return_visit', 'guest_return'], true)) {
            $summary['return_ready']++;
        }
        if (!empty($mediaByTemplate[(int)$reward['reward_template_id']])) {
            $summary['media_ready']++;
        }
        if ((string)$reward['owner_type'] === 'group') {
            $summary['group_ready']++;
        }
        if ((string)$reward['owner_type'] === 'business') {
            $summary['business_ready']++;
        }
        if ((string)$reward['owner_type'] === 'artist') {
            $summary['artist_ready']++;
        }
        if (!empty($reward['event_id'])) {
            $summary['event_ready']++;
        }
    }

    return [
        'ready' => $ready,
        'redeemed' => $redeemed,
        'expired' => $expired,
        'upcoming' => $upcoming,
        'media_by_template' => $mediaByTemplate,
        'eligible_locations' => $eligibleLocations,
        'summary' => $summary,
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
