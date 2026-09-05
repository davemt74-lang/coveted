<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function coveted_admin_agent_threads_ensure_schema(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo ??= coveted_db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_agent_threads (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(64) NOT NULL UNIQUE,
            owner_user_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL DEFAULT 'New Chat',
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            last_message_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_admin_agent_threads_owner_status (owner_user_id,status,last_message_at,id),
            CONSTRAINT fk_admin_agent_threads_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_agent_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thread_id BIGINT UNSIGNED NOT NULL,
            request_id VARCHAR(64) NULL,
            role ENUM('user','assistant','action') NOT NULL,
            content MEDIUMTEXT NOT NULL,
            provider VARCHAR(32) NULL,
            model VARCHAR(190) NULL,
            metadata_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_admin_agent_messages_thread_id (thread_id,id),
            KEY idx_admin_agent_messages_request (thread_id,request_id,id),
            CONSTRAINT fk_admin_agent_messages_thread FOREIGN KEY (thread_id) REFERENCES admin_agent_threads(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

function coveted_admin_agent_threads_schema_available(?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    try {
        $pdo->query('SELECT 1 FROM admin_agent_threads LIMIT 1');
        $pdo->query('SELECT 1 FROM admin_agent_messages LIMIT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

function coveted_admin_agent_thread_require_admin(array $admin): void
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
}

function coveted_admin_agent_thread_ref(string $ref): string
{
    $ref = trim($ref);
    if ($ref === '' || strlen($ref) > 64 || preg_match('/^[A-Za-z0-9_-]+$/', $ref) !== 1) {
        throw new InvalidArgumentException('Invalid Admin Agent thread reference.');
    }
    return $ref;
}

function coveted_admin_agent_request_id(string $requestId): string
{
    $requestId = trim($requestId);
    if (strlen($requestId) < 10 || strlen($requestId) > 64 || preg_match('/^[A-Za-z0-9_-]+$/', $requestId) !== 1) {
        throw new InvalidArgumentException('Invalid Admin Agent request identifier.');
    }
    return $requestId;
}

function coveted_admin_agent_thread_title_from_message(string $message): string
{
    $title = preg_replace('/\s+/u', ' ', trim($message)) ?: 'New Chat';
    $title = mb_substr($title, 0, 76);
    return $title !== '' ? $title : 'New Chat';
}

/** @return array<string,mixed>|null */
function coveted_admin_agent_thread_by_ref(array $admin, string $ref, ?PDO $pdo = null): ?array
{
    coveted_admin_agent_thread_require_admin($admin);
    $ref = coveted_admin_agent_thread_ref($ref);
    $pdo ??= coveted_db();

    try {
        $stmt = $pdo->prepare(
            'SELECT id, public_id, owner_user_id, title, status, last_message_at, created_at, updated_at
             FROM admin_agent_threads
             WHERE public_id = ? AND owner_user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$ref, (int)$admin['id']]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02' || (int)($e->errorInfo[1] ?? 0) === 1146) {
            return null;
        }
        throw $e;
    }
}

/** @return array<int,array<string,mixed>> */
function coveted_admin_agent_recent_threads(array $admin, int $limit = 8, string $search = '', ?PDO $pdo = null): array
{
    coveted_admin_agent_thread_require_admin($admin);
    $pdo ??= coveted_db();
    $limit = max(1, min($limit, 50));
    $search = trim($search);
    if (mb_strlen($search) > 120) {
        $search = mb_substr($search, 0, 120);
    }

    try {
        $params = [(int)$admin['id']];
        $where = "t.owner_user_id = ? AND t.status = 'active'";
        if ($search !== '') {
            $where .= " AND (
                t.title LIKE ?
                OR EXISTS (
                    SELECT 1 FROM admin_agent_messages m
                    WHERE m.thread_id = t.id
                      AND m.role IN ('user','assistant')
                      AND m.content LIKE ?
                )
            )";
            $pattern = '%' . $search . '%';
            $params[] = $pattern;
            $params[] = $pattern;
        }

        $stmt = $pdo->prepare(
            "SELECT t.id, t.public_id, t.title, t.status, t.last_message_at, t.created_at, t.updated_at,
                    (SELECT COUNT(*) FROM admin_agent_messages m WHERE m.thread_id = t.id) AS message_count
             FROM admin_agent_threads t
             WHERE {$where}
             ORDER BY COALESCE(t.last_message_at, t.created_at) DESC, t.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02' || (int)($e->errorInfo[1] ?? 0) === 1146) {
            return [];
        }
        throw $e;
    }
}

/** @return array<string,mixed> */
function coveted_admin_agent_thread_create(array $admin, string $title = 'New Chat', ?PDO $pdo = null): array
{
    coveted_admin_agent_thread_require_admin($admin);
    $pdo ??= coveted_db();
    coveted_admin_agent_threads_ensure_schema($pdo);

    $title = preg_replace('/\s+/u', ' ', trim($title)) ?: 'New Chat';
    if (mb_strlen($title) > 190) {
        $title = mb_substr($title, 0, 190);
    }
    $publicId = coveted_uuid('achat');
    $stmt = $pdo->prepare(
        "INSERT INTO admin_agent_threads (public_id, owner_user_id, title, status)
         VALUES (?, ?, ?, 'active')"
    );
    $stmt->execute([$publicId, (int)$admin['id'], $title]);

    coveted_audit(
        'admin.agent_thread_created',
        'admin_agent_thread',
        $publicId,
        [],
        (int)$admin['id']
    );

    return [
        'id' => (int)$pdo->lastInsertId(),
        'public_id' => $publicId,
        'owner_user_id' => (int)$admin['id'],
        'title' => $title,
        'status' => 'active',
    ];
}

function coveted_admin_agent_thread_rename(array $admin, string $ref, string $title, ?PDO $pdo = null): void
{
    coveted_admin_agent_thread_require_admin($admin);
    $pdo ??= coveted_db();
    coveted_admin_agent_threads_ensure_schema($pdo);
    $thread = coveted_admin_agent_thread_by_ref($admin, $ref, $pdo);
    if (!$thread || $thread['status'] !== 'active') {
        throw new InvalidArgumentException('Admin Agent chat not found.');
    }

    $title = preg_replace('/\s+/u', ' ', trim($title)) ?: '';
    if ($title === '' || mb_strlen($title) > 190) {
        throw new InvalidArgumentException('Enter a chat title under 190 characters.');
    }
    $pdo->prepare('UPDATE admin_agent_threads SET title = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute([$title, (int)$thread['id']]);
    coveted_audit('admin.agent_thread_renamed', 'admin_agent_thread', (string)$thread['public_id'], [], (int)$admin['id']);
}

function coveted_admin_agent_thread_archive(array $admin, string $ref, ?PDO $pdo = null): void
{
    coveted_admin_agent_thread_require_admin($admin);
    $pdo ??= coveted_db();
    coveted_admin_agent_threads_ensure_schema($pdo);
    $thread = coveted_admin_agent_thread_by_ref($admin, $ref, $pdo);
    if (!$thread || $thread['status'] !== 'active') {
        throw new InvalidArgumentException('Admin Agent chat not found.');
    }

    try {
        $activeRun = $pdo->prepare(
            "SELECT 1 FROM admin_agent_runs
             WHERE thread_id = ? AND status = 'processing'
             LIMIT 1"
        );
        $activeRun->execute([(int)$thread['id']]);
        if ($activeRun->fetchColumn()) {
            throw new InvalidArgumentException('Wait for the active Admin Agent request to finish before archiving this chat.');
        }
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02' && (int)($e->errorInfo[1] ?? 0) !== 1146) {
            throw $e;
        }
    }

    $pdo->prepare("UPDATE admin_agent_threads SET status = 'archived', updated_at = UTC_TIMESTAMP() WHERE id = ?")
        ->execute([(int)$thread['id']]);
    coveted_audit('admin.agent_thread_archived', 'admin_agent_thread', (string)$thread['public_id'], [], (int)$admin['id']);
}

/** @return array<int,array<string,mixed>> */
function coveted_admin_agent_thread_messages(array $admin, string $ref, int $limit = 100, ?PDO $pdo = null): array
{
    coveted_admin_agent_thread_require_admin($admin);
    $pdo ??= coveted_db();
    $thread = coveted_admin_agent_thread_by_ref($admin, $ref, $pdo);
    if (!$thread) {
        throw new InvalidArgumentException('Admin Agent chat not found.');
    }
    $limit = max(1, min($limit, 200));

    $stmt = $pdo->prepare(
        "SELECT id, request_id, role, content, provider, model, metadata_json, created_at
         FROM (
             SELECT id, request_id, role, content, provider, model, metadata_json, created_at
             FROM admin_agent_messages
             WHERE thread_id = ?
             ORDER BY id DESC
             LIMIT {$limit}
         ) recent
         ORDER BY id ASC"
    );
    $stmt->execute([(int)$thread['id']]);
    return $stmt->fetchAll();
}

/** @return array<int,array<string,mixed>> */
function coveted_admin_agent_thread_request_messages(array $admin, string $ref, string $requestId, ?PDO $pdo = null): array
{
    coveted_admin_agent_thread_require_admin($admin);
    $requestId = coveted_admin_agent_request_id($requestId);
    $pdo ??= coveted_db();
    $thread = coveted_admin_agent_thread_by_ref($admin, $ref, $pdo);
    if (!$thread) {
        throw new InvalidArgumentException('Admin Agent chat not found.');
    }

    $stmt = $pdo->prepare(
        'SELECT id, request_id, role, content, provider, model, metadata_json, created_at
         FROM admin_agent_messages
         WHERE thread_id = ? AND request_id = ?
         ORDER BY id ASC'
    );
    $stmt->execute([(int)$thread['id'], $requestId]);
    return $stmt->fetchAll();
}

/** @return array<string,mixed> */
function coveted_admin_agent_thread_append_message(
    array $admin,
    string $ref,
    string $role,
    string $content,
    string $requestId = '',
    ?string $provider = null,
    ?string $model = null,
    array $metadata = [],
    ?PDO $pdo = null
): array {
    coveted_admin_agent_thread_require_admin($admin);
    $pdo ??= coveted_db();
    coveted_admin_agent_threads_ensure_schema($pdo);
    $thread = coveted_admin_agent_thread_by_ref($admin, $ref, $pdo);
    if (!$thread || $thread['status'] !== 'active') {
        throw new InvalidArgumentException('Admin Agent chat not found or archived.');
    }
    if (!in_array($role, ['user','assistant','action'], true)) {
        throw new InvalidArgumentException('Invalid Admin Agent message role.');
    }

    $content = trim($content);
    $maxLength = match ($role) {
        'action' => 4000,
        'assistant' => 30000,
        default => 12000,
    };
    if ($content === '' || mb_strlen($content) > $maxLength) {
        throw new InvalidArgumentException('Admin Agent message content is invalid.');
    }
    if ($requestId !== '') {
        $requestId = coveted_admin_agent_request_id($requestId);
    }
    $provider = $provider !== null ? trim($provider) : null;
    $model = $model !== null ? trim($model) : null;
    if ($provider !== null && (strlen($provider) > 32 || preg_match('/^[A-Za-z0-9._-]+$/', $provider) !== 1)) {
        throw new InvalidArgumentException('Invalid stored AI provider.');
    }
    if ($model !== null && (strlen($model) > 190 || preg_match('/[^A-Za-z0-9._:\/-]/', $model) === 1)) {
        throw new InvalidArgumentException('Invalid stored AI model.');
    }

    $metadataJson = $metadata ? coveted_json($metadata) : null;
    if ($metadataJson !== null && strlen($metadataJson) > 12000) {
        throw new InvalidArgumentException('Admin Agent message metadata is too large.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO admin_agent_messages
            (thread_id, request_id, role, content, provider, model, metadata_json)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$thread['id'],
        $requestId !== '' ? $requestId : null,
        $role,
        $content,
        $provider !== '' ? $provider : null,
        $model !== '' ? $model : null,
        $metadataJson,
    ]);
    $messageId = (int)$pdo->lastInsertId();

    $newTitle = null;
    if ($role === 'user' && (string)$thread['title'] === 'New Chat') {
        $newTitle = coveted_admin_agent_thread_title_from_message($content);
        $pdo->prepare(
            'UPDATE admin_agent_threads SET title = ?, last_message_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([$newTitle, (int)$thread['id']]);
    } else {
        $pdo->prepare(
            'UPDATE admin_agent_threads SET last_message_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?'
        )->execute([(int)$thread['id']]);
    }

    return [
        'id' => $messageId,
        'request_id' => $requestId,
        'role' => $role,
        'content' => $content,
        'provider' => $provider,
        'model' => $model,
        'metadata' => $metadata,
        'thread_title' => $newTitle,
    ];
}

/** @return array<int,array{role:string,content:string}> */
function coveted_admin_agent_thread_chat_history(array $admin, string $ref, int $limit = 20, ?PDO $pdo = null): array
{
    $rows = coveted_admin_agent_thread_messages($admin, $ref, 200, $pdo);
    $history = [];
    foreach ($rows as $row) {
        if (!in_array((string)$row['role'], ['user','assistant'], true)) {
            continue;
        }
        $content = trim((string)$row['content']);
        if ($content !== '') {
            $history[] = ['role' => (string)$row['role'], 'content' => $content];
        }
    }
    return array_slice($history, -max(1, min($limit, 100)));
}

/** @return array<string,mixed>|null */
function coveted_admin_agent_thread_completed_request(array $admin, string $ref, string $requestId, ?PDO $pdo = null): ?array
{
    $rows = coveted_admin_agent_thread_request_messages($admin, $ref, $requestId, $pdo);
    $assistant = null;
    $actions = [];
    foreach ($rows as $row) {
        $metadata = [];
        try {
            $decoded = json_decode((string)($row['metadata_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        } catch (Throwable) {
            $metadata = [];
        }

        if ($row['role'] === 'action') {
            $actions[] = [
                'action' => (string)($metadata['action'] ?? ''),
                'label' => (string)($metadata['label'] ?? 'Admin action'),
                'ok' => !empty($metadata['ok']),
                'message' => (string)$row['content'],
                'entity_ref' => (string)($metadata['entity_ref'] ?? ''),
            ];
        } elseif ($row['role'] === 'assistant') {
            $assistant = [
                'text' => (string)$row['content'],
                'provider' => (string)($row['provider'] ?? ''),
                'model' => (string)($row['model'] ?? ''),
            ];
        }
    }

    if ($assistant === null) {
        return null;
    }
    return [
        ...$assistant,
        'actions' => $actions,
    ];
}
