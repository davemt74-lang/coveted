<?php
declare(strict_types=1);

require_once __DIR__ . '/campaigns.php';
require_once __DIR__ . '/venue_relationships.php';
require_once __DIR__ . '/notifications.php';

/** @return array<string,mixed> */
function coveted_return_claim_context(string $claimRef): array
{
    $claimRef = trim($claimRef);
    if ($claimRef === '' || strlen($claimRef) > 64) {
        throw new InvalidArgumentException('Return claim is unavailable.');
    }

    $stmt = coveted_db()->prepare(
        "SELECT
            rc.id AS claim_id,
            rc.public_id AS claim_public_id,
            rc.status AS claim_status,
            rc.claimed_at,
            rc.location_id AS return_location_id,
            ri.id AS reward_issuance_id,
            ri.public_id AS reward_issuance_public_id,
            ri.user_id,
            ri.event_id,
            ri.campaign_id AS source_campaign_id,
            source_campaign.trigger_key AS source_trigger_key,
            e.public_id AS event_public_id,
            e.group_id,
            e.status AS event_status,
            e.starts_at AS event_starts_at,
            e.ends_at AS event_ends_at,
            e.timezone AS event_timezone,
            el.location_id AS event_location_id,
            l.business_id,
            l.status AS location_status,
            b.status AS business_status,
            vr.id AS venue_relationship_id,
            vr.relationship_status,
            COALESCE(vr.benefits_enabled, 0) AS benefits_enabled,
            ea.status AS attendance_status,
            ei.invite_type,
            gm.group_role,
            gm.membership_status,
            EXISTS(
                SELECT 1
                FROM group_guest_passes ggp
                WHERE ggp.group_id = e.group_id
                  AND ggp.guest_user_id = ri.user_id
                  AND ggp.status = 'used'
            ) AS used_guest_pass
         FROM reward_claims rc
         JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
         JOIN campaigns source_campaign ON source_campaign.id = ri.campaign_id
         LEFT JOIN events e ON e.id = ri.event_id
         LEFT JOIN event_locations el ON el.event_id = e.id
         LEFT JOIN locations l ON l.id = rc.location_id
         LEFT JOIN businesses b ON b.id = l.business_id
         LEFT JOIN venue_relationships vr
           ON vr.group_id = e.group_id
          AND vr.location_id = rc.location_id
         LEFT JOIN event_attendance ea
           ON ea.event_id = e.id
          AND ea.user_id = ri.user_id
         LEFT JOIN event_invitations ei
           ON ei.event_id = e.id
          AND ei.user_id = ri.user_id
         LEFT JOIN group_memberships gm
           ON gm.group_id = e.group_id
          AND gm.user_id = ri.user_id
         WHERE rc.public_id = ? OR CAST(rc.id AS CHAR) = ?
         LIMIT 1"
    );
    $stmt->execute([$claimRef, $claimRef]);
    $context = $stmt->fetch();
    if (!$context) {
        throw new InvalidArgumentException('Return claim is unavailable.');
    }

    $context['eligible'] = false;
    $context['reason'] = 'not_return_visit';
    $context['guest_origin'] = in_array((string)($context['invite_type'] ?? ''), ['guest', 'plus_one'], true)
        || ((string)($context['membership_status'] ?? '') === 'active' && (string)($context['group_role'] ?? '') === 'guest')
        || (int)($context['used_guest_pass'] ?? 0) === 1;

    if ((string)$context['claim_status'] !== 'claimed') {
        $context['reason'] = 'claim_not_active';
        return $context;
    }
    if ($context['event_id'] === null || $context['event_location_id'] === null) {
        $context['reason'] = 'no_event_venue_origin';
        return $context;
    }
    if ((string)$context['event_status'] !== 'completed') {
        $context['reason'] = 'origin_event_not_completed';
        return $context;
    }
    if (!in_array((string)$context['attendance_status'], ['checked_in', 'attended', 'left_early'], true)) {
        $context['reason'] = 'origin_attendance_not_verified';
        return $context;
    }
    if ((int)$context['event_location_id'] !== (int)$context['return_location_id']) {
        $context['reason'] = 'different_venue';
        return $context;
    }
    if ((string)$context['location_status'] !== 'active' || (string)$context['business_status'] !== 'active') {
        $context['reason'] = 'venue_not_active';
        return $context;
    }
    if ($context['venue_relationship_id'] === null || (int)$context['benefits_enabled'] !== 1) {
        $context['reason'] = 'relationship_benefits_disabled';
        return $context;
    }

    try {
        $zone = new DateTimeZone(trim((string)$context['event_timezone']) ?: 'UTC');
    } catch (Throwable) {
        $zone = new DateTimeZone('UTC');
    }

    $eventBoundary = coveted_utc_datetime(
        (string)($context['event_ends_at'] ?: $context['event_starts_at'])
    )->setTimezone($zone);
    $claimedAt = coveted_utc_datetime((string)$context['claimed_at'])->setTimezone($zone);

    // A same-local-date redemption is part of the gathering, not a return visit.
    if ($claimedAt->format('Y-m-d') <= $eventBoundary->format('Y-m-d')) {
        $context['reason'] = 'same_local_date';
        return $context;
    }

    $context['eligible'] = true;
    $context['reason'] = 'eligible_return_visit';
    $context['event_local_date'] = $eventBoundary->format('Y-m-d');
    $context['return_local_date'] = $claimedAt->format('Y-m-d');

    return $context;
}

