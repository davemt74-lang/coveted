<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_agent_brain.php';
require_once dirname(__DIR__) . '/app/admin_agent_actions.php';
require_once dirname(__DIR__) . '/app/admin_agent_threads.php';
require_once dirname(__DIR__) . '/app/site_branding.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/** @return array<int,array<string,mixed>> */
function coveted_admin_agent_persisted_action_results(array $rows): array
{
    $actions = [];
    foreach ($rows as $row) {
        if (($row['role'] ?? '') !== 'action') {
            continue;
        }
        $metadata = [];
        try {
            $decoded = json_decode((string)($row['metadata_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        } catch (Throwable) {
            $metadata = [];
        }
        $actions[] = [
            'action' => (string)($metadata['action'] ?? ''),
            'label' => (string)($metadata['label'] ?? 'Admin action'),
            'ok' => !empty($metadata['ok']),
            'message' => (string)($row['content'] ?? 'Action processed.'),
            'entity_ref' => (string)($metadata['entity_ref'] ?? ''),
        ];
    }
    return $actions;
}

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
    if ($message === '' || mb_strlen($message) > 12000) {
        throw new InvalidArgumentException('Enter a message for the Admin Agent.');
    }
    $requestId = coveted_admin_agent_request_id((string)($_POST['request_id'] ?? ''));

    $pdo = coveted_db();
    coveted_admin_agent_threads_ensure_schema($pdo);

    $threadRef = trim((string)($_POST['thread_ref'] ?? ''));
    if ($threadRef === '') {
        $thread = coveted_admin_agent_thread_create($admin, 'New Chat', $pdo);
        $threadRef = (string)$thread['public_id'];
    } else {
        $threadRef = coveted_admin_agent_thread_ref($threadRef);
        $thread = coveted_admin_agent_thread_by_ref($admin, $threadRef, $pdo);
        if (!$thread || $thread['status'] !== 'active') {
            throw new InvalidArgumentException('Admin Agent chat not found or archived.');
        }
    }

    // Request IDs make browser/network retries safe. If the same request was
    // already completed, return its persisted result without another provider
    // call. If actions completed before a transport interruption, do not replay
    // those mutations on retry.
    $requestRows = coveted_admin_agent_thread_request_messages($admin, $threadRef, $requestId, $pdo);
    $storedUserContent = null;
    foreach ($requestRows as $row) {
        if (($row['role'] ?? '') === 'user') {
            $storedUserContent = (string)$row['content'];
            break;
        }
    }
    if ($storedUserContent !== null && !hash_equals($storedUserContent, $message)) {
        throw new InvalidArgumentException('That Admin Agent request identifier was already used for a different message.');
    }

    $completed = coveted_admin_agent_thread_completed_request($admin, $threadRef, $requestId, $pdo);
    if ($completed !== null) {
        $thread = coveted_admin_agent_thread_by_ref($admin, $threadRef, $pdo) ?? $thread;
        $brain = coveted_site_branding_enrich_agent_snapshot(coveted_admin_agent_snapshot($admin));
        echo coveted_json([
            'ok' => true,
            'provider' => (string)$completed['provider'],
            'model' => (string)$completed['model'],
            'text' => (string)$completed['text'],
            'autonomous_actions' => coveted_admin_agent_autonomous_actions_enabled($pdo),
            'actions' => (array)$completed['actions'],
            'thread' => ['public_id' => $threadRef, 'title' => (string)$thread['title']],
            'replayed' => true,
            'readiness' => (int)($brain['readiness']['percent'] ?? 0),
            'opportunity_count' => count((array)($brain['opportunities'] ?? [])),
        ]);
        exit;
    }

    $persistedActions = coveted_admin_agent_persisted_action_results($requestRows);
    if ($persistedActions) {
        $thread = coveted_admin_agent_thread_by_ref($admin, $threadRef, $pdo) ?? $thread;
        $brain = coveted_site_branding_enrich_agent_snapshot(coveted_admin_agent_snapshot($admin));
        echo coveted_json([
            'ok' => true,
            'provider' => '',
            'model' => '',
            'text' => 'This request already executed or attempted Admin actions before the previous response was interrupted. I did not repeat them. Review the persisted action results and send a new message if you want me to continue.',
            'autonomous_actions' => coveted_admin_agent_autonomous_actions_enabled($pdo),
            'actions' => $persistedActions,
            'thread' => ['public_id' => $threadRef, 'title' => (string)$thread['title']],
            'replayed' => true,
            'readiness' => (int)($brain['readiness']['percent'] ?? 0),
            'opportunity_count' => count((array)($brain['opportunities'] ?? [])),
        ]);
        exit;
    }

    if ($storedUserContent === null) {
        coveted_admin_agent_thread_append_message(
            $admin,
            $threadRef,
            'user',
            $message,
            $requestId,
            null,
            null,
            [],
            $pdo
        );
    }

    // Server history is authoritative. Browser-supplied history is not used to
    // construct provider context, which prevents cross-thread/client tampering
    // and makes the same conversation resumable from another device.
    $dialogue = coveted_admin_agent_thread_chat_history($admin, $threadRef, 20, $pdo);

    $autonomous = coveted_admin_agent_autonomous_actions_enabled($pdo);
    $maxRounds = $autonomous ? 3 : 1;
    $maxActions = 8;

    // Reserve worst-case provider-call cost only after replay/idempotency checks.
    $now = time();
    $recent = array_values(array_filter(
        (array)($_SESSION['admin_ai_chat_timestamps'] ?? []),
        static fn(mixed $timestamp): bool => is_int($timestamp) && $timestamp >= $now - 300
    ));
    if (count($recent) + $maxRounds > 30) {
        http_response_code(429);
        echo coveted_json(['ok' => false, 'error' => 'Too many Admin Agent requests. Wait a few minutes and try again.']);
        exit;
    }
    for ($reservation = 0; $reservation < $maxRounds; $reservation++) {
        $recent[] = $now;
    }
    $_SESSION['admin_ai_chat_timestamps'] = $recent;

    $executedActions = [];
    $visibleChunks = [];
    $providerResult = null;
    $totalActionCount = 0;
    $brain = [];

    for ($round = 0; $round < $maxRounds; $round++) {
        $brain = coveted_site_branding_enrich_agent_snapshot(coveted_admin_agent_snapshot($admin));
        $callMessages = [
            ['role' => 'user', 'content' => coveted_admin_agent_context_message($brain)],
            ['role' => 'user', 'content' => coveted_admin_agent_action_protocol_message($autonomous)],
            ...array_slice($dialogue, -20),
        ];

        $providerResult = coveted_ai_chat($admin, $provider, $callMessages);
        $rawText = (string)$providerResult['text'];
        $visibleText = coveted_admin_agent_strip_action_requests($rawText);
        if ($visibleText !== '') {
            $visibleChunks[] = $visibleText;
        }

        try {
            $requests = coveted_admin_agent_extract_action_requests($rawText);
        } catch (InvalidArgumentException $e) {
            $actionResult = [
                'action' => 'action_protocol',
                'label' => 'Action request rejected',
                'ok' => false,
                'message' => $e->getMessage(),
                'entity_ref' => '',
            ];
            $executedActions[] = $actionResult;
            coveted_admin_agent_thread_append_message(
                $admin,
                $threadRef,
                'action',
                $actionResult['message'],
                $requestId,
                null,
                null,
                $actionResult,
                $pdo
            );
            break;
        }

        if (!$autonomous || !$requests) {
            break;
        }

        $roundResults = [];
        foreach ($requests as $request) {
            if ($totalActionCount >= $maxActions) {
                $actionResult = [
                    'action' => 'action_limit',
                    'label' => 'Autonomous action limit',
                    'ok' => false,
                    'message' => 'The bounded autonomous action limit was reached for this request.',
                    'entity_ref' => '',
                ];
                $roundResults[] = $actionResult;
                $executedActions[] = $actionResult;
                coveted_admin_agent_thread_append_message(
                    $admin, $threadRef, 'action', $actionResult['message'], $requestId,
                    null, null, $actionResult, $pdo
                );
                break;
            }

            $totalActionCount++;
            try {
                $actionResult = coveted_admin_agent_execute_action($admin, $request);
            } catch (Throwable $e) {
                $definition = coveted_admin_agent_action_registry()[$request['action']] ?? [];
                $actionResult = [
                    'action' => (string)$request['action'],
                    'label' => (string)($definition['label'] ?? $request['action']),
                    'ok' => false,
                    'message' => mb_substr($e->getMessage(), 0, 500),
                    'entity_ref' => '',
                ];
            }
            $roundResults[] = $actionResult;
            $executedActions[] = $actionResult;
            coveted_admin_agent_thread_append_message(
                $admin,
                $threadRef,
                'action',
                (string)$actionResult['message'],
                $requestId,
                null,
                null,
                $actionResult,
                $pdo
            );
        }

        if (!$roundResults) {
            break;
        }

        $feedback = array_map(
            static fn(array $result): array => [
                'action' => (string)$result['action'],
                'ok' => !empty($result['ok']),
                'message' => (string)$result['message'],
                'entity_ref' => (string)($result['entity_ref'] ?? ''),
            ],
            $roundResults
        );

        $dialogue[] = [
            'role' => 'assistant',
            'content' => $visibleText !== '' ? $visibleText : 'I am executing the requested Coveted Admin actions.',
        ];
        $dialogue[] = [
            'role' => 'user',
            'content' => "TRUSTED COVETED SERVER ACTION RESULTS:\n"
                . coveted_json($feedback)
                . "\nContinue the System Admin's goal using these real results. You may issue another allowlisted action only if it is necessary. Do not repeat a successful action.",
        ];
    }

    if ($providerResult === null) {
        throw new RuntimeException('The Admin Agent did not return a provider response.');
    }

    if (!$visibleChunks) {
        $visibleChunks[] = $executedActions
            ? 'The requested Coveted Admin actions were processed.'
            : 'The Admin Agent completed the request.';
    }
    $finalText = trim(implode("\n\n", $visibleChunks));

    coveted_admin_agent_thread_append_message(
        $admin,
        $threadRef,
        'assistant',
        $finalText,
        $requestId,
        (string)$providerResult['provider'],
        (string)$providerResult['model'],
        [],
        $pdo
    );

    $thread = coveted_admin_agent_thread_by_ref($admin, $threadRef, $pdo) ?? $thread;
    $brain = coveted_site_branding_enrich_agent_snapshot(coveted_admin_agent_snapshot($admin));

    echo coveted_json([
        'ok' => true,
        'provider' => $providerResult['provider'],
        'model' => $providerResult['model'],
        'text' => $finalText,
        'autonomous_actions' => $autonomous,
        'actions' => $executedActions,
        'thread' => ['public_id' => $threadRef, 'title' => (string)$thread['title']],
        'replayed' => false,
        'readiness' => (int)($brain['readiness']['percent'] ?? 0),
        'opportunity_count' => count((array)($brain['opportunities'] ?? [])),
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
