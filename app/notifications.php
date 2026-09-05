<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function coveted_notification_queue_deliveries_locked(PDO $pdo, int $notificationId, int $userId): void
{
    $pdo->prepare(
        "INSERT IGNORE INTO notification_deliveries
            (notification_id, device_id, transport, status)
         SELECT ?, d.id, d.transport, 'pending'
         FROM notification_devices d
         WHERE d.user_id = ? AND d.status = 'active'"
    )->execute([$notificationId, $userId]);
}

/**
 * Create one canonical notification record. Product domains write here only;
 * delivery transports consume notification_deliveries independently.
 */
function coveted_notification_create(
    int $userId,
    string $type,
    string $title,
    string $body = '',
    ?string $actionUrl = null,
    array $payload = [],
    string $priority = 'normal',
    ?string $dedupeKey = null,
    ?int $actorId = null
): array {
    $type = strtolower(trim($type));
    $title = trim($title);
    $body = trim($body);
    $actionUrl = $actionUrl !== null ? coveted_safe_internal_path($actionUrl, '') : null;
    $priority = strtolower(trim($priority));
    $dedupeKey = $dedupeKey !== null ? trim($dedupeKey) : null;

    if ($userId < 1) {
        throw new InvalidArgumentException('Notification user is required.');
    }
    if ($type === '' || strlen($type) > 80 || preg_match('/^[a-z0-9_.-]+$/', $type) !== 1) {
        throw new InvalidArgumentException('Invalid notification type.');
    }
    if ($title === '' || mb_strlen($title) > 190) {
        throw new InvalidArgumentException('Notification title is required.');
    }
    if (mb_strlen($body) > 2000) {
        throw new InvalidArgumentException('Notification body is too long.');
    }
    if ($actionUrl === '') {
        $actionUrl = null;
    }
    if (!in_array($priority, ['low', 'normal', 'high'], true)) {
        throw new InvalidArgumentException('Invalid notification priority.');
    }
    if ($dedupeKey !== null && strlen($dedupeKey) > 190) {
        $dedupeKey = hash('sha256', $dedupeKey);
    }
    if ($dedupeKey === '') {
        $dedupeKey = null;
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $user = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $user->execute([$userId]);
        if ($user->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Notification recipient is not active.');
        }

        if ($dedupeKey !== null) {
            $existing = $pdo->prepare(
                'SELECT * FROM notifications WHERE user_id = ? AND dedupe_key = ? LIMIT 1 FOR UPDATE'
            );
            $existing->execute([$userId, $dedupeKey]);
            $row = $existing->fetch();
            if ($row) {
                coveted_notification_queue_deliveries_locked($pdo, (int)$row['id'], $userId);
                $pdo->commit();
                return $row;
            }
        }

        $publicId = coveted_uuid('ntf');
        $pdo->prepare(
            'INSERT INTO notifications
                (public_id, user_id, notification_type, title, body, action_url,
                 payload_json, priority, dedupe_key, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $publicId,
            $userId,
            $type,
            $title,
            $body !== '' ? $body : null,
            $actionUrl,
            $payload ? coveted_json($payload) : null,
            $priority,
            $dedupeKey,
            $actorId,
        ]);
        $id = (int)$pdo->lastInsertId();

        coveted_notification_queue_deliveries_locked($pdo, $id, $userId);
        coveted_audit(
            'notification.created',
            'notification',
            $publicId,
            ['recipient_user_id' => $userId, 'type' => $type, 'priority' => $priority],
            $actorId
        );

        $pdo->commit();
        $stmt = coveted_db()->prepare('SELECT * FROM notifications WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: ['id' => $id, 'public_id' => $publicId];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ((string)$e->getCode() === '23000' && $dedupeKey !== null) {
            $retry = coveted_db();
            $retry->beginTransaction();
            try {
                $existing = $retry->prepare(
                    'SELECT * FROM notifications WHERE user_id = ? AND dedupe_key = ? LIMIT 1 FOR UPDATE'
                );
                $existing->execute([$userId, $dedupeKey]);
                $row = $existing->fetch();
                if ($row) {
                    coveted_notification_queue_deliveries_locked($retry, (int)$row['id'], $userId);
                    $retry->commit();
                    return $row;
                }
                $retry->rollBack();
            } catch (Throwable $retryError) {
                if ($retry->inTransaction()) {
                    $retry->rollBack();
                }
                throw $retryError;
            }
        }

        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_notifications_for_user(int $userId, int $limit = 50): array
{
    $limit = max(1, min($limit, 100));
    $stmt = coveted_db()->prepare(
        "SELECT * FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function coveted_notification_unread_count(int $userId): int
{
    $stmt = coveted_db()->prepare(
        'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL'
    );
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function coveted_notification_mark_read(int $userId, string $notificationRef): void
{
    $notificationRef = trim($notificationRef);
    if ($notificationRef === '' || strlen($notificationRef) > 64) {
        throw new InvalidArgumentException('Notification not found.');
    }

    $stmt = coveted_db()->prepare(
        'UPDATE notifications
         SET read_at = COALESCE(read_at, NOW())
         WHERE user_id = ? AND (public_id = ? OR CAST(id AS CHAR) = ?)'
    );
    $stmt->execute([$userId, $notificationRef, $notificationRef]);
}

function coveted_notification_mark_all_read(int $userId): void
{
    coveted_db()->prepare(
        'UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE user_id = ? AND read_at IS NULL'
    )->execute([$userId]);
}

function coveted_notification_validate_client_id(string $clientId): string
{
    $clientId = trim($clientId);
    if (strlen($clientId) < 16 || strlen($clientId) > 80 || preg_match('/^[A-Za-z0-9_-]+$/', $clientId) !== 1) {
        throw new InvalidArgumentException('Invalid notification device identifier.');
    }
    return $clientId;
}

function coveted_notification_validate_web_subscription(array $subscription): array
{
    $endpoint = trim((string)($subscription['endpoint'] ?? ''));
    $keys = (array)($subscription['keys'] ?? []);
    $p256dh = trim((string)($keys['p256dh'] ?? ''));
    $auth = trim((string)($keys['auth'] ?? ''));
    $encoding = trim((string)($subscription['contentEncoding'] ?? 'aes128gcm')) ?: 'aes128gcm';

    if (strlen($endpoint) > 1200 || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException('Invalid Web Push endpoint.');
    }
    $parts = parse_url($endpoint);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        throw new InvalidArgumentException('Web Push endpoint must use HTTPS.');
    }
    if (
        strlen($p256dh) < 40 || strlen($p256dh) > 255
        || strlen($auth) < 8 || strlen($auth) > 255
        || preg_match('/^[A-Za-z0-9_-]+$/', $p256dh) !== 1
        || preg_match('/^[A-Za-z0-9_-]+$/', $auth) !== 1
    ) {
        throw new InvalidArgumentException('Invalid Web Push subscription keys.');
    }
    if (!in_array($encoding, ['aes128gcm', 'aesgcm'], true)) {
        throw new InvalidArgumentException('Unsupported Web Push content encoding.');
    }

    return [
        'endpoint' => $endpoint,
        'endpoint_hash' => hash('sha256', $endpoint),
        'p256dh' => $p256dh,
        'auth' => $auth,
        'content_encoding' => $encoding,
    ];
}

function coveted_notification_device_lock(PDO $pdo, string $endpointHash): string
{
    $lockName = 'covpushreg:' . substr($endpointHash, 0, 48);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $stmt->execute([$lockName]);
    if ((int)$stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Notification device is already being updated. Try again.');
    }
    return $lockName;
}

function coveted_notification_device_unlock(PDO $pdo, string $lockName): void
{
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lockName]);
    } catch (Throwable $e) {
        error_log('Coveted notification device lock release failed: ' . $e->getMessage());
    }
}

function coveted_notification_register_pwa_device(
    array $user,
    string $clientId,
    array $subscription,
    string $userAgent = ''
): array {
    $clientId = coveted_notification_validate_client_id($clientId);
    $validated = coveted_notification_validate_web_subscription($subscription);
    $userAgent = trim($userAgent);
    if (mb_strlen($userAgent) > 500) {
        $userAgent = mb_substr($userAgent, 0, 500);
    }

    $pdo = coveted_db();
    $lockName = coveted_notification_device_lock($pdo, $validated['endpoint_hash']);

    try {
        $pdo->beginTransaction();

        $existingStmt = $pdo->prepare(
            'SELECT * FROM notification_devices WHERE client_id = ? LIMIT 1 FOR UPDATE'
        );
        $existingStmt->execute([$clientId]);
        $existing = $existingStmt->fetch();

        $pdo->prepare(
            "UPDATE notification_devices
             SET status = 'disabled', updated_at = NOW()
             WHERE transport = 'web_push' AND endpoint_hash = ? AND status = 'active'
               AND client_id <> ?"
        )->execute([$validated['endpoint_hash'], $clientId]);

        if ($existing) {
            if ((int)$existing['user_id'] !== (int)$user['id']) {
                $pdo->prepare(
                    "UPDATE notification_deliveries nd
                     JOIN notifications n ON n.id = nd.notification_id
                     SET nd.status = 'cancelled', nd.error_message = 'Device reassigned to another account', nd.updated_at = NOW()
                     WHERE nd.device_id = ? AND n.user_id <> ?
                       AND nd.status IN ('pending','sending','failed')"
                )->execute([(int)$existing['id'], (int)$user['id']]);
            }

            $pdo->prepare(
                "UPDATE notification_devices
                 SET user_id = ?, client_type = 'pwa', transport = 'web_push', endpoint = ?, endpoint_hash = ?,
                     p256dh = ?, auth_secret = ?, content_encoding = ?, status = 'active', user_agent = ?,
                     last_seen_at = NOW(), failure_count = 0, updated_at = NOW()
                 WHERE id = ?"
            )->execute([
                (int)$user['id'],
                $validated['endpoint'],
                $validated['endpoint_hash'],
                $validated['p256dh'],
                $validated['auth'],
                $validated['content_encoding'],
                $userAgent !== '' ? $userAgent : null,
                (int)$existing['id'],
            ]);
            $deviceId = (int)$existing['id'];
            $publicId = (string)$existing['public_id'];
        } else {
            $publicId = coveted_uuid('ndv');
            $pdo->prepare(
                "INSERT INTO notification_devices
                    (public_id, user_id, client_id, client_type, transport, endpoint, endpoint_hash,
                     p256dh, auth_secret, content_encoding, status, user_agent, last_seen_at)
                 VALUES (?, ?, ?, 'pwa', 'web_push', ?, ?, ?, ?, ?, 'active', ?, NOW())"
            )->execute([
                $publicId,
                (int)$user['id'],
                $clientId,
                $validated['endpoint'],
                $validated['endpoint_hash'],
                $validated['p256dh'],
                $validated['auth'],
                $validated['content_encoding'],
                $userAgent !== '' ? $userAgent : null,
            ]);
            $deviceId = (int)$pdo->lastInsertId();
        }

        coveted_audit(
            'notification.device_registered',
            'notification_device',
            $publicId,
            ['client_type' => 'pwa', 'transport' => 'web_push'],
            (int)$user['id']
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        coveted_notification_device_unlock($pdo, $lockName);
    }

    $stmt = coveted_db()->prepare(
        'SELECT id, public_id, client_id, client_type, transport, status, last_seen_at, created_at, updated_at
         FROM notification_devices WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$deviceId]);
    return $stmt->fetch() ?: ['id' => $deviceId, 'public_id' => $publicId];
}

function coveted_notification_disable_device(array $user, string $clientId): void
{
    $clientId = coveted_notification_validate_client_id($clientId);
    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT id, public_id FROM notification_devices WHERE client_id = ? AND user_id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$clientId, (int)$user['id']]);
        $device = $stmt->fetch();
        if (!$device) {
            $pdo->commit();
            return;
        }

        $pdo->prepare(
            "UPDATE notification_devices SET status = 'disabled', updated_at = NOW() WHERE id = ?"
        )->execute([(int)$device['id']]);
        $pdo->prepare(
            "UPDATE notification_deliveries
             SET status = 'cancelled', error_message = 'Push disabled on this device', updated_at = NOW()
             WHERE device_id = ? AND status IN ('pending','sending','failed')"
        )->execute([(int)$device['id']]);

        coveted_audit(
            'notification.device_disabled',
            'notification_device',
            (string)$device['public_id'],
            [],
            (int)$user['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_notification_devices_for_user(int $userId): array
{
    $stmt = coveted_db()->prepare(
        "SELECT id, public_id, client_id, client_type, transport, status, user_agent,
                last_seen_at, last_success_at, last_failure_at, failure_count, created_at, updated_at
         FROM notification_devices
         WHERE user_id = ?
         ORDER BY updated_at DESC, id DESC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function coveted_notification_admin_stats(): array
{
    $pdo = coveted_db();
    return [
        'notifications_total' => (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn(),
        'notifications_unread' => (int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL')->fetchColumn(),
        'devices_active' => (int)$pdo->query("SELECT COUNT(*) FROM notification_devices WHERE status = 'active'")->fetchColumn(),
        'deliveries_pending' => (int)$pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE status IN ('pending','failed')")->fetchColumn(),
        'deliveries_sent' => (int)$pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE status = 'sent'")->fetchColumn(),
        'deliveries_failed' => (int)$pdo->query("SELECT COUNT(*) FROM notification_deliveries WHERE status IN ('failed','permanent_failure')")->fetchColumn(),
    ];
}

function coveted_notification_recent_admin(int $limit = 50): array
{
    $limit = max(1, min($limit, 100));
    return coveted_db()->query(
        "SELECT n.*, u.display_name, u.email
         FROM notifications n
         JOIN users u ON u.id = n.user_id
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT {$limit}"
    )->fetchAll();
}

function coveted_notification_recent_deliveries_admin(int $limit = 50): array
{
    $limit = max(1, min($limit, 100));
    return coveted_db()->query(
        "SELECT nd.*, n.public_id AS notification_public_id, n.title, n.notification_type,
                u.display_name, u.email, d.client_type, d.transport, d.status AS device_status
         FROM notification_deliveries nd
         JOIN notifications n ON n.id = nd.notification_id
         JOIN users u ON u.id = n.user_id
         JOIN notification_devices d ON d.id = nd.device_id
         ORDER BY nd.updated_at DESC, nd.id DESC
         LIMIT {$limit}"
    )->fetchAll();
}
