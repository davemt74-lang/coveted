<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'app/invite_crm.php',
    'request-invite.php',
    'activate.php',
    'admin/crm.php',
    'admin/cities.php',
    'app/admin_ui.php',
    'auth.php',
    'assets/css/invite-crm-v2.css',
    'assets/css/coveted.css',
    'assets/js/invite-crm-v2.js',
    'assets/js/coveted.js',
    'database/migrations/20260905_invite_crm_cities.sql',
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
    'function coveted_invite_event_interest_options',
    'function coveted_invite_request_submit',
    'function coveted_invite_requests_list',
    'function coveted_invite_request_convert',
    'function coveted_city_create',
    'function coveted_city_set_status',
    'function coveted_activation_complete',
    'CREATE TABLE IF NOT EXISTS cities',
    'CREATE TABLE IF NOT EXISTS invite_requests',
    'CREATE TABLE IF NOT EXISTS user_activation_tokens',
    "'private_dinners' => 'Private dinners'",
    "'mystery_events' => 'Mystery events'",
    "'artist_sessions' => 'Live music & artist sessions'",
    "status = 'converted'",
    "VALUES (?, ?, ?, ?, 'invited')",
    "status = 'active'",
    'source_ip_hash',
    "hash('sha256', 'invite-request|' . \$ip)",
    "INSERT INTO profiles (user_id,city,interests_json)",
] as $fragment) {
    if (!str_contains($data, $fragment)) {
        fwrite(STDERR, "Invite CRM data contract missing: {$fragment}\n");
        exit(1);
    }
}

$public = $files['request-invite.php'];
foreach ([
    'coveted_require_csrf();',
    'coveted_invite_request_submit($_POST, $pdo)',
    'name="city_id"',
    'name="city_other"',
    'name="event_interests[]"',
    'Interested in events',
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
    'Convert to User',
    'One-time activation link',
    'city_name',
    'event_interests_json',
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

$nav = $files['app/admin_ui.php'];
foreach (['/admin/crm.php', "'Invite CRM'", '/admin/cities.php', "'Cities'"] as $fragment) {
    if (!str_contains($nav, $fragment)) {
        fwrite(STDERR, "Admin navigation missing invite CRM/city section: {$fragment}\n");
        exit(1);
    }
}

$cssIndex = $files['assets/css/coveted.css'];
$jsIndex = $files['assets/js/coveted.js'];
if (!str_contains($cssIndex, 'invite-crm-v2.css')) {
    fwrite(STDERR, "Invite CRM stylesheet is not loaded by the canonical CSS entrypoint.\n");
    exit(1);
}
if (!str_contains($jsIndex, 'invite-crm-v2.js')) {
    fwrite(STDERR, "Invite CRM interaction script is not loaded by the canonical JS entrypoint.\n");
    exit(1);
}

$js = $files['assets/js/invite-crm-v2.js'];
foreach (['data-dialog-open', "link.href = '/request-invite.php'", 'showModal'] as $fragment) {
    if (!str_contains($js, $fragment)) {
        fwrite(STDERR, "Invite CRM interaction contract missing: {$fragment}\n");
        exit(1);
    }
}

$migration = $files['database/migrations/20260905_invite_crm_cities.sql'];
foreach (['CREATE TABLE IF NOT EXISTS cities', 'CREATE TABLE IF NOT EXISTS invite_requests', 'CREATE TABLE IF NOT EXISTS user_activation_tokens', 'city_phoenix_az'] as $fragment) {
    if (!str_contains($migration, $fragment)) {
        fwrite(STDERR, "Invite CRM migration contract missing: {$fragment}\n");
        exit(1);
    }
}

echo "Invite CRM + city contract OK\n";
