<?php
declare(strict_types=1);

require_once __DIR__ . '/deployment.php';

function coveted_installer_validate_input(array $input): array
{
    $siteName = trim((string)($input['site_name'] ?? 'Coveted'));
    $baseUrl = rtrim(trim((string)($input['base_url'] ?? '')), '/');
    $timezone = trim((string)($input['timezone'] ?? 'America/Phoenix'));
    $dbHost = trim((string)($input['db_host'] ?? 'localhost'));
    $dbPort = trim((string)($input['db_port'] ?? '3306'));
    $dbName = trim((string)($input['db_name'] ?? ''));
    $dbUser = trim((string)($input['db_user'] ?? ''));
    $dbPassword = (string)($input['db_password'] ?? '');
    $adminName = trim((string)($input['admin_name'] ?? ''));
    $adminEmail = strtolower(trim((string)($input['admin_email'] ?? '')));
    $adminPassword = (string)($input['admin_password'] ?? '');
    $adminPasswordConfirm = (string)($input['admin_password_confirm'] ?? '');

    if ($siteName === '' || mb_strlen($siteName) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $siteName) === 1) {
        throw new InvalidArgumentException('Enter a valid site name.');
    }

    $urlParts = filter_var($baseUrl, FILTER_VALIDATE_URL) !== false ? parse_url($baseUrl) : false;
    if (!is_array($urlParts) || strtolower((string)($urlParts['scheme'] ?? '')) !== 'https' || empty($urlParts['host'])) {
        throw new InvalidArgumentException('Base URL must be a valid HTTPS URL.');
    }
    if (isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['query']) || isset($urlParts['fragment'])) {
        throw new InvalidArgumentException('Base URL cannot include credentials, a query string, or a fragment.');
    }
    $urlPath = (string)($urlParts['path'] ?? '');
    if ($urlPath !== '' && $urlPath !== '/') {
        throw new InvalidArgumentException('Coveted must be installed at the root of the base URL, not in a subdirectory.');
    }

    try {
        new DateTimeZone($timezone);
    } catch (Throwable $e) {
        throw new InvalidArgumentException('Choose a valid timezone.', 0, $e);
    }

    if ($dbHost === '' || strlen($dbHost) > 255 || preg_match('/^[A-Za-z0-9._:-]+$/', $dbHost) !== 1) {
        throw new InvalidArgumentException('Enter a valid database host.');
    }
    if (!ctype_digit($dbPort) || (int)$dbPort < 1 || (int)$dbPort > 65535) {
        throw new InvalidArgumentException('Enter a valid database port.');
    }
    if ($dbName === '' || strlen($dbName) > 64 || preg_match('/^[A-Za-z0-9_$-]+$/', $dbName) !== 1) {
        throw new InvalidArgumentException('Enter a valid database name.');
    }
    if ($dbUser === '' || strlen($dbUser) > 128 || preg_match('/[\x00-\x1F\x7F]/', $dbUser) === 1) {
        throw new InvalidArgumentException('Enter a valid database username.');
    }

    if ($adminName === '' || mb_strlen($adminName) > 180 || preg_match('/[\x00-\x1F\x7F]/u', $adminName) === 1) {
        throw new InvalidArgumentException('Enter the first admin name.');
    }
    if (strlen($adminEmail) > 255 || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid admin email address.');
    }
    if (strlen($adminPassword) < 10 || strlen($adminPassword) > 4096) {
        throw new InvalidArgumentException('Admin password must be at least 10 characters.');
    }
    if (!hash_equals($adminPassword, $adminPasswordConfirm)) {
        throw new InvalidArgumentException('Admin passwords do not match.');
    }

    return [
        'site_name' => $siteName,
        'base_url' => $baseUrl,
        'timezone' => $timezone,
        'db_host' => $dbHost,
        'db_port' => (int)$dbPort,
        'db_name' => $dbName,
        'db_user' => $dbUser,
        'db_password' => $dbPassword,
        'admin_name' => $adminName,
        'admin_email' => $adminEmail,
        'admin_password' => $adminPassword,
    ];
}

function coveted_installer_connect(array $data): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $data['db_host'],
        $data['db_port'],
        $data['db_name']
    );

    try {
        $pdo = new PDO($dsn, $data['db_user'], $data['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (PDOException $e) {
        throw new InvalidArgumentException('Unable to connect to that database. Check the host, database name, username, and password.', 0, $e);
    }

    $mysqlIssue = coveted_deployment_mysql_issue($pdo);
    if ($mysqlIssue !== null) {
        throw new InvalidArgumentException($mysqlIssue);
    }

    return $pdo;
}

function coveted_installer_schema_state(PDO $pdo, string $schemaFile): array
{
    return coveted_deployment_schema_state($pdo, $schemaFile);
}

function coveted_installer_apply_schema(PDO $pdo, string $schemaFile): void
{
    $sql = file_get_contents($schemaFile);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Canonical schema file could not be read.');
    }

    $pdo->exec($sql);
}

