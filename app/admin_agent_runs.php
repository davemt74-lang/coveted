<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_agent_threads.php';

function coveted_admin_agent_runs_ensure_schema(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo ??= coveted_db();
    coveted_admin_agent_threads_ensure_schema($pdo);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_agent_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thread_id BIGINT UNSIGNED NOT NULL,
            request_id VARCHAR(64) NOT NULL,
            user_message_hash CHAR(64) NOT NULL,
            status ENUM('processing','completed','interrupted') NOT NULL DEFAULT 'processing',
            mutation_started TINYINT(1) NOT NULL DEFAULT 0,
            response_text MEDIUMTEXT NULL,
            provider VARCHAR(32) NULL,
            model VARCHAR(190) NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_admin_agent_runs_thread_request (thread_id,request_id),
            KEY idx_admin_agent_runs_status_updated (status,updated_at),
            CONSTRAINT fk_admin_agent_runs_thread FOREIGN KEY (thread_id) REFERENCES admin_agent_threads(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ready = true;
}

/** @return array<string,mixed>|null */
function coveted_admin_agent_run_by_request(
    array $admin,
    string $threadRef,
    string $requestId,
    ?PDO $pdo = null
): ?array {
    coveted_admin_agent_thread_require_admin($admin);
    $requestId = coveted_admin_agent_request_id($requestId);
    $pdo ??= coveted_db();
    coveted_admin_agent_runs_ensure_schema($pdo);
    $thread = coveted_admin_agent_thread_by_ref($admin, $threadRef, $pdo);
    if (!$thread) {
        throw new InvalidArgumentException('Admin Agent chat not found.');
    }

    $stmt = $pdo->prepare(
        'SELECT id, thread_id, request_id, user_message_hash, status, mutation_started,
                response_text, provider, model, started_at, completed_at, updated_at
         FROM admin_agent_runs
         WHERE thread_id = ? AND request_id = ?
         LIMIT 1'
    );
    $stmt->execute([(int)$thread['id'], $requestId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Atomically claim one browser request for provider/action processing.
 *
 * @return array{state:string,run:array<string,mixed>}
 */
function coveted_admin_agent_run_claim(
    array $admin,
    string $threadRef,
    string $requestId,
    string $userMessage,
    ?PDO $pdo = null
): array {
    coveted_admin_agent_thread_require_admin($admin);
    $threadRef = coveted_admin_agent_thread_ref($threadRef);
    $requestId = coveted_admin_agent_request_id($requestId);
    $userMessage = trim($userMessage);
    if ($userMessage === '' || mb_strlen($userMessage) > 12000) {
        throw new InvalidArgumentException('Enter a message for the Admin Agent.');
    }

    $pdo ??= coveted_db();
    coveted_admin_agent_runs_ensure_schema($pdo);
    $thread = coveted_admin_agent_thread_by_ref($admin, $threadRef, $pdo);
    if (!$thread || $thread['status'] !== 'active') {
        throw new InvalidArgumentException('Admin Agent chat not found or archived.');
    }

    $messageHash = hash('sha256', $userMessage);
    try {
        $insert = $pdo->prepare(
            "INSERT INTO admin_agent_runs
                (thread_id, request_id, user_message_hash, status, mutation_started)
             VALUES (?, ?, ?, 'processing', 0)"
        );
        $insert->execute([(int)$thread['id'], $requestId, $messageHash]);
        $run = coveted_admin_agent_run_by_request($admin, $threadRef, $requestId, $pdo);
        if (!$run) {
            throw new RuntimeException('Unable to claim the Admin Agent request.');
        }
        return ['state' => 'claimed', 'run' => $run];
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') {
            throw $e;
        }
    }

    $run = coveted_admin_agent_run_by_request($admin, $threadRef, $requestId, $pdo);
    if (!$run) {
        throw new RuntimeException('Unable to read the existing Admin Agent request.');
    }
    if (!hash_equals((string)$run['user_message_hash'], $messageHash)) {
        throw new InvalidArgumentException('That Admin Agent request identifier was already used for a different message.');
    }
    if ((string)$run['status'] === 'completed') {
        return ['state' => 'completed', 'run' => $run];
    }
    if (!empty($run['mutation_started'])) {
        return ['state' => 'blocked', 'run' => $run];
    }

    if ((string)$run['status'] === 'processing') {
        // Provider requests are capped at 60 seconds each and three rounds.
        // Five minutes is deliberately longer than the entire normal loop.
        $markStale = $pdo->prepare(
            "UPDATE admin_agent_runs
             SET status = 'interrupted', updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND status = 'processing'
               AND mutation_started = 0
               AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)"
        );
        $markStale->execute([(int)$run['id']]);
        if ($markStale->rowCount() === 0) {
            return ['state' => 'processing', 'run' => $run];
        }
    }

    $resume = $pdo->prepare(
        "UPDATE admin_agent_runs
         SET status = 'processing', started_at = UTC_TIMESTAMP(), completed_at = NULL,
             response_text = NULL, provider = NULL, model = NULL, updated_at = UTC_TIMESTAMP()
         WHERE id = ? AND status = 'interrupted' AND mutation_started = 0"
    );
    $resume->execute([(int)$run['id']]);
    $run = coveted_admin_agent_run_by_request($admin, $threadRef, $requestId, $pdo) ?? $run;
    return [
        'state' => $resume->rowCount() === 1 ? 'claimed' : ((string)$run['status'] === 'completed' ? 'completed' : 'processing'),
        'run' => $run,
    ];
}

function coveted_admin_agent_run_mark_mutation_started(
    array $admin,
    string $threadRef,
    string $requestId,
    ?PDO $pdo = null
): void {
    $pdo ??= coveted_db();
    $run = coveted_admin_agent_run_by_request($admin, $threadRef, $requestId, $pdo);
    if (!$run || (string)$run['status'] !== 'processing') {
        throw new RuntimeException('Admin Agent request is not available for mutation.');
    }
    $pdo->prepare(
        'UPDATE admin_agent_runs SET mutation_started = 1, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = ?'
    )->execute([(int)$run['id'], 'processing']);
}

function coveted_admin_agent_run_complete(
    array $admin,
    string $threadRef,
    string $requestId,
    string $responseText,
    ?string $provider,
    ?string $model,
    ?PDO $pdo = null
): void {
    $responseText = trim($responseText);
    if ($responseText === '' || mb_strlen($responseText) > 12000) {
        throw new InvalidArgumentException('Admin Agent response is invalid.');
    }
    $provider = $provider !== null ? trim($provider) : null;
    $model = $model !== null ? trim($model) : null;
    if ($provider !== null && $provider !== '' && (strlen($provider) > 32 || preg_match('/^[A-Za-z0-9._-]+$/', $provider) !== 1)) {
        throw new InvalidArgumentException('Invalid stored AI provider.');
    }
    if ($model !== null && $model !== '' && (strlen($model) > 190 || preg_match('/[^A-Za-z0-9._:\/-]/', $model) === 1)) {
        throw new InvalidArgumentException('Invalid stored AI model.');
    }

    $pdo ??= coveted_db();
    $run = coveted_admin_agent_run_by_request($admin, $threadRef, $requestId, $pdo);
    if (!$run) {
        throw new RuntimeException('Admin Agent request not found.');
    }
    $pdo->prepare(
        "UPDATE admin_agent_runs
         SET status = 'completed', response_text = ?, provider = ?, model = ?,
             completed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
         WHERE id = ?"
    )->execute([
        $responseText,
        $provider !== '' ? $provider : null,
        $model !== '' ? $model : null,
        (int)$run['id'],
    ]);
}

function coveted_admin_agent_run_interrupt(
    array $admin,
    string $threadRef,
    string $requestId,
    ?PDO $pdo = null
): void {
    try {
        $pdo ??= coveted_db();
        $run = coveted_admin_agent_run_by_request($admin, $threadRef, $requestId, $pdo);
        if (!$run || (string)$run['status'] !== 'processing') {
            return;
        }
        $pdo->prepare(
            "UPDATE admin_agent_runs SET status = 'interrupted', updated_at = UTC_TIMESTAMP()
             WHERE id = ? AND status = 'processing'"
        )->execute([(int)$run['id']]);
    } catch (Throwable $e) {
        error_log('Admin Agent run interruption marker failed: ' . $e->getMessage());
    }
}
