<?php
declare(strict_types=1);

require_once __DIR__ . '/notifications.php';

$covetedAutoload = dirname(__DIR__) . '/vendor/autoload.php';
$covetedPushLibraryError = null;
$covetedPushRuntimeReady = PHP_VERSION_ID >= 80200
    && extension_loaded('curl')
    && extension_loaded('json')
    && extension_loaded('mbstring')
    && extension_loaded('openssl');

if (!$covetedPushRuntimeReady) {
    // Core Coveted does not depend on Web Push. The bundled Web Push transport
    // requires PHP 8.2+ and its extensions, so leave it disabled instead of
    // invoking Composer's platform checker (which deliberately sends HTTP 500).
    $covetedPushLibraryError = 'Web Push is unavailable on this PHP runtime. Core Coveted remains available.';
} elseif (is_file($covetedAutoload)) {
    try {
        require_once $covetedAutoload;
    } catch (Throwable $e) {
        $covetedPushLibraryError = $e->getMessage();
        error_log('Coveted Web Push library unavailable: ' . $e->getMessage());
        if (http_response_code() >= 500) {
            http_response_code(200);
        }
    }
}
unset($covetedAutoload, $covetedPushRuntimeReady);

function coveted_push_config_status(): array
{
    $config = coveted_config('push');
    $enabled = (bool)($config['enabled'] ?? false);
    $subject = trim((string)($config['vapid_subject'] ?? ''));
    $publicKey = trim((string)($config['vapid_public_key'] ?? ''));
    $privateKey = trim((string)($config['vapid_private_key'] ?? ''));
    $batchSize = max(1, min((int)($config['batch_size'] ?? 100), 500));

    $validSubject = false;
    if (str_starts_with($subject, 'mailto:')) {
        $validSubject = filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false;
    } elseif (filter_var($subject, FILTER_VALIDATE_URL) !== false) {
        $parts = parse_url($subject);
        $validSubject = is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']);
    }

    $validPublicKey = strlen($publicKey) >= 80
        && strlen($publicKey) <= 120
        && preg_match('/^[A-Za-z0-9_-]+$/', $publicKey) === 1;
    $validPrivateKey = strlen($privateKey) >= 40
        && strlen($privateKey) <= 80
        && preg_match('/^[A-Za-z0-9_-]+$/', $privateKey) === 1;
    $libraryReady = class_exists('Minishlink\\WebPush\\WebPush')
        && class_exists('Minishlink\\WebPush\\Subscription');

    return [
        'enabled' => $enabled,
        'subject_ready' => $validSubject,
        'public_key_ready' => $validPublicKey,
        'private_key_ready' => $validPrivateKey,
        'library_ready' => $libraryReady,
        'configured' => $enabled && $validSubject && $validPublicKey && $validPrivateKey && $libraryReady,
        'vapid_public_key' => $validPublicKey ? $publicKey : '',
        'batch_size' => $batchSize,
    ];
}

function coveted_push_assert_configured(): array
{
    $status = coveted_push_config_status();
    if (!$status['configured']) {
        throw new RuntimeException('Web Push is not fully configured.');
    }
    return coveted_config('push');
}

function coveted_push_public_config(): array
{
    $status = coveted_push_config_status();
    return [
        'enabled' => (bool)$status['configured'],
        'public_key' => (string)$status['vapid_public_key'],
    ];
}

function coveted_push_payload(array $delivery): string
{
    $body = trim((string)($delivery['body'] ?? ''));
    if (mb_strlen($body) > 900) {
        $body = rtrim(mb_substr($body, 0, 897)) . '…';
    }

    $url = coveted_safe_internal_path((string)($delivery['action_url'] ?? ''), '/notifications.php');
    return coveted_json([
        'notificationId' => (string)$delivery['notification_public_id'],
        'type' => (string)$delivery['notification_type'],
        'title' => (string)$delivery['title'],
        'body' => $body,
        'url' => $url,
        'priority' => (string)$delivery['priority'],
    ]);
}

function coveted_push_cancel_inactive_deliveries(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        "UPDATE notification_deliveries nd
         JOIN notification_devices d ON d.id = nd.device_id
         SET nd.status = 'cancelled',
             nd.error_message = 'Notification device is no longer active',
             nd.next_attempt_at = NULL,
             nd.updated_at = NOW()
         WHERE d.status <> 'active'
           AND nd.status IN ('pending','sending','failed')"
    );
    $stmt->execute();
    return $stmt->rowCount();
}

