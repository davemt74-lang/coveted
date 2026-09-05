<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/ai_providers.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$error = '';

try {
    $providers = coveted_ai_provider_statuses($pdo);
} catch (Throwable $e) {
    error_log('Admin Agent provider load failed: ' . $e->getMessage());
    $providers = [];
    $error = 'AI providers are unavailable. Open AI Settings and confirm the installation is configured.';
}

$chatProviders = array_values(array_filter(
    $providers,
    static fn(array $provider): bool => in_array((string)$provider['provider'], ['openai', 'anthropic'], true)
        && !empty($provider['enabled'])
        && !empty($provider['configured'])
));
$counts = coveted_admin_ui_counts($pdo);

coveted_page_start('Admin Agent', '', true);
coveted_admin_ui_start($admin, 'agent', 'Admin Agent', $counts);
?>
<div class="cv-admin-agent-page" data-admin-agent data-endpoint="/api/admin-agent-chat.php" data-csrf="<?= coveted_e(coveted_csrf_token()) ?>">
    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

    <section class="cv-admin-agent-canvas" aria-label="Admin Agent conversation" aria-live="polite" data-agent-canvas>
        <div class="cv-admin-agent-empty" data-agent-empty>
            <div class="cv-admin-agent-mark" aria-hidden="true">C</div>
            <h2>What do you want to work on?</h2>
            <p>Ask about an event plan, CRM workflow, member experience, partner strategy or the next operational task.</p>
            <?php if (!$chatProviders): ?>
                <div class="cv-admin-agent-setup-callout">
                    <strong>Connect a chat provider first.</strong>
                    <span>Add and enable an OpenAI or Anthropic API key in AI Settings.</span>
                    <a href="/admin/ai-settings.php">Open AI Settings →</a>
                </div>
            <?php else: ?>
                <div class="cv-admin-agent-starters" aria-label="Suggested prompts">
                    <button type="button" data-agent-starter="Review the current event operations workflow and suggest the next highest-value improvement.">Review event operations</button>
                    <button type="button" data-agent-starter="Help me think through the Invite CRM pipeline and what the admin should do next.">Review Invite CRM</button>
                    <button type="button" data-agent-starter="Give me a concise prioritized list of what to build next for Coveted.">Prioritize next build</button>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="cv-admin-agent-composer-shell">
        <form class="cv-admin-agent-composer" data-agent-form>
            <div class="cv-admin-agent-provider">
                <select name="provider" aria-label="AI provider" data-agent-provider <?= !$chatProviders ? 'disabled' : '' ?>>
                    <?php if (!$chatProviders): ?>
                        <option>No provider</option>
                    <?php endif; ?>
                    <?php foreach ($chatProviders as $provider): ?>
                        <option value="<?= coveted_e((string)$provider['provider']) ?>">
                            <?= coveted_e((string)$provider['chat_label']) ?> · <?= coveted_e((string)$provider['model']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cv-admin-agent-input-wrap">
                <textarea name="message" rows="1" maxlength="12000" placeholder="Message Coveted Admin Agent…" aria-label="Message Coveted Admin Agent" data-agent-input <?= !$chatProviders ? 'disabled' : '' ?>></textarea>
                <button type="submit" class="cv-admin-agent-send" aria-label="Send message" <?= !$chatProviders ? 'disabled' : '' ?>>↑</button>
            </div>
            <div class="cv-admin-agent-composer-meta">
                <span data-agent-status><?= $chatProviders ? 'Ready' : 'Provider required' ?></span>
                <span>System Admin only · server-side credentials</span>
            </div>
        </form>
    </div>
</div>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
