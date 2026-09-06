<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($content === false) {
        fwrite(STDERR, "Missing Group Loyalty file: {$path}\n");
        exit(1);
    }
    return $content;
};
$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Group Loyalty contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Group Loyalty contract failed: {$label}\n");
        exit(1);
    }
};

$migration = $read('database/migrations/20260906_group_loyalty_membership_status.sql');
$schemaFragment = $read('database/schema-loyalty.sql');
$installer = $read('app/installer.php');
$service = $read('app/loyalty.php');
$memberPage = $read('loyalty.php');
$adminPage = $read('admin/loyalty.php');
$bootstrap = $read('app/bootstrap.php');
$adminUi = $read('app/admin_ui.php');
$worker = $read('scripts/reconcile-lifecycle.php');
$branding = $read('app/site_branding.php');
$agentJs = $read('assets/js/admin-agent-live-business-v1.js');
$workflow = $read('.github/workflows/php-lint.yml');
$events = $read('app/events.php');

// Durable private points + milestones for existing and fresh installations.
$contains($migration, 'CREATE TABLE IF NOT EXISTS loyalty_point_ledger', 'private point ledger migration is required');
$contains($schemaFragment, 'CREATE TABLE IF NOT EXISTS loyalty_point_ledger', 'new installs need the loyalty schema fragment');
$contains($installer, "database/schema-loyalty.sql", 'installer must apply loyalty schema fragment');
$contains($migration, 'UNIQUE KEY uq_loyalty_source (user_id,source_type,source_ref)', 'point sources must be idempotent');
$contains($migration, 'CREATE TABLE IF NOT EXISTS loyalty_milestones', 'milestones must be durable');
$contains($migration, 'UNIQUE KEY uq_loyalty_milestone (user_id,group_id,milestone_key)', 'milestones must be idempotent');
$missing($migration, 'points_balance', 'schema must not introduce a mutable points balance');
$missing($service, 'UPDATE loyalty_point_ledger', 'ledger entries must remain append-only');
$missing($service, 'DELETE FROM loyalty_point_ledger', 'ledger entries must remain append-only');
$contains($service, 'function coveted_loyalty_is_duplicate(PDOException $e): bool', 'duplicate handling must distinguish true duplicate-key errors');
$contains($service, "(int)(\$e->errorInfo[1] ?? 0) === 1062", 'integrity errors other than MySQL duplicate key must not be swallowed');

// V1 earning rules are canonical verified behavior only. Redemption itself is
// intentionally zero points to avoid reward-consumption farming.
$contains($service, 'const COVETED_LOYALTY_ATTENDANCE_POINTS = 100;', 'verified attendance should award 100 points');
$contains($service, 'const COVETED_LOYALTY_HOST_POINTS = 50;', 'lead/cohost contribution should award 50 points');
$contains($service, 'const COVETED_LOYALTY_RETURN_POINTS = 40;', 'verified return should award 40 points');
$contains($service, "e.status='completed'", 'attendance/host points require completed events');
$contains($service, "ea.status IN ('checked_in','attended','left_early')", 'attendance points require canonical verified attendance');
$contains($service, "eh.host_role IN ('lead','cohost')", 'host points exclude check-in-only assignment');
$contains($service, "c.trigger_key IN ('return_visit','guest_return')", 'return points require canonical return engine trigger');
$contains($service, "'$.source_reward_issuance_id'", 'return points require exact source reward linkage');
$contains($service, "'benefit_claim' => 0", 'benefit claim alone must not earn points');

// Status is derived from local group history while lifetime points remain
// group-independent for future cross-city/travel recognition.
$contains($service, "'community_contributor'", 'highest relationship tier must exist');
$contains($service, 'COVETED_LOYALTY_RECONNECT_DAYS = 90', 'reconnect state must use durable 90-day inactivity rule');
$contains($service, "'activity_state' => \$activity", 'reconnect must be an activity overlay, not a tier reset');
$contains($service, 'COUNT(DISTINCT group_id) AS groups_with_points', 'member view must expose cross-group lifetime foundation');
$contains($service, 'Lifetime Coveted Points are group-independent', 'travel-ready lifetime point policy must be explicit');