/** @return array<int,array<string,mixed>> */
function coveted_push_claim_delivery_batch(int $limit): array
{
    $limit = max(1, min($limit, 500));
    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        coveted_push_cancel_inactive_deliveries($pdo);
        $pdo->exec(
            "UPDATE notification_deliveries
             SET status = 'failed', next_attempt_at = NOW(),
                 error_message = 'Recovered stale delivery lease', updated_at = NOW()
             WHERE transport = 'web_push' AND status = 'sending'
               AND last_attempt_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );

        $stmt = $pdo->query(
            "SELECT nd.id
             FROM notification_deliveries nd
             JOIN notification_devices d ON d.id = nd.device_id
             JOIN notifications n ON n.id = nd.notification_id
             WHERE nd.transport = 'web_push'
               AND nd.status IN ('pending','failed')
               AND (nd.next_attempt_at IS NULL OR nd.next_attempt_at <= NOW())
               AND nd.attempts < 5
               AND d.status = 'active'
               AND d.user_id = n.user_id
             ORDER BY n.priority = 'high' DESC, nd.created_at ASC, nd.id ASC
             LIMIT {$limit}
             FOR UPDATE SKIP LOCKED"
        );
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        if (!$ids) {
            $pdo->commit();
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $update = $pdo->prepare(
            "UPDATE notification_deliveries
             SET status = 'sending', attempts = attempts + 1, last_attempt_at = NOW(),
                 next_attempt_at = NULL, error_message = NULL, updated_at = NOW()
             WHERE id IN ({$placeholders})"
        );
        $update->execute($ids);
        $pdo->commit();

        $select = coveted_db()->prepare(
            "SELECT nd.*, n.public_id AS notification_public_id, n.notification_type,
                    n.title, n.body, n.action_url, n.priority, n.payload_json,
                    d.endpoint, d.p256dh, d.auth_secret, d.content_encoding,
                    d.public_id AS device_public_id, d.user_id AS device_user_id
             FROM notification_deliveries nd
             JOIN notifications n ON n.id = nd.notification_id
             JOIN notification_devices d ON d.id = nd.device_id
             WHERE nd.id IN ({$placeholders})
             ORDER BY FIELD(nd.id, " . implode(',', $ids) . ')'
        );
        $select->execute($ids);
        return $select->fetchAll();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_push_webpush_client(): object
{
    $config = coveted_push_assert_configured();
    $class = 'Minishlink\\WebPush\\WebPush';
    $client = new $class([
        'VAPID' => [
            'subject' => trim((string)$config['vapid_subject']),
            'publicKey' => trim((string)$config['vapid_public_key']),
            'privateKey' => trim((string)$config['vapid_private_key']),
        ],
    ], [
        'TTL' => 43200,
        'urgency' => 'normal',
        'batchSize' => max(1, min((int)($config['batch_size'] ?? 100), 500)),
        'contentType' => 'application/json',
    ]);

    if (method_exists($client, 'setReuseVAPIDHeaders')) {
        $client->setReuseVAPIDHeaders(true);
    }
    return $client;
}

function coveted_push_send_delivery(object $client, array $delivery, string $payload): array
{
    $subscriptionClass = 'Minishlink\\WebPush\\Subscription';
    $subscription = $subscriptionClass::create([
        'endpoint' => (string)$delivery['endpoint'],
        'keys' => [
            'p256dh' => (string)$delivery['p256dh'],
            'auth' => (string)$delivery['auth_secret'],
        ],
        'contentEncoding' => (string)($delivery['content_encoding'] ?: 'aes128gcm'),
    ]);

    $priority = (string)$delivery['priority'];
    $options = [
        'TTL' => $priority === 'high' ? 86400 : ($priority === 'low' ? 21600 : 43200),
        'urgency' => $priority === 'high' ? 'high' : ($priority === 'low' ? 'low' : 'normal'),
    ];
    $report = $client->sendOneNotification($subscription, $payload, $options);
    $response = $report->getResponse();

    return [
        'success' => $report->isSuccess(),
        'expired' => !$report->isSuccess() && $report->isSubscriptionExpired(),
        'code' => $response ? $response->getStatusCode() : null,
        'reason' => $report->isSuccess() ? '' : mb_substr((string)$report->getReason(), 0, 1000),
    ];
}

function coveted_push_complete_delivery(array $delivery, array $result): void
{
    $deliveryId = (int)$delivery['id'];
    $deviceId = (int)$delivery['device_id'];
    $success = !empty($result['success']);
    $expired = !empty($result['expired']);
    $code = isset($result['code']) && is_numeric($result['code']) ? (int)$result['code'] : null;
    $reason = trim((string)($result['reason'] ?? ''));
    if (mb_strlen($reason) > 1000) {
        $reason = mb_substr($reason, 0, 1000);
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $lock = $pdo->prepare(
            'SELECT id, status, attempts FROM notification_deliveries WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $lock->execute([$deliveryId]);
        $current = $lock->fetch();
        if (!$current || $current['status'] !== 'sending') {
            $pdo->commit();
            return;
        }

        if ($success) {
            $pdo->prepare(
                "UPDATE notification_deliveries
                 SET status = 'sent', sent_at = NOW(), response_code = ?, error_message = NULL,
                     next_attempt_at = NULL, updated_at = NOW()
                 WHERE id = ?"
            )->execute([$code, $deliveryId]);
            $pdo->prepare(
                "UPDATE notification_devices
                 SET last_success_at = NOW(), failure_count = 0, updated_at = NOW()
                 WHERE id = ?"
            )->execute([$deviceId]);
        } elseif ($expired) {
            $pdo->prepare(
                "UPDATE notification_deliveries
                 SET status = 'permanent_failure', response_code = ?, error_message = ?,
                     next_attempt_at = NULL, updated_at = NOW()
                 WHERE id = ?"
            )->execute([$code, $reason !== '' ? $reason : 'Push subscription expired', $deliveryId]);
            $pdo->prepare(
                "UPDATE notification_devices
                 SET status = 'invalid', last_failure_at = NOW(), failure_count = failure_count + 1, updated_at = NOW()
                 WHERE id = ?"
            )->execute([$deviceId]);
            $pdo->prepare(
                "UPDATE notification_deliveries
                 SET status = 'cancelled', error_message = 'Device subscription expired', updated_at = NOW()
                 WHERE device_id = ? AND id <> ? AND status IN ('pending','sending','failed')"
            )->execute([$deviceId, $deliveryId]);
        } else {
            $attempts = (int)$current['attempts'];
            $permanent = $attempts >= 5;
            $delay = min(3600, max(60, (2 ** max(0, $attempts - 1)) * 60));
            $nextAttempt = $permanent ? null : date('Y-m-d H:i:s', time() + $delay);

            $pdo->prepare(
                "UPDATE notification_deliveries
                 SET status = ?, response_code = ?, error_message = ?, next_attempt_at = ?, updated_at = NOW()
                 WHERE id = ?"
            )->execute([
                $permanent ? 'permanent_failure' : 'failed',
                $code,
                $reason !== '' ? $reason : 'Web Push delivery failed',
                $nextAttempt,
                $deliveryId,
            ]);
            $pdo->prepare(
                "UPDATE notification_devices
                 SET last_failure_at = NOW(), failure_count = failure_count + 1, updated_at = NOW()
                 WHERE id = ?"
            )->execute([$deviceId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Deliver pending Web Push rows. Tests can inject a sender callable so runtime
 * state transitions are exercised without external network calls.
 */
function coveted_push_dispatch_pending(int $limit = 0, ?callable $sender = null): array
{
    $status = coveted_push_config_status();
    if ($limit < 1) {
        $limit = (int)$status['batch_size'];
    }
    $limit = max(1, min($limit, 500));

    if ($sender === null && !$status['configured']) {
        throw new RuntimeException('Web Push is not fully configured.');
    }

    $deliveries = coveted_push_claim_delivery_batch($limit);
    if (!$deliveries) {
        return ['claimed' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0];
    }

    $client = $sender === null ? coveted_push_webpush_client() : null;
    $summary = ['claimed' => count($deliveries), 'sent' => 0, 'failed' => 0, 'expired' => 0];

    foreach ($deliveries as $delivery) {
        try {
            $payload = coveted_push_payload($delivery);
            $result = $sender !== null
                ? $sender($delivery, $payload)
                : coveted_push_send_delivery($client, $delivery, $payload);

            if (!is_array($result) || !array_key_exists('success', $result)) {
                throw new RuntimeException('Push transport returned an invalid result.');
            }
        } catch (Throwable $e) {
            $result = [
                'success' => false,
                'expired' => false,
                'code' => null,
                'reason' => mb_substr($e->getMessage(), 0, 1000),
            ];
        }

        coveted_push_complete_delivery($delivery, $result);
        if (!empty($result['success'])) {
            $summary['sent']++;
        } elseif (!empty($result['expired'])) {
            $summary['expired']++;
        } else {
            $summary['failed']++;
        }
    }

    return $summary;
}

function coveted_push_dispatch_as_admin(array $actor, int $limit = 0): array
{
    if (!coveted_is_system_admin($actor)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    return coveted_push_dispatch_pending($limit);
}
