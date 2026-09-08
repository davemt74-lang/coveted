<?php
declare(strict_types=1);

require_once __DIR__ . '/app/stripe_billing.php';
require_once __DIR__ . '/app/public_packages.php';

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

        $billingSubject = $business !== null ? 'business' : 'user';
        if (!coveted_service_package_available_to_subject($packageId,$billingSubject,$pdo)) {
            throw new InvalidArgumentException(
                $billingSubject === 'business'
                    ? 'Choose a package available for partner businesses.'
                    : 'Choose a package available for member accounts.'
            );
        }

        $subjectKey = $business !== null
            ? 'business:' . (string)$business['public_id']
            : 'user:' . (string)$user['public_id'];
        $lockName = 'coveted_checkout_' . substr(hash('sha256',$subjectKey . '|' . $packageId),0,40);
        $lock = $pdo->prepare('SELECT GET_LOCK(?,25)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new RuntimeException('Another checkout is already being prepared. Try again shortly.');
        }

        try {
            $result = coveted_stripe_create_checkout($user,$packageId,$business,$pdo);
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
                // MySQL also releases named locks automatically when this request's connection closes.
            }
        }

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
