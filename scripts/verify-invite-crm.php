<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'app/invite_crm.php',
    'app/invite_profile.php',
    'request-invite.php',
    'activate.php',
    'admin/crm.php',
    'admin/cities.php',
    'app/admin_ui.php',
    'auth.php',
    'privacy.php',
    'terms.php',
    'assets/css/invite-crm-v2.css',
    'assets/css/invite-profile-v2.css',
    'assets/css/coveted.css',
    'assets/js/invite-crm-v2.js',
    'assets/js/invite-profile-v2.js',
    'assets/js/legal-footer.js',
    'assets/js/coveted.js',
    'database/migrations/20260905_invite_crm_cities.sql',
    'database/migrations/20260905_invite_profile_legal.sql',
    'database/README.md',
];

$files = [];
foreach ($paths as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing invite CRM file: {$relative}\n");
        exit(1);
    }
    $files[$relative] = (string)file_get_contents($path);
}

$data = $files['app/invite_crm.php'];
foreach ([
    'function coveted_invite_crm_ensure_schema',
    'information_schema.tables',
    'function coveted_invite_event_interest_options',
    'function coveted_invite_request_submit',
    'function coveted_invite_requests_list',
    'function coveted_invite_request_convert',
    'function coveted_city_create',
    'function coveted_city_set_status',
    'function coveted_activation_issue_token',
    'function coveted_activation_complete',
    'CREATE TABLE IF NOT EXISTS cities',
    'CREATE TABLE IF NOT EXISTS invite_requests',
    'CREATE TABLE IF NOT EXISTS user_activation_tokens',
    "'private_dinners' => 'Private dinners'",
    "'mystery_events' => 'Mystery events'",
    "status = 'converted'",
    "VALUES (?, ?, ?, ?, 'invited')",
    'source_ip_hash',
    'idx_invite_requests_ip_created',
    "hash('sha256', 'invite-request|' . \$ip)",
    'INTERVAL 24 HOUR',
    "INSERT INTO profiles (user_id,city,interests_json)",
    "['suspended', 'deleted']",
] as $fragment) {
    if (!str_contains($data, $fragment)) {
        fwrite(STDERR, "Invite CRM data contract missing: {$fragment}\n");
        exit(1);
    }
}

$profile = $files['app/invite_profile.php'];
foreach ([
    'function coveted_invite_goal_options',
    'function coveted_invite_source_options',
    'function coveted_invite_gender_options',
    'function coveted_invite_profile_validate_input',
    'function coveted_invite_profile_save',
    'function coveted_invite_profile_apply_to_user',
    'CREATE TABLE IF NOT EXISTS invite_request_profiles',
    'CREATE TABLE IF NOT EXISTS user_profile_intake',
    'social_links_json',
    'gender_key',
    'source_keys_json',
    "strtolower((string)(\$parts['scheme'] ?? '')) !== 'https'",
] as $fragment) {
    if (!str_contains($profile, $fragment)) {
        fwrite(STDERR, "Invite profile contract missing: {$fragment}\n");
        exit(1);
    }
}

$public = $files['request-invite.php'];
foreach ([
    'coveted_require_csrf();',
    'coveted_invite_profile_validate_input($_POST)',
    'coveted_invite_request_submit($baseInput, $pdo)',
    'coveted_invite_profile_save($requestPublicId, $profileInput, $pdo)',
    '$pdo->beginTransaction();',
    'name="city_id"',
    'name="city_other"',
    'data-city-select',
    'name="event_interests[]"',
    'name="goals[]"',
    'name="sources[]"',
    'name="personal_website"',
    'name="business_website"',
    'name="instagram"',
    'name="linkedin"',
    'name="gender"',
    'data-gender-self',
    'href="/terms.php"',
    'href="/privacy.php"',
    'Request an Invite',
    'name="company"',
] as $fragment) {
    if (!str_contains($public, $fragment)) {
        fwrite(STDERR, "Public invite request contract missing: {$fragment}\n");
        exit(1);
    }
}
if (str_contains($public, 'coveted_register(')) {
    fwrite(STDERR, "Public invite request must not bypass Admin CRM conversion.\n");
    exit(1);
}

$crm = $files['admin/crm.php'];
foreach ([
    'coveted_require_system_admin()',
    'coveted_invite_request_update',
    'coveted_invite_request_convert',
    'coveted_invite_profile_apply_to_user',
    'coveted_invite_profile_details_map',
    'Convert to User',
    'One-time activation link',
    'LOOKING FOR',
    'Gender',
    'Links',
    'name="city_id"',
    'name="interest"',
] as $fragment) {
    if (!str_contains($crm, $fragment)) {
        fwrite(STDERR, "Admin Invite CRM contract missing: {$fragment}\n");
        exit(1);
    }
}

