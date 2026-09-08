<?php
declare(strict_types=1);

require_once __DIR__ . '/app/stripe_billing.php';

$user = coveted_require_user();
$pdo = coveted_db();
$schemaReady = coveted_service_packages_schema_available($pdo);
$stripeSchemaReady = $schemaReady && coveted_stripe_schema_available($pdo);
$stripeReady = $stripeSchemaReady && coveted_stripe_webhook_ready();
$error = trim((string)($_SESSION['billing_error'] ?? ''));
$notice = trim((string)($_SESSION['billing_notice'] ?? ''));
unset($_SESSION['billing_error'],$_SESSION['billing_notice']);
$business = null;
$businesses = [];
$businessRef = trim((string)($_GET['business'] ?? ''));

if (!coveted_is_system_admin($user)) {
    try {
        $businesses = coveted_businesses_for_actor($user);
        if ($businessRef !== '') {
            $business = coveted_business_resolve_context($user,$businessRef);
        }
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to load that partner billing context.';
    }
} elseif ($businessRef !== '') {
    $candidate = coveted_business_by_ref($businessRef);
    if ($candidate && (string)$candidate['status'] !== 'archived') {
        $business = $candidate;
    } else {
        $error = 'Partner business not found.';
    }
}

$effective = null;
$packages = [];
$subscriptions = [];
$subjectSubscriptions = [];
$adminOverride = null;
$stripeCustomer = null;
$billingSubjectType = $business ? 'business' : 'user';
$billingSubjectId = $business ? (int)$business['id'] : (int)$user['id'];
if ($schemaReady && $error === '') {
    try {
        $effective = coveted_service_effective_package($user,$business ? (int)$business['id'] : null,$pdo);
        $packages = coveted_service_packages(true,$pdo);
        $subjectSubscriptions = coveted_service_subscriptions_for_subject($billingSubjectType,$billingSubjectId,false,$pdo);
        $subscriptions = $subjectSubscriptions;
        if ($business) {
            $subscriptions = array_merge(
                $subjectSubscriptions,
                coveted_service_subscriptions_for_subject('user',(int)$user['id'],false,$pdo)
            );
        }
        if (in_array((string)($effective['source'] ?? ''),['user_override','partner_override','user_type_override'],true)) {
            $adminOverride = $effective['assignment'];
        }
        if ($stripeSchemaReady) {
            $stripeCustomer = coveted_stripe_customer_for_subject($billingSubjectType,$billingSubjectId,$pdo);
        }
    } catch (Throwable $e) {
        error_log('Billing & Plan unavailable: ' . $e->getMessage());
        $error = 'Billing & Plan is temporarily unavailable.';
    }
}

$sourceLabels = [
    'user_override' => 'Admin user override',
    'partner_override' => 'Admin partner override',
    'user_type_override' => 'Admin user-type override',
    'subscription' => 'Paid subscription',
    'default' => 'Default package',
];
$activeSubjectSubscriptions = array_values(array_filter($subjectSubscriptions,static fn(array $row): bool => in_array((string)$row['status'],['trialing','active'],true)));
$activeStripeSubscriptions = array_values(array_filter($activeSubjectSubscriptions,static fn(array $row): bool => (string)$row['provider']==='stripe'));
$hasOverrideAndPaid = $adminOverride !== null && $activeSubjectSubscriptions !== [];
$checkoutCancelled = (string)($_GET['checkout'] ?? '') === 'cancelled';

