<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'Coveted',
        'base_url' => 'https://coveted.example',
        'environment' => 'production',
        'session_name' => 'coveted_session',
        'default_timezone' => 'America/Phoenix',

        // Internal server secret used to build an indexed, non-reversible lookup
        // digest for business claim codes. Businesses still create and enter one
        // 5–10 character claim code; this value is never shown to them.
        'claim_code_lookup_key' => 'replace-with-a-random-secret-at-least-32-characters',

        // Dedicated root secret for encrypting AI provider API keys stored from
        // System Admin. Keep this only in the uncommitted production config.php.
        // Use a separate random value of at least 32 characters.
        'ai_credentials_key' => 'replace-with-a-different-random-secret-at-least-32-characters',

        // Only configure these when Coveted is behind a reverse proxy you
        // control. The forwarding header is ignored unless REMOTE_ADDR matches
        // one of the trusted proxy IPs below.
        'client_ip_header' => '',
        'trusted_proxy_ips' => [],
    ],
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=coveted;charset=utf8mb4',
        'user' => 'coveted_user',
        'password' => 'replace-me',
    ],
    'push' => [
        // Web Push stays opt-in for members. Keep the private VAPID key only in
        // the uncommitted production config.php / environment-managed secret.
        'enabled' => false,
        'vapid_subject' => 'mailto:notifications@coveted.example',
        'vapid_public_key' => '',
        'vapid_private_key' => '',
        'batch_size' => 100,
    ],
];
