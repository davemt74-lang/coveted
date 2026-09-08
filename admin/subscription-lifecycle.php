<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/billing_operations.php';
require_once dirname(__DIR__) . '/app/subscription_lifecycle.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
if (coveted_system_sample_mode($admin,$pdo)) {
    coveted_redirect('/admin/agent.php');
}

$error = '';
$notice = trim((string)($_SESSION['subscription_lifecycle_notice'] ?? ''));
unset($_SESSION['subscription_lifecycle_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action !== 'save_grace_policy') {
            throw new InvalidArgumentException('Unsupported subscription lifecycle action.');
        }
        $raw = trim((string)($_POST['grace_days'] ?? ''));
        if ($raw === '' || preg_match('/^\d{1,2}$/',$raw) !== 1) {
            throw new InvalidArgumentException('Enter a grace period from 0 to 30 days.');
        }
        coveted_subscription_lifecycle_set_grace_days($admin,(int)$raw,$pdo);
        $_SESSION['subscription_lifecycle_notice'] = 'Billing grace policy updated to ' . (int)$raw . ' day' . ((int)$raw === 1 ? '' : 's') . '.';
        coveted_redirect('/admin/subscription-lifecycle.php');
    } catch (InvalidArgumentException|RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Subscription lifecycle setting failed: ' . $e->getMessage());
        $error = 'Unable to save the subscription lifecycle policy.';
    }
}

$graceDays = coveted_subscription_lifecycle_grace_days($pdo);
$counts = coveted_subscription_lifecycle_past_due_counts($pdo);
$pastDue = [];
try {
    if (coveted_billing_ops_ready($pdo)) {
        $pastDue = coveted_billing_ops_search_subscriptions('','past_due','',250,$pdo);
    }
} catch (Throwable $e) {
    error_log('Subscription lifecycle past-due list unavailable: ' . $e->getMessage());
}

coveted_page_start('Subscription Lifecycle','',true);
coveted_admin_ui_start($admin,'subscription-lifecycle','Subscription Lifecycle');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">PLATFORM · BILLING POLICY</span>
        <h1>Payment recovery without duplicate subscriptions.</h1>
        <p>Control the temporary access window after a subscription becomes past due. Stripe reports payment state; Coveted's provider-neutral lifecycle policy decides whether the paid package remains entitled.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/billing-operations.php">Billing Operations</a>
        <a class="cv-button cv-button-soft" href="/admin/service-packages.php">Service Packages</a>
    </div>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Grace policy</span><strong><?= $graceDays ?> days</strong><small>0 = immediate paid-access pause</small></div>
    <div><span>Past due · grace</span><strong><?= (int)$counts['past_due_grace'] ?></strong><small>paid entitlements temporarily retained</small></div>
    <div><span>Past due · expired</span><strong><?= (int)$counts['past_due_expired'] ?></strong><small>paid entitlements paused</small></div>
    <div><span>Recovery</span><strong>Automatic</strong><small>restores when canonical status returns active/trialing</small></div>
</div>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">POLICY</span><h2>Past-due grace period</h2></div><span class="cv-status">System Admin</span></div>
    <form method="post" class="cv-stack">
        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
        <input type="hidden" name="action" value="save_grace_policy">
        <label>Grace days
            <input name="grace_days" type="number" min="0" max="30" required value="<?= $graceDays ?>">
        </label>
        <p><small>Default is 7 days. Set 0 for immediate loss of paid entitlements after payment state becomes past due. This does not cancel the provider subscription or delete member/partner data.</small></p>
        <button class="cv-button cv-button-primary" type="submit">Save grace policy</button>
    </form>
</section>

<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ACCESS RULE</span><h2>Lifecycle states</h2></div></div>
    <div class="cv-admin-list">
        <div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>Trialing / Active</strong><small>Paid package entitlements are available during a valid billing period.</small></div><span class="cv-status">Access</span></div>
        <div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>Past due · grace</strong><small>Paid entitlements remain temporarily available until the configured grace deadline.</small></div><span class="cv-status">Temporary</span></div>
        <div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>Past due · expired</strong><small>Paid entitlements pause. Account, business and data remain intact.</small></div><span class="cv-status">Paused</span></div>
        <div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>Paused / Cancelled / Expired</strong><small>No subscription-backed paid entitlements. Admin package overrides still resolve first.</small></div><span class="cv-status">No paid access</span></div>
        <div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>Recovered</strong><small>When provider state synchronizes back to active/trialing, the paid package is restored automatically.</small></div><span class="cv-status">Automatic</span></div>
    </div>
</section>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">DUNNING QUEUE</span><h2>Past-due subscriptions</h2></div><span class="cv-status"><?= count($pastDue) ?> records</span></div>
    <div class="cv-admin-list">
        <?php if (!$pastDue): ?><div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>No past-due subscriptions.</strong><small>There is currently no dunning queue.</small></div></div><?php endif; ?>
        <?php foreach ($pastDue as $row):
            $state = coveted_subscription_lifecycle_access_state($row,$pdo);
        ?>
            <a class="cv-admin-list-row" href="/admin/billing-operations.php?subscription=<?= coveted_e(rawurlencode((string)$row['public_id'])) ?>">
                <div class="cv-admin-list-copy">
                    <strong><?= coveted_e(coveted_billing_ops_subject_label($row)) ?></strong>
                    <small><?= coveted_e((string)$row['package_name']) ?> · <?= coveted_e((string)$state['label']) ?></small>
                    <?php if ($state['past_due_since']): ?><small>Failure episode began <?= coveted_e((string)$state['past_due_since']) ?> UTC</small><?php endif; ?>
                </div>
                <div class="cv-admin-list-meta">
                    <strong><?= $state['state'] === 'past_due_grace' ? (int)$state['days_remaining'] . ' day' . ((int)$state['days_remaining'] === 1 ? '' : 's') . ' left' : 'Access paused' ?></strong>
                    <?php if ($state['grace_until']): ?><small>Grace through <?= coveted_e((string)$state['grace_until']) ?> UTC</small><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<div class="cv-alert cv-admin-section-gap">
    <strong>Billing ownership remains separate from access.</strong> An open past-due or paused subscription blocks a second checkout for the same billing subject. Resolve that subscription through billing management or Admin reconciliation instead of creating duplicate provider billing.
</div>
<?php coveted_admin_ui_end(); coveted_page_end(); ?>
