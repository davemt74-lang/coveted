<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $value = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($value === false) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
    return $value;
};
$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Event Communications Agent contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Event Communications Agent contract failed: {$label}\n");
        exit(1);
    }
};

$lifecycle = $read('app/event_communications_agent.php');
$communications = $read('app/event_communications.php');
$page = $read('admin/event-communications.php');
$operations = $read('app/operations.php');
$brain = $read('app/admin_agent_brain.php');
$tasks = $read('app/admin_agent_tasks.php');

$contains($lifecycle, 'function coveted_event_communications_lifecycle_snapshot', 'single-Event lifecycle snapshot is required');
$contains($lifecycle, 'function coveted_event_communications_agent_context', 'aggregate Operations/Agent context is required');
$contains($lifecycle, 'function coveted_event_communications_agent_source_task', 'canonical source-key task lookup is required');
$contains($lifecycle, "'rsvp-followup-'", 'RSVP reminder lifecycle must reuse the Phase 1 task key');
$contains($lifecycle, "'invitation-wave-'", 'Invitation handoffs must reuse the canonical Invitation Wave task key');
$contains($lifecycle, "'event-communications-'", 'confirmation and other communication work needs a communications task key');
$contains($lifecycle, "'review_followup'", 'follow-up review lifecycle state is required');
$contains($lifecycle, "'waiting_response'", 'waiting-for-response lifecycle state is required');
$contains($lifecycle, "'recalculate_after_responses'", 'response-driven forecast recalculation lifecycle state is required');
$contains($lifecycle, "'next_wave'", 'next-wave lifecycle state is required');
$contains($lifecycle, "'waitlist_first'", 'waitlist-first lifecycle state is required');
$contains($lifecycle, "'confirmation_ready'", 'confirmation lifecycle state is required');
$contains($lifecycle, 'function coveted_event_communications_confirmation_queue_state', 'confirmation lifecycle must evaluate current per-member confirmation cycles');
$contains($lifecycle, "'event-confirmation:'", 'confirmation readiness must share Phase 2 confirmation dedupe semantics');
$contains($lifecycle, "(int)\$confirmations['ready'] > 0", 'confirmation recommendation must be driven by currently unqueued confirmation cycles');
$contains($lifecycle, "JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.event_id'))", 'lifecycle must derive queue state from canonical notifications');
$contains($lifecycle, 'coveted_event_communications_reminder_candidates($admin, $event, $pdo)', 'current nudge-ready eligibility must come from canonical communications/follow-up logic');
$contains($lifecycle, "coveted_event_invitation_wave_snapshot(\$admin, (string)\$event['public_id'], \$pdo)", 'current forecast must come from canonical Invitation Wave intelligence');
$contains($lifecycle, 'coveted_event_communications_responses_since(', 'post-reminder RSVP changes must feed lifecycle state');
$contains($lifecycle, 'coveted_event_communications_agent_source_task($admin, $recommendationKey, $pdo)', 'displayed Agent task must follow the current recommendation source key');
$contains($lifecycle, "!str_starts_with(\$key, 'invitation-wave-')", 'communications Agent context must not duplicate canonical Invitation Wave opportunities');
$contains($lifecycle, 'coveted_admin_agent_tasks_sync_opportunities', 'lifecycle must reuse canonical Agent task sync');
$contains($lifecycle, 'coveted_admin_agent_task_set_status', 'explicit Admin queueing must move the tracking task through canonical task transitions');
$contains($lifecycle, "'admin.agent_event_communication_tracked'", 'Agent queue tracking must be audited');
$contains($lifecycle, "'recipient_identities_in_agent_payload'=>false", 'Agent tracking audit must explicitly exclude recipient identities');
$contains($lifecycle, 'Recipient names, emails, user IDs and reminder recipient lists are excluded.', 'privacy boundary must be explicit');
$contains($lifecycle, 'System Admin must explicitly choose recipients and queue every communication', 'authority boundary must remain explicit');
$contains($lifecycle, "coveted_system_sample_mode(\$admin,\$pdo)", 'Agent lifecycle must be disabled against live state in Sample Mode');

$missing($lifecycle, 'INSERT INTO notifications', 'Agent lifecycle must not create communications directly');
$missing($lifecycle, 'UPDATE event_invitations', 'Agent lifecycle must not mutate invitations');
$missing($lifecycle, 'UPDATE event_rsvps', 'Agent lifecycle must not mutate RSVPs');
$missing($lifecycle, 'UPDATE events', 'Agent lifecycle must not mutate Events');
$missing($lifecycle, 'coveted_push_dispatch', 'Agent lifecycle must not dispatch transport');
$missing($lifecycle, 'mail(', 'Agent lifecycle must not invent email transport');
$missing($lifecycle, 'CREATE TABLE', 'no runtime schema creation allowed');
$missing($lifecycle, 'ALTER TABLE', 'no runtime schema alteration allowed');

$contains($page, "require_once dirname(__DIR__) . '/app/event_communications_agent.php';", 'Admin Communications must load lifecycle integration');
$contains($page, 'coveted_event_communications_agent_track_queue(', 'explicit Admin queueing must activate Agent tracking');
$contains($page, 'coveted_event_communications_lifecycle_snapshot(', 'Admin workspace must display live lifecycle state');
$contains($page, 'coveted_event_communications_agent_sync_recommendation(', 'Admin workspace must sync actionable lifecycle recommendations');
$contains($page, 'AGENT COMMUNICATIONS LIFECYCLE', 'Admin lifecycle UI must be visible');
$contains($page, 'Agent recipient identities</dt><dd>Not exposed', 'Admin UI must make Agent privacy boundary visible');
$contains($page, 'The Agent lifecycle is now tracking canonical RSVP/forecast changes.', 'successful explicit queueing must explain Agent tracking');

$contains($operations, "require_once __DIR__ . '/event_communications_agent.php';", 'Operations must load communications lifecycle');
$contains($operations, 'coveted_event_communications_agent_context($actor, 12, $pdo)', 'Operations must read aggregate communications lifecycle');
$contains($operations, "'event_communications_attention'", 'Operations attention must include communications lifecycle');
$contains($operations, "'event_communications' => \$eventCommunications", 'Operations response must expose aggregate communications lifecycle');
$contains($operations, '$communicationRecommendations', 'communications recommendations must join the promoted Agent stream');
$contains($operations, '$resultRecommendations=array_merge(', 'communications must reuse an existing promoted Agent opportunity stream');
$contains($brain, "foreach (['event_planning','host_command','event_results'", 'Admin Agent brain must continue promoting the shared event-results recommendation stream while permitting additive intelligence streams');
$contains($tasks, 'function coveted_admin_agent_tasks_sync_opportunities', 'canonical Agent task queue must remain the task persistence service');
$contains($communications, 'coveted_notification_create(', 'actual communication execution must remain in the canonical Phase 2 service');

fwrite(STDOUT, "Event Communications Agent lifecycle contract verified.\n");