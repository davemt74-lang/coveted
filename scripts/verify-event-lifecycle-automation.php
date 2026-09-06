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
        fwrite(STDERR, "Event lifecycle automation contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Event lifecycle automation contract failed: {$label}\n");
        exit(1);
    }
};

$service = $read('app/event_lifecycle_automation.php');
$worker = $read('scripts/reconcile-lifecycle.php');
$admin = $read('admin/event-automation.php');
$adminUi = $read('app/admin_ui.php');
$events = $read('app/events.php');
$rewards = $read('app/rewards.php');
$notifications = $read('app/notifications.php');

// Scheduler must remain CLI-only and reuse the existing lifecycle worker.
$contains($worker, "if (PHP_SAPI !== 'cli')", 'lifecycle worker must remain CLI-only');
$contains($worker, "require_once dirname(__DIR__) . '/app/event_lifecycle_automation.php';", 'existing lifecycle worker must load event automation');
$contains($worker, 'coveted_lifecycle_reconcile($limit, $maxBatches)', 'existing invitation/Guest Pass reconciliation must remain canonical');
$contains($worker, 'coveted_event_lifecycle_automation_reconcile($limit)', 'worker must invoke bounded event automation');
$contains($worker, "exit(3);", 'bounded item failures must surface a non-zero worker exit');

// No new scheduler schema or runtime DDL.
foreach (['CREATE TABLE', 'ALTER TABLE', 'DROP TABLE', 'TRUNCATE TABLE'] as $ddl) {
    $missing($service, $ddl, 'event automation must not create or alter runtime schema: ' . $ddl);
}

// Overlap/replay safety.
$contains($service, 'SELECT GET_LOCK(?, 0)', 'worker must use a non-blocking named database lock');
$contains($service, 'SELECT RELEASE_LOCK(?)', 'worker must release its database lock');
$contains($service, 'NOT EXISTS (', 'worker queries must exclude already-processed rows');
$contains($service, "n.dedupe_key = CONCAT('event-published:'", 'publish notifications need durable dedupe');
$contains($service, "n.dedupe_key = CONCAT('event-rsvp-24h:'", 'RSVP reminders need durable dedupe');
$contains($service, "'event-attendee-'", 'attendee milestone reminders need durable dedupe');
$contains($service, "n.dedupe_key = CONCAT('event-reveal:'", 'mystery reveals need durable dedupe');
$contains($service, "n.dedupe_key = CONCAT('event-post:'", 'post-event opening needs durable dedupe');
$contains($service, "'event-' . $trigger . ':'", 'reward issuance needs deterministic event idempotency keys');
$contains($rewards, 'function coveted_reward_issue(', 'canonical reward issuance service is required');
$contains($rewards, 'coveted_reward_existing_idempotent', 'canonical reward issuance must retain idempotency handling');
$contains($notifications, 'UNIQUE KEY', 'notification service/schema contract should preserve dedupe-backed writes');

// Campaign bounds and trigger scope.
$contains($service, "['attendance', 'completion']", 'automation may only issue attendance/completion event rewards');
$contains($service, 'c.quantity_limit IS NULL', 'campaign quantity limits must be respected before automation');
$contains($service, 'c.per_user_limit IS NULL', 'per-member campaign limits must be respected before automation');
$contains($service, "ea.status IN ('checked_in','attended','left_early')", 'rewards require verified attendance states');
$contains($service, "c.trigger_key IN ('attendance','completion')", 'exceptions must only report automated reward triggers');

// The worker automates delivery, not event authority.
$contains($events, 'function coveted_event_require_system_admin(array $actor): void', 'System Admin event authority contract is missing');
$contains($events, "function coveted_event_create(array \$actor, int \$groupId, array \$data): array\n{\n    coveted_event_require_system_admin(\$actor);", 'event creation must remain System Admin-only');
foreach (['coveted_event_create(', 'coveted_event_set_status(', 'coveted_event_update(', 'coveted_event_set_location(', 'coveted_event_assign_host('] as $mutation) {
    $missing($service, $mutation, 'automation service must not perform event-authority mutation: ' . $mutation);
}

// Mystery and post-event delivery are read from canonical state.
$contains($service, 'FROM event_mystery_reveals emr', 'automation must read canonical scheduled mystery reveals');
$contains($service, "emr.reveal_at <= NOW()", 'mystery delivery must wait for the canonical reveal time');
$contains($service, "e.status = 'completed'", 'post-event/completion automation must require canonical completed state');
$contains($service, "ea.status IN ('checked_in','attended','left_early')", 'post-event value must remain attendance-scoped');

// Admin exception surface is System Admin-only and read-only.
$contains($admin, 'coveted_require_system_admin()', 'Event Automation dashboard must remain System Admin-only');
$contains($admin, 'coveted_event_lifecycle_automation_exceptions(50)', 'Admin dashboard must use bounded exception snapshot');
$contains($admin, 'php scripts/reconcile-lifecycle.php', 'Admin dashboard must show the lifecycle worker command');
$contains($admin, 'php scripts/dispatch-push.php', 'Admin dashboard must distinguish push transport worker');
$contains($admin, 'No public worker endpoint', 'Admin dashboard must document worker exposure boundary');
$missing($admin, 'method="post"', 'Event Automation dashboard must stay read-only');
$missing($admin, '<script', 'Event Automation dashboard must not require inline script');
$missing($admin, '<style', 'Event Automation dashboard must not require inline style');
$contains($adminUi, "coveted_admin_nav_link($active, 'event-automation', '/admin/event-automation.php', 'Event Automation')", 'Admin navigation must expose Event Automation');

fwrite(STDOUT, "Event lifecycle automation contract verified.\n");
