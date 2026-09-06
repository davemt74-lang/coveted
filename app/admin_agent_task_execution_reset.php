<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_agent_task_execution.php';

function coveted_admin_agent_task_execution_reset(
    array $admin,
    string $taskRef,
    string $expectedTaskStatus,
    ?PDO $pdo = null
): void {
    coveted_admin_agent_tasks_require_admin($admin);
    $pdo ??= coveted_db();
    coveted_admin_agent_task_execution_require_schema($pdo);
    $expectedTaskStatus = trim($expectedTaskStatus);
    if (!in_array($expectedTaskStatus, ['approved','suggested','dismissed'], true)) {
        throw new InvalidArgumentException('That task status cannot reset Agent execution authorization.');
    }

    $pdo->beginTransaction();
    try {
        $task = coveted_admin_agent_task_execution_row_locked($pdo, $admin, $taskRef);
        if (!$task) {
            throw new InvalidArgumentException('Admin Agent task not found.');
        }
        if ((string)$task['status'] !== $expectedTaskStatus) {
            throw new InvalidArgumentException('This task changed before its Agent execution state could be reset.');
        }
        if ((string)($task['execution_state'] ?? 'idle') === 'running') {
            throw new InvalidArgumentException('Wait for the autonomous Agent run to finish before changing this task authorization.');
        }

        $hadExecution = (string)($task['execution_state'] ?? 'idle') !== 'idle'
            || trim((string)($task['execution_thread_ref'] ?? '')) !== ''
            || trim((string)($task['execution_request_id'] ?? '')) !== '';

        $stmt = $pdo->prepare(
            "UPDATE admin_agent_tasks
             SET execution_state = 'idle', execution_thread_ref = NULL, execution_request_id = NULL,
                 execution_provider = NULL, execution_goal = NULL, execution_summary = NULL,
                 execution_error = NULL, execution_started_at = NULL, execution_completed_at = NULL,
                 updated_by_user_id = ?, updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND owner_user_id = ? AND status = ? AND execution_state <> 'running'"
        );
        $stmt->execute([
            (int)$admin['id'],
            (int)$task['id'],
            (int)$admin['id'],
            $expectedTaskStatus,
        ]);

        if ($hadExecution) {
            coveted_audit(
                'admin.agent_task_execution_reset',
                'admin_agent_task',
                (string)$task['public_id'],
                ['status'=>$expectedTaskStatus,'previous_execution_state'=>(string)$task['execution_state']],
                (int)$admin['id']
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
