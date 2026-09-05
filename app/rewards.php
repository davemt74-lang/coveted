<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/businesses.php';

function coveted_reward_owner_columns(string $ownerType, int $ownerId): array
{
    if (!in_array($ownerType, ['platform', 'group', 'business', 'artist'], true)) {
        throw new InvalidArgumentException('Invalid reward owner type.');
    }

    if ($ownerType !== 'platform' && $ownerId < 1) {
        throw new InvalidArgumentException('Reward owner is required.');
    }

    return [
        'group_id' => $ownerType === 'group' ? $ownerId : null,
        'business_id' => $ownerType === 'business' ? $ownerId : null,
        'artist_id' => $ownerType === 'artist' ? $ownerId : null,
    ];
}

function coveted_reward_actor_can_manage_owner(array $actor, string $ownerType, int $ownerId = 0): bool
{
    if (coveted_is_system_admin($actor)) {
        return true;
    }

    $pdo = coveted_db();
    $userId = (int)$actor['id'];

    if ($ownerType === 'group') {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM group_memberships
             WHERE group_id = ?
               AND user_id = ?
               AND membership_status = 'active'
               AND group_role = 'group_admin'
             LIMIT 1"
        );
        $stmt->execute([$ownerId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    if ($ownerType === 'business') {
        return coveted_business_actor_can_manage($actor, $ownerId);
    }

    if ($ownerType === 'artist') {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM artist_members
             WHERE artist_id = ?
               AND user_id = ?
               AND member_role IN ('owner','manager')
             LIMIT 1"
        );
        $stmt->execute([$ownerId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    return false;
}

function coveted_reward_owner_status(string $ownerType, int $ownerId): string
{
    if ($ownerType === 'platform') {
        return 'active';
    }

    [$table, $idColumn] = match ($ownerType) {
        'group' => ['social_groups', 'id'],
        'business' => ['businesses', 'id'],
        'artist' => ['artist_profiles', 'id'],
        default => throw new InvalidArgumentException('Invalid reward owner type.'),
    };

    $stmt = coveted_db()->prepare("SELECT status FROM {$table} WHERE {$idColumn} = ? LIMIT 1");
    $stmt->execute([$ownerId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        throw new InvalidArgumentException('Reward owner not found.');
    }

    return (string)$status;
}

function coveted_reward_template_owner_id(array $template): int
{
    return match ((string)$template['owner_type']) {
        'group' => (int)$template['group_id'],
        'business' => (int)$template['business_id'],
        'artist' => (int)$template['artist_id'],
        default => 0,
    };
}

function coveted_reward_template_by_ref(string $ref): ?array
{
    $stmt = coveted_db()->prepare(
        'SELECT * FROM reward_templates WHERE public_id = ? OR CAST(id AS CHAR) = ? LIMIT 1'
    );
    $stmt->execute([$ref, $ref]);
    $template = $stmt->fetch();

    return $template ?: null;
}

function coveted_reward_templates_for_owner(string $ownerType, int $ownerId): array
{
    $owner = coveted_reward_owner_columns($ownerType, $ownerId);
    $column = match ($ownerType) {
        'group' => 'group_id',
        'business' => 'business_id',
        'artist' => 'artist_id',
        default => null,
    };

    if ($column === null) {
        $stmt = coveted_db()->query(
            "SELECT * FROM reward_templates WHERE owner_type = 'platform' ORDER BY created_at DESC, id DESC"
        );
        return $stmt->fetchAll();
    }

    $stmt = coveted_db()->prepare(
        "SELECT * FROM reward_templates WHERE owner_type = ? AND {$column} = ? ORDER BY created_at DESC, id DESC"
    );
    $stmt->execute([$ownerType, $owner[$column]]);
    return $stmt->fetchAll();
}

function coveted_reward_create_template(array $actor, array $data): array
{
    $ownerType = strtolower(trim((string)($data['owner_type'] ?? '')));
    $ownerId = (int)($data['owner_id'] ?? 0);
    $owner = coveted_reward_owner_columns($ownerType, $ownerId);

    if (!coveted_reward_actor_can_manage_owner($actor, $ownerType, $ownerId)) {
        throw new InvalidArgumentException('You cannot create rewards for that owner.');
    }

    $title = trim((string)($data['title'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $rewardType = strtolower(trim((string)($data['reward_type'] ?? 'custom')));
    $claimMode = strtolower(trim((string)($data['claim_mode'] ?? 'none')));
    $valueText = trim((string)($data['value_text'] ?? ''));
    $coverUrl = coveted_safe_url($data['cover_url'] ?? null, false);
    $status = strtolower(trim((string)($data['status'] ?? 'draft')));
    $startsAt = trim((string)($data['starts_at'] ?? '')) ?: null;
    $expiresAt = trim((string)($data['expires_at'] ?? '')) ?: null;
    $valueAmount = $data['value_amount'] ?? null;

    $allowedTypes = [
        'credit', 'free_item', 'discount', 'perk', 'access', 'service',
        'audio', 'video', 'media_pack', 'experience', 'custom',
    ];

    if ($title === '' || mb_strlen($title) > 190) {
        throw new InvalidArgumentException('Enter a reward title.');
    }
    if (mb_strlen($description) > 4000 || mb_strlen($valueText) > 255) {
        throw new InvalidArgumentException('Reward copy is too long.');
    }
    if (!in_array($rewardType, $allowedTypes, true)) {
        throw new InvalidArgumentException('Invalid reward type.');
    }
    if (!in_array($claimMode, ['none', 'location_code'], true)) {
        throw new InvalidArgumentException('Invalid claim mode.');
    }
    if ($claimMode === 'location_code' && $ownerType !== 'business') {
        throw new InvalidArgumentException('Location-code rewards must belong to a business.');
    }
    if (!in_array($status, ['draft', 'active', 'paused', 'archived'], true)) {
        throw new InvalidArgumentException('Invalid reward status.');
    }
    if ($valueAmount !== null && (!is_numeric($valueAmount) || (float)$valueAmount < 0)) {
        throw new InvalidArgumentException('Invalid reward value.');
    }
    if ($status === 'active' && coveted_reward_owner_status($ownerType, $ownerId) !== 'active') {
        throw new InvalidArgumentException('Only an active owner can publish an active reward.');
    }

    if ($startsAt !== null) {
        $startsAt = coveted_utc_datetime($startsAt)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    if ($expiresAt !== null) {
        $expiresAt = coveted_utc_datetime($expiresAt)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    if ($startsAt !== null && $expiresAt !== null && strtotime($expiresAt) <= strtotime($startsAt)) {
        throw new InvalidArgumentException('Reward expiration must be after its start time.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $publicId = coveted_uuid('rwd');
        $pdo->prepare(
            "INSERT INTO reward_templates
                (public_id, owner_type, group_id, business_id, artist_id, created_by,
                 title, description, reward_type, claim_mode, value_amount, value_text, cover_url,
                 claim_rules_json, redemption_rules_json, starts_at, expires_at, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $publicId,
            $ownerType,
            $owner['group_id'],
            $owner['business_id'],
            $owner['artist_id'],
            (int)$actor['id'],
            $title,
            $description !== '' ? $description : null,
            $rewardType,
            $claimMode,
            $valueAmount !== null ? number_format((float)$valueAmount, 2, '.', '') : null,
            $valueText !== '' ? $valueText : null,
            $coverUrl,
            !empty($data['claim_rules']) && is_array($data['claim_rules'])
                ? coveted_json($data['claim_rules'])
                : null,
            !empty($data['redemption_rules']) && is_array($data['redemption_rules'])
                ? coveted_json($data['redemption_rules'])
                : null,
            $startsAt,
            $expiresAt,
            $status,
        ]);
        $templateId = (int)$pdo->lastInsertId();

        coveted_audit(
            'reward_template.created',
            'reward_template',
            $publicId,
            ['owner_type' => $ownerType, 'reward_type' => $rewardType, 'claim_mode' => $claimMode],
            (int)$actor['id']
        );

        $pdo->commit();
        return ['id' => $templateId, 'public_id' => $publicId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_reward_set_status(array $actor, string $templateRef, string $status): void
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['draft', 'active', 'paused', 'archived'], true)) {
        throw new InvalidArgumentException('Invalid reward status.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT * FROM reward_templates WHERE public_id = ? OR CAST(id AS CHAR) = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$templateRef, $templateRef]);
        $template = $stmt->fetch();
        if (!$template) {
            throw new InvalidArgumentException('Reward template not found.');
        }

        $ownerId = coveted_reward_template_owner_id($template);
        if (!coveted_reward_actor_can_manage_owner($actor, (string)$template['owner_type'], $ownerId)) {
            throw new InvalidArgumentException('You cannot manage this reward.');
        }
        if ($status === 'active' && coveted_reward_owner_status((string)$template['owner_type'], $ownerId) !== 'active') {
            throw new InvalidArgumentException('Only an active owner can publish an active reward.');
        }

        $pdo->prepare('UPDATE reward_templates SET status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$status, (int)$template['id']]);

        coveted_audit(
            'reward_template.status_changed',
            'reward_template',
            (string)$template['public_id'],
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

function coveted_reward_replace_media(array $actor, string $templateRef, array $items): void
{
    $template = coveted_reward_template_by_ref($templateRef);
    if (!$template) {
        throw new InvalidArgumentException('Reward template not found.');
    }

    $ownerId = coveted_reward_template_owner_id($template);
    if (!coveted_reward_actor_can_manage_owner($actor, (string)$template['owner_type'], $ownerId)) {
        throw new InvalidArgumentException('You cannot edit this reward.');
    }

    if (count($items) > 100) {
        throw new InvalidArgumentException('A reward can contain at most 100 media items.');
    }

    $validated = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('Invalid media item.');
        }

        $type = strtolower(trim((string)($item['media_type'] ?? '')));
        $title = trim((string)($item['title'] ?? ''));
        $url = coveted_safe_url($item['media_url'] ?? null, false);
        $mimeType = trim((string)($item['mime_type'] ?? ''));
        $duration = isset($item['duration_seconds']) ? (int)$item['duration_seconds'] : null;

        if (!in_array($type, ['audio', 'video', 'image', 'file'], true) || $url === null) {
            throw new InvalidArgumentException('Invalid reward media item.');
        }
        if (mb_strlen($title) > 190 || mb_strlen($mimeType) > 120) {
            throw new InvalidArgumentException('Media metadata is too long.');
        }
        if ($duration !== null && $duration < 0) {
            throw new InvalidArgumentException('Invalid media duration.');
        }

        $validated[] = [
            $type,
            $title !== '' ? $title : null,
            $url,
            $mimeType !== '' ? $mimeType : null,
            $duration,
            $index,
        ];
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $lock = $pdo->prepare('SELECT id FROM reward_templates WHERE id = ? FOR UPDATE');
        $lock->execute([(int)$template['id']]);
        $pdo->prepare('DELETE FROM reward_media WHERE reward_template_id = ?')
            ->execute([(int)$template['id']]);

        $insert = $pdo->prepare(
            'INSERT INTO reward_media
                (reward_template_id, media_type, title, media_url, mime_type, duration_seconds, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($validated as $item) {
            $insert->execute([(int)$template['id'], ...$item]);
        }

        coveted_audit(
            'reward_template.media_replaced',
            'reward_template',
            (string)$template['public_id'],
            ['media_count' => count($validated)],
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

function coveted_reward_media_for_template(int $templateId): array
{
    return coveted_reward_media_for_templates([$templateId])[$templateId] ?? [];
}

/** @return array<int,array<int,array<string,mixed>>> */
function coveted_reward_media_for_templates(array $templateIds): array
{
    $templateIds = array_values(array_unique(array_filter(array_map('intval', $templateIds), static fn(int $id): bool => $id > 0)));
    if (!$templateIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($templateIds), '?'));
    $stmt = coveted_db()->prepare(
        "SELECT reward_template_id, media_type, title, media_url, mime_type, duration_seconds, sort_order
         FROM reward_media
         WHERE reward_template_id IN ({$placeholders})
         ORDER BY reward_template_id, sort_order, id"
    );
    $stmt->execute($templateIds);

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int)$row['reward_template_id']][] = $row;
    }
    return $grouped;
}

function coveted_reward_existing_idempotent(PDO $pdo, string $idempotencyKey): ?array
{
    if ($idempotencyKey === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM reward_issuances WHERE idempotency_key = ? LIMIT 1');
    $stmt->execute([$idempotencyKey]);
    $issuance = $stmt->fetch();

    return $issuance ?: null;
}

function coveted_reward_issue(
    int $campaignId,
    int $userId,
    ?int $eventId = null,
    array $metadata = [],
    string $idempotencyKey = ''
): array {
    if ($campaignId < 1 || $userId < 1) {
        throw new InvalidArgumentException('Campaign and member are required.');
    }

    if ($idempotencyKey !== '' && strlen($idempotencyKey) > 190) {
        $idempotencyKey = hash('sha256', $idempotencyKey);
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $existing = coveted_reward_existing_idempotent($pdo, $idempotencyKey);
        if ($existing) {
            $pdo->commit();
            return $existing;
        }

        $stmt = $pdo->prepare(
            "SELECT
                c.*,
                rt.public_id AS reward_public_id,
                rt.owner_type AS reward_owner_type,
                rt.status AS reward_status,
                rt.starts_at AS reward_starts_at,
                rt.expires_at AS reward_expires_at,
                rt.reward_type,
                rt.claim_mode,
                rt.group_id AS reward_group_id,
                rt.business_id AS reward_business_id,
                rt.artist_id AS reward_artist_id
             FROM campaigns c
             JOIN reward_templates rt ON rt.id = c.reward_template_id
             WHERE c.id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch();

        if (!$campaign || $campaign['status'] !== 'active' || $campaign['reward_status'] !== 'active') {
            throw new InvalidArgumentException('Campaign is not active.');
        }

        $ownerCoherent = match ((string)$campaign['owner_type']) {
            'platform' => $campaign['reward_owner_type'] === 'platform',
            'group' => $campaign['reward_owner_type'] === 'group'
                && (int)$campaign['group_id'] === (int)$campaign['reward_group_id'],
            'business' => $campaign['reward_owner_type'] === 'business'
                && (int)$campaign['business_id'] === (int)$campaign['reward_business_id'],
            'artist' => $campaign['reward_owner_type'] === 'artist'
                && (int)$campaign['artist_id'] === (int)$campaign['reward_artist_id'],
            default => false,
        };
        if (!$ownerCoherent) {
            throw new RuntimeException('Campaign reward ownership is inconsistent.');
        }

        $ownerId = match ((string)$campaign['owner_type']) {
            'group' => (int)$campaign['group_id'],
            'business' => (int)$campaign['business_id'],
            'artist' => (int)$campaign['artist_id'],
            default => 0,
        };
        if (coveted_reward_owner_status((string)$campaign['owner_type'], $ownerId) !== 'active') {
            throw new InvalidArgumentException('Campaign owner is not active.');
        }

        if ($eventId !== null) {
            $link = $pdo->prepare(
                'SELECT 1 FROM campaign_event_links WHERE campaign_id = ? AND event_id = ? LIMIT 1 FOR UPDATE'
            );
            $link->execute([$campaignId, $eventId]);
            if (!$link->fetchColumn()) {
                throw new InvalidArgumentException('Campaign is not linked to this event.');
            }
        }

        $now = time();
        foreach (['starts_at', 'reward_starts_at'] as $field) {
            if (!empty($campaign[$field]) && strtotime((string)$campaign[$field]) > $now) {
                throw new InvalidArgumentException('Campaign is not active yet.');
            }
        }
        if (!empty($campaign['ends_at']) && strtotime((string)$campaign['ends_at']) <= $now) {
            throw new InvalidArgumentException('Campaign has ended.');
        }
        if (!empty($campaign['reward_expires_at']) && strtotime((string)$campaign['reward_expires_at']) <= $now) {
            throw new InvalidArgumentException('Reward has expired.');
        }

        $userStmt = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $userStmt->execute([$userId]);
        if ($userStmt->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Member account is not active.');
        }

        if ($campaign['quantity_limit'] !== null) {
            $count = $pdo->prepare(
                "SELECT COUNT(*) FROM reward_issuances
                 WHERE campaign_id = ? AND status <> 'cancelled'"
            );
            $count->execute([$campaignId]);
            if ((int)$count->fetchColumn() >= (int)$campaign['quantity_limit']) {
                throw new InvalidArgumentException('Campaign distribution limit has been reached.');
            }
        }

        if ($campaign['per_user_limit'] !== null) {
            $count = $pdo->prepare(
                "SELECT COUNT(*) FROM reward_issuances
                 WHERE campaign_id = ? AND user_id = ? AND status <> 'cancelled'"
            );
            $count->execute([$campaignId, $userId]);
            if ((int)$count->fetchColumn() >= (int)$campaign['per_user_limit']) {
                throw new InvalidArgumentException('Member campaign limit has been reached.');
            }
        }

        $publicId = coveted_uuid('iss');
        $pdo->prepare(
            "INSERT INTO reward_issuances
                (public_id, campaign_id, reward_template_id, user_id, event_id,
                 location_id, artist_id, status, idempotency_key, expires_at, metadata_json, issued_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, ?, NOW())"
        )->execute([
            $publicId,
            $campaignId,
            (int)$campaign['reward_template_id'],
            $userId,
            $eventId,
            $campaign['location_id'] !== null ? (int)$campaign['location_id'] : null,
            $campaign['reward_artist_id'] !== null ? (int)$campaign['reward_artist_id'] : null,
            $idempotencyKey !== '' ? $idempotencyKey : null,
            $campaign['reward_expires_at'] ?: null,
            $metadata ? coveted_json($metadata) : null,
        ]);

        $issuanceId = (int)$pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO campaign_activity
                (public_id, campaign_id, reward_issuance_id, user_id, event_id, activity_type, metadata_json)
             VALUES (?, ?, ?, ?, ?, 'reward_issued', ?)"
        )->execute([
            coveted_uuid('cact'),
            $campaignId,
            $issuanceId,
            $userId,
            $eventId,
            $metadata ? coveted_json($metadata) : null,
        ]);

        coveted_audit(
            'reward.issued',
            'reward_issuance',
            $publicId,
            ['campaign_id' => $campaign['public_id'], 'event_id' => $eventId]
        );

        $pdo->commit();

        $result = coveted_db()->prepare('SELECT * FROM reward_issuances WHERE id = ? LIMIT 1');
        $result->execute([$issuanceId]);
        return $result->fetch() ?: ['id' => $issuanceId, 'public_id' => $publicId];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ((string)$e->getCode() === '23000' && $idempotencyKey !== '') {
            $existing = coveted_reward_existing_idempotent(coveted_db(), $idempotencyKey);
            if ($existing) {
                return $existing;
            }
        }

        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_reward_mark_viewed(string $issuanceRef, int $userId): void
{
    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT id, campaign_id, event_id, status, expires_at
             FROM reward_issuances
             WHERE (public_id = ? OR CAST(id AS CHAR) = ?)
               AND user_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$issuanceRef, $issuanceRef, $userId]);
        $issuance = $stmt->fetch();

        if (!$issuance || in_array($issuance['status'], ['expired', 'cancelled'], true)) {
            throw new InvalidArgumentException('Reward is not available.');
        }
        if (!empty($issuance['expires_at']) && strtotime((string)$issuance['expires_at']) <= time()) {
            $pdo->prepare("UPDATE reward_issuances SET status = 'expired', updated_at = NOW() WHERE id = ?")
                ->execute([(int)$issuance['id']]);
            $pdo->prepare(
                "INSERT INTO campaign_activity
                    (public_id, campaign_id, reward_issuance_id, user_id, event_id, activity_type)
                 VALUES (?, ?, ?, ?, ?, 'reward_expired')"
            )->execute([
                coveted_uuid('cact'),
                (int)$issuance['campaign_id'],
                (int)$issuance['id'],
                $userId,
                $issuance['event_id'] !== null ? (int)$issuance['event_id'] : null,
            ]);
            $pdo->commit();
            throw new InvalidArgumentException('Reward has expired.');
        }

        if ($issuance['status'] === 'issued') {
            $pdo->prepare(
                "UPDATE reward_issuances
                 SET status = 'viewed', viewed_at = COALESCE(viewed_at, NOW()), updated_at = NOW()
                 WHERE id = ?"
            )->execute([(int)$issuance['id']]);

            $pdo->prepare(
                "INSERT INTO campaign_activity
                    (public_id, campaign_id, reward_issuance_id, user_id, event_id, activity_type)
                 VALUES (?, ?, ?, ?, ?, 'reward_viewed')"
            )->execute([
                coveted_uuid('cact'),
                (int)$issuance['campaign_id'],
                (int)$issuance['id'],
                $userId,
                $issuance['event_id'] !== null ? (int)$issuance['event_id'] : null,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_reward_eligible_locations(string $issuanceRef, int $userId): array
{
    $stmt = coveted_db()->prepare(
        "SELECT ri.location_id, rt.business_id, rt.claim_mode, b.status AS business_status
         FROM reward_issuances ri
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         LEFT JOIN businesses b ON b.id = rt.business_id
         WHERE (ri.public_id = ? OR CAST(ri.id AS CHAR) = ?)
           AND ri.user_id = ?
         LIMIT 1"
    );
    $stmt->execute([$issuanceRef, $issuanceRef, $userId]);
    $reward = $stmt->fetch();

    if (
        !$reward
        || $reward['claim_mode'] !== 'location_code'
        || $reward['business_id'] === null
        || $reward['business_status'] !== 'active'
    ) {
        return [];
    }

    if ($reward['location_id'] !== null) {
        $location = coveted_location_by_ref((string)$reward['location_id']);
        return $location && $location['status'] === 'active' ? [$location] : [];
    }

    return coveted_locations_for_business((int)$reward['business_id'], true);
}

/** @return array<string,array<int,array<string,mixed>>> */
function coveted_reward_eligible_locations_for_rows(array $rewards): array
{
    $businessIds = [];
    foreach ($rewards as $reward) {
        if (($reward['claim_mode'] ?? '') === 'location_code' && !empty($reward['business_id'])) {
            $businessIds[] = (int)$reward['business_id'];
        }
    }

    $locations = coveted_locations_for_businesses($businessIds);
    $byBusiness = [];
    $byId = [];
    foreach ($locations as $location) {
        $byBusiness[(int)$location['business_id']][] = $location;
        $byId[(int)$location['id']] = $location;
    }

    $result = [];
    foreach ($rewards as $reward) {
        $ref = (string)($reward['public_id'] ?? '');
        if ($ref === '' || ($reward['claim_mode'] ?? '') !== 'location_code' || empty($reward['business_id'])) {
            continue;
        }

        if (!empty($reward['location_id'])) {
            $locationId = (int)$reward['location_id'];
            if (isset($byId[$locationId])) {
                $result[$ref] = [$byId[$locationId]];
            }
            continue;
        }

        $result[$ref] = $byBusiness[(int)$reward['business_id']] ?? [];
    }

    return $result;
}

function coveted_reward_claim_with_code(
    array $member,
    string $issuanceRef,
    int $locationId,
    string $claimCode
): array {
    if ($locationId < 1) {
        throw new InvalidArgumentException('Select the business location.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT
                ri.*,
                rt.business_id,
                rt.claim_mode,
                rt.value_amount,
                c.location_id AS campaign_location_id
             FROM reward_issuances ri
             JOIN reward_templates rt ON rt.id = ri.reward_template_id
             JOIN campaigns c ON c.id = ri.campaign_id
             WHERE (ri.public_id = ? OR CAST(ri.id AS CHAR) = ?)
               AND ri.user_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$issuanceRef, $issuanceRef, (int)$member['id']]);
        $issuance = $stmt->fetch();

        if (!$issuance) {
            throw new InvalidArgumentException('Reward not found.');
        }

        if ($issuance['status'] === 'claimed') {
            $existing = $pdo->prepare(
                "SELECT * FROM reward_claims
                 WHERE reward_issuance_id = ? AND status = 'claimed'
                 ORDER BY id DESC LIMIT 1"
            );
            $existing->execute([(int)$issuance['id']]);
            $claim = $existing->fetch();
            if ($claim) {
                $pdo->commit();
                return $claim;
            }
        }

        if (in_array($issuance['status'], ['expired', 'cancelled'], true)) {
            throw new InvalidArgumentException('Reward cannot be claimed.');
        }
        if (!empty($issuance['expires_at']) && strtotime((string)$issuance['expires_at']) <= time()) {
            $pdo->prepare("UPDATE reward_issuances SET status = 'expired', updated_at = NOW() WHERE id = ?")
                ->execute([(int)$issuance['id']]);
            $pdo->prepare(
                "INSERT INTO campaign_activity
                    (public_id, campaign_id, reward_issuance_id, user_id, event_id, activity_type)
                 VALUES (?, ?, ?, ?, ?, 'reward_expired')"
            )->execute([
                coveted_uuid('cact'),
                (int)$issuance['campaign_id'],
                (int)$issuance['id'],
                (int)$member['id'],
                $issuance['event_id'] !== null ? (int)$issuance['event_id'] : null,
            ]);
            $pdo->commit();
            throw new InvalidArgumentException('Reward has expired.');
        }
        if ($issuance['claim_mode'] !== 'location_code' || $issuance['business_id'] === null) {
            throw new InvalidArgumentException('This reward does not use a business claim code.');
        }

        $locationStmt = $pdo->prepare(
            "SELECT l.*, b.status AS business_status
             FROM locations l
             JOIN businesses b ON b.id = l.business_id
             WHERE l.id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $locationStmt->execute([$locationId]);
        $location = $locationStmt->fetch();
        if (!$location || $location['status'] !== 'active' || $location['business_status'] !== 'active') {
            throw new InvalidArgumentException('Claim location is unavailable.');
        }
        if ((int)$issuance['business_id'] !== (int)$location['business_id']) {
            throw new InvalidArgumentException('Reward is not valid at this business.');
        }
        if ($issuance['campaign_location_id'] !== null && (int)$issuance['campaign_location_id'] !== $locationId) {
            throw new InvalidArgumentException('Reward is not valid at this location.');
        }

        coveted_claim_assert_attempt_allowed((int)$member['id'], (int)$issuance['id']);

        try {
            $code = coveted_claim_code_verify_for_location($pdo, $location, $claimCode);
        } catch (InvalidArgumentException) {
            $code = null;
        }

        if (!$code) {
            $pdo->rollBack();
            coveted_claim_record_failure((int)$member['id'], (int)$issuance['id']);
            throw new InvalidArgumentException('Claim code is incorrect.');
        }

        coveted_claim_clear_attempts((int)$member['id'], (int)$issuance['id']);

        $claimPublicId = coveted_uuid('claim');
        $pdo->prepare(
            "INSERT INTO reward_claims
                (public_id, reward_issuance_id, location_id, claim_code_id,
                 claim_code_type, claim_code_label, status, claim_method, claimed_at)
             VALUES (?, ?, ?, ?, ?, ?, 'claimed', 'location_code', NOW())"
        )->execute([
            $claimPublicId,
            (int)$issuance['id'],
            $locationId,
            (int)$code['id'],
            (string)$code['code_type'],
            (string)$code['label'],
        ]);
        $claimId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "UPDATE reward_issuances
             SET status = 'claimed',
                 viewed_at = COALESCE(viewed_at, NOW()),
                 claimed_at = NOW(),
                 updated_at = NOW()
             WHERE id = ?"
        )->execute([(int)$issuance['id']]);

        $activityMetadata = [
            'claim_id' => $claimPublicId,
            'location_id' => $locationId,
            'claim_code_id' => (int)$code['id'],
            'claim_code_type' => (string)$code['code_type'],
            'claim_code_label' => (string)$code['label'],
        ];
        $pdo->prepare(
            "INSERT INTO campaign_activity
                (public_id, campaign_id, reward_issuance_id, user_id, event_id, activity_type, metadata_json)
             VALUES (?, ?, ?, ?, ?, 'reward_claimed', ?)"
        )->execute([
            coveted_uuid('cact'),
            (int)$issuance['campaign_id'],
            (int)$issuance['id'],
            (int)$issuance['user_id'],
            $issuance['event_id'] !== null ? (int)$issuance['event_id'] : null,
            coveted_json($activityMetadata),
        ]);

        coveted_audit(
            'reward.claimed',
            'reward_claim',
            $claimPublicId,
            $activityMetadata,
            (int)$member['id']
        );

        $pdo->commit();

        $result = coveted_db()->prepare('SELECT * FROM reward_claims WHERE id = ? LIMIT 1');
        $result->execute([$claimId]);
        return $result->fetch() ?: ['id' => $claimId, 'public_id' => $claimPublicId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_reward_refund_claim(array $actor, string $claimRef, string $reason = ''): void
{
    $reason = trim($reason);
    if (mb_strlen($reason) > 500) {
        throw new InvalidArgumentException('Refund reason is too long.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT
                rc.*,
                ri.campaign_id,
                ri.user_id,
                ri.event_id,
                ri.expires_at,
                ri.status AS issuance_status,
                rt.business_id,
                l.business_id AS location_business_id
             FROM reward_claims rc
             JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
             JOIN reward_templates rt ON rt.id = ri.reward_template_id
             JOIN locations l ON l.id = rc.location_id
             WHERE rc.public_id = ? OR CAST(rc.id AS CHAR) = ?
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([$claimRef, $claimRef]);
        $claim = $stmt->fetch();

        if (!$claim) {
            throw new InvalidArgumentException('Claim not found.');
        }
        if ($claim['status'] === 'refunded') {
            $pdo->commit();
            return;
        }
        if (
            $claim['business_id'] === null
            || (int)$claim['business_id'] !== (int)$claim['location_business_id']
            || !coveted_business_actor_can_manage($actor, (int)$claim['business_id'])
        ) {
            throw new InvalidArgumentException('Only a Business Admin or System Admin can refund this claim.');
        }

        $pdo->prepare(
            "UPDATE reward_claims
             SET status = 'refunded',
                 refunded_at = NOW(),
                 refunded_by_user_id = ?,
                 refund_reason = ?
             WHERE id = ?"
        )->execute([
            (int)$actor['id'],
            $reason !== '' ? $reason : null,
            (int)$claim['id'],
        ]);

        $nextStatus = !empty($claim['expires_at']) && strtotime((string)$claim['expires_at']) <= time()
            ? 'expired'
            : 'viewed';
        $pdo->prepare(
            'UPDATE reward_issuances SET status = ?, claimed_at = NULL, updated_at = NOW() WHERE id = ?'
        )->execute([$nextStatus, (int)$claim['reward_issuance_id']]);

        $metadata = [
            'claim_id' => (string)$claim['public_id'],
            'location_id' => (int)$claim['location_id'],
            'refund_reason' => $reason !== '' ? $reason : null,
        ];
        $pdo->prepare(
            "INSERT INTO campaign_activity
                (public_id, campaign_id, reward_issuance_id, user_id, event_id, activity_type, metadata_json)
             VALUES (?, ?, ?, ?, ?, 'reward_refunded', ?)"
        )->execute([
            coveted_uuid('cact'),
            (int)$claim['campaign_id'],
            (int)$claim['reward_issuance_id'],
            (int)$claim['user_id'],
            $claim['event_id'] !== null ? (int)$claim['event_id'] : null,
            coveted_json($metadata),
        ]);

        coveted_audit(
            'reward.refunded',
            'reward_claim',
            (string)$claim['public_id'],
            $metadata,
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

function coveted_reward_list_for_user(int $userId, array $rewardTypes = [], string $box = 'inbox'): array
{
    $allowed = [
        'credit', 'free_item', 'discount', 'perk', 'access', 'service',
        'audio', 'video', 'media_pack', 'experience', 'custom',
    ];
    $rewardTypes = array_values(array_intersect($rewardTypes, $allowed));
    if (!in_array($box, ['inbox', 'claimed', 'all'], true)) {
        $box = 'inbox';
    }

    if ($box === 'claimed') {
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
                    rt.group_id,
                    rt.business_id,
                    rt.artist_id AS template_artist_id,
                    c.title AS campaign_title,
                    e.title AS event_title,
                    ap.artist_name,
                    l.name AS location_name,
                    rc.public_id AS claim_public_id,
                    rc.status AS claim_status,
                    rc.claimed_at AS claim_recorded_at,
                    rc.refunded_at AS claim_refunded_at,
                    rc.refund_reason AS claim_refund_reason,
                    rc.claim_code_type,
                    rc.claim_code_label
                FROM reward_claims rc
                JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
                JOIN reward_templates rt ON rt.id = ri.reward_template_id
                JOIN campaigns c ON c.id = ri.campaign_id
                LEFT JOIN events e ON e.id = ri.event_id
                LEFT JOIN artist_profiles ap ON ap.id = COALESCE(ri.artist_id, rt.artist_id)
                JOIN locations l ON l.id = rc.location_id
                WHERE ri.user_id = ?";
        $params = [$userId];

        if ($rewardTypes) {
            $sql .= ' AND rt.reward_type IN (' . implode(',', array_fill(0, count($rewardTypes), '?')) . ')';
            array_push($params, ...$rewardTypes);
        }

        $sql .= ' ORDER BY rc.claimed_at DESC, rc.id DESC LIMIT 100';
        $stmt = coveted_db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

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
                rt.group_id,
                rt.business_id,
                rt.artist_id AS template_artist_id,
                c.title AS campaign_title,
                e.title AS event_title,
                ap.artist_name,
                il.name AS location_name,
                NULL AS claim_public_id,
                NULL AS claim_status,
                NULL AS claim_recorded_at,
                NULL AS claim_refunded_at,
                NULL AS claim_refund_reason,
                NULL AS claim_code_type,
                NULL AS claim_code_label
            FROM reward_issuances ri
            JOIN reward_templates rt ON rt.id = ri.reward_template_id
            JOIN campaigns c ON c.id = ri.campaign_id
            LEFT JOIN events e ON e.id = ri.event_id
            LEFT JOIN artist_profiles ap ON ap.id = COALESCE(ri.artist_id, rt.artist_id)
            LEFT JOIN locations il ON il.id = ri.location_id
            WHERE ri.user_id = ?
              AND ri.status <> 'cancelled'";
    $params = [$userId];

    if ($box === 'inbox') {
        $sql .= " AND ri.status IN ('issued','viewed') AND (ri.expires_at IS NULL OR ri.expires_at > NOW())";
    }

    if ($rewardTypes) {
        $sql .= ' AND rt.reward_type IN (' . implode(',', array_fill(0, count($rewardTypes), '?')) . ')';
        array_push($params, ...$rewardTypes);
    }

    $sql .= ' ORDER BY ri.issued_at DESC, ri.id DESC LIMIT 100';

    $stmt = coveted_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function coveted_reward_claims_for_business(int $businessId, int $limit = 200): array
{
    $limit = max(1, min($limit, 500));
    $stmt = coveted_db()->prepare(
        "SELECT
            rc.*,
            ri.public_id AS issuance_public_id,
            ri.user_id,
            rt.title AS reward_title,
            rt.value_amount,
            u.display_name,
            u.email,
            l.name AS location_name,
            c.title AS campaign_title
         FROM reward_claims rc
         JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         JOIN campaigns c ON c.id = ri.campaign_id
         JOIN users u ON u.id = ri.user_id
         JOIN locations l ON l.id = rc.location_id
         WHERE rt.business_id = ?
         ORDER BY rc.claimed_at DESC, rc.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$businessId]);
    return $stmt->fetchAll();
}
