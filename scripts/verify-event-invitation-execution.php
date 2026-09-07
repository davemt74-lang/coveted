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
        fwrite(STDERR, "Invitation Wave Execution contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Invitation Wave Execution contract failed: {$label}\n");
        exit(1);
    }
};

$service = $read('app/event_invitation_execution.php');
$page = $read('admin/event-invitation-execution.php');
$wave = $read('app/event_invitation_waves.php');
$operations = $read('app/operations.php');
$tasks = $read('app/admin_agent_tasks.php');
$actions = $read('app/admin_agent_actions.php');
$nav = $read('assets/js/event-invitation-waves-nav-v1.js');
$client = $read('assets/js/event-invitation-execution-v1.js');

$contains($service, 'function coveted_event_invitation_execution_snapshot', 'live execution snapshot required');
$contains($service, 'function coveted_event_invitation_execution_send_selected', 'explicit selected-recipient execution service required');
$contains($service, 'coveted_event_invitation_wave_snapshot($admin, $eventRef, $pdo)', 'send must rebuild the live wave forecast');
$contains($service, "coveted_utc_datetime((string)(\$event['starts_at'] ?? ''))->getTimestamp()", 'event execution must use canonical UTC parsing');
$contains($service, "str_starts_with(\$decisionKey, 'open_wave_')", 'only open-wave recommendations may be executable');
$contains($service, '$candidates = $safeLimit > 0 ? array_slice($candidates, 0, $safeLimit) : [];', 'server must restrict selectable candidates to exact safe recommended top-N');
$contains($service, "count(\$selected) > (int)\$live['safe_limit']", 'selected batch must obey live safe limit');
$contains($service, 'if (!isset($currentCandidates[$userId]))', 'every selected recipient must remain in the current recommended wave');
$contains($service, "\$canonicalEventRef = (string)(\$live['event']['public_id'] ?? \$eventRef);", 'execution must normalize to canonical Event public ID');
$contains($service, 'coveted_event_invite_user(', 'every recipient must use canonical Event invitation service');
$contains($service, "'require_active_group_member' => true", 'canonical invitation must require active group membership');
$contains($service, "'reject_event_host' => true", 'canonical invitation must reject event hosts');
$contains($service, "'respect_existing_response' => true", 'canonical invitation must preserve existing RSVP state');
$contains($service, "'idempotent_pending' => true", 'canonical invitation must be idempotent for pending invites');
$contains($service, "'invitation-wave-' . \$eventRef", 'execution must bind to proactive Invitation Wave Agent task');
$contains($service, "coveted_admin_agent_task_set_status(\$admin, \$taskRef, 'approved', \$pdo, 'suggested')", 'explicit Admin execution must approve a Suggested Agent task with optimistic status guard');
$contains($service, "coveted_admin_agent_task_set_status(\$admin, \$taskRef, 'in_progress', \$pdo, 'approved')", 'executed wave must become an In Progress Agent task with optimistic status guard');
$contains($service, 'A concurrent', 'Agent tracking concurrency boundary must be documented');
$contains($service, "'agent_tracking_warning' => \$agentTrackingWarning", 'Agent tracking failure must be returned without undoing sent invitations');
$contains($service, "'recipient_identities_in_agent_payload' => false", 'Agent execution audit must document identity privacy');
$contains($service, 'System Admin must review and explicitly select exact recipients', 'human recipient approval boundary required');
$missing($service, 'INSERT INTO event_invitations', 'execution service must not directly insert invitations');
$missing($service, 'UPDATE event_invitations', 'execution service must not directly mutate invitations');
$missing($service, 'CREATE TABLE', 'execution service must not create schema');
$missing($service, 'ALTER TABLE', 'execution service must not alter schema');

$contains($page, 'coveted_require_system_admin();', 'workspace must be System Admin-only');
$contains($page, 'coveted_system_sample_mode($admin, $pdo)', 'workspace must isolate Full System Sample Mode');
$contains($page, 'coveted_require_csrf();', 'batch send must require CSRF');
$contains($page, 'name="action" value="send_selected"', 'send must be an explicit POST action');
$contains($page, 'name="recipient_ids[]"', 'Admin must explicitly select exact recipients');
$contains($page, 'data-wave-preview', 'forecast impact preview required');
$contains($page, 'Agent Tasks', 'workspace must link the proactive Agent task system');
$contains($page, 'Human approval boundary.', 'workspace must explain Agent/Admin authority boundary');
$contains($page, "!empty(\$execution['agent_tracking_warning'])", 'Admin must be warned if Agent tracking changes concurrently after invitations send');
$contains($page, 'Invitations were sent, but the Agent task changed concurrently', 'Agent tracking warning must truthfully preserve successful send result');
$contains($page, 'Manual Email Invite', 'canonical manual invitation fallback must remain available');

$contains($wave, 'function coveted_event_invitation_wave_agent_context', 'existing wave forecast must remain in Agent brain context');
$contains($wave, "'key'=>'invitation-wave-'", 'wave recommendation must have deterministic proactive Agent source key');
$contains($operations, 'coveted_event_invitation_wave_agent_context($actor, 12, $pdo)', 'Operations must keep feeding wave intelligence to the Agent brain');
$contains($operations, '$invitationWaveRecommendations', 'wave recommendations must remain in the promoted Agent opportunity stream');
$contains($tasks, 'coveted_admin_agent_tasks_sync_opportunities', 'proactive Agent opportunity synchronization must remain active');
$contains($tasks, "'source_type'=>(string)\$task['source_type']", 'Agent task context must preserve opportunity source type');
$missing($actions, 'coveted_event_invitation_execution_send_selected(', 'autonomous Agent action allowlist must not send recipient batches');

$contains($nav, '/admin/event-invitation-execution.php?event=', 'Event navigation must expose Wave Execution');
$contains($nav, "shell.dataset.systemSample === '1'", 'execution navigation must stay isolated in Sample Mode');
$contains($client, '[data-wave-recipient]', 'client must manage explicit recipient checkboxes');
$contains($client, 'maxSelection', 'client must enforce displayed safe selection cap');
$contains($client, 'window.confirm', 'client must require explicit send confirmation');

fwrite(STDOUT, "Invitation Wave Execution Queue + Agent management contract verified.\n");
