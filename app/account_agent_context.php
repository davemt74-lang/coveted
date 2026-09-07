<?php
declare(strict_types=1);

require_once __DIR__ . '/account_agent_threads.php';
require_once __DIR__ . '/ai_providers.php';

function coveted_account_agent_surface(string $surface): string
{
    $surface = trim($surface);
    if ($surface === '') {
        return '/';
    }
    if (strlen($surface) > 500 || preg_match('/[\x00-\x1F\x7F]/', $surface) === 1) {
        return '/';
    }
    $parts = parse_url($surface);
    $path = is_array($parts) ? (string)($parts['path'] ?? '/') : '/';
    if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return '/';
    }
    $query = is_array($parts) ? trim((string)($parts['query'] ?? '')) : '';
    if ($query !== '' && strlen($query) <= 300) {
        return $path . '?' . $query;
    }
    return $path;
}

/** @return array<string,bool> */
function coveted_account_agent_capabilities(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $roles = array_map('strval', (array)($user['roles'] ?? []));
    $businessHost = false;
    $eventHost = in_array('attendee_host', $roles, true);

    try {
        $stmt = $pdo->prepare('SELECT 1 FROM business_admins WHERE user_id=? LIMIT 1');
        $stmt->execute([(int)$user['id']]);
        $businessHost = (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        $businessHost = false;
    }

    try {
        $stmt = $pdo->prepare('SELECT 1 FROM event_hosts WHERE user_id=? LIMIT 1');
        $stmt->execute([(int)$user['id']]);
        $eventHost = $eventHost || (bool)$stmt->fetchColumn();
    } catch (Throwable) {
        // Role membership remains authoritative when event assignment storage is unavailable.
    }

    return [
        'system_admin'=>coveted_is_system_admin($user),
        'member'=>in_array('attendee', $roles, true) || !coveted_is_system_admin($user),
        'attendee_host'=>$eventHost,
        'business_host'=>$businessHost,
    ];
}

function coveted_account_agent_role_label(array $user, ?PDO $pdo = null): string
{
    $caps = coveted_account_agent_capabilities($user, $pdo);
    if ($caps['system_admin']) return 'System Admin';
    if ($caps['business_host'] && $caps['attendee_host']) return 'Member + Host';
    if ($caps['business_host']) return 'Business / Location Host';
    if ($caps['attendee_host']) return 'Attendee Host';
    return 'Member';
}

/** @return array<string,mixed> */
function coveted_account_agent_member_context(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $userId = (int)$user['id'];
    $result = [
        'display_name'=>(string)($user['display_name'] ?? 'Member'),
        'pending_invitations'=>0,
        'future_attending'=>0,
        'active_groups'=>[],
        'active_benefits'=>0,
    ];

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM event_invitations ei
             JOIN events e ON e.id=ei.event_id
             WHERE ei.user_id=? AND ei.status='pending' AND e.status='published' AND e.starts_at>UTC_TIMESTAMP()"
        );
        $stmt->execute([$userId]);
        $result['pending_invitations'] = (int)$stmt->fetchColumn();
    } catch (Throwable) {}

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM event_rsvps er
             JOIN events e ON e.id=er.event_id
             WHERE er.user_id=? AND er.response='attending' AND e.status IN ('published','closed') AND e.starts_at>=UTC_TIMESTAMP()"
        );
        $stmt->execute([$userId]);
        $result['future_attending'] = (int)$stmt->fetchColumn();
    } catch (Throwable) {}

    try {
        $stmt = $pdo->prepare(
            "SELECT g.name FROM group_memberships gm
             JOIN social_groups g ON g.id=gm.group_id
             WHERE gm.user_id=? AND gm.membership_status='active' AND g.status='active'
             ORDER BY g.name ASC LIMIT 6"
        );
        $stmt->execute([$userId]);
        $result['active_groups'] = array_values(array_map('strval', array_column($stmt->fetchAll(), 'name')));
    } catch (Throwable) {}

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM reward_issuances
             WHERE user_id=? AND status NOT IN ('cancelled','expired') AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP())"
        );
        $stmt->execute([$userId]);
        $result['active_benefits'] = (int)$stmt->fetchColumn();
    } catch (Throwable) {}

    return $result;
}

