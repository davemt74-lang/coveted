<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_agent_threads.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/** @return array<string,mixed> */
function coveted_admin_agent_thread_api_message(array $row): array
{
    $metadata = [];
    try {
        $decoded = json_decode((string)($row['metadata_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    } catch (Throwable) {
        $metadata = [];
    }

    $result = [
        'id' => (int)($row['id'] ?? 0),
        'request_id' => (string)($row['request_id'] ?? ''),
        'role' => (string)($row['role'] ?? ''),
        'content' => (string)($row['content'] ?? ''),
        'provider' => (string)($row['provider'] ?? ''),
        'model' => (string)($row['model'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
    ];

    if ($result['role'] === 'action') {
        $result['action'] = (string)($metadata['action'] ?? '');
        $result['label'] = (string)($metadata['label'] ?? 'Admin action');
        $result['ok'] = !empty($metadata['ok']);
        $result['entity_ref'] = (string)($metadata['entity_ref'] ?? '');
    }

    return $result;
}

try {
    $admin = coveted_require_system_admin();
    $pdo = coveted_db();
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $threadRef = trim((string)($_GET['thread'] ?? ''));
        if ($threadRef !== '') {
            $thread = coveted_admin_agent_thread_by_ref($admin, $threadRef, $pdo);
            if (!$thread || $thread['status'] !== 'active') {
                http_response_code(404);
                echo coveted_json(['ok' => false, 'error' => 'Admin Agent chat not found.']);
                exit;
            }
            $messages = array_map(
                'coveted_admin_agent_thread_api_message',
                coveted_admin_agent_thread_messages($admin, $threadRef, 200, $pdo)
            );
            echo coveted_json([
                'ok' => true,
                'thread' => [
                    'public_id' => (string)$thread['public_id'],
                    'title' => (string)$thread['title'],
                    'status' => (string)$thread['status'],
                    'last_message_at' => (string)($thread['last_message_at'] ?? ''),
                    'created_at' => (string)$thread['created_at'],
                ],
                'messages' => $messages,
            ]);
            exit;
        }

        $search = trim((string)($_GET['q'] ?? ''));
        $threads = coveted_admin_agent_recent_threads($admin, 50, $search, $pdo);
        echo coveted_json([
            'ok' => true,
            'threads' => array_map(
                static fn(array $thread): array => [
                    'public_id' => (string)$thread['public_id'],
                    'title' => (string)$thread['title'],
                    'message_count' => (int)$thread['message_count'],
                    'last_message_at' => (string)($thread['last_message_at'] ?? ''),
                    'created_at' => (string)$thread['created_at'],
                ],
                $threads
            ),
        ]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo coveted_json(['ok' => false, 'error' => 'GET or POST required.']);
        exit;
    }

    coveted_require_csrf();
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'create') {
        $thread = coveted_admin_agent_thread_create($admin, 'New Chat', $pdo);
        echo coveted_json([
            'ok' => true,
            'thread' => [
                'public_id' => (string)$thread['public_id'],
                'title' => (string)$thread['title'],
            ],
        ]);
        exit;
    }

    $threadRef = coveted_admin_agent_thread_ref((string)($_POST['thread'] ?? ''));
    if ($action === 'rename') {
        coveted_admin_agent_thread_rename($admin, $threadRef, (string)($_POST['title'] ?? ''), $pdo);
        echo coveted_json(['ok' => true]);
        exit;
    }

    if ($action === 'archive') {
        coveted_admin_agent_thread_archive($admin, $threadRef, $pdo);
        echo coveted_json(['ok' => true]);
        exit;
    }

    throw new InvalidArgumentException('Unsupported Admin Agent thread action.');
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo coveted_json(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Admin Agent thread API failed: ' . $e->getMessage());
    http_response_code(500);
    echo coveted_json(['ok' => false, 'error' => 'Admin Agent chat history is temporarily unavailable.']);
}
