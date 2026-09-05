<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Lock the current System Admin role rows and their accounts in deterministic
 * order. This serializes mutations that could otherwise remove or suspend
 * every usable platform administrator under concurrent requests.
 *
 * @return array<int,array{user_id:int,status:string}>
 */
function coveted_admin_lock_accounts(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT ur.user_id, u.status
         FROM user_roles ur
         JOIN users u ON u.id = ur.user_id
         WHERE ur.role_key = 'system_admin'
         ORDER BY ur.user_id
         FOR UPDATE"
    );

    return $stmt->fetchAll();
}

function coveted_admin_active_count(array $adminAccounts): int
{
    $count = 0;

    foreach ($adminAccounts as $account) {
        if ($account['status'] === 'active') {
            $count++;
        }
    }

    return $count;
}

/**
 * Remove all resource-scoped host authority derived from Attendee Host
 * approval. Every affected group is locked before its memberships so this
 * follows the same lock order as normal group administration.
 */
function coveted_admin_revoke_attendee_host_locked(PDO $pdo, int $userId): array
{
    $groupStmt = $pdo->prepare(
        "SELECT DISTINCT group_id
         FROM group_memberships
         WHERE user_id = ?
           AND membership_status = 'active'
           AND group_role IN ('host','group_admin')
         ORDER BY group_id"
    );
    $groupStmt->execute([$userId]);
    $groupIds = array_map('intval', array_column($groupStmt->fetchAll(), 'group_id'));

    $demotedGroups = [];
    foreach ($groupIds as $groupId) {
        $groupLock = $pdo->prepare('SELECT id FROM social_groups WHERE id = ? LIMIT 1 FOR UPDATE');
        $groupLock->execute([$groupId]);
        if (!$groupLock->fetchColumn()) {
            continue;
        }

        $memberships = $pdo->prepare(
            "SELECT gm.user_id, gm.group_role, gm.membership_status,
                    u.status AS user_status,
                    EXISTS (
                        SELECT 1 FROM user_roles ur
                        WHERE ur.user_id = gm.user_id
                          AND ur.role_key IN ('attendee_host','system_admin')
                    ) AS host_approved
             FROM group_memberships gm
             JOIN users u ON u.id = gm.user_id
             WHERE gm.group_id = ?
             ORDER BY gm.user_id
             FOR UPDATE"
        );
        $memberships->execute([$groupId]);
        $rows = $memberships->fetchAll();

        $targetIsAdmin = false;
        $otherApprovedAdmin = false;
        foreach ($rows as $row) {
            if ((int)$row['user_id'] === $userId
                && $row['membership_status'] === 'active'
                && $row['group_role'] === 'group_admin') {
                $targetIsAdmin = true;
                continue;
            }

            if ((int)$row['user_id'] !== $userId
                && $row['membership_status'] === 'active'
                && $row['group_role'] === 'group_admin'
                && $row['user_status'] === 'active'
                && (bool)$row['host_approved']) {
                $otherApprovedAdmin = true;
            }
        }

        if ($targetIsAdmin && !$otherApprovedAdmin) {
            throw new InvalidArgumentException(
                'Transfer Group Admin access before removing Attendee Host approval.'
            );
        }

        $demote = $pdo->prepare(
            "UPDATE group_memberships
             SET group_role = 'member', updated_at = NOW()
             WHERE group_id = ? AND user_id = ?
               AND membership_status = 'active'
               AND group_role IN ('host','group_admin')"
        );
        $demote->execute([$groupId, $userId]);
        if ($demote->rowCount() > 0) {
            $demotedGroups[] = $groupId;
        }
    }

    $eventCleanup = $pdo->prepare(
        "DELETE FROM event_hosts
         WHERE user_id = ? AND host_role IN ('lead','cohost')"
    );
    $eventCleanup->execute([$userId]);

    return [
        'demoted_group_ids' => $demotedGroups,
        'removed_event_host_assignments' => $eventCleanup->rowCount(),
    ];
}

/**
 * Remove privileged artist-team authority when Artist Partner approval is
 * revoked. Canonical ownership stays on artist_profiles; owner memberships are
 * restored only after an explicit future approval. Managers remain members.
 */
