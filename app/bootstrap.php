<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$configFile = $root . '/config.php';

if (!is_file($configFile)) {
    http_response_code(500);
    exit('Coveted is not configured. Copy config-example.php to config.php.');
}

$config = require $configFile;
if (!is_array($config)) {
    throw new RuntimeException('Invalid Coveted configuration.');
}

$appConfig = (array)($config['app'] ?? []);
$environment = strtolower(trim((string)($appConfig['environment'] ?? 'production')));
$requestUsesHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$secure = $environment === 'production' || $requestUsesHttps;
$baseUrl = trim((string)($appConfig['base_url'] ?? ''));
$sessionName = trim((string)($appConfig['session_name'] ?? 'coveted_session'));

if (!in_array($environment, ['production', 'development', 'testing'], true)) {
    throw new RuntimeException('Invalid Coveted environment configuration.');
}

if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sessionName)) {
    throw new RuntimeException('Invalid Coveted session name.');
}

if ($environment === 'production') {
    $baseParts = filter_var($baseUrl, FILTER_VALIDATE_URL) !== false ? parse_url($baseUrl) : false;
    if (!is_array($baseParts) || strtolower((string)($baseParts['scheme'] ?? '')) !== 'https' || empty($baseParts['host'])) {
        throw new RuntimeException('Coveted base_url must be a valid HTTPS URL in production.');
    }

    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

date_default_timezone_set('UTC');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');

session_name($sessionName);
session_set_cookie_params([
    'lifetime' => 0,
    'httponly' => true,
    'secure' => $secure,
    'samesite' => 'Lax',
    'path' => '/',
]);

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Opener-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' https: data:; media-src 'self' https: blob:; connect-src 'self' https:");
    header('Cache-Control: no-store, private');

    if ($environment === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function coveted_config(?string $section = null): array
{
    global $config;
    return $section === null ? $config : (array)($config[$section] ?? []);
}

function coveted_cookie_secure(): bool
{
    $environment = strtolower((string)(coveted_config('app')['environment'] ?? 'production'));
    $requestUsesHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    return $environment === 'production' || $requestUsesHttps;
}

function coveted_db(): PDO
{
    static $pdo;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = coveted_config('database');
    $dsn = trim((string)($db['dsn'] ?? ''));

    if ($dsn === '') {
        throw new RuntimeException('Database DSN is not configured.');
    }

    $pdo = new PDO(
        $dsn,
        (string)($db['user'] ?? ''),
        (string)($db['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function coveted_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function coveted_json(array $value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

function coveted_safe_url(mixed $value, bool $allowRelative = true): ?string
{
    $url = trim((string)$value);

    if ($url === '' || strlen($url) > 1500 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
        return null;
    }

    if ($allowRelative && str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return $url;
    }

    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $environment = strtolower((string)(coveted_config('app')['environment'] ?? 'production'));
    $allowedSchemes = $environment === 'production' ? ['https'] : ['http', 'https'];

    if (
        !is_array($parts)
        || !in_array($scheme, $allowedSchemes, true)
        || empty($parts['host'])
        || isset($parts['user'], $parts['pass'])
    ) {
        return null;
    }

    return $url;
}

function coveted_safe_internal_path(mixed $value, string $fallback = '/'): string
{
    $path = trim((string)$value);

    if (
        $path === ''
        || strlen($path) > 1800
        || !str_starts_with($path, '/')
        || str_starts_with($path, '//')
        || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
    ) {
        return $fallback;
    }

    return $path;
}

function coveted_utc_datetime(string $value): DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException('Invalid UTC date/time value.');
    }

    try {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        throw new InvalidArgumentException('Invalid UTC date/time value.', 0, $e);
    }
}

function coveted_timezone(string $timezone = ''): DateTimeZone
{
    $timezone = trim($timezone);

    if ($timezone === '') {
        $timezone = (string)(coveted_config('app')['default_timezone'] ?? 'UTC');
    }

    try {
        return new DateTimeZone($timezone);
    } catch (Throwable) {
        return new DateTimeZone('UTC');
    }
}

function coveted_require_timezone(string $timezone): DateTimeZone
{
    $timezone = trim($timezone);
    if ($timezone === '') {
        throw new InvalidArgumentException('Choose a valid timezone.');
    }

    try {
        return new DateTimeZone($timezone);
    } catch (Throwable $e) {
        throw new InvalidArgumentException('Choose a valid timezone.', 0, $e);
    }
}

function coveted_uuid(string $prefix): string
{
    $prefix = preg_replace('/[^a-z0-9_-]/i', '', trim($prefix)) ?: 'id';
    return $prefix . '_' . bin2hex(random_bytes(12));
}

function coveted_url(string $path = ''): string
{
    $base = rtrim((string)(coveted_config('app')['base_url'] ?? ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function coveted_redirect(string $path): never
{
    $path = coveted_safe_internal_path($path);
    $status = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 303 : 302;
    header('Location: ' . $path, true, $status);
    exit;
}

function coveted_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function coveted_rotate_csrf_token(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
}

function coveted_require_csrf(): void
{
    $provided = (string)($_POST['csrf_token'] ?? '');

    if ($provided === '' || !hash_equals(coveted_csrf_token(), $provided)) {
        http_response_code(419);
        exit('Your session expired. Refresh and try again.');
    }
}

function coveted_current_user(): ?array
{
    static $loaded = false;
    static $user = null;

    if ($loaded) {
        return $user;
    }

    $loaded = true;
    $id = (int)($_SESSION['user_id'] ?? 0);

    if ($id < 1) {
        return null;
    }

    $stmt = coveted_db()->prepare(
        "SELECT id, public_id, email, display_name, status, last_login_at, created_at
         FROM users
         WHERE id = ? AND status = 'active'
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        unset($_SESSION['user_id']);
        return null;
    }

    $roles = coveted_db()->prepare(
        'SELECT role_key FROM user_roles WHERE user_id = ? ORDER BY role_key'
    );
    $roles->execute([$id]);
    $row['roles'] = array_column($roles->fetchAll(), 'role_key');

    return $user = $row;
}

function coveted_has_role(string $role): bool
{
    $user = coveted_current_user();
    return $user !== null && in_array($role, (array)$user['roles'], true);
}

function coveted_is_system_admin(array $user): bool
{
    return in_array('system_admin', (array)($user['roles'] ?? []), true);
}

function coveted_require_user(): array
{
    $user = coveted_current_user();

    if (!$user) {
        coveted_redirect('/auth.php?action=login');
    }

    return $user;
}

function coveted_require_role(string $role): array
{
    $user = coveted_require_user();

    if (!in_array($role, (array)$user['roles'], true)) {
        http_response_code(403);
        exit('You do not have access to this area.');
    }

    return $user;
}

function coveted_require_system_admin(): array
{
    return coveted_require_role('system_admin');
}

function coveted_audit(
    string $eventType,
    string $entityType,
    ?string $entityId = null,
    array $metadata = [],
    ?int $actorId = null
): void {
    $actorId ??= (int)(coveted_current_user()['id'] ?? 0);

    coveted_db()->prepare(
        'INSERT INTO audit_events (actor_user_id, event_type, entity_type, entity_id, metadata_json)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $actorId > 0 ? $actorId : null,
        $eventType,
        $entityType,
        $entityId,
        $metadata ? coveted_json($metadata) : null,
    ]);
}

function coveted_request_role(array $user, string $roleKey, string $note = ''): void
{
    $allowedRoles = ['attendee_host', 'artist_partner'];

    if (!in_array($roleKey, $allowedRoles, true)) {
        throw new InvalidArgumentException('Unsupported role request.');
    }

    if (in_array($roleKey, (array)$user['roles'], true)) {
        throw new InvalidArgumentException('That role is already active on your account.');
    }

    $note = trim($note);
    if (mb_strlen($note) > 2000) {
        throw new InvalidArgumentException('Keep the request note under 2,000 characters.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $lock = $pdo->prepare('SELECT status FROM users WHERE id = ? FOR UPDATE');
        $lock->execute([(int)$user['id']]);

        if ($lock->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Only active accounts can request additional access.');
        }

        $stmt = $pdo->prepare(
            "SELECT id
             FROM role_requests
             WHERE user_id = ? AND role_key = ? AND status = 'pending'
             LIMIT 1"
        );
        $stmt->execute([(int)$user['id'], $roleKey]);

        if ($stmt->fetch()) {
            throw new InvalidArgumentException('You already have a pending request.');
        }

        $pdo->prepare(
            "INSERT INTO role_requests (public_id, user_id, role_key, request_note, status)
             VALUES (?, ?, ?, ?, 'pending')"
        )->execute([
            coveted_uuid('rrq'),
            (int)$user['id'],
            $roleKey,
            $note !== '' ? $note : null,
        ]);

        coveted_audit(
            'role.requested',
            'user',
            (string)$user['public_id'],
            ['role' => $roleKey],
            (int)$user['id']
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_client_ip(): string
{
    $app = coveted_config('app');
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $remote = filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : 'unknown';
    $configuredHeader = trim((string)($app['client_ip_header'] ?? ''));
    $trustedProxyIps = array_values(array_filter(
        array_map('strval', (array)($app['trusted_proxy_ips'] ?? [])),
        static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
    ));

    if ($configuredHeader !== '' && in_array($remote, $trustedProxyIps, true)) {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $configuredHeader));
        $raw = trim((string)($_SERVER[$serverKey] ?? ''));

        if ($raw !== '') {
            foreach (explode(',', $raw) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }
    }

    return $remote;
}

/** @return array<int,array{key:string,limit:int,scope:string}> */
function coveted_auth_attempt_entries(string $email): array
{
    $normalizedEmail = strtolower(trim($email));
    $ip = coveted_client_ip();

    return [
        ['key' => hash('sha256', 'login-email|' . $normalizedEmail), 'limit' => 5, 'scope' => 'email'],
        ['key' => hash('sha256', 'login-ip|' . $ip), 'limit' => 25, 'scope' => 'ip'],
    ];
}

function coveted_auth_assert_allowed(string $email): void
{
    $entries = coveted_auth_attempt_entries($email);
    $keys = array_column($entries, 'key');
    $placeholders = implode(',', array_fill(0, count($keys), '?'));

    $stmt = coveted_db()->prepare(
        "SELECT blocked_until FROM auth_attempts WHERE attempt_key IN ({$placeholders})"
    );
    $stmt->execute($keys);

    foreach ($stmt->fetchAll() as $row) {
        if (!empty($row['blocked_until']) && strtotime((string)$row['blocked_until']) > time()) {
            throw new InvalidArgumentException('Too many sign-in attempts. Try again later.');
        }
    }
}

function coveted_auth_record_failure(string $email): void
{
    $entries = coveted_auth_attempt_entries($email);
    usort($entries, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        foreach ($entries as $entry) {
            $pdo->prepare(
                'INSERT INTO auth_attempts (attempt_key, failures, window_started_at, updated_at)
                 VALUES (?, 0, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE updated_at = updated_at'
            )->execute([$entry['key']]);

            $stmt = $pdo->prepare(
                'SELECT id, failures, window_started_at
                 FROM auth_attempts
                 WHERE attempt_key = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([$entry['key']]);
            $row = $stmt->fetch();

            if (!$row) {
                throw new RuntimeException('Unable to update sign-in throttle.');
            }

            $windowFresh = strtotime((string)$row['window_started_at']) >= time() - 900;
            $failures = ($windowFresh ? (int)$row['failures'] : 0) + 1;
            $blocked = $failures >= (int)$entry['limit'] ? date('Y-m-d H:i:s', time() + 900) : null;
            $windowStart = $windowFresh ? (string)$row['window_started_at'] : date('Y-m-d H:i:s');

            $pdo->prepare(
                'UPDATE auth_attempts
                 SET failures = ?, window_started_at = ?, blocked_until = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute([$failures, $windowStart, $blocked, (int)$row['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_auth_clear_email_attempts(string $email): void
{
    $key = hash('sha256', 'login-email|' . strtolower(trim($email)));
    coveted_db()->prepare('DELETE FROM auth_attempts WHERE attempt_key = ?')->execute([$key]);
}

function coveted_establish_session(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION = ['user_id' => $userId, 'csrf_token' => bin2hex(random_bytes(32))];
}

function coveted_login(string $email, string $password): bool
{
    $email = strtolower(trim($email));

    if (strlen($email) > 255 || strlen($password) > 4096) {
        return false;
    }

    coveted_auth_assert_allowed($email);

    $stmt = coveted_db()->prepare('SELECT id, password_hash, status FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row || $row['status'] !== 'active' || !password_verify($password, (string)$row['password_hash'])) {
        coveted_auth_record_failure($email);
        return false;
    }

    coveted_auth_clear_email_attempts($email);
    coveted_establish_session((int)$row['id']);

    if (password_needs_rehash((string)$row['password_hash'], PASSWORD_DEFAULT)) {
        coveted_db()->prepare('UPDATE users SET password_hash = ?, last_login_at = NOW() WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$row['id']]);
    } else {
        coveted_db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
            ->execute([(int)$row['id']]);
    }

    return true;
}

function coveted_register(string $name, string $email, string $password): array
{
    $name = trim($name);
    $email = strtolower(trim($email));

    if ($name === '' || mb_strlen($name) > 180 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
        throw new InvalidArgumentException('Enter your name.');
    }
    if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if (strlen($password) < 10) {
        throw new InvalidArgumentException('Use at least 10 characters for your password.');
    }
    if (strlen($password) > 4096) {
        throw new InvalidArgumentException('Password is too long.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $publicId = coveted_uuid('usr');
        $pdo->prepare(
            "INSERT INTO users (public_id, email, password_hash, display_name, status)
             VALUES (?, ?, ?, ?, 'active')"
        )->execute([$publicId, $email, password_hash($password, PASSWORD_DEFAULT), $name]);

        $userId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO user_roles (user_id, role_key) VALUES (?, 'attendee')")->execute([$userId]);
        $pdo->prepare('INSERT INTO profiles (user_id) VALUES (?)')->execute([$userId]);
        coveted_audit('user.registered', 'user', $publicId, [], $userId);
        $pdo->commit();

        return ['id' => $userId, 'public_id' => $publicId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            throw new InvalidArgumentException('An account already exists for that email.');
        }
        throw $e;
    }
}

function coveted_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)$params['secure'],
            'httponly' => (bool)$params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function coveted_asset_version(string $relativePath): string
{
    $path = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    $mtime = @filemtime($path);
    return $mtime !== false ? (string)$mtime : '1';
}

function coveted_shell_initials(string $name): string
{
    $initials = '';
    foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
        $initial = function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
        $initials .= function_exists('mb_strtoupper') ? mb_strtoupper($initial) : strtoupper($initial);
        if ((function_exists('mb_strlen') ? mb_strlen($initials) : strlen($initials)) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'U';
}

function coveted_shell_avatar_url(int $userId): ?string
{
    if ($userId < 1) {
        return null;
    }

    $stmt = coveted_db()->prepare('SELECT avatar_url FROM profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch() ?: [];

    return coveted_safe_url((string)($profile['avatar_url'] ?? ''), true);
}

function coveted_page_start(string $title, string $active = '', bool $adminMode = false): void
{
    $user = coveted_current_user();
    $isSystemAdmin = $user !== null && coveted_is_system_admin($user);
    $avatarUrl = $user ? coveted_shell_avatar_url((int)$user['id']) : null;
    $userName = trim((string)($user['display_name'] ?? '')) ?: 'Member';
    $userInitials = coveted_shell_initials($userName);
    $appConfig = coveted_config('app');
    $appName = (string)($appConfig['name'] ?? 'Coveted');
    $baseUrl = rtrim((string)($appConfig['base_url'] ?? ''), '/');
    $cssVersion = coveted_asset_version('assets/css/coveted.css');
    $isPublicHome = !$adminMode && $user === null && $title === 'Home';
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <?php if ($isPublicHome): ?>
        <title>Coveted · Real people. Extraordinary experiences.</title>
        <meta name="description" content="Coveted is a private social membership for curated gatherings, meaningful connections and benefits that reward showing up.">
        <meta name="theme-color" content="#090b0e">
        <meta property="og:type" content="website">
        <meta property="og:title" content="Coveted · Real people. Extraordinary experiences.">
        <meta property="og:description" content="A private social membership built around real-world gatherings, trusted communities, local places and artist moments.">
        <meta property="og:image" content="<?= coveted_e(rtrim($baseUrl, '/') . '/assets/images/landing/hero-rooftop.png') ?>">
        <link rel="preload" as="image" href="/assets/images/landing/hero-rooftop.png" fetchpriority="high">
    <?php else: ?>
        <title><?= coveted_e($title) ?> · <?= coveted_e($appName) ?></title>
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/css/coveted.css?v=<?= coveted_e($cssVersion) ?>">
</head>
<body<?= $adminMode ? ' class="cv-admin-body"' : ($isPublicHome ? ' class="cv-public-home"' : '') ?>>
<?php if (!$adminMode): ?>
<header class="cv-header">
    <a class="cv-brand" href="/">Coveted</a>
    <nav class="cv-nav" aria-label="Primary">
        <?php if ($user): ?>
            <?php foreach (['Home' => '/', 'Invitations' => '/invitations.php', 'Events' => '/events.php', 'Benefits' => '/benefits.php', 'Profile' => '/profile.php'] as $label => $url): ?>
                <a class="<?= $active === $label ? 'is-active' : '' ?>" href="<?= coveted_e($url) ?>"><?= coveted_e($label) ?></a>
            <?php endforeach; ?>
            <?php if ($isSystemAdmin): ?>
                <a class="cv-member-admin-link" href="/admin/"><span>Admin</span><small>Control Center</small></a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>
    <?php if (!$user): ?>
        <?php if ($title === 'Home'): ?>
            <nav class="cv-public-nav" aria-label="Public">
                <a href="#membership">Membership</a>
                <a href="#partners">Community</a>
                <a href="#about">About</a>
            </nav>
        <?php endif; ?>
        <div class="cv-header-actions">
            <a href="/auth.php?action=login">Sign in</a>
            <a class="cv-button" href="/auth.php?action=register">Join Coveted</a>
        </div>
    <?php endif; ?>
</header>

<?php if ($user): ?>
<div class="cv-app-topbar" data-app-topbar>
    <div class="cv-app-topbar-copy">
        <span class="cv-eyebrow"><?= $isSystemAdmin ? 'SYSTEM ADMIN · MEMBER VIEW' : 'MEMBER' ?></span>
        <strong><?= coveted_e($title) ?></strong>
    </div>
    <div class="cv-app-topbar-actions">
        <?php if ($isSystemAdmin): ?>
            <a class="cv-button cv-button-soft cv-app-admin-button" href="/admin/">Admin</a>
            <details class="cv-admin-dropdown cv-global-create-menu">
                <summary class="cv-button cv-button-primary"><span aria-hidden="true">＋</span> Create</summary>
                <div class="cv-admin-menu cv-admin-create-panel">
                    <span class="cv-admin-menu-label">CREATE</span>
                    <a href="/admin/?view=users#create-user"><strong>User</strong><small>Create an account and assign roles</small></a>
                    <a href="/admin/?view=businesses#create-business"><strong>Business</strong><small>Add a partner business</small></a>
                    <a href="/admin/?view=groups#create-group"><strong>Group</strong><small>Start a private community</small></a>
                    <a href="/admin/?view=events#create-event"><strong>Event</strong><small>Plan a new gathering</small></a>
                    <a href="/admin/?view=artists#create-artist"><strong>Artist</strong><small>Create an artist identity</small></a>
                    <a href="/admin/?view=benefits"><strong>Benefit</strong><small>Manage rewards, campaigns and distribution</small></a>
                </div>
            </details>
        <?php endif; ?>

        <details class="cv-admin-dropdown cv-global-account-menu">
            <summary class="cv-admin-avatar-button" aria-label="Open account menu">
                <?php if ($avatarUrl !== null): ?>
                    <img src="<?= coveted_e($avatarUrl) ?>" alt="">
                <?php else: ?>
                    <span><?= coveted_e($userInitials) ?></span>
                <?php endif; ?>
            </summary>
            <div class="cv-admin-menu cv-admin-account-panel">
                <div class="cv-admin-account-summary">
                    <strong><?= coveted_e($userName) ?></strong>
                    <small><?= coveted_e((string)$user['email']) ?></small>
                    <?php if ($isSystemAdmin): ?><span class="cv-admin-account-role">System Admin</span><?php endif; ?>
                </div>
                <?php if ($isSystemAdmin): ?>
                    <a href="/admin/"><strong>Admin Control Center</strong><small>Manage the entire Coveted platform</small></a>
                    <a href="/admin/onboarding.php"><strong>Admin Setup</strong><small>Review first-run setup and onboarding</small></a>
                    <span class="cv-admin-menu-label">QUICK CREATE</span>
                    <a href="/admin/?view=users#create-user"><strong>Add User</strong><small>Create an account and assign access</small></a>
                    <a href="/admin/?view=businesses#create-business"><strong>Add Business</strong><small>Add a venue or partner</small></a>
                    <a href="/admin/?view=groups#create-group"><strong>Add Group</strong><small>Create a private community</small></a>
                    <a href="/admin/?view=events#create-event"><strong>Add Event</strong><small>Plan a gathering</small></a>
                <?php endif; ?>
                <a href="/profile.php"><strong>Profile</strong><small>Photo, identity and account details</small></a>
                <form method="post" action="/auth.php?action=logout">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <button type="submit">Sign out</button>
                </form>
            </div>
        </details>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
<main class="cv-main<?= $adminMode ? ' cv-admin-main' : '' ?>">
<?php
}

function coveted_page_end(): void
{
    ?>
</main>
<div class="cv-player" data-coveted-player hidden>
    <img data-player-artwork alt="">
    <div class="cv-player-copy">
        <strong data-player-title>Nothing playing</strong>
        <span data-player-artist></span>
    </div>
    <button type="button" data-player-play aria-label="Play or pause">▶</button>
    <input data-player-progress type="range" min="0" max="100" value="0" aria-label="Playback position">
    <span data-player-time>0:00</span>
    <button type="button" data-player-close aria-label="Close player">×</button>
    <audio data-player-audio preload="metadata"></audio>
</div>
<?php $jsVersion = coveted_asset_version('assets/js/coveted.js'); ?>
<script src="/assets/js/coveted.js?v=<?= coveted_e($jsVersion) ?>" defer></script>
</body>
</html>
<?php
}
