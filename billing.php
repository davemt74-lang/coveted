<?php
declare(strict_types=1);

require_once __DIR__ . '/app/service_packages.php';

$user = coveted_require_user();
$pdo = coveted_db();
$schemaReady = coveted_service_packages_schema_available($pdo);
$error = '';
$business = null;
$businesses = [];
$businessRef = trim((string)($_GET['business'] ?? ''));

if (!coveted_is_system_admin($user)) {
    try {
        $businesses = coveted_businesses_for_actor($user);
        if ($businessRef !== '') {
            $business = coveted_business_resolve_context($user, $businessRef);
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
$adminOverride = null;
if ($schemaReady && $error === '') {
    try {
        $effective = coveted_service_effective_package($user, $business ? (int)$business['id'] : null, $pdo);
        $packages = coveted_service_packages(true, $pdo);
        $subscriptions = coveted_service_subscriptions_for_subject('user', (int)$user['id'], false, $pdo);
        if ($business) {
            $subscriptions = array_merge(
                coveted_service_subscriptions_for_subject('business', (int)$business['id'], false, $pdo),
                $subscriptions
            );
        }
        if (in_array((string)($effective['source'] ?? ''), ['user_override','partner_override','user_type_override'], true)) {
            $adminOverride = $effective['assignment'];
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
$activeSubscriptions = array_values(array_filter($subscriptions, static fn(array $row): bool => in_array((string)$row['status'], ['trialing','active'], true)));
$hasOverrideAndPaid = $adminOverride !== null && $activeSubscriptions !== [];

coveted_page_start('Billing & Plan', '');
?>
<section class="cv-section">
    <div class="cv-section-head">
        <div>
            <span class="cv-eyebrow">ACCOUNT · SERVICE</span>
            <h1>Billing & Plan</h1>
            <p>See the package currently governing your Coveted access, where that access came from, and available service packages.</p>
        </div>
        <?php if (coveted_is_system_admin($user)): ?>
            <a class="cv-button cv-button-soft" href="/admin/service-packages.php">Manage service packages</a>
        <?php endif; ?>
    </div>

    <?php if (!$schemaReady): ?>
        <div class="cv-alert cv-alert-error"><strong>Database migration required.</strong> Import <code>database/migrations/20260907_service_packages_billing.sql</code> before using Billing & Plan.</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

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
            <div><span>Current package</span><strong><?= coveted_e((string)$current['name']) ?></strong><small><?= coveted_e(coveted_service_format_price($current['monthly_price_cents'] !== null ? (int)$current['monthly_price_cents'] : null, (string)$current['currency'])) ?></small></div>
            <div><span>Access source</span><strong><?= coveted_e($sourceLabels[$source] ?? ucwords(str_replace('_',' ',$source))) ?></strong><small><?= $source === 'subscription' ? 'payment-backed access' : ($source === 'default' ? 'platform default' : 'billing bypass set by System Admin') ?></small></div>
            <div><span>Entitlements</span><strong><?= count($entitlements) ?></strong><small>package capabilities</small></div>
            <div><span>Billing subject</span><strong><?= coveted_e($business ? (string)$business['name'] : 'My account') ?></strong><small><?= $business ? 'partner context' : 'personal context' ?></small></div>
        </div>

        <?php if ($hasOverrideAndPaid): ?>
            <div class="cv-alert cv-alert-error"><strong>Admin package override + active subscription.</strong> Your Admin-granted package governs access, but an active/trial subscription also exists. The override does not automatically cancel provider billing.</div>
        <?php elseif ($adminOverride !== null): ?>
            <div class="cv-alert"><strong>Payment bypass active.</strong> A System Admin assigned this package directly. Payment status does not control this access while the assignment remains active.</div>
        <?php endif; ?>

        <div class="cv-admin-dashboard-grid cv-admin-section-gap">
            <section class="cv-panel">
                <span class="cv-eyebrow">CURRENT ACCESS</span>
                <h2><?= coveted_e((string)$current['name']) ?></h2>
                <p><?= coveted_e((string)($current['description'] ?? '')) ?></p>
                <dl class="cv-admin-event-definition-list">
                    <div><dt>Package key</dt><dd><code><?= coveted_e((string)$current['package_key']) ?></code></dd></div>
                    <div><dt>Monthly price</dt><dd><?= coveted_e(coveted_service_format_price($current['monthly_price_cents'] !== null ? (int)$current['monthly_price_cents'] : null, (string)$current['currency'])) ?></dd></div>
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
                <p>Subscriptions are provider-neutral. A future payment adapter can write provider state here without becoming the authorization policy.</p>
                <div class="cv-admin-list">
                    <?php if (!$subscriptions): ?><div class="cv-admin-empty"><strong>No subscription records.</strong><span>Your package may be the default or an Admin assignment.</span></div><?php endif; ?>
                    <?php foreach ($subscriptions as $row): ?>
                        <div class="cv-admin-list-row">
                            <span class="cv-admin-list-copy">
                                <strong><?= coveted_e((string)$row['package_name']) ?></strong>
                                <small><?= coveted_e(ucwords(str_replace('_',' ',(string)$row['status']))) ?> · <?= coveted_e((string)$row['provider']) ?></small>
                                <?php if (!empty($row['current_period_end'])): ?><small>Current period ends <?= coveted_e((string)$row['current_period_end']) ?> UTC</small><?php endif; ?>
                            </span>
                            <span class="cv-status"><?= coveted_e((string)$row['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <section class="cv-panel cv-admin-section-gap">
            <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PACKAGES</span><h2>Available service packages</h2></div><span class="cv-status">Checkout adapter next</span></div>
            <div class="cv-admin-list">
                <?php foreach ($packages as $package):
                    $included = coveted_service_entitlements_for_package((int)$package['id'], $pdo);
                    $isCurrent = (int)$package['id'] === (int)$current['id'];
                ?>
                    <div class="cv-admin-list-row">
                        <span class="cv-admin-list-copy">
                            <strong><?= coveted_e((string)$package['name']) ?><?= $isCurrent ? ' · Current' : '' ?></strong>
                            <small><?= coveted_e(coveted_service_format_price($package['monthly_price_cents'] !== null ? (int)$package['monthly_price_cents'] : null, (string)$package['currency'])) ?></small>
                            <small><?= coveted_e((string)($package['description'] ?? '')) ?></small>
                            <?php if ($included): ?><small><?= coveted_e(implode(' · ', array_keys($included))) ?></small><?php endif; ?>
                        </span>
                        <span class="cv-status"><?= $isCurrent ? 'Active' : ((int)$package['monthly_price_cents'] > 0 ? 'Paid' : 'Available') ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p><small>This foundation intentionally does not invent a checkout provider. System Admin sets package prices and overrides here; Stripe or another provider can be connected next through <code>billing_subscriptions</code>.</small></p>
        </section>
    <?php endif; ?>
</section>
<?php coveted_page_end(); ?>
