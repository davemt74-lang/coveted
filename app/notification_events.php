<?php
declare(strict_types=1);

require_once __DIR__ . '/notifications.php';

/**
 * Reconcile durable product state into notification records. Every projected
 * event has a stable dedupe key so a worker or member request can repair a
 * missed notification without creating duplicates.
 */
function coveted_notification_reconcile(?int $onlyUserId = null, int $limit = 200): array
{
    $limit = max(1, min($limit, 500));
    $summary = [
        'event_invitations' => 0,
        'waitlist_promotions' => 0,
        'event_cancellations' => 0,
        'reward_refunds' => 0,
        'mystery_reveals' => 0,
    ];

    $userFilter = $onlyUserId !== null && $onlyUserId > 0 ? $onlyUserId : null;

    $inviteSql = "SELECT ei.id, ei.public_id, ei.user_id, ei.invited_by,
                         e.public_id AS event_public_id, e.title AS event_title,
                         e.starts_at, e.timezone, g.name AS group_name
                  FROM event_invitations ei
                  JOIN events e ON e.id = ei.event_id
                  JOIN social_groups g ON g.id = e.group_id
                  JOIN users u ON u.id = ei.user_id AND u.status = 'active'
                  WHERE ei.status = 'pending'
                    AND e.status = 'published'
                    AND e.starts_at > NOW()";
    $inviteParams = [];
    if ($userFilter !== null) {
        $inviteSql .= ' AND ei.user_id = ?';
        $inviteParams[] = $userFilter;
    }
    $inviteSql .= ' ORDER BY ei.created_at ASC, ei.id ASC LIMIT ' . $limit;
    $stmt = coveted_db()->prepare($inviteSql);
    $stmt->execute($inviteParams);

    foreach ($stmt->fetchAll() as $invite) {
        coveted_notification_create(
            (int)$invite['user_id'],
            'event.invitation',
            'You’re invited: ' . (string)$invite['event_title'],
            (string)$invite['group_name'] . ' wants you there.',
            '/invitations.php',
            [
                'event_id' => (string)$invite['event_public_id'],
                'invitation_id' => (string)$invite['public_id'],
                'starts_at' => (string)$invite['starts_at'],
                'timezone' => (string)$invite['timezone'],
            ],
            'high',
            'event-invitation:' . (int)$invite['id'],
            $invite['invited_by'] !== null ? (int)$invite['invited_by'] : null
        );
        $summary['event_invitations']++;
    }

    $promotionSql = "SELECT ae.id AS audit_id, ae.entity_id AS event_public_id,
                            CAST(JSON_UNQUOTE(JSON_EXTRACT(ae.metadata_json, '$.user_id')) AS UNSIGNED) AS user_id,
                            e.title AS event_title, e.starts_at, e.timezone, g.name AS group_name
                     FROM audit_events ae
                     JOIN events e ON e.public_id = ae.entity_id
                     JOIN social_groups g ON g.id = e.group_id
                     JOIN users u ON u.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(ae.metadata_json, '$.user_id')) AS UNSIGNED)
                                  AND u.status = 'active'
                     WHERE ae.event_type = 'event.waitlist_promoted'
                       AND ae.metadata_json IS NOT NULL";
    $promotionParams = [];
    if ($userFilter !== null) {
        $promotionSql .= " AND CAST(JSON_UNQUOTE(JSON_EXTRACT(ae.metadata_json, '$.user_id')) AS UNSIGNED) = ?";
        $promotionParams[] = $userFilter;
    }
    $promotionSql .= ' ORDER BY ae.id ASC LIMIT ' . $limit;
    $stmt = coveted_db()->prepare($promotionSql);
    $stmt->execute($promotionParams);

    foreach ($stmt->fetchAll() as $promotion) {
        coveted_notification_create(
            (int)$promotion['user_id'],
            'event.waitlist_promoted',
            'You’re in: ' . (string)$promotion['event_title'],
            'A spot opened for ' . (string)$promotion['group_name'] . '.',
            '/events.php',
            [
                'event_id' => (string)$promotion['event_public_id'],
                'starts_at' => (string)$promotion['starts_at'],
                'timezone' => (string)$promotion['timezone'],
            ],
            'high',
            'waitlist-promotion:' . (int)$promotion['audit_id'],
            null
        );
        $summary['waitlist_promotions']++;
    }

    $cancellationSql = "SELECT DISTINCT e.id AS event_id, e.public_id AS event_public_id,
                               e.title AS event_title, e.starts_at, e.timezone,
                               e.updated_at AS event_updated_at,
                               g.name AS group_name, u.id AS user_id
                        FROM events e
                        JOIN social_groups g ON g.id = e.group_id
                        JOIN users u ON u.status = 'active'
                        WHERE e.status = 'cancelled'
                          AND (
                              EXISTS (
                                  SELECT 1 FROM event_invitations ei
                                  WHERE ei.event_id = e.id AND ei.user_id = u.id
                                    AND ei.status IN ('pending','accepted')
                              )
                              OR EXISTS (
                                  SELECT 1 FROM event_rsvps er
                                  WHERE er.event_id = e.id AND er.user_id = u.id
                                    AND er.response IN ('attending','waitlist')
                              )
                              OR EXISTS (
                                  SELECT 1 FROM event_attendance ea
                                  WHERE ea.event_id = e.id AND ea.user_id = u.id
                                    AND ea.status IN ('checked_in','attended','left_early')
                              )
                          )";
    $cancellationParams = [];
    if ($userFilter !== null) {
        $cancellationSql .= ' AND u.id = ?';
        $cancellationParams[] = $userFilter;
    }
    $cancellationSql .= ' ORDER BY event_updated_at DESC, event_id DESC, user_id ASC LIMIT ' . $limit;
    $stmt = coveted_db()->prepare($cancellationSql);
    $stmt->execute($cancellationParams);

    foreach ($stmt->fetchAll() as $cancellation) {
        coveted_notification_create(
            (int)$cancellation['user_id'],
            'event.cancelled',
            'Cancelled: ' . (string)$cancellation['event_title'],
            (string)$cancellation['group_name'] . ' cancelled this gathering.',
            '/events.php',
            [
                'event_id' => (string)$cancellation['event_public_id'],
                'starts_at' => (string)$cancellation['starts_at'],
                'timezone' => (string)$cancellation['timezone'],
            ],
            'high',
            'event-cancelled:' . (int)$cancellation['event_id'],
            null
        );
        $summary['event_cancellations']++;
    }

    $refundSql = "SELECT ca.id AS activity_id, ca.user_id, ca.reward_issuance_id,
                         ri.public_id AS issuance_public_id, rt.title AS reward_title,
                         rc.refund_reason, rc.refunded_by_user_id
                  FROM campaign_activity ca
                  JOIN reward_issuances ri ON ri.id = ca.reward_issuance_id
                  JOIN reward_templates rt ON rt.id = ri.reward_template_id
                  LEFT JOIN reward_claims rc
                    ON rc.public_id = JSON_UNQUOTE(JSON_EXTRACT(ca.metadata_json, '$.claim_id'))
                  JOIN users u ON u.id = ca.user_id AND u.status = 'active'
                  WHERE ca.activity_type = 'reward_refunded'
                    AND ca.user_id IS NOT NULL";
    $refundParams = [];
    if ($userFilter !== null) {
        $refundSql .= ' AND ca.user_id = ?';
        $refundParams[] = $userFilter;
    }
    $refundSql .= ' ORDER BY ca.id ASC LIMIT ' . $limit;
    $stmt = coveted_db()->prepare($refundSql);
    $stmt->execute($refundParams);

    foreach ($stmt->fetchAll() as $refund) {
        $reason = trim((string)($refund['refund_reason'] ?? ''));
        coveted_notification_create(
            (int)$refund['user_id'],
            'reward.refunded',
            'Your ' . (string)$refund['reward_title'] . ' was returned',
            $reason !== ''
                ? 'Refunded: ' . $reason . '. The benefit is back in your Inbox if it is still valid.'
                : 'The benefit is back in your Inbox if it is still valid.',
            '/benefits.php?box=inbox',
            ['reward_issuance_id' => (string)$refund['issuance_public_id']],
            'normal',
            'reward-refund:' . (int)$refund['activity_id'],
            $refund['refunded_by_user_id'] !== null ? (int)$refund['refunded_by_user_id'] : null
        );
        $summary['reward_refunds']++;
    }

    $pdo = coveted_db();
    $revealSql = "SELECT mr.*, e.public_id AS event_public_id, e.title AS event_title
                  FROM event_mystery_reveals mr
                  JOIN events e ON e.id = mr.event_id
                  WHERE mr.reveal_at <= NOW()
                    AND e.status IN ('published','closed','completed')";
    if ($userFilter === null) {
        $revealSql .= ' AND mr.notified_at IS NULL';
    }
    $revealSql .= ' ORDER BY mr.reveal_at ASC, mr.id ASC LIMIT ' . $limit;
    $revealStmt = $pdo->query($revealSql);

    foreach ($revealStmt->fetchAll() as $reveal) {
        $recipientSql = "SELECT DISTINCT u.id
                         FROM users u
                         WHERE u.status = 'active'
                           AND (
                               EXISTS (
                                   SELECT 1 FROM event_rsvps er
                                   WHERE er.event_id = ? AND er.user_id = u.id AND er.response = 'attending'
                               )
                               OR EXISTS (
                                   SELECT 1 FROM event_attendance ea
                                   WHERE ea.event_id = ? AND ea.user_id = u.id
                                     AND ea.status IN ('checked_in','attended','left_early')
                               )
                           )";
        $recipientParams = [(int)$reveal['event_id'], (int)$reveal['event_id']];
        if ($userFilter !== null) {
            $recipientSql .= ' AND u.id = ?';
            $recipientParams[] = $userFilter;
        }
        $recipientStmt = $pdo->prepare($recipientSql);
        $recipientStmt->execute($recipientParams);
        $recipients = array_map('intval', array_column($recipientStmt->fetchAll(), 'id'));

        foreach ($recipients as $recipientId) {
            $title = trim((string)($reveal['title'] ?? ''));
            if ($title === '') {
                $title = match ((string)$reveal['reveal_type']) {
                    'location' => 'Location revealed',
                    'artist' => 'Artist revealed',
                    'area' => 'Your event area is ready',
                    'experience' => 'Experience revealed',
                    'instructions' => 'New event instructions',
                    default => 'Mystery event update',
                };
            }

            coveted_notification_create(
                $recipientId,
                'event.mystery_reveal',
                $title . ': ' . (string)$reveal['event_title'],
                (string)$reveal['content'],
                '/events.php',
                [
                    'event_id' => (string)$reveal['event_public_id'],
                    'reveal_type' => (string)$reveal['reveal_type'],
                ],
                in_array((string)$reveal['reveal_type'], ['location','instructions'], true) ? 'high' : 'normal',
                'mystery-reveal:' . (int)$reveal['id'] . ':user:' . $recipientId,
                null
            );
            $summary['mystery_reveals']++;
        }

        if ($userFilter === null) {
            $pdo->prepare('UPDATE event_mystery_reveals SET notified_at = NOW() WHERE id = ? AND notified_at IS NULL')
                ->execute([(int)$reveal['id']]);
        }
    }

    return $summary;
}