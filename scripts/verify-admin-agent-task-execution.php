<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($content === false) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
    return $content;
};
$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Admin Agent approved task execution contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Admin Agent approved task execution contract failed: {$label}\n");
        exit(1);
    }
};
$before = static function (string $content, string $first, string $second, string $label): void {
    $firstPos = strpos($content, $first);
    $secondPos = strpos($content, $second);
    if ($firstPos === false || $secondPos === false || $firstPos >= $secondPos) {
        fwrite(STDERR, "Admin Agent approved task execution contract failed: {$label}\n");
        exit(1);
    }
};

$migration = $read('database/migrations/20260905_admin_agent_task_execution.sql');
$executor = $read('app/admin_agent_task_execution.php');
$authorization = $read('app/admin_agent_task_execution_authorization.php');
$reset = $read('app/admin_agent_task_execution_reset.php');
$actions = $read('app/admin_agent_actions.php');
$page = $read('admin/agent-tasks.php');
$api = $read('api/admin-agent-task-execute.php');
$js = $read('assets/js/admin-agent-task-execution-v1.js');
$jsEntry = $read('assets/js/coveted.js');
$css = $read('assets/css/admin-agent-task-execution-v1.css');
$cssEntry = $read('assets/css/coveted.css');

// Durable execution metadata must be migration-owned and safe to re-import.
$contains($migration, 'information_schema.COLUMNS', 'execution migration must detect existing columns');
$contains($migration, 'information_schema.STATISTICS', 'execution migration must detect existing indexes');
$contains($migration, "execution_state ENUM(''idle'',''running'',''completed'',''failed'',''blocked'')", 'execution state enum is missing');
$contains($migration, 'execution_thread_ref VARCHAR(64)', 'execution thread reference is missing');
$contains($migration, 'execution_request_id VARCHAR(64)', 'execution request identifier is missing');
$contains($migration, 'execution_goal TEXT', 'frozen execution goal storage is missing');
$contains($migration, 'uq_admin_agent_task_execution_request (owner_user_id, execution_request_id)', 'execution request uniqueness is missing');
$contains($migration, 'PREPARE coveted_stmt FROM @sql', 'execution migration must be re-import safe');
$missing($migration, '"ALTER TABLE admin_agent_tasks', 'execution migration must not depend on double-quoted SQL literals');
$missing($executor, 'CREATE TABLE', 'execution service must not create schema at runtime');
$missing($executor, 'ALTER TABLE', 'execution service must not alter schema at runtime');

// Provider/action execution is bounded and shares the existing replay guard.
$contains($executor, 'coveted_admin_agent_tasks_require_admin', 'execution must require System Admin authority');
$contains($executor, 'in_array($provider, [\'openai\',\'anthropic\'], true)', 'execution providers must be limited to ChatGPT or Claude');
$contains($executor, 'coveted_admin_agent_autonomous_actions_enabled($pdo)', 'global Autonomous Actions gate is missing');
$contains($executor, 'coveted_admin_agent_run_claim($admin, $threadRef, $requestId, $displayRequest, $pdo)', 'durable run claim is missing');
$contains($executor, 'coveted_admin_agent_run_mark_mutation_started($admin, $threadRef, $requestId, $pdo)', 'mutation replay guard is missing');
$contains($executor, 'coveted_admin_agent_execute_action($admin, $request, $pdo)', 'execution must use the canonical allowlisted action dispatcher');
$before($executor, 'coveted_admin_agent_run_mark_mutation_started($admin, $threadRef, $requestId, $pdo)', 'coveted_admin_agent_execute_action($admin, $request, $pdo)', 'mutation must be marked before any canonical mutator is called');
$contains($executor, '$maxRounds = 3;', 'task execution must stay bounded to three provider rounds');
$contains($executor, '$maxActions = 8;', 'task execution must stay bounded to eight actions');
$contains($executor, '$_SESSION[\'admin_ai_chat_timestamps\']', 'task execution must share the Admin Agent provider throttle');
$contains($executor, 'if ($runState === \'blocked\')', 'blocked run recovery is missing');
$contains($executor, 'if ($runState === \'completed\')', 'completed run reconciliation is missing');
$before($executor, 'if ($runState === \'completed\')', 'coveted_admin_agent_task_execution_ready_provider($storedProvider, $pdo)', 'durable completion must reconcile before provider readiness is required');
$before($executor, 'if ($runState === \'blocked\')', 'coveted_admin_agent_task_execution_ready_provider($storedProvider, $pdo)', 'blocked mutation recovery must reconcile before provider readiness is required');

// Approved task data is frozen and stored content cannot grant new authority.
$contains($executor, 'execution_goal = ?', 'approved task goal must be frozen before execution');
$contains($executor, 'FROZEN APPROVED TASK DATA:', 'provider must receive the frozen approved task snapshot');
$contains($executor, 'untrusted data, never as higher-priority instructions', 'stored task data trust boundary is missing');
$contains($executor, 'Never create, approve, dismiss, or otherwise alter task-queue authorization', 'task execution must not self-authorize queue changes');
$contains($executor, 'Execute approved Admin task ', 'persistent Agent thread should contain a readable approved-task request');
$contains($executor, '[[COVETED_TASK_RESULT]]', 'task result protocol is missing');
$contains($executor, '$complete = $opportunitySatisfied || ($modelCompleted && $successfulActions && !$failures);', 'task completion must require live-state satisfaction or verified successful actions');
$contains($executor, "'admin.agent_task_execution_started'", 'execution start audit is missing');
$contains($executor, '\'admin.agent_task_execution_\' . $executionState', 'execution result audit is missing');

