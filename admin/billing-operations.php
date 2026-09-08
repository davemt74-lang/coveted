<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/billing_operations.php';
require_once dirname(__DIR__) . '/app/stripe_dunning.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
if (coveted_system_sample_mode($admin,$pdo)) {
    coveted_redirect('/admin/agent.php');
}

$schemaReady = coveted_billing_ops_ready($pdo);
$error = '';
$notice = trim((string)($_SESSION['billing_ops_notice'] ?? ''));
unset($_SESSION['billing_ops_notice']);

$q = trim((string)($_GET['q'] ?? ''));
$status = strtolower(trim((string)($_GET['status'] ?? '')));
$subject = strtolower(trim((string)($_GET['subject'] ?? '')));
$webhookStatus = strtolower(trim((string)($_GET['webhook_status'] ?? '')));
$selectedRef = trim((string)($_GET['subscription'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        if (!$schemaReady) {
            throw new RuntimeException('Billing operations requires the Service Packages and Stripe Billing migrations.');
        }
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action !== 'resync_subscription') {
            throw new InvalidArgumentException('Unsupported billing operations action.');
        }
        $ref = trim((string)($_POST['subscription_ref'] ?? ''));
        $result = coveted_billing_ops_resync_subscription($admin,$ref,$pdo);
        coveted_stripe_dunning_reconcile_subscription((array)$result['remote'],$pdo);
        $fresh = coveted_billing_ops_subscription_by_ref((string)$result['subscription']['public_id'],$pdo)
            ?: (array)$result['subscription'];
        $_SESSION['billing_ops_notice'] = 'Subscription reconciled from Stripe. ' . count((array)$result['before']['differences']) . ' difference(s) were reviewed before sync, including lifecycle recovery state.';
        coveted_redirect('/admin/billing-operations.php?subscription=' . rawurlencode((string)$fresh['public_id']));
    } catch (InvalidArgumentException|RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Billing operations action failed: ' . $e->getMessage());
        $error = 'Unable to complete that billing operation.';
    }
}

$metrics = coveted_billing_ops_metrics($pdo);
$health = coveted_billing_ops_health_label($metrics);
$lifecycleCounts = coveted_subscription_lifecycle_past_due_counts($pdo);
$graceDays = coveted_subscription_lifecycle_grace_days($pdo);
$subscriptions = [];
$webhooks = [];
$failedCheckouts = [];
$selected = null;
$remote = null;
$reconciliation = null;
$invoices = [];
$remoteError = '';

if ($schemaReady) {
    try {
        $subscriptions = coveted_billing_ops_search_subscriptions($q,$status,$subject,100,$pdo);
        $webhooks = coveted_billing_ops_webhooks($webhookStatus,75,$pdo);
        $failedCheckouts = coveted_billing_ops_failed_checkouts(25,$pdo);
        if ($selectedRef !== '') {
            $selected = coveted_billing_ops_subscription_by_ref($selectedRef,$pdo);
            if (!$selected) {
                $error = $error !== '' ? $error : 'Billing subscription not found.';
            } elseif (coveted_stripe_checkout_ready()) {
                try {
                    $remote = coveted_billing_ops_remote_subscription($selected);
                    $reconciliation = coveted_billing_ops_reconciliation($selected,$remote);
                    $invoices = coveted_billing_ops_invoices($selected,20);
                } catch (Throwable $e) {
                    error_log('Billing live detail unavailable: ' . $e->getMessage());
                    $remoteError = $e->getMessage();
                }
            }
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Billing operations workspace unavailable: ' . $e->getMessage());
        $error = $error !== '' ? $error : 'Billing operations data is temporarily unavailable.';
    }
}

$money = static function (int $cents, string $currency='USD'): string {
    $currency = strtoupper($currency ?: 'USD');
    return ($currency === 'USD' ? '$' : $currency . ' ') . number_format($cents / 100,2);
};
$date = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    $time = strtotime($value . ' UTC');
    return $time ? gmdate('M j, Y g:i A',$time) . ' UTC' : $value;
};
$statusLabel = static function (string $value): string {
    return ucwords(str_replace('_',' ',strtolower($value)));
};

