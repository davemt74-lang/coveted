<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** @return array<string,array<string,string>> */
function coveted_ai_provider_definitions(): array
{
    return [
        'openai' => [
            'label' => 'OpenAI',
            'chat_label' => 'ChatGPT',
            'default_model' => 'gpt-5.6',
            'key_placeholder' => 'sk-…',
        ],
        'anthropic' => [
            'label' => 'Anthropic',
            'chat_label' => 'Claude',
            'default_model' => 'claude-sonnet-5',
            'key_placeholder' => 'sk-ant-…',
        ],
        'elevenlabs' => [
            'label' => 'ElevenLabs',
            'chat_label' => 'ElevenLabs',
            'default_model' => '',
            'key_placeholder' => 'xi-api-key',
        ],
    ];
}

function coveted_ai_provider_key(string $provider): string
{
    $provider = strtolower(trim($provider));
    if (!isset(coveted_ai_provider_definitions()[$provider])) {
        throw new InvalidArgumentException('Unsupported AI provider.');
    }
    return $provider;
}

function coveted_ai_ensure_schema(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo ??= coveted_db();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ai_provider_settings (
            provider VARCHAR(32) PRIMARY KEY,
            secret_ciphertext TEXT NULL,
            secret_last4 VARCHAR(8) NULL,
            model VARCHAR(190) NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ai_provider_enabled (enabled),
            CONSTRAINT fk_ai_provider_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $insert = $pdo->prepare(
        "INSERT IGNORE INTO ai_provider_settings (provider, model, enabled)
         VALUES (?, ?, 0)"
    );
    foreach (coveted_ai_provider_definitions() as $provider => $definition) {
        $insert->execute([$provider, $definition['default_model'] !== '' ? $definition['default_model'] : null]);
    }

    $ready = true;
}

function coveted_ai_root_secret(): string
{
    $app = coveted_config('app');
    $secret = trim((string)($app['ai_credentials_key'] ?? ''));
    if ($secret === '') {
        // Backward-compatible fallback for existing Coveted installs that
        // already have a strong uncommitted server-side application secret.
        $secret = trim((string)($app['claim_code_lookup_key'] ?? ''));
    }

    if (
        strlen($secret) < 32
        || str_contains(strtolower($secret), 'replace-with')
        || str_contains(strtolower($secret), 'replace-me')
    ) {
        throw new RuntimeException('Configure app.ai_credentials_key with a random secret of at least 32 characters before saving AI credentials.');
    }

    return hash_hkdf('sha256', $secret, 32, 'coveted-ai-credentials-v1');
}

function coveted_ai_credentials_ready(): bool
{
    try {
        coveted_ai_root_secret();
        return function_exists('openssl_encrypt') && function_exists('openssl_decrypt');
    } catch (Throwable) {
        return false;
    }
}

function coveted_ai_encrypt_secret(string $plaintext): string
{
    $plaintext = trim($plaintext);
    if ($plaintext === '' || strlen($plaintext) > 4096) {
        throw new InvalidArgumentException('Enter a valid API key.');
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL is required to store AI credentials securely.');
    }

    $key = coveted_ai_root_secret();
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Unable to encrypt AI credential.');
    }

    return 'v1:' . base64_encode($iv . $tag . $ciphertext);
}