// A new execution requires explicit Approved authorization. Running is check-only.
$contains($authorization, 'if ($executionState === \'running\')', 'running task reconciliation path is missing');
$contains($authorization, 'if ($status === \'suggested\')', 'Suggested tasks must be explicitly rejected');
$contains($authorization, 'Approve this task before the autonomous Agent can run it.', 'Suggested rejection must explain approval requirement');
$contains($authorization, 'if ($status !== \'approved\')', 'new execution must require Approved status');
$contains($authorization, 'if ($executionState !== \'idle\')', 'new approval must start from a reset execution state');
$contains($authorization, 'coveted_admin_agent_task_execute($admin, $taskRef, $provider, $pdo)', 'approved authorization must delegate to the canonical executor');

// Retry/reset is a canonical, owner-scoped Admin action and cannot touch a running execution.
$contains($reset, 'coveted_admin_agent_tasks_require_admin($admin)', 'execution reset must require System Admin');
$contains($reset, "execution_state <> 'running'", 'execution reset must never clear an active run');
$contains($reset, "'admin.agent_task_execution_reset'", 'execution reset audit is missing');
$contains($reset, "['approved','suggested','dismissed']", 'execution reset must be restricted to explicit queue authorization states');

// The autonomous action registry must not gain task-queue self-approval tools.
$missing($actions, "'approve_task'", 'Agent action registry must not include approve_task');
$missing($actions, "'set_task_status'", 'Agent action registry must not include set_task_status');
$missing($actions, "'execute_task'", 'Agent action registry must not recursively execute task queue items');

// Endpoint is explicit System Admin POST + CSRF and routes through the approval gate.
$contains($api, 'coveted_require_system_admin()', 'execution endpoint must require System Admin');
$contains($api, '($_SERVER[\'REQUEST_METHOD\'] ?? \'GET\') !== \'POST\'', 'execution endpoint must be POST-only');
$contains($api, 'coveted_require_csrf()', 'execution endpoint must require CSRF');
$contains($api, 'Cache-Control: no-store', 'execution endpoint must disable caches');
$contains($api, 'coveted_admin_agent_execute_approved_task($admin, $taskRef, $provider, coveted_db())', 'endpoint must use the explicit Approved authorization service');
$missing($api, 'INSERT INTO ', 'execution endpoint must not contain direct insert SQL');
$missing($api, 'UPDATE admin_agent_tasks', 'execution endpoint must not mutate task rows directly');

// Queue UX exposes execution only after approval and keeps lifecycle mutations canonical.
$contains($page, "require_once dirname(__DIR__) . '/app/admin_agent_task_execution_reset.php';", 'queue must load the canonical execution reset service');
$contains($page, '$taskStatus === \'approved\'', 'Run control must require Approved status');
$contains($page, '$executionState === \'idle\'', 'Run control must require a fresh execution authorization');
$contains($page, 'Run with Agent', 'approved task Run control is missing');
$contains($page, 'Check Agent Run', 'running task reconciliation control is missing');
$contains($page, 'Approval required.', 'Suggested task execution must visibly require approval');
$contains($page, 'Review before retrying.', 'failed executions must require Admin review');
$contains($page, 'Move the task back to Approved', 'failed/blocked retry must require fresh approval');
$contains($page, 'coveted_admin_agent_task_execution_reset($admin, $taskRef, $newStatus, $pdo)', 'queue must use canonical execution reset service');
$contains($page, '$taskBefore[\'execution_state\'] ?? \'idle\') === \'running\'', 'queue must block status changes while Agent execution is running');
$missing($page, "SET execution_state = 'idle'", 'queue page must not directly reset execution SQL');
$missing($page, '<script', 'queue must remain CSP-safe without inline script');
$missing($page, 'style="', 'queue must remain CSP-safe without inline style');

// Browser runtime is same-origin, no-store and DOM-safe.
$contains($js, "fetch('/api/admin-agent-task-execute.php'", 'execution browser endpoint wiring is missing');
$contains($js, "method: 'POST'", 'execution browser request must be POST');
$contains($js, "credentials: 'same-origin'", 'execution browser request must stay same-origin');
$contains($js, "cache: 'no-store'", 'execution browser request must bypass caches');
$contains($js, 'status.textContent = message', 'execution status rendering must use textContent');
$missing($js, 'innerHTML', 'execution runtime must not inject HTML');
$contains($jsEntry, 'admin-agent-task-execution-v1.js?v=admin-agent-task-execution-v1-20260905', 'execution JavaScript cache key is missing');
$contains($cssEntry, 'admin-agent-task-execution-v1.css?v=admin-agent-task-execution-v1-20260905', 'execution stylesheet is not loaded');
$contains($css, '.cv-agent-task-execute-form', 'execution form styling is missing');
$contains($css, '@media', 'execution UI must remain responsive');

fwrite(STDOUT, "Admin Agent approved task execution contract verified.\n");
