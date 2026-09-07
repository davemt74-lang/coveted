<?php
declare(strict_types=1);

require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/lifecycle.php';
require_once __DIR__ . '/event_opportunities.php';
require_once __DIR__ . '/event_production.php';
require_once __DIR__ . '/event_proposals.php';
require_once __DIR__ . '/host_command.php';
require_once __DIR__ . '/event_results.php';
require_once __DIR__ . '/member_relationships.php';
require_once __DIR__ . '/event_invitation_waves.php';
require_once __DIR__ . '/event_communications_agent.php';
require_once __DIR__ . '/event_communication_delivery.php';

/**
 * Read-only System Admin launch-health view.
 *
 * This service derives operational attention queues from canonical domain
 * tables. It deliberately does not create a second lifecycle/state machine and
 * never exposes notification endpoints, push keys, provider error payloads,
 * private event feedback, attendee identities, communication recipient identities,
 * or Mutual Reconnect choices.
 *
 * Event Opportunity, Proposal / Playbook, Event Production, Host Command,
 * Event Results, aggregate Member Relationship summaries, aggregate Invitation
 * Wave / RSVP forecasts, aggregate Event Communications lifecycle state and
 * aggregate Event communication delivery health are intentionally included here
 * because the Admin Agent consumes this canonical Operations summary on every
 * reasoning/chat round.
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

    try {
        $eventOpportunities = coveted_event_opportunity_agent_context($actor, $pdo);
    } catch (Throwable $e) {
        $eventOpportunities = ['total'=>0,'high_priority'=>0,'recommendations'=>[],'unavailable'=>true];
        error_log('Operations Event Opportunity context unavailable: ' . $e->getMessage());
    }

    try {
        $eventPlanning = coveted_event_proposal_agent_context($actor, $pdo);
    } catch (Throwable $e) {
        $eventPlanning = ['available'=>false,'unavailable'=>true,'pipeline'=>[],'playbooks'=>[],'proposals'=>[],'recommendations'=>[]];
        error_log('Operations Event Proposal / Playbook context unavailable: ' . $e->getMessage());
    }

    try {
        $eventProduction = coveted_event_production_agent_context($actor, $pdo);
    } catch (Throwable $e) {
        $eventProduction = ['unavailable'=>true,'events'=>[],'attention'=>0];
        error_log('Operations Event Production context unavailable: ' . $e->getMessage());
    }

    try {
        $hostCommand = coveted_host_command_agent_context($actor, $pdo);
    } catch (Throwable $e) {
        $hostCommand = ['available'=>false,'events'=>[],'recommendations'=>[],'attention'=>0,'unavailable'=>true];
        error_log('Operations Host Command context unavailable: ' . $e->getMessage());
    }

    try {
        $eventResults = coveted_event_results_agent_context($actor, 10, $pdo);
    } catch (Throwable $e) {
        $eventResults = ['available'=>false,'events'=>[],'recommendations'=>[],'attention'=>0,'unavailable'=>true];
        error_log('Operations Event Results context unavailable: ' . $e->getMessage());
    }

    try {
        $memberRelationships = coveted_member_relationship_agent_context($actor, 20, $pdo);
    } catch (Throwable $e) {
        $memberRelationships = ['available'=>false,'groups'=>[],'recommendations'=>[],'attention'=>0,'unavailable'=>true];
        error_log('Operations Member Relationship context unavailable: ' . $e->getMessage());
    }

    try {
        $invitationWaves = coveted_event_invitation_wave_agent_context($actor, 12, $pdo);
    } catch (Throwable $e) {
        $invitationWaves = ['available'=>false,'events'=>[],'recommendations'=>[],'attention'=>0,'unavailable'=>true];
        error_log('Operations Invitation Wave context unavailable: ' . $e->getMessage());
    }

    try {
        $eventCommunications = coveted_event_communications_agent_context($actor, 12, $pdo);
    } catch (Throwable $e) {
        $eventCommunications = ['available'=>false,'events'=>[],'recommendations'=>[],'attention'=>0,'unavailable'=>true];
        error_log('Operations Event Communications lifecycle unavailable: ' . $e->getMessage());
    }

    try {
        $eventCommunicationDelivery = coveted_event_communication_delivery_agent_context($actor, 12, $pdo);
    } catch (Throwable $e) {
        $eventCommunicationDelivery = ['available'=>false,'events'=>[],'recommendations'=>[],'attention'=>0,'unavailable'=>true];
        error_log('Operations Event Communication Delivery Health unavailable: ' . $e->getMessage());
    }

    $planningPipeline=(array)($eventPlanning['pipeline'] ?? []);
    $planningRecommendations=array_slice((array)($eventPlanning['recommendations'] ?? []),0,12);
    $hostRecommendations=array_slice((array)($hostCommand['recommendations'] ?? []),0,12);
    $relationshipRecommendations=array_slice((array)($memberRelationships['recommendations'] ?? []),0,8);
    $invitationWaveRecommendations=array_slice((array)($invitationWaves['recommendations'] ?? []),0,8);
    $communicationRecommendations=array_slice((array)($eventCommunications['recommendations'] ?? []),0,8);
    $communicationDeliveryRecommendations=array_slice((array)($eventCommunicationDelivery['recommendations'] ?? []),0,8);
    // Relationship, invitation-wave, communications lifecycle and delivery-health
    // intelligence are merged into the already promoted post-event stream so each
    // reaches the Admin Agent's canonical opportunity queue without a parallel
    // Agent path. Delivery Health reuses event-communications-* keys, allowing the
    // canonical Agent task upsert to update one Event communication task instead
    // of creating competing delivery tasks.
    $resultRecommendations=array_merge(
        (array)($eventResults['recommendations'] ?? []),
        $relationshipRecommendations,
        $invitationWaveRecommendations,
        $communicationRecommendations,
        $communicationDeliveryRecommendations
    );
    usort($resultRecommendations, static function(array $a,array $b): int {
        $priority=((int)($a['priority']??3)) <=> ((int)($b['priority']??3));
        if ($priority!==0) return $priority;
        $keyCompare=strcmp((string)($a['key']??''),(string)($b['key']??''));
        if ($keyCompare!==0) return $keyCompare;
        // When lifecycle and delivery health share the same Event communications
        // source key at the same priority, surface the transport-health warning.
        $aDelivery=str_contains((string)($a['href']??''),'#delivery-health');
        $bDelivery=str_contains((string)($b['href']??''),'#delivery-health');
        return ($aDelivery===$bDelivery) ? 0 : ($aDelivery ? -1 : 1);
    });
    // De-duplicate by canonical source key after priority ordering. This matters
    // when lifecycle and delivery health both describe event-communications-<event>.
    $dedupedRecommendations=[];$seenRecommendationKeys=[];
    foreach ($resultRecommendations as $recommendation) {
        $key=trim((string)($recommendation['key']??''));
        if ($key!=='' && isset($seenRecommendationKeys[$key])) continue;
        if ($key!=='') $seenRecommendationKeys[$key]=true;
        $dedupedRecommendations[]=$recommendation;
        if (count($dedupedRecommendations)>=20) break;
    }
    $resultRecommendations=$dedupedRecommendations;

    $summary['event_opportunity_count'] = (int)($eventOpportunities['total'] ?? 0);
    $summary['event_opportunity_high_priority'] = (int)($eventOpportunities['high_priority'] ?? 0);
    $summary['event_proposal_active']=(int)($planningPipeline['active'] ?? 0);
    $summary['event_proposal_approved']=(int)($planningPipeline['approved'] ?? 0);
    $summary['event_proposal_negotiating']=(int)($planningPipeline['negotiating'] ?? 0);
    $summary['event_proposal_stalled']=(int)($planningPipeline['stalled'] ?? 0);
    $summary['event_production_attention'] = (int)($eventProduction['attention'] ?? 0);
    $summary['host_command_attention'] = (int)($hostCommand['attention'] ?? 0);
    $summary['event_results_attention'] = (int)($eventResults['attention'] ?? 0);
    $summary['member_relationship_attention'] = (int)($memberRelationships['attention'] ?? 0);
    $summary['invitation_wave_attention'] = (int)($invitationWaves['attention'] ?? 0);
    $summary['event_communications_attention'] = (int)($eventCommunications['attention'] ?? 0);
    $summary['event_communication_delivery_attention'] = (int)($eventCommunicationDelivery['attention'] ?? 0);
    $summary['event_opportunities'] = array_slice((array)($eventOpportunities['recommendations'] ?? []), 0, 8);
    $summary['event_planning'] = [
        'available'=>!empty($eventPlanning['available']),
        'pipeline'=>$planningPipeline,
        'playbooks'=>array_slice((array)($eventPlanning['playbooks'] ?? []),0,12),
        'proposals'=>array_slice((array)($eventPlanning['proposals'] ?? []),0,16),
        'recommendations'=>$planningRecommendations,
        'authority'=>(string)($eventPlanning['authority'] ?? ''),
    ];
    $summary['event_production'] = [
        'unavailable' => !empty($eventProduction['unavailable']),
        'attention' => (int)($eventProduction['attention'] ?? 0),
        'events' => array_slice((array)($eventProduction['events'] ?? []), 0, 10),
    ];
    $summary['host_command'] = [
        'available' => !empty($hostCommand['available']),
        'attention' => (int)($hostCommand['attention'] ?? 0),
        'events' => array_slice((array)($hostCommand['events'] ?? []), 0, 12),
        'recommendations' => $hostRecommendations,
        'authority' => (string)($hostCommand['authority'] ?? ''),
    ];
    $summary['event_results'] = [
        'available' => !empty($eventResults['available']),
        'attention' => (int)($eventResults['attention'] ?? 0),
        'events' => array_slice((array)($eventResults['events'] ?? []), 0, 10),
        'recommendations' => $resultRecommendations,
        'privacy' => (string)($eventResults['privacy'] ?? ''),
    ];
    $summary['member_relationships'] = [
        'available' => !empty($memberRelationships['available']),
        'attention' => (int)($memberRelationships['attention'] ?? 0),
        'groups' => array_slice((array)($memberRelationships['groups'] ?? []),0,20),
        'recommendations' => $relationshipRecommendations,
        'privacy' => (string)($memberRelationships['privacy'] ?? ''),
    ];
    $summary['invitation_waves'] = [
        'available' => !empty($invitationWaves['available']),
        'attention' => (int)($invitationWaves['attention'] ?? 0),
        'events' => array_slice((array)($invitationWaves['events'] ?? []),0,12),
        'recommendations' => $invitationWaveRecommendations,
        'privacy' => (string)($invitationWaves['privacy'] ?? ''),
        'authority' => (string)($invitationWaves['authority'] ?? ''),
    ];
    $summary['event_communications'] = [
        'available' => !empty($eventCommunications['available']),
        'attention' => (int)($eventCommunications['attention'] ?? 0),
        'events' => array_slice((array)($eventCommunications['events'] ?? []),0,12),
        'recommendations' => $communicationRecommendations,
        'privacy' => (string)($eventCommunications['privacy'] ?? ''),
        'authority' => (string)($eventCommunications['authority'] ?? ''),
    ];
    $summary['event_communication_delivery'] = [
        'available' => !empty($eventCommunicationDelivery['available']),
        'attention' => (int)($eventCommunicationDelivery['attention'] ?? 0),
        'events' => array_slice((array)($eventCommunicationDelivery['events'] ?? []),0,12),
        'recommendations' => $communicationDeliveryRecommendations,
        'privacy' => (string)($eventCommunicationDelivery['privacy'] ?? ''),
        'authority' => (string)($eventCommunicationDelivery['authority'] ?? ''),
    ];

    // Global stuck/permanent transport rows are already counted above. Keep
    // Event delivery attention as a contextual drilldown and Agent recommendation
    // rather than adding the same underlying failures to attention_count twice.
    $summary['attention_count'] = (int)$summary['pending_role_requests']
        + (int)$summary['overdue_events']
        + (int)$summary['upcoming_without_location']
        + (int)$summary['permanent_failures_24h']
        + (int)$summary['stuck_deliveries']
        + (int)$summary['lifecycle_backlog']
        + (int)$summary['event_proposal_approved']
        + (int)$summary['event_proposal_stalled']
        + (int)$summary['event_production_attention']
        + (int)$summary['host_command_attention']
        + (int)$summary['event_results_attention']
        + (int)$summary['member_relationship_attention']
        + (int)$summary['invitation_wave_attention']
        + (int)$summary['event_communications_attention'];

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
        'event_opportunities' => $eventOpportunities,
        'event_planning' => $eventPlanning,
        'event_production' => $eventProduction,
        'host_command' => $hostCommand,
        'event_results' => $eventResults,
        'member_relationships' => $memberRelationships,
        'invitation_waves' => $invitationWaves,
        'event_communications' => $eventCommunications,
        'event_communication_delivery' => $eventCommunicationDelivery,
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
