<?php
declare(strict_types=1);

require_once __DIR__ . '/app/stripe_billing.php';
require_once __DIR__ . '/app/public_packages.php';

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
        $packages = array_values(array_filter(
            coveted_service_packages(true,$pdo),
            static fn(array $package): bool => coveted_service_package_available_to_subject((int)$package['id'],$billingSubjectType,$pdo)
        ));
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
$accessSubjectSubscriptions = array_values(array_filter(
    $subjectSubscriptions,
    static fn(array $row): bool => coveted_subscription_lifecycle_allows_access($row,$pdo)
));
$openSubjectSubscriptions = array_values(array_filter(
    $subjectSubscriptions,
    static fn(array $row): bool => coveted_subscription_lifecycle_is_open($row)
));
$openStripeSubscriptions = array_values(array_filter(
    $openSubjectSubscriptions,
    static fn(array $row): bool => (string)$row['provider'] === 'stripe'
));
$hasOverrideAndPaid = $adminOverride !== null && $openSubjectSubscriptions !== [];
$checkoutCancelled = (string)($_GET['checkout'] ?? '') === 'cancelled';

$attentionSubscription = null;
foreach ($subjectSubscriptions as $row) {
    $message = coveted_subscription_lifecycle_customer_message($row,$pdo);
    if ($message['title'] !== '') {
        $attentionSubscription = ['subscription'=>$row,'message'=>$message,'state'=>coveted_subscription_lifecycle_access_state($row,$pdo)];
        if (str_starts_with((string)$message['state'],'past_due')) {
            break;
        }
    }
}

