<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$integrityPath = $root . '/app/admin_integrity.php';
$uiPath = $root . '/app/admin_ui.php';
$cssIndexPath = $root . '/assets/css/coveted.css';
$cssPath = $root . '/assets/css/admin-v2.css';

foreach ([$integrityPath, $uiPath, $cssIndexPath, $cssPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing Admin v2 integrity file: {$path}\n");
        exit(1);
    }
}

$integrity = (string)file_get_contents($integrityPath);
$ui = (string)file_get_contents($uiPath);
$cssIndex = (string)file_get_contents($cssIndexPath);
$css = (string)file_get_contents($cssPath);

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

foreach (['Profile', 'Admin Setup', 'Member View', 'Sign out'] as $accountItem) {
    if (!str_contains($ui, $accountItem)) {
        fwrite(STDERR, "Admin account menu missing: {$accountItem}\n");
        exit(1);
    }
}

if (!str_contains($cssIndex, 'admin-v2.css')) {
    fwrite(STDERR, "Admin v2 stylesheet must be loaded by the canonical CSS entrypoint.\n");
    exit(1);
}

foreach (['control-center-v5', '.cv-admin-quick-create', '.cv-admin-table', '.cv-admin-sidebar'] as $fragment) {
    if (!str_contains($css, $fragment)) {
        fwrite(STDERR, "Admin v2 visual contract missing: {$fragment}\n");
        exit(1);
    }
}

echo "Admin v2 integrity contract OK\n";
