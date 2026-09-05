<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$integrityPath = $root . '/app/admin_integrity.php';
$uiPath = $root . '/app/admin_ui.php';
$cssIndexPath = $root . '/assets/css/coveted.css';
$cssPath = $root . '/assets/css/admin-v2.css';
$eventCssPath = $root . '/assets/css/admin-events-v2.css';
$peopleBusinessCssPath = $root . '/assets/css/admin-people-business-v2.css';
$communityValueCssPath = $root . '/assets/css/admin-community-value-v2.css';
$platformCssPath = $root . '/assets/css/admin-platform-v2.css';
$jsIndexPath = $root . '/assets/js/coveted.js';
$jsPath = $root . '/assets/js/admin-v2.js';
$platformJsPath = $root . '/assets/js/admin-platform-v2.js';
$operationsPath = $root . '/admin/operations.php';
$landingPath = $root . '/admin/landing.php';
$sampleDataPath = $root . '/admin/sample-data.php';

foreach ([
    $integrityPath,
    $uiPath,
    $cssIndexPath,
    $cssPath,
    $eventCssPath,
    $peopleBusinessCssPath,
    $communityValueCssPath,
    $platformCssPath,
    $jsIndexPath,
    $jsPath,
    $platformJsPath,
    $operationsPath,
    $landingPath,
    $sampleDataPath,
] as $path) {
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
$peopleBusinessCss = (string)file_get_contents($peopleBusinessCssPath);
$communityValueCss = (string)file_get_contents($communityValueCssPath);
$platformCss = (string)file_get_contents($platformCssPath);
$jsIndex = (string)file_get_contents($jsIndexPath);
$js = (string)file_get_contents($jsPath);
$platformJs = (string)file_get_contents($platformJsPath);
$operations = (string)file_get_contents($operationsPath);
$landing = (string)file_get_contents($landingPath);
$sampleData = (string)file_get_contents($sampleDataPath);

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

foreach ([
    'admin-v2.css',
    'admin-events-v2.css',
    'admin-people-business-v2.css',
    'admin-community-value-v2.css',
    'admin-platform-v2.css',
] as $stylesheet) {
    if (!str_contains($cssIndex, $stylesheet)) {
        fwrite(STDERR, "Admin v2 stylesheet missing from canonical CSS entrypoint: {$stylesheet}\n");
        exit(1);
    }
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

foreach (['.cv-admin-people-toolbar', '.cv-admin-business-toolbar', '.cv-admin-role-summary', '.cv-admin-business-health', '.cv-location-search'] as $fragment) {
    if (!str_contains($peopleBusinessCss, $fragment)) {
        fwrite(STDERR, "Admin v2 people/business style contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach (['.cv-admin-group-list', '.cv-admin-artist-grid', '.cv-admin-benefit-panel', '.cv-admin-distribution-workspace', '.cv-admin-community-toolbar', '.cv-admin-value-toolbar'] as $fragment) {
    if (!str_contains($communityValueCss, $fragment)) {
        fwrite(STDERR, "Admin v2 community/value style contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach ([
    '.cv-admin-platform-status',
    '.cv-admin-platform-links',
    '.cv-admin-operations-v2',
    '.cv-admin-landing-v2',
    '.cv-admin-sample-data-v2',
    '.cv-admin-settings-v2',
    '@media (max-width: 720px)',
] as $fragment) {
    if (!str_contains($platformCss, $fragment)) {
        fwrite(STDERR, "Admin v2 platform/mobile style contract missing: {$fragment}\n");
        exit(1);
    }
}

if (!str_contains($jsIndex, 'admin-v2.js') || !str_contains($jsIndex, 'admin-platform-v2.js')) {
    fwrite(STDERR, "Admin v2 interaction layers must be loaded by the canonical JS entrypoint.\n");
    exit(1);
}

foreach ([
    'cv-admin-event-toolbar',
    'dataset.status',
    'Search events, groups or status',
    'cv-admin-dropdown',
    'initUsers',
    'initRoleRequests',
    'initBusinesses',
    'initBusinessLocations',
    'initGroups',
    'initArtists',
    'initBenefits',
    'initDistribution',
    'Search people, email or role',
    'Search requests by person, email or role',
    'Search businesses or status',
    'Search groups, creators or status',
    'Search artists, owners or status',
    'Search campaigns, owners, rewards or status',
    'Search distribution history',
] as $fragment) {
    if (!str_contains($js, $fragment)) {
        fwrite(STDERR, "Admin v2 interaction contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach ([
    'initOperations',
    'initLanding',
    'initSampleData',
    'initSettings',
    'initMobileQA',
    'cv-admin-platform-status',
    'cv-admin-platform-links',
    "event.key !== 'Escape'",
    'scrollIntoView',
] as $fragment) {
    if (!str_contains($platformJs, $fragment)) {
        fwrite(STDERR, "Admin v2 platform interaction contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach ([$operations, $landing, $sampleData] as $platformPage) {
    if (!str_contains($platformPage, 'coveted_require_system_admin();')) {
        fwrite(STDERR, "Admin platform pages must remain System Admin only.\n");
        exit(1);
    }
}

if (!str_contains($operations, 'coveted_operations_snapshot($admin)')) {
    fwrite(STDERR, "Operations must use the canonical read-only operations snapshot.\n");
    exit(1);
}

foreach (['coveted_require_csrf();', 'coveted_site_setting_set_bool'] as $fragment) {
    if (!str_contains($landing, $fragment) || !str_contains($sampleData, $fragment)) {
        fwrite(STDERR, "Platform setting pages must preserve CSRF-protected canonical setting writes: {$fragment}\n");
        exit(1);
    }
}

echo "Admin v2 integrity contract OK\n";