function coveted_installer_config_array(array $data): array
{
    $host = (string)(parse_url($data['base_url'], PHP_URL_HOST) ?: 'localhost');
    $vapidSubject = filter_var('notifications@' . $host, FILTER_VALIDATE_EMAIL)
        ? 'mailto:notifications@' . $host
        : 'mailto:notifications@example.com';

    return [
        'app' => [
            'name' => $data['site_name'],
            'base_url' => $data['base_url'],
            'environment' => 'production',
            'session_name' => 'coveted_session',
            'default_timezone' => $data['timezone'],
            // Generated automatically. It is not a setup field and does not need to be managed manually.
            'claim_code_lookup_key' => bin2hex(random_bytes(32)),
            'client_ip_header' => '',
            'trusted_proxy_ips' => [],
        ],
        'database' => [
            'dsn' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $data['db_host'],
                $data['db_port'],
                $data['db_name']
            ),
            'user' => $data['db_user'],
            'password' => $data['db_password'],
        ],
        'push' => [
            'enabled' => false,
            'vapid_subject' => $vapidSubject,
            'vapid_public_key' => '',
            'vapid_private_key' => '',
            'batch_size' => 100,
        ],
    ];
}

function coveted_installer_write_config(string $root, array $config): string
{
    $configPath = $root . '/config.php';
    if (is_file($configPath)) {
        throw new RuntimeException('Coveted is already configured.');
    }
    if (!is_writable($root)) {
        throw new RuntimeException('The Coveted root directory is not writable. Temporarily allow PHP to create config.php, then restore normal permissions.');
    }

    $php = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
    $temp = $root . '/.config.php.' . bin2hex(random_bytes(8)) . '.tmp';

    if (file_put_contents($temp, $php, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the temporary config file.');
    }
    @chmod($temp, 0600);

    if (!rename($temp, $configPath)) {
        @unlink($temp);
        throw new RuntimeException('Unable to create config.php.');
    }
    @chmod($configPath, 0600);

    return $configPath;
}

function coveted_installer_create_admin(PDO $pdo, array $data): int
{
    $existing = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($existing > 0) {
        throw new InvalidArgumentException('Coveted already has a user account. Setup is locked; sign in instead.');
    }

    $publicId = 'usr_' . bin2hex(random_bytes(12));
    $pdo->prepare(
        "INSERT INTO users (public_id, email, password_hash, display_name, status)
         VALUES (?, ?, ?, ?, 'active')"
    )->execute([
        $publicId,
        $data['admin_email'],
        password_hash($data['admin_password'], PASSWORD_DEFAULT),
        $data['admin_name'],
    ]);
    $userId = (int)$pdo->lastInsertId();

    $role = $pdo->prepare('INSERT INTO user_roles (user_id, role_key, granted_by) VALUES (?, ?, ?)');
    $role->execute([$userId, 'attendee', $userId]);
    $role->execute([$userId, 'system_admin', $userId]);
    $pdo->prepare('INSERT INTO profiles (user_id) VALUES (?)')->execute([$userId]);
    $pdo->prepare(
        "INSERT INTO audit_events (actor_user_id, event_type, entity_type, entity_id, metadata_json)
         VALUES (?, 'system.installed', 'user', ?, NULL)"
    )->execute([$userId, $publicId]);

    return $userId;
}

function coveted_installer_run(string $root, array $input): array
{
    $configPath = $root . '/config.php';
    if (is_file($configPath)) {
        throw new InvalidArgumentException('Coveted is already installed.');
    }

    $data = coveted_installer_validate_input($input);
    $pdo = coveted_installer_connect($data);
    $schemaFile = $root . '/database/schema.sql';
    $state = coveted_installer_schema_state($pdo, $schemaFile);

    if ($state['state'] === 'partial') {
        throw new InvalidArgumentException('The selected database contains a partial Coveted schema. Use a clean empty database or restore the complete canonical schema before setup.');
    }
    if ($state['state'] === 'empty') {
        coveted_installer_apply_schema($pdo, $schemaFile);
        $state = coveted_installer_schema_state($pdo, $schemaFile);
    }
    if ($state['state'] !== 'installed') {
        throw new RuntimeException('Coveted could not verify the installed database schema.');
    }

    $config = coveted_installer_config_array($data);
    $configIssues = coveted_deployment_config_issues($config, true);
    if ($configIssues['errors'] !== []) {
        throw new RuntimeException('Generated configuration failed validation: ' . implode(' ', $configIssues['errors']));
    }

    $pdo->beginTransaction();
    $writtenConfig = null;
    try {
        $adminId = coveted_installer_create_admin($pdo, $data);
        $writtenConfig = coveted_installer_write_config($root, $config);
        $pdo->commit();
        return ['admin_id' => $adminId, 'config_path' => $writtenConfig];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($writtenConfig !== null && is_file($writtenConfig)) {
            @unlink($writtenConfig);
        }
        throw $e;
    }
}
