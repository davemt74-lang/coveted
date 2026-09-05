<?php
declare(strict_types=1);

require_once __DIR__ . '/rewards.php';

function coveted_campaign_by_ref(string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '' || strlen($ref) > 64) {
        return null;
    }

    $stmt = coveted_db()->prepare(
        'SELECT * FROM campaigns WHERE public_id = ? OR CAST(id AS CHAR) = ? LIMIT 1'
    );
    $stmt->execute([$ref, $ref]);
    $campaign = $stmt->fetch();
    return $campaign ?: null;
}

function coveted_campaign_owner_id(array $campaign): int
{
    return match ((string)$campaign['owner_type']) {
        'group' => (int)$campaign['group_id'],
        'business' => (int)$campaign['business_id'],
        'artist' => (int)$campaign['artist_id'],
        default => 0,
    };
}

function coveted_campaigns_for_owner(string $ownerType, int $ownerId): array
{
    coveted_reward_owner_columns($ownerType, $ownerId);

    $column = match ($ownerType) {
        'group' => 'c.group_id',
        'business' => 'c.business_id',
        'artist' => 'c.artist_id',
        default => null,
    };

    $sql = "SELECT c.*, rt.title AS reward_title, rt.reward_type, rt.claim_mode, l.name AS location_name
            FROM campaigns c
            JOIN reward_templates rt ON rt.id = c.reward_template_id
            LEFT JOIN locations l ON l.id = c.location_id";
    $params = [];

    if ($column === null) {
        $sql .= " WHERE c.owner_type = 'platform'";
    } else {
        $sql .= " WHERE c.owner_type = ? AND {$column} = ?";
        $params = [$ownerType, $ownerId];
    }

    $sql .= ' ORDER BY c.created_at DESC, c.id DESC';
    $stmt = coveted_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function coveted_campaign_assert_activatable(array $campaign, array $template): void
{
    $ownerId = coveted_campaign_owner_id($campaign);
    if (coveted_reward_owner_status((string)$campaign['owner_type'], $ownerId) !== 'active') {
        throw new InvalidArgumentException('Only an active owner can run an active campaign.');
    }
    if (($template['status'] ?? '') !== 'active') {
        throw new InvalidArgumentException('Activate the campaign reward before activating the campaign.');
    }

    if (!empty($campaign['location_id'])) {
        $stmt = coveted_db()->prepare(
            "SELECT l.status, b.status AS business_status
             FROM locations l
             JOIN businesses b ON b.id = l.business_id
             WHERE l.id = ? AND l.business_id = ? LIMIT 1"
        );
        $stmt->execute([(int)$campaign['location_id'], (int)$campaign['business_id']]);
        $location = $stmt->fetch();
        if (!$location || $location['status'] !== 'active' || $location['business_status'] !== 'active') {
            throw new InvalidArgumentException('Campaign location must be active.');
        }
    }
}

function coveted_campaign_create(array $actor, array $data): array
{
    $ownerType = strtolower(trim((string)($data['owner_type'] ?? '')));
    $ownerId = (int)($data['owner_id'] ?? 0);
    $owner = coveted_reward_owner_columns($ownerType, $ownerId);

    if (!coveted_reward_actor_can_manage_owner($actor, $ownerType, $ownerId)) {
        throw new InvalidArgumentException('You cannot create campaigns for that owner.');
    }

    $templateRef = trim((string)($data['reward_template'] ?? ''));
    $template = coveted_reward_template_by_ref($templateRef);
    if (!$template) {
        throw new InvalidArgumentException('Reward template not found.');
    }

    $templateOwnerId = coveted_reward_template_owner_id($template);
    if ($template['owner_type'] !== $ownerType || $templateOwnerId !== $ownerId) {
        throw new InvalidArgumentException('Campaign and reward must have the same owner.');
    }

    $title = trim((string)($data['title'] ?? ''));
    $campaignType = strtolower(trim((string)($data['campaign_type'] ?? 'manual')));
    $triggerKey = strtolower(trim((string)($data['trigger_key'] ?? $campaignType)));
    $status = strtolower(trim((string)($data['status'] ?? 'draft')));
    $startsAt = trim((string)($data['starts_at'] ?? '')) ?: null;
    $endsAt = trim((string)($data['ends_at'] ?? '')) ?: null;
    $quantityLimit = $data['quantity_limit'] ?? null;
    $perUserLimit = $data['per_user_limit'] ?? 1;
    $locationId = isset($data['location_id']) && (int)$data['location_id'] > 0
        ? (int)$data['location_id']
        : null;

    $allowedTypes = [
        'attendance', 'event_completion', 'return_visit', 'guest_return',
        'random_reward', 'mystery_unlock', 'membership', 'birthday', 'manual', 'custom',
    ];
    $allowedTriggers = [
        'attendance', 'completion', 'return_visit', 'guest_return',
        'random_reward', 'mystery_unlock', 'membership', 'birthday', 'manual',
    ];

    if ($title === '' || mb_strlen($title) > 190) {
        throw new InvalidArgumentException('Enter a campaign title.');
    }
    if (!in_array($campaignType, $allowedTypes, true) || !in_array($triggerKey, $allowedTriggers, true)) {
        throw new InvalidArgumentException('Invalid campaign type or trigger.');
    }
    if (!in_array($status, ['draft', 'active', 'paused', 'archived'], true)) {
        throw new InvalidArgumentException('Invalid campaign status.');
    }
    if ($quantityLimit !== null && (!is_numeric($quantityLimit) || (int)$quantityLimit < 1)) {
        throw new InvalidArgumentException('Invalid campaign quantity limit.');
    }
    if ($perUserLimit !== null && (!is_numeric($perUserLimit) || (int)$perUserLimit < 1)) {
        throw new InvalidArgumentException('Invalid member campaign limit.');
    }
    if ($startsAt !== null) {
        $startsAt = coveted_utc_datetime($startsAt)->format('Y-m-d H:i:s');
    }
    if ($endsAt !== null) {
        $endsAt = coveted_utc_datetime($endsAt)->format('Y-m-d H:i:s');
    }
    if ($startsAt !== null && $endsAt !== null && strtotime($endsAt) <= strtotime($startsAt)) {
        throw new InvalidArgumentException('Campaign end time must be after its start time.');
    }

    if ($locationId !== null) {
        $location = coveted_db()->prepare(
            "SELECT l.business_id, l.status, b.status AS business_status
             FROM locations l
             JOIN businesses b ON b.id = l.business_id
             WHERE l.id = ? AND l.status <> 'archived'
             LIMIT 1"
        );
        $location->execute([$locationId]);
        $locationRow = $location->fetch();
        if (!$locationRow) {
            throw new InvalidArgumentException('Campaign location not found.');
        }
        if ($ownerType !== 'business' || (int)$locationRow['business_id'] !== $ownerId) {
            throw new InvalidArgumentException('A campaign location must belong to the campaign business.');
        }
        if ($status === 'active' && ($locationRow['status'] !== 'active' || $locationRow['business_status'] !== 'active')) {
            throw new InvalidArgumentException('Campaign location must be active.');
        }
    }

    if ($template['claim_mode'] === 'location_code' && $ownerType !== 'business') {
        throw new InvalidArgumentException('Location-code rewards require a business campaign.');
    }

    $campaignShape = [
        'owner_type' => $ownerType,
        'group_id' => $owner['group_id'],
        'business_id' => $owner['business_id'],
        'artist_id' => $owner['artist_id'],
        'location_id' => $locationId,
    ];
    if ($status === 'active') {
        coveted_campaign_assert_activatable($campaignShape, $template);
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $publicId = coveted_uuid('cmp');
        $pdo->prepare(
            "INSERT INTO campaigns
                (public_id, owner_type, group_id, business_id, artist_id, created_by,
                 reward_template_id, location_id, title, campaign_type, trigger_key,
                 quantity_limit, per_user_limit, starts_at, ends_at, status, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $publicId,
            $ownerType,
            $owner['group_id'],
            $owner['business_id'],
            $owner['artist_id'],
            (int)$actor['id'],
            (int)$template['id'],
            $locationId,
            $title,
            $campaignType,
            $triggerKey,
            $quantityLimit !== null ? (int)$quantityLimit : null,
            $perUserLimit !== null ? (int)$perUserLimit : null,
            $startsAt,
            $endsAt,
            $status,
            !empty($data['metadata']) && is_array($data['metadata']) ? coveted_json($data['metadata']) : null,
        ]);
        $campaignId = (int)$pdo->lastInsertId();

        coveted_audit(
            'campaign.created',
            'campaign',
            $publicId,
            ['owner_type' => $ownerType, 'trigger_key' => $triggerKey],
            (int)$actor['id']
        );
        $pdo->commit();
        return ['id' => $campaignId, 'public_id' => $publicId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_campaign_set_status(array $actor, string $campaignRef, string $status): void
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['draft', 'active', 'paused', 'archived'], true)) {
        throw new InvalidArgumentException('Invalid campaign status.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT c.*, rt.status AS reward_status, rt.owner_type AS reward_owner_type,
                    rt.group_id AS reward_group_id, rt.business_id AS reward_business_id,
                    rt.artist_id AS reward_artist_id
             FROM campaigns c
             JOIN reward_templates rt ON rt.id = c.reward_template_id
             WHERE c.public_id = ? OR CAST(c.id AS CHAR) = ?
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$campaignRef, $campaignRef]);
        $campaign = $stmt->fetch();
        if (!$campaign) {
            throw new InvalidArgumentException('Campaign not found.');
        }

        $ownerId = coveted_campaign_owner_id($campaign);
        if (!coveted_reward_actor_can_manage_owner($actor, (string)$campaign['owner_type'], $ownerId)) {
            throw new InvalidArgumentException('You cannot manage this campaign.');
        }
        if ($status === 'active') {
            coveted_campaign_assert_activatable($campaign, ['status' => $campaign['reward_status']]);
        }

        $pdo->prepare('UPDATE campaigns SET status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$status, (int)$campaign['id']]);
        coveted_audit(
            'campaign.status_changed',
            'campaign',
            (string)$campaign['public_id'],
            ['status' => $status],
            (int)$actor['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_campaign_link_event(array $actor, string $campaignRef, int $eventId): void
{
    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $campaignStmt = $pdo->prepare(
            'SELECT * FROM campaigns WHERE public_id = ? OR CAST(id AS CHAR) = ? LIMIT 1 FOR UPDATE'
        );
        $campaignStmt->execute([$campaignRef, $campaignRef]);
        $campaign = $campaignStmt->fetch();
        if (!$campaign) {
            throw new InvalidArgumentException('Campaign not found.');
        }

        $ownerId = coveted_campaign_owner_id($campaign);
        if (!coveted_reward_actor_can_manage_owner($actor, (string)$campaign['owner_type'], $ownerId)) {
            throw new InvalidArgumentException('You cannot manage this campaign.');
        }

        $eventStmt = $pdo->prepare(
            "SELECT e.group_id, e.status, el.location_id, l.business_id AS event_business_id
             FROM events e
             LEFT JOIN event_locations el ON el.event_id = e.id
             LEFT JOIN locations l ON l.id = el.location_id
             WHERE e.id = ?
             LIMIT 1 FOR UPDATE"
        );
        $eventStmt->execute([$eventId]);
        $event = $eventStmt->fetch();
        if (!$event) {
            throw new InvalidArgumentException('Event not found.');
        }
        if ($event['status'] === 'cancelled') {
            throw new InvalidArgumentException('Campaigns cannot be linked to a cancelled event.');
        }

        if ($campaign['owner_type'] === 'group') {
            if ((int)$campaign['group_id'] !== (int)$event['group_id']) {
                throw new InvalidArgumentException('Group campaign can only be linked to its own events.');
            }
        } elseif ($campaign['owner_type'] === 'business') {
            if ($event['event_business_id'] === null || (int)$campaign['business_id'] !== (int)$event['event_business_id']) {
                throw new InvalidArgumentException('Business campaign can only be linked to an event at that business.');
            }
            if ($campaign['location_id'] !== null && (int)$campaign['location_id'] !== (int)$event['location_id']) {
                throw new InvalidArgumentException('Location-specific campaign must be linked to that location.');
            }
        } elseif ($campaign['owner_type'] === 'artist') {
            $artist = $pdo->prepare(
                'SELECT 1 FROM event_artists WHERE event_id = ? AND artist_id = ? LIMIT 1'
            );
            $artist->execute([$eventId, (int)$campaign['artist_id']]);
            if (!$artist->fetchColumn()) {
                throw new InvalidArgumentException('Artist campaign can only be linked to an event featuring that artist.');
            }
        }

        $pdo->prepare('INSERT IGNORE INTO campaign_event_links (campaign_id, event_id) VALUES (?, ?)')
            ->execute([(int)$campaign['id'], $eventId]);
        coveted_audit(
            'campaign.event_linked',
            'campaign',
            (string)$campaign['public_id'],
            ['event_id' => $eventId],
            (int)$actor['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_campaign_assert_event_trigger_eligible(int $eventId, string $triggerKey, int $userId): void
{
    $stmt = coveted_db()->prepare(
        "SELECT e.status,
                ea.status AS attendance_status,
                er.response AS rsvp_response,
                ei.status AS invitation_status
         FROM events e
         LEFT JOIN event_attendance ea ON ea.event_id = e.id AND ea.user_id = ?
         LEFT JOIN event_rsvps er ON er.event_id = e.id AND er.user_id = ?
         LEFT JOIN event_invitations ei ON ei.event_id = e.id AND ei.user_id = ?
         WHERE e.id = ?
         LIMIT 1"
    );
    $stmt->execute([$userId, $userId, $userId, $eventId]);
    $state = $stmt->fetch();
    if (!$state) {
        throw new InvalidArgumentException('Event not found.');
    }
    if (in_array((string)$state['status'], ['draft', 'cancelled'], true)) {
        throw new InvalidArgumentException('Rewards cannot be distributed from this event state.');
    }

    if ($triggerKey === 'attendance') {
        if (!in_array((string)$state['attendance_status'], ['checked_in', 'attended', 'left_early'], true)) {
            throw new InvalidArgumentException('Attendance must be verified before issuing this reward.');
        }
        return;
    }

    if ($triggerKey === 'completion') {
        if ($state['status'] !== 'completed'
            || !in_array((string)$state['attendance_status'], ['attended', 'left_early'], true)) {
            throw new InvalidArgumentException('Completed-event attendance is required for this reward.');
        }
        return;
    }

    $participates = in_array((string)$state['attendance_status'], ['checked_in', 'attended', 'left_early'], true)
        || $state['rsvp_response'] === 'attending'
        || $state['invitation_status'] === 'accepted';

    if (!$participates) {
        throw new InvalidArgumentException('Member is not eligible for this event campaign.');
    }
}