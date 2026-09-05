<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_agent_brain.php';
require_once dirname(__DIR__) . '/app/admin_agent_actions.php';
require_once dirname(__DIR__) . '/app/site_branding.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $admin = coveted_require_system_admin();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo coveted_json(['ok' => false, 'error' => 'POST required.']);
        exit;
    }

    coveted_require_csrf();

    $now = time();
    $recent = array_values(array_filter(
        (array)($_SESSION['admin_ai_chat_timestamps'] ?? []),
        static fn(mixed $timestamp): bool => is_int($timestamp) && $timestamp >= $now - 300
    ));
    if (count($recent) >= 30) {
        http_response_code(429);
        echo coveted_json(['ok' => false, 'error' => 'Too many Admin Agent requests. Wait a few minutes and try again.']);
        exit;
    }
    $recent[] = $now;
    $_SESSION['admin_ai_chat_timestamps'] = $recent;

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

    $dialogue = [];
    foreach ($history as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $role = strtolower(trim((string)($entry['role'] ?? '')));
        $content = trim((string)($entry['content'] ?? ''));
        if (in_array($role, ['user', 'assistant'], true) && $content !== '') {
            $dialogue[] = ['role' => $role, 'content' => $content];
        }
    }
    $dialogue = array_slice($dialogue, -20);
    $dialogue[] = ['role' => 'user', 'content' => $message];

    $autonomous = coveted_admin_agent_autonomous_actions_enabled(coveted_db());
    $executedActions = [];
    $visibleChunks = [];
    $providerResult = null;
    $totalActionCount = 0;
    $maxRounds = $autonomous ? 3 : 1;
    $maxActions = 8;
    $brain = [];

    for ($round = 0; $round < $maxRounds; $round++) {
        // Refresh canonical state on every reasoning/action round. The context
        // is rebuilt for each provider call so it can never be pushed out by
        // long client history or action-result messages.
        $brain = coveted_site_branding_enrich_agent_snapshot(coveted_admin_agent_snapshot($admin));
        $callMessages = [
            [
                'role' => 'user',
                'content' => coveted_admin_agent_context_message($brain),
            ],
            [
                'role' => 'user',
                'content' => coveted_admin_agent_action_protocol_message($autonomous),
            ],
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
            $executedActions[] = [
                'action' => 'action_protocol',
                'label' => 'Action request rejected',
                'ok' => false,
                'message' => $e->getMessage(),
                'entity_ref' => '',
            ];
            break;
        }

        if (!$autonomous || !$requests) {
            break;
        }

        $roundResults = [];
        foreach ($requests as $request) {
            if ($totalActionCount >= $maxActions) {
                $roundResults[] = [
                    'action' => 'action_limit',
                    'label' => 'Autonomous action limit',
                    'ok' => false,
                    'message' => 'The bounded autonomous action limit was reached for this request.',
                    'entity_ref' => '',
                ];
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

    // Re-read state once more so readiness/opportunity counts returned to the
    // browser reflect any actions that completed during this same request.
    $brain = coveted_site_branding_enrich_agent_snapshot(coveted_admin_agent_snapshot($admin));

    echo coveted_json([
        'ok' => true,
        'provider' => $providerResult['provider'],
        'model' => $providerResult['model'],
        'text' => trim(implode("\n\n", $visibleChunks)),
        'autonomous_actions' => $autonomous,
        'actions' => $executedActions,
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
