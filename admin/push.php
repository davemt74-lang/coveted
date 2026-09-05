<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/push.php';
require_once dirname(__DIR__) . '/app/notification_events.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$admin = coveted_require_system_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        coveted_push_cancel_inactive_deliveries(coveted_db());
        $status = coveted_push_config_status();
        echo coveted_json([
            'ok' => true,
            'push' => [
                'enabled' => (bool)$status['enabled'],
                'configured' => (bool)$status['configured'],
                'subject_ready' => (bool)$status['subject_ready'],
                'public_key_ready' => (bool)$status['public_key_ready'],
                'private_key_ready' => (bool)$status['private_key_ready'],
                'library_ready' => (bool)$status['library_ready'],
                'batch_size' => (int)$status['batch_size'],
            ],
            'stats' => coveted_notification_admin_stats(),
            'deliveries' => coveted_notification_recent_deliveries_admin(25),
        ]);
        exit;
    } catch (Throwable $e) {
        error_log('Coveted Admin push state error: ' . $e->getMessage());
        http_response_code(500);
        echo coveted_json(['ok' => false, 'error' => 'Unable to load Web Push state.']);
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
    if ($action !== 'dispatch') {
        throw new InvalidArgumentException('Unsupported Web Push Admin action.');
    }

    $projection = coveted_notification_reconcile(null, 500);
    $summary = coveted_push_dispatch_as_admin($admin, (int)($_POST['limit'] ?? 0));

    coveted_audit(
        'notification.push_dispatched',
        'notification_delivery',
        null,
        ['projection' => $projection, 'delivery' => $summary],
        (int)$admin['id']
    );

    echo coveted_json([
        'ok' => true,
        'projection' => $projection,
        'delivery' => $summary,
        'stats' => coveted_notification_admin_stats(),
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo coveted_json(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Coveted Admin Web Push dispatch failed: ' . $e->getMessage());
    http_response_code(500);
    echo coveted_json(['ok' => false, 'error' => 'Unable to dispatch Web Push right now.']);
}
