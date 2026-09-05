<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/ai_providers.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$error = '';
$notice = trim((string)($_SESSION['ai_settings_notice'] ?? ''));
unset($_SESSION['ai_settings_notice']);

try {
    coveted_ai_ensure_schema($pdo);
} catch (Throwable $e) {
    error_log('AI settings schema unavailable: ' . $e->getMessage());
    $error = 'AI provider settings are temporarily unavailable.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    coveted_require_csrf();
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        $provider = trim((string)($_POST['provider'] ?? ''));

        if ($action === 'save_provider') {
            coveted_ai_save_provider($admin, $provider, $_POST, $pdo);
            $_SESSION['ai_settings_notice'] = ucfirst($provider) . ' settings saved.';
            coveted_redirect('/admin/ai-settings.php');
        }

        if ($action === 'clear_provider_key') {
            coveted_ai_clear_provider_key($admin, $provider, $pdo);
            $_SESSION['ai_settings_notice'] = ucfirst($provider) . ' API key removed and provider disabled.';
            coveted_redirect('/admin/ai-settings.php');
        }

        throw new InvalidArgumentException('Unsupported AI settings action.');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('AI settings update failed: ' . $e->getMessage());
        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to save AI provider settings.';
    }
}

$providers = $error === '' ? coveted_ai_provider_statuses($pdo) : [];
$credentialStorageReady = coveted_ai_credentials_ready();
$counts = coveted_admin_ui_counts($pdo);

coveted_page_start('AI Settings', '', true);
coveted_admin_ui_start($admin, 'ai-settings', 'AI Settings', $counts);
?>
<div class="cv-admin-page-head cv-ai-settings-head">
    <div>
        <span class="cv-eyebrow">AGENT · PROVIDERS</span>
        <h1>AI provider keys</h1>
        <p>Connect Coveted to OpenAI, Anthropic and ElevenLabs. API keys stay server-side, are encrypted before database storage and are never returned to the browser after saving.</p>
    </div>
    <a class="cv-button cv-button-primary" href="/admin/agent.php">Open Admin Agent</a>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<?php if (!$credentialStorageReady): ?>
    <div class="cv-alert cv-alert-error cv-ai-credentials-warning">
        <strong>Credential encryption is not ready.</strong>
        <span>Add a random 32+ character <code>app.ai_credentials_key</code> value to your uncommitted production <code>config.php</code>. Until then, Coveted will not accept API keys.</span>
    </div>
<?php endif; ?>

<section class="cv-ai-provider-grid">
    <?php foreach ($providers as $provider => $status): ?>
        <article class="cv-admin-panel cv-ai-provider-card <?= !empty($status['enabled']) ? 'is-enabled' : '' ?>">
            <header class="cv-ai-provider-head">
                <div>
                    <span class="cv-eyebrow"><?= coveted_e(strtoupper((string)$status['label'])) ?></span>
                    <h2><?= coveted_e((string)$status['chat_label']) ?></h2>
                </div>
                <span class="cv-ai-provider-state <?= !empty($status['enabled']) ? 'is-ready' : '' ?>">
                    <?= !empty($status['enabled']) ? 'Enabled' : (!empty($status['configured']) ? 'Configured' : 'Not configured') ?>
                </span>
            </header>

            <form method="post" class="cv-ai-provider-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                <input type="hidden" name="action" value="save_provider">
                <input type="hidden" name="provider" value="<?= coveted_e($provider) ?>">

                <label>
                    API key
                    <input
                        type="password"
                        name="api_key"
                        maxlength="4096"
                        autocomplete="new-password"
                        placeholder="<?= !empty($status['configured']) ? 'Saved · ends in ' . coveted_e((string)$status['last4']) : coveted_e((string)(coveted_ai_provider_definitions()[$provider]['key_placeholder'] ?? 'API key')) ?>"
                        <?= !$credentialStorageReady ? 'disabled' : '' ?>
                    >
                    <small><?= !empty($status['configured']) ? 'Leave blank to keep the saved key. Enter a new key to rotate it.' : 'The full key is encrypted on save and will not be shown again.' ?></small>
                </label>

                <?php if ($provider !== 'elevenlabs'): ?>
                    <label>
                        Model
                        <input name="model" maxlength="190" value="<?= coveted_e((string)$status['model']) ?>" placeholder="Model identifier">
                        <small>Use a model your provider account is authorized to access.</small>
                    </label>
                <?php else: ?>
                    <div class="cv-ai-provider-note">
                        <strong>Voice provider</strong>
                        <span>The ElevenLabs key is stored now for the voice layer. Text chat stays on ChatGPT or Claude in this phase.</span>
                    </div>
                <?php endif; ?>

                <label class="cv-ai-provider-toggle">
                    <input type="checkbox" name="enabled" value="1" <?= !empty($status['enabled']) ? 'checked' : '' ?> <?= !$credentialStorageReady ? 'disabled' : '' ?>>
                    <span>Enable this provider</span>
                </label>

                <div class="cv-ai-provider-actions">
                    <button class="cv-button cv-button-primary" type="submit" <?= !$credentialStorageReady ? 'disabled' : '' ?>>Save Provider</button>
                </div>
            </form>

            <?php if (!empty($status['configured'])): ?>
                <form method="post" class="cv-ai-provider-clear" onsubmit="return confirm('Remove this saved API key and disable the provider?');">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="clear_provider_key">
                    <input type="hidden" name="provider" value="<?= coveted_e($provider) ?>">
                    <button type="submit">Remove saved key</button>
                </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>

<section class="cv-admin-panel cv-ai-security-note">
    <span class="cv-eyebrow">SECURITY MODEL</span>
    <h2>Keys never enter client-side JavaScript.</h2>
    <p>The browser sends Admin Agent prompts only to Coveted. Coveted decrypts the selected provider key on the server, calls the provider over HTTPS, and returns only the generated response. Saved keys are represented in Admin by their final four characters.</p>
</section>

<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