coveted_page_start('Billing & Plan','');
?>
<section class="cv-section">
    <div class="cv-section-head">
        <div>
            <span class="cv-eyebrow">ACCOUNT · SERVICE</span>
            <h1>Billing & Plan</h1>
            <p>See the package governing your Coveted access, resolve payment issues, subscribe through hosted Stripe Checkout, and manage billing without exposing payment-card data to Coveted.</p>
        </div>
        <div class="cv-action-row">
            <?php if ($stripeCustomer !== null && $stripeReady): ?>
                <form method="post" action="/billing-action.php">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="portal">
                    <input type="hidden" name="business_ref" value="<?= coveted_e((string)($business['public_id'] ?? '')) ?>">
                    <button class="cv-button cv-button-primary" type="submit"><?= $attentionSubscription && str_starts_with((string)$attentionSubscription['message']['state'],'past_due') ? 'Resolve billing' : 'Manage billing' ?></button>
                </form>
            <?php endif; ?>
            <?php if (coveted_is_system_admin($user)): ?>
                <a class="cv-button cv-button-soft" href="/admin/service-packages.php">Manage service packages</a>
                <a class="cv-button cv-button-soft" href="/admin/billing-operations.php">Billing operations</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$schemaReady): ?>
        <div class="cv-alert cv-alert-error"><strong>Database migration required.</strong> Import <code>database/migrations/20260907_service_packages_billing.sql</code> before using Billing & Plan.</div>
    <?php elseif (!$stripeSchemaReady): ?>
        <div class="cv-alert cv-alert-error"><strong>Stripe Billing migration required.</strong> Import <code>database/migrations/20260908_stripe_billing.sql</code> before enabling payments.</div>
    <?php elseif (!$stripeReady): ?>
        <div class="cv-alert"><strong>Stripe is not live yet.</strong> Add the private Stripe keys to production <code>config.php</code>, enable Stripe, and register <code>/api/stripe-webhook.php</code>.</div>
    <?php endif; ?>
    <?php if ($checkoutCancelled): ?><div class="cv-alert">Checkout was cancelled. No Coveted package or subscription state was changed.</div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

    <?php if ($attentionSubscription):
        $attentionMessage = (array)$attentionSubscription['message'];
        $attentionState = (array)$attentionSubscription['state'];
    ?>
        <div class="cv-alert <?= $attentionMessage['state'] === 'past_due_expired' ? 'cv-alert-error' : '' ?>">
            <strong><?= coveted_e((string)$attentionMessage['title']) ?>.</strong>
            <?= coveted_e((string)$attentionMessage['message']) ?>
            <?php if (!empty($attentionState['grace_until'])): ?> Grace ends <?= coveted_e((string)$attentionState['grace_until']) ?> UTC.<?php endif; ?>
        </div>
    <?php endif; ?>

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
        $effectiveSubscriptionState = is_array($effective['subscription_state'] ?? null) ? (array)$effective['subscription_state'] : null;
    ?>
        <div class="cv-admin-metric-grid cv-admin-section-gap">
            <div><span>Current package</span><strong><?= coveted_e((string)$current['name']) ?></strong><small><?= coveted_e(coveted_service_format_price($current['monthly_price_cents'] !== null ? (int)$current['monthly_price_cents'] : null,(string)$current['currency'])) ?></small></div>
            <div><span>Access source</span><strong><?= coveted_e($sourceLabels[$source] ?? ucwords(str_replace('_',' ',$source))) ?></strong><small><?= $effectiveSubscriptionState ? coveted_e((string)$effectiveSubscriptionState['label']) : ($source === 'default' ? 'platform default' : 'billing bypass set by System Admin') ?></small></div>
            <div><span>Entitlements</span><strong><?= count($entitlements) ?></strong><small>package capabilities</small></div>
            <div><span>Billing subject</span><strong><?= coveted_e($business ? (string)$business['name'] : 'My account') ?></strong><small><?= $business ? 'partner context' : 'personal context' ?></small></div>
        </div>

        <?php if ($hasOverrideAndPaid): ?>
            <div class="cv-alert cv-alert-error"><strong>Admin package override + open subscription.</strong> Your Admin-granted package governs access, but this billing subject still has a provider subscription requiring billing management. The override does not cancel provider billing.</div>
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
                    <div class="cv-admin-empty"><strong>No explicit entitlements.</strong><span>This package currently has no explicit premium feature gates.</span></div>
                <?php endif; ?>
            </section>

            <section class="cv-panel">
                <span class="cv-eyebrow">SUBSCRIPTION HISTORY</span>
                <h2>Payment records</h2>
                <p>Provider state is synchronized into Coveted's canonical subscription record. Payment failure can retain paid access only during the configured grace window.</p>
                <div class="cv-admin-list">
                    <?php if (!$subscriptions): ?><div class="cv-admin-empty"><strong>No subscription records.</strong><span>Your package may be the default or an Admin assignment.</span></div><?php endif; ?>
                    <?php foreach ($subscriptions as $row):
                        $rowState = coveted_subscription_lifecycle_access_state($row,$pdo);
                    ?>
                        <div class="cv-admin-list-row">
                            <span class="cv-admin-list-copy">
                                <strong><?= coveted_e((string)$row['package_name']) ?></strong>
                                <small><?= coveted_e((string)$rowState['label']) ?> · <?= coveted_e(ucfirst((string)$row['provider'])) ?></small>
                                <?php if (!empty($rowState['grace_until'])): ?><small>Grace ends <?= coveted_e((string)$rowState['grace_until']) ?> UTC</small><?php endif; ?>
                                <?php if (!empty($row['current_period_end'])): ?><small>Billing period ends <?= coveted_e((string)$row['current_period_end']) ?> UTC<?= !empty($row['cancel_at_period_end']) ? ' · cancellation scheduled' : '' ?></small><?php endif; ?>
                            </span>
                            <span class="cv-status"><?= coveted_e((string)$rowState['state']) ?></span>
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
                    $canCheckout = $isPaid && $stripeReady && $adminOverride === null && !$openSubjectSubscriptions;
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
                        <?php elseif ($isCurrent && $accessSubjectSubscriptions): ?>
                            <span class="cv-status">Active access</span>
                        <?php elseif ($isPaid && $adminOverride !== null): ?>
                            <span class="cv-status">Admin access</span>
                        <?php elseif ($isPaid && $openSubjectSubscriptions): ?>
                            <span class="cv-status"><?= $openStripeSubscriptions ? 'Manage billing' : 'Existing subscription' ?></span>
                        <?php elseif ($isPaid && !$stripeReady): ?>
                            <span class="cv-status">Not configured</span>
                        <?php else: ?>
                            <span class="cv-status">Available</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p><small>Checkout is hosted by Stripe. Webhooks synchronize subscription status and payment recovery into Coveted. System Admin package assignments still resolve before payment state.</small></p>
        </section>
    <?php endif; ?>
</section>
<?php coveted_page_end(); ?>
