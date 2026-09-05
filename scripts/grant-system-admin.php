<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$email = strtolower(trim((string)($argv[1] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/grant-system-admin.php admin@example.com\n");
    exit(1);
}

$stmt = coveted_db()->prepare(
    "SELECT id, display_name, status FROM users WHERE email = ? LIMIT 1"
);
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    fwrite(STDERR, "No Coveted user found for {$email}.\n");
    exit(1);
}

if ($user['status'] !== 'active') {
    fwrite(STDERR, "System Admin can only be granted to an active account.\n");
    exit(1);
}

coveted_db()->prepare(
    "INSERT IGNORE INTO user_roles (user_id, role_key) VALUES (?, 'system_admin')"
)->execute([(int)$user['id']]);

fwrite(STDOUT, "System Admin granted to {$user['display_name']} <{$email}>.\n");