/** @return array<int,array<string,mixed>> */
function coveted_return_candidate_campaigns(array $context): array
{
    if (empty($context['eligible'])) {
        return [];
    }

    $stmt = coveted_db()->prepare(
        "SELECT
            c.*,
            rt.title AS reward_title,
            rt.reward_type,
            rt.claim_mode,
            rt.status AS reward_status
         FROM campaign_event_links cel
         JOIN campaigns c ON c.id = cel.campaign_id
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         WHERE cel.event_id = ?
           AND c.owner_type = 'business'
           AND c.business_id = ?
           AND c.trigger_key IN ('return_visit','guest_return')
           AND c.status = 'active'
           AND rt.status = 'active'
           AND (c.location_id IS NULL OR c.location_id = ?)
           AND (c.starts_at IS NULL OR c.starts_at <= NOW())
           AND (c.ends_at IS NULL OR c.ends_at > NOW())
           AND (rt.starts_at IS NULL OR rt.starts_at <= NOW())
           AND (rt.expires_at IS NULL OR rt.expires_at > NOW())
         ORDER BY FIELD(c.trigger_key, 'guest_return', 'return_visit'), c.created_at, c.id"
    );
    $stmt->execute([
        (int)$context['event_id'],
        (int)$context['business_id'],
        (int)$context['return_location_id'],
    ]);

    return $stmt->fetchAll();
}

function coveted_return_notify(array $context, array $campaign, array $issuance): void
{
    $issuanceRef = (string)($issuance['public_id'] ?? $issuance['id'] ?? '');
    if ($issuanceRef === '') {
        return;
    }

    $guest = (string)$campaign['trigger_key'] === 'guest_return';
    coveted_notification_create(
        (int)$context['user_id'],
        'reward.return_unlocked',
        $guest ? 'Your guest return unlocked something new.' : 'Your return visit unlocked something new.',
        (string)$campaign['reward_title'],
        '/benefits.php?box=inbox',
        [
            'reward_issuance_id' => $issuanceRef,
            'campaign_id' => (string)$campaign['public_id'],
            'origin_event_id' => (string)$context['event_public_id'],
            'return_location_id' => (int)$context['return_location_id'],
            'return_kind' => (string)$campaign['trigger_key'],
        ],
        'high',
        'return-reward:' . $issuanceRef
    );
}

function coveted_return_notify_safely(array $context, array $campaign, array $issuance): void
{
    try {
        coveted_return_notify($context, $campaign, $issuance);
    } catch (Throwable $e) {
        // Reward issuance is canonical truth. Delivery is a separate subsystem and
        // must never make a successfully issued return benefit look unsuccessful.
        error_log('Coveted return reward notification failed: ' . $e->getMessage());
    }
}

