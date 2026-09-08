<?php
declare(strict_types=1);

/**
 * Deployment/preflight helpers intentionally do not require app/bootstrap.php.
 * They must be usable before a database install/upgrade and before a web request
 * can safely bootstrap the application.
 */

function coveted_deployment_required_extensions(): array
{
    return ['curl', 'json', 'mbstring', 'openssl', 'PDO', 'pdo_mysql'];
}

/** @return array{required_migrations:array<int,string>,required_tables:array<int,string>} */
function coveted_deployment_release_requirements(): array
{
    return [
        // Keep this list intentionally small: it represents schema prerequisites
        // for the code currently on main, not a second migration history.
        'required_migrations' => ['20260908_member_relationship_actions.sql'],
        'required_tables' => ['member_relationship_actions'],
    ];
}

/** @return array<int,string> */
function coveted_deployment_migration_files(string $migrationDir): array
{
    if (!is_dir($migrationDir)) {
        return [];
    }
    $files = [];
    foreach (scandir($migrationDir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $migrationDir . '/' . $name;
        if (is_file($path)) {
            $files[] = $name;
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

function coveted_deployment_migration_inventory_issues(string $migrationDir): array
{
    $errors = [];
    $warnings = [];
    if (!is_dir($migrationDir)) {
        $errors[] = 'Migration directory is missing: database/migrations.';
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    $files = coveted_deployment_migration_files($migrationDir);
    foreach ($files as $name) {
        if (!preg_match('/^\d{8}_[a-z0-9_]+\.sql$/', $name)) {
            $errors[] = 'Migration filename is not deployment-safe: ' . $name . '.';
            continue;
        }
        $path = $migrationDir . '/' . $name;
        $sql = @file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            $errors[] = 'Migration file is unreadable or empty: database/migrations/' . $name . '.';
        }
    }

    $requirements = coveted_deployment_release_requirements();
    foreach ($requirements['required_migrations'] as $name) {
        if (!in_array($name, $files, true)) {
            $errors[] = 'Required release migration is missing from the deploy package: database/migrations/' . $name . '.';
        }
    }

    if ($files === []) {
        $warnings[] = 'No additive migrations are present. This is unusual after Coveted\'s first production deployment.';
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}

function coveted_deployment_runtime_issues(string $root): array
{
    $errors = [];
    $warnings = [];

    // The repository continuously validates PHP 8.1 and 8.2. Keep deployment
    // requirements aligned with the compatibility contract exercised by CI.
    if (version_compare(PHP_VERSION, '8.1.0', '<')) {
        $errors[] = 'PHP 8.1 or newer is required; found ' . PHP_VERSION . '.';
    }

    foreach (coveted_deployment_required_extensions() as $extension) {
        if (!extension_loaded($extension)) {
            $errors[] = 'Required PHP extension is missing: ' . $extension . '.';
        }
    }

    $requiredFiles = [
        'composer.json',
        'database/schema.sql',
        'database/migrations/20260908_member_relationship_actions.sql',
        'scripts/reconcile-lifecycle.php',
        'scripts/dispatch-push.php',
        'app/bootstrap.php',
        'index.php',
    ];
    foreach ($requiredFiles as $relative) {
        if (!is_file($root . '/' . $relative)) {
            $errors[] = 'Required application file is missing: ' . $relative . '.';
        }
    }

    $migrationIssues = coveted_deployment_migration_inventory_issues($root . '/database/migrations');
    $errors = array_merge($errors, $migrationIssues['errors']);
    $warnings = array_merge($warnings, $migrationIssues['warnings']);

    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        $errors[] = 'Composer dependencies are not installed. Run composer install --no-dev --prefer-dist --no-interaction.';
    } else {
        require_once $autoload;
        if (!class_exists('Minishlink\\WebPush\\WebPush')) {
            $errors[] = 'Composer dependencies are incomplete: minishlink/web-push is unavailable.';
        }
    }

    $uploadDir = $root . '/uploads/pwa';
    if (!is_dir($uploadDir)) {
        $warnings[] = 'PWA upload directory does not exist yet: uploads/pwa. Create it and make it writable by the web process before uploading PWA artwork.';
    } elseif (!is_writable($uploadDir)) {
        $warnings[] = 'PWA upload directory is not writable by this process: uploads/pwa.';
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}

function coveted_deployment_config_issues(array $config, bool $requireProduction = false): array
{
    $errors = [];
    $warnings = [];

    $app = (array)($config['app'] ?? []);
    $database = (array)($config['database'] ?? []);
    $push = (array)($config['push'] ?? []);

    $environment = strtolower(trim((string)($app['environment'] ?? '')));
    if (!in_array($environment, ['production', 'development', 'testing'], true)) {
        $errors[] = 'app.environment must be production, development, or testing.';
    }
    if ($requireProduction && $environment !== 'production') {
        $errors[] = 'Production preflight requires app.environment=production.';
    }

    $baseUrl = trim((string)($app['base_url'] ?? ''));
    $baseParts = filter_var($baseUrl, FILTER_VALIDATE_URL) !== false ? parse_url($baseUrl) : false;
    if (!is_array($baseParts) || empty($baseParts['host'])) {
        $errors[] = 'app.base_url must be a valid absolute URL.';
    } else {
        $scheme = strtolower((string)($baseParts['scheme'] ?? ''));
        if ($environment === 'production' && $scheme !== 'https') {
            $errors[] = 'app.base_url must use HTTPS in production.';
        }
        if (isset($baseParts['user']) || isset($baseParts['pass'])) {
            $errors[] = 'app.base_url must not contain embedded credentials.';
        }
        if (isset($baseParts['query']) || isset($baseParts['fragment'])) {
            $errors[] = 'app.base_url must not contain a query string or fragment.';
        }
        $path = (string)($baseParts['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            $warnings[] = 'app.base_url contains a path. Coveted V1 routes are root-relative; deploy at the host root unless the web server rewrites root-relative routes intentionally.';
        }
    }

    $sessionName = trim((string)($app['session_name'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sessionName)) {
        $errors[] = 'app.session_name must contain only letters, numbers, underscore, or hyphen and be 1-64 characters.';
    }

    $timezone = trim((string)($app['default_timezone'] ?? ''));
    try {
        if ($timezone === '') {
            throw new RuntimeException('empty');
        }
        new DateTimeZone($timezone);
    } catch (Throwable) {
        $errors[] = 'app.default_timezone must be a valid IANA timezone.';
    }

    $lookupKey = trim((string)($app['claim_code_lookup_key'] ?? ''));
    $placeholderKeys = [
        'replace-with-a-random-secret-at-least-32-characters',
        'change-me',
        'replace-me',
    ];
    if (strlen($lookupKey) < 32) {
        $errors[] = 'app.claim_code_lookup_key must be at least 32 characters.';
    } elseif (in_array(strtolower($lookupKey), $placeholderKeys, true) || str_contains(strtolower($lookupKey), 'replace-with')) {
        $errors[] = 'app.claim_code_lookup_key is still using a placeholder value.';
    }

    $clientIpHeader = trim((string)($app['client_ip_header'] ?? ''));
    $trustedProxyIps = $app['trusted_proxy_ips'] ?? [];
    if (!is_array($trustedProxyIps)) {
        $errors[] = 'app.trusted_proxy_ips must be an array.';
    } else {
        foreach ($trustedProxyIps as $proxyIp) {
            if (!is_string($proxyIp) || filter_var($proxyIp, FILTER_VALIDATE_IP) === false) {
                $errors[] = 'Every app.trusted_proxy_ips entry must be a valid IPv4 or IPv6 address.';
                break;
            }
        }
        if ($clientIpHeader !== '' && $trustedProxyIps === []) {
            $errors[] = 'app.client_ip_header cannot be enabled without trusted_proxy_ips.';
        } elseif ($clientIpHeader === '' && $trustedProxyIps !== []) {
            $warnings[] = 'trusted_proxy_ips are configured but client_ip_header is disabled.';
        }
    }

    $dsn = trim((string)($database['dsn'] ?? ''));
    if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:')) {
        $errors[] = 'database.dsn must be a MySQL PDO DSN.';
    }
    if (trim((string)($database['user'] ?? '')) === '') {
        $errors[] = 'database.user is required.';
    }
    if ((string)($database['password'] ?? '') === 'replace-me') {
        $errors[] = 'database.password is still using the config-example placeholder.';
    }

    if (array_key_exists('enabled', $push) && !is_bool($push['enabled'])) {
        $errors[] = 'push.enabled must be a boolean true or false.';
    }
    $pushEnabled = ($push['enabled'] ?? false) === true;
    $batchSize = (int)($push['batch_size'] ?? 100);
    if ($batchSize < 1 || $batchSize > 1000) {
        $errors[] = 'push.batch_size must be between 1 and 1000.';
    }

    if ($pushEnabled) {
        $subject = trim((string)($push['vapid_subject'] ?? ''));
        $publicKey = trim((string)($push['vapid_public_key'] ?? ''));
        $privateKey = trim((string)($push['vapid_private_key'] ?? ''));
        $validSubject = false;
        if (str_starts_with($subject, 'mailto:')) {
            $validSubject = filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false;
        } elseif (str_starts_with($subject, 'https://')) {
            $validSubject = filter_var($subject, FILTER_VALIDATE_URL) !== false;
        }
        if (!$validSubject) {
            $errors[] = 'push.vapid_subject must be a valid mailto: address or HTTPS URL when Push is enabled.';
        }
        if (strlen($publicKey) < 40 || strlen($privateKey) < 40) {
            $errors[] = 'Push is enabled but VAPID public/private keys are missing or invalid.';
        }
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}

function coveted_deployment_connect(array $config): PDO
{
    $database = (array)($config['database'] ?? []);
    $pdo = new PDO(
        (string)($database['dsn'] ?? ''),
        (string)($database['user'] ?? ''),
        (string)($database['password'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function coveted_deployment_mysql_issue(PDO $pdo): ?string
{
    $version = trim((string)$pdo->query('SELECT VERSION()')->fetchColumn());
    if ($version === '') {
        return 'Unable to determine the database server version.';
    }
    if (stripos($version, 'mariadb') !== false) {
        return 'Coveted V1 is validated against MySQL 8; MariaDB is not an approved deployment target.';
    }
    if (!preg_match('/^(\d+)\.(\d+)/', $version, $matches) || (int)$matches[1] < 8) {
        return 'MySQL 8 or newer is required; found ' . $version . '.';
    }
    return null;
}

/** @return array<int,string> */
function coveted_deployment_sql_tables(string $sql): array
{
    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $matches);
    $tables = array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
    sort($tables);
    return $tables;
}

function coveted_deployment_schema_tables(string $schemaFile): array
{
    $sql = @file_get_contents($schemaFile);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Unable to read database/schema.sql.');
    }
    $tables = coveted_deployment_sql_tables($sql);
    if ($tables === []) {
        throw new RuntimeException('No canonical tables were found in database/schema.sql.');
    }
    return $tables;
}

/** @return array<int,string> */
function coveted_deployment_migration_tables(string $migrationDir): array
{
    $tables = [];
    foreach (coveted_deployment_migration_files($migrationDir) as $name) {
        $sql = @file_get_contents($migrationDir . '/' . $name);
        if ($sql === false) {
            continue;
        }
        $tables = array_merge($tables, coveted_deployment_sql_tables($sql));
    }
    $tables = array_values(array_unique(array_map('strtolower', $tables)));
    sort($tables);
    return $tables;
}

/** @return array<int,string> */
function coveted_deployment_expected_tables(string $schemaFile, ?string $migrationDir = null): array
{
    $tables = coveted_deployment_schema_tables($schemaFile);
    if ($migrationDir !== null) {
        $tables = array_merge($tables, coveted_deployment_migration_tables($migrationDir));
    }
    $requirements = coveted_deployment_release_requirements();
    $tables = array_merge($tables, $requirements['required_tables']);
    $tables = array_values(array_unique(array_map('strtolower', $tables)));
    sort($tables);
    return $tables;
}

function coveted_deployment_database_tables(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_type = 'BASE TABLE'
         ORDER BY table_name"
    );
    $tables = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $tables[] = strtolower((string)$table);
    }
    return $tables;
}

function coveted_deployment_schema_state(PDO $pdo, string $schemaFile, ?string $migrationDir = null): array
{
    $expected = coveted_deployment_expected_tables($schemaFile, $migrationDir);
    $actual = coveted_deployment_database_tables($pdo);
    $missing = array_values(array_diff($expected, $actual));
    $extra = array_values(array_diff($actual, $expected));

    $state = $actual === []
        ? 'empty'
        : ($missing === [] ? 'installed' : 'partial');

    return [
        'state' => $state,
        'expected_count' => count($expected),
        'actual_count' => count($actual),
        'missing' => $missing,
        'extra' => $extra,
        'baseline_count' => count(coveted_deployment_schema_tables($schemaFile)),
        'migration_table_count' => $migrationDir === null ? 0 : count(coveted_deployment_migration_tables($migrationDir)),
    ];
}

function coveted_deployment_schema_expectation_issues(array $state, string $expect): array
{
    $errors = [];
    $warnings = [];
    $actualState = (string)($state['state'] ?? 'partial');

    if ($actualState === 'partial') {
        $missing = (array)($state['missing'] ?? []);
        $errors[] = 'The database is missing required Coveted schema objects: ' . implode(', ', $missing) . '. Apply the corresponding additive migration(s) before deploying code.';
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    if ($expect === 'empty' && $actualState !== 'empty') {
        $errors[] = 'Fresh-install preflight expected an empty database, but tables already exist.';
    } elseif ($expect === 'installed' && $actualState !== 'installed') {
        $errors[] = 'Upgrade preflight expected the current Coveted schema and migration-created tables to be installed.';
    }

    if ($actualState === 'installed' && ($state['extra'] ?? []) !== []) {
        $warnings[] = 'Database contains non-canonical tables: ' . implode(', ', (array)$state['extra']) . '.';
    }

    return ['errors' => $errors, 'warnings' => $warnings];
}
