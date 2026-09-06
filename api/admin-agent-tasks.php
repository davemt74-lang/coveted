<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_agent_tasks.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $admin = coveted_require_system_admin();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        echo coveted_json(['ok' => false, 'error' => 'GET required.']);
        exit;
    }

    $counts = coveted_admin_agent_task_counts($admin);
    echo coveted_json([
        'ok' => true,
        'available' => true,
        'active_total' => (int)$counts['suggested'] + (int)$counts['approved'] + (int)$counts['in_progress'],
        'counts' => $counts,
        'href' => '/admin/agent-tasks.php',
    ]);
} catch (Throwable $e) {
    error_log('Admin Agent task summary failed: ' . $e->getMessage());
    http_response_code(500);
    echo coveted_json(['ok' => false, 'available' => false, 'error' => 'Task queue summary is temporarily unavailable.']);
}