coveted_page_start('Billing Operations','',true);
coveted_admin_ui_start($admin,'billing-operations','Billing Operations');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">PLATFORM · REVENUE OPERATIONS</span>
        <h1>Billing operations and reconciliation.</h1>
        <p>Review subscription state, customer payment health, webhook processing and local-versus-Stripe drift. Coveted package entitlements remain the authorization source of truth; Stripe remains the payment provider.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/subscription-lifecycle.php">Subscription Lifecycle</a>
        <a class="cv-button cv-button-soft" href="/admin/service-packages.php">Service Packages</a>
        <a class="cv-button cv-button-soft" href="/pricing.php">Public Pricing</a>
    </div>
</div>

<?php if (!$schemaReady): ?>
<div class="cv-alert cv-alert-error"><strong>Database migration required.</strong> Import the existing Service Packages and Stripe Billing migrations before using this workspace.</div>
<?php endif; ?>
<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<?php if ($schemaReady): ?>
<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Billing health</span><strong><?= coveted_e((string)$health['label']) ?></strong><small><?= coveted_e((string)$health['detail']) ?></small></div>
    <div><span>Active + trial</span><strong><?= (int)$metrics['active'] + (int)$metrics['trialing'] ?></strong><small><?= (int)$metrics['active'] ?> active · <?= (int)$metrics['trialing'] ?> trial</small></div>
    <div><span>Past due · grace</span><strong><?= (int)$lifecycleCounts['past_due_grace'] ?></strong><small><?= $graceDays ?>-day policy</small></div>
    <div><span>Past due · paused</span><strong><?= (int)$lifecycleCounts['past_due_expired'] ?></strong><small>grace exhausted</small></div>
    <div><span>Canceling</span><strong><?= (int)$metrics['scheduled_cancel'] ?></strong><small>end-of-period cancellations</small></div>
    <div><span>Webhook failures</span><strong><?= (int)$metrics['failed_webhooks_24h'] ?></strong><small>last 24 hours</small></div>
    <div><span>Stale processing</span><strong><?= (int)$metrics['stale_webhooks'] ?></strong><small>over 10 minutes</small></div>
    <div><span>Checkout failures</span><strong><?= (int)$metrics['failed_checkouts_24h'] ?></strong><small>last 24 hours</small></div>
</div>

<div class="cv-alert cv-admin-section-gap">
    <strong>Current entitlement policy:</strong> <code>trialing</code> and <code>active</code> subscriptions grant paid access during a valid period. <code>past_due</code> can retain access only during the configured <?= $graceDays ?>-day grace window. After grace, and for <code>paused</code>, <code>cancelled</code> or <code>expired</code>, subscription-backed paid entitlements are unavailable. Admin package assignments still resolve first.
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">SUBSCRIPTIONS</span><h2>Customer and partner billing</h2></div><span class="cv-status">Local source of truth</span></div>
    <form method="get" class="cv-admin-dashboard-grid">
        <label>Search <input name="q" value="<?= coveted_e($q) ?>" placeholder="name, email, business, sub_ or cus_"></label>
        <label>Status
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach (['trialing','active','past_due','paused','cancelled','expired'] as $option): ?>
                <option value="<?= coveted_e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= coveted_e($statusLabel($option)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Subject
            <select name="subject">
                <option value="">Members + partners</option>
                <option value="user" <?= $subject === 'user' ? 'selected' : '' ?>>Members</option>
                <option value="business" <?= $subject === 'business' ? 'selected' : '' ?>>Partner businesses</option>
            </select>
        </label>
        <div class="cv-action-row"><button class="cv-button cv-button-primary" type="submit">Filter</button><a class="cv-button cv-button-soft" href="/admin/billing-operations.php">Reset</a></div>
    </form>

    <div class="cv-admin-list cv-admin-section-gap">
        <?php if (!$subscriptions): ?><div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>No subscriptions match this view.</strong></div></div><?php endif; ?>
        <?php foreach ($subscriptions as $row):
            $rowLifecycle = coveted_subscription_lifecycle_access_state($row,$pdo);
        ?>
        <a class="cv-admin-list-row" href="/admin/billing-operations.php?<?= http_build_query(array_filter(['q'=>$q,'status'=>$status,'subject'=>$subject,'subscription'=>(string)$row['public_id']],static fn($v)=>$v!=='')) ?>">
            <div class="cv-admin-list-copy">
                <strong><?= coveted_e(coveted_billing_ops_subject_label($row)) ?></strong>
                <small><?= coveted_e((string)$row['package_name']) ?> · <?= coveted_e((string)$rowLifecycle['label']) ?> · <?= coveted_e((string)$row['provider_subscription_ref']) ?></small>
            </div>
            <div class="cv-admin-list-meta">
                <strong><?= coveted_e(coveted_service_format_price($row['monthly_price_cents'] !== null ? (int)$row['monthly_price_cents'] : null,(string)$row['currency'])) ?></strong>
                <small><?= $rowLifecycle['grace_until'] ? 'Grace → ' . coveted_e($date((string)$rowLifecycle['grace_until'])) : coveted_e($date((string)($row['current_period_end'] ?? ''))) ?></small>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($selected):
    $selectedLifecycle = coveted_subscription_lifecycle_access_state($selected,$pdo);