/** @return array<string,mixed> */
function coveted_return_process_claim(string $claimRef): array
{
    $context = coveted_return_claim_context($claimRef);
    $summary = [
        'claim_id' => (string)$context['claim_public_id'],
        'eligible' => (bool)$context['eligible'],
        'reason' => (string)$context['reason'],
        'guest_origin' => (bool)$context['guest_origin'],
        'candidate_count' => 0,
        'issued_count' => 0,
        'already_issued_count' => 0,
        'skipped_count' => 0,
        'errors' => [],
    ];

    if (empty($context['eligible'])) {
        return $summary;
    }

    $campaigns = coveted_return_candidate_campaigns($context);
    $summary['candidate_count'] = count($campaigns);

    foreach ($campaigns as $campaign) {
        $trigger = (string)$campaign['trigger_key'];
        if ($trigger === 'guest_return' && empty($context['guest_origin'])) {
            $summary['skipped_count']++;
            continue;
        }

        $idempotencyKey = implode(':', [
            'relationship-return',
            'claim', (int)$context['claim_id'],
            'campaign', (int)$campaign['id'],
            'user', (int)$context['user_id'],
        ]);

        $existing = coveted_reward_existing_idempotent(coveted_db(), $idempotencyKey);
        if ($existing) {
            coveted_return_notify_safely($context, $campaign, $existing);
            $summary['already_issued_count']++;
            continue;
        }

        try {
            $issuance = coveted_reward_issue(
                (int)$campaign['id'],
                (int)$context['user_id'],
                (int)$context['event_id'],
                [
                    'trigger_key' => $trigger,
                    'relationship_trigger' => 'venue_return',
                    'source_claim_id' => (string)$context['claim_public_id'],
                    'source_reward_issuance_id' => (string)$context['reward_issuance_public_id'],
                    'origin_event_id' => (string)$context['event_public_id'],
                    'origin_group_id' => (int)$context['group_id'],
                    'return_location_id' => (int)$context['return_location_id'],
                    'guest_origin' => (bool)$context['guest_origin'],
                    'event_local_date' => (string)$context['event_local_date'],
                    'return_local_date' => (string)$context['return_local_date'],
                ],
                $idempotencyKey
            );
            $summary['issued_count']++;
            coveted_return_notify_safely($context, $campaign, $issuance);
        } catch (InvalidArgumentException $e) {
            $summary['skipped_count']++;
            $summary['errors'][] = [
                'campaign_id' => (string)$campaign['public_id'],
                'reason' => $e->getMessage(),
            ];
        }
    }

    $summary['errors'] = array_slice($summary['errors'], 0, 20);

    if ((int)$summary['issued_count'] > 0) {
        coveted_audit(
            'relationship.return_triggered',
            'reward_claim',
            (string)$context['claim_public_id'],
            [
                'origin_event_id' => (string)$context['event_public_id'],
                'group_id' => (int)$context['group_id'],
                'location_id' => (int)$context['return_location_id'],
                'guest_origin' => (bool)$context['guest_origin'],
                'issued_count' => (int)$summary['issued_count'],
            ],
            (int)$context['user_id']
        );
    }

    return $summary;
}