coveted_page_start('Billing & Plan','');
?>
<section class="cv-section">
    <div class="cv-section-head">
        <div>
            <span class="cv-eyebrow">ACCOUNT · SERVICE</span>
            <h1>Billing & Plan</h1>
            <p>See the package governing your Coveted access, subscribe through hosted Stripe Checkout, and manage an existing billing account without exposing payment-card data to Coveted.</p>
        </div>
        <div class="cv-action-row">
            <?php if ($stripeCustomer !== null && $stripeReady): ?>
                <form method="post" action="/billing-action.php">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="portal">
                    <input type="hidden" name="business_ref" value="<?= coveted_e((string)($business['public_id'] ?? '')) ?>">
                    <button class="cv-button cv-button-primary" type="submit">Manage billing</button>
                </form>
            <?php endif; ?>
            <?php if (coveted_is_system_admin($user)): ?>
                <a class="cv-button cv-button-soft" href="/admin/service-packages.php">Manage service packages</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$schemaReady): ?>
        <div class="cv-alert cv-alert-error"><strong>Database migration required.</strong> Import <code>database/migrations/20260907_service_packages_billing.sql</code> before using Billing & Plan.</div>
    <?php elseif (!$stripeSchemaReady): ?>
        <div class="cv-alert cv-alert-error"><strong>Stripe Billing migration required.</strong> Import <code>database/migrations/20260908_stripe_billing.sql</code> before enabling payments.</div>
    <?php elseif (!$stripeReady): ?>
        <div class="cv-alert"><strong>Stripe is not live yet.</strong> Add <code>billing.stripe.secret_key</code> and <code>billing.stripe.webhook_secret</code> to the private production <code>config.php</code>, enable Stripe, and register <code>/api/stripe-webhook.php</code> in Stripe.</div>
    <?php endif; ?>
    <?php if ($checkoutCancelled): ?><div class="cv-alert">Checkout was cancelled. No Coveted package or subscription state was changed.</div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

    <?php if ($businesses): ?>
        <section class="cv-panel">
            <span class="cv-eyebrow">PARTNER CONTEXT</span>
            <h2>Business package</h2>
            <p>Partner-level Admin assignments and business subscriptions apply only when Coveted is operating in that business context.</p>
            <div class="cv-action-row">
                <a class="cv-button <?= $business === null ? 'cv-button-primary' : 'cv-button-soft' ?>" href="/billing.php">My account</a>
                <?php foreach ($businesses as $row): ?>
                    <a class="cv-button <?= $business && (int)$business['id'] === (int)$row['id'] ? 'cv-button-primary' : 'cv-button-soft' ?>" href="/billing.php?business=<?= coveted_e(rawurlencode((string)$row['public_id'])) ?>"><?= coveted_e((string)$row['name']) ?></a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($schemaReady && $effective):
        $current = (array)$effective['package'];
        $entitlements = (array)$effective['entitlements'];
        $source = (string)$effective['source'];
    ?>
        <div class="cv-admin-metric-grid cv-admin-section-gap">
            <div><span>Current package</span><strong><?= coveted_e((string)$current['name']) ?></strong><small><?= coveted_e(coveted_service_format_price($current['monthly_price_cents'] !== null ? (int)$current['monthly_price_cents'] : null,(string)$current['currency'])) ?></small></div>
            <div><span>Access source</span><strong><?= coveted_e($sourceLabels[$source] ?? ucwords(str_replace('_',' ',$source))) ?></strong><small><?= $source === 'subscription' ? 'payment-backed access' : ($source === 'default' ? 'platform default' : 'billing bypass set by System Admin') ?></small></div>
            <div><span>Entitlements</span><strong><?= count($entitlements) ?></strong><small>package capabilities</small></div>
            <div><span>Billing subject</span><strong><?= coveted_e($business ? (string)$business['name'] : 'My account') ?></strong><small><?= $business ? 'partner context' : 'personal context' ?></small></div>
        </div>

        <?php if ($hasOverrideAndPaid): ?>
            <div class="cv-alert cv-alert-error"><strong>Admin package override + active subscription.</strong> Your Admin-granted package governs access, but this billing subject also has an active/trial subscription. The override does not automatically cancel provider billing. Use Manage billing to review the paid subscription.</div>
        <?php elseif ($adminOverride !== null): ?>
            <div class="cv-alert"><strong>Payment bypass active.</strong> A System Admin assigned this package directly. Paid checkout is disabled while the assignment remains active.</div>
        <?php endif; ?>

        <div class="cv-admin-dashboard-grid cv-admin-section-gap">
            <section class="cv-panel">
                <span class="cv-eyebrow">CURRENT ACCESS</span>
                <h2><?= coveted_e((string)$current['name']) ?></h2>
                <p><?= coveted_e((string)($current['description'] ?? '')) ?></p>
                <dl class="cv-admin-event-definition-list">
                    <div><dt>Package key</dt><dd><code><?= coveted_e((string)$current['package_key']) ?></code></dd></div>
                    <div><dt>Monthly price</dt><dd><?= coveted_e(coveted_service_format_price($current['monthly_price_cents'] !== null ? (int)$current['monthly_price_cents'] : null,(string)$current['currency'])) ?></dd></div>
                    <div><dt>Source</dt><dd><?= coveted_e($sourceLabels[$source] ?? $source) ?></dd></div>
                </dl>
                <?php if ($entitlements): ?>
                    <div class="cv-admin-list">
                        <?php foreach ($entitlements as $key => $value): ?>
                            <div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$key) ?></strong><small><?= coveted_e((string)$value) ?></small></span><span class="cv-status">Included</span></div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="cv-admin-empty"><strong>No explicit entitlements.</strong><span>Existing Coveted features remain unchanged until they intentionally adopt entitlement gating.</span></div>
                <?php endif; ?>
            </section>

            <section class="cv-panel">
                <span class="cv-eyebrow">SUBSCRIPTION HISTORY</span>
                <h2>Payment records</h2>
                <p>Stripe state is synchronized into Coveted's provider-neutral subscription record. Authorization still resolves through the canonical package service.</p>
                <div class="cv-admin-list">
                    <?php if (!$subscriptions): ?><div class="cv-admin-empty"><strong>No subscription records.</strong><span>Your package may be the default or an Admin assignment.</span></div><?php endif; ?>
                    <?php foreach ($subscriptions as $row): ?>
                        <div class="cv-admin-list-row">
                            <span class="cv-admin-list-copy">
                                <strong><?= coveted_e((string)$row['package_name']) ?></strong>
                                <small><?= coveted_e(ucwords(str_replace('_',' ',(string)$row['status']))) ?> · <?= coveted_e(ucfirst((string)$row['provider'])) ?></small>
                                <?php if (!empty($row['current_period_end'])): ?><small>Current period ends <?= coveted_e((string)$row['current_period_end']) ?> UTC<?= !empty($row['cancel_at_period_end']) ? ' · cancels at period end' : '' ?></small><?php endif; ?>
                            </span>
                            <span class="cv-status"><?= coveted_e((string)$row['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <section class="cv-panel cv-admin-section-gap">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PACKAGES</span><h2>Available service packages</h2></div><span class="cv-status"><?= $stripeReady ? 'Stripe Checkout ready' : 'Checkout disabled' ?></span></div>
            <div class="cv-admin-list">
                <?php foreach ($packages as $package):
                    $included = coveted_service_entitlements_for_package((int)$package['id'],$pdo);
                    $isCurrent = (int)$package['id'] === (int)$current['id'];
                    $isPaid = $package['monthly_price_cents'] !== null && (int)$package['monthly_price_cents'] > 0;
                    $canCheckout = $isPaid && $stripeReady && $adminOverride === null && !$activeSubjectSubscriptions;
                ?>
                    <div class="cv-admin-list-row">
                        <span class="cv-admin-list-copy">
                            <strong><?= coveted_e((string)$package['name']) ?><?= $isCurrent ? ' · Current' : '' ?></strong>
                            <small><?= coveted_e(coveted_service_format_price($package['monthly_price_cents'] !== null ? (int)$package['monthly_price_cents'] : null,(string)$package['currency'])) ?></small>
                            <small><?= coveted_e((string)($package['description'] ?? '')) ?></small>
                            <?php if ($included): ?><small><?= coveted_e(implode(' · ',array_keys($included))) ?></small><?php endif; ?>
                        </span>
                        <?php if ($canCheckout && !$isCurrent): ?>
                            <form method="post" action="/billing-action.php" onsubmit="return confirm('Continue to secure Stripe Checkout for this monthly Coveted package?');">
                                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                <input type="hidden" name="action" value="checkout">
                                <input type="hidden" name="package_id" value="<?= (int)$package['id'] ?>">
                                <input type="hidden" name="business_ref" value="<?= coveted_e((string)($business['public_id'] ?? '')) ?>">
                                <button class="cv-button cv-button-primary" type="submit">Subscribe</button>
                            </form>
                        <?php elseif ($isCurrent): ?>
                            <span class="cv-status">Active</span>
                        <?php elseif ($isPaid && $adminOverride !== null): ?>
                            <span class="cv-status">Admin access</span>
                        <?php elseif ($isPaid && $activeSubjectSubscriptions): ?>
                            <span class="cv-status"><?= $activeStripeSubscriptions ? 'Manage billing' : 'Active subscription' ?></span>
                        <?php elseif ($isPaid && !$stripeReady): ?>
                            <span class="cv-status">Not configured</span>
                        <?php else: ?>
                            <span class="cv-status">Available</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p><small>Checkout is hosted by Stripe. Webhooks synchronize subscription status, renewals, payment failures and cancellations into <code>billing_subscriptions</code>. System Admin package assignments still resolve before payment state.</small></p>
        </section>
    <?php endif; ?>
</section>
<?php coveted_page_end(); ?>
