<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/ai_providers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $admin = coveted_require_system_admin();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo coveted_json(['ok' => false, 'error' => 'POST required.']);
        exit;
    }

    coveted_require_csrf();

    $provider = trim((string)($_POST['provider'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    if ($message === '') {
        throw new InvalidArgumentException('Enter a message for the Admin Agent.');
    }

    $historyRaw = trim((string)($_POST['history_json'] ?? '[]'));
    if (strlen($historyRaw) > 120000) {
        throw new InvalidArgumentException('This chat is too long. Start a new chat and try again.');
    }

    try {
        $history = json_decode($historyRaw, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new InvalidArgumentException('The chat history is invalid.', 0, $e);
    }
    if (!is_array($history)) {
        $history = [];
    }

    $messages = [];
    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $role = strtolower(trim((string)($entry['role'] ?? '')));
        $content = trim((string)($entry['content'] ?? ''));
        if (in_array($role, ['user', 'assistant'], true) && $content !== '') {
            $messages[] = ['role' => $role, 'content' => $content];
        }
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    $result = coveted_ai_chat($admin, $provider, $messages);
    echo coveted_json([
        'ok' => true,
        'provider' => $result['provider'],
        'model' => $result['model'],
        'text' => $result['text'],
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo coveted_json(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Admin Agent chat failed: ' . $e->getMessage());
    http_response_code(502);
    echo coveted_json([
        'ok' => false,
        'error' => preg_match('/^(AI provider|The PHP cURL|Unable to decrypt|Configure app\.ai_credentials_key)/', $e->getMessage()) === 1
            ? $e->getMessage()
            : 'The Admin Agent could not complete that request. Check the selected provider and try again.',
    ]);
}
