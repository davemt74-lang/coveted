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
        fwrite(STDERR, "Business Host workspace contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Business Host workspace contract failed: {$label}\n");
        exit(1);
    }
};

$service = $read('app/business_host_workspace.php');
$page = $read('business-host.php');
$events = $read('app/events.php');
$css = $read('assets/css/business-host-workspace-v1.css');
$cssEntry = $read('assets/css/coveted.css');

// Venue access must reuse canonical Business Admin, event, and notification services.
$contains($service, "require_once __DIR__ . '/businesses.php';", 'workspace must reuse canonical business services');
$contains($service, "require_once __DIR__ . '/events.php';", 'workspace must reuse canonical event services');
$contains($service, "require_once __DIR__ . '/notifications.php';", 'issue escalation must reuse canonical notifications');
$contains($service, 'FROM business_admins WHERE user_id = ?', 'Business Admin assignment must gate venue access');
$contains($service, 'coveted_business_actor_can_view($actor, $businessId)', 'venue data must stay scoped through canonical business permission');
$contains($service, 'JOIN event_locations el ON el.event_id = e.id', 'events must be linked through canonical event locations');
$contains($service, 'JOIN locations l ON l.id = el.location_id', 'events must resolve through canonical locations');
$contains($service, 'WHERE l.business_id = ?', 'event queries must be business scoped');
$missing($service, 'CREATE TABLE', 'workspace service must not create schema at runtime');
$missing($service, 'ALTER TABLE', 'workspace service must not alter schema at runtime');

// Business access alone never grants attendance mutation authority.
$contains($service, "if ($role === 'checkin')", 'explicit check-in assignment must unlock check-in');
$contains($service, "in_array($role, ['lead','cohost'], true)", 'lead/cohost handling must remain explicit');
$contains($service, 'coveted_event_actor_has_host_approval($actor)', 'lead/cohost check-in must still require Attendee Host approval');
$contains($service, 'coveted_event_record_attendance($actor, (string)$event[\'public_id\'], $userId, $status);', 'attendance writes must delegate to the canonical event attendance service');
$missing($service, 'INSERT INTO event_attendance', 'workspace service must not write attendance directly');
$missing($service, 'UPDATE event_attendance', 'workspace service must not update attendance directly');

// Issue reporting must be durable without inventing a parallel issue/message table.
$contains($service, 'function coveted_business_host_report_issue(', 'venue issue reporting service is missing');
$contains($service, "ur.role_key = 'system_admin'", 'issue reports must target active System Admins');
$contains($service, 'coveted_notification_create(', 'issue reports must use canonical notifications');
$contains($service, "'business_host.issue'", 'venue issue notification type is missing');
$contains($service, "$category === 'safety' ? 'high' : 'normal'", 'safety issues must be high priority');
$contains($service, "'business_host.issue_reported'", 'issue reporting audit event is missing');
$missing($service, 'CREATE TABLE business_host', 'issue reporting must not create a parallel issue table');
$missing($service, 'INSERT INTO notifications', 'workspace must not bypass the canonical notification service');

// Event creation/configuration authority must remain System Admin only.
$contains($events, 'function coveted_event_create(array $actor, int $groupId, array $data): array', 'canonical event create service is missing');
$contains($events, 'coveted_event_require_system_admin($actor);', 'canonical event creation/configuration must retain System Admin gate');
$contains($page, 'Coveted System Admin owns event creation and configuration.', 'workspace must explain the authority boundary');
$contains($page, 'Admin-controlled event setup', 'workspace must identify Admin-controlled setup');
$missing($page, 'coveted_event_create(', 'Business Host page must not create events');
$missing($page, 'coveted_event_update(', 'Business Host page must not edit event configuration');
$missing($page, 'coveted_event_set_status(', 'Business Host page must not change event status');
$missing($page, 'coveted_event_set_location(', 'Business Host page must not change event location');
$missing($page, 'coveted_event_set_artist(', 'Business Host page must not change event lineup');

// POST surface is narrow, CSRF protected, scoped, and limited to venue operations.
$contains($page, "($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'", 'workspace POST detection is missing');
$contains($page, 'if (!$isPost)', 'invalid GET business refs may recover but POST mutations must not fall back to another business');
$contains($page, 'coveted_require_csrf();', 'workspace POST must require CSRF');
$contains($page, "['record_attendance','report_issue']", 'workspace POST action allowlist is missing');
$contains($page, 'coveted_business_host_record_attendance(', 'workspace must call the bounded attendance wrapper');
$contains($page, 'coveted_business_host_report_issue(', 'workspace must call the bounded issue-report wrapper');
$contains($page, "(string)$guest['response'] === 'attending'", 'attendance controls must be limited to attending guests');
$missing($page, 'INSERT INTO ', 'workspace page must not contain direct insert SQL');
$missing($page, 'UPDATE events ', 'workspace page must not mutate events directly');

// Product surface must include the complete venue operating flow.
$contains($page, 'HOST HOME', 'Host Home is missing');
$contains($page, 'EVENT DAY MODE', 'Event Day Mode is missing');
$contains($page, 'VENUE PROFILE', 'Venue Profile is missing');
$contains($page, 'REWARDS &amp; PERKS', 'Rewards & Perks is missing');
$contains($page, 'ARTIST / ENTERTAINMENT', 'Artist / Entertainment is missing');
$contains($page, 'POST-EVENT REPORT', 'Post-Event Report is missing');
$contains($page, 'GUEST ISSUES &amp; ADMIN COORDINATION', 'Guest issues/Admin coordination is missing');
$contains($page, 'Business Admin access by itself does not grant check-in authority.', 'view-only/check-in boundary must be visible');
$contains($page, 'Send to Coveted Admin', 'issue reporting control is missing');
$contains($page, '/notifications.php', 'Admin coordination must reuse existing Coveted notifications');
$contains($page, '/business.php?business=', 'venue profile must reuse the existing business workspace');
$missing($page, '<script', 'workspace must remain CSP-safe without inline script');
$missing($page, '<style', 'workspace must remain CSP-safe without inline style');
$missing($page, 'style="', 'workspace must not use inline style attributes');

// Dedicated stylesheet must be loaded canonically and stay responsive.
$contains($cssEntry, 'business-host-workspace-v1.css?v=business-host-workspace-v1-20260906', 'business host stylesheet is not loaded');
$contains($css, '.cv-business-host-layout', 'workspace layout styling is missing');
$contains($css, '.cv-business-host-guest', 'event-day guest styling is missing');
$contains($css, '.cv-business-host-issue-form', 'issue reporting styling is missing');
$contains($css, '@media(max-width:980px)', 'tablet/mobile breakpoint is missing');
$contains($css, '@media(max-width:640px)', 'small-phone breakpoint is missing');

fwrite(STDOUT, "Business / Location Host workspace contract verified.\n");
