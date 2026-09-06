<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_agent_task_execution.php';

/** @return array<string,mixed> */
function coveted_admin_agent_execute_approved_task(
    array $admin,
    string $taskRef,
    string $provider,
    ?PDO $pdo = null
): array {
    coveted_admin_agent_tasks_require_admin($admin);
    $pdo ??= coveted_db();
    coveted_admin_agent_task_execution_require_schema($pdo);

    $task = coveted_admin_agent_task_by_ref($admin, $taskRef, $pdo);
    if (!$task) {
        throw new InvalidArgumentException('Admin Agent task not found.');
    }

    $status = (string)$task['status'];
    $executionState = (string)($task['execution_state'] ?? 'idle');

    // A running execution is the only state that may be checked while the task
    // is In Progress. No new execution can start without a fresh Approved state.
    if ($executionState === 'running') {
        return coveted_admin_agent_task_execute($admin, $taskRef, $provider, $pdo);
    }

    if ($status === 'suggested') {
        throw new InvalidArgumentException('Approve this task before the autonomous Agent can run it.');
    }
    if ($status !== 'approved') {
        throw new InvalidArgumentException('Move this task to Approved to authorize a new autonomous Agent execution.');
    }
    if ($executionState !== 'idle') {
        throw new InvalidArgumentException('Re-approve this task to reset its previous Agent execution before running it again.');
    }

    return coveted_admin_agent_task_execute($admin, $taskRef, $provider, $pdo);
}
