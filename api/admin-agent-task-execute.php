<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_agent_task_execution.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $admin = coveted_require_system_admin();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo coveted_json(['ok'=>false,'error'=>'POST required.']);
        exit;
    }

    coveted_require_csrf();
    $taskRef = coveted_admin_agent_task_ref((string)($_POST['task_ref'] ?? ''));
    $provider = trim((string)($_POST['provider'] ?? ''));
    if ($provider === '') {
        throw new InvalidArgumentException('Choose ChatGPT or Claude for this task.');
    }

    $result = coveted_admin_agent_task_execute($admin, $taskRef, $provider, coveted_db());
    echo coveted_json($result);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo coveted_json(['ok'=>false,'state'=>'rejected','error'=>$e->getMessage()]);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $safe = preg_match('/^(Approved task execution storage|Admin Agent task storage|Too many Admin Agent provider requests|AI provider|The PHP cURL|Unable to decrypt|Configure app\.ai_credentials_key)/', $message) === 1
        ? $message
        : 'The autonomous Agent could not complete that task. Review the task and Agent thread before retrying.';
    error_log('Admin Agent task execution failed: ' . $message);
    http_response_code(502);
    echo coveted_json(['ok'=>false,'state'=>'error','error'=>$safe]);
} catch (Throwable $e) {
    error_log('Admin Agent task execution failed: ' . $e->getMessage());
    http_response_code(502);
    echo coveted_json([
        'ok'=>false,
        'state'=>'error',
        'error'=>'The autonomous Agent could not complete that task. Review the task and Agent thread before retrying.',
    ]);
}
