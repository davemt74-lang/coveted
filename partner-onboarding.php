<?php
declare(strict_types=1);

require_once __DIR__ . '/app/partner_accounts.php';
require_once __DIR__ . '/app/public_packages.php';

$user = coveted_require_user();
$pdo = coveted_db();
$error = '';
$businesses = coveted_businesses_for_actor($user);
$partnerPackages = coveted_service_packages_schema_available($pdo)
    ? coveted_service_public_packages('business',$pdo)
    : [];
$selectedPackageKey = strtolower(trim((string)($_GET['package'] ?? $_POST['package_key'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        $business = coveted_partner_create_self_service(
            $user,
            (string)($_POST['name'] ?? ''),
            (string)($_POST['description'] ?? ''),
            $pdo
        );

        $packageKey = strtolower(trim((string)($_POST['package_key'] ?? '')));
        $path = '/billing.php?business=' . rawurlencode((string)$business['public_id']) . '&partner=created';
        if ($packageKey !== '') {
            $package = coveted_service_package($packageKey,$pdo);
            if ($package && coveted_service_package_available_to_subject((int)$package['id'],'business',$pdo)) {
                $path .= '&package=' . rawurlencode((string)$package['package_key']);
            }
        }
        coveted_redirect($path);
    } catch (InvalidArgumentException|RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Partner onboarding failed: ' . $e->getMessage());
        $error = 'Unable to create that partner account right now.';
    }
}

coveted_page_start('Become a Partner');
?>
<section class="cv-section">
    <div class="cv-section-head">
        <div>
            <span class="cv-eyebrow">PARTNER ONBOARDING</span>
            <h1>Create your Coveted partner account.</h1>
            <p>Your normal Coveted user account remains your identity. The partner is a separate business record that you administer and that can hold its own service package and Stripe subscription.</p>
        </div>
        <a class="cv-button cv-button-soft" href="/pricing.php">View pricing</a>
    </div>

    <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>

    <?php if ($businesses): ?>
        <section class="cv-panel cv-admin-section-gap">
            <span class="cv-eyebrow">YOUR PARTNERS</span>
            <h2>Already managing a business?</h2>
            <div class="cv-action-row">
                <?php foreach ($businesses as $business): ?>
                    <a class="cv-button cv-button-soft" href="/billing.php?business=<?= coveted_e(rawurlencode((string)$business['public_id'])) ?>"><?= coveted_e((string)$business['name']) ?> · Billing</a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="cv-admin-dashboard-grid cv-admin-section-gap">
        <form class="cv-panel cv-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <span class="cv-eyebrow">BUSINESS</span>
            <h2>Create a partner</h2>
            <label>Business name
                <input name="name" maxlength="180" required value="<?= coveted_e((string)($_POST['name'] ?? '')) ?>" placeholder="Your business name">
            </label>
            <label>Short description
                <textarea name="description" maxlength="4000" rows="5" placeholder="What does the business do?"><?= coveted_e((string)($_POST['description'] ?? '')) ?></textarea>
            </label>
            <label>Preferred package
                <select name="package_key">
                    <option value="">Choose after creating the partner</option>
                    <?php foreach ($partnerPackages as $package): ?>
                        <option value="<?= coveted_e((string)$package['package_key']) ?>" <?= $selectedPackageKey === (string)$package['package_key'] ? 'selected' : '' ?>>
                            <?= coveted_e((string)$package['name']) ?> · <?= coveted_e(coveted_service_format_price($package['monthly_price_cents'] !== null ? (int)$package['monthly_price_cents'] : null,(string)$package['currency'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="cv-button cv-button-primary" type="submit">Create partner & continue</button>
            <p><small>Creating a partner does not change your global Coveted user role. You become the first resource-scoped Business Admin for this business.</small></p>
        </form>

        <section class="cv-panel">
            <span class="cv-eyebrow">HOW BILLING WORKS</span>
            <h2>The business pays.</h2>
            <div class="cv-admin-list">
                <div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong>1. Create the partner</strong><small>Coveted creates a prospective business and makes you its first Business Admin.</small></span></div>
                <div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong>2. Choose a live package</strong><small>The choices come directly from System Admin's active Service Packages catalog.</small></span></div>
                <div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong>3. Pay securely through Stripe</strong><small>The Stripe customer and subscription belong to the business billing subject.</small></span></div>
                <div class="cv-admin-list-row"><span class="cv-admin-list-copy"><strong>4. Share administration</strong><small>Other authorized Coveted users can later manage the same business without creating another subscription.</small></span></div>
            </div>
        </section>
    </div>
</section>
<?php coveted_page_end(); ?>