/** @return array<int,array<string,mixed>> */
function coveted_account_agent_host_events(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    try {
        $stmt = $pdo->prepare(
            "SELECT e.public_id,e.title,e.status,e.starts_at,eh.host_role,g.name AS group_name
             FROM event_hosts eh
             JOIN events e ON e.id=eh.event_id
             JOIN social_groups g ON g.id=e.group_id
             WHERE eh.user_id=? AND e.status IN ('published','closed')
             ORDER BY e.starts_at ASC LIMIT 8"
        );
        $stmt->execute([(int)$user['id']]);
        return array_map(static fn(array $row): array => [
            'event_ref'=>(string)$row['public_id'],
            'title'=>(string)$row['title'],
            'status'=>(string)$row['status'],
            'starts_at'=>(string)$row['starts_at'],
            'host_role'=>(string)$row['host_role'],
            'group'=>(string)$row['group_name'],
        ], $stmt->fetchAll());
    } catch (Throwable) {
        return [];
    }
}

/** @return array<int,array<string,mixed>> */
function coveted_account_agent_business_context(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    try {
        $stmt = $pdo->prepare(
            "SELECT b.public_id,b.name,
                    (SELECT COUNT(DISTINCT e.id)
                     FROM locations l
                     JOIN event_locations el ON el.location_id=l.id
                     JOIN events e ON e.id=el.event_id
                     WHERE l.business_id=b.id AND e.status IN ('published','closed')) AS active_event_count
             FROM business_admins ba
             JOIN businesses b ON b.id=ba.business_id
             WHERE ba.user_id=? AND b.status<>'archived'
             ORDER BY b.name ASC LIMIT 8"
        );
        $stmt->execute([(int)$user['id']]);
        return array_map(static fn(array $row): array => [
            'business_ref'=>(string)$row['public_id'],
            'name'=>(string)$row['name'],
            'active_event_count'=>(int)$row['active_event_count'],
        ], $stmt->fetchAll());
    } catch (Throwable) {
        return [];
    }
}

/** @return array<string,int> */
function coveted_account_agent_admin_counts(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $counts = ['users'=>0,'groups'=>0,'events'=>0,'businesses'=>0];
    foreach (['users'=>'users','groups'=>'social_groups','events'=>'events','businesses'=>'businesses'] as $key=>$table) {
        try {
            $counts[$key] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        } catch (Throwable) {}
    }
    return $counts;
}

/** @return array<string,mixed> */
function coveted_account_agent_context_snapshot(array $user, string $surface = '/', ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $caps = coveted_account_agent_capabilities($user, $pdo);
    $snapshot = [
        'surface'=>coveted_account_agent_surface($surface),
        'role'=>coveted_account_agent_role_label($user, $pdo),
        'capabilities'=>$caps,
        'member'=>coveted_account_agent_member_context($user, $pdo),
        'authority'=>'This shared Agent may advise using the authenticated account context but cannot execute mutations. Use canonical Coveted controls and explicit approvals for actions.',
        'privacy'=>'Only the authenticated account own context and role-permitted aggregate operational context are included. Admin-only member intelligence, private relationship timelines, contact details, and other members private data are excluded.',
    ];
    if ($caps['attendee_host']) {
        $snapshot['host_events'] = coveted_account_agent_host_events($user, $pdo);
        $snapshot['host_authority'] = 'Attendee Hosts assist with assigned Event operations only. They do not create or configure Events.';
    }
    if ($caps['business_host']) {
        $snapshot['businesses'] = coveted_account_agent_business_context($user, $pdo);
        $snapshot['business_authority'] = 'Business / Location Hosts receive only their own business and aggregate Event-operational context.';
    }
    if ($caps['system_admin']) {
        $snapshot['admin_counts'] = coveted_account_agent_admin_counts($pdo);
        $snapshot['admin_authority'] = 'This persistent shell is read/advise only even for System Admin. Use the dedicated Admin Agent for approved or autonomous Admin mutations.';
    }
    return $snapshot;
}

function coveted_account_agent_context_message(array $snapshot): string
{
    return "TRUSTED COVETED ACCOUNT CONTEXT:\n" . coveted_json($snapshot)
        . "\nUse only this server-provided context for account-specific claims. Do not infer unavailable private data.";
}

