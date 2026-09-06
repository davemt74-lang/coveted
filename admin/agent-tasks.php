<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/admin_agent_brain.php';
require_once dirname(__DIR__) . '/app/admin_agent_tasks.php';
require_once dirname(__DIR__) . '/app/site_branding.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$error = '';
$notice = trim((string)($_SESSION['agent_task_notice'] ?? ''));
unset($_SESSION['agent_task_notice']);
$status = strtolower(trim((string)($_GET['status'] ?? 'active')));
$allowedStatuses = ['active','suggested','approved','in_progress','completed','dismissed','all'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'active';
}

$storageAvailable = false;
try {
    $storageAvailable = coveted_admin_agent_tasks_schema_available($pdo);
    if (!$storageAvailable) {
        $error = 'Task queue storage is unavailable. Import database/migrations/20260905_admin_agent_tasks.sql.';
    }
} catch (Throwable $e) {
    error_log('Admin Agent task schema check failed: ' . $e->getMessage());
    $error = 'Task queue storage is temporarily unavailable.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $storageAvailable) {
    coveted_require_csrf();
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'create') {
            coveted_admin_agent_task_create_manual(
                $admin,
                (string)($_POST['title'] ?? ''),
                (string)($_POST['detail'] ?? ''),
                (int)($_POST['priority'] ?? 2),
                $pdo
            );
            $_SESSION['agent_task_notice'] = 'Task added to the approved queue.';
        } elseif ($action === 'sync') {
            $snapshot = coveted_site_branding_enrich_agent_snapshot(coveted_admin_agent_snapshot($admin, $pdo));
            $result = coveted_admin_agent_tasks_sync_opportunities(
                $admin,
                (array)($snapshot['opportunities'] ?? []),
                $pdo
            );
            $_SESSION['agent_task_notice'] = 'Suggestions refreshed: '
                . (int)$result['created'] . ' created, '
                . (int)$result['updated'] . ' updated, '
                . (int)$result['skipped'] . ' skipped.';
        } elseif ($action === 'status') {
            coveted_admin_agent_task_set_status(
                $admin,
                (string)($_POST['task_ref'] ?? ''),
                (string)($_POST['status'] ?? ''),
                $pdo,
                (string)($_POST['expected_status'] ?? '')
            );
            $_SESSION['agent_task_notice'] = 'Task status updated.';
        } else {
            throw new InvalidArgumentException('Unsupported task queue action.');
        }
        coveted_redirect('/admin/agent-tasks.php?status=' . rawurlencode($status));
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Admin Agent task queue action failed: ' . $e->getMessage());
        $error = 'Unable to complete that task queue action.';
    }
}

$counts = ['suggested'=>0,'approved'=>0,'in_progress'=>0,'completed'=>0,'dismissed'=>0];
$tasks = [];
if ($storageAvailable) {
    $counts = coveted_admin_agent_task_counts($admin, $pdo);
    $tasks = coveted_admin_agent_tasks_list($admin, $status, 200, $pdo);
}
$adminCounts = coveted_admin_ui_counts($pdo);
$activeTotal = $counts['suggested'] + $counts['approved'] + $counts['in_progress'];
$statusLabels = [
    'suggested' => 'Suggested',
    'approved' => 'Approved',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
    'dismissed' => 'Dismissed',
];

coveted_page_start('Agent Task Queue', '', true);
coveted_admin_ui_start($admin, 'agent', 'Agent Task Queue', $adminCounts);
?>
<div class="cv-admin-page-head cv-agent-task-page-head">
    <div>
        <span class="cv-eyebrow">ADMIN AGENT · WORK QUEUE</span>
        <h1>Task Queue</h1>
        <p>Turn Agent opportunities and Admin work into a persistent operating queue. Suggested tasks require an explicit queue decision; completed and dismissed suggestions are never silently reopened.</p>
    </div>
    <div class="cv-agent-task-head-actions">
        <a class="cv-button cv-button-soft" href="/admin/agent.php">Back to Agent</a>
        <?php if ($storageAvailable): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                <input type="hidden" name="action" value="sync">
                <button class="cv-button cv-button-primary" type="submit">Refresh Agent Suggestions</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<div class="cv-agent-task-metrics" aria-label="Agent task queue counts">
    <a class="<?= $status === 'active' ? 'is-active' : '' ?>" href="/admin/agent-tasks.php?status=active"><span>Active</span><strong><?= $activeTotal ?></strong></a>
    <?php foreach (['suggested','approved','in_progress','completed','dismissed'] as $key): ?>
        <a class="<?= $status === $key ? 'is-active' : '' ?>" href="/admin/agent-tasks.php?status=<?= coveted_e($key) ?>"><span><?= coveted_e($statusLabels[$key]) ?></span><strong><?= (int)$counts[$key] ?></strong></a>
    <?php endforeach; ?>
