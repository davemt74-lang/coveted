<?php
declare(strict_types=1);

require_once __DIR__ . '/event_communications.php';

/**
 * Read-only delivery/timing intelligence for Event communications.
 *
 * This layer never dispatches transport, never reads endpoint/key material, and
 * never mutates notification, Event, invitation or RSVP state.
 */
function coveted_event_communication_delivery_require_admin(array $admin, ?PDO $pdo = null): PDO
{
    return coveted_event_communications_require_admin($admin, $pdo);
}

/** @return array<int,array<string,mixed>> */
function coveted_event_communication_delivery_rows(array $admin, string $eventRef, int $limit = 100, ?PDO $pdo = null): array
{
    $pdo = coveted_event_communication_delivery_require_admin($admin, $pdo);
    $event = coveted_event_communications_event($admin, $eventRef, $pdo);
    $limit = max(1, min(200, $limit));

    $stmt = $pdo->prepare(
        "SELECT
            n.id AS notification_id,
            n.public_id AS notification_ref,
            n.user_id,
            u.display_name,
            n.notification_type,
            n.title,
            n.priority,
            n.created_at,
            JSON_UNQUOTE(JSON_EXTRACT(n.payload_json,'$.communication_type')) AS communication_type,
            JSON_UNQUOTE(JSON_EXTRACT(n.payload_json,'$.reveal_type')) AS reveal_type,
            COUNT(nd.id) AS delivery_total,
            SUM(CASE WHEN nd.status='pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN nd.status='sending' THEN 1 ELSE 0 END) AS sending,
            SUM(CASE WHEN nd.status='sent' THEN 1 ELSE 0 END) AS sent,
            SUM(CASE WHEN nd.status='failed' THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN nd.status='permanent_failure' THEN 1 ELSE 0 END) AS permanent_failure,
            SUM(CASE
                WHEN nd.status='sending'
                 AND COALESCE(nd.last_attempt_at,nd.updated_at)<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE)
                THEN 1
                WHEN nd.status IN ('pending','failed')
                 AND nd.attempts<5
                 AND (nd.next_attempt_at IS NULL OR nd.next_attempt_at<=UTC_TIMESTAMP())
                 AND nd.updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)
                THEN 1
                ELSE 0
            END) AS stuck,
            MAX(nd.attempts) AS max_attempts,
            MAX(nd.response_code) AS response_code,
            MAX(nd.last_attempt_at) AS last_attempt_at,
            MAX(nd.sent_at) AS sent_at,
            MAX(nd.updated_at) AS delivery_updated_at
         FROM notifications n
         JOIN users u ON u.id=n.user_id
         LEFT JOIN notification_deliveries nd ON nd.notification_id=n.id AND nd.transport='web_push'
         WHERE JSON_UNQUOTE(JSON_EXTRACT(n.payload_json,'$.event_id'))=?
           AND n.notification_type IN ('event.rsvp_reminder','event.confirmation','event.location','event.mystery_reveal')
         GROUP BY n.id,n.public_id,n.user_id,u.display_name,n.notification_type,n.title,n.priority,n.created_at,
                  JSON_UNQUOTE(JSON_EXTRACT(n.payload_json,'$.communication_type')),
                  JSON_UNQUOTE(JSON_EXTRACT(n.payload_json,'$.reveal_type'))
         ORDER BY n.created_at DESC,n.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([(string)$event['public_id']]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $deliveryTotal = (int)($row['delivery_total'] ?? 0);
        $sent = (int)($row['sent'] ?? 0);
        $pending = (int)($row['pending'] ?? 0);
        $sending = (int)($row['sending'] ?? 0);
        $failed = (int)($row['failed'] ?? 0);
        $permanent = (int)($row['permanent_failure'] ?? 0);
        $stuck = (int)($row['stuck'] ?? 0);

        $transportState = 'in_app_only';
        if ($deliveryTotal > 0) {
            if ($sent > 0) {
                $transportState = ($failed + $permanent + $pending + $sending) > 0 ? 'partial' : 'sent';
            } elseif ($stuck > 0) {
                $transportState = 'stuck';
            } elseif ($permanent > 0) {
                // If one device is permanently failed while another device is
                // still pending/retrying, keep the notification visible as a
                // permanent-failure concern until any device succeeds.
                $transportState = 'permanent_failure';
            } elseif ($failed > 0) {
                $transportState = 'retrying';
            } elseif ($sending > 0) {
                $transportState = 'sending';
            } else {
                $transportState = 'pending';
            }
        }

        $rows[] = [
            'notification_ref'=>(string)$row['notification_ref'],
            'user_id'=>(int)$row['user_id'],
            'display_name'=>(string)$row['display_name'],
            'notification_type'=>(string)$row['notification_type'],
            'communication_type'=>(string)($row['communication_type'] ?? ''),
            'reveal_type'=>(string)($row['reveal_type'] ?? ''),
            'title'=>(string)$row['title'],
            'priority'=>(string)$row['priority'],
            'created_at'=>(string)$row['created_at'],
            'transport_state'=>$transportState,
            'delivery_total'=>$deliveryTotal,
            'pending'=>$pending,
            'sending'=>$sending,
            'sent'=>$sent,
            'failed'=>$failed,
            'permanent_failure'=>$permanent,
            'stuck'=>$stuck,
            'max_attempts'=>(int)($row['max_attempts'] ?? 0),
            'response_code'=>$row['response_code'] !== null ? (int)$row['response_code'] : null,
            'last_attempt_at'=>(string)($row['last_attempt_at'] ?? ''),
            'sent_at'=>(string)($row['sent_at'] ?? ''),
            'delivery_updated_at'=>(string)($row['delivery_updated_at'] ?? ''),
        ];
    }
    return $rows;
}

