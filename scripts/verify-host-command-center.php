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
        fwrite(STDERR, "Host Command Center contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Host Command Center contract failed: {$label}\n");
        exit(1);
    }
};

$service = $read('app/host_command.php');
$page = $read('host-command.php');
$operations = $read('app/operations.php');
$brain = $read('app/admin_agent_brain.php');
$cssIndex = $read('assets/css/coveted.css');
$css = $read('assets/css/host-command-v1.css');
$jsIndex = $read('assets/js/coveted.js');
$nav = $read('assets/js/host-command-nav-v1.js');

$contains($service, 'function coveted_host_command_require_event', 'canonical Host Command access resolver required');
$contains($service, "['lead','cohost','checkin']", 'Host Command must use canonical assigned host roles');
$contains($service, 'coveted_system_sample_mode($actor, $pdo)', 'Full System Sample Mode must block live Host Command access');
$contains($service, 'function coveted_host_command_can_operate_task', 'role-aware Production task authority required');
$contains($service, "if ((string)\$item['phase'] === 'planning') return false;", 'hosts must not operate planning-phase Production work');
$contains($service, "if (\$role === 'lead') return true;", 'lead host must coordinate non-planning operational work');
$contains($service, "if (\$role === 'cohost') return \$assignedUserId === 0 || \$assignedUserId === \$actorId;", 'cohosts must be limited to assigned or unassigned operational work');
$contains($service, "\$role === 'checkin'", 'check-in role must have a narrow task path');
$contains($service, "\$assignedUserId === \$actorId", 'check-in tasks must be explicitly assigned');
$contains($service, 'event_production_items', 'Host Command must reuse canonical Event Production items');
$contains($service, "['open','in_progress','blocked','completed']", 'host task changes must stay inside canonical Production statuses');
$contains($service, "['incident','closeout']", 'hosts may only add operational incident/closeout notes');
$contains($service, "['lead','cohost','system_admin']", 'closeout notes must require lead/cohost authority');
$contains($service, 'coveted_event_record_attendance($actor,$eventRef,$userId,$status)', 'attendance must use canonical Event attendance service');
$contains($service, 'function coveted_host_command_agent_context', 'Host Command must feed the Admin Agent brain');
$contains($service, "'recommendations'=>array_slice(\$recommendations,0,12)", 'Host Command must provide actionable Agent recommendations');
$contains($service, "'blocked_host_tasks'=>\$blocked", 'Agent context must include aggregate blocked Host work');
$contains($service, "'incidents_24h'=>\$incidents", 'Agent context must include aggregate incident signal');
$contains($service, "'authority'=>'Host Command is operational only.", 'Agent context must state the host authority boundary');
$missing($service, 'CREATE TABLE', 'Host Command must not create runtime schema');
$missing($service, 'ALTER TABLE', 'Host Command must not alter runtime schema');
$missing($service, 'coveted_event_update(', 'Host Command must not change canonical Event configuration');
$missing($service, 'coveted_event_set_location(', 'Host Command must not change event location');
$missing($service, 'coveted_event_set_artist(', 'Host Command must not change artist configuration');
$missing($service, 'coveted_event_set_status(', 'Host Command must not change event lifecycle status');
$missing($service, 'coveted_event_assign_host(', 'Host Command must not assign hosts');

$contains($page, 'coveted_require_csrf();', 'Host Command POST actions must require CSRF');
$contains($page, 'coveted_host_command_update_task', 'Host Command UI must use canonical host task service');
$contains($page, 'coveted_host_command_record_attendance', 'Host Command UI must use canonical attendance service');
$contains($page, 'coveted_host_command_add_note', 'Host Command UI must use canonical operational note service');
$contains($page, 'RUN OF SHOW', 'Host Command must expose run-of-show guidance');
$contains($page, 'GUEST COMMAND', 'Host Command must expose event-day attendance');
$contains($page, 'Log an incident', 'Host Command must expose operational escalation');
$contains($page, 'Host operating boundary:', 'Host UI must explain configuration authority');
$missing($page, 'name="starts_at"', 'Host Command UI must not edit event dates');
$missing($page, 'name="capacity"', 'Host Command UI must not edit capacity');
$missing($page, 'name="location_id"', 'Host Command UI must not edit venue configuration');

$contains($operations, "require_once __DIR__ . '/host_command.php';", 'Operations brain must load Host Command intelligence');
$contains($operations, 'coveted_host_command_agent_context($actor, $pdo)', 'Operations must request Host Command Agent context');
$contains($operations, "'host_command'", 'Operations summary must expose Host Command');
$contains($operations, "'recommendations' => \$hostRecommendations", 'Operations summary must carry Host Command recommendations');
$contains($operations, "'host_command_attention'", 'Host Command attention must contribute to Operations attention');

$contains($brain, "foreach (['event_planning','host_command'] as \$streamKey)", 'Agent opportunity queue must promote Playbook/Proposal and Host Command recommendations');
$contains($brain, "!str_starts_with(\$href, '/')", 'Agent recommendation routes must remain internal');
$contains($brain, 'reason over Event Playbooks and Proposals', 'Agent capability catalog must include Playbook/Proposal reasoning');
$contains($brain, 'review Host Command blockers and incidents', 'Agent capability catalog must include Host Command oversight');

$contains($cssIndex, 'host-command-v1.css', 'canonical CSS entrypoint must load Host Command styles');
$contains($css, '.cv-host-command-shell', 'Host Command visual shell required');
$contains($css, '.cv-host-command-guest-list', 'Host Command guest UI required');
$contains($jsIndex, 'host-command-nav-v1.js', 'canonical JS entrypoint must load Host Command navigation');
$contains($nav, "location.pathname !== '/host.php'", 'Host Command navigation must only augment Host Workspace');
$contains($nav, '/host-command.php?event=', 'Host Workspace must link to Host Command for the selected event');

fwrite(STDOUT, "Host Command Center + Agent integration contract verified.\n");
