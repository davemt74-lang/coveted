<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$samplePath = $root . '/app/member_sample_data.php';
$homePath = $root . '/app/member_home_v2.php';
$pagesPath = $root . '/app/member_pages_v2.php';
$peoplePath = $root . '/app/member_people_v2.php';
$invitationsPath = $root . '/invitations.php';
$eventsPath = $root . '/events.php';
$groupsPath = $root . '/groups.php';
$benefitsPath = $root . '/benefits.php';
$reconnectPath = $root . '/reconnect.php';
$profilePath = $root . '/profile.php';
$adminPath = $root . '/admin/sample-data.php';

foreach ([$samplePath, $homePath, $pagesPath, $peoplePath, $invitationsPath, $eventsPath, $groupsPath, $benefitsPath, $reconnectPath, $profilePath, $adminPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required member sample-data file: {$path}\n");
        exit(1);
    }
}

$sample = (string)file_get_contents($samplePath);
$home = (string)file_get_contents($homePath);
$pages = (string)file_get_contents($pagesPath);
$people = (string)file_get_contents($peoplePath);
$invitations = (string)file_get_contents($invitationsPath);
$events = (string)file_get_contents($eventsPath);
$groups = (string)file_get_contents($groupsPath);
$benefits = (string)file_get_contents($benefitsPath);
$reconnect = (string)file_get_contents($reconnectPath);
$profile = (string)file_get_contents($profilePath);
$admin = (string)file_get_contents($adminPath);

$requiredSampleFragments = [
    'function coveted_member_sample_mode',
    'coveted_is_system_admin($user)',
    'COVETED_SETTING_MEMBER_SAMPLE_DATA',
    'Saturday Night Supper Club',
    'Sunset Dinner',
    'Vinyl & Cocktails',
    'The Inner Circle',
    'City Table Club',
    'Late Night Listening',
    'Dinner on us',
    'Member welcome',
    'First Friday Supper',
    'Listening Room Night',
    "'profile' => \$profile",
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

foreach (['coveted_member_v2_invitations', 'coveted_member_v2_events', 'coveted_member_sample_mode($user, $pdo)'] as $fragment) {
    if (!str_contains($pages, $fragment)) {
        fwrite(STDERR, "Member page adapter contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach (['coveted_member_v2_profile_data', 'coveted_member_v2_reconnect_events', 'coveted_member_v2_reconnect_attendees', 'coveted_member_v2_reconnect_matches'] as $fragment) {
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
if (!str_contains($benefits, 'Sample benefits are preview-only') || !str_contains($benefits, 'coveted_member_sample_mode($user, $pdo)')) {
    fwrite(STDERR, "Benefits sample mode must stay guarded and mutation-free.\n");
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
    fwrite(STDERR, "Profile must persist interests and gathering-style context through the canonical profiles JSON column.\n");
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
        fwrite(STDERR, "Missing member preview image: {$relative}\n");
        exit(1);
    }

    $header = (string)file_get_contents($path, false, null, 0, 12);
    if (strlen($header) < 12 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') {
        fwrite(STDERR, "Invalid WebP member preview image: {$relative}\n");
        exit(1);
    }
}

echo "Member sample-data contract OK\n";
