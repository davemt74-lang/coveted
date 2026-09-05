<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function coveted_artist_by_ref(string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '' || strlen($ref) > 64) {
        return null;
    }

    $stmt = coveted_db()->prepare(
        "SELECT ap.*, u.display_name AS owner_display_name
         FROM artist_profiles ap
         JOIN users u ON u.id = ap.owner_user_id
         WHERE ap.public_id = ? OR CAST(ap.id AS CHAR) = ?
         LIMIT 1"
    );
    $stmt->execute([$ref, $ref]);
    $artist = $stmt->fetch();
    return $artist ?: null;
}

function coveted_artist_actor_has_partner_approval(array $actor): bool
{
    return coveted_is_system_admin($actor)
        || in_array('artist_partner', (array)($actor['roles'] ?? []), true);
}

function coveted_artist_actor_permission(array $actor, int $artistId): string
{
    if (coveted_is_system_admin($actor)) {
        return 'system_admin';
    }

    $stmt = coveted_db()->prepare(
        "SELECT
            CASE
                WHEN ap.owner_user_id = ? THEN 'owner'
                WHEN am.member_role = 'manager' THEN 'manager'
                WHEN am.member_role = 'member' THEN 'member'
                ELSE 'none'
            END AS permission
         FROM artist_profiles ap
         LEFT JOIN artist_members am ON am.artist_id = ap.id AND am.user_id = ?
         WHERE ap.id = ?
         LIMIT 1"
    );
    $stmt->execute([(int)$actor['id'], (int)$actor['id'], $artistId]);
    $permission = $stmt->fetchColumn();
    return $permission !== false ? (string)$permission : 'none';
}

function coveted_artist_actor_can_manage(array $actor, int $artistId): bool
{
    if (!coveted_artist_actor_has_partner_approval($actor)) {
        return false;
    }

    return in_array(coveted_artist_actor_permission($actor, $artistId), ['system_admin','owner','manager'], true);
}

function coveted_artist_actor_can_manage_team(array $actor, int $artistId): bool
{
    if (!coveted_artist_actor_has_partner_approval($actor)) {
        return false;
    }

    return in_array(coveted_artist_actor_permission($actor, $artistId), ['system_admin','owner'], true);
}

/** @return array<int,array<string,mixed>> */
function coveted_artists_for_actor(array $actor): array
{
    if (coveted_is_system_admin($actor)) {
        return coveted_db()->query(
            "SELECT ap.*, u.display_name AS owner_display_name, 'system_admin' AS permission
             FROM artist_profiles ap
             JOIN users u ON u.id = ap.owner_user_id
             ORDER BY ap.artist_name, ap.id"
        )->fetchAll();
    }

    $stmt = coveted_db()->prepare(
        "SELECT DISTINCT ap.*, u.display_name AS owner_display_name,
                CASE
                    WHEN ap.owner_user_id = ? THEN 'owner'
                    WHEN am.member_role = 'manager' THEN 'manager'
                    ELSE 'member'
                END AS permission
         FROM artist_profiles ap
         JOIN users u ON u.id = ap.owner_user_id
         LEFT JOIN artist_members am ON am.artist_id = ap.id AND am.user_id = ?
         WHERE ap.owner_user_id = ? OR am.user_id = ?
         ORDER BY ap.artist_name, ap.id"
    );
    $stmt->execute([(int)$actor['id'], (int)$actor['id'], (int)$actor['id'], (int)$actor['id']]);
    return $stmt->fetchAll();
}