/** @return array<int,array<string,mixed>> */
function coveted_return_programs_for_relationship(
    array $actor,
    int $businessId,
    string $groupRef,
    string $locationRef
): array {
    $relationship = coveted_venue_relationship_resolve($actor, $businessId, $groupRef, $locationRef);

    $stmt = coveted_db()->prepare(
        "SELECT
            c.*,
            rt.title AS reward_title,
            rt.reward_type,
            rt.status AS reward_status,
            l.name AS campaign_location_name,
            COUNT(DISTINCT CASE
                WHEN linked_event.group_id = ?
                 AND linked_event.status IN ('published','closed','completed')
                 AND linked_location.location_id = ?
                THEN linked_event.id END) AS linked_event_count,
            (
                SELECT COUNT(DISTINCT eligible_event.id)
                FROM events eligible_event
                JOIN event_locations eligible_location ON eligible_location.event_id = eligible_event.id
                WHERE eligible_event.group_id = ?
                  AND eligible_location.location_id = ?
                  AND eligible_event.status IN ('published','closed','completed')
            ) AS eligible_event_count
         FROM campaigns c
         JOIN reward_templates rt ON rt.id = c.reward_template_id
         LEFT JOIN locations l ON l.id = c.location_id
         LEFT JOIN campaign_event_links cel ON cel.campaign_id = c.id
         LEFT JOIN events linked_event ON linked_event.id = cel.event_id
         LEFT JOIN event_locations linked_location ON linked_location.event_id = linked_event.id
         WHERE c.owner_type = 'business'
           AND c.business_id = ?
           AND c.trigger_key IN ('return_visit','guest_return')
           AND (c.location_id IS NULL OR c.location_id = ?)
         GROUP BY
            c.id, c.public_id, c.owner_type, c.group_id, c.business_id, c.artist_id,
            c.created_by, c.reward_template_id, c.location_id, c.title, c.campaign_type,
            c.trigger_key, c.quantity_limit, c.per_user_limit, c.starts_at, c.ends_at,
            c.status, c.metadata_json, c.created_at, c.updated_at,
            rt.title, rt.reward_type, rt.status, l.name
         ORDER BY FIELD(c.trigger_key, 'return_visit', 'guest_return'), c.created_at DESC, c.id DESC"
    );
    $stmt->execute([
        (int)$relationship['group_id'],
        (int)$relationship['location_id'],
        (int)$relationship['group_id'],
        (int)$relationship['location_id'],
        $businessId,
        (int)$relationship['location_id'],
    ]);

    return $stmt->fetchAll();
}

/** @return array<string,int|string> */
function coveted_return_program_link_relationship_events(
    array $actor,
    int $businessId,
    string $groupRef,
    string $locationRef,
    string $campaignRef
): array {
    coveted_business_require_mutable($actor, $businessId);
    $relationship = coveted_venue_relationship_resolve($actor, $businessId, $groupRef, $locationRef);
    $campaign = coveted_campaign_by_ref($campaignRef);

    if (
        !$campaign
        || (string)$campaign['owner_type'] !== 'business'
        || (int)$campaign['business_id'] !== $businessId
        || !in_array((string)$campaign['trigger_key'], ['return_visit', 'guest_return'], true)
    ) {
        throw new InvalidArgumentException('Choose a return campaign owned by this business.');
    }
    if ($campaign['location_id'] !== null && (int)$campaign['location_id'] !== (int)$relationship['location_id']) {
        throw new InvalidArgumentException('That return campaign is scoped to a different location.');
    }

    $eventStmt = coveted_db()->prepare(
        "SELECT e.id
         FROM events e
         JOIN event_locations el ON el.event_id = e.id
         WHERE e.group_id = ?
           AND el.location_id = ?
           AND e.status IN ('published','closed','completed')
         ORDER BY e.starts_at, e.id"
    );
    $eventStmt->execute([(int)$relationship['group_id'], (int)$relationship['location_id']]);
    $eventIds = array_map('intval', array_column($eventStmt->fetchAll(), 'id'));
    if (!$eventIds) {
        throw new InvalidArgumentException('This relationship has no eligible Coveted events to link.');
    }

    $existingStmt = coveted_db()->prepare(
        'SELECT 1 FROM campaign_event_links WHERE campaign_id = ? AND event_id = ? LIMIT 1'
    );
    $linked = 0;
    $alreadyLinked = 0;

    foreach ($eventIds as $eventId) {
        $existingStmt->execute([(int)$campaign['id'], $eventId]);
        if ($existingStmt->fetchColumn()) {
            $alreadyLinked++;
            continue;
        }

        coveted_campaign_link_event($actor, (string)$campaign['public_id'], $eventId);
        $linked++;
    }

    if ($linked > 0) {
        coveted_audit(
            'relationship.return_program_linked',
            'campaign',
            (string)$campaign['public_id'],
            [
                'group_id' => (int)$relationship['group_id'],
                'location_id' => (int)$relationship['location_id'],
                'linked_events' => $linked,
            ],
            (int)$actor['id']
        );
    }

    return [
        'campaign_id' => (string)$campaign['public_id'],
        'linked_count' => $linked,
        'already_linked_count' => $alreadyLinked,
        'eligible_event_count' => count($eventIds),
    ];
}