?>
<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">RECONCILIATION</span><h2><?= coveted_e(coveted_billing_ops_subject_label($selected)) ?></h2></div>
        <span class="cv-status"><?= coveted_e((string)$selectedLifecycle['label']) ?></span>
    </div>
    <div class="cv-admin-dashboard-grid">
        <div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>Package</strong><small><?= coveted_e((string)$selected['package_name']) ?> · <code><?= coveted_e((string)$selected['package_key']) ?></code></small></div></div>
        <div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>Stripe subscription</strong><small><code><?= coveted_e((string)$selected['provider_subscription_ref']) ?></code></small></div></div>
        <div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>Stripe customer</strong><small><code><?= coveted_e((string)($selected['provider_customer_ref'] ?? '—')) ?></code></small></div></div>
        <div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>Current period</strong><small><?= coveted_e($date((string)($selected['current_period_start'] ?? ''))) ?> → <?= coveted_e($date((string)($selected['current_period_end'] ?? ''))) ?></small></div></div>
        <?php if ($selectedLifecycle['past_due_since']): ?><div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>Dunning episode</strong><small>Started <?= coveted_e($date((string)$selectedLifecycle['past_due_since'])) ?><?= $selectedLifecycle['grace_until'] ? ' · grace through ' . coveted_e($date((string)$selectedLifecycle['grace_until'])) : '' ?></small></div></div><?php endif; ?>
    </div>

    <?php if (!coveted_stripe_checkout_ready()): ?>
        <div class="cv-alert cv-alert-error cv-admin-section-gap">Stripe live reconciliation is unavailable until the private Stripe secret key is configured.</div>
    <?php elseif ($remoteError !== ''): ?>
        <div class="cv-alert cv-alert-error cv-admin-section-gap"><strong>Stripe live check failed.</strong> <?= coveted_e($remoteError) ?></div>
    <?php elseif ($reconciliation): ?>
        <div class="cv-alert cv-admin-section-gap">
            <strong><?= $reconciliation['in_sync'] ? 'Local record matches Stripe.' : count($reconciliation['differences']) . ' reconciliation difference(s) detected.' ?></strong>
            <?php if (!$reconciliation['in_sync']): ?> Use the audited resync below to copy current Stripe subscription state into Coveted. This does not change the Stripe subscription itself.<?php endif; ?>
        </div>
        <?php if (!$reconciliation['in_sync']): ?>
        <div class="cv-admin-list">
            <?php foreach ($reconciliation['differences'] as $difference): ?>
            <div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong><?= coveted_e($statusLabel((string)$difference['field'])) ?></strong><small>Local: <code><?= coveted_e((string)$difference['local']) ?></code> · Stripe: <code><?= coveted_e((string)$difference['remote']) ?></code></small></div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="post" class="cv-action-row cv-admin-section-gap">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="resync_subscription">
            <input type="hidden" name="subscription_ref" value="<?= coveted_e((string)$selected['public_id']) ?>">
            <button class="cv-button cv-button-primary" type="submit">Resync from Stripe</button>
        </form>
    <?php endif; ?>

    <div class="cv-admin-panel-head cv-admin-section-gap"><div><span class="cv-eyebrow">INVOICES</span><h2>Recent payment history</h2></div><span class="cv-status">Stripe live data</span></div>
    <div class="cv-admin-list">
        <?php if (!$invoices): ?><div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>No invoice history loaded.</strong><small>Invoices appear here when Stripe live access is available for this customer.</small></div></div><?php endif; ?>
        <?php foreach ($invoices as $invoice): ?>
        <div class="cv-admin-list-row">
            <div class="cv-admin-list-copy">
                <strong><?= coveted_e((string)($invoice['number'] ?: $invoice['id'])) ?></strong>
                <small><?= coveted_e($statusLabel((string)$invoice['status'])) ?> · <?= coveted_e((string)$invoice['billing_reason']) ?> · <?= coveted_e($date((string)$invoice['created'])) ?></small>
            </div>
            <div class="cv-admin-list-meta">
                <strong><?= coveted_e($money((int)$invoice['amount_due'],(string)$invoice['currency'])) ?></strong>
                <small><?= (int)$invoice['amount_remaining'] > 0 ? coveted_e($money((int)$invoice['amount_remaining'],(string)$invoice['currency']) . ' remaining') : coveted_e($money((int)$invoice['amount_paid'],(string)$invoice['currency']) . ' paid') ?></small>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">WEBHOOK HEALTH</span><h2>Stripe events</h2></div></div>
    <form method="get" class="cv-action-row">
        <?php if ($selectedRef !== ''): ?><input type="hidden" name="subscription" value="<?= coveted_e($selectedRef) ?>"><?php endif; ?>
        <select name="webhook_status">
            <option value="">All webhook states</option>
            <?php foreach (['failed','processing','received','processed'] as $option): ?>
            <option value="<?= coveted_e($option) ?>" <?= $webhookStatus === $option ? 'selected' : '' ?>><?= coveted_e($statusLabel($option)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="cv-button cv-button-soft" type="submit">Filter events</button>
    </form>
    <div class="cv-admin-list cv-admin-section-gap">
        <?php foreach ($webhooks as $event): ?>
        <div class="cv-admin-list-row">
            <div class="cv-admin-list-copy"><strong><?= coveted_e((string)$event['event_type']) ?></strong><small><code><?= coveted_e((string)$event['event_ref']) ?></code> · attempt <?= (int)$event['attempt_count'] ?> · <?= coveted_e($date((string)$event['received_at'])) ?></small><?php if (!empty($event['last_error'])): ?><small><?= coveted_e((string)$event['last_error']) ?></small><?php endif; ?></div>
            <span class="cv-status"><?= coveted_e($statusLabel((string)$event['status'])) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">CHECKOUT HEALTH</span><h2>Recent failed checkouts</h2></div></div>
    <div class="cv-admin-list">
        <?php if (!$failedCheckouts): ?><div class="cv-admin-list-row"><div class="cv-admin-list-copy"><strong>No failed checkout records.</strong></div></div><?php endif; ?>
        <?php foreach ($failedCheckouts as $checkout): ?>
        <div class="cv-admin-list-row">
            <div class="cv-admin-list-copy">
                <strong><?= coveted_e((string)($checkout['business_name'] ?: ($checkout['user_name'] ?: $checkout['user_email']))) ?></strong>
                <small><?= coveted_e((string)$checkout['package_name']) ?> · <?= coveted_e($date((string)$checkout['updated_at'])) ?></small>
                <?php if (!empty($checkout['last_error'])): ?><small><?= coveted_e((string)$checkout['last_error']) ?></small><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
</div>
<?php endif; ?>
<?php
coveted_admin_ui_end();
coveted_page_end();