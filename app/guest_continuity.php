<?php
declare(strict_types=1);

require_once __DIR__ . '/groups.php';

/**
 * Return active Guests who have verified attendance at at least one completed
 * gathering for this group. Identity stays inside the Group Admin workspace;
 * this is not a ranking or recommendation score.
 *
 * @return array<int,array<string,mixed>>
 */
function coveted_group_guest_continuity_candidates(array $group, array $actor): array
{
    if (!coveted_group_can_admin($group, $actor)) {
        throw new InvalidArgumentException('Group Admin access is required.');
    }

    $stmt = coveted_db()->prepare(
        "SELECT
            gm.user_id,
            gm.joined_at,
            u.display_name,
            p.avatar_url,
            COUNT(DISTINCT e.id) AS verified_gatherings,
            MAX(e.starts_at) AS last_attended_at,
            EXISTS (
                SELECT 1
                FROM group_invitations gi
                WHERE gi.group_id = gm.group_id
                  AND gi.invitee_user_id = gm.user_id
                  AND gi.public_id LIKE 'gstay\\_%'
                  AND gi.status = 'pending'
                  AND (gi.expires_at IS NULL OR gi.expires_at > NOW())
            ) AS stay_invite_pending
         FROM group_memberships gm
         JOIN users u ON u.id = gm.user_id AND u.status = 'active'
         LEFT JOIN profiles p ON p.user_id = u.id
         JOIN event_attendance ea
           ON ea.user_id = gm.user_id
          AND ea.status IN ('checked_in','attended','left_early')
         JOIN events e
           ON e.id = ea.event_id
          AND e.group_id = gm.group_id
          AND e.status = 'completed'
         WHERE gm.group_id = ?
           AND gm.membership_status = 'active'
           AND gm.group_role = 'guest'
         GROUP BY gm.group_id, gm.user_id, gm.joined_at, u.display_name, p.avatar_url
         ORDER BY stay_invite_pending ASC, last_attended_at DESC, u.display_name"
    );
    $stmt->execute([(int)$group['id']]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['user_id'] = (int)$row['user_id'];
        $row['verified_gatherings'] = (int)$row['verified_gatherings'];
        $row['stay_invite_pending'] = (bool)$row['stay_invite_pending'];
    }
    unset($row);

    return $rows;
}

/** @return array{guest_passes_used:int,eligible_guests:int,pending_stay_invites:int,member_conversions:int,conversion_rate:float} */
function coveted_group_guest_continuity_summary(array $group, array $actor): array
{
    if (!coveted_group_can_admin($group, $actor)) {
        throw new InvalidArgumentException('Group Admin access is required.');
    }

    $pdo = coveted_db();
    $usedStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM group_guest_passes
         WHERE group_id = ? AND status = 'used'"
    );
    $usedStmt->execute([(int)$group['id']]);
    $guestPassesUsed = (int)$usedStmt->fetchColumn();

    $pendingStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM group_invitations gi
         JOIN group_memberships gm
           ON gm.group_id = gi.group_id
          AND gm.user_id = gi.invitee_user_id
          AND gm.membership_status = 'active'
          AND gm.group_role = 'guest'
         WHERE gi.group_id = ?
           AND gi.public_id LIKE 'gstay\\_%'
           AND gi.status = 'pending'
           AND (gi.expires_at IS NULL OR gi.expires_at > NOW())
           AND EXISTS (
               SELECT 1
               FROM event_attendance ea
               JOIN events e ON e.id = ea.event_id
               WHERE ea.user_id = gm.user_id
                 AND ea.status IN ('checked_in','attended','left_early')
                 AND e.group_id = gm.group_id
                 AND e.status = 'completed'
           )"
    );
    $pendingStmt->execute([(int)$group['id']]);
    $pending = (int)$pendingStmt->fetchColumn();

    $conversionStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM group_invitations
         WHERE group_id = ?
           AND public_id LIKE 'gstay\\_%'
           AND status = 'accepted'"
    );
    $conversionStmt->execute([(int)$group['id']]);
    $conversions = (int)$conversionStmt->fetchColumn();

    $candidates = coveted_group_guest_continuity_candidates($group, $actor);

    return [
        'guest_passes_used' => $guestPassesUsed,
        'eligible_guests' => count($candidates),
        'pending_stay_invites' => $pending,
        'member_conversions' => $conversions,
        'conversion_rate' => $guestPassesUsed > 0
            ? round(($conversions / $guestPassesUsed) * 100, 1)
            : 0.0,
    ];
}

/** @return array{public_id:string,token:string,url:string} */
function coveted_group_create_stay_invitation(array $group, array $actor, int $guestUserId): array
{
    if ($guestUserId < 1) {
        throw new InvalidArgumentException('Choose an eligible guest.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        coveted_group_assert_active_locked($pdo, (int)$group['id']);
        $memberships = coveted_group_lock_memberships($pdo, (int)$group['id']);
        coveted_group_require_admin_locked($memberships, $actor);

        $membership = coveted_group_locked_membership($memberships, $guestUserId);
        if (!$membership
            || $membership['membership_status'] !== 'active'
            || $membership['group_role'] !== 'guest') {
            throw new InvalidArgumentException('Only an active Guest can receive an Invite to Stay.');
        }

        if (!coveted_group_guest_has_verified_completed_attendance(
            $pdo,
            (int)$group['id'],
            $guestUserId
        )) {
            throw new InvalidArgumentException('Invite to Stay requires verified attendance at a completed gathering.');
        }

        $userStmt = $pdo->prepare(
            "SELECT email
             FROM users
             WHERE id = ? AND status = 'active'
             LIMIT 1 FOR UPDATE"
        );
        $userStmt->execute([$guestUserId]);
        $email = $userStmt->fetchColumn();
        if (!is_string($email) || $email === '') {
            throw new InvalidArgumentException('Guest account is unavailable.');
        }

        $pendingStmt = $pdo->prepare(
            "SELECT 1
             FROM group_invitations
             WHERE group_id = ?
               AND invitee_user_id = ?
               AND public_id LIKE 'gstay\\_%'
               AND status = 'pending'
               AND (expires_at IS NULL OR expires_at > NOW())
             LIMIT 1 FOR UPDATE"
        );
        $pendingStmt->execute([(int)$group['id'], $guestUserId]);
        if ($pendingStmt->fetchColumn()) {
            throw new InvalidArgumentException('That Guest already has an active Invite to Stay.');
        }

        $token = bin2hex(random_bytes(24));
        $publicId = coveted_uuid('gstay');
        $expiresAt = (new DateTimeImmutable('+14 days', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');

        $pdo->prepare(
            "INSERT INTO group_invitations
                (public_id, group_id, inviter_user_id, invitee_email, invitee_user_id, invite_token_hash, status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)"
        )->execute([
            $publicId,
            (int)$group['id'],
            (int)$actor['id'],
            strtolower($email),
            $guestUserId,
            password_hash($token, PASSWORD_DEFAULT),
            $expiresAt,
        ]);

        coveted_group_event(
            (int)$group['id'],
            (int)$actor['id'],
            'guest.stay_invited',
            $guestUserId,
            ['invitation_id' => $publicId]
        );
        coveted_audit(
            'group.guest_stay_invited',
            'group',
            (string)$group['public_id'],
            ['guest_user_id' => $guestUserId, 'invitation_id' => $publicId],
            (int)$actor['id']
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
