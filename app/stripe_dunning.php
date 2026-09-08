<?php
declare(strict_types=1);

require_once __DIR__ . '/stripe_billing.php';

function coveted_stripe_dunning_local_subscription(string $providerSubscriptionRef, ?PDO $pdo = null): ?array
{
    $pdo ??= coveted_db();
    $providerSubscriptionRef = trim($providerSubscriptionRef);
    if ($providerSubscriptionRef === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT * FROM billing_subscriptions
         WHERE provider='stripe' AND provider_subscription_ref=? LIMIT 1"
    );
    $stmt->execute([$providerSubscriptionRef]);
    return $stmt->fetch() ?: null;
}

/**
 * Translate Stripe billing transitions into provider-neutral Coveted lifecycle
 * events. This deliberately runs after canonical Stripe subscription sync.
 */
function coveted_stripe_dunning_record_event(array $event, ?PDO $pdo = null): void
{
    $pdo ??= coveted_db();
    $eventType = trim((string)($event['type'] ?? ''));
    $eventRef = trim((string)($event['id'] ?? ''));
    $object = (array)($event['data']['object'] ?? []);
    $subscriptionRef = '';

    if (str_starts_with($eventType, 'customer.subscription.')) {
        $subscriptionRef = coveted_stripe_object_id($object['id'] ?? '');
    } elseif (in_array($eventType, ['invoice.payment_failed','invoice.paid'], true)) {
        $subscriptionRef = coveted_stripe_invoice_subscription_ref($object);
    }
    if ($subscriptionRef === '') {
        return;
    }

    $local = coveted_stripe_dunning_local_subscription($subscriptionRef, $pdo);
    if (!$local) {
        return;
    }

    $metadata = [
        'provider' => 'stripe',
        'provider_event_ref' => $eventRef !== '' ? $eventRef : null,
        'provider_subscription_ref' => $subscriptionRef,
    ];

    if ($eventType === 'invoice.payment_failed') {
        $metadata['provider_invoice_ref'] = coveted_stripe_object_id($object['id'] ?? '');
        $metadata['attempt_count'] = (int)($object['attempt_count'] ?? 0);
        $metadata['next_payment_attempt_at'] = coveted_stripe_sql_datetime($object['next_payment_attempt'] ?? null);
        coveted_subscription_lifecycle_record_failure($local, $metadata, $pdo);
        return;
    }

    if ($eventType === 'invoice.paid') {
        $metadata['provider_invoice_ref'] = coveted_stripe_object_id($object['id'] ?? '');
        coveted_subscription_lifecycle_record_recovery($local, $metadata, $pdo);
        return;
    }

    if (str_starts_with($eventType, 'customer.subscription.')) {
        $status = coveted_stripe_local_status((string)($object['status'] ?? ''));
        $metadata['provider_status'] = (string)($object['status'] ?? '');
        if ($status === 'past_due') {
            coveted_subscription_lifecycle_record_failure($local, $metadata, $pdo);
        } elseif (in_array($status, ['trialing','active'], true)) {
            coveted_subscription_lifecycle_record_recovery($local, $metadata, $pdo);
        }
    }
}

/**
 * Manual reconciliation can discover a missed provider transition even when no
 * webhook lifecycle event was recorded. Seed or close the canonical dunning
 * episode from the live subscription state without mutating Stripe.
 */
function coveted_stripe_dunning_reconcile_subscription(array $remote, ?PDO $pdo = null): void
{
    $pdo ??= coveted_db();
    $subscriptionRef = coveted_stripe_object_id($remote['id'] ?? '');
    if ($subscriptionRef === '') {
        return;
    }
    $local = coveted_stripe_dunning_local_subscription($subscriptionRef, $pdo);
    if (!$local) {
        return;
    }
    $status = coveted_stripe_local_status((string)($remote['status'] ?? ''));
    $metadata = [
        'provider' => 'stripe',
        'source' => 'admin_reconciliation',
        'provider_subscription_ref' => $subscriptionRef,
        'provider_status' => (string)($remote['status'] ?? ''),
    ];
    if ($status === 'past_due') {
        coveted_subscription_lifecycle_record_failure($local, $metadata, $pdo);
    } elseif (in_array($status, ['trialing','active'], true)) {
        coveted_subscription_lifecycle_record_recovery($local, $metadata, $pdo);
    }
}
