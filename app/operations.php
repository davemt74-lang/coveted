<?php
declare(strict_types=1);

require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/lifecycle.php';

/**
 * Read-only System Admin launch-health view.
 *
 * This service derives operational attention queues from canonical domain
 * tables. It deliberately does not create a second lifecycle/state machine and
 * never exposes notification endpoints, push keys, provider error payloads,
 * private event feedback, or Mutual Reconnect choices.
 *
 * @return array<string,mixed>
 */
function coveted_operations_snapshot(array $actor): array
{
    if (!coveted_is_system_admin($actor)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $pdo = coveted_db();

    $summaryStmt = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM role_requests WHERE status = 'pending') AS pending_role_requests,
            (SELECT COUNT(*) FROM users WHERE status = 'suspended') AS suspended_accounts,
            (SELECT COUNT(*)
             FROM events
             WHERE status IN ('published','closed')
               AND COALESCE(ends_at, starts_at) < DATE_SUB(NOW(), INTERVAL 6 HOUR)) AS overdue_events,
            (SELECT COUNT(*)
             FROM events e
             WHERE e.status = 'published'
               AND e.starts_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 72 HOUR)
               AND NOT EXISTS (
                   SELECT 1
                   FROM event_locations el
                   WHERE el.event_id = e.id
                     AND (el.location_id IS NOT NULL OR NULLIF(TRIM(el.private_location_label), '') IS NOT NULL)
               )) AS upcoming_without_location,
            (SELECT COUNT(*)
             FROM notification_deliveries
             WHERE status = 'permanent_failure'
               AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS permanent_failures_24h,
            (SELECT COUNT(*)
             FROM notification_deliveries
             WHERE status = 'failed'
               AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS retryable_failures_24h,
            (SELECT COUNT(*)
             FROM notification_deliveries nd
             JOIN notification_devices d ON d.id = nd.device_id
             JOIN notifications n ON n.id = nd.notification_id
             WHERE nd.transport = 'web_push'
               AND d.status = 'active'
               AND d.user_id = n.user_id
               AND (
                    (
                        nd.status = 'sending'
                        AND COALESCE(nd.last_attempt_at, nd.updated_at) < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                    )
                    OR
                    (
                        nd.status IN ('pending','failed')
                        AND nd.attempts < 5
                        AND (nd.next_attempt_at IS NULL OR nd.next_attempt_at <= NOW())
                        AND nd.updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                    )
               )) AS stuck_deliveries,
            (SELECT COUNT(*)
             FROM reward_claims
             WHERE claimed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS claims_7d,
            (SELECT COUNT(*)
             FROM reward_claims
             WHERE status = 'refunded'
               AND refunded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS refunds_7d"
    );
    $summary = $summaryStmt->fetch() ?: [];
    foreach ($summary as $key => $value) {
        $summary[$key] = (int)$value;
    }

    $lifecycleBacklog = coveted_lifecycle_backlog();
    $summary['lifecycle_backlog'] = (int)$lifecycleBacklog['total'];
    $summary['attention_count'] = (int)$summary['pending_role_requests']
        + (int)$summary['overdue_events']
        + (int)$summary['upcoming_without_location']
        + (int)$summary['permanent_failures_24h']
        + (int)$summary['stuck_deliveries']
        + (int)$summary['lifecycle_backlog'];

    $overdueEvents = $pdo->query(
        "SELECT
            e.public_id,
            e.title,
            e.status,
            e.event_type,
            e.starts_at,
            e.ends_at,
            e.timezone,
            g.public_id AS group_public_id,
            g.name AS group_name,
            u.display_name AS creator_name
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         JOIN users u ON u.id = e.created_by
         WHERE e.status IN ('published','closed')
           AND COALESCE(e.ends_at, e.starts_at) < DATE_SUB(NOW(), INTERVAL 6 HOUR)
         ORDER BY COALESCE(e.ends_at, e.starts_at) ASC, e.id ASC
         LIMIT 100"
    )->fetchAll();

    $locationAttention = $pdo->query(
        "SELECT
            e.public_id,
            e.title,
            e.event_type,
            e.starts_at,
            e.timezone,
            e.location_visibility,
            g.public_id AS group_public_id,
            g.name AS group_name
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         WHERE e.status = 'published'
           AND e.starts_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 72 HOUR)
           AND NOT EXISTS (
               SELECT 1
               FROM event_locations el
               WHERE el.event_id = e.id
                 AND (el.location_id IS NOT NULL OR NULLIF(TRIM(el.private_location_label), '') IS NOT NULL)
           )
         ORDER BY e.starts_at ASC, e.id ASC
         LIMIT 100"
    )->fetchAll();

    $deliveryFailures = $pdo->query(
        "SELECT
            nd.id,
            nd.status,
            nd.transport,
            nd.attempts,
            nd.response_code,
            nd.last_attempt_at,
            nd.next_attempt_at,
            nd.updated_at,
            n.notification_type,
            n.title,
            u.display_name AS recipient_name
         FROM notification_deliveries nd
         JOIN notifications n ON n.id = nd.notification_id
         JOIN users u ON u.id = n.user_id
         WHERE nd.status IN ('failed','permanent_failure')
           AND nd.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY FIELD(nd.status, 'permanent_failure','failed'), nd.updated_at DESC, nd.id DESC
         LIMIT 100"
    )->fetchAll();
    foreach ($deliveryFailures as &$row) {
        $row['id'] = (int)$row['id'];
        $row['attempts'] = (int)$row['attempts'];
        $row['response_code'] = $row['response_code'] !== null ? (int)$row['response_code'] : null;
    }
    unset($row);

    $stuckDeliveries = $pdo->query(
        "SELECT
            nd.id,
            nd.status,
            nd.transport,
            nd.attempts,
            nd.last_attempt_at,
            nd.next_attempt_at,
            nd.updated_at,
            n.notification_type,
            n.title
         FROM notification_deliveries nd
         JOIN notification_devices d ON d.id = nd.device_id
         JOIN notifications n ON n.id = nd.notification_id
         WHERE nd.transport = 'web_push'
           AND d.status = 'active'
           AND d.user_id = n.user_id
           AND (
                (
                    nd.status = 'sending'
                    AND COALESCE(nd.last_attempt_at, nd.updated_at) < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                )
                OR
                (
                    nd.status IN ('pending','failed')
                    AND nd.attempts < 5
                    AND (nd.next_attempt_at IS NULL OR nd.next_attempt_at <= NOW())
                    AND nd.updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                )
           )
         ORDER BY nd.updated_at ASC, nd.id ASC
         LIMIT 100"
    )->fetchAll();
    foreach ($stuckDeliveries as &$row) {
        $row['id'] = (int)$row['id'];
        $row['attempts'] = (int)$row['attempts'];
    }
    unset($row);

    $claimActivity = $pdo->query(
        "SELECT
            rc.public_id,
            rc.status,
            rc.claim_code_type,
            rc.claim_code_label,
            rc.claimed_at,
            rc.refunded_at,
            l.name AS location_name,
            b.name AS business_name,
            rt.title AS reward_title
         FROM reward_claims rc
         JOIN reward_issuances ri ON ri.id = rc.reward_issuance_id
         JOIN reward_templates rt ON rt.id = ri.reward_template_id
         JOIN locations l ON l.id = rc.location_id
         JOIN businesses b ON b.id = l.business_id
         WHERE rc.claimed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY rc.claimed_at DESC, rc.id DESC
         LIMIT 100"
    )->fetchAll();

    $auditTrail = $pdo->query(
        "SELECT
            ae.id,
            ae.event_type,
            ae.entity_type,
            ae.entity_id,
            ae.created_at,
            u.display_name AS actor_name
         FROM audit_events ae
         LEFT JOIN users u ON u.id = ae.actor_user_id
         ORDER BY ae.created_at DESC, ae.id DESC
         LIMIT 100"
    )->fetchAll();
    foreach ($auditTrail as &$row) {
        $row['id'] = (int)$row['id'];
    }
    unset($row);

    return [
        'summary' => $summary,
        'lifecycle_backlog' => $lifecycleBacklog,
        'overdue_events' => $overdueEvents,
        'location_attention' => $locationAttention,
        'delivery_failures' => $deliveryFailures,
        'stuck_deliveries' => $stuckDeliveries,
        'claim_activity' => $claimActivity,
        'audit_trail' => $auditTrail,
        'generated_at' => gmdate('Y-m-d H:i:s'),
    ];
}
