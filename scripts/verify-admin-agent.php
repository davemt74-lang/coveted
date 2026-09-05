<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$read = static function (string $path) use ($root): string {
    $content = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($content === false) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
    return $content;
};

$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Admin Agent contract failed: {$label}\n");
        exit(1);
    }
};

$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains($content, $needle)) {
        fwrite(STDERR, "Admin Agent contract failed: {$label}\n");
        exit(1);
    }
};

$providers = $read('app/ai_providers.php');
$settings = $read('admin/ai-settings.php');
$agent = $read('admin/agent.php');
$endpoint = $read('api/admin-agent-chat.php');
$adminUi = $read('app/admin_ui.php');
$config = $read('config-example.php');
$cssEntry = $read('assets/css/coveted.css');
$jsEntry = $read('assets/js/coveted.js');
$css = $read('assets/css/admin-agent-v1.css');
$js = $read('assets/js/admin-agent-v1.js');
$migration = $read('database/migrations/20260905_ai_provider_settings.sql');

$contains($providers, "'openai'", 'OpenAI provider is missing');
$contains($providers, "'anthropic'", 'Anthropic provider is missing');
$contains($providers, "'elevenlabs'", 'ElevenLabs provider is missing');
$contains($providers, 'aes-256-gcm', 'provider keys must be encrypted at rest');
$contains($providers, 'ai_credentials_key', 'dedicated AI credential root secret is missing');
$contains($providers, 'https://api.openai.com/v1/responses', 'OpenAI Responses endpoint is missing');
$contains($providers, 'Authorization: Bearer ', 'OpenAI server-side authentication is missing');
$contains($providers, 'https://api.anthropic.com/v1/messages', 'Anthropic Messages endpoint is missing');
$contains($providers, 'x-api-key: ', 'Anthropic server-side authentication is missing');
$contains($providers, 'anthropic-version: 2023-06-01', 'Anthropic version header is missing');
$contains($providers, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS', 'provider transport must be HTTPS-only');
$missing($providers, 'echo $secret', 'provider secrets must never be echoed');

$contains($settings, 'AI provider keys', 'AI settings page is missing');
$contains($settings, 'name="api_key"', 'API key inputs are missing');
$contains($settings, 'type="password"', 'API keys must use password inputs');
$contains($settings, 'Leave blank to keep the saved key', 'saved keys must not be re-displayed');
$contains($settings, '/admin/agent.php', 'AI settings must link to Admin Agent');

$contains($adminUi, "'/admin/agent.php'", 'Admin Agent is missing from Admin navigation');
$contains($agent, 'data-admin-agent', 'Admin Agent canvas root is missing');
$contains($agent, 'cv-admin-agent-canvas', 'chat canvas is missing');
$contains($agent, 'cv-admin-agent-composer-shell', 'sticky footer composer is missing');
$contains($agent, '/admin/ai-settings.php', 'Admin Agent must link to provider settings');
$contains($endpoint, 'coveted_require_system_admin()', 'chat endpoint must require System Admin');
$contains($endpoint, 'coveted_require_csrf()', 'chat endpoint must enforce CSRF');
$contains($endpoint, 'coveted_ai_chat(', 'chat endpoint must use server provider service');

$contains($config, "'ai_credentials_key'", 'config example must document AI credential encryption key');
$contains($migration, 'CREATE TABLE IF NOT EXISTS ai_provider_settings', 'AI provider migration is missing');
$contains($cssEntry, 'admin-agent-v1.css', 'Admin Agent stylesheet is not loaded');
$contains($jsEntry, 'admin-agent-v1.js', 'Admin Agent script is not loaded');
$contains($css, 'position: fixed', 'composer must remain visible as a sticky footer-style bar');
$contains($css, '@media (max-width: 760px)', 'Admin Agent mobile layout is missing');
$contains($js, 'sessionStorage', 'chat should preserve the current browser-session conversation');
$contains($js, 'textContent = entry.content', 'chat response rendering must avoid raw HTML injection');
$contains($js, "credentials: 'same-origin'", 'chat request must retain the authenticated Admin session');

fwrite(STDOUT, "Admin Agent contract verified.\n");
