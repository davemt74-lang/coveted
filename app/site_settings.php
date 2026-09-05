<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const COVETED_SETTING_LANDING_EVENTS = 'landing_upcoming_events_enabled';
const COVETED_SETTING_LANDING_SAMPLE_EVENTS = 'landing_sample_events_enabled';

/**
 * Runtime-safe settings table bootstrap.
 *
 * The public site never creates schema. System Admin settings pages call this
 * explicitly before writing so an existing install can adopt new settings
 * without making ordinary page requests depend on DDL permissions.
 */
function coveted_site_settings_ensure_schema(?PDO $pdo = null): void
{
    $pdo ??= coveted_db();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_by_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_site_settings_updated (updated_at),
            KEY idx_site_settings_updated_by (updated_by_user_id),
            CONSTRAINT fk_site_settings_updated_by
                FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function coveted_site_setting_key(string $key): string
{
    $key = trim($key);
    if ($key === '' || strlen($key) > 120 || !preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $key)) {
        throw new InvalidArgumentException('Invalid site setting key.');
    }

    return $key;
}

function coveted_site_setting_get(string $key, ?string $default = null, ?PDO $pdo = null): ?string
{
    $pdo ??= coveted_db();
    $key = coveted_site_setting_key($key);

    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? $default : (string)$value;
    } catch (PDOException $e) {
        // A deployment may receive this application code before System Admin
        // has opened settings and initialized the table. Default safely OFF.
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        if ($e->getCode() === '42S02' || $driverCode === 1146) {
            return $default;
        }

        error_log('Coveted site setting read failed [' . $key . ']: ' . $e->getMessage());
        return $default;
    }
}

function coveted_site_setting_bool(string $key, bool $default = false, ?PDO $pdo = null): bool
{
    $raw = coveted_site_setting_get($key, $default ? '1' : '0', $pdo);

    return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
}

function coveted_site_setting_set(string $key, string $value, array $actor, ?PDO $pdo = null): void
{
    $pdo ??= coveted_db();
    $key = coveted_site_setting_key($key);
    $actorId = (int)($actor['id'] ?? 0);
    if ($actorId < 1) {
        throw new RuntimeException('System Admin access required.');
    }

    $roleStmt = $pdo->prepare(
        "SELECT 1 FROM user_roles WHERE user_id = ? AND role_key = 'system_admin' LIMIT 1"
    );
    $roleStmt->execute([$actorId]);
    if (!$roleStmt->fetchColumn()) {
        throw new RuntimeException('System Admin access required.');
    }

    coveted_site_settings_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        "INSERT INTO site_settings (setting_key, setting_value, updated_by_user_id)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by_user_id = VALUES(updated_by_user_id),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([$key, $value, $actorId]);

    try {
        $audit = $pdo->prepare(
            "INSERT INTO audit_events (actor_user_id, event_type, entity_type, entity_id, metadata_json)
             VALUES (?, 'site_setting.updated', 'site_setting', ?, ?)"
        );
        $audit->execute([
            $actorId,
            $key,
            json_encode(['value' => $value], JSON_THROW_ON_ERROR),
        ]);
    } catch (Throwable $e) {
        // The setting is authoritative; a logging failure must not undo it.
        error_log('Coveted site setting audit failed [' . $key . ']: ' . $e->getMessage());
    }
}

function coveted_site_setting_set_bool(string $key, bool $enabled, array $actor, ?PDO $pdo = null): void
{
    coveted_site_setting_set($key, $enabled ? '1' : '0', $actor, $pdo);
}
