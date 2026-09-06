<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$systemPath = $root . '/app/system_sample_data.php';
$memberPath = $root . '/app/member_sample_data.php';
$homePath = $root . '/app/member_home_v2.php';
$pagesPath = $root . '/app/member_pages_v2.php';
$peoplePath = $root . '/app/member_people_v2.php';
$invitationsPath = $root . '/invitations.php';
$eventsPath = $root . '/events.php';
$groupsPath = $root . '/groups.php';
$benefitsPath = $root . '/benefits.php';
$walletPath = $root . '/wallet.php';
$reconnectPath = $root . '/reconnect.php';
$profilePath = $root . '/profile.php';
$adminPath = $root . '/admin/sample-data.php';
$apiPath = $root . '/api/admin-system-sample.php';
$settingsPath = $root . '/app/site_settings.php';

foreach ([$systemPath,$memberPath,$homePath,$pagesPath,$peoplePath,$invitationsPath,$eventsPath,$groupsPath,$benefitsPath,$walletPath,$reconnectPath,$profilePath,$adminPath,$apiPath,$settingsPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required sample-data file: {$path}\n");
        exit(1);
    }
}

$system = (string)file_get_contents($systemPath);
$member = (string)file_get_contents($memberPath);
$home = (string)file_get_contents($homePath);
$pages = (string)file_get_contents($pagesPath);
$people = (string)file_get_contents($peoplePath);
$invitations = (string)file_get_contents($invitationsPath);
$events = (string)file_get_contents($eventsPath);
$groups = (string)file_get_contents($groupsPath);
$benefits = (string)file_get_contents($benefitsPath);
$wallet = (string)file_get_contents($walletPath);
$reconnect = (string)file_get_contents($reconnectPath);
$profile = (string)file_get_contents($profilePath);
$admin = (string)file_get_contents($adminPath);
$api = (string)file_get_contents($apiPath);
$settings = (string)file_get_contents($settingsPath);

