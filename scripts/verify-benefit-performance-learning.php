<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($content === false) {
        fwrite(STDERR, "Missing Benefit performance file: {$path}\n");
        exit(1);
    }
    return $content;
};
$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Benefit performance contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Benefit performance contract failed: {$label}\n");
        exit(1);
    }
};

$service = $read('app/benefit_performance.php');
$page = $read('admin/benefit-performance.php');
$branding = $read('app/site_branding.php');
$tasks = $read('app/admin_agent_tasks.php');
$actions = $read('app/admin_agent_actions.php');
$adminUi = $read('app/admin_ui.php');
$js = $read('assets/js/admin-agent-live-business-v1.js');
$events = $read('app/events.php');

// Read-only performance model: no schema ownership or mutation surface.
$contains($service, 'Read-only program metrics.', 'service purpose must remain read-only');
$contains($service, 'function coveted_benefit_performance_snapshot(', 'performance snapshot is required');
$contains($service, 'function coveted_benefit_performance_agent_context(', 'Agent performance context is required');
$missing($service, 'CREATE TABLE', 'performance intelligence must not create runtime schema');
$missing($service, 'ALTER TABLE', 'performance intelligence must not alter runtime schema');
$missing($service, 'INSERT INTO ', 'performance intelligence must not insert records');
$missing($service, 'UPDATE ', 'performance intelligence must not update records');
$missing($service, 'DELETE FROM ', 'performance intelligence must not delete records');
$missing($service, 'coveted_benefit_program_set_status(', 'performance intelligence must never change program status');
$missing($service, 'coveted_benefit_program_create_draft(', 'performance intelligence must never create drafts by itself');
$missing($service, 'coveted_reward_issue(', 'performance intelligence must never issue rewards');

// Return conversion must use exact source linkage from the canonical return engine.
$contains($service, "JSON_UNQUOTE(JSON_EXTRACT(followup.metadata_json, '$.source_reward_issuance_id')) = source.public_id", 'return conversion must use exact source issuance linkage');
$contains($service, "followup_campaign.trigger_key IN ('return_visit','guest_return')", 'return conversion must require canonical return triggers');
$contains($service, 'Return conversions use the exact source_reward_issuance_id', 'exact attribution boundary must remain documented');

// Learning cohorts must avoid judging brand-new issuances as failures.
$contains($service, 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)', 'matured claim cohort must remain at least seven days old');
$contains($service, "'matured_claim_rate'", 'matured claim rate is required');
$contains($service, '$maturedIssued < 5', 'learning band must require a minimum matured sample');
$contains($service, '$matured >= 10 && $maturedRate <= 15.0', 'weak-performance signal must require enough matured data');

// Follow-on attendance/benefit use is bounded observational behavior, not causation.
$contains($service, 'COVETED_BENEFIT_PERFORMANCE_FOLLOW_ON_DAYS = 90', 'follow-on observation window must stay bounded');
$contains($service, "ea2.status IN ('checked_in','attended','left_early')", 'later attendance must require verified attendance');
$contains($service, 'c2.metadata_json LIKE', 'later benefit query must scope through campaign metadata');
$contains($service, 'benefit_program_builder', 'later benefit use must stay within Builder-owned Benefit Programs');
$contains($service, 'not proof of causation', 'performance model must explicitly reject causal overclaiming');

// Recommendations are analysis-only. They can suggest review/testing but cannot
// become autonomous task execution from performance context alone.
$contains($service, "'kind' => 'successful_pool_review'", 'successful low-pool review signal is required');
$contains($service, "'kind' => 'underperforming_program_review'", 'underperforming program review signal is required');
$contains($service, "'kind' => 'clone_candidate'", 'future-template signal is required');
$contains($service, "'kind' => 'return_behavior_signal'", 'return behavior signal is required');
$contains($service, "'task_sync' => false", 'all performance insights must opt out of task sync');
$contains($service, "'execution_ready' => false", 'all performance insights must remain non-executable');
$contains($service, 'Never refill a pool, change economics, pause, archive or launch a program from performance context alone.', 'Agent performance policy must forbid autonomous economics/status changes');
$contains($tasks, "array_key_exists('task_sync', \$item) && \$item['task_sync'] === false", 'task queue must honor analysis-only opt-out');
$missing($actions, "'refill_benefit_program'", 'performance build must not add autonomous refill action');
$missing($actions, "'resize_benefit_program'", 'performance build must not add autonomous pool resize action');

// Privacy: aggregate rows only, no member identity fields exposed.
$contains($service, 'No member names, emails, phone numbers, notes or person-level CRM records are exposed.', 'dashboard privacy boundary is required');
$missing($service, 'display_name', 'member names must not enter performance context');
$missing($service, 'u.email', 'member emails must not enter performance context');
$missing($service, 'u.phone', 'member phone numbers must not enter performance context');

// Admin dashboard is read-only and discoverable.
$contains($page, 'coveted_require_system_admin()', 'performance dashboard must require System Admin');
$contains($page, 'Observed behavior, not invented causation.', 'dashboard must explain attribution limits');
$contains($page, 'BENEFIT PERFORMANCE', 'performance dashboard heading is required');
$missing($page, '<form', 'performance dashboard must not expose mutations');
$missing($page, 'INSERT INTO', 'performance dashboard must not bypass canonical services');
$missing($page, 'UPDATE ', 'performance dashboard must not bypass canonical services');
$contains($adminUi, "'/admin/benefit-performance.php'", 'Admin VALUE navigation must expose Benefit Performance');

// Agent snapshot uses the same performance model; labels remain stored data and
// performance insights remain analysis-only when added to the opportunity UI.
$contains($branding, "require_once __DIR__ . '/benefit_performance.php';", 'Agent snapshot must load Benefit performance');
$contains($branding, 'coveted_benefit_performance_agent_context()', 'Agent snapshot must use canonical performance context');
$contains($branding, "\$operations['benefit_performance']", 'Agent operations context must include performance');
$contains($branding, "'task_sync' => false", 'performance Agent opportunities must remain analysis-only');
$contains($branding, "'execution_ready' => false", 'performance Agent opportunities must remain non-executable');

// Direct Agent starter is analysis-only and explicitly preserves approval rules.
$contains($js, "label: 'Benefit performance'", 'Admin Agent must expose Benefit performance starter');
$contains($js, 'Do not refill pools, change economics, pause, archive, clone, create or launch anything unless I explicitly ask', 'performance starter must prohibit autonomous mutation');

// Event authority remains unchanged.
$contains($events, 'coveted_event_require_system_admin($actor);', 'event creation/configuration authority must remain System Admin-only');

fwrite(STDOUT, "Benefit Program performance and learning contract verified.\n");
