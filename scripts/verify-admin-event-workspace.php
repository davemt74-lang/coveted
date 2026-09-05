<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'admin/event.php',
    'assets/css/admin-event-workspace-v1.css',
    'assets/css/coveted.css',
    'assets/js/admin-event-workspace-v1.js',
    'assets/js/coveted.js',
];

$files = [];
foreach ($paths as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing Admin Event Workspace file: {$relative}\n");
        exit(1);
    }
    $files[$relative] = (string)file_get_contents($path);
}

$workspace = $files['admin/event.php'];
foreach ([
    'coveted_require_system_admin()',
    "'overview' => 'Overview'",
    "'guests' => 'Guests'",
    "'hosts' => 'Hosts'",
    "'location' => 'Location'",
    "'artist' => 'Artist'",
    "'benefits' => 'Benefits'",
    "'mystery' => 'Mystery'",
    "'attendance' => 'Attendance'",
    "'activity' => 'Activity'",
    'coveted_event_update($admin',
    'coveted_event_set_status($admin',
    'coveted_event_invite_user($admin',
    'coveted_event_assign_host($admin',
    'coveted_event_set_location(',
    'coveted_event_set_artist(',
    'coveted_campaign_link_event($admin',
    'coveted_event_add_mystery_reveal(',
    'coveted_event_record_attendance(',
    'Admin configures. Hosts operate.',
    'Host / Check-in View',
    'data-admin-event-workspace',
    "'event.host_removed'",
    "'campaign.event_unlinked'",
    "'event.reveal_removed'",
] as $fragment) {
    if (!str_contains($workspace, $fragment)) {
        fwrite(STDERR, "Admin Event Workspace contract missing: {$fragment}\n");
        exit(1);
    }
}

if (str_contains($workspace, 'coveted_event_create(')) {
    fwrite(STDERR, "Admin Event Workspace must not become a second event-creation surface.\n");
    exit(1);
}

foreach ([
    "case 'update_event':",
    "case 'set_status':",
    "case 'assign_host':",
    "case 'set_location':",
    "case 'set_artist':",
    "case 'link_campaign':",
    "case 'add_reveal':",
] as $adminMutation) {
    if (!str_contains($workspace, $adminMutation)) {
        fwrite(STDERR, "Missing System Admin event configuration action: {$adminMutation}\n");
        exit(1);
    }
}

$router = $files['assets/js/admin-event-workspace-v1.js'];
foreach ([
    '.cv-admin-app[data-admin-shell="control-center-v5"]',
    'a[href^="/host.php?event="]',
    "link.closest('[data-admin-event-workspace]')",
    "new URL('/admin/event.php'",
    "link.dataset.adminEventWorkspaceLink = '1'",
] as $fragment) {
    if (!str_contains($router, $fragment)) {
        fwrite(STDERR, "Admin event canonical-route contract missing: {$fragment}\n");
        exit(1);
    }
}

$css = $files['assets/css/admin-event-workspace-v1.css'];
foreach ([
    '.cv-admin-event-workspace',
    '.cv-admin-event-metrics',
    '.cv-admin-event-tabs',
    '.cv-admin-event-grid',
    '@media (max-width: 620px)',
] as $fragment) {
    if (!str_contains($css, $fragment)) {
        fwrite(STDERR, "Admin Event Workspace CSS contract missing: {$fragment}\n");
        exit(1);
    }
}

if (!str_contains($files['assets/css/coveted.css'], 'admin-event-workspace-v1.css')) {
    fwrite(STDERR, "Canonical CSS entrypoint does not load Admin Event Workspace styles.\n");
    exit(1);
}
if (!str_contains($files['assets/js/coveted.js'], 'admin-event-workspace-v1.js')) {
    fwrite(STDERR, "Canonical JS entrypoint does not load Admin Event Workspace routing.\n");
    exit(1);
}

echo "Admin Event Workspace contract OK\n";
