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
        fwrite(STDERR,"Subscription lifecycle contract failed: {$label}\n");
        exit(1);
    }
};
$missing = static function (string $content,string $needle,string $label): void {
    if (str_contains($content,$needle)) {
        fwrite(STDERR,"Subscription lifecycle contract failed: {$label}\n");
        exit(1);
    }
};

$lifecycle = $read('app/subscription_lifecycle.php');
$service = $read('app/service_packages.php');
$stripeDunning = $read('app/stripe_dunning.php');
$webhook = $read('api/stripe-webhook.php');
$billingAction = $read('billing-action.php');
$billing = $read('billing.php');
$admin = $read('admin/subscription-lifecycle.php');
$ops = $read('admin/billing-operations.php');

// Provider-neutral lifecycle policy.
$contains($lifecycle,'COVETED_BILLING_DEFAULT_GRACE_DAYS = 7','default grace must be seven days');
$contains($lifecycle,'COVETED_BILLING_MAX_GRACE_DAYS = 30','Admin grace range must be bounded');
$contains($lifecycle,"'billing.payment_failed'",'failure episodes must be canonical audit events');
$contains($lifecycle,"'billing.payment_recovered'",'recovery episodes must be canonical audit events');
$contains($lifecycle,'$status !== \'past_due\'','grace must apply only to past-due state');
$contains($lifecycle,'$base[\'state\'] = \'past_due_grace\'','past-due grace state must exist');
$contains($lifecycle,'$base[\'state\'] = \'past_due_expired\'','expired grace state must exist');
$contains($lifecycle,"['trialing','active','past_due','paused']",'open-subscription definition must include delinquent and paused records');
$missing($lifecycle,"provider='stripe'",'authorization lifecycle must not depend on Stripe');

// Canonical entitlement resolver must consume lifecycle policy and preserve
// the existing override precedence rather than checking a provider directly.
$contains($service,"require_once __DIR__ . '/subscription_lifecycle.php'",'service package resolver must load lifecycle policy');
$contains($service,"s.status IN ('trialing','active','past_due')",'resolver candidates must include past-due subscriptions');
$contains($service,'coveted_subscription_lifecycle_allows_access($row, $pdo)','resolver must filter candidates through lifecycle access policy');
$contains($service,"'subscription_state' => $subscription ? coveted_subscription_lifecycle_access_state",'effective package must expose lifecycle state');
$missing($service,"provider='stripe'",'service authorization must remain provider-neutral');

// Stripe is only a translator into canonical lifecycle events.
$contains($stripeDunning,"'provider' => 'stripe'",'provider adapter should identify Stripe in event metadata');
$contains($stripeDunning,'coveted_subscription_lifecycle_record_failure','Stripe failure events must become canonical failures');
$contains($stripeDunning,'coveted_subscription_lifecycle_record_recovery','Stripe paid/active events must close failure episodes');
$contains($stripeDunning,'coveted_stripe_dunning_reconcile_subscription','manual reconciliation must repair lifecycle state');
$missing($stripeDunning,"coveted_stripe_api_request('POST'",'dunning adapter must not mutate Stripe');
$contains($webhook,"require_once dirname(__DIR__) . '/app/stripe_dunning.php'",'webhook must load dunning adapter');
$contains($webhook,'if (empty($result[\'duplicate\']))','duplicate webhook delivery must not duplicate lifecycle events');
$contains($webhook,'coveted_stripe_dunning_record_event($event,$pdo)','successful webhook sync must record lifecycle transition');

// Duplicate subscriptions must be blocked even after paid entitlement access
// has paused due to delinquency.
$contains($billingAction,'coveted_service_subscriptions_for_subject($billingSubject,$billingSubjectId,false,$pdo)','checkout must inspect all subject subscriptions');
$contains($billingAction,'coveted_subscription_lifecycle_is_open($row)','checkout must block any open subscription requiring management');
$contains($billingAction,'Use Manage billing instead of creating a second subscription.','duplicate-subscription recovery UX must be explicit');

// Customer recovery UX.
$contains($billing,'coveted_subscription_lifecycle_customer_message($row,$pdo)','Billing page must render lifecycle messages');
$contains($billing,'Resolve billing','Billing page must expose a recovery action');
$contains($billing,"'past_due_expired'",'Billing page must distinguish expired grace');
$contains($billing,'coveted_subscription_lifecycle_is_open($row)','package checkout UI must stay disabled for open delinquent subscriptions');

// Admin policy and reconciliation.
$contains($admin,'name="grace_days"','Admin must control grace days');
$contains($admin,'coveted_subscription_lifecycle_set_grace_days','Admin form must use canonical lifecycle setter');
$contains($admin,'Past due · grace','Admin must expose grace queue');
$contains($admin,'Past due · expired','Admin must expose expired queue');
$contains($ops,'/admin/subscription-lifecycle.php','Billing Operations must link to lifecycle policy');
$contains($ops,'coveted_stripe_dunning_reconcile_subscription','manual Stripe resync must reconcile lifecycle state');
$contains($ops,'past_due</code> can retain access only during','Billing Operations must explain grace-aware access');

// This phase adds policy only: no billing schema or card-data handling.
foreach ([$lifecycle,$stripeDunning,$admin,$billingAction,$billing] as $content) {
    $missing($content,'CREATE TABLE','subscription lifecycle must not introduce runtime billing DDL');
    $missing($content,'ALTER TABLE','subscription lifecycle must not alter billing schema');
    $missing($content,'card_number','subscription lifecycle must not handle card numbers');
    $missing($content,'cvv','subscription lifecycle must not handle CVV');
    $missing($content,'cvc','subscription lifecycle must not handle CVC');
}

fwrite(STDOUT,"Subscription lifecycle + dunning contract verified.\n");