function coveted_admin_revoke_artist_partner_locked(PDO $pdo, int $userId): array
{
    $demoteManagers = $pdo->prepare(
        "UPDATE artist_members
         SET member_role = 'member'
         WHERE user_id = ? AND member_role = 'manager'"
    );
    $demoteManagers->execute([$userId]);

    $removeOwnerMemberships = $pdo->prepare(
        "DELETE FROM artist_members
         WHERE user_id = ? AND member_role = 'owner'"
    );
    $removeOwnerMemberships->execute([$userId]);

    return [
        'demoted_artist_manager_memberships' => $demoteManagers->rowCount(),
        'removed_artist_owner_memberships' => $removeOwnerMemberships->rowCount(),
    ];
}

function coveted_admin_restore_artist_partner_owner_memberships_locked(PDO $pdo, int $userId): array
{
    $owned = $pdo->prepare(
        'SELECT id FROM artist_profiles WHERE owner_user_id = ? ORDER BY id'
    );
    $owned->execute([$userId]);
    $artistIds = array_map('intval', array_column($owned->fetchAll(), 'id'));

    if ($artistIds) {
        $restore = $pdo->prepare(
            "INSERT INTO artist_members (artist_id, user_id, member_role)
             VALUES (?, ?, 'owner')
             ON DUPLICATE KEY UPDATE member_role = 'owner'"
        );
        foreach ($artistIds as $artistId) {
            $restore->execute([$artistId, $userId]);
        }
    }

    return ['restored_artist_owner_memberships' => count($artistIds)];
}