function coveted_ai_decrypt_secret(string $encoded): string
{
    if (!str_starts_with($encoded, 'v1:')) {
        throw new RuntimeException('Unsupported AI credential format.');
    }
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL is required to read AI credentials securely.');
    }

    $raw = base64_decode(substr($encoded, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('Stored AI credential is invalid.');
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        coveted_ai_root_secret(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if ($plaintext === false || $plaintext === '') {
        throw new RuntimeException('Unable to decrypt stored AI credential.');
    }
    return $plaintext;
}

/** @return array<string,array<string,mixed>> */
function coveted_ai_provider_statuses(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    coveted_ai_ensure_schema($pdo);
    $rows = $pdo->query('SELECT provider, secret_last4, model, enabled, updated_at FROM ai_provider_settings')->fetchAll();
    $byProvider = [];
    foreach ($rows as $row) {
        $byProvider[(string)$row['provider']] = $row;
    }

    $result = [];
    foreach (coveted_ai_provider_definitions() as $provider => $definition) {
        $row = $byProvider[$provider] ?? [];
        $result[$provider] = [
            'provider' => $provider,
            'label' => $definition['label'],
            'chat_label' => $definition['chat_label'],
            'configured' => trim((string)($row['secret_last4'] ?? '')) !== '',
            'last4' => (string)($row['secret_last4'] ?? ''),
            'model' => trim((string)($row['model'] ?? '')) ?: $definition['default_model'],
            'enabled' => !empty($row['enabled']),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
    return $result;
}

function coveted_ai_save_provider(array $admin, string $provider, array $input, ?PDO $pdo = null): void
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $provider = coveted_ai_provider_key($provider);
    $definitions = coveted_ai_provider_definitions();
    $pdo ??= coveted_db();
    coveted_ai_ensure_schema($pdo);

    $apiKey = trim((string)($input['api_key'] ?? ''));
    $model = trim((string)($input['model'] ?? ''));
    $enabled = !empty($input['enabled']);

    if ($provider !== 'elevenlabs') {
        if ($model === '') {
            $model = $definitions[$provider]['default_model'];
        }
        if (strlen($model) > 190 || preg_match('/[^A-Za-z0-9._:\/-]/', $model) === 1) {
            throw new InvalidArgumentException('Enter a valid model identifier.');
        }
    } else {
        $model = '';
    }

    $current = $pdo->prepare('SELECT secret_ciphertext, secret_last4 FROM ai_provider_settings WHERE provider = ? LIMIT 1');
    $current->execute([$provider]);
    $row = $current->fetch() ?: [];
    $ciphertext = (string)($row['secret_ciphertext'] ?? '');
    $last4 = (string)($row['secret_last4'] ?? '');

    if ($apiKey !== '') {
        if (strlen($apiKey) < 10 || strlen($apiKey) > 4096 || preg_match('/[\x00-\x20\x7F]/', $apiKey) === 1) {
            throw new InvalidArgumentException('Enter a valid API key without spaces.');
        }
        $ciphertext = coveted_ai_encrypt_secret($apiKey);
        $last4 = substr($apiKey, -4);
    }

    if ($enabled && $ciphertext === '') {
        throw new InvalidArgumentException('Add an API key before enabling this provider.');
    }

    $pdo->prepare(
        "INSERT INTO ai_provider_settings (provider, secret_ciphertext, secret_last4, model, enabled, updated_by)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            secret_ciphertext = VALUES(secret_ciphertext),
            secret_last4 = VALUES(secret_last4),
            model = VALUES(model),
            enabled = VALUES(enabled),
            updated_by = VALUES(updated_by),
            updated_at = UTC_TIMESTAMP()"
    )->execute([
        $provider,
        $ciphertext !== '' ? $ciphertext : null,
        $last4 !== '' ? $last4 : null,
        $model !== '' ? $model : null,
        $enabled ? 1 : 0,
        (int)$admin['id'],
    ]);

    coveted_audit(
        'admin.ai_provider_updated',
        'ai_provider',
        $provider,
        ['enabled' => $enabled, 'model' => $model !== '' ? $model : null, 'key_rotated' => $apiKey !== ''],
        (int)$admin['id']
    );
}

function coveted_ai_clear_provider_key(array $admin, string $provider, ?PDO $pdo = null): void
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    $provider = coveted_ai_provider_key($provider);
    $pdo ??= coveted_db();
    coveted_ai_ensure_schema($pdo);
    $pdo->prepare(
        'UPDATE ai_provider_settings SET secret_ciphertext = NULL, secret_last4 = NULL, enabled = 0, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE provider = ?'
    )->execute([(int)$admin['id'], $provider]);
    coveted_audit('admin.ai_provider_key_cleared', 'ai_provider', $provider, [], (int)$admin['id']);
}

function coveted_ai_provider_secret(string $provider, ?PDO $pdo = null): string
{
    $provider = coveted_ai_provider_key($provider);
    $pdo ??= coveted_db();
    coveted_ai_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT secret_ciphertext, enabled FROM ai_provider_settings WHERE provider = ? LIMIT 1');
    $stmt->execute([$provider]);
    $row = $stmt->fetch();
    if (!$row || empty($row['enabled']) || trim((string)$row['secret_ciphertext']) === '') {
        throw new InvalidArgumentException('That AI provider is not enabled or configured.');
    }
    return coveted_ai_decrypt_secret((string)$row['secret_ciphertext']);
}

/** @return array<string,mixed> */
function coveted_ai_http_json(string $url, array $headers, array $payload): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for AI provider requests.');
    }

    $allowedUrls = [
        'https://api.openai.com/v1/responses',
        'https://api.anthropic.com/v1/messages',
    ];
    if (!in_array($url, $allowedUrls, true)) {
        throw new RuntimeException('AI provider endpoint is not allowlisted.');
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize provider request.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Coveted-Admin-Agent/1.0',
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('AI provider request failed: ' . ($curlError !== '' ? $curlError : 'network error'));
    }

    try {
        $decoded = json_decode((string)$body, true, 128, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new RuntimeException('AI provider returned an unreadable response.', 0, $e);
    }

    if ($status < 200 || $status >= 300) {
        $message = trim((string)($decoded['error']['message'] ?? $decoded['error']['type'] ?? 'Provider request failed.'));
        throw new RuntimeException('AI provider error (' . $status . '): ' . mb_substr($message, 0, 500));
    }

    return is_array($decoded) ? $decoded : [];
}

/** @param array<int,array{role:string,content:string}> $messages */
function coveted_ai_validate_chat_messages(array $messages): array
{
    $clean = [];
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        $role = strtolower(trim((string)($message['role'] ?? '')));
        $content = trim((string)($message['content'] ?? ''));
        if (!in_array($role, ['user', 'assistant'], true) || $content === '') {
            continue;
        }
        if (mb_strlen($content) > 12000) {
            throw new InvalidArgumentException('A chat message is too long.');
        }
        $clean[] = ['role' => $role, 'content' => $content];
    }
    $clean = array_slice($clean, -24);
    if (!$clean) {
        throw new InvalidArgumentException('Enter a message for the Admin Agent.');
    }
    return $clean;
}

