<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/account_agent_context.php';
require_once dirname(__DIR__) . '/app/member_onboarding_agent.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$activeUser = null;
$activeThreadRef = '';
$activeRequestId = '';
$activePdo = null;
$userTurnPersisted = false;

try {
    $user = coveted_require_user();
    $activeUser = $user;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo coveted_json(['ok'=>false,'error'=>'POST required.']);
        exit;
    }
    coveted_require_csrf();

    $message = trim((string)($_POST['message'] ?? ''));
    if ($message === '' || mb_strlen($message) > 12000) {
        throw new InvalidArgumentException('Enter a message for the Coveted Agent.');
    }
    $requestId = coveted_account_agent_request_id((string)($_POST['request_id'] ?? ''));
    $activeRequestId = $requestId;
    $surface = coveted_account_agent_surface((string)($_POST['surface'] ?? '/'));

    $pdo = coveted_db();
    $activePdo = $pdo;
    if (!coveted_account_agent_storage_available($pdo)) {
        throw new RuntimeException('Persistent Agent chat storage is unavailable.');
    }

    $availableProviders = coveted_account_agent_providers($pdo);
    if (!$availableProviders) {
        throw new InvalidArgumentException('Agent chat is not configured yet.');
    }
    $provider = strtolower(trim((string)($_POST['provider'] ?? '')));
    if ($provider === '') {
        $provider = (string)$availableProviders[0]['provider'];
    }
    $availableKeys = array_column($availableProviders, 'provider');
    if (!in_array($provider, $availableKeys, true)) {
        throw new InvalidArgumentException('Choose an available chat provider.');
    }

    $now = time();
    $recent = array_values(array_filter(
        (array)($_SESSION['account_agent_chat_timestamps'] ?? []),
        static fn(mixed $ts): bool => is_int($ts) && $ts >= $now - 300
    ));
    if (count($recent) >= 20) {
        http_response_code(429);
        echo coveted_json(['ok'=>false,'error'=>'Too many Agent requests. Wait a few minutes and try again.']);
        exit;
    }
    $recent[] = $now;
    $_SESSION['account_agent_chat_timestamps'] = $recent;

    $threadRef = trim((string)($_POST['thread_ref'] ?? ''));
    $requestThreads = (array)($_SESSION['account_agent_request_threads'] ?? []);
    if ($threadRef === '' && isset($requestThreads[$requestId])) {
        $threadRef = (string)$requestThreads[$requestId];
    }
    if ($threadRef === '') {
        $thread = coveted_account_agent_thread_create($user, 'New Chat', $pdo);
        $threadRef = (string)$thread['public_id'];
        $requestThreads[$requestId] = $threadRef;
        if (count($requestThreads) > 50) {
            $requestThreads = array_slice($requestThreads, -50, null, true);
        }
        $_SESSION['account_agent_request_threads'] = $requestThreads;
    } else {
        $threadRef = coveted_account_agent_thread_ref($threadRef);
    }
    $activeThreadRef = $threadRef;

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare(
            "SELECT id,public_id,title,status FROM admin_agent_threads
             WHERE public_id=? AND owner_user_id=? LIMIT 1 FOR UPDATE"
        );
        $lock->execute([$threadRef,(int)$user['id']]);
        $thread = $lock->fetch();
        if (!$thread || $thread['status'] !== 'active') {
            throw new InvalidArgumentException('Agent chat not found or archived.');
        }

        $request = $pdo->prepare(
            "SELECT role,content,provider,model FROM admin_agent_messages
             WHERE thread_id=? AND request_id=? AND role IN ('user','assistant') ORDER BY id ASC"
        );
        $request->execute([(int)$thread['id'],$requestId]);
        $storedUser = null;
        $storedAssistant = null;
        foreach ($request->fetchAll() as $row) {
            if ($row['role'] === 'user' && $storedUser === null) $storedUser = $row;
            if ($row['role'] === 'assistant') $storedAssistant = $row;
        }
        if ($storedUser !== null && !hash_equals((string)$storedUser['content'], $message)) {
            throw new InvalidArgumentException('That Agent request identifier was already used for a different message.');
        }
        if ($storedAssistant !== null) {
            $pdo->commit();
            echo coveted_json([
                'ok'=>true,
                'text'=>(string)$storedAssistant['content'],
                'provider'=>(string)($storedAssistant['provider'] ?? ''),
                'model'=>(string)($storedAssistant['model'] ?? ''),
                'thread'=>['public_id'=>$threadRef,'title'=>(string)$thread['title']],
                'replayed'=>true,
            ]);
            exit;
        }
        if ($storedUser !== null) {
            $pdo->commit();
            http_response_code(409);
            echo coveted_json([
                'ok'=>false,
                'error'=>'That Agent request is already processing. It will not be executed twice.',
                'thread'=>['public_id'=>$threadRef,'title'=>(string)$thread['title']],
            ]);
            exit;
        }

        coveted_account_agent_append_message($user, $threadRef, 'user', $message, $requestId, null, null, ['surface'=>$surface], $pdo);
        $userTurnPersisted = true;
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Release the PHP session lock before the external provider call. A concurrent
    // replay now sees the durable user turn above and returns 409 instead of running twice.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $dialogue = coveted_account_agent_chat_history($user, $threadRef, 20, $pdo);
    $snapshot = coveted_account_agent_context_snapshot($user, $surface, $pdo);
    if (!empty($snapshot['capabilities']['member']) && empty($snapshot['capabilities']['system_admin'])) {
        $snapshot['onboarding'] = coveted_member_onboarding_agent_snapshot($user, $pdo);
    }
    $providerResult = coveted_account_agent_chat($user, $provider, $dialogue, $snapshot, $pdo);
    coveted_account_agent_append_message(
        $user,
        $threadRef,
        'assistant',
        (string)$providerResult['text'],
        $requestId,
        (string)$providerResult['provider'],
        (string)$providerResult['model'],
        [
            'surface'=>$surface,
            'role'=>(string)$snapshot['role'],
            'onboarding_stage'=>(string)($snapshot['onboarding']['stage'] ?? ''),
        ],
        $pdo
    );

    $thread = coveted_account_agent_thread_by_ref($user, $threadRef, $pdo);
    echo coveted_json([
        'ok'=>true,
        'text'=>(string)$providerResult['text'],
        'provider'=>(string)$providerResult['provider'],
        'model'=>(string)$providerResult['model'],
        'thread'=>['public_id'=>$threadRef,'title'=>(string)($thread['title'] ?? 'Chat')],
        'replayed'=>false,
        'authority'=>'Read/advise only. Canonical Coveted controls retain action authority.',
    ]);
} catch (Throwable $e) {
    error_log('Account Agent chat failed: ' . $e->getMessage());

    if ($userTurnPersisted && $activeUser && $activeThreadRef !== '' && $activeRequestId !== '' && $activePdo instanceof PDO) {
        try {
            $existing = coveted_account_agent_request_response($activeUser, $activeThreadRef, $activeRequestId, $activePdo);
            if ($existing === null) {
                coveted_account_agent_append_message(
                    $activeUser,
                    $activeThreadRef,
                    'assistant',
                    'I could not complete that request. Please send it again as a new message.',
                    $activeRequestId,
                    null,
                    null,
                    ['error'=>'provider_or_context_unavailable'],
                    $activePdo
                );
            }
        } catch (Throwable $persistError) {
            error_log('Account Agent failure response could not be persisted: ' . $persistError->getMessage());
        }
    }

    $status = $e instanceof InvalidArgumentException ? 400 : 503;
    http_response_code($status);
    echo coveted_json([
        'ok'=>false,
        'error'=>$e instanceof InvalidArgumentException ? $e->getMessage() : 'The Coveted Agent could not complete that request.',
        'thread'=>$activeThreadRef !== '' ? ['public_id'=>$activeThreadRef] : null,
    ]);
}