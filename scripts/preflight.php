<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/app/deployment.php';

$expect = 'auto';
$expectWasSet = false;
$requireProduction = false;
foreach (array_slice($argv, 1) as $argument) {
    if (in_array($argument, ['--expect-empty', '--fresh'], true)) {
        $requested = 'empty';
    } elseif (in_array($argument, ['--expect-installed', '--upgrade'], true)) {
        $requested = 'installed';
    } elseif ($argument === '--production') {
        $requireProduction = true;
        continue;
    } elseif (in_array($argument, ['-h', '--help'], true)) {
        fwrite(STDOUT, "Usage: php scripts/preflight.php [--fresh|--upgrade] [--production]\n");
        fwrite(STDOUT, "       --fresh   require an empty database before a new installation\n");
        fwrite(STDOUT, "       --upgrade require the current baseline + migration-created schema before deploying code\n");
        exit(0);
    } else {
        fwrite(STDERR, 'Unknown preflight option: ' . $argument . "\n");
        exit(1);
    }

    if ($expectWasSet && $expect !== $requested) {
        fwrite(STDERR, "[FAIL] Choose only one schema expectation: --fresh or --upgrade.\n");
        exit(1);
    }
    $expect = $requested;
    $expectWasSet = true;
}

$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "[FAIL] config.php is missing. Copy config-example.php to config.php and configure it first.\n");
    exit(1);
}

try {
    $config = require $configFile;
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Unable to load config.php: ' . $e->getMessage() . "\n");
    exit(1);
}
if (!is_array($config)) {
    fwrite(STDERR, "[FAIL] config.php must return a PHP array.\n");
    exit(1);
}

$errors = [];
$warnings = [];

$runtime = coveted_deployment_runtime_issues($root);
$errors = array_merge($errors, $runtime['errors']);
$warnings = array_merge($warnings, $runtime['warnings']);

$configIssues = coveted_deployment_config_issues($config, $requireProduction);
$errors = array_merge($errors, $configIssues['errors']);
$warnings = array_merge($warnings, $configIssues['warnings']);

$schemaState = null;
$dbVersion = null;
if ($configIssues['errors'] === []) {
    try {
        $pdo = coveted_deployment_connect($config);
        $dbVersion = trim((string)$pdo->query('SELECT VERSION()')->fetchColumn());
        $mysqlIssue = coveted_deployment_mysql_issue($pdo);
        if ($mysqlIssue !== null) {
            $errors[] = $mysqlIssue;
        }

        $schemaState = coveted_deployment_schema_state(
            $pdo,
            $root . '/database/schema.sql',
            $root . '/database/migrations'
        );
        $schemaIssues = coveted_deployment_schema_expectation_issues($schemaState, $expect);
        $errors = array_merge($errors, $schemaIssues['errors']);
        $warnings = array_merge($warnings, $schemaIssues['warnings']);
    } catch (Throwable $e) {
        $errors[] = 'Database preflight failed: ' . $e->getMessage();
    }
}

fwrite(STDOUT, "Coveted deployment preflight\n");
fwrite(STDOUT, str_repeat('=', 28) . "\n");
fwrite(STDOUT, '[INFO] PHP ' . PHP_VERSION . "\n");
fwrite(STDOUT, '[INFO] Mode: ' . ($expect === 'empty' ? 'fresh install' : ($expect === 'installed' ? 'production upgrade' : 'automatic schema check')) . "\n");
if ($dbVersion !== null && $dbVersion !== '') {
    fwrite(STDOUT, '[INFO] Database ' . $dbVersion . "\n");
}
if (is_array($schemaState)) {
    fwrite(
        STDOUT,
        sprintf(
            "[INFO] Schema state: %s (%d/%d required tables present; %d baseline + %d migration-created)\n",
            (string)$schemaState['state'],
            (int)$schemaState['actual_count'],
            (int)$schemaState['expected_count'],
            (int)$schemaState['baseline_count'],
            (int)$schemaState['migration_table_count']
        )
    );
}

$requirements = coveted_deployment_release_requirements();
foreach ($requirements['required_migrations'] as $migration) {
    fwrite(STDOUT, '[INFO] Current release migration prerequisite: database/migrations/' . $migration . "\n");
}

foreach ($warnings as $warning) {
    fwrite(STDOUT, '[WARN] ' . $warning . "\n");
}
foreach ($errors as $error) {
    fwrite(STDERR, '[FAIL] ' . $error . "\n");
}

if ($errors !== []) {
    fwrite(STDERR, sprintf("Preflight failed with %d blocking issue(s).\n", count($errors)));
    exit(1);
}

fwrite(STDOUT, "[OK] Coveted deployment preflight passed.\n");
exit(0);
