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
        fwrite(STDERR, "Event Communications contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Event Communications contract failed: {$label}\n");
        exit(1);
    }
};

$service = $read('app/event_communications.php');
$page = $read('admin/event-communications.php');
$notifications = $read('app/notifications.php');
$eventsProjection = $read('app/notification_events.php');
$nav = $read('assets/js/event-invitation-waves-nav-v1.js');

$contains($service, 'function coveted_event_communications_require_admin', 'System Admin service authority is required');
$contains($service, 'coveted_is_system_admin($admin)', 'service must enforce System Admin authority');
$contains($service, 'coveted_system_sample_mode($admin, $pdo)', 'live queueing must be blocked in Full System Sample Mode');
$contains($service, "['published', 'closed']", 'communications must be limited to canonical pre-Event lifecycle states');
$contains($service, "coveted_utc_datetime((string)$event['starts_at'])->getTimestamp() <= time()", 'pre-Event execution must stop once the Event starts');

$contains($service, 'coveted_event_rsvp_followup_snapshot($admin, (string)$event[\'public_id\'], $pdo)', 'RSVP reminders must reuse Phase 1 follow-up intelligence');
$contains($service, "!== 'nudge_ready'", 'RSVP reminders must exclude hold and stop-contact recipients');
$contains($service, "er.response = 'attending'", 'confirmation/location/reveal recipients must come from current attending RSVPs');
$contains($service, "u.status = 'active'", 'communications must exclude inactive users');

$contains($service, "location_visibility'] === 'host_only'", 'host-only Event locations must be blocked from attendee communications');
$contains($service, "reveal_at <= UTC_TIMESTAMP()", 'only already-live canonical reveals may be selected');
$contains($service, "reveal_type'] !== 'location'", 'scheduled location sends must require a canonical location reveal');
$contains($service, "location_visibility'] !== 'immediate'", 'private location rows must not be read by the immediate-location helper unless visibility is immediate');

$contains($service, "'mystery-reveal:' . (int)$reveal['id'] . ':user:' . $userId", 'manual reveal queueing must share the canonical reconciler dedupe key');
$contains($eventsProjection, "'mystery-reveal:' . (int)$reveal['id'] . ':user:' . $recipientId", 'existing notification reconciler must retain the matching reveal dedupe key');
$contains($service, 'coveted_notification_create(', 'communications must use the canonical notification creation service');
$contains($notifications, 'function coveted_notification_create(', 'canonical notification service must remain available');
$contains($service, 'count($selectedUserIds) > 100', 'explicit execution must enforce a hard recipient cap');
$contains($service, 'coveted_event_communications_preview($admin, $eventRef, $communicationType, $revealId, $pdo)', 'recipient eligibility must be rebuilt immediately before execution');
$contains($service, "'event.communication_queued'", 'queue execution must be audited');

$missing($service, 'INSERT INTO notifications', 'Event communications must not bypass the canonical notification service');
$missing($service, 'INSERT INTO notification_deliveries', 'Event communications must not create transport rows directly');
$missing($service, 'coveted_push_dispatch', 'Event communications must not globally dispatch unrelated pending push rows');
$missing($service, 'UPDATE event_invitations', 'communications must not mutate invitation state');
$missing($service, 'UPDATE event_rsvps', 'communications must not mutate RSVP state');
$missing($service, 'UPDATE events', 'communications must not mutate Event configuration/lifecycle state');
$missing($service, 'UPDATE event_mystery_reveals', 'communications must not mutate reveal publication state');
$missing($service, 'CREATE TABLE', 'no runtime schema creation allowed');
$missing($service, 'ALTER TABLE', 'no runtime schema alteration allowed');
$missing($service, 'mail(', 'Phase 2 must not invent a direct email transport');

$contains($page, 'coveted_require_system_admin();', 'workspace must be System Admin-only');
$contains($page, 'coveted_require_csrf();', 'queue execution must require CSRF protection');
$contains($page, "value=\"queue_selected\"", 'workspace must require explicit queue action');
$contains($page, 'name="recipient_ids[]"', 'workspace must expose exact recipient selection');
$missing($page, 'name="recipient_ids[]" value="<?= (int)$recipient[\'user_id\'] ?>" checked', 'recipient checkboxes must not be preselected');
$contains($page, 'unchecked by default', 'human approval boundary must be visible');
$contains($page, 'Transport delivery remains in the canonical notification delivery queue.', 'workspace must distinguish queueing from transport dispatch');
$contains($page, 'Host-only locations are blocked.', 'location privacy boundary must be visible');

$contains($nav, '/admin/event-communications.php?event=', 'Event navigation must expose Communications');
$contains($nav, 'data-event-communications-tab', 'Communications must be a first-class Event tab');
$contains($nav, "shell.dataset.systemSample === '1'", 'Communications navigation must remain isolated in Sample Mode');

fwrite(STDOUT, "Event Communications Execution contract verified.\n");
