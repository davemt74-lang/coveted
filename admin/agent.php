<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/admin_agent_brain.php';
require_once dirname(__DIR__) . '/app/admin_agent_briefing.php';
require_once dirname(__DIR__) . '/app/admin_agent_actions.php';
require_once dirname(__DIR__) . '/app/admin_agent_threads.php';
require_once dirname(__DIR__) . '/app/admin_agent_runs.php';
require_once dirname(__DIR__) . '/app/site_branding.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$error = '';
$brainError = '';
$threadError = '';
$briefingError = '';
$crmCursor = 0;
$auditCursor = 0;
$currentThreadRef = '';
$currentThreadTitle = 'New Chat';
$threadStorageReady = false;
$autonomousActionsEnabled = coveted_admin_agent_autonomous_actions_enabled($pdo);

try {
    coveted_admin_agent_runs_ensure_schema($pdo);
    $threadStorageReady = true;

    if (!isset($_GET['new'])) {
        $requestedThread = trim((string)($_GET['thread'] ?? ''));
        if ($requestedThread !== '') {
            $thread = coveted_admin_agent_thread_by_ref($admin, $requestedThread, $pdo);
            if (!$thread || $thread['status'] !== 'active') {
                $threadError = 'That Admin Agent chat is unavailable or archived.';
            } else {
                $currentThreadRef = (string)$thread['public_id'];
                $currentThreadTitle = (string)$thread['title'];
            }
        }
    }
} catch (Throwable $e) {
    error_log('Admin Agent thread storage unavailable: ' . $e->getMessage());
    $threadError = 'Persistent Admin Agent chat storage is unavailable. Import the current database migration or verify database DDL permissions.';
}

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
    $crmCursor = (int)($pdo->query('SELECT COALESCE(MAX(id), 0) FROM invite_requests')->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $crmCursor = 0;
}