function coveted_ai_openai_text(array $response): string
{
    $parts = [];
    foreach ((array)($response['output'] ?? []) as $item) {
        foreach ((array)($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                $parts[] = (string)$content['text'];
            }
        }
    }
    return trim(implode("\n", $parts));
}

function coveted_ai_anthropic_text(array $response): string
{
    $parts = [];
    foreach ((array)($response['content'] ?? []) as $content) {
        if (($content['type'] ?? '') === 'text' && isset($content['text'])) {
            $parts[] = (string)$content['text'];
        }
    }
    return trim(implode("\n", $parts));
}

/** @param array<int,array{role:string,content:string}> $messages @return array{provider:string,model:string,text:string} */
function coveted_ai_chat(array $admin, string $provider, array $messages, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    $provider = coveted_ai_provider_key($provider);
    if ($provider === 'elevenlabs') {
        throw new InvalidArgumentException('ElevenLabs is configured for voice services, not text chat. Choose ChatGPT or Claude.');
    }

    $messages = coveted_ai_validate_chat_messages($messages);
    $pdo ??= coveted_db();
    $statuses = coveted_ai_provider_statuses($pdo);
    $model = trim((string)($statuses[$provider]['model'] ?? ''));
    if ($model === '') {
        throw new InvalidArgumentException('Choose a model for that provider in AI Settings.');
    }
    $secret = coveted_ai_provider_secret($provider, $pdo);
    $instructions = 'You are the Coveted System Admin Agent. Help the administrator operate and reason about the Coveted platform. Be concise, practical, and explicit about uncertainty. Do not claim access to database records, files, tools, or live application state unless that information is included in the conversation.';

    if ($provider === 'openai') {
        $response = coveted_ai_http_json(
            'https://api.openai.com/v1/responses',
            ['Authorization: Bearer ' . $secret],
            [
                'model' => $model,
                'instructions' => $instructions,
                'input' => $messages,
            ]
        );
        $text = coveted_ai_openai_text($response);
    } else {
        $response = coveted_ai_http_json(
            'https://api.anthropic.com/v1/messages',
            [
                'x-api-key: ' . $secret,
                'anthropic-version: 2023-06-01',
            ],
            [
                'model' => $model,
                'max_tokens' => 1600,
                'system' => $instructions,
                'messages' => $messages,
            ]
        );
        $text = coveted_ai_anthropic_text($response);
    }

    if ($text === '') {
        throw new RuntimeException('The AI provider returned an empty response.');
    }

    try {
        coveted_audit(
            'admin.ai_chat_completed',
            'ai_provider',
            $provider,
            ['model' => $model, 'message_count' => count($messages)],
            (int)$admin['id']
        );
    } catch (Throwable $e) {
        error_log('Admin AI chat audit failed: ' . $e->getMessage());
    }

    return ['provider' => $provider, 'model' => $model, 'text' => $text];
}
