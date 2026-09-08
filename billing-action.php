<?php
declare(strict_types=1);

require_once __DIR__ . '/app/stripe_billing.php';

$user = coveted_require_user();
$pdo = coveted_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

coveted_require_csrf();

$businessRef = trim((string)($_POST['business_ref'] ?? ''));
$returnPath = '/billing.php' . ($businessRef !== '' ? '?business=' . rawurlencode($businessRef) : '');

try {
    $business = coveted_stripe_actor_business($user,$businessRef);
    $action = strtolower(trim((string)($_POST['action'] ?? '')));

    if ($action === 'checkout') {
        $packageId = (int)($_POST['package_id'] ?? 0);
        if ($packageId < 1) {
            throw new InvalidArgumentException('Choose a paid package.');
        }
        $result = coveted_stripe_create_checkout($user,$packageId,$business,$pdo);
        coveted_stripe_safe_redirect((string)$result['session']['url'],['checkout.stripe.com']);
    }

    if ($action === 'portal') {
        $session = coveted_stripe_portal_session($user,$business,$pdo);
        coveted_stripe_safe_redirect((string)$session['url'],['billing.stripe.com']);
    }

    throw new InvalidArgumentException('Unsupported billing action.');
} catch (InvalidArgumentException|RuntimeException $e) {
    $_SESSION['billing_error'] = $e->getMessage();
    coveted_redirect($returnPath);
} catch (Throwable $e) {
    error_log('Coveted billing action failed: ' . $e->getMessage());
    $_SESSION['billing_error'] = 'Unable to start that billing action right now.';
    coveted_redirect($returnPath);
}
