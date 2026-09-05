<?php
declare(strict_types=1);

require_once __DIR__ . '/app/push.php';
require_once __DIR__ . '/app/notification_events.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$user = coveted_require_user();

function coveted_push_client_cookie(string $clientId = '', bool $clear = false): void
{
    setcookie('coveted_push_client', $clear ? '' : $clientId, [
        'expires' => $clear ? time() - 3600 : time() + 31536000,
        'path' => '/',
        'secure' => coveted_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        coveted_notification_reconcile((int)$user['id'], 100);

        $clientId = trim((string)($_GET['client_id'] ?? ''));
        $device = null;

        if ($clientId !== '') {
            $clientId = coveted_notification_validate_client_id($clientId);
            $stmt = coveted_db()->prepare(
                'SELECT public_id, client_type, transport, status, last_seen_at
                 FROM notification_devices
                 WHERE user_id = ? AND client_id = ?
                 LIMIT 1'
            );
            $stmt->execute([(int)$user['id'], $clientId]);
            $device = $stmt->fetch() ?: null;
        }

        echo coveted_json([
            'ok' => true,
            'csrf_token' => coveted_csrf_token(),
            'unread_count' => coveted_notification_unread_count((int)$user['id']),
            'push' => coveted_push_public_config(),
            'device' => $device,
        ]);
        exit;
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo coveted_json(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    } catch (Throwable $e) {
        error_log('Coveted notification bootstrap error: ' . $e->getMessage());
        http_response_code(500);
        echo coveted_json(['ok' => false, 'error' => 'Unable to load notification state right now.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo coveted_json(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

coveted_require_csrf();

try {
    $action = trim((string)($_POST['action'] ?? ''));
    $clientId = (string)($_POST['client_id'] ?? '');

    if ($action === 'subscribe') {
        $clientId = coveted_notification_validate_client_id($clientId);
        $raw = (string)($_POST['subscription'] ?? '');
        if ($raw === '' || strlen($raw) > 5000) {
            throw new InvalidArgumentException('Invalid Web Push subscription.');
        }

        $subscription = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($subscription)) {
            throw new InvalidArgumentException('Invalid Web Push subscription.');
        }

        $device = coveted_notification_register_pwa_device(
            $user,
            $clientId,
            $subscription,
            (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
        coveted_push_client_cookie($clientId);

        echo coveted_json([
            'ok' => true,
            'device' => [
                'public_id' => (string)$device['public_id'],
                'status' => (string)$device['status'],
            ],
        ]);
        exit;
    }

    if ($action === 'disable') {
        $clientId = coveted_notification_validate_client_id($clientId);
        coveted_notification_disable_device($user, $clientId);
        coveted_push_client_cookie('', true);
        echo coveted_json(['ok' => true]);
        exit;
    }

    throw new InvalidArgumentException('Unsupported push subscription action.');
} catch (JsonException|InvalidArgumentException $e) {
    http_response_code(422);
    echo coveted_json(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Coveted push subscription error: ' . $e->getMessage());
    http_response_code(500);
    echo coveted_json(['ok' => false, 'error' => 'Unable to update push notifications right now.']);
}