function coveted_artist_create(array $actor, string $artistName, string $bio = ''): array
{
    if (!coveted_artist_actor_has_partner_approval($actor)) {
        throw new InvalidArgumentException('Artist Partner approval is required before creating an artist profile.');
    }

    $artistName = trim($artistName);
    $bio = trim($bio);
    if ($artistName === '' || mb_strlen($artistName) > 180) {
        throw new InvalidArgumentException('Enter an artist name.');
    }
    if (mb_strlen($bio) > 5000) {
        throw new InvalidArgumentException('Artist bio is too long.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $actorRow = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $actorRow->execute([(int)$actor['id']]);
        if ($actorRow->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Artist owner account must be active.');
        }

        $publicId = coveted_uuid('art');
        $pdo->prepare(
            "INSERT INTO artist_profiles
                (public_id, owner_user_id, artist_name, bio, status)
             VALUES (?, ?, ?, ?, 'active')"
        )->execute([
            $publicId,
            (int)$actor['id'],
            $artistName,
            $bio !== '' ? $bio : null,
        ]);
        $artistId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO artist_members (artist_id, user_id, member_role)
             VALUES (?, ?, 'owner')"
        )->execute([$artistId, (int)$actor['id']]);

        coveted_audit(
            'artist.created',
            'artist',
            $publicId,
            ['artist_name' => $artistName],
            (int)$actor['id']
        );
        $pdo->commit();
        return ['id' => $artistId, 'public_id' => $publicId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_artist_update(array $actor, string $artistRef, array $data): void
{
    $artistName = trim((string)($data['artist_name'] ?? ''));
    $bio = trim((string)($data['bio'] ?? ''));
    $avatarUrl = coveted_safe_url($data['avatar_url'] ?? null, false);
    $coverUrl = coveted_safe_url($data['cover_url'] ?? null, false);
    $links = isset($data['links']) && is_array($data['links']) ? $data['links'] : [];

    if ($artistName === '' || mb_strlen($artistName) > 180) {
        throw new InvalidArgumentException('Enter an artist name.');
    }
    if (mb_strlen($bio) > 5000 || count($links) > 20) {
        throw new InvalidArgumentException('Artist profile content is too large.');
    }

    $safeLinks = [];
    foreach ($links as $label => $url) {
        $label = trim((string)$label);
        $safeUrl = coveted_safe_url((string)$url, false);
        if ($label === '' || mb_strlen($label) > 80 || $safeUrl === null) {
            continue;
        }
        $safeLinks[$label] = $safeUrl;
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM artist_profiles WHERE public_id = ? OR CAST(id AS CHAR) = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$artistRef, $artistRef]);
        $artist = $stmt->fetch();
        if (!$artist || !coveted_artist_actor_can_manage($actor, (int)$artist['id'])) {
            throw new InvalidArgumentException('You cannot manage this artist.');
        }
        if ($artist['status'] === 'archived' && !coveted_is_system_admin($actor)) {
            throw new InvalidArgumentException('Archived artist profiles can only be changed by System Admin.');
        }

        $pdo->prepare(
            "UPDATE artist_profiles
             SET artist_name = ?, bio = ?, avatar_url = ?, cover_url = ?, links_json = ?, updated_at = NOW()
             WHERE id = ?"
        )->execute([
            $artistName,
            $bio !== '' ? $bio : null,
            $avatarUrl,
            $coverUrl,
            $safeLinks ? coveted_json($safeLinks) : null,
            (int)$artist['id'],
        ]);

        coveted_audit('artist.updated', 'artist', (string)$artist['public_id'], [], (int)$actor['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_artist_set_status(array $actor, string $artistRef, string $status): void
{
    $status = strtolower(trim($status));
    if (!in_array($status, ['active','paused','archived'], true)) {
        throw new InvalidArgumentException('Invalid artist status.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM artist_profiles WHERE public_id = ? OR CAST(id AS CHAR) = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$artistRef, $artistRef]);
        $artist = $stmt->fetch();
        if (!$artist || !coveted_artist_actor_can_manage_team($actor, (int)$artist['id'])) {
            throw new InvalidArgumentException('Artist owner or System Admin access is required.');
        }
        if ($artist['status'] === 'archived' && $status !== 'archived' && !coveted_is_system_admin($actor)) {
            throw new InvalidArgumentException('Only System Admin can restore an archived artist.');
        }

        $pdo->prepare('UPDATE artist_profiles SET status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$status, (int)$artist['id']]);
        coveted_audit('artist.status_changed', 'artist', (string)$artist['public_id'], ['status' => $status], (int)$actor['id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_artist_add_member(array $actor, string $artistRef, int $userId, string $role): void
{
    $role = strtolower(trim($role));
    if (!in_array($role, ['manager','member'], true)) {
        throw new InvalidArgumentException('Artist team role must be manager or member.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM artist_profiles WHERE public_id = ? OR CAST(id AS CHAR) = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$artistRef, $artistRef]);
        $artist = $stmt->fetch();
        if (!$artist || !coveted_artist_actor_can_manage_team($actor, (int)$artist['id'])) {
            throw new InvalidArgumentException('Artist owner or System Admin access is required.');
        }
        if ($artist['status'] === 'archived' && !coveted_is_system_admin($actor)) {
            throw new InvalidArgumentException('Archived artist teams can only be changed by System Admin.');
        }

        $userStmt = $pdo->prepare(
            "SELECT u.status,
                    EXISTS (
                        SELECT 1 FROM user_roles ur
                        WHERE ur.user_id = u.id AND ur.role_key IN ('artist_partner','system_admin')
                    ) AS partner_approved
             FROM users u
             WHERE u.id = ?
             LIMIT 1 FOR UPDATE"
        );
        $userStmt->execute([$userId]);
        $target = $userStmt->fetch();
        if (!$target || $target['status'] !== 'active') {
            throw new InvalidArgumentException('Artist team member account must be active.');
        }
        if ($role === 'manager' && !(bool)$target['partner_approved']) {
            throw new InvalidArgumentException('Artist managers require Artist Partner approval.');
        }
        if ($userId === (int)$artist['owner_user_id']) {
            throw new InvalidArgumentException('The artist owner already has owner access.');
        }

        $pdo->prepare(
            "INSERT INTO artist_members (artist_id, user_id, member_role)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE member_role = VALUES(member_role)"
        )->execute([(int)$artist['id'], $userId, $role]);
        coveted_audit(
            'artist.member_changed',
            'artist',
            (string)$artist['public_id'],
            ['user_id' => $userId, 'role' => $role],
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

function coveted_artist_remove_member(array $actor, string $artistRef, int $userId): void
{
    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM artist_profiles WHERE public_id = ? OR CAST(id AS CHAR) = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$artistRef, $artistRef]);
        $artist = $stmt->fetch();
        if (!$artist || !coveted_artist_actor_can_manage_team($actor, (int)$artist['id'])) {
            throw new InvalidArgumentException('Artist owner or System Admin access is required.');
        }
        if ($artist['status'] === 'archived' && !coveted_is_system_admin($actor)) {
            throw new InvalidArgumentException('Archived artist teams can only be changed by System Admin.');
        }
        if ($userId === (int)$artist['owner_user_id']) {
            throw new InvalidArgumentException('The artist owner cannot be removed.');
        }

        $pdo->prepare('DELETE FROM artist_members WHERE artist_id = ? AND user_id = ?')
            ->execute([(int)$artist['id'], $userId]);
        coveted_audit(
            'artist.member_removed',
            'artist',
            (string)$artist['public_id'],
            ['user_id' => $userId],
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
