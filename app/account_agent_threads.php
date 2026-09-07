<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_agent_threads.php';

function coveted_account_agent_storage_available(?PDO $pdo = null): bool
{
    return coveted_admin_agent_threads_schema_available($pdo);
}

function coveted_account_agent_thread_ref(string $ref): string
{
    return coveted_admin_agent_thread_ref($ref);
}

function coveted_account_agent_request_id(string $requestId): string
{
    return coveted_admin_agent_request_id($requestId);
}

/** @return array<string,mixed>|null */
function coveted_account_agent_thread_by_ref(array $user, string $ref, ?PDO $pdo = null): ?array
{
    $ref = coveted_account_agent_thread_ref($ref);
    $pdo ??= coveted_db();
    $stmt = $pdo->prepare(
        'SELECT id, public_id, owner_user_id, title, status, last_message_at, created_at, updated_at
         FROM admin_agent_threads
         WHERE public_id = ? AND owner_user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$ref, (int)$user['id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** @return array<int,array<string,mixed>> */
function coveted_account_agent_recent_threads(array $user, int $limit = 8, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $limit = max(1, min($limit, 20));
    $stmt = $pdo->prepare(
        "SELECT t.public_id, t.title, t.last_message_at, t.created_at,
                (SELECT COUNT(*) FROM admin_agent_messages m WHERE m.thread_id=t.id AND m.role IN ('user','assistant')) AS message_count
         FROM admin_agent_threads t
         WHERE t.owner_user_id=? AND t.status='active'
         ORDER BY COALESCE(t.last_message_at,t.created_at) DESC,t.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([(int)$user['id']]);
    return $stmt->fetchAll();
}

/** @return array<string,mixed> */
function coveted_account_agent_thread_create(array $user, string $title = 'New Chat', ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    if (!coveted_account_agent_storage_available($pdo)) {
        throw new RuntimeException('Persistent Agent chat storage is unavailable. Import the existing Admin Agent threads migration.');
    }
    $title = preg_replace('/\s+/u', ' ', trim($title)) ?: 'New Chat';
    if (mb_strlen($title) > 190) {
        $title = mb_substr($title, 0, 190);
    }
    $publicId = coveted_uuid('chat');
    $stmt = $pdo->prepare(
        "INSERT INTO admin_agent_threads (public_id,owner_user_id,title,status) VALUES (?,?,?,'active')"
    );
    $stmt->execute([$publicId, (int)$user['id'], $title]);
    coveted_audit('account.agent_thread_created', 'agent_thread', $publicId, [], (int)$user['id']);
    return [
        'id'=>(int)$pdo->lastInsertId(),
        'public_id'=>$publicId,
        'owner_user_id'=>(int)$user['id'],
        'title'=>$title,
        'status'=>'active',
    ];
}

/** @return array<int,array<string,mixed>> */
function coveted_account_agent_thread_messages(array $user, string $ref, int $limit = 100, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $thread = coveted_account_agent_thread_by_ref($user, $ref, $pdo);
    if (!$thread || $thread['status'] !== 'active') {
        throw new InvalidArgumentException('Agent chat not found.');
    }
    $limit = max(1, min($limit, 160));
    $stmt = $pdo->prepare(
        "SELECT id,request_id,role,content,provider,model,metadata_json,created_at
         FROM (
             SELECT id,request_id,role,content,provider,model,metadata_json,created_at
             FROM admin_agent_messages
             WHERE thread_id=? AND role IN ('user','assistant')
             ORDER BY id DESC LIMIT {$limit}
         ) recent ORDER BY id ASC"
    );
    $stmt->execute([(int)$thread['id']]);
    return $stmt->fetchAll();
}

/** @return array<int,array{role:string,content:string}> */
function coveted_account_agent_chat_history(array $user, string $ref, int $limit = 20, ?PDO $pdo = null): array
{
    $rows = coveted_account_agent_thread_messages($user, $ref, 160, $pdo);
    $history = [];
    foreach ($rows as $row) {
        $role = (string)($row['role'] ?? '');
        if (!in_array($role, ['user','assistant'], true)) {
            continue;
        }
        $history[] = ['role'=>$role,'content'=>(string)$row['content']];
    }
    return array_slice($history, -max(1, min($limit, 24)));
}

/** @return array<string,mixed>|null */
function coveted_account_agent_request_response(array $user, string $ref, string $requestId, ?PDO $pdo = null): ?array
{
    $requestId = coveted_account_agent_request_id($requestId);
    $pdo ??= coveted_db();
    $thread = coveted_account_agent_thread_by_ref($user, $ref, $pdo);
    if (!$thread) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT role,content,provider,model FROM admin_agent_messages
         WHERE thread_id=? AND request_id=? AND role IN ('user','assistant') ORDER BY id ASC"
    );
    $stmt->execute([(int)$thread['id'], $requestId]);
    $userMessage = null;
    $assistant = null;
    foreach ($stmt->fetchAll() as $row) {
        if ($row['role'] === 'user' && $userMessage === null) $userMessage = $row;
        if ($row['role'] === 'assistant') $assistant = $row;
    }
    if (!$assistant) return null;
    return [
        'user_content'=>(string)($userMessage['content'] ?? ''),
        'text'=>(string)$assistant['content'],
        'provider'=>(string)($assistant['provider'] ?? ''),
        'model'=>(string)($assistant['model'] ?? ''),
    ];
}

/** @return array<string,mixed> */
function coveted_account_agent_append_message(
    array $user,
    string $ref,
    string $role,
    string $content,
    string $requestId = '',
    ?string $provider = null,
    ?string $model = null,
    array $metadata = [],
    ?PDO $pdo = null
): array {
    if (!in_array($role, ['user','assistant'], true)) {
        throw new InvalidArgumentException('Invalid Agent message role.');
    }
    $content = trim($content);
    $max = $role === 'assistant' ? 30000 : 12000;
    if ($content === '' || mb_strlen($content) > $max) {
        throw new InvalidArgumentException('Agent message content is invalid.');
    }
    if ($requestId !== '') $requestId = coveted_account_agent_request_id($requestId);
    $pdo ??= coveted_db();
    $thread = coveted_account_agent_thread_by_ref($user, $ref, $pdo);
    if (!$thread || $thread['status'] !== 'active') {
        throw new InvalidArgumentException('Agent chat not found.');
    }
    $metadataJson = $metadata ? coveted_json($metadata) : null;
    if ($metadataJson !== null && strlen($metadataJson) > 8000) {
        throw new InvalidArgumentException('Agent message metadata is too large.');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO admin_agent_messages (thread_id,request_id,role,content,provider,model,metadata_json) VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        (int)$thread['id'],
        $requestId !== '' ? $requestId : null,
        $role,
        $content,
        $provider,
        $model,
        $metadataJson,
    ]);
    $title = (string)$thread['title'];
    if ($role === 'user' && $title === 'New Chat') {
        $title = coveted_admin_agent_thread_title_from_message($content);
        $pdo->prepare('UPDATE admin_agent_threads SET title=?,last_message_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?')
            ->execute([$title,(int)$thread['id']]);
    } else {
        $pdo->prepare('UPDATE admin_agent_threads SET last_message_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?')
            ->execute([(int)$thread['id']]);
    }
    return ['id'=>(int)$pdo->lastInsertId(),'role'=>$role,'content'=>$content,'thread_title'=>$title];
}
