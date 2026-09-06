<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'system' => 'app/system_sample_data.php',
    'member' => 'app/member_sample_data.php',
    'home' => 'app/member_home_v2.php',
    'pages' => 'app/member_pages_v2.php',
    'people' => 'app/member_people_v2.php',
    'invitations' => 'invitations.php',
    'events' => 'events.php',
    'groups' => 'groups.php',
    'benefits' => 'benefits.php',
    'wallet' => 'wallet.php',
    'reconnect' => 'reconnect.php',
    'profile' => 'profile.php',
    'admin' => 'admin/sample-data.php',
    'api' => 'api/admin-system-sample.php',
    'settings' => 'app/site_settings.php',
];

$files = [];
foreach ($required as $key => $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required sample-data file: {$relative}\n");
        exit(1);
    }
    $files[$key] = (string)file_get_contents($path);
}

$assertContains = static function (string $content, array $needles, string $label): void {
    foreach ($needles as $needle) {
        if (!str_contains($content, $needle)) {
            fwrite(STDERR, "{$label} missing: {$needle}\n");
            exit(1);
        }
    }
};
$assertNoMutationSql = static function (string $content, string $label): void {
    foreach (['INSERT INTO','UPDATE ','DELETE FROM','REPLACE INTO','CREATE TABLE','ALTER TABLE'] as $needle) {
        if (stripos($content, $needle) !== false) {
            fwrite(STDERR, "{$label} must remain read-only: {$needle}\n");
            exit(1);
        }
    }
};

$assertContains($files['system'], [
    'function coveted_system_sample_mode',
    'function coveted_system_sample_data',
    'function coveted_system_sample_inventory',
    'function coveted_system_sample_admin_counts',
    'function coveted_system_sample_agent_snapshot',
    'Coveted Full System Demo',
    'Saturday Night Supper Club',
    'Sunset Dinner',
    'Vinyl & Cocktails',
    'The Inner Circle',
    'City Table Club',
    'Late Night Listening',
    'Ember Hospitality',
    'Harbor House Group',
    'Velvet Note',
    'Desert Bloom Wellness',
    'Sienna Cole',
    'partner_relationships',
    'partner_contacts',
    'partner_notes',
    'partner_interactions',
    'partner_followups',
    'partner_perks',
    'daily_events',
    'benefit_programs',
    'sponsorships',
    'loyalty',
    'claims',
    'distribution',
    'artist_media',
    'artist_appearances',
    'notifications',
    'agent_tasks',
], 'Full system sample-data contract');
$assertNoMutationSql($files['system'], 'Full system sample pack');

$assertContains($files['member'], [
    'function coveted_member_sample_mode',
    'coveted_system_sample_mode($user, $pdo)',
    'COVETED_SETTING_MEMBER_SAMPLE_DATA',
    "coveted_system_sample_data()['member']",
], 'Member sample projection contract');

$assertContains($files['home'], ['coveted_member_sample_mode($user, $pdo)'], 'Home sample adapter');
$assertContains($files['pages'], ['coveted_member_v2_invitations','coveted_member_v2_events','coveted_member_sample_mode($user, $pdo)'], 'Member page adapters');
$assertContains($files['people'], ['coveted_member_v2_profile_data','coveted_member_v2_reconnect_events','coveted_member_v2_reconnect_attendees','coveted_member_v2_reconnect_matches'], 'Member people adapters');
$assertContains($files['invitations'], ['Sample invitations are preview-only'], 'Invitation sample guard');
$assertContains($files['events'], ['coveted_member_v2_events($user, $pdo)'], 'Event sample adapter');
$assertContains($files['groups'], ['Sample groups are preview-only','coveted_member_sample_mode($user, $pdo)'], 'Group sample guard');
$assertContains($files['benefits'], ["require __DIR__ . '/wallet.php';"], 'Benefits sample route');
$assertContains($files['wallet'], ['Sample benefits are preview-only','coveted_member_sample_mode($user, $pdo)'], 'Wallet sample guard');
$assertContains($files['reconnect'], ['Sample reconnect choices are preview-only','coveted_member_v2_reconnect_attendees'], 'Reconnect sample guard');
$assertContains($files['profile'], ['The sample profile is preview-only','coveted_member_v2_profile_data','interests_json = VALUES(interests_json)'], 'Profile sample guard');

$assertContains($files['admin'], [
    'coveted_require_system_admin()',
    'set_system_sample_data',
    'COVETED_SETTING_SYSTEM_SAMPLE_DATA',
    'coveted_system_sample_inventory',
    'Full Coveted demo network',
    'Read-only by design',
    'Autonomous Agent execution is disabled',
], 'Full-system Sample Data Admin contract');

$assertContains($files['api'], [
    'coveted_require_system_admin()',
    'GET required.',
    'coveted_system_sample_data()',
    'coveted_system_sample_inventory($sample)',
    "'read_only' => true",
    "'sample' => true",
    "'partner_relationships'",
    "'benefit_programs'",
    "'artist_media'",
    "'agent'",
], 'System sample read API contract');
$assertNoMutationSql($files['api'], 'System sample read API');

$assertContains($files['settings'], [
    "const COVETED_SETTING_SYSTEM_SAMPLE_DATA = 'system_sample_data_enabled';",
    '$key === COVETED_SETTING_ADMIN_AGENT_AUTONOMOUS_ACTIONS',
    'COVETED_SETTING_SYSTEM_SAMPLE_DATA',
], 'System sample setting / Agent safety contract');

$previewAssets = [
    'assets/images/sample/events/saturday-night-supper-club-hero.webp',
    'assets/images/sample/events/sunset-dinner-hero.webp',
    'assets/images/sample/events/vinyl-and-cocktails-hero.webp',
    'assets/images/sample/groups/the-inner-circle.webp',
    'assets/images/sample/groups/city-table-club.webp',
    'assets/images/sample/groups/late-night-listening.webp',
    'assets/images/sample/benefits/dinner-on-us.webp',
    'assets/images/sample/benefits/member-gift.webp',
    'assets/images/sample/people/taylor-kim.webp',
    'assets/images/sample/people/jordan-ellis.webp',
    'assets/images/sample/people/maya-rivera.webp',
    'assets/images/sample/people/leo-martinez.webp',
    'assets/images/sample/people/sienna-cole.webp',
    'assets/images/sample/people/noah-bennett.webp',
];
foreach ($previewAssets as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path) || filesize($path) < 100) {
        fwrite(STDERR, "Missing sample preview image: {$relative}\n");
        exit(1);
    }
    $header = (string)file_get_contents($path, false, null, 0, 12);
    if (strlen($header) < 12 || substr($header,0,4) !== 'RIFF' || substr($header,8,4) !== 'WEBP') {
        fwrite(STDERR, "Invalid WebP sample preview image: {$relative}\n");
        exit(1);
    }
}

echo "Full system + member sample-data contract OK\n";
