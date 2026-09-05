<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$samplePath = $root . '/app/member_sample_data.php';
$homePath = $root . '/app/member_home_v2.php';
$adminPath = $root . '/admin/sample-data.php';

foreach ([$samplePath, $homePath, $adminPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required member sample-data file: {$path}\n");
        exit(1);
    }
}

$sample = (string)file_get_contents($samplePath);
$home = (string)file_get_contents($homePath);
$admin = (string)file_get_contents($adminPath);

$requiredSampleFragments = [
    'function coveted_member_sample_mode',
    'coveted_is_system_admin($user)',
    'COVETED_SETTING_MEMBER_SAMPLE_DATA',
    'Saturday Night Supper Club',
    'Sunset Dinner',
    'Vinyl & Cocktails',
    'Phoenix, Arizona',
];

foreach ($requiredSampleFragments as $fragment) {
    if (!str_contains($sample, $fragment)) {
        fwrite(STDERR, "Member sample-data contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'REPLACE INTO'] as $mutation) {
    if (stripos($sample, $mutation) !== false) {
        fwrite(STDERR, "Synthetic member sample data must remain read-only/in-memory: {$mutation}\n");
        exit(1);
    }
}

if (!str_contains($home, 'coveted_member_sample_mode($user, $pdo)')) {
    fwrite(STDERR, "Home v2 must route sample preview through the guarded sample-mode helper.\n");
    exit(1);
}

if (!str_contains($admin, 'coveted_require_system_admin()')) {
    fwrite(STDERR, "Sample Data control must remain System Admin-only.\n");
    exit(1);
}

if (!str_contains($admin, 'coveted_site_setting_set_bool(COVETED_SETTING_MEMBER_SAMPLE_DATA')) {
    fwrite(STDERR, "Sample Data control must use the canonical site setting.\n");
    exit(1);
}

echo "Member sample-data contract OK\n";
