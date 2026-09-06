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
        fwrite(STDERR, "Attendee event workspace contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Attendee event workspace contract failed: {$label}\n");
        exit(1);
    }
};

$service = $read('app/attendee_event_workspace.php');
$page = $read('my-events.php');
$eventPage = $read('event.php');
$eventsService = $read('app/events.php');
$nav = $read('assets/js/attendee-event-nav-v1.js');
$loader = $read('assets/js/coveted.js');
$css = $read('assets/css/attendee-event-workspace-v1.css');
$cssEntry = $read('assets/css/coveted.css');

// RSVP stays on the canonical event service and never gains event-authority powers.
$contains($service, 'return coveted_event_set_rsvp($actor, $eventRef, $decision', 'attendee RSVP must delegate to canonical event RSVP service');
$missing($service, 'INSERT INTO event_rsvps', 'attendee workspace must not write RSVP rows directly');
$missing($service, 'UPDATE event_rsvps', 'attendee workspace must not update RSVP rows directly');
$missing($page, 'INSERT INTO ', 'My Events page must not contain direct database inserts');
$missing($page, 'UPDATE events ', 'My Events page must never update event configuration');
$contains($eventsService, 'function coveted_event_require_system_admin(array $actor): void', 'System Admin event authority contract is missing');
$contains($eventsService, "function coveted_event_create(array \$actor, int \$groupId, array \$data): array\n{\n    coveted_event_require_system_admin(\$actor);", 'event creation must remain System Admin-gated');

// Event Pass is an identity aid only, never authentication or authorization.
$contains($service, 'function coveted_attendee_event_pass_id(', 'Event Pass ID helper is missing');
$contains($service, 'display/check-in identifiers only', 'Event Pass must explicitly remain non-authoritative');
$contains($service, "hash('sha256', 'coveted-event-pass|'", 'Event Pass ID must be deterministic without storing new credentials');
$contains($page, 'Show this screen to the host at check-in.', 'Event Pass check-in guidance is missing');
$contains($page, 'host-side attendance permissions remain authoritative.', 'Event Pass must not claim attendance authority');

// Mystery/privacy boundaries must be preserved across location and perk previews.
$contains($service, "if ($visibility === 'host_only')", 'host-only location must remain hidden from attendee workspace');
$contains($service, "(string)($event['event_type'] ?? '') !== 'mystery'", 'mystery event value preview guard is missing');
$contains($service, '!coveted_attendee_event_value_preview_visible($event)', 'event perk preview must obey mystery visibility guard');
$contains($service, "(string)($event['status'] ?? '') === 'completed' && coveted_attendee_event_has_verified_attendance($event)", 'verified completed attendees must retain scheduled-reveal history visibility');
$contains($page, 'Coveted will reveal the location only when the Admin-defined reveal becomes active.', 'member mystery reveal explanation is missing');

// POST surface is narrow, CSRF protected and sample data remains read-only.
$contains($page, "($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'", 'My Events POST detection is missing');
$contains($page, 'coveted_require_csrf();', 'My Events POST must require CSRF');
$contains($page, "if ($action !== 'rsvp')", 'My Events POST must be limited to RSVP');
$contains($page, 'Sample events are preview-only.', 'sample event RSVP mutation guard is missing');
$contains($page, 'coveted_attendee_event_set_rsvp(', 'My Events must use bounded attendee RSVP wrapper');

// Product surface must complete the attendee lifecycle.
foreach ([
    'MY EVENTS',
    'EVENT PASS',
    'PHONE-FREE EVENT DAY',
    'MYSTERY REVEALS',
    'EVENT VALUE',
    'EVENT HISTORY',
    'AFTER THE EVENT',
    '/invitations.php',
    '/notifications.php',
    '/benefits.php',
    '/reconnect.php',
    '/event.php?event=',
] as $fragment) {
    $contains($page, $fragment, 'My Events lifecycle surface missing: ' . $fragment);
}

// The existing event-detail lifecycle remains canonical for artist, quiet mode and post-event value.
$contains($eventPage, "if ($phase === 'arrived')", 'checked-in phone-free event phase is missing');
$contains($eventPage, 'Enjoy the evening.', 'checked-in event screen must remain intentionally quiet');
$contains($eventPage, 'ARTIST PARTNERS', 'artist event experience is missing');
$contains($eventPage, 'data-play-audio', 'post-event audio benefit experience is missing');
$contains($eventPage, 'MUTUAL RECONNECT', 'post-event Reconnect is missing');
$contains($eventPage, 'Benefits from this event', 'post-event benefit memory is missing');

// Primary member navigation promotes My Events without removing the legacy hosting calendar route.
$contains($nav, '.cv-nav a[href="/events.php"]', 'primary Events nav selector is missing');
$contains($nav, "memberEventsLink.href = '/my-events.php';", 'primary member navigation must route to My Events');
$contains($nav, "memberEventsLink.textContent = 'My Events';", 'primary member nav label must say My Events');
$contains($loader, 'attendee-event-nav-v1.js?v=attendee-event-nav-v1-20260906', 'attendee event navigation script is not loaded');
$missing($nav, 'querySelectorAll', 'navigation enhancement must not rewrite unrelated event links or host routes');

// Dedicated CSS is canonical and responsive.
$contains($cssEntry, 'attendee-event-workspace-v1.css?v=attendee-event-workspace-v1-20260906', 'attendee event stylesheet is not loaded');
foreach (['.cv-attendee-event-pass', '.cv-attendee-rsvp', '.cv-attendee-event-grid', '@media(max-width:980px)', '@media(max-width:640px)'] as $fragment) {
    $contains($css, $fragment, 'attendee event responsive styling missing: ' . $fragment);
}
$missing($page, '<script', 'My Events must remain CSP-safe without inline scripts');
$missing($page, '<style', 'My Events must remain CSP-safe without inline styles');
$missing($page, 'style="', 'My Events must not use inline style attributes');

fwrite(STDOUT, "Attendee Event Experience contract verified.\n");