try {
    $auditCursor = (int)($pdo->query('SELECT COALESCE(MAX(id), 0) FROM audit_events')->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $auditCursor = 0;
}

try {
    $brain = coveted_site_branding_enrich_agent_snapshot(coveted_admin_agent_snapshot($admin, $pdo));
} catch (Throwable $e) {
    error_log('Admin Agent brain load failed: ' . $e->getMessage());
    $brain = [
        'readiness' => ['percent' => 0, 'ready' => 0, 'total' => 0],
        'opportunities' => [],
        'issues' => ['brain'],
    ];
    $brainError = 'The Agent could not read the full platform snapshot. Chat is still available, but proactive recommendations may be incomplete.';
}

try {
    $briefing = coveted_admin_agent_briefing($admin, $brain, $pdo);
} catch (Throwable $e) {
    error_log('Admin Agent briefing unavailable: ' . $e->getMessage());
    $briefing = [
        'headline' => 'The proactive briefing is temporarily unavailable',
        'summary' => 'The Agent can still use the current canonical platform snapshot in chat.',
        'generated_at' => '',
        'readiness' => (int)($brain['readiness']['percent'] ?? 0),
        'priority_count' => 0,
        'crm_ready' => 0,
        'operations_attention' => 0,
        'changes_24h' => 0,
        'top_moves' => array_slice((array)($brain['opportunities'] ?? []), 0, 3),
        'recent' => [],
        'issues' => ['briefing'],
    ];
    $briefingError = 'The daily briefing could not read all of its inputs. The Agent will not invent missing activity.';
}

$brainIssues = array_values(array_unique([
    ...(array)($brain['issues'] ?? []),
    ...(array)($briefing['issues'] ?? []),
]));
$topMoves = (array)($briefing['top_moves'] ?? []);
$recentBriefing = (array)($briefing['recent'] ?? []);

coveted_page_start('Admin Agent', '', true);
coveted_admin_ui_start($admin, 'agent', 'Admin Agent', $counts);
?>
<div class="cv-admin-agent-page"
     data-admin-agent
     data-endpoint="/api/admin-agent-chat.php"
     data-threads-endpoint="/api/admin-agent-threads.php"
     data-activity-endpoint="/api/admin-agent-activity.php"
     data-operations-activity-endpoint="/api/admin-agent-operations-activity.php"
     data-current-thread="<?= coveted_e($currentThreadRef) ?>"
     data-thread-storage-ready="<?= $threadStorageReady ? '1' : '0' ?>"
     data-crm-cursor="<?= $crmCursor ?>"
     data-audit-cursor="<?= $auditCursor ?>"
     data-autonomous-actions="<?= $autonomousActionsEnabled ? '1' : '0' ?>"
     data-csrf="<?= coveted_e(coveted_csrf_token()) ?>"
     data-start-new="<?= isset($_GET['new']) ? '1' : '0' ?>">
    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
    <?php if ($brainError !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($brainError) ?></div><?php endif; ?>
    <?php if ($threadError !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($threadError) ?></div><?php endif; ?>
    <?php if ($briefingError !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($briefingError) ?></div><?php endif; ?>

    <div class="cv-admin-agent-thread-toolbar">
        <div class="cv-admin-agent-thread-heading">
            <span class="cv-eyebrow">CHAT</span>
            <strong data-agent-thread-title><?= coveted_e($currentThreadTitle) ?></strong>
        </div>
        <div class="cv-admin-agent-thread-actions">
            <button type="button" class="cv-button cv-button-soft" data-agent-history-toggle>Search Chats</button>
            <button type="button" class="cv-button cv-button-soft" data-agent-rename-thread <?= $currentThreadRef === '' ? 'hidden' : '' ?>>Rename</button>
            <button type="button" class="cv-button cv-button-soft" data-agent-archive-thread <?= $currentThreadRef === '' ? 'hidden' : '' ?>>Archive</button>
            <a class="cv-button cv-button-primary" href="/admin/agent.php?new=1">New Chat</a>
        </div>
    </div>

    <section class="cv-admin-agent-history" data-agent-history-panel hidden aria-label="Search Admin Agent chats">
        <div class="cv-admin-agent-history-search">
            <input type="search" maxlength="120" placeholder="Search chat titles and messages…" aria-label="Search Admin Agent chats" data-agent-thread-search>
            <button type="button" class="cv-button cv-button-soft" data-agent-history-close>Close</button>
        </div>
        <div class="cv-admin-agent-history-results" data-agent-thread-results></div>
    </section>

    <section class="cv-admin-agent-canvas" aria-label="Admin Agent conversation" aria-live="polite" data-agent-canvas>
        <div class="cv-admin-agent-empty" data-agent-empty>
            <div class="cv-admin-agent-mark" aria-hidden="true">C</div>
            <h2>What should Coveted work on next?</h2>
            <p>
                The Agent reads the current Admin state, operational history and available Coveted tools
                before it <?= $autonomousActionsEnabled ? 'recommends or autonomously executes an allowlisted Admin action.' : 'recommends the next move.' ?>
            </p>

            <section class="cv-admin-agent-briefing" aria-labelledby="agent-briefing-title">
                <header class="cv-admin-agent-briefing-head">
                    <div>
                        <span class="cv-eyebrow">DAILY BRIEFING</span>
                        <h3 id="agent-briefing-title"><?= coveted_e((string)$briefing['headline']) ?></h3>
                        <p><?= coveted_e((string)$briefing['summary']) ?></p>
                    </div>
                    <?php if (trim((string)($briefing['generated_at'] ?? '')) !== ''): ?>
                        <small>Updated <?= coveted_e((string)$briefing['generated_at']) ?> · no AI call</small>
                    <?php endif; ?>
                </header>

                <div class="cv-admin-agent-briefing-stats" aria-label="Daily briefing signals">
                    <div><strong><?= (int)($briefing['readiness'] ?? 0) ?>%</strong><span>Launch readiness</span></div>
                    <div><strong><?= (int)($briefing['priority_count'] ?? 0) ?></strong><span>P1 priorities</span></div>
                    <div><strong><?= (int)($briefing['crm_ready'] ?? 0) ?></strong><span>CRM ready</span></div>
                    <div><strong><?= (int)($briefing['operations_attention'] ?? 0) ?></strong><span>Ops attention</span></div>
                    <div><strong><?= (int)($briefing['changes_24h'] ?? 0) ?></strong><span>Changes / 24h</span></div>
                </div>

                <div class="cv-admin-agent-briefing-grid">
                    <section aria-labelledby="briefing-moves-title">
                        <span class="cv-eyebrow">PROACTIVE OPPORTUNITIES</span>
                        <h4 id="briefing-moves-title">Next best moves</h4>
                        <?php if ($topMoves): ?>
                            <div class="cv-admin-agent-briefing-list">
                                <?php foreach ($topMoves as $move): ?>
                                    <a href="<?= coveted_e((string)($move['href'] ?? '/admin/agent.php')) ?>">
                                        <span>
                                            <strong><?= coveted_e((string)($move['title'] ?? 'Review opportunity')) ?></strong>
                                            <small><?= coveted_e((string)($move['evidence'] ?? $move['detail'] ?? '')) ?></small>
                                        </span>
                                        <em>P<?= (int)($move['priority'] ?? 3) ?> · <?= coveted_e((string)($move['category'] ?? 'Admin')) ?></em>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="cv-form-help">No current setup or operational gap is being flagged.</p>
                        <?php endif; ?>
                    </section>

                    <section aria-labelledby="briefing-recent-title">
                        <span class="cv-eyebrow">LAST 24 HOURS</span>
                        <h4 id="briefing-recent-title">Meaningful changes</h4>
                        <?php if ($recentBriefing): ?>
                            <div class="cv-admin-agent-briefing-list">
                                <?php foreach ($recentBriefing as $change): ?>
                                    <div class="cv-admin-agent-briefing-change">
                                        <span>
                                            <strong><?= coveted_e((string)($change['label'] ?? 'Coveted activity')) ?></strong>
                                            <small><?= coveted_e((string)($change['actor'] ?? 'System')) ?><?php if (trim((string)($change['entity'] ?? '')) !== ''): ?> · <?= coveted_e((string)$change['entity']) ?><?php endif; ?></small>
                                        </span>
                                        <em><?= coveted_e((string)($change['category'] ?? 'Platform')) ?><?php if (trim((string)($change['at'] ?? '')) !== ''): ?> · <?= coveted_e((string)$change['at']) ?><?php endif; ?></em>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="cv-form-help">No meaningful audited change has been recorded in the last 24 hours.</p>
                        <?php endif; ?>
                    </section>
                </div>

                <?php if ($chatProviders): ?>
                    <button type="button" class="cv-button cv-button-soft cv-admin-agent-briefing-discuss" data-agent-starter="Review the current Coveted daily briefing using the live server context. Explain the most important change, the highest-priority risk, and the best next action. Do not invent missing facts.">Discuss this briefing</button>
                <?php endif; ?>
            </section>

            <?php if ($brainIssues): ?>
                <p class="cv-form-help">Partial data: <?= coveted_e(implode(', ', $brainIssues)) ?>. The Agent will not guess about unavailable sources.</p>
            <?php endif; ?>

            <?php if (!$chatProviders): ?>
                <div class="cv-admin-agent-setup-callout">
                    <strong>Connect a chat provider to reason over this briefing.</strong>
                    <span>The briefing, readiness and opportunity engine work without an LLM. Add OpenAI or Anthropic only when you want to discuss or act on the live state.</span>
                    <a href="/admin/ai-settings.php">Open AI Settings →</a>
                </div>
            <?php else: ?>
                <div class="cv-admin-agent-starters" aria-label="Suggested prompts">
                    <button type="button" data-agent-starter="Review my current Coveted opportunities and tell me the three highest-value actions to take next. Use the live server context and explain why each one matters.">Prioritize my opportunities</button>
                    <button type="button" data-agent-starter="Audit the current Coveted setup for launch readiness. Tell me what is complete, what is incomplete, and what I should do next without inventing requirements that are not in the system.">Audit launch readiness</button>
                    <button type="button" data-agent-starter="Review recent Coveted operational and audit history. What changed, what needs attention, and what opportunity should I act on now?">Review recent activity</button>
                    <?php if ($autonomousActionsEnabled): ?>
                        <button type="button" data-agent-starter="Review the live Coveted state and autonomously complete the highest-value allowlisted Admin action that is clearly justified by the current data. Do not invent missing IDs or requirements.">Act on the top opportunity</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="cv-admin-agent-composer-shell">
        <form class="cv-admin-agent-composer" data-agent-form>
            <div class="cv-admin-agent-provider">
                <select name="provider" aria-label="AI provider" data-agent-provider <?= !$chatProviders || !$threadStorageReady ? 'disabled' : '' ?>>
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
                <textarea name="message" rows="1" maxlength="12000" placeholder="Message Coveted Admin Agent…" aria-label="Message Coveted Admin Agent" data-agent-input <?= !$chatProviders || !$threadStorageReady ? 'disabled' : '' ?>></textarea>
                <button type="submit" class="cv-admin-agent-send" aria-label="Send message" <?= !$chatProviders || !$threadStorageReady ? 'disabled' : '' ?>>↑</button>
            </div>
            <div class="cv-admin-agent-composer-meta">
                <span data-agent-status><?= !$threadStorageReady ? 'Chat storage required' : ($chatProviders ? ($autonomousActionsEnabled ? 'Ready · Autonomous actions ON' : 'Ready · Read/advise mode') : 'Provider required') ?></span>
                <span>Persistent server history · <a href="/admin/ai-settings.php">Autonomous actions <?= $autonomousActionsEnabled ? 'ON' : 'OFF' ?></a></span>
            </div>
        </form>
    </div>
</div>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