foreach ([
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
    'Dinner on us',
    'Member welcome',
    'Ember Hospitality',
    'Harbor House Group',
    'Velvet Note',
    'Desert Bloom Wellness',
    'Sienna Cole',
    'partner_relationships',
    'partner_contacts',
    'partner_followups',
    'benefit_programs',
    'sponsorships',
    'daily_events',
    'loyalty',
    'artist_media',
    'notifications',
    'agent_tasks',
] as $fragment) {
    if (!str_contains($system, $fragment)) {
        fwrite(STDERR, "Full system sample-data contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach (['INSERT INTO','UPDATE ','DELETE FROM','REPLACE INTO','CREATE TABLE','ALTER TABLE'] as $mutation) {
    if (stripos($system, $mutation) !== false) {
        fwrite(STDERR, "Full system sample data must remain read-only/in-memory: {$mutation}\n");
        exit(1);
    }
}

foreach ([
    'function coveted_member_sample_mode',
    'coveted_system_sample_mode($user, $pdo)',
    'COVETED_SETTING_MEMBER_SAMPLE_DATA',
    "return (array)coveted_system_sample_data()['member'];",
] as $fragment) {
    if (!str_contains($member, $fragment)) {
        fwrite(STDERR, "Member sample projection contract missing: {$fragment}\n");
        exit(1);
    }
}

if (!str_contains($home, 'coveted_member_sample_mode($user, $pdo)')) {
    fwrite(STDERR, "Home v2 must route sample preview through the guarded sample-mode helper.\n");
    exit(1);
}
foreach (['coveted_member_v2_invitations','coveted_member_v2_events','coveted_member_sample_mode($user, $pdo)'] as $fragment) {
    if (!str_contains($pages, $fragment)) {
        fwrite(STDERR, "Member page adapter contract missing: {$fragment}\n");
        exit(1);
    }
}
foreach (['coveted_member_v2_profile_data','coveted_member_v2_reconnect_events','coveted_member_v2_reconnect_attendees','coveted_member_v2_reconnect_matches'] as $fragment) {
    if (!str_contains($people, $fragment)) {
        fwrite(STDERR, "Member people adapter contract missing: {$fragment}\n");
        exit(1);
    }
}

if (!str_contains($invitations, 'Sample invitations are preview-only')) {
    fwrite(STDERR, "Invitations sample mode must block synthetic RSVP mutations.\n");
    exit(1);
}
if (!str_contains($events, 'coveted_member_v2_events($user, $pdo)')) {
    fwrite(STDERR, "Events page must use the guarded Member v2 event adapter.\n");
    exit(1);
}
if (!str_contains($groups, 'Sample groups are preview-only') || !str_contains($groups, 'coveted_member_sample_mode($user, $pdo)')) {
    fwrite(STDERR, "Groups sample mode must stay guarded and mutation-free.\n");
    exit(1);
}
if (!str_contains($benefits, "require __DIR__ . '/wallet.php';")
    || !str_contains($wallet, 'Sample benefits are preview-only')
    || !str_contains($wallet, 'coveted_member_sample_mode($user, $pdo)')) {
    fwrite(STDERR, "Benefits sample mode must stay guarded and mutation-free through the wallet route.\n");
    exit(1);
}
if (!str_contains($reconnect, 'Sample reconnect choices are preview-only') || !str_contains($reconnect, 'coveted_member_v2_reconnect_attendees')) {
    fwrite(STDERR, "Reconnect sample mode must stay guarded and mutation-free.\n");
    exit(1);
}
if (!str_contains($profile, 'The sample profile is preview-only') || !str_contains($profile, 'coveted_member_v2_profile_data')) {
    fwrite(STDERR, "Profile sample mode must stay guarded and use the Member v2 adapter.\n");
    exit(1);
}
if (!str_contains($profile, 'interests_json = VALUES(interests_json)')) {
    fwrite(STDERR, "Profile must persist interests through canonical profiles JSON outside sample mode.\n");
    exit(1);
}

foreach ([
    'coveted_require_system_admin()',
    'set_system_sample_data',
    'COVETED_SETTING_SYSTEM_SAMPLE_DATA',
    'coveted_system_sample_inventory',
    'Full Coveted demo network',
    'Read-only by design',
    'Autonomous Agent execution is disabled',
] as $fragment) {
    if (!str_contains($admin, $fragment)) {
        fwrite(STDERR, "Full-system Sample Data Admin contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach ([
    'coveted_require_system_admin()',
    '(\$_SERVER[\'REQUEST_METHOD\'] ?? \'GET\') !== \'GET\'',
    'coveted_system_sample_data()',
    'coveted_system_sample_inventory($sample)',
    "'read_only' => true",
    "'sample' => true",
    "'section' => \$section",
    "'partner_relationships'",
    "'benefit_programs'",
    "'artist_media'",
    "'agent'",
] as $fragment) {
    if (!str_contains($api, $fragment)) {
        fwrite(STDERR, "System sample read API contract missing: {$fragment}\n");
        exit(1);
    }
}
foreach (['INSERT INTO','UPDATE ','DELETE FROM','REPLACE INTO'] as $mutation) {
    if (stripos($api, $mutation) !== false) {
        fwrite(STDERR, "System sample read API must not mutate application data: {$mutation}\n");
        exit(1);
    }
}

if (!str_contains($settings, "const COVETED_SETTING_SYSTEM_SAMPLE_DATA = 'system_sample_data_enabled';")) {
    fwrite(STDERR, "Full-system sample setting constant is missing.\n");
    exit(1);
}
if (!str_contains($settings, '$key === COVETED_SETTING_ADMIN_AGENT_AUTONOMOUS_ACTIONS')
    || !str_contains($settings, 'COVETED_SETTING_SYSTEM_SAMPLE_DATA')) {
    fwrite(STDERR, "Agent autonomous actions must resolve false while full-system sample mode is active.\n");
    exit(1);
}

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
