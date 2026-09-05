<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$request = (string)file_get_contents($root . '/request-invite.php');
$profile = (string)file_get_contents($root . '/app/invite_profile.php');

foreach ([
    'name="goals[]"',
    'name="sources[]"',
    'name="gender"',
    'name="gender_self"',
    'name="personal_website"',
    'name="business_website"',
    'href="/terms.php"',
    'href="/privacy.php"',
] as $fragment) {
    if (!str_contains($request, $fragment)) {
        fwrite(STDERR, "Invite profile form edge contract missing: {$fragment}\n");
        exit(1);
    }
}

foreach ([
    "'prefer_not' => 'Prefer not to say'",
    "'self_describe' => 'Prefer to self-describe'",
    'Use a full https:// link',
    'CREATE TABLE IF NOT EXISTS user_profile_intake',
] as $fragment) {
    if (!str_contains($profile, $fragment)) {
        fwrite(STDERR, "Invite profile data edge contract missing: {$fragment}\n");
        exit(1);
    }
}

echo "Invite profile edge contract OK\n";
