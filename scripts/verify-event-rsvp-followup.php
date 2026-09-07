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
        fwrite(STDERR, "RSVP Follow-Up contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "RSVP Follow-Up contract failed: {$label}\n");
        exit(1);
    }
};

$service = $read('app/event_rsvp_followup.php');
$page = $read('admin/event-rsvp-followup.php');
$tasks = $read('app/admin_agent_tasks.php');
$nav = $read('assets/js/event-invitation-waves-nav-v1.js');

$contains($service, 'function coveted_event_rsvp_followup_snapshot', 'derived follow-up snapshot required');
$contains($service, 'coveted_event_invitation_wave_snapshot($admin, $eventRef, $pdo)', 'follow-up must reuse canonical Invitation Wave / forecast intelligence');
$contains($service, "ei.status = 'pending'", 'only canonical pending invitations may enter follow-up review');
$contains($service, 'er.response IS NULL', 'members with an RSVP must not remain in follow-up candidates');
$contains($service, "'hold'", 'hold classification required');
$contains($service, "'nudge_ready'", 'nudge-ready classification required');
$contains($service, "'stop_contact'", 'stop-contact classification required');
$contains($service, '$closeWindowHours = max(72, $responseWindowHours * 2);', 'stop-contact must require at least two learned windows / 72 hours');
$contains($service, '$hoursToEvent <= 12.0', 'follow-up must stop when the Event is too close');
$contains($service, "'waitlist_ready' => $waitlistReady", 'waitlist-first readiness required');
$contains($service, "'key' => 'rsvp-followup-'", 'stable proactive Agent source key required');
$contains($service, "'aggregate_context'", 'privacy-safe aggregate Agent context payload required');
$contains($service, 'Exact pending-member identities remain in this System Admin workspace', 'identity privacy boundary required');
$contains($service, 'coveted_admin_agent_tasks_sync_opportunities', 'recommendation must reuse the canonical Admin Agent task queue');
$missing($service, 'INSERT INTO event_invitations', 'follow-up intelligence must not create invitations');
$missing($service, 'UPDATE event_invitations', 'follow-up intelligence must not mutate invitations');
$missing($service, 'INSERT INTO event_rsvps', 'follow-up intelligence must not create RSVPs');
$missing($service, 'UPDATE event_rsvps', 'follow-up intelligence must not mutate RSVPs');
$missing($service, 'notification_send', 'Phase 1 must not send notifications');
$missing($service, 'mail(', 'Phase 1 must not send email');
$missing($service, 'CREATE TABLE', 'no runtime schema creation allowed');
$missing($service, 'ALTER TABLE', 'no runtime schema alteration allowed');

$contains($page, 'coveted_require_system_admin();', 'workspace must be System Admin-only');
$contains($page, 'coveted_system_sample_mode($admin, $pdo)', 'workspace must isolate Full System Sample Mode');
$contains($page, 'coveted_event_rsvp_followup_sync_agent_task', 'workspace must synchronize deterministic task metadata');
$contains($page, 'Exact identities stay in System Admin', 'recipient identity boundary must be visible');
$contains($page, 'No autonomous messaging.', 'human messaging authority boundary must be visible');
$contains($page, 'Invitation Waves', 'workspace must link canonical forecasting');
$contains($page, 'Wave Execution', 'workspace must link canonical wave execution');
$contains($tasks, 'function coveted_admin_agent_tasks_sync_opportunities', 'canonical Agent task sync service must remain available');
$contains($nav, '/admin/event-rsvp-followup.php?event=', 'Event navigation must expose RSVP Follow-Up');
$contains($nav, 'data-event-rsvp-followup-tab', 'RSVP Follow-Up must be a first-class Event tab');
$contains($nav, "shell.dataset.systemSample === '1'", 'RSVP Follow-Up navigation must stay isolated in Sample Mode');

fwrite(STDOUT, "RSVP Follow-Up Intelligence contract verified.\n");
