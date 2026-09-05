<?php
declare(strict_types=1);

require_once __DIR__ . '/events.php';

function coveted_group_by_ref(string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '' || strlen($ref) > 64) {
        return null;
    }

    $stmt = coveted_db()->prepare(
        "SELECT g.*, u.display_name AS creator_name,
                (SELECT COUNT(*) FROM group_memberships gm
                 WHERE gm.group_id = g.id
                   AND gm.membership_status = 'active'
                   AND gm.group_role <> 'guest') AS member_count
         FROM social_groups g
         JOIN users u ON u.id = g.created_by
         WHERE g.public_id = ? OR CAST(g.id AS CHAR) = ?
         LIMIT 1"
    );
    $stmt->execute([$ref, $ref]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function coveted_group_membership(int $groupId, int $userId): ?array
{
    $stmt = coveted_db()->prepare(
        'SELECT * FROM group_memberships WHERE group_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$groupId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function coveted_group_actor_has_host_approval(array $user): bool
{
    return coveted_is_system_admin($user)
        || in_array('attendee_host', (array)($user['roles'] ?? []), true);
}

function coveted_group_can_view(array $group, array $user): bool
{
    if (coveted_is_system_admin($user)) {
        return true;
    }

    $membership = coveted_group_membership((int)$group['id'], (int)$user['id']);
    if ($membership && in_array($membership['membership_status'], ['active', 'invited'], true)) {
        return true;
    }

    return ($group['visibility'] ?? '') === 'unlisted' && ($group['status'] ?? '') !== 'archived';
}

function coveted_group_can_admin(array $group, array $user): bool
{
    if (!coveted_group_actor_has_host_approval($user)) {
        return false;
    }

    if (coveted_is_system_admin($user)) {
        return true;
    }

    $membership = coveted_group_membership((int)$group['id'], (int)$user['id']);
    return $membership
        && $membership['membership_status'] === 'active'
        && $membership['group_role'] === 'group_admin';
}

function coveted_group_can_host(array $group, array $user): bool
{
    if (!coveted_group_actor_has_host_approval($user)) {
        return false;
    }

    if (coveted_is_system_admin($user)) {
        return true;
    }

    $membership = coveted_group_membership((int)$group['id'], (int)$user['id']);
    return $membership
        && $membership['membership_status'] === 'active'
        && in_array($membership['group_role'], ['host', 'group_admin'], true);
}

function coveted_group_event(
    int $groupId,
    int $actorId,
    string $eventType,
    ?int $subjectUserId = null,
    array $context = []
): void {
    coveted_db()->prepare(
        'INSERT INTO group_admin_events
            (public_id, group_id, actor_user_id, event_type, subject_user_id, context_json)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        coveted_uuid('gae'),
        $groupId,
        $actorId,
        $eventType,
        $subjectUserId,
        $context ? coveted_json($context) : null,
    ]);
}

function coveted_group_assert_active_locked(PDO $pdo, int $groupId): void
{
    $stmt = $pdo->prepare('SELECT status FROM social_groups WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$groupId]);
    if ($stmt->fetchColumn() !== 'active') {
        throw new InvalidArgumentException('This group is not currently active.');
    }
}

/** @return array<int,array<string,mixed>> */
function coveted_group_lock_memberships(PDO $pdo, int $groupId): array
{
    $stmt = $pdo->prepare(
        'SELECT user_id, group_role, membership_status
         FROM group_memberships
         WHERE group_id = ?
         ORDER BY user_id
         FOR UPDATE'
    );
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

function coveted_group_locked_membership(array $memberships, int $userId): ?array
{
    foreach ($memberships as $membership) {
        if ((int)$membership['user_id'] === $userId) {
            return $membership;
        }
    }
    return null;
}

function coveted_group_actor_is_admin(array $memberships, array $actor): bool
{
    if (!coveted_group_actor_has_host_approval($actor)) {
        return false;
    }

    if (coveted_is_system_admin($actor)) {
        return true;
    }

    $membership = coveted_group_locked_membership($memberships, (int)$actor['id']);
    return $membership
        && $membership['membership_status'] === 'active'
        && $membership['group_role'] === 'group_admin';
}

function coveted_group_require_admin_locked(array $memberships, array $actor): void
{
    if (!coveted_group_actor_is_admin($memberships, $actor)) {
        throw new InvalidArgumentException('Group Admin access is required.');
    }
}

function coveted_group_user_can_hold_host_role_locked(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM users u
         WHERE u.id = ? AND u.status = 'active'
           AND EXISTS (
               SELECT 1 FROM user_roles ur
               WHERE ur.user_id = u.id AND ur.role_key IN ('attendee_host','system_admin')
           )
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
}

function coveted_group_validate_invite_email(string $email): string
{
    $email = strtolower(trim($email));
    if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    return $email;
}

function coveted_group_assert_invitable(PDO $pdo, int $groupId, ?int $inviteeId, string $email): void
{
    if ($inviteeId !== null) {
        $membership = $pdo->prepare(
            'SELECT membership_status FROM group_memberships WHERE group_id = ? AND user_id = ? LIMIT 1'
        );
        $membership->execute([$groupId, $inviteeId]);
        $status = $membership->fetchColumn();
        if (in_array($status, ['active', 'invited', 'away'], true)) {
            throw new InvalidArgumentException('That person already belongs to this group.');
        }
    }

    $pendingInvite = $pdo->prepare(
        "SELECT 1 FROM group_invitations
         WHERE group_id = ? AND invitee_email = ? AND status = 'pending'
           AND (expires_at IS NULL OR expires_at > NOW())
         LIMIT 1"
    );
    $pendingInvite->execute([$groupId, $email]);
    if ($pendingInvite->fetchColumn()) {
        throw new InvalidArgumentException('That person already has an active invitation.');
    }
}

function coveted_group_guest_has_verified_completed_attendance(PDO $pdo, int $groupId, int $userId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM event_attendance ea
         JOIN events e ON e.id = ea.event_id
         WHERE ea.user_id = ?
           AND ea.status IN ('checked_in','attended','left_early')
           AND e.group_id = ?
           AND e.status = 'completed'
         LIMIT 1"
    );
    $stmt->execute([$userId, $groupId]);
    return (bool)$stmt->fetchColumn();
}

function coveted_group_release_stale_guest_pass_reservations(PDO $pdo, int $groupId, int $ownerUserId): void
{
    $stmt = $pdo->prepare(
        "SELECT gp.id, gp.expires_at AS pass_expires_at, gi.id AS invitation_id,
                gi.status AS invitation_status, gi.expires_at AS invitation_expires_at
         FROM group_guest_passes gp
         LEFT JOIN group_invitations gi ON gi.id = gp.invitation_id
         WHERE gp.group_id = ? AND gp.issued_to_user_id = ? AND gp.status = 'reserved'
         ORDER BY gp.id FOR UPDATE"
    );
    $stmt->execute([$groupId, $ownerUserId]);

    foreach ($stmt->fetchAll() as $row) {
        $passExpired = !empty($row['pass_expires_at']) && strtotime((string)$row['pass_expires_at']) <= time();
        $inviteExpired = !empty($row['invitation_expires_at']) && strtotime((string)$row['invitation_expires_at']) <= time();
        $inviteInactive = $row['invitation_id'] === null || $row['invitation_status'] !== 'pending';
        if (!$passExpired && !$inviteExpired && !$inviteInactive) {
            continue;
        }

        if ($row['invitation_id'] !== null && $inviteExpired && $row['invitation_status'] === 'pending') {
            $pdo->prepare("UPDATE group_invitations SET status = 'expired' WHERE id = ? AND status = 'pending'")
                ->execute([(int)$row['invitation_id']]);
        }

        $pdo->prepare(
            "UPDATE group_guest_passes
             SET status = ?, guest_email = NULL, guest_user_id = NULL,
                 invitation_id = NULL, used_at = NULL
             WHERE id = ?"
        )->execute([$passExpired ? 'expired' : 'available', (int)$row['id']]);
    }
}

function coveted_create_group(
    array $user,
    string $name,
    string $description,
    string $city,
    string $visibility
): array {
    if (!coveted_group_actor_has_host_approval($user)) {
        throw new InvalidArgumentException('Host approval is required before creating a group.');
    }

    $name = trim($name);
    $description = trim($description);
    $city = trim($city);
    $visibility = trim($visibility);
    if ($name === '' || mb_strlen($name) > 180) {
        throw new InvalidArgumentException('Enter a group name.');
    }
    if (mb_strlen($description) > 2000) {
        throw new InvalidArgumentException('Keep the group description under 2,000 characters.');
    }
    if (mb_strlen($city) > 160) {
        throw new InvalidArgumentException('Keep the city under 160 characters.');
    }
    if (!in_array($visibility, ['private', 'invite_only', 'unlisted'], true)) {
        $visibility = 'invite_only';
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $publicId = coveted_uuid('grp');
        $pdo->prepare(
            "INSERT INTO social_groups
                (public_id, name, description, city, visibility, status, created_by)
             VALUES (?, ?, ?, ?, ?, 'active', ?)"
        )->execute([
            $publicId,
            $name,
            $description !== '' ? $description : null,
            $city !== '' ? $city : null,
            $visibility,
            (int)$user['id'],
        ]);
        $groupId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO group_memberships
                (group_id, user_id, group_role, membership_status, joined_at)
             VALUES (?, ?, 'group_admin', 'active', NOW())"
        )->execute([$groupId, (int)$user['id']]);

        coveted_group_event($groupId, (int)$user['id'], 'group.created', (int)$user['id']);
        coveted_audit('group.created', 'group', $publicId, ['name' => $name], (int)$user['id']);
        $pdo->commit();
        return ['id' => $groupId, 'public_id' => $publicId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_group_update_member_role(array $group, array $actor, int $memberId, string $role): void
{
    if (!in_array($role, ['member', 'host', 'group_admin'], true)) {
        throw new InvalidArgumentException('Invalid group role.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        coveted_group_assert_active_locked($pdo, (int)$group['id']);
        $memberships = coveted_group_lock_memberships($pdo, (int)$group['id']);
        coveted_group_require_admin_locked($memberships, $actor);

        $target = coveted_group_locked_membership($memberships, $memberId);
        if (!$target || $target['membership_status'] !== 'active') {
            throw new InvalidArgumentException('Active member not found.');
        }
        if ($target['group_role'] === 'guest') {
            throw new InvalidArgumentException('A Guest becomes a Member only by accepting an Invite to Stay.');
        }

        if (in_array($role, ['host', 'group_admin'], true)
            && !coveted_group_user_can_hold_host_role_locked($pdo, $memberId)) {
            throw new InvalidArgumentException('Host and Group Admin roles require Attendee Host approval.');
        }

        if ($target['group_role'] === 'group_admin' && $role !== 'group_admin') {
            $adminCount = 0;
            foreach ($memberships as $membership) {
                if ($membership['membership_status'] === 'active' && $membership['group_role'] === 'group_admin') {
                    $adminCount++;
                }
            }
            if ($adminCount <= 1) {
                throw new InvalidArgumentException('Every group must keep at least one Group Admin.');
            }
        }

        $pdo->prepare(
            'UPDATE group_memberships SET group_role = ?, updated_at = NOW() WHERE group_id = ? AND user_id = ?'
        )->execute([$role, (int)$group['id'], $memberId]);

        coveted_group_event(
            (int)$group['id'],
            (int)$actor['id'],
            'member.role_changed',
            $memberId,
            ['role' => $role]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_group_remove_member(array $group, array $actor, int $memberId): void
{
    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        coveted_group_assert_active_locked($pdo, (int)$group['id']);
        $memberships = coveted_group_lock_memberships($pdo, (int)$group['id']);
        coveted_group_require_admin_locked($memberships, $actor);

        $target = coveted_group_locked_membership($memberships, $memberId);
        if (!$target || $target['membership_status'] !== 'active') {
            throw new InvalidArgumentException('Active member not found.');
        }

        if ($target['group_role'] === 'group_admin') {
            $adminCount = 0;
            foreach ($memberships as $membership) {
                if ($membership['membership_status'] === 'active' && $membership['group_role'] === 'group_admin') {
                    $adminCount++;
                }
            }
            if ($adminCount <= 1) {
                throw new InvalidArgumentException('Every group must keep at least one Group Admin.');
            }
        }

        $pdo->prepare(
            "UPDATE group_memberships
             SET membership_status = 'removed', group_role = 'member', updated_at = NOW()
             WHERE group_id = ? AND user_id = ?"
        )->execute([(int)$group['id'], $memberId]);

        $cleanup = coveted_event_revoke_group_member_future_access_locked(
            $pdo,
            (int)$group['id'],
            $memberId,
            (int)$actor['id']
        );

        coveted_group_event(
            (int)$group['id'],
            (int)$actor['id'],
            'member.removed',
            $memberId,
            $cleanup
        );
        coveted_audit(
            'group.member_removed',
            'group',
            (string)$group['public_id'],
            ['user_id' => $memberId, ...$cleanup],
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

function coveted_group_issue_guest_pass(array $group, array $actor, int $memberId): void
{
    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        coveted_group_assert_active_locked($pdo, (int)$group['id']);
        $memberships = coveted_group_lock_memberships($pdo, (int)$group['id']);
        coveted_group_require_admin_locked($memberships, $actor);

        $target = coveted_group_locked_membership($memberships, $memberId);
        if (!$target || $target['membership_status'] !== 'active') {
            throw new InvalidArgumentException('Choose an active member.');
        }
        if ($target['group_role'] === 'guest') {
            throw new InvalidArgumentException('Guest Passes can be issued only to Members or Hosts.');
        }

        $pdo->prepare(
            "INSERT INTO group_guest_passes
                (public_id, group_id, issued_to_user_id, issued_by_user_id, status, expires_at)
             VALUES (?, ?, ?, ?, 'available', DATE_ADD(NOW(), INTERVAL 90 DAY))"
        )->execute([
            coveted_uuid('gpass'),
            (int)$group['id'],
            $memberId,
            (int)$actor['id'],
        ]);

        coveted_group_event((int)$group['id'], (int)$actor['id'], 'guest_pass.issued', $memberId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return array{public_id:string,token:string,url:string} */
function coveted_group_create_invitation(
    array $group,
    array $actor,
    string $email,
    bool $useGuestPass = false
): array {
    $email = coveted_group_validate_invite_email($email);
    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        coveted_group_assert_active_locked($pdo, (int)$group['id']);
        $memberships = coveted_group_lock_memberships($pdo, (int)$group['id']);

        if ($useGuestPass) {
            $actorMembership = coveted_group_locked_membership($memberships, (int)$actor['id']);
            if (!$actorMembership || $actorMembership['membership_status'] !== 'active') {
                throw new InvalidArgumentException('Active membership is required.');
            }
            coveted_group_release_stale_guest_pass_reservations($pdo, (int)$group['id'], (int)$actor['id']);
        } else {
            coveted_group_require_admin_locked($memberships, $actor);
        }

        $existingUser = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $existingUser->execute([$email]);
        $inviteeId = $existingUser->fetchColumn();
        $inviteeId = $inviteeId !== false ? (int)$inviteeId : null;
        coveted_group_assert_invitable($pdo, (int)$group['id'], $inviteeId, $email);

        $guestPass = null;
        if ($useGuestPass) {
            $passStmt = $pdo->prepare(
                "SELECT id, expires_at FROM group_guest_passes
                 WHERE group_id = ? AND issued_to_user_id = ? AND status = 'available'
                   AND (expires_at IS NULL OR expires_at > NOW())
                 ORDER BY created_at, id LIMIT 1 FOR UPDATE"
            );
            $passStmt->execute([(int)$group['id'], (int)$actor['id']]);
            $guestPass = $passStmt->fetch();
            if (!$guestPass) {
                throw new InvalidArgumentException('You do not have an available guest pass.');
            }
        }

        $token = bin2hex(random_bytes(24));
        $publicId = coveted_uuid('ginv');
        $inviteExpiresAt = new DateTimeImmutable('+14 days', new DateTimeZone('UTC'));
        if ($guestPass && !empty($guestPass['expires_at'])) {
            $passExpiry = coveted_utc_datetime((string)$guestPass['expires_at']);
            if ($passExpiry < $inviteExpiresAt) {
                $inviteExpiresAt = $passExpiry;
            }
        }

        $pdo->prepare(
            "INSERT INTO group_invitations
                (public_id, group_id, inviter_user_id, invitee_email, invitee_user_id, invite_token_hash, status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)"
        )->execute([
            $publicId,
            (int)$group['id'],
            (int)$actor['id'],
            $email,
            $inviteeId,
            password_hash($token, PASSWORD_DEFAULT),
            $inviteExpiresAt->format('Y-m-d H:i:s'),
        ]);
        $invitationId = (int)$pdo->lastInsertId();

        if ($guestPass) {
            $reserve = $pdo->prepare(
                "UPDATE group_guest_passes
                 SET status = 'reserved', guest_email = ?, guest_user_id = ?, invitation_id = ?, used_at = NULL
                 WHERE id = ? AND status = 'available'"
            );
            $reserve->execute([$email, $inviteeId, $invitationId, (int)$guestPass['id']]);
            if ($reserve->rowCount() !== 1) {
                throw new RuntimeException('Unable to reserve the guest pass.');
            }
        }

        coveted_group_event(
            (int)$group['id'],
            (int)$actor['id'],
            $useGuestPass ? 'guest_pass.reserved' : 'member.invited',
            $inviteeId,
            ['email' => $email, 'invitation_id' => $publicId]
        );
        $pdo->commit();

        return [
            'public_id' => $publicId,
            'token' => $token,
            'url' => coveted_url('/group-invite.php?id=' . rawurlencode($publicId) . '&token=' . rawurlencode($token)),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Apply a private group invitation response.
 *
 * Member invitations create/reactivate normal Members. Guest Pass invitations
 * create active Guests. A gstay_* Invite to Stay is valid only for an existing
 * active Guest with current verified completed attendance and is the sole path
 * that upgrades that Guest to Member.
 *
 * @return string Public group id.
 */
function coveted_group_respond_invitation(
    array $user,
    string $invitationPublicId,
    string $token,
    string $action
): string {
    $invitationPublicId = trim($invitationPublicId);
    $token = trim($token);
    if ($invitationPublicId === '' || strlen($invitationPublicId) > 64
        || preg_match('/^[a-f0-9]{48}$/i', $token) !== 1) {
        throw new InvalidArgumentException('Invitation is no longer available.');
    }
    if (!in_array($action, ['accept', 'decline'], true)) {
        throw new InvalidArgumentException('Unsupported invitation action.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT gi.*, g.status AS group_status, g.public_id AS group_public_id
             FROM group_invitations gi
             JOIN social_groups g ON g.id = gi.group_id
             WHERE gi.public_id = ? LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$invitationPublicId]);
        $invite = $stmt->fetch();

        if (!$invite
            || $invite['status'] !== 'pending'
            || $invite['group_status'] !== 'active'
            || (!empty($invite['expires_at']) && strtotime((string)$invite['expires_at']) <= time())
            || !password_verify($token, (string)$invite['invite_token_hash'])) {
            throw new InvalidArgumentException('Invitation is no longer available.');
        }
        if (!empty($invite['invitee_email'])
            && strtolower((string)$invite['invitee_email']) !== strtolower((string)$user['email'])) {
            throw new InvalidArgumentException('This invitation belongs to a different account.');
        }

        $passStmt = $pdo->prepare(
            'SELECT * FROM group_guest_passes WHERE invitation_id = ? LIMIT 1 FOR UPDATE'
        );
        $passStmt->execute([(int)$invite['id']]);
        $guestPass = $passStmt->fetch();
        if ($guestPass && $guestPass['status'] !== 'reserved') {
            throw new InvalidArgumentException('This guest invitation is no longer available.');
        }

        $isStayInvitation = str_starts_with((string)$invite['public_id'], 'gstay_');
        $membershipStmt = $pdo->prepare(
            'SELECT group_role, membership_status, joined_at
             FROM group_memberships
             WHERE group_id = ? AND user_id = ?
             LIMIT 1 FOR UPDATE'
        );
        $membershipStmt->execute([(int)$invite['group_id'], (int)$user['id']]);
        $existingMembership = $membershipStmt->fetch() ?: null;

        if ($isStayInvitation) {
            if ($guestPass) {
                throw new InvalidArgumentException('Invite to Stay cannot consume a Guest Pass.');
            }
            if ((int)($invite['invitee_user_id'] ?? 0) !== (int)$user['id']) {
                throw new InvalidArgumentException('This Invite to Stay belongs to a different account.');
            }
            if (!$existingMembership
                || $existingMembership['membership_status'] !== 'active'
                || $existingMembership['group_role'] !== 'guest') {
                throw new InvalidArgumentException('Invite to Stay is available only to an active Guest.');
            }
            if (!coveted_group_guest_has_verified_completed_attendance(
                $pdo,
                (int)$invite['group_id'],
                (int)$user['id']
            )) {
                throw new InvalidArgumentException('Invite to Stay requires current verified attendance at a completed gathering.');
            }
        }

        if ($action === 'accept') {
            if ($isStayInvitation) {
                $convert = $pdo->prepare(
                    "UPDATE group_memberships
                     SET group_role = 'member', updated_at = NOW()
                     WHERE group_id = ? AND user_id = ?
                       AND membership_status = 'active' AND group_role = 'guest'"
                );
                $convert->execute([(int)$invite['group_id'], (int)$user['id']]);
                if ($convert->rowCount() !== 1) {
                    throw new RuntimeException('Unable to convert Guest membership.');
                }
            } else {
                $targetRole = $guestPass ? 'guest' : 'member';
                $pdo->prepare(
                    "INSERT INTO group_memberships
                        (group_id, user_id, group_role, membership_status, invited_by, joined_at)
                     VALUES (?, ?, ?, 'active', ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        membership_status = 'active', group_role = VALUES(group_role),
                        invited_by = VALUES(invited_by), joined_at = NOW(), updated_at = NOW()"
                )->execute([
                    (int)$invite['group_id'],
                    (int)$user['id'],
                    $targetRole,
                    (int)$invite['inviter_user_id'],
                ]);
            }

            $pdo->prepare(
                "UPDATE group_invitations
                 SET status = 'accepted', invitee_user_id = ?, accepted_at = NOW()
                 WHERE id = ?"
            )->execute([(int)$user['id'], (int)$invite['id']]);

            if ($guestPass) {
                $consume = $pdo->prepare(
                    "UPDATE group_guest_passes
                     SET status = 'used', guest_user_id = ?, used_at = NOW()
                     WHERE id = ? AND status = 'reserved'"
                );
                $consume->execute([(int)$user['id'], (int)$guestPass['id']]);
                if ($consume->rowCount() !== 1) {
                    throw new RuntimeException('Unable to consume the guest pass.');
                }
            }

            $eventType = $isStayInvitation
                ? 'guest.became_member'
                : ($guestPass ? 'guest.joined' : 'invitation.accepted');
            coveted_group_event(
                (int)$invite['group_id'],
                (int)$user['id'],
                $eventType,
                (int)$user['id'],
                ['invitation_id' => $invite['public_id']]
            );
            if ($isStayInvitation) {
                coveted_audit(
                    'group.guest_became_member',
                    'group',
                    (string)$invite['group_public_id'],
                    ['user_id' => (int)$user['id'], 'invitation_id' => (string)$invite['public_id']],
                    (int)$user['id']
                );
            }
        } else {
            $pdo->prepare(
                "UPDATE group_invitations SET status = 'declined', invitee_user_id = ? WHERE id = ?"
            )->execute([(int)$user['id'], (int)$invite['id']]);

            if ($guestPass) {
                $release = $pdo->prepare(
                    "UPDATE group_guest_passes
                     SET status = 'available', guest_email = NULL, guest_user_id = NULL,
                         invitation_id = NULL, used_at = NULL
                     WHERE id = ? AND status = 'reserved'"
                );
                $release->execute([(int)$guestPass['id']]);
                if ($release->rowCount() !== 1) {
                    throw new RuntimeException('Unable to release the guest pass.');
                }
            }

            coveted_group_event(
                (int)$invite['group_id'],
                (int)$user['id'],
                $isStayInvitation ? 'guest.stay_declined' : 'invitation.declined',
                (int)$user['id'],
                ['invitation_id' => $invite['public_id']]
            );
        }

        $pdo->commit();
        return (string)$invite['group_public_id'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
