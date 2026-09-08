<?php
declare(strict_types=1);

require_once __DIR__ . '/app/stripe_billing.php';
require_once __DIR__ . '/app/public_packages.php';

$user = coveted_require_user();
$pdo = coveted_db();
$packageKey = strtolower(trim((string)($_GET['package'] ?? '')));
$businessRef = trim((string)($_GET['business'] ?? ''));
$error = '';
$package = null;
$business = null;
$subjectType = 'user';
$subjectId = (int)$user['id'];
$subjectLabel = (string)$user['display_name'];
$activeSubscriptions = [];
$effective = null;

try {
    if (!coveted_service_packages_schema_available($pdo)) {
        throw new RuntimeException('Service Packages are not installed yet.');
    }
    if ($packageKey === '') {
        throw new InvalidArgumentException('Choose a service package from Pricing.');
    }

    $package = coveted_service_package($packageKey,$pdo);
    if (!$package || (int)$package['is_active'] !== 1 || !coveted_service_package_is_public($package,$pdo)) {
        throw new InvalidArgumentException('That service package is not currently available.');
    }

    $packageSubject = coveted_service_package_subject($package,$pdo);
    if ($packageSubject === 'business') {
        if ($businessRef === '') {
            coveted_redirect('/partner-onboarding.php?package=' . rawurlencode($packageKey));
        }
        $business = coveted_stripe_actor_business($user,$businessRef);
        if (!$business) {
            throw new InvalidArgumentException('Choose a partner business for this package.');
        }
        $subjectType = 'business';
        $subjectId = (int)$business['id'];
        $subjectLabel = (string)$business['name'];
    } elseif ($packageSubject !== 'user') {
        throw new InvalidArgumentException('This package requires a Coveted-assisted setup rather than self-service checkout.');
    } elseif ($businessRef !== '') {
        throw new InvalidArgumentException('Member packages are purchased for your personal Coveted account, not a business.');
    }

    if (!coveted_service_package_available_to_subject((int)$package['id'],$subjectType,$pdo)) {
        throw new InvalidArgumentException('That package is not available for this billing account.');
    }

    $activeSubscriptions = coveted_service_subscriptions_for_subject($subjectType,$subjectId,true,$pdo);
    $effective = coveted_service_effective_package($user,$business ? (int)$business['id'] : null,$pdo);
} catch (InvalidArgumentException|RuntimeException $e) {
    $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('Subscription confirmation unavailable: ' . $e->getMessage());
    $error = 'Unable to load that subscription option right now.';
}

$stripeReady = coveted_stripe_schema_available($pdo) && coveted_stripe_webhook_ready();
$priceCents = is_array($package) && $package['monthly_price_cents'] !== null ? (int)$package['monthly_price_cents'] : null;
$isPaid = $priceCents !== null && $priceCents > 0;
$hasAdminOverride = is_array($effective) && in_array((string)($effective['source'] ?? ''),['user_override','partner_override','user_type_override'],true);
$canCheckout = $error === '' && $package !== null && $isPaid && $stripeReady && !$activeSubscriptions && !$hasAdminOverride;

coveted_page_start('Confirm Subscription');
?>
<section class="cv-section">
    <div class="cv-section-head">
        <div>
            <span class="cv-eyebrow">SUBSCRIPTION</span>
            <h1>Confirm your Coveted service.</h1>
            <p>The package, price and included capabilities below come directly from the live Service Packages catalog managed by Coveted System Admin.</p>
        </div>
        <a class="cv-button cv-button-soft" href="/pricing.php">Back to pricing</a>
    </div>

    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

    <?php if ($package !== null && $error === ''): ?>
        <div class="cv-admin-dashboard-grid cv-admin-section-gap">
            <section class="cv-panel">
                <span class="cv-eyebrow"><?= $subjectType === 'business' ? 'PARTNER SUBSCRIPTION' : 'MEMBER SUBSCRIPTION' ?></span>
                <h2>You are purchasing <?= coveted_e((string)$package['name']) ?> for <?= coveted_e($subjectLabel) ?>.</h2>
                <p><?= coveted_e((string)($package['description'] ?? '')) ?></p>
                <div class="cv-admin-metric-grid cv-admin-section-gap">
                    <div><span>Package</span><strong><?= coveted_e((string)$package['name']) ?></strong><small><?= coveted_e((string)$package['package_key']) ?></small></div>
                    <div><span>Monthly price</span><strong><?= coveted_e(coveted_service_format_price($priceCents,(string)$package['currency'])) ?></strong><small>recurring monthly service</small></div>
                    <div><span>Billed to</span><strong><?= coveted_e($subjectLabel) ?></strong><small><?= $subjectType === 'business' ? 'business billing account' : 'member billing account' ?></small></div>
                </div>

                <?php $features = coveted_service_public_entitlements($package,$pdo); if ($features): ?>
                    <div class="cv-admin-list cv-admin-section-gap">
                        <?php foreach ($features as $feature): ?>
                            <div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong><?= coveted_e((string)$feature['label']) ?></strong><?php if ((string)$feature['description'] !== ''): ?><small><?= coveted_e((string)$feature['description']) ?></small><?php endif; ?></span><span class="cv-status">Included</span></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="cv-panel">
                <span class="cv-eyebrow">SECURE BILLING</span>
                <h2>Continue to Stripe.</h2>
                <p>Stripe hosts the payment form. Coveted does not receive or store your card number or CVC.</p>

                <?php if ($hasAdminOverride): ?>
                    <div class="cv-alert"><strong>Payment bypass active.</strong> A System Admin already grants this billing context a package, so self-service checkout is disabled.</div>
                    <a class="cv-button cv-button-soft" href="/billing.php<?= $business ? '?business=' . coveted_e(rawurlencode((string)$business['public_id'])) : '' ?>">View Billing &amp; Plan</a>
                <?php elseif ($activeSubscriptions): ?>
                    <div class="cv-alert"><strong>Subscription already active.</strong> Use Billing &amp; Plan to manage the existing subscription instead of creating another one.</div>
                    <a class="cv-button cv-button-primary" href="/billing.php<?= $business ? '?business=' . coveted_e(rawurlencode((string)$business['public_id'])) : '' ?>">Manage billing</a>
                <?php elseif (!$isPaid): ?>
                    <div class="cv-alert">This package does not currently have a paid monthly price configured for self-service checkout.</div>
                    <a class="cv-button cv-button-soft" href="/pricing.php">View pricing</a>
                <?php elseif (!$stripeReady): ?>
                    <div class="cv-alert">Online billing is not currently available. The package remains visible because Pricing is controlled independently by the live catalog.</div>
                <?php elseif ($canCheckout): ?>
                    <form method="post" action="/billing-action.php">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="checkout">
                        <input type="hidden" name="package_id" value="<?= (int)$package['id'] ?>">
                        <input type="hidden" name="business_ref" value="<?= coveted_e((string)($business['public_id'] ?? '')) ?>">
                        <button class="cv-button cv-button-primary" type="submit">Continue to secure Stripe Checkout</button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</section>
<?php coveted_page_end(); ?>