function coveted_admin_review_role_request(
    array $admin,
    int $requestId,
    string $decision,
    string $reviewNote = ''
): void {
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    if (!in_array($decision, ['approved', 'declined'], true)) {
        throw new InvalidArgumentException('Invalid review decision.');
    }

    $reviewNote = trim($reviewNote);
    if (mb_strlen($reviewNote) > 500) {
        throw new InvalidArgumentException('Keep the review note under 500 characters.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT rr.*, u.public_id AS user_public_id, u.status AS user_status
             FROM role_requests rr
             JOIN users u ON u.id = rr.user_id
             WHERE rr.id = ?
             FOR UPDATE"
        );
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();

        if (!$request || $request['status'] !== 'pending') {
            throw new InvalidArgumentException('That request is no longer pending.');
        }

        if ($decision === 'approved' && $request['user_status'] !== 'active') {
            throw new InvalidArgumentException('Only active accounts can receive host or partner access.');
        }

        $roleContext = [];
        if ($decision === 'approved') {
            $pdo->prepare(
                'INSERT IGNORE INTO user_roles (user_id, role_key, granted_by) VALUES (?, ?, ?)'
            )->execute([
                (int)$request['user_id'],
                (string)$request['role_key'],
                (int)$admin['id'],
            ]);

            if ($request['role_key'] === 'artist_partner') {
                $roleContext = coveted_admin_restore_artist_partner_owner_memberships_locked(
                    $pdo,
                    (int)$request['user_id']
                );
            }
        }

        $pdo->prepare(
            'UPDATE role_requests
             SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?
             WHERE id = ?'
        )->execute([
            $decision,
            (int)$admin['id'],
            $reviewNote !== '' ? $reviewNote : null,
            $requestId,
        ]);

        coveted_audit(
            'admin.role_request_' . $decision,
            'user',
            (string)$request['user_public_id'],
            [
                'role' => $request['role_key'],
                'request_id' => $request['public_id'],
                ...$roleContext,
            ],
            (int)$admin['id']
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


function coveted_admin_create_user(
    array $admin,
    string $name,
    string $email,
    string $password,
    string $passwordConfirm,
    array $roles = []
): array {
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $name = trim($name);
    $email = strtolower(trim($email));
    $password = (string)$password;
    $passwordConfirm = (string)$passwordConfirm;

    if ($name === '' || mb_strlen($name) > 180 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
        throw new InvalidArgumentException('Enter a valid user name.');
    }
    if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if (strlen($password) < 10) {
        throw new InvalidArgumentException('Use at least 10 characters for the password.');
    }
    if (strlen($password) > 4096) {
        throw new InvalidArgumentException('Password is too long.');
    }
    if (!hash_equals($password, $passwordConfirm)) {
        throw new InvalidArgumentException('Passwords do not match.');
    }

    $allowedRoles = ['attendee_host', 'artist_partner', 'system_admin'];
    $roles = array_values(array_unique(array_filter(
        array_map(static fn(mixed $role): string => trim((string)$role), $roles),
        static fn(string $role): bool => in_array($role, $allowedRoles, true)
    )));

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $existing = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1 FOR UPDATE');
        $existing->execute([$email]);
        if ($existing->fetchColumn()) {
            throw new InvalidArgumentException('A user already exists for that email address.');
        }

        $publicId = coveted_uuid('usr');
        $pdo->prepare(
            "INSERT INTO users (public_id, email, password_hash, display_name, status)
             VALUES (?, ?, ?, ?, 'active')"
        )->execute([
            $publicId,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $name,
        ]);

        $userId = (int)$pdo->lastInsertId();

        $roleStmt = $pdo->prepare(
            'INSERT INTO user_roles (user_id, role_key, granted_by) VALUES (?, ?, ?)'
        );
        $roleStmt->execute([$userId, 'attendee', (int)$admin['id']]);

        foreach ($roles as $role) {
            $roleStmt->execute([$userId, $role, (int)$admin['id']]);
        }

        $pdo->prepare('INSERT INTO profiles (user_id) VALUES (?)')->execute([$userId]);

        coveted_audit(
            'admin.user_created',
            'user',
            $publicId,
            [
                'email' => $email,
                'roles' => array_merge(['attendee'], $roles),
            ],
            (int)$admin['id']
        );

        $pdo->commit();

        return [
            'id' => $userId,
            'public_id' => $publicId,
            'email' => $email,
            'roles' => array_merge(['attendee'], $roles),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            throw new InvalidArgumentException('A user already exists for that email address.');
        }

        throw $e;
    }
}


function coveted_admin_set_user_password(
    array $admin,
    int $userId,
    string $password,
    string $passwordConfirm
): void {
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    if ($userId < 1) {
        throw new InvalidArgumentException('User not found.');
    }

    if (strlen($password) < 10) {
        throw new InvalidArgumentException('Use at least 10 characters for the password.');
    }
    if (strlen($password) > 4096) {
        throw new InvalidArgumentException('Password is too long.');
    }
    if (!hash_equals($password, $passwordConfirm)) {
        throw new InvalidArgumentException('Passwords do not match.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT public_id, email, status FROM users WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$userId]);
        $target = $stmt->fetch();

        if (!$target || $target['status'] === 'deleted') {
            throw new InvalidArgumentException('User not found.');
        }

        $pdo->prepare(
            'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?'
        )->execute([
            password_hash($password, PASSWORD_DEFAULT),
            $userId,
        ]);

        $pdo->prepare(
            'DELETE FROM auth_attempts WHERE attempt_key = ?'
        )->execute([
            hash('sha256', 'login-email|' . strtolower(trim((string)$target['email'])))
        ]);

        coveted_audit(
            'admin.user_password_set',
            'user',
            (string)$target['public_id'],
            [],
            (int)$admin['id']
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_admin_set_user_role(
    array $admin,
    int $userId,
    string $role,
    string $mode
): void {
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $allowedRoles = ['attendee_host', 'artist_partner', 'system_admin'];

    if (!in_array($role, $allowedRoles, true) || !in_array($mode, ['grant', 'revoke'], true)) {
        throw new InvalidArgumentException('Invalid role change.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $adminAccounts = coveted_admin_lock_accounts($pdo);

        $userStmt = $pdo->prepare(
            'SELECT public_id, status FROM users WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $userStmt->execute([$userId]);
        $target = $userStmt->fetch();

        if (!$target) {
            throw new InvalidArgumentException('User not found.');
        }

        if ($target['status'] === 'deleted') {
            throw new InvalidArgumentException('Deleted accounts cannot receive or change roles.');
        }

        if ($role === 'system_admin' && $mode === 'revoke') {
            if ($userId === (int)$admin['id']) {
                throw new InvalidArgumentException('You cannot remove your own System Admin role.');
            }

            $hasAdminRole = false;
            foreach ($adminAccounts as $account) {
                if ((int)$account['user_id'] === $userId) {
                    $hasAdminRole = true;
                    break;
                }
            }

            if (
                $hasAdminRole
                && $target['status'] === 'active'
                && coveted_admin_active_count($adminAccounts) <= 1
            ) {
                throw new InvalidArgumentException('Coveted must keep at least one active System Admin.');
            }
        }

        $cleanup = [];
        if ($mode === 'grant') {
            if ($target['status'] !== 'active') {
                throw new InvalidArgumentException('Only active accounts can receive platform roles.');
            }

            $pdo->prepare(
                'INSERT IGNORE INTO user_roles (user_id, role_key, granted_by) VALUES (?, ?, ?)'
            )->execute([$userId, $role, (int)$admin['id']]);

            if ($role === 'artist_partner') {
                $cleanup = coveted_admin_restore_artist_partner_owner_memberships_locked($pdo, $userId);
            }
        } else {
            if ($role === 'attendee_host') {
                $cleanup = coveted_admin_revoke_attendee_host_locked($pdo, $userId);
            } elseif ($role === 'artist_partner') {
                $cleanup = coveted_admin_revoke_artist_partner_locked($pdo, $userId);
            }

            $pdo->prepare(
                'DELETE FROM user_roles WHERE user_id = ? AND role_key = ?'
            )->execute([$userId, $role]);
        }

        coveted_audit(
            'admin.role_' . $mode,
            'user',
            (string)$target['public_id'],
            ['role' => $role, ...$cleanup],
            (int)$admin['id']
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_admin_set_user_status(array $admin, int $userId, string $status): void
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    if (!in_array($status, ['active', 'suspended'], true)) {
        throw new InvalidArgumentException('Invalid account status.');
    }

    if ($userId === (int)$admin['id'] && $status !== 'active') {
        throw new InvalidArgumentException('You cannot suspend your own account.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $adminAccounts = coveted_admin_lock_accounts($pdo);

        $userStmt = $pdo->prepare(
            'SELECT public_id, status FROM users WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $userStmt->execute([$userId]);
        $target = $userStmt->fetch();

        if (!$target) {
            throw new InvalidArgumentException('User not found.');
        }

        if (!in_array($target['status'], ['active', 'suspended'], true)) {
            throw new InvalidArgumentException('That account state cannot be changed from this Admin action.');
        }

        if ($status === 'suspended' && $target['status'] === 'active') {
            $targetIsAdmin = false;
            foreach ($adminAccounts as $account) {
                if ((int)$account['user_id'] === $userId) {
                    $targetIsAdmin = true;
                    break;
                }
            }

            if ($targetIsAdmin && coveted_admin_active_count($adminAccounts) <= 1) {
                throw new InvalidArgumentException('Coveted must keep at least one active System Admin.');
            }
        }

        $pdo->prepare(
            'UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$status, $userId]);

        coveted_audit(
            'admin.user_status',
            'user',
            (string)$target['public_id'],
            ['status' => $status],
            (int)$admin['id']
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_admin_set_group_status(array $admin, int $groupId, string $status): void
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    if (!in_array($status, ['active', 'paused', 'archived'], true)) {
        throw new InvalidArgumentException('Invalid group status.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $groupStmt = $pdo->prepare(
            'SELECT public_id FROM social_groups WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $groupStmt->execute([$groupId]);
        $groupPublicId = $groupStmt->fetchColumn();

        if (!$groupPublicId) {
            throw new InvalidArgumentException('Group not found.');
        }

        $pdo->prepare(
            'UPDATE social_groups SET status = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$status, $groupId]);

        coveted_audit(
            'admin.group_status',
            'group',
            (string)$groupPublicId,
            ['status' => $status],
            (int)$admin['id']
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
