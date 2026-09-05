<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/admin_agent_brain.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$error = '';
$brainError = '';

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

try {
    $brain = coveted_admin_agent_snapshot($admin, $pdo);
} catch (Throwable $e) {
    error_log('Admin Agent brain load failed: ' . $e->getMessage());
    $brain = [
        'readiness' => ['percent' => 0, 'ready' => 0, 'total' => 0],
        'opportunities' => [],
        'issues' => ['brain'],
    ];
    $brainError = 'The Agent could not read the full platform snapshot. Chat is still available, but proactive recommendations may be incomplete.';
}

$readiness = (array)($brain['readiness'] ?? []);
$opportunities = array_slice((array)($brain['opportunities'] ?? []), 0, 7);
$brainIssues = array_values((array)($brain['issues'] ?? []));

coveted_page_start('Admin Agent', '', true);
coveted_admin_ui_start($admin, 'agent', 'Admin Agent', $counts);
?>
<div class="cv-admin-agent-page" data-admin-agent data-endpoint="/api/admin-agent-chat.php" data-csrf="<?= coveted_e(coveted_csrf_token()) ?>" data-start-new="<?= isset($_GET['new']) ? '1' : '0' ?>">
    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
    <?php if ($brainError !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($brainError) ?></div><?php endif; ?>

    <section class="cv-admin-agent-canvas" aria-label="Admin Agent conversation" aria-live="polite" data-agent-canvas>
        <div class="cv-admin-agent-empty" data-agent-empty>
            <div class="cv-admin-agent-mark" aria-hidden="true">C</div>
            <h2>What should Coveted work on next?</h2>
            <p>The Agent reads the current Admin state, operational history and available Coveted tools before it recommends the next move.</p>

            <div class="cv-stat-grid cv-stat-grid-compact" aria-label="Admin Agent readiness">
                <div class="cv-card cv-stat">
                    <strong><?= (int)($readiness['percent'] ?? 0) ?>%</strong>
                    <span>Launch readiness</span>
                </div>
                <div class="cv-card cv-stat">
                    <strong><?= count((array)($brain['opportunities'] ?? [])) ?></strong>
                    <span>Current opportunities</span>
                </div>
                <div class="cv-card cv-stat">
                    <strong><?= count((array)($brain['memory'] ?? [])) ?></strong>
                    <span>Recent tracked changes</span>
                </div>
            </div>

            <?php if ($opportunities): ?>
                <section class="cv-admin-panel" aria-labelledby="agent-opportunities-title">
                    <div class="cv-admin-panel-head">
                        <div>
                            <span class="cv-eyebrow">PROACTIVE OPPORTUNITIES</span>
                            <h3 id="agent-opportunities-title">What needs attention or can create value next</h3>
                        </div>
                        <span class="cv-status"><?= (int)($readiness['ready'] ?? 0) ?>/<?= (int)($readiness['total'] ?? 0) ?> readiness checks</span>
                    </div>
                    <div class="cv-admin-list">
                        <?php foreach ($opportunities as $opportunity): ?>
                            <a class="cv-admin-list-row" href="<?= coveted_e((string)$opportunity['href']) ?>">
                                <span class="cv-admin-list-copy">
                                    <strong><?= coveted_e((string)$opportunity['title']) ?></strong>
                                    <small><?= coveted_e((string)$opportunity['detail']) ?></small>
                                    <?php if (trim((string)($opportunity['evidence'] ?? '')) !== ''): ?>
                                        <small><?= coveted_e((string)$opportunity['evidence']) ?></small>
                                    <?php endif; ?>
                                </span>
                                <span>P<?= (int)$opportunity['priority'] ?> · <?= coveted_e((string)$opportunity['category']) ?> →</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php else: ?>
                <section class="cv-admin-panel">
                    <span class="cv-eyebrow">OPPORTUNITIES</span>
                    <h3>No current setup or operational gap is being flagged.</h3>
                    <p>The Agent can still review growth, partner, event and member-value opportunities from the live platform state.</p>
                </section>
            <?php endif; ?>

            <?php if ($brainIssues): ?>
                <p class="cv-form-help">Partial data: <?= coveted_e(implode(', ', $brainIssues)) ?>. The Agent will not guess about unavailable sources.</p>
            <?php endif; ?>

            <?php if (!$chatProviders): ?>
                <div class="cv-admin-agent-setup-callout">
                    <strong>Connect a chat provider to reason over these opportunities.</strong>
                    <span>The readiness and opportunity engine works without an LLM. Add OpenAI or Anthropic to discuss and prioritize the live state.</span>
                    <a href="/admin/ai-settings.php">Open AI Settings →</a>
                </div>
            <?php else: ?>
                <div class="cv-admin-agent-starters" aria-label="Suggested prompts">
                    <button type="button" data-agent-starter="Review my current Coveted opportunities and tell me the three highest-value actions to take next. Use the live server context and explain why each one matters.">Prioritize my opportunities</button>
                    <button type="button" data-agent-starter="Audit the current Coveted setup for launch readiness. Tell me what is complete, what is incomplete, and what I should do next without inventing requirements that are not in the system.">Audit launch readiness</button>
                    <button type="button" data-agent-starter="Review recent Coveted operational and audit history. What changed, what needs attention, and what opportunity should I act on now?">Review recent activity</button>
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
                <span data-agent-status><?= $chatProviders ? 'Live platform context ready' : 'Provider required' ?></span>
                <span>System Admin only · canonical state · server-side credentials</span>
            </div>
        </form>
    </div>
</div>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
