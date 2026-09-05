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
    if ($argument === '--expect-empty' || $argument === '--expect-installed') {
        $requested = $argument === '--expect-empty' ? 'empty' : 'installed';
        if ($expectWasSet && $expect !== $requested) {
            fwrite(STDERR, "[FAIL] Choose only one schema expectation: --expect-empty or --expect-installed.\n");
            exit(1);
        }
        $expect = $requested;
        $expectWasSet = true;
    } elseif ($argument === '--production') {
        $requireProduction = true;
    } elseif (in_array($argument, ['-h', '--help'], true)) {
        fwrite(STDOUT, "Usage: php scripts/preflight.php [--expect-empty|--expect-installed] [--production]\n");
        exit(0);
    } else {
        fwrite(STDERR, 'Unknown preflight option: ' . $argument . "\n");
        exit(1);
    }
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

        $schemaState = coveted_deployment_schema_state($pdo, $root . '/database/schema.sql');
        $schemaIssues = coveted_deployment_schema_expectation_issues($schemaState, $expect);
        $errors = array_merge($errors, $schemaIssues['errors']);
        $warnings = array_merge($warnings, $schemaIssues['warnings']);
    } catch (Throwable $e) {
        $errors[] = 'Database preflight failed: ' . $e->getMessage();
    }
}

fwrite(STDOUT, "Coveted first-install preflight\n");
fwrite(STDOUT, str_repeat('=', 32) . "\n");
fwrite(STDOUT, '[INFO] PHP ' . PHP_VERSION . "\n");
if ($dbVersion !== null && $dbVersion !== '') {
    fwrite(STDOUT, '[INFO] Database ' . $dbVersion . "\n");
}
if (is_array($schemaState)) {
    fwrite(
        STDOUT,
        sprintf(
            "[INFO] Schema state: %s (%d/%d tables present)\n",
            (string)$schemaState['state'],
            (int)$schemaState['actual_count'],
            (int)$schemaState['expected_count']
        )
    );
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

fwrite(STDOUT, "[OK] Coveted preflight passed.\n");
exit(0);
