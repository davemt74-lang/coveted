<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_onboarding.php';
require_once dirname(__DIR__) . '/app/admin_ui.php';

$admin = coveted_require_system_admin();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'dismiss') {
            coveted_admin_dismiss_onboarding($admin);
            coveted_redirect('/admin/');
        }
        throw new InvalidArgumentException('Unsupported onboarding action.');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

$state = coveted_admin_onboarding_state($admin);

coveted_page_start('Admin Setup', '', true);
?>
<section class="cv-admin-onboarding">
    <div class="cv-admin-onboarding-header">
        <a class="cv-admin-onboarding-brand" href="/admin/">COVETED <span>ADMIN</span></a>
        <details class="cv-admin-dropdown cv-admin-account-menu">
            <summary class="cv-admin-avatar-button" aria-label="Open account menu">
                <?php $avatar = coveted_shell_avatar_url((int)$admin['id']); ?>
                <?php if ($avatar !== null): ?>
                    <img src="<?= coveted_e($avatar) ?>" alt="">
                <?php else: ?>
                    <span><?= coveted_e(coveted_shell_initials((string)$admin['display_name'])) ?></span>
                <?php endif; ?>
            </summary>
            <div class="cv-admin-menu cv-admin-account-panel">
                <div class="cv-admin-account-summary">
                    <strong><?= coveted_e((string)$admin['display_name']) ?></strong>
                    <small><?= coveted_e((string)$admin['email']) ?></small>
                </div>
                <a href="/profile.php"><strong>Profile</strong><small>Photo and account details</small></a>
                <a href="/"><strong>Member View</strong><small>Preview the attendee experience</small></a>
                <form method="post" action="/auth.php?action=logout">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <button type="submit">Sign out</button>
                </form>
            </div>
        </details>
    </div>

    <div class="cv-admin-onboarding-grid">
        <div class="cv-admin-onboarding-copy">
            <span class="cv-eyebrow">FIRST-RUN SETUP</span>
            <h1>Build the first Coveted experience.</h1>
            <p>The platform is installed. These five steps turn an empty install into something you can actually test as an administrator, host, partner and attendee.</p>

            <div class="cv-admin-progress" aria-label="<?= (int)$state['completed'] ?> of <?= (int)$state['total'] ?> setup steps complete">
                <div class="cv-admin-progress-copy"><strong><?= (int)$state['completed'] ?>/<?= (int)$state['total'] ?></strong><span>setup steps complete</span></div>
                <div class="cv-admin-progress-track"><span style="width: <?= (int)$state['percent'] ?>%"></span></div>
            </div>

            <?php if ($state['is_complete']): ?>
                <div class="cv-admin-ready-card">
                    <span class="cv-status">READY</span>
                    <h2>Your first operating loop is in place.</h2>
                    <p>You can now work from the Admin Control Center or switch to Member View to test the attendee experience.</p>
                    <div class="cv-action-row">
                        <a class="cv-button cv-button-primary" href="/admin/">Enter Control Center</a>
                        <a class="cv-button cv-button-soft" href="/">View as Member</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="cv-admin-onboarding-steps">
            <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
            <?php foreach ($state['steps'] as $index => $step): ?>
                <a class="cv-admin-onboarding-step <?= $step['done'] ? 'is-done' : '' ?>" href="<?= coveted_e($step['href']) ?>">
                    <span class="cv-admin-step-number"><?= $step['done'] ? '✓' : str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <span class="cv-admin-step-copy">
                        <strong><?= coveted_e($step['title']) ?></strong>
                        <small><?= coveted_e($step['description']) ?></small>
                    </span>
                    <span class="cv-admin-step-arrow">→</span>
                </a>
            <?php endforeach; ?>

            <?php if (!$state['is_complete']): ?>
                <div class="cv-admin-onboarding-actions">
                    <a class="cv-button cv-button-primary" href="<?= coveted_e((string)($state['steps'][array_search(false, array_column($state['steps'], 'done'), true)]['href'] ?? '/admin/')) ?>">Continue setup</a>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="dismiss">
                        <button class="cv-button cv-button-soft" type="submit">Skip for now</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php coveted_page_end(); ?>
