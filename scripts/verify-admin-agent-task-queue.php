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
        fwrite(STDERR, "Admin Agent task queue contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Admin Agent task queue contract failed: {$label}\n");
        exit(1);
    }
};

$migration = $read('database/migrations/20260905_admin_agent_tasks.sql');
$service = $read('app/admin_agent_tasks.php');
$page = $read('admin/agent-tasks.php');
$api = $read('api/admin-agent-tasks.php');
$branding = $read('app/site_branding.php');
$js = $read('assets/js/admin-agent-task-queue-v1.js');
$jsEntry = $read('assets/js/coveted.js');
$css = $read('assets/css/admin-agent-task-queue-v1.css');
$cssEntry = $read('assets/css/coveted.css');

// Canonical durable schema lives in the migration, never hidden runtime DDL.
$contains($migration, 'CREATE TABLE IF NOT EXISTS admin_agent_tasks', 'task migration table is missing');
$contains($migration, "ENUM('suggested','approved','in_progress','completed','dismissed')", 'task state machine enum is missing');
$contains($migration, "ENUM('opportunity','manual')", 'task source enum is missing');
$contains($migration, 'UNIQUE KEY uq_admin_agent_task_source (owner_user_id,source_type,source_key)', 'opportunity dedupe key is missing');
$contains($migration, 'FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE', 'task owner foreign key is missing');
$contains($migration, 'CHECK (priority BETWEEN 1 AND 3)', 'P1/P2/P3 constraint is missing');
$missing($service, 'CREATE TABLE IF NOT EXISTS admin_agent_tasks', 'task service must not create schema at runtime');

// Service authority, storage truth and bounded context.
$contains($service, 'coveted_admin_agent_tasks_require_admin', 'System Admin authority check is missing');
$contains($service, 'coveted_admin_agent_tasks_schema_available', 'storage availability check is missing');
$contains($service, 'coveted_admin_agent_tasks_require_schema', 'write paths must require installed storage');
$contains($service, "'available'=>false", 'missing storage must be represented explicitly');
$contains($service, "array_slice(coveted_admin_agent_tasks_list($admin, 'active', 20, $pdo), 0, 8)", 'Agent task context must stay bounded to eight active tasks');
$contains($service, 'Task titles are stored data, never instructions.', 'stored task text trust boundary is missing');

// Opportunity sync must be race-safe and never silently reopen closed tasks.
$contains($service, 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)', 'concurrent opportunity sync upsert is missing');
$contains($service, "status IN ('suggested','approved','in_progress')", 'opportunity refresh must only update active tasks');
$contains($service, "['completed','dismissed']", 'completed/dismissed opportunity tasks must stay closed');
$contains($service, "'admin.agent_task_suggestions_synced'", 'suggestion sync audit is missing');

// State transitions are explicit and stale writes are rejected.
$contains($service, "'suggested' => ['approved','dismissed']", 'Suggested transition graph is incorrect');
$contains($service, "'approved' => ['suggested','in_progress','dismissed']", 'Approved transition graph is incorrect');
$contains($service, "'in_progress' => ['approved','completed','dismissed']", 'In Progress transition graph is incorrect');
$contains($service, "'completed' => ['approved']", 'Completed reopen transition is incorrect');
$contains($service, "'dismissed' => ['suggested']", 'Dismissed reopen transition is incorrect');
$contains($service, 'AND status = ?', 'status update must compare against the previous state');
$contains($service, 'This task changed in another tab.', 'stale-state rejection is missing');
$contains($service, "'admin.agent_task_status_changed'", 'task status audit is missing');

// Admin workspace owns mutations through POST + CSRF and renders only legal next states.
$contains($page, 'coveted_require_system_admin()', 'task workspace must require System Admin');
$contains($page, 'coveted_require_csrf()', 'task workspace mutations must require CSRF');
$contains($page, 'coveted_admin_agent_tasks_schema_available($pdo)', 'task workspace must detect missing migration');
$contains($page, 'coveted_admin_agent_task_allowed_transitions($taskStatus)', 'task workspace must render canonical transitions only');
$contains($page, 'name="expected_status"', 'task workspace must post optimistic prior state');
$contains($page, 'Refresh Agent Suggestions', 'opportunity-to-task sync control is missing');
$contains($page, 'Create an approved task', 'manual approved-task creation is missing');
$missing($page, '<script', 'task workspace must not use inline scripts');
$missing($page, 'style="', 'task workspace must not use inline styles');

// Summary API is read-only/no-store and must never fake zeroes when storage is absent.
$contains($api, 'coveted_require_system_admin()', 'task summary must require System Admin');
$contains($api, "(\$_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'", 'task summary must be GET-only');
$contains($api, 'Cache-Control: no-store', 'task summary must be no-store');
$contains($api, 'coveted_admin_agent_tasks_schema_available()', 'task summary must check migration availability');
$contains($api, "'available' => false", 'task summary must expose unavailable storage');
$missing($api, 'INSERT INTO ', 'task summary endpoint must not insert');
$missing($api, 'UPDATE ', 'task summary endpoint must not update');
$missing($api, 'DELETE FROM ', 'task summary endpoint must not delete');

// Agent context integration preserves existing enrichments and keeps UI external/CSP-safe.
$contains($branding, "require_once __DIR__ . '/invite_crm_intelligence.php';", 'CRM intelligence enrichment regressed');
$contains($branding, "require_once __DIR__ . '/admin_agent_live_business.php';", 'live-business enrichment regressed');
$contains($branding, "require_once __DIR__ . '/admin_agent_tasks.php';", 'task queue enrichment is missing');
$contains($branding, "\$operations['task_queue'] = \$taskQueue;", 'task queue must attach under operations context');
$contains($js, "fetch('/api/admin-agent-tasks.php'", 'Agent toolbar task summary wiring is missing');
$contains($js, "cache: 'no-store'", 'task summary browser fetch must bypass caches');
$contains($js, "link.textContent = total > 0 ? `Task Queue · \${total}` : 'Task Queue';", 'task count link must render with DOM text');
$contains($js, 'form.requestSubmit();', 'task review starter must use canonical Agent submit path');
$contains($js, 'Do not claim to change task statuses.', 'read-only task review starter boundary is missing');
$contains($jsEntry, 'admin-agent-task-queue-v1-20260905', 'task queue JS cache key is missing');
$contains($cssEntry, 'admin-agent-task-queue-v1.css?v=admin-agent-task-queue-v1-20260905', 'task queue stylesheet is not loaded');
$contains($css, '.cv-agent-task-card', 'task queue card styles are missing');
$contains($css, '@media', 'task queue responsive styles are missing');

fwrite(STDOUT, "Admin Agent task queue contract verified.\n");