/** @return array<string,mixed> */
function coveted_event_communication_delivery_snapshot(array $admin, string $eventRef, ?PDO $pdo = null): array
{
    $pdo = coveted_event_communication_delivery_require_admin($admin, $pdo);
    $event = coveted_event_communications_event($admin, $eventRef, $pdo);
    $rows = coveted_event_communication_delivery_rows($admin, (string)$event['public_id'], 200, $pdo);

    $counts = [
        'notifications'=>count($rows),
        'push_routed'=>0,
        'in_app_only'=>0,
        'sent'=>0,
        'partial'=>0,
        'pending'=>0,
        'sending'=>0,
        'retrying'=>0,
        'stuck'=>0,
        'permanent_failure'=>0,
        'attention'=>0,
    ];
    $latest = '';
    $latestCritical = '';
    $criticalOpen = 0;
    foreach ($rows as $row) {
        $state = (string)$row['transport_state'];
        if (array_key_exists($state, $counts)) $counts[$state]++;
        if ((int)$row['delivery_total'] > 0) $counts['push_routed']++;
        if ($latest === '' || strcmp((string)$row['created_at'], $latest) > 0) $latest = (string)$row['created_at'];

        $critical = (string)$row['priority'] === 'high'
            || in_array((string)$row['communication_type'], ['location','reveal'], true)
            || in_array((string)$row['reveal_type'], ['location','instructions'], true);
        if ($critical && ($latestCritical === '' || strcmp((string)$row['created_at'], $latestCritical) > 0)) {
            $latestCritical = (string)$row['created_at'];
        }
        if ($critical && in_array($state, ['pending','sending','retrying','stuck','permanent_failure'], true)) {
            $criticalOpen++;
        }
    }

    $counts['attention'] = $counts['stuck'] + $counts['permanent_failure'];
    $eventTs = coveted_utc_datetime((string)$event['starts_at'])->getTimestamp();
    $hoursToEvent = max(0.0, ($eventTs - time()) / 3600);
    $coverage = $counts['notifications'] > 0
        ? round(($counts['push_routed'] / $counts['notifications']) * 100, 1)
        : 100.0;

    $severity = 'healthy';
    $title = 'Event communication delivery is healthy';
    $detail = 'No stuck or permanently failed Event communication delivery requires attention.';
    $actionable = false;
    $priority = 3;

    if ($counts['stuck'] > 0 || $counts['permanent_failure'] > 0) {
        $severity = 'attention';
        $title = 'Review Event communication delivery health';
        $detail = 'Canonical Web Push delivery has stuck or permanently failed Event communication records. Review exact System Admin delivery state before sending another communication.';
        $actionable = true;
        $priority = $hoursToEvent <= 24.0 || $criticalOpen > 0 ? 1 : 2;
    } elseif ($criticalOpen > 0 && $hoursToEvent <= 12.0) {
        $severity = 'time_sensitive';
        $title = 'Time-sensitive Event communication is still in transport';
        $detail = 'A high-priority location/reveal/instruction communication is still pending, sending or retrying close to Event start. Review delivery state before adding another message.';
        $actionable = true;
        $priority = 1;
    } elseif (($counts['pending'] + $counts['sending'] + $counts['retrying']) > 0) {
        $severity = 'processing';
        $title = 'Event communication delivery is still processing';
        $detail = 'Canonical Web Push rows are pending, sending or retrying. Avoid stacking duplicate communication while the transport queue is still active.';
    } elseif ($counts['notifications'] > 0 && $coverage < 50.0 && $hoursToEvent <= 24.0) {
        $severity = 'limited_push_coverage';
        $title = 'Event communication has limited push coverage';
        $detail = 'Most canonical notifications for this Event have no Web Push delivery row. The in-app notifications still exist, but System Admin should account for limited push reach close to Event start.';
    }

    $deliveryHref='/admin/event-communication-delivery.php?event='.rawurlencode((string)$event['public_id']).'#delivery-health';
    $recommendation = $actionable ? [
        'priority'=>$priority,
        'key'=>'event-communications-' . (string)$event['public_id'],
        'category'=>'Event Communications',
        'title'=>$title,
        'detail'=>$detail,
        'evidence'=>$counts['stuck'] . ' stuck · ' . $counts['permanent_failure'] . ' permanent failure · ' . $criticalOpen . ' critical still open · ' . $coverage . '% push-routed · ' . round($hoursToEvent,1) . 'h to Event.',
        'href'=>$deliveryHref,
    ] : null;

    return [
        'event'=>[
            'event_ref'=>(string)$event['public_id'],
            'title'=>(string)$event['title'],
            'starts_at'=>(string)$event['starts_at'],
            'status'=>(string)$event['status'],
        ],
        'severity'=>$severity,
        'title'=>$title,
        'detail'=>$detail,
        'actionable'=>$actionable,
        'counts'=>$counts,
        'push_coverage_percent'=>$coverage,
        'critical_open'=>$criticalOpen,
        'hours_to_event'=>round($hoursToEvent,1),
        'latest_queued_at'=>$latest,
        'latest_critical_queued_at'=>$latestCritical,
        'recommendation'=>$recommendation,
        'rows'=>$rows,
        'privacy'=>'Broad Agent context receives aggregate delivery counts only. Exact member identities and per-notification delivery details stay in the System Admin Delivery Health workspace. Endpoint URLs, push keys and provider response bodies are never exposed by this service.',
        'authority'=>'Delivery Health is read-only. It does not retry, dispatch, cancel or mutate notifications. System Admin remains responsible for communication decisions and the canonical transport worker remains the only dispatcher.',
    ];
}

