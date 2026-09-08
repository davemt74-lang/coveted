<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $value = @file_get_contents($root . '/' . ltrim($path,'/'));
    if ($value === false) {
        fwrite(STDERR,"Missing required file: {$path}\n");
        exit(1);
    }
    return $value;
};
$contains = static function (string $content,string $needle,string $label): void {
    if (!str_contains($content,$needle)) {
        fwrite(STDERR,"Billing operations contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content,string $needle,string $label): void {
    if (str_contains($content,$needle)) {
        fwrite(STDERR,"Billing operations contract failed: {$label}\n");
        exit(1);
    }
};

$ops = $read('app/billing_operations.php');
$page = $read('admin/billing-operations.php');
$adminUi = $read('app/admin_ui.php');
$service = $read('app/service_packages.php');
$stripe = $read('app/stripe_billing.php');

// Admin-only operational surface.
$contains($page,'coveted_require_system_admin()','billing operations must require System Admin');
$contains($page,'coveted_require_csrf()','billing repair mutations must require CSRF');
$contains($page,'$action !== \'resync_subscription\'','Admin page must allowlist the resync action');
$contains($page,'coveted_billing_ops_resync_subscription($admin,$ref,$pdo)','Admin repair must use the audited reconciliation service');
$contains($page,'only <code>trialing</code> and <code>active</code> subscriptions','current entitlement policy must be visible to Admin');
$contains($page,'Webhook failures','webhook failure health must be visible');
$contains($page,'Recent failed checkouts','checkout failures must be visible');
$contains($page,'Recent payment history','invoice history must be visible');

// Reconciliation is pull-only: it may read remote Stripe state and update local
// billing_subscriptions through the canonical Stripe sync function, but it must
// not cancel, change prices, charge, refund or otherwise mutate Stripe.
$contains($ops,"coveted_stripe_api_request('GET','/subscriptions/'",'manual reconciliation must fetch the live Stripe subscription');
$contains($ops,'coveted_billing_ops_assert_resync_ownership($local,$remote,$admin)','manual reconciliation must validate ownership before local sync');
$contains($ops,"'billing.subscription_resync_blocked'",'ownership conflicts must be audited and blocked');
$contains($ops,'coveted_stripe_sync_subscription($remote,$pdo)','manual reconciliation must reuse canonical subscription sync');
$contains($ops,"'billing.subscription_admin_resync'",'manual resync must be audited to the acting Admin');
$contains($ops,"coveted_stripe_api_request('GET','/invoices'",'invoice visibility must use Stripe read API');
$contains($ops,'coveted_stripe_invoice_subscription_ref($invoice)','invoice results must be scoped back to the selected subscription');
$contains($ops,'$invoiceSubscription === \'\' || !hash_equals($subscriptionRef,$invoiceSubscription)','invoice history must strictly reject unscoped or mismatched subscription invoices');
$missing($ops,"coveted_stripe_api_request('POST'",'billing operations service must not mutate remote Stripe state');
$missing($ops,'/refunds','billing operations service must not create refunds');
$missing($ops,'/charges','billing operations service must not create charges');
$missing($ops,'/subscription_items','billing operations service must not alter subscription prices');

// Local authorization remains unchanged and provider-neutral.
$contains($service,"s.status IN ('trialing','active')",'canonical entitlement resolver must continue to define paid active states');
$missing($service,"provider='stripe'",'service package authorization must not be hard-coded to Stripe');
$contains($stripe,'function coveted_stripe_sync_subscription','billing operations must reuse the existing Stripe sync implementation');

// Admin navigation must expose configuration and operations separately.
$contains($adminUi,"'/admin/service-packages.php', 'Service Packages'",'Service Packages must be linked from Admin navigation');
$contains($adminUi,"'/admin/billing-operations.php', 'Billing Operations'",'Billing Operations must be linked from Admin navigation');

// No sensitive card data or schema mutation belongs in this phase.
foreach ([$ops,$page] as $content) {
    $missing($content,'CREATE TABLE','billing operations must not perform runtime DDL');
    $missing($content,'ALTER TABLE','billing operations must not mutate schema');
    $missing($content,'card_number','billing operations must not handle card numbers');
    $missing($content,'cvc','billing operations must not handle CVC data');
    $missing($content,'cvv','billing operations must not handle CVV data');
}

fwrite(STDOUT,"Billing operations + reconciliation contract verified.\n");