</div>

<?php if ($storageAvailable): ?>
<section class="cv-admin-panel cv-agent-task-create">
    <div class="cv-admin-panel-head">
        <div>
            <span class="cv-eyebrow">ADD WORK</span>
            <h2>Create an approved task</h2>
        </div>
        <span class="cv-status">Manual · Admin</span>
    </div>
    <form method="post" class="cv-agent-task-create-form">
        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <label>Task title<input name="title" maxlength="190" required placeholder="What needs to get done?"></label>
        <label>Priority<select name="priority"><option value="1">P1 · High</option><option value="2" selected>P2 · Normal</option><option value="3">P3 · Later</option></select></label>
        <label class="cv-agent-task-detail">Details<textarea name="detail" rows="3" maxlength="4000" placeholder="Optional context, definition of done, or follow-up notes"></textarea></label>
        <div class="cv-agent-task-create-actions"><button class="cv-button cv-button-primary" type="submit">Add Task</button></div>
    </form>
</section>
<?php endif; ?>

<section class="cv-agent-task-list" aria-label="Agent tasks">
    <?php if ($storageAvailable && !$tasks): ?>
        <div class="cv-card cv-empty"><h3>No tasks in this view.</h3><p>Refresh Agent Suggestions or create an approved task above.</p></div>
    <?php endif; ?>

    <?php foreach ($tasks as $task): ?>
        <?php
        $taskStatus = (string)$task['status'];
        $sourceHref = trim((string)($task['source_href'] ?? ''));
        $allowedTransitions = coveted_admin_agent_task_allowed_transitions($taskStatus);
        ?>
        <article class="cv-admin-panel cv-agent-task-card is-<?= coveted_e(str_replace('_','-',$taskStatus)) ?>">
            <div class="cv-agent-task-copy">
                <div class="cv-tag-row">
                    <span class="cv-status">P<?= (int)$task['priority'] ?></span>
                    <span class="cv-pill"><?= coveted_e($statusLabels[$taskStatus] ?? ucfirst($taskStatus)) ?></span>
                    <span class="cv-pill"><?= coveted_e((string)$task['source_type'] === 'opportunity' ? 'Agent opportunity' : 'Manual') ?></span>
                </div>
                <h2><?= coveted_e((string)$task['title']) ?></h2>
                <?php if (trim((string)($task['detail'] ?? '')) !== ''): ?><p><?= nl2br(coveted_e((string)$task['detail'])) ?></p><?php endif; ?>
                <div class="cv-agent-task-meta">
                    <span>Updated <?= coveted_e(coveted_utc_datetime((string)$task['updated_at'])->setTimezone(coveted_timezone())->format('M j, g:i A')) ?></span>
                    <?php if ($sourceHref !== '' && str_starts_with($sourceHref, '/admin/')): ?><a href="<?= coveted_e($sourceHref) ?>">Open related Admin workspace →</a><?php endif; ?>
                </div>
            </div>
            <div class="cv-agent-task-controls">
                <?php foreach ($allowedTransitions as $key): ?>
                    <?php $label = $statusLabels[$key] ?? ucfirst(str_replace('_', ' ', $key)); ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="task_ref" value="<?= coveted_e((string)$task['public_id']) ?>">
                        <input type="hidden" name="expected_status" value="<?= coveted_e($taskStatus) ?>">
                        <input type="hidden" name="status" value="<?= coveted_e($key) ?>">
                        <button class="cv-button <?= in_array($key,['completed','in_progress'],true) ? 'cv-button-primary' : 'cv-button-soft' ?>" type="submit"><?= coveted_e($label) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