// Milestones are canonical, durable relationship moments. Candidate queries
// must select missing work only so large installs cannot starve later members,
// and historical event milestones must use the actual Nth attendance date.
foreach (['first_event','event_3','event_5','event_10','event_25','first_return','first_host','membership_year_1'] as $milestone) {
    $contains($service, "'{$milestone}'", "{$milestone} milestone must exist");
}
$contains($service, 'function coveted_loyalty_nth_attendance(', 'event milestone backfill must resolve the exact Nth verified attendance');
$contains($service, 'LIMIT 1 OFFSET {$offset}', 'event milestone date must come from the Nth attendance row');
$contains($service, "lm.milestone_key='event_25'", 'event milestone candidate query must exclude completed 25-event milestones');
$contains($service, 'AND lm.milestone_key=?', 'first-return/host candidates must exclude already achieved milestones');

// Reuse the one lifecycle worker. Do not create a second cron architecture or
// flood the audit log when a five-minute pass has no Loyalty changes.
$contains($service, "const COVETED_LOYALTY_LOCK = 'coveted:group-loyalty:v1';", 'loyalty reconciliation must use a nonblocking lock');
$contains($service, 'SELECT GET_LOCK(?,0)', 'loyalty reconciliation must skip overlapping runs');
$contains($service, 'if ($changed > 0 || $failures > 0)', 'no-op loyalty passes must not write repetitive audit rows');
$contains($worker, "require_once dirname(__DIR__) . '/app/loyalty.php';", 'existing lifecycle worker must load loyalty engine');
$contains($worker, '$loyalty = coveted_loyalty_reconcile($limit);', 'existing lifecycle worker must reconcile loyalty');
$contains($worker, 'Coveted loyalty:', 'worker output must expose loyalty result');
$contains($worker, "!empty(\$loyalty['more_work_possible'])", 'loyalty backlog must affect worker exit');
$contains($worker, "(int)\$loyalty['failures'] > 0", 'loyalty failures must affect worker exit');

// Member/admin surfaces keep points private and avoid peer comparison.
$contains($memberPage, "coveted_page_start('Loyalty', 'Loyalty')", 'member Loyalty page must use canonical shell');
$contains($memberPage, 'Lifetime Coveted Points', 'member must see private lifetime balance');
$contains($memberPage, 'Your point balance is private', 'member privacy language is required');
$missing($memberPage, 'leaderboard.php', 'member page must not link a leaderboard');
$contains($bootstrap, "'Loyalty' => '/loyalty.php'", 'member primary navigation must expose Loyalty');
$contains($bootstrap, '<strong>Loyalty</strong><small>Private points, status and milestones</small>', 'account menu must expose private Loyalty');
$contains($adminPage, 'Group Loyalty + Membership Status', 'Admin loyalty workspace must exist');
$contains($adminPage, 'There is no Admin control that directly overwrites a balance.', 'Admin UI must preserve append-only balance model');
$contains($adminUi, "'/admin/loyalty.php'", 'Admin VALUE navigation must expose Loyalty');

// Admin Agent receives aggregate intelligence only. Loyalty insights are
// analysis-only and cannot mutate points, thresholds or economics.
$contains($service, 'function coveted_loyalty_agent_context()', 'aggregate Agent loyalty context must exist');
$contains($service, 'No member names, emails, phones, individual point balances', 'Agent loyalty privacy boundary must be explicit');
$contains($service, 'Loyalty insights are analysis-only.', 'Agent loyalty recommendations must not self-authorize action');
$contains($branding, "require_once __DIR__ . '/loyalty.php';", 'Agent snapshot must load loyalty context');
$contains($branding, "\$operations['loyalty']", 'Agent operations context must include loyalty');
$contains($branding, "'kind' => 'loyalty_intelligence'", 'Agent opportunities must identify Loyalty intelligence');
$contains($branding, "'task_sync' => false", 'loyalty insights must remain analysis-only tasks');
$contains($branding, "'execution_ready' => false", 'loyalty insights must remain non-executable');
$contains($agentJs, "label: 'Loyalty health'", 'Admin Agent must expose Loyalty starter');
$contains($agentJs, 'Do not expose individual point balances', 'starter must preserve private points boundary');

// Event authority remains unchanged.
$contains($events, 'coveted_event_require_system_admin($actor);', 'event creation/configuration remains System Admin-only');
$contains($workflow, 'php scripts/verify-group-loyalty.php', 'Group Loyalty contract must run in CI');

fwrite(STDOUT, "Group Loyalty + Membership Status contract verified.\n");