$cities = $files['admin/cities.php'];
foreach ([
    'coveted_require_system_admin()',
    'coveted_city_create',
    'coveted_city_set_status',
    'City network',
    'Invite CRM',
    'Members',
    'Groups',
    'Locations',
    'data-dialog-open="create-city"',
] as $fragment) {
    if (!str_contains($cities, $fragment)) {
        fwrite(STDERR, "Admin Cities contract missing: {$fragment}\n");
        exit(1);
    }
}

$activation = $files['activate.php'];
foreach (['coveted_activation_lookup', 'coveted_activation_complete', 'coveted_establish_session', 'Activate Account'] as $fragment) {
    if (!str_contains($activation, $fragment)) {
        fwrite(STDERR, "Account activation contract missing: {$fragment}\n");
        exit(1);
    }
}

$auth = $files['auth.php'];
if (!str_contains($auth, "if (\$action === 'register')") || !str_contains($auth, "coveted_redirect('/request-invite.php')")) {
    fwrite(STDERR, "Public registration must route through the invite request CRM.\n");
    exit(1);
}
if (str_contains($auth, 'coveted_register(') || str_contains($auth, 'Create your account')) {
    fwrite(STDERR, "Open public self-signup must stay hidden while Coveted is invite-led.\n");
    exit(1);
}

$nav = $files['app/admin_ui.php'];
foreach (['/admin/crm.php', "'Invite CRM'", '/admin/cities.php', "'Cities'"] as $fragment) {
    if (!str_contains($nav, $fragment)) {
        fwrite(STDERR, "Admin navigation missing invite CRM/city section: {$fragment}\n");
        exit(1);
    }
}

$cssIndex = $files['assets/css/coveted.css'];
$jsIndex = $files['assets/js/coveted.js'];
foreach (['invite-crm-v2.css', 'invite-profile-v2.css', 'Request an Invite', 'a[href="/auth.php?action=register"]'] as $fragment) {
    if (!str_contains($cssIndex, $fragment)) {
        fwrite(STDERR, "Invite-led CSS entrypoint contract missing: {$fragment}\n");
        exit(1);
    }
}
foreach (['invite-crm-v2.js', 'invite-profile-v2.js', 'legal-footer.js'] as $fragment) {
    if (!str_contains($jsIndex, $fragment)) {
        fwrite(STDERR, "Canonical JS entrypoint missing: {$fragment}\n");
        exit(1);
    }
}

$legalJs = $files['assets/js/legal-footer.js'];
foreach (['/privacy.php', '/terms.php', '.cv-landing-footer nav', 'cv-legal-footer'] as $fragment) {
    if (!str_contains($legalJs, $fragment)) {
        fwrite(STDERR, "Legal footer contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach (['Privacy Policy', 'Gender information', 'social or website links'] as $fragment) {
    if (!str_contains($files['privacy.php'], $fragment)) {
        fwrite(STDERR, "Privacy policy missing: {$fragment}\n");
        exit(1);
    }
}
foreach (['Terms of Service', 'Invite-led membership', 'Community conduct'] as $fragment) {
    if (!str_contains($files['terms.php'], $fragment)) {
        fwrite(STDERR, "Terms page missing: {$fragment}\n");
        exit(1);
    }
}

$migration = $files['database/migrations/20260905_invite_crm_cities.sql'];
foreach (['CREATE TABLE IF NOT EXISTS cities', 'CREATE TABLE IF NOT EXISTS invite_requests', 'CREATE TABLE IF NOT EXISTS user_activation_tokens', 'idx_invite_requests_ip_created', 'city_phoenix_az'] as $fragment) {
    if (!str_contains($migration, $fragment)) {
        fwrite(STDERR, "Invite CRM migration contract missing: {$fragment}\n");
        exit(1);
    }
}

$profileMigration = $files['database/migrations/20260905_invite_profile_legal.sql'];
foreach (['CREATE TABLE IF NOT EXISTS invite_request_profiles', 'CREATE TABLE IF NOT EXISTS user_profile_intake', 'social_links_json', 'gender_key'] as $fragment) {
    if (!str_contains($profileMigration, $fragment)) {
        fwrite(STDERR, "Invite profile migration contract missing: {$fragment}\n");
        exit(1);
    }
}

$databaseReadme = $files['database/README.md'];
if (!str_contains($databaseReadme, 'database/migrations/') || !str_contains($databaseReadme, 'filename order')) {
    fwrite(STDERR, "Database deployment documentation must include additive migrations.\n");
    exit(1);
}

echo "Invite CRM + city + profile + legal contract OK\n";
