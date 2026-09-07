<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/account_agent_context.php';
require_once dirname(__DIR__) . '/app/member_onboarding_agent.php';
require_once dirname(__DIR__) . '/app/member_concierge.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $user = coveted_require_user();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        echo coveted_json(['ok'=>false,'error'=>'GET required.']);
        exit;
    }

    $pdo = coveted_db();
    $storageReady = coveted_account_agent_storage_available($pdo);
    $providers = coveted_account_agent_providers($pdo);
    $role = coveted_account_agent_role_label($user, $pdo);
    $caps = coveted_account_agent_capabilities($user, $pdo);
    $recent = $storageReady ? coveted_account_agent_recent_threads($user, 8, $pdo) : [];

    $requestedRef = trim((string)($_GET['thread_ref'] ?? ''));
    $thread = null;
    if ($storageReady && $requestedRef !== '') {
        try {
            $thread = coveted_account_agent_thread_by_ref($user, $requestedRef, $pdo);
        } catch (InvalidArgumentException) {
            $thread = null;
        }
    }
    if ($storageReady && !$thread && $recent) {
        $thread = coveted_account_agent_thread_by_ref($user, (string)$recent[0]['public_id'], $pdo);
    }

    $messages = [];
    if ($thread) {
        foreach (coveted_account_agent_thread_messages($user, (string)$thread['public_id'], 120, $pdo) as $row) {
            $messages[] = [
                'id'=>(int)$row['id'],
                'role'=>(string)$row['role'],
                'content'=>(string)$row['content'],
                'created_at'=>(string)$row['created_at'],
            ];
        }
    }

    $snapshot = coveted_account_agent_context_snapshot($user, '/', $pdo);
    $member = (array)($snapshot['member'] ?? []);
    $onboarding = null;
    $concierge = null;
    $welcome = [
        'title'=>'How can I help?',
        'body'=>'Ask about your Coveted events, invitations, benefits, hosting responsibilities, or account context.',
    ];

    if (!empty($caps['member']) && empty($caps['system_admin'])) {
        $onboarding = coveted_member_onboarding_agent_snapshot($user, $pdo);
        $concierge = coveted_member_concierge_snapshot($user, $pdo);
        $attentionCount = count((array)($concierge['attention'] ?? []));
        $welcome = [
            'title'=>'Your Coveted Concierge',
            'body'=>$attentionCount > 0
                ? $attentionCount . ' item' . ($attentionCount === 1 ? '' : 's') . ' may need your attention. I can also help with Events, benefits, RSVPs and what to do next.'
                : (string)($onboarding['headline'] ?? 'I can help you decide what to attend, use and do next in Coveted.'),
        ];
    }

    $starters = [];
    if ($concierge !== null) {
        foreach ((array)($concierge['starter_prompts'] ?? []) as $prompt) {
            $prompt = trim((string)$prompt);
            if ($prompt !== '') $starters[] = $prompt;
        }
    }
    if ($onboarding !== null) {
        foreach ((array)($onboarding['starter_prompts'] ?? []) as $prompt) {
            $prompt = trim((string)$prompt);
            if ($prompt !== '') $starters[] = $prompt;
        }
    }
    if ((int)($member['pending_invitations'] ?? 0) > 0) {
        $starters[] = 'What invitations need my attention?';
    }
    if ((int)($member['future_attending'] ?? 0) > 0) {
        $starters[] = 'What should I know about my next event?';
    }
    if ((int)($member['active_benefits'] ?? 0) > 0) {
        $starters[] = 'What benefits or perks can I use?';
    }
    if (!empty($caps['attendee_host'])) {
        $starters[] = 'What needs attention for the events I am hosting?';
    }
    if (!empty($caps['business_host'])) {
        $starters[] = 'What should I know about my business event activity?';
    }
    if (!empty($caps['system_admin'])) {
        $starters[] = 'Summarize the current Coveted platform state.';
    }
    if (!$starters) {
        $starters[] = 'What can you help me with in Coveted?';
    }

    echo coveted_json([
        'ok'=>true,
        'storage_ready'=>$storageReady,
        'storage_error'=>$storageReady ? '' : 'Persistent chat storage is unavailable. Import database/migrations/20260905_admin_agent_threads.sql.',
        'user_ref'=>(string)$user['public_id'],
        'role'=>$role,
        'capabilities'=>$caps,
        'is_system_admin'=>!empty($caps['system_admin']),
        'providers'=>$providers,
        'csrf'=>coveted_csrf_token(),
        'thread'=>$thread ? [
            'public_id'=>(string)$thread['public_id'],
            'title'=>(string)$thread['title'],
        ] : null,
        'messages'=>$messages,
        'recent_threads'=>array_map(static fn(array $row): array => [
            'public_id'=>(string)$row['public_id'],
            'title'=>(string)$row['title'],
            'message_count'=>(int)$row['message_count'],
            'last_message_at'=>(string)($row['last_message_at'] ?? ''),
        ], $recent),
        'starters'=>array_slice(array_values(array_unique($starters)), 0, 5),
        'welcome'=>$welcome,
        'onboarding'=>$onboarding,
        'concierge'=>$concierge,
        'authority'=>'Chat does not elevate account permissions. The model remains read/advise only. Member RSVP mutations require an explicit server-owned confirmation and execute through canonical Coveted Event services.',
    ]);
} catch (Throwable $e) {
    error_log('Account Agent bootstrap failed: ' . $e->getMessage());
    http_response_code($e instanceof InvalidArgumentException ? 400 : 500);
    echo coveted_json(['ok'=>false,'error'=>'The Coveted Agent is temporarily unavailable.']);
}