/** @return array<int,array{provider:string,label:string,model:string}> */
function coveted_account_agent_providers(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    try {
        $stmt = $pdo->query(
            "SELECT provider,model FROM ai_provider_settings
             WHERE provider IN ('openai','anthropic') AND enabled=1 AND secret_ciphertext IS NOT NULL AND secret_ciphertext<>''
             ORDER BY FIELD(provider,'openai','anthropic')"
        );
        $definitions = coveted_ai_provider_definitions();
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $provider = (string)$row['provider'];
            $model = trim((string)$row['model']);
            if (!isset($definitions[$provider]) || $model === '') continue;
            $result[] = [
                'provider'=>$provider,
                'label'=>(string)$definitions[$provider]['chat_label'],
                'model'=>$model,
            ];
        }
        return $result;
    } catch (Throwable) {
        return [];
    }
}

function coveted_account_agent_provider_secret(string $provider, ?PDO $pdo = null): string
{
    $provider = strtolower(trim($provider));
    if (!in_array($provider, ['openai','anthropic'], true)) {
        throw new InvalidArgumentException('Choose an available chat provider.');
    }
    $pdo ??= coveted_db();
    $stmt = $pdo->prepare(
        "SELECT secret_ciphertext FROM ai_provider_settings
         WHERE provider=? AND enabled=1 AND secret_ciphertext IS NOT NULL AND secret_ciphertext<>'' LIMIT 1"
    );
    $stmt->execute([$provider]);
    $ciphertext = (string)($stmt->fetchColumn() ?: '');
    if ($ciphertext === '') {
        throw new InvalidArgumentException('That chat provider is not available.');
    }
    return coveted_ai_decrypt_secret($ciphertext);
}

/** @param array<int,array{role:string,content:string}> $messages @return array{provider:string,model:string,text:string} */
function coveted_account_agent_chat(array $user, string $provider, array $messages, array $snapshot, ?PDO $pdo = null): array
{
    $provider = strtolower(trim($provider));
    if (!in_array($provider, ['openai','anthropic'], true)) {
        throw new InvalidArgumentException('Choose an available chat provider.');
    }
    $messages = coveted_ai_validate_chat_messages($messages);
    $pdo ??= coveted_db();
    $available = [];
    foreach (coveted_account_agent_providers($pdo) as $item) $available[(string)$item['provider']] = $item;
    if (!isset($available[$provider])) {
        throw new InvalidArgumentException('That chat provider is not available.');
    }
    $model = (string)$available[$provider]['model'];
    $secret = coveted_account_agent_provider_secret($provider, $pdo);
    $role = (string)($snapshot['role'] ?? 'Member');
    $instructions = 'You are the private Coveted Agent for the currently authenticated account. The account role is ' . $role . '. '
        . 'Answer concisely and practically from the trusted Coveted account context supplied in the conversation. '
        . 'Never expose or infer Admin-only member intelligence, other members private data, contact details, private relationship timelines, or personality traits. '
        . 'This shared chat shell is read-and-advise only: do not claim that you sent invitations, changed RSVPs, issued rewards, changed memberships, configured Events, or performed any mutation. '
        . 'When an action is appropriate, direct the user to the existing canonical Coveted control or explain that approval is required. '
        . 'Attendee Hosts may discuss assigned Event operations but do not create or configure Events. Business / Location Hosts may discuss only their own business context and permitted aggregate operations. '
        . 'If the account is System Admin, advise from the supplied context and direct mutation requests to the dedicated Admin Agent or canonical Admin control.';

    $providerMessages = [
        ['role'=>'user','content'=>coveted_account_agent_context_message($snapshot)],
        ...$messages,
    ];

    if ($provider === 'openai') {
        $response = coveted_ai_http_json(
            'https://api.openai.com/v1/responses',
            ['Authorization: Bearer ' . $secret],
            ['model'=>$model,'instructions'=>$instructions,'input'=>$providerMessages]
        );
        $text = coveted_ai_openai_text($response);
    } else {
        $response = coveted_ai_http_json(
            'https://api.anthropic.com/v1/messages',
            ['x-api-key: ' . $secret,'anthropic-version: 2023-06-01'],
            ['model'=>$model,'max_tokens'=>1600,'system'=>$instructions,'messages'=>$providerMessages]
        );
        $text = coveted_ai_anthropic_text($response);
    }
    if ($text === '') {
        throw new RuntimeException('The Agent provider returned an empty response.');
    }
    coveted_audit(
        'account.agent_chat_completed',
        'ai_provider',
        $provider,
        ['model'=>$model,'surface'=>(string)($snapshot['surface'] ?? '/'),'role'=>$role,'message_count'=>count($messages)],
        (int)$user['id']
    );
    return ['provider'=>$provider,'model'=>$model,'text'=>$text];
}
