<?php
declare(strict_types=1);

require_once __DIR__ . '/groups.php';

/**
 * Reconcile time-driven V1 state that would otherwise remain semantically
 * stale until a member happens to touch the related record.
 *
 * This is the single canonical reconciliation path for invitation expiry and
 * Guest Pass reservation release. It is deliberately bounded and idempotent so
 * it can run repeatedly from a scheduler without creating a second lifecycle.
 *
 * @return array{
 *   group_invitations_expired:int,
 *   event_invitations_expired:int,
 *   guest_passes_released:int,
 *   guest_passes_expired:int,
 *   more_work_possible:bool
 * }
 */
function coveted_lifecycle_reconcile_batch(int $limit = 250): array
{
    $limit = max(1, min($limit, 1000));
    $pdo = coveted_db();
    $pdo->beginTransaction();

    $summary = [
        'group_invitations_expired' => 0,
        'event_invitations_expired' => 0,
        'guest_passes_released' => 0,
        'guest_passes_expired' => 0,
        'more_work_possible' => false,
    ];

    try {
        $groupInvites = $pdo->query(
            "SELECT id, public_id
             FROM group_invitations
             WHERE status = 'pending'
               AND expires_at IS NOT NULL
               AND expires_at <= NOW()
             ORDER BY id
             LIMIT {$limit}
             FOR UPDATE"
        )->fetchAll();

        $expireGroupInvite = $pdo->prepare(
            "UPDATE group_invitations
             SET status = 'expired'
             WHERE id = ? AND status = 'pending'"
        );
        foreach ($groupInvites as $invite) {
            $expireGroupInvite->execute([(int)$invite['id']]);
            $summary['group_invitations_expired'] += $expireGroupInvite->rowCount();
        }

        $reservedPasses = $pdo->query(
            "SELECT
                gp.id,
                gp.expires_at AS pass_expires_at,
                gp.invitation_id,
                gi.status AS invitation_status,
                gi.expires_at AS invitation_expires_at
             FROM group_guest_passes gp
             LEFT JOIN group_invitations gi ON gi.id = gp.invitation_id
             WHERE gp.status = 'reserved'
               AND (
                    (gp.expires_at IS NOT NULL AND gp.expires_at <= NOW())
                    OR gi.id IS NULL
                    OR gi.status <> 'pending'
                    OR (gi.expires_at IS NOT NULL AND gi.expires_at <= NOW())
               )
             ORDER BY gp.id
             LIMIT {$limit}
             FOR UPDATE"
        )->fetchAll();

        $releasePass = $pdo->prepare(
            "UPDATE group_guest_passes
             SET status = ?, guest_email = NULL, guest_user_id = NULL,
                 invitation_id = NULL, used_at = NULL
             WHERE id = ? AND status = 'reserved'"
        );
        foreach ($reservedPasses as $pass) {
            if ($pass['invitation_id'] !== null
                && (string)($pass['invitation_status'] ?? '') === 'pending'
                && !empty($pass['invitation_expires_at'])
                && strtotime((string)$pass['invitation_expires_at']) <= time()) {
                $expireGroupInvite->execute([(int)$pass['invitation_id']]);
                $summary['group_invitations_expired'] += $expireGroupInvite->rowCount();
            }

            $passExpired = !empty($pass['pass_expires_at'])
                && strtotime((string)$pass['pass_expires_at']) <= time();
            $releasePass->execute([$passExpired ? 'expired' : 'available', (int)$pass['id']]);
            if ($releasePass->rowCount() !== 1) {
                continue;
            }
            if ($passExpired) {
                $summary['guest_passes_expired']++;
            } else {
                $summary['guest_passes_released']++;
            }
        }

        $availablePasses = $pdo->query(
            "SELECT id
             FROM group_guest_passes
             WHERE status = 'available'
               AND expires_at IS NOT NULL
               AND expires_at <= NOW()
             ORDER BY id
             LIMIT {$limit}
             FOR UPDATE"
        )->fetchAll();
        $expirePass = $pdo->prepare(
            "UPDATE group_guest_passes
             SET status = 'expired'
             WHERE id = ? AND status = 'available'"
        );
        foreach ($availablePasses as $pass) {
            $expirePass->execute([(int)$pass['id']]);
            $summary['guest_passes_expired'] += $expirePass->rowCount();
        }

        $eventInvites = $pdo->query(
            "SELECT ei.id, ei.public_id
             FROM event_invitations ei
             JOIN events e ON e.id = ei.event_id
             WHERE ei.status = 'pending'
               AND (
                    e.starts_at <= NOW()
                    OR e.status IN ('completed','cancelled')
               )
             ORDER BY ei.id
             LIMIT {$limit}
             FOR UPDATE"
        )->fetchAll();
        $expireEventInvite = $pdo->prepare(
            "UPDATE event_invitations
             SET status = 'expired'
             WHERE id = ? AND status = 'pending'"
        );
        foreach ($eventInvites as $invite) {
            $expireEventInvite->execute([(int)$invite['id']]);
            $summary['event_invitations_expired'] += $expireEventInvite->rowCount();
        }

        $summary['more_work_possible'] = count($groupInvites) >= $limit
            || count($reservedPasses) >= $limit
            || count($availablePasses) >= $limit
            || count($eventInvites) >= $limit;

        $changed = $summary['group_invitations_expired']
            + $summary['event_invitations_expired']
            + $summary['guest_passes_released']
            + $summary['guest_passes_expired'];
        if ($changed > 0) {
            coveted_audit(
                'lifecycle.reconciled',
                'platform',
                null,
                [
                    'group_invitations_expired' => $summary['group_invitations_expired'],
                    'event_invitations_expired' => $summary['event_invitations_expired'],
                    'guest_passes_released' => $summary['guest_passes_released'],
                    'guest_passes_expired' => $summary['guest_passes_expired'],
                ],
                0
            );
        }

        $pdo->commit();
        return $summary;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Drain a bounded number of reconciliation batches for CLI/operational use.
 *
 * @return array{
 *   group_invitations_expired:int,
 *   event_invitations_expired:int,
 *   guest_passes_released:int,
 *   guest_passes_expired:int,
 *   batches:int,
 *   more_work_possible:bool
 * }
 */
function coveted_lifecycle_reconcile(int $limit = 250, int $maxBatches = 10): array
{
    $limit = max(1, min($limit, 1000));
    $maxBatches = max(1, min($maxBatches, 100));
    $total = [
        'group_invitations_expired' => 0,
        'event_invitations_expired' => 0,
        'guest_passes_released' => 0,
        'guest_passes_expired' => 0,
        'batches' => 0,
        'more_work_possible' => false,
    ];

    for ($batch = 0; $batch < $maxBatches; $batch++) {
        $result = coveted_lifecycle_reconcile_batch($limit);
        $total['batches']++;
        foreach ([
            'group_invitations_expired',
            'event_invitations_expired',
            'guest_passes_released',
            'guest_passes_expired',
        ] as $key) {
            $total[$key] += (int)$result[$key];
        }

        $total['more_work_possible'] = (bool)$result['more_work_possible'];
        if (!$result['more_work_possible']) {
            break;
        }
    }

    if ($total['more_work_possible']) {
        $total['more_work_possible'] = coveted_lifecycle_backlog()['total'] > 0;
    }

    return $total;
}

/** @return array{group_invitations:int,event_invitations:int,guest_passes:int,total:int} */
function coveted_lifecycle_backlog(): array
{
    $row = coveted_db()->query(
        "SELECT
            (SELECT COUNT(*)
             FROM group_invitations
             WHERE status = 'pending'
               AND expires_at IS NOT NULL
               AND expires_at <= NOW()) AS group_invitations,
            (SELECT COUNT(*)
             FROM event_invitations ei
             JOIN events e ON e.id = ei.event_id
             WHERE ei.status = 'pending'
               AND (e.starts_at <= NOW() OR e.status IN ('completed','cancelled'))) AS event_invitations,
            (SELECT COUNT(*)
             FROM group_guest_passes gp
             LEFT JOIN group_invitations gi ON gi.id = gp.invitation_id
             WHERE (
                    gp.status = 'available'
                    AND gp.expires_at IS NOT NULL
                    AND gp.expires_at <= NOW()
                 )
                 OR (
                    gp.status = 'reserved'
                    AND (
                        (gp.expires_at IS NOT NULL AND gp.expires_at <= NOW())
                        OR gi.id IS NULL
                        OR gi.status <> 'pending'
                        OR (gi.expires_at IS NOT NULL AND gi.expires_at <= NOW())
                    )
                 )) AS guest_passes"
    )->fetch() ?: [];

    $groupInvites = (int)($row['group_invitations'] ?? 0);
    $eventInvites = (int)($row['event_invitations'] ?? 0);
    $guestPasses = (int)($row['guest_passes'] ?? 0);

    return [
        'group_invitations' => $groupInvites,
        'event_invitations' => $eventInvites,
        'guest_passes' => $guestPasses,
        'total' => $groupInvites + $eventInvites + $guestPasses,
    ];
}
