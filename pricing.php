<?php
declare(strict_types=1);

require_once __DIR__ . '/app/public_packages.php';

$user = coveted_current_user();
$pdo = coveted_db();
$schemaReady = coveted_service_packages_schema_available($pdo);
$packages = $schemaReady ? coveted_service_public_packages(null,$pdo) : [];
$appConfig = coveted_config('app');
$appName = (string)($appConfig['name'] ?? 'Coveted');
$baseUrl = rtrim((string)($appConfig['base_url'] ?? ''),'/');
$cssVersion = coveted_asset_version('assets/css/coveted.css');
$pricingCssVersion = coveted_asset_version('assets/css/pricing-v1.css');
$footerJsVersion = coveted_asset_version('assets/js/legal-footer.js');

$audienceLabels = [
    'user' => 'Member',
    'business' => 'Business',
    'manual' => 'Organization',
];

$ctaFor = static function (array $package) use ($user): array {
    $subject = (string)$package['billing_subject'];
    $key = (string)$package['package_key'];
    $price = $package['monthly_price_cents'] !== null ? (int)$package['monthly_price_cents'] : null;

    if ($subject === 'business') {
        $target = '/partner-onboarding.php?package=' . rawurlencode($key);
        if (!$user) {
            return ['/auth.php?action=login&return=' . rawurlencode($target),'Sign in to become a partner'];
        }
        return [$target,'Choose for a partner'];
    }
    if ($subject === 'manual') {
        return ['/request-invite.php','Talk with Coveted'];
    }
    if ($price === 0) {
        return [$user ? '/billing.php' : '/request-invite.php',$user ? 'View my plan' : 'Request an invite'];
    }

    $target = '/subscribe.php?package=' . rawurlencode($key);
    if (!$user) {
        return ['/auth.php?action=login&return=' . rawurlencode($target),'Sign in to subscribe'];
    }
    return [$target,'Choose ' . (string)$package['name']];
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#f7f7f4">
    <title>Pricing · <?= coveted_e($appName) ?></title>
    <meta name="description" content="Coveted membership and partner service packages. Pricing and included capabilities are loaded from the live Coveted Service Packages catalog.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Pricing · Coveted">
    <meta property="og:description" content="Membership and partner service packages for real-world connection.">
    <?php if ($baseUrl !== ''): ?><meta property="og:url" content="<?= coveted_e($baseUrl . '/pricing.php') ?>"><?php endif; ?>
    <link rel="stylesheet" href="/assets/css/coveted.css?v=<?= coveted_e($cssVersion) ?>">
    <link rel="stylesheet" href="/assets/css/pricing-v1.css?v=<?= coveted_e($pricingCssVersion) ?>">
</head>
<body class="cv-pricing-public">
<header class="cv-header">
    <a class="cv-brand" href="/">Coveted</a>
    <nav class="cv-nav" aria-label="Primary"></nav>
    <div class="cv-header-actions">
        <?php if ($user): ?>
            <a href="/">Open Coveted</a>
        <?php else: ?>
            <a href="/auth.php?action=login">Sign in</a>
            <a class="cv-button" href="/request-invite.php">Request an Invite</a>
        <?php endif; ?>
    </div>
</header>
<main class="cv-main">
    <div class="cv-pricing-shell">
        <section class="cv-pricing-hero">
            <span class="cv-eyebrow">COVETED SERVICE</span>
            <h1>Choose how you want to participate.</h1>
            <p>Member and partner packages come directly from Coveted's live Service Packages catalog. System Admin controls the names, prices, availability and included capabilities shown here.</p>
        </section>

        <?php if (!$schemaReady): ?>
            <div class="cv-pricing-empty"><strong>Pricing is temporarily unavailable.</strong><p>The service package catalog has not been installed yet.</p></div>
        <?php elseif (!$packages): ?>
            <div class="cv-pricing-empty"><strong>No public packages are currently available.</strong><p>Check back after Coveted publishes its service packages.</p></div>
        <?php else: ?>
            <section class="cv-pricing-grid" aria-label="Coveted pricing packages">
                <?php foreach ($packages as $package):
                    [$ctaHref,$ctaLabel] = $ctaFor($package);
                    $features = (array)($package['public_entitlements'] ?? []);
                    $subject = (string)$package['billing_subject'];
                    $priceCents = $package['monthly_price_cents'] !== null ? (int)$package['monthly_price_cents'] : null;
                    $featured = in_array((string)$package['package_key'],['plus','partner_pro'],true);
                ?>
                <article class="cv-pricing-card<?= $featured ? ' is-featured' : '' ?>">
                    <div class="cv-pricing-card-head">
                        <div>
                            <span class="cv-pricing-audience"><?= coveted_e($audienceLabels[$subject] ?? 'Service') ?></span>
                            <h2><?= coveted_e((string)$package['name']) ?></h2>
                        </div>
                        <div class="cv-pricing-price">
                            <?php if ($priceCents === null): ?>
                                Contact
                            <?php elseif ($priceCents === 0): ?>
                                Free
                            <?php else: ?>
                                <?= coveted_e((string)$package['currency'] === 'USD' ? '$' . number_format($priceCents / 100,2) : strtoupper((string)$package['currency']) . ' ' . number_format($priceCents / 100,2)) ?><small>/mo</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p class="cv-pricing-description"><?= coveted_e((string)($package['description'] ?? '')) ?></p>
                    <?php if ($features): ?>
                        <ul class="cv-pricing-features">
                            <?php foreach ($features as $feature): ?><li><?= coveted_e((string)$feature['label']) ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <a class="cv-button <?= $featured ? 'cv-button-primary' : 'cv-button-soft' ?>" href="<?= coveted_e($ctaHref) ?>"><?= coveted_e($ctaLabel) ?></a>
                </article>
                <?php endforeach; ?>
            </section>
            <div class="cv-pricing-note">Public pricing is dynamic. When System Admin changes a package price, description, active state or enabled entitlement, this page reflects the live catalog automatically. Future premium experiences can be added by enabling new entitlements without redesigning the billing model.</div>
        <?php endif; ?>
    </div>
</main>
<script src="/assets/js/legal-footer.js?v=<?= coveted_e($footerJsVersion) ?>" defer></script>
</body>
</html>
