<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $value = @file_get_contents($root . '/' . ltrim($path, '/'));
    if ($value === false) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
    return $value;
};
$contains = static function (string $content, string $needle, string $label): void {
    if (!str_contains($content, $needle)) {
        fwrite(STDERR, "Stripe Billing contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content, string $needle, string $label): void {
    if (str_contains(strtolower($content), strtolower($needle))) {
        fwrite(STDERR, "Stripe Billing contract failed: {$label}\n");
        exit(1);
    }
};

$migration = $read('database/migrations/20260908_stripe_billing.sql');
$adapter = $read('app/stripe_billing.php');
$billing = $read('billing.php');
$action = $read('billing-action.php');
$return = $read('billing-return.php');
$webhook = $read('api/stripe-webhook.php');
$config = $read('config-example.php');

$contains($migration, 'CREATE TABLE IF NOT EXISTS billing_customers (', 'customer mapping table is required');
$contains($migration, 'CREATE TABLE IF NOT EXISTS billing_checkout_sessions (', 'checkout idempotency table is required');
$contains($migration, 'CREATE TABLE IF NOT EXISTS billing_webhook_events (', 'webhook event ledger is required');
$contains($migration, 'UNIQUE KEY uq_billing_webhook_provider_event (provider,event_ref)', 'webhook events must be provider/id unique');
$contains($migration, "status ENUM('received','processing','processed','failed')", 'webhook retry state must be explicit');
$contains($migration, "status ENUM('creating','open','completed','expired','failed')", 'checkout lifecycle must be explicit');
$contains($migration, "subject_type ENUM('user','business')", 'billing subjects must stay canonical user/business resources');
$missing($migration, 'card_number', 'database must not store card numbers');
$missing($migration, 'cvc', 'database must not store card CVC');
$missing($migration, 'cvv', 'database must not store card CVV');

$contains($adapter, "require_once __DIR__ . '/service_packages.php';", 'Stripe must sit on the canonical package service');
$contains($adapter, "'https://api.stripe.com'", 'Stripe API must be server-side');
$contains($adapter, 'CURLOPT_FOLLOWLOCATION => false', 'Stripe HTTP client must not follow arbitrary redirects');
$contains($adapter, 'CURLOPT_SSL_VERIFYPEER => true', 'TLS peer verification is required');
$contains($adapter, 'CURLOPT_SSL_VERIFYHOST => 2', 'TLS hostname verification is required');
$contains($adapter, "'Idempotency-Key: '", 'checkout creation must use Stripe idempotency keys');
$contains($adapter, "'mode' => 'subscription'", 'Checkout must create subscriptions');
$contains($adapter, "'/billing-return.php?session_id={CHECKOUT_SESSION_ID}'", 'Checkout success must return through server-side sync');
$contains($adapter, "'coveted_subject_type'", 'Stripe metadata must preserve canonical subject ownership');
$contains($adapter, "'coveted_subject_ref'", 'Stripe metadata must preserve canonical subject reference');
$contains($adapter, "'coveted_package_key'", 'Stripe metadata must preserve package identity');
$contains($adapter, "['user_override','partner_override','user_type_override']", 'Admin billing bypass must block paid checkout');
$contains($adapter, 'An active subscription already exists for this billing account.', 'duplicate active subscriptions must be blocked');
$contains($adapter, "'/billing_portal/sessions'", 'Stripe Billing Portal support is required');
$contains($adapter, "'trialing' => 'trialing'", 'trialing subscription state must sync');
$contains($adapter, "'active' => 'active'", 'active subscription state must sync');
$contains($adapter, "'past_due', 'unpaid', 'incomplete' => 'past_due'", 'payment-problem states must sync conservatively');
$contains($adapter, "'canceled', 'cancelled' => 'cancelled'", 'cancellation state must sync');
$contains($adapter, "'incomplete_expired' => 'expired'", 'expired subscriptions must lose active status');
$contains($adapter, "\$firstItem['current_period_start']", 'current billing period must support subscription-item fields');
$contains($adapter, "\$firstItem['current_period_end']", 'billing renewal/end period must support subscription-item fields');
$contains($adapter, 'INSERT INTO billing_subscriptions', 'provider sync must write the provider-neutral subscription ledger');
$contains($adapter, "provider='stripe'", 'Stripe subscription writes must remain provider-scoped');
$contains($adapter, "hash_hmac('sha256',\$timestamp . '.' . \$payload,\$secret)", 'webhook signature must be verified cryptographically');
$contains($adapter, 'hash_equals($expected,$signature)', 'webhook signature comparison must be timing-safe');
$contains($adapter, 'abs($now-$timestamp) > $tolerance', 'webhook replay tolerance must be bounded');
$contains($adapter, "INSERT IGNORE INTO billing_webhook_events", 'webhook processing must be idempotent');
$contains($adapter, "status='processing'", 'webhook claims must be explicit');
$contains($adapter, "status='processed'", 'successful webhook processing must be durable');
$contains($adapter, "status='failed'", 'failed webhook processing must be retryable');
$contains($adapter, "'checkout.session.completed'", 'checkout completion webhook is required');
$contains($adapter, "'customer.subscription.updated'", 'subscription update webhook is required');
$contains($adapter, "'customer.subscription.deleted'", 'subscription deletion webhook is required');
$contains($adapter, "'invoice.paid'", 'renewal/payment success webhook is required');
$contains($adapter, "'invoice.payment_failed'", 'payment failure webhook is required');
$missing($adapter, 'CREATE TABLE', 'runtime Stripe schema creation is forbidden');
$missing($adapter, 'ALTER TABLE', 'runtime Stripe schema mutation is forbidden');

$contains($action, "\$_SERVER['REQUEST_METHOD'] !== 'POST'", 'billing actions must be POST-only');
$contains($action, 'coveted_require_csrf();', 'billing actions must require CSRF');
$contains($action, "coveted_stripe_safe_redirect((string)\$result['session']['url'],['checkout.stripe.com'])", 'Checkout redirect must be host allowlisted');
$contains($action, "coveted_stripe_safe_redirect((string)\$session['url'],['billing.stripe.com'])", 'Portal redirect must be host allowlisted');
$missing($action, 'INSERT INTO billing_subscriptions', 'user action endpoint must not bypass the canonical Stripe adapter');

$contains($return, 'coveted_stripe_sync_checkout_return($user,$sessionRef,$pdo)', 'checkout return must re-fetch and verify Stripe server-side');
$contains($adapter, 'coveted_stripe_actor_can_manage_subject($user,$type,$ref)', 'checkout return must enforce subject ownership');
$missing($return, 'INSERT INTO billing_subscriptions', 'return endpoint must not write subscription state directly');

$contains($webhook, "file_get_contents('php://input')", 'webhook must verify the raw request body');
$contains($webhook, "\$_SERVER['HTTP_STRIPE_SIGNATURE']", 'webhook must require Stripe-Signature');
$contains($webhook, 'coveted_stripe_verify_webhook_signature($payload,$signature)', 'webhook endpoint must verify signature before processing');
$contains($webhook, 'coveted_stripe_process_webhook($event,$payload,$pdo)', 'webhook endpoint must use canonical processor');
$missing($webhook, 'coveted_require_csrf', 'Stripe webhook must not depend on browser CSRF state');
$missing($webhook, 'coveted_require_user', 'Stripe webhook must not depend on an interactive session');
$missing($webhook, 'INSERT INTO billing_subscriptions', 'webhook endpoint must not bypass canonical adapter sync');

$contains($billing, 'coveted_stripe_webhook_ready()', 'Billing UI must expose provider readiness');
$contains($billing, 'database/migrations/20260908_stripe_billing.sql', 'Billing UI must name the required migration');
$contains($billing, 'name="action" value="checkout"', 'Billing UI must expose hosted subscription checkout');
$contains($billing, 'name="action" value="portal"', 'Billing UI must expose Stripe Billing Portal');
$contains($billing, '$subjectSubscriptions = coveted_service_subscriptions_for_subject($billingSubjectType,$billingSubjectId,false,$pdo);', 'checkout availability must be scoped to the exact billing subject');
$contains($billing, '$hasOverrideAndPaid = $adminOverride !== null && $activeSubjectSubscriptions !== [];', 'billing-overlap warning must be scoped to the exact billing subject');
$contains($billing, 'Paid checkout is disabled while the assignment remains active.', 'Admin bypass must disable duplicate paid checkout');
$contains($billing, 'without exposing payment-card data to Coveted', 'hosted payment boundary must be explicit');
$missing($billing, 'INSERT INTO billing_subscriptions', 'Billing UI must not write subscription state directly');
$missing($billing, 'UPDATE billing_subscriptions', 'Billing UI must not mutate provider state directly');

$contains($config, "'billing' => [", 'billing config section is required');
$contains($config, "'stripe' => [", 'Stripe config section is required');
$contains($config, "'enabled' => false", 'Stripe must default disabled in example config');
$contains($config, "'secret_key' => ''", 'Stripe secret key must be configured outside source control');
$contains($config, "'webhook_secret' => ''", 'webhook signing secret must be configured outside source control');
$missing($config, 'sk_live_', 'live Stripe secret keys must never be committed');
$missing($config, 'whsec_123', 'real webhook secrets must never be committed');

fwrite(STDOUT, "Stripe Billing contract verified.\n");
