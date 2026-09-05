<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$integrityPath = $root . '/app/admin_integrity.php';
$uiPath = $root . '/app/admin_ui.php';
$cssIndexPath = $root . '/assets/css/coveted.css';
$cssPath = $root . '/assets/css/admin-v2.css';
$eventCssPath = $root . '/assets/css/admin-events-v2.css';
$jsIndexPath = $root . '/assets/js/coveted.js';
$jsPath = $root . '/assets/js/admin-v2.js';

foreach ([$integrityPath, $uiPath, $cssIndexPath, $cssPath, $eventCssPath, $jsIndexPath, $jsPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing Admin v2 integrity file: {$path}\n");
        exit(1);
    }
}

$integrity = (string)file_get_contents($integrityPath);
$ui = (string)file_get_contents($uiPath);
$cssIndex = (string)file_get_contents($cssIndexPath);
$css = (string)file_get_contents($cssPath);
$eventCss = (string)file_get_contents($eventCssPath);
$jsIndex = (string)file_get_contents($jsIndexPath);
$js = (string)file_get_contents($jsPath);

$requiredIntegrity = [
    'coveted_admin_integrity_guard_replay',
    'coveted_admin_integrity_lock_create',
    'GET_LOCK',
    'RELEASE_LOCK',
    'coveted_admin_integrity_assert_unique_user',
    'coveted_admin_integrity_assert_unique_business',
    'coveted_admin_integrity_assert_unique_group',
    'coveted_admin_integrity_assert_unique_event',
    'coveted_admin_integrity_assert_unique_artist',
    "'create_user'",
    "'create_business'",
    "'create_group'",
    "'create_event'",
    "'create_artist'",
    'coveted_require_csrf();',
];

foreach ($requiredIntegrity as $fragment) {
    if (!str_contains($integrity, $fragment)) {
        fwrite(STDERR, "Admin integrity contract missing: {$fragment}\n");
        exit(1);
    }
}

if (!str_contains($ui, 'coveted_admin_integrity_guard_request();')) {
    fwrite(STDERR, "Admin shell must run the create-data integrity gate.\n");
    exit(1);
}

if (str_contains($ui, '<span class="cv-admin-menu-label">QUICK CREATE</span>')) {
    fwrite(STDERR, "Admin account menu must not duplicate the canonical Create menu.\n");
    exit(1);
}

if (str_contains($ui, '<a href="/admin/onboarding.php"><strong>Admin Setup</strong>')) {
    fwrite(STDERR, "Admin account menu must not duplicate Setup from the sidebar.\n");
    exit(1);
}

foreach (['Profile', 'Member View', 'Sign out'] as $accountItem) {
    if (!str_contains($ui, $accountItem)) {
        fwrite(STDERR, "Admin account menu missing: {$accountItem}\n");
        exit(1);
    }
}

if (!str_contains($cssIndex, 'admin-v2.css') || !str_contains($cssIndex, 'admin-events-v2.css')) {
    fwrite(STDERR, "Admin v2 stylesheets must be loaded by the canonical CSS entrypoint.\n");
    exit(1);
}

foreach (['control-center-v5', '.cv-admin-quick-create', '.cv-admin-table', '.cv-admin-sidebar'] as $fragment) {
    if (!str_contains($css, $fragment)) {
        fwrite(STDERR, "Admin v2 visual contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach (['.cv-admin-event-toolbar', '.cv-admin-event-filters', '.cv-admin-event-search'] as $fragment) {
    if (!str_contains($eventCss, $fragment)) {
        fwrite(STDERR, "Admin v2 event style contract missing: {$fragment}\n");
        exit(1);
    }
}

if (!str_contains($jsIndex, 'admin-v2.js')) {
    fwrite(STDERR, "Admin v2 interaction layer must be loaded by the canonical JS entrypoint.\n");
    exit(1);
}

foreach (['cv-admin-event-toolbar', 'data-status', 'Search events, groups or status', 'cv-admin-dropdown'] as $fragment) {
    if (!str_contains($js, $fragment)) {
        fwrite(STDERR, "Admin v2 interaction contract missing: {$fragment}\n");
        exit(1);
    }
}

echo "Admin v2 integrity contract OK\n";
