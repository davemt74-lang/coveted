<?php
declare(strict_types=1);

require_once __DIR__ . '/app/stripe_billing.php';

$user = coveted_require_user();
$pdo = coveted_db();
$sessionRef = trim((string)($_GET['session_id'] ?? ''));

try {
    $result = coveted_stripe_sync_checkout_return($user,$sessionRef,$pdo);
    $subscription = (array)($result['subscription'] ?? []);
    $package = (array)($subscription['package'] ?? []);
    $name = trim((string)($package['name'] ?? ''));
    $_SESSION['billing_notice'] = $name !== ''
        ? 'Stripe checkout completed. ' . $name . ' is now synced to your billing account.'
        : 'Stripe checkout completed. Your billing account has been synchronized.';
    $business = $result['business'] ?? null;
    $path = '/billing.php';
    if (is_array($business) && !empty($business['public_id'])) {
        $path .= '?business=' . rawurlencode((string)$business['public_id']);
    }
    coveted_redirect($path);
} catch (InvalidArgumentException|RuntimeException $e) {
    $_SESSION['billing_error'] = $e->getMessage();
    coveted_redirect('/billing.php');
} catch (Throwable $e) {
    error_log('Stripe checkout return failed: ' . $e->getMessage());
    $_SESSION['billing_error'] = 'Checkout completed, but Coveted could not synchronize the subscription yet. The Stripe webhook will retry automatically.';
    coveted_redirect('/billing.php');
}