/** @return array<string,mixed> */
function coveted_event_communication_delivery_agent_context(array $admin, int $limit = 12, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) throw new InvalidArgumentException('System Admin access is required.');
    $pdo ??= coveted_db();
    if (coveted_system_sample_mode($admin,$pdo)) {
        return ['available'=>false,'reason'=>'sample_mode','events'=>[],'recommendations'=>[],'attention'=>0];
    }

    $limit = max(1,min(20,$limit));
    $stmt = $pdo->query(
        "SELECT public_id FROM events
         WHERE status IN ('published','closed')
           AND starts_at>UTC_TIMESTAMP()
           AND starts_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 45 DAY)
         ORDER BY starts_at,id LIMIT 30"
    );
    $events=[];$recommendations=[];$attention=0;
    foreach ($stmt->fetchAll() as $row) {
        if (count($events)>=$limit) break;
        try {
            $snap = coveted_event_communication_delivery_snapshot($admin,(string)$row['public_id'],$pdo);
        } catch (Throwable $e) {
            error_log('Event communication delivery health unavailable: ' . $e->getMessage());
            continue;
        }
        $counts=(array)$snap['counts'];
        $events[]=[
            'event_ref'=>(string)$snap['event']['event_ref'],
            'title'=>(string)$snap['event']['title'],
            'starts_at'=>(string)$snap['event']['starts_at'],
            'severity'=>(string)$snap['severity'],
            'notifications'=>(int)$counts['notifications'],
            'push_routed'=>(int)$counts['push_routed'],
            'sent'=>(int)$counts['sent'],
            'processing'=>(int)$counts['pending']+(int)$counts['sending']+(int)$counts['retrying'],
            'stuck'=>(int)$counts['stuck'],
            'permanent_failure'=>(int)$counts['permanent_failure'],
            'critical_open'=>(int)$snap['critical_open'],
            'push_coverage_percent'=>(float)$snap['push_coverage_percent'],
            'hours_to_event'=>(float)$snap['hours_to_event'],
            'href'=>'/admin/event-communication-delivery.php?event='.rawurlencode((string)$snap['event']['event_ref']).'#delivery-health',
        ];
        if (is_array($snap['recommendation'] ?? null)) {
            $attention++;
            if (count($recommendations)<12) $recommendations[]=$snap['recommendation'];
        }
    }
    return [
        'available'=>true,
        'events'=>$events,
        'recommendations'=>$recommendations,
        'attention'=>$attention,
        'privacy'=>'Aggregate Event communication delivery counts only. No recipient identities, endpoints, push credentials or provider response bodies are included.',
        'authority'=>'Read-only delivery intelligence. No transport dispatch or retry action is available to the Agent.',
    ];
}
