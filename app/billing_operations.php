<?php
declare(strict_types=1);

require_once __DIR__ . '/stripe_billing.php';

function coveted_billing_ops_ready(?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    return coveted_service_packages_schema_available($pdo) && coveted_stripe_schema_available($pdo);
}

/** @return array<string,int> */
function coveted_billing_ops_metrics(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    if (!coveted_billing_ops_ready($pdo)) {
        return [
            'subscriptions' => 0,
            'active' => 0,
            'trialing' => 0,
            'past_due' => 0,
            'scheduled_cancel' => 0,
            'failed_webhooks_24h' => 0,
            'stale_webhooks' => 0,
            'failed_checkouts_24h' => 0,
        ];
    }

    $scalar = static function (PDO $pdo, string $sql): int {
        try {
            return (int)$pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            error_log('Billing operations metric unavailable: ' . $e->getMessage());
            return 0;
        }
    };

    return [
        'subscriptions' => $scalar($pdo, "SELECT COUNT(*) FROM billing_subscriptions WHERE provider='stripe'"),
        'active' => $scalar($pdo, "SELECT COUNT(*) FROM billing_subscriptions WHERE provider='stripe' AND status='active'"),
        'trialing' => $scalar($pdo, "SELECT COUNT(*) FROM billing_subscriptions WHERE provider='stripe' AND status='trialing'"),
        'past_due' => $scalar($pdo, "SELECT COUNT(*) FROM billing_subscriptions WHERE provider='stripe' AND status='past_due'"),
        'scheduled_cancel' => $scalar($pdo, "SELECT COUNT(*) FROM billing_subscriptions WHERE provider='stripe' AND cancel_at_period_end=1 AND status IN ('trialing','active','past_due')"),
        'failed_webhooks_24h' => $scalar($pdo, "SELECT COUNT(*) FROM billing_webhook_events WHERE provider='stripe' AND status='failed' AND updated_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)"),
        'stale_webhooks' => $scalar($pdo, "SELECT COUNT(*) FROM billing_webhook_events WHERE provider='stripe' AND status='processing' AND updated_at < DATE_SUB(NOW(),INTERVAL 10 MINUTE)"),
        'failed_checkouts_24h' => $scalar($pdo, "SELECT COUNT(*) FROM billing_checkout_sessions WHERE provider='stripe' AND status='failed' AND updated_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)"),
    ];
}

/** @return list<array<string,mixed>> */
function coveted_billing_ops_search_subscriptions(
    string $query = '',
    string $status = '',
    string $subjectType = '',
    int $limit = 100,
    ?PDO $pdo = null
): array {
    $pdo ??= coveted_db();
    if (!coveted_billing_ops_ready($pdo)) {
        return [];
    }

    $query = trim($query);
    $status = strtolower(trim($status));
    $subjectType = strtolower(trim($subjectType));
    $limit = max(1, min($limit, 250));

    $where = ["s.provider='stripe'"];
    $params = [];
    if ($status !== '') {
        $allowed = ['trialing','active','past_due','paused','cancelled','expired'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid subscription status filter.');
        }
        $where[] = 's.status=?';
        $params[] = $status;
    }
    if ($subjectType !== '') {
        if (!in_array($subjectType, ['user','business'], true)) {
            throw new InvalidArgumentException('Invalid billing subject filter.');
        }
        $where[] = 's.subject_type=?';
        $params[] = $subjectType;
    }
    if ($query !== '') {
        $where[] = '(s.public_id LIKE ? OR s.provider_subscription_ref LIKE ? OR s.provider_customer_ref LIKE ? OR p.name LIKE ? OR u.display_name LIKE ? OR u.email LIKE ? OR b.name LIKE ? OR b.public_id LIKE ?)';
        $like = '%' . $query . '%';
        for ($i = 0; $i < 8; $i++) {
            $params[] = $like;
        }
    }

    $sql = "SELECT s.*,p.package_key,p.name AS package_name,p.monthly_price_cents,p.currency,
                   u.public_id AS user_public_id,u.display_name AS user_name,u.email AS user_email,
                   b.public_id AS business_public_id,b.name AS business_name,b.status AS business_status
            FROM billing_subscriptions s
            JOIN service_packages p ON p.id=s.package_id
            LEFT JOIN users u ON u.id=s.user_id
            LEFT JOIN businesses b ON b.id=s.business_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY
                CASE s.status WHEN 'past_due' THEN 0 WHEN 'trialing' THEN 1 WHEN 'active' THEN 2 ELSE 3 END,
                s.updated_at DESC,s.id DESC
            LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function coveted_billing_ops_subscription_by_ref(string $ref, ?PDO $pdo = null): ?array
{
    $pdo ??= coveted_db();
    $ref = trim($ref);
    if ($ref === '' || !coveted_billing_ops_ready($pdo)) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT s.*,p.package_key,p.name AS package_name,p.monthly_price_cents,p.currency,
                u.public_id AS user_public_id,u.display_name AS user_name,u.email AS user_email,
                b.public_id AS business_public_id,b.name AS business_name,b.status AS business_status
         FROM billing_subscriptions s
         JOIN service_packages p ON p.id=s.package_id
         LEFT JOIN users u ON u.id=s.user_id
         LEFT JOIN businesses b ON b.id=s.business_id
         WHERE s.provider='stripe' AND (s.public_id=? OR s.provider_subscription_ref=?)
         LIMIT 1"
    );
    $stmt->execute([$ref,$ref]);
    return $stmt->fetch() ?: null;
}

function coveted_billing_ops_subject_label(array $subscription): string
{
    if ((string)($subscription['subject_type'] ?? '') === 'business') {
        return trim((string)($subscription['business_name'] ?? '')) ?: 'Partner business';
    }
    $name = trim((string)($subscription['user_name'] ?? ''));
    $email = trim((string)($subscription['user_email'] ?? ''));
    return $name !== '' ? $name . ($email !== '' ? ' · ' . $email : '') : ($email !== '' ? $email : 'Member');
}

/** @return list<array<string,mixed>> */
function coveted_billing_ops_webhooks(string $status = '', int $limit = 100, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    if (!coveted_billing_ops_ready($pdo)) {
        return [];
    }
    $status = strtolower(trim($status));
    $limit = max(1, min($limit, 250));
    $params = [];
    $sql = "SELECT * FROM billing_webhook_events WHERE provider='stripe'";
    if ($status !== '') {
        if (!in_array($status, ['received','processing','processed','failed'], true)) {
            throw new InvalidArgumentException('Invalid webhook status filter.');
        }
        $sql .= ' AND status=?';
        $params[] = $status;
    }
    $sql .= " ORDER BY received_at DESC,id DESC LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** @return list<array<string,mixed>> */
function coveted_billing_ops_failed_checkouts(int $limit = 50, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    if (!coveted_billing_ops_ready($pdo)) {
        return [];
    }
    $limit = max(1, min($limit, 100));
    $stmt = $pdo->query(
        "SELECT c.*,p.package_key,p.name AS package_name,
                u.display_name AS user_name,u.email AS user_email,
                b.name AS business_name,b.public_id AS business_public_id
         FROM billing_checkout_sessions c
         JOIN service_packages p ON p.id=c.package_id
         LEFT JOIN users u ON u.id=c.user_id
         LEFT JOIN businesses b ON b.id=c.business_id
         WHERE c.provider='stripe' AND c.status='failed'
         ORDER BY c.updated_at DESC,c.id DESC
         LIMIT {$limit}"
    );
    return $stmt->fetchAll();
}

function coveted_billing_ops_remote_subscription(array $local): array
{
    coveted_stripe_require_ready();
    $ref = trim((string)($local['provider_subscription_ref'] ?? ''));
    if ($ref === '' || preg_match('/^sub_[A-Za-z0-9_]+$/', $ref) !== 1) {
        throw new InvalidArgumentException('This local record does not contain a valid Stripe subscription reference.');
    }
    return coveted_stripe_api_request('GET','/subscriptions/' . rawurlencode($ref));
}

/** @return array{in_sync:bool,differences:list<array{field:string,local:string,remote:string}>,remote_status:string,remote_package_key:string} */
function coveted_billing_ops_reconciliation(array $local, array $remote): array
{
    $differences = [];
    $remoteStatus = coveted_stripe_local_status((string)($remote['status'] ?? ''));
    $remoteCustomer = coveted_stripe_object_id($remote['customer'] ?? '');
    $remoteMetadata = (array)($remote['metadata'] ?? []);
    $remotePackageKey = strtolower(trim((string)($remoteMetadata['coveted_package_key'] ?? '')));
    $items = (array)($remote['items']['data'] ?? []);
    $item = $items ? (array)$items[0] : [];
    $remotePeriodStart = coveted_stripe_sql_datetime($item['current_period_start'] ?? ($remote['current_period_start'] ?? null));
    $remotePeriodEnd = coveted_stripe_sql_datetime($item['current_period_end'] ?? ($remote['current_period_end'] ?? null));
    $remoteCancel = !empty($remote['cancel_at_period_end']) ? '1' : '0';

    $checks = [
        ['status',(string)($local['status'] ?? ''),$remoteStatus],
        ['customer',(string)($local['provider_customer_ref'] ?? ''),$remoteCustomer],
        ['package',(string)($local['package_key'] ?? ''),$remotePackageKey],
        ['period_start',(string)($local['current_period_start'] ?? ''),(string)$remotePeriodStart],
        ['period_end',(string)($local['current_period_end'] ?? ''),(string)$remotePeriodEnd],
        ['cancel_at_period_end',(string)(int)($local['cancel_at_period_end'] ?? 0),$remoteCancel],
    ];
    foreach ($checks as [$field,$localValue,$remoteValue]) {
        if ((string)$localValue !== (string)$remoteValue) {
            $differences[] = ['field'=>$field,'local'=>(string)$localValue,'remote'=>(string)$remoteValue];
        }
    }

    return [
        'in_sync' => $differences === [],
        'differences' => $differences,
        'remote_status' => $remoteStatus,
        'remote_package_key' => $remotePackageKey,
    ];
}

function coveted_billing_ops_assert_resync_ownership(array $local, array $remote, array $admin): void
{
    $metadata = (array)($remote['metadata'] ?? []);
    $remoteType = strtolower(trim((string)($metadata['coveted_subject_type'] ?? '')));
    $remoteRef = trim((string)($metadata['coveted_subject_ref'] ?? ''));
    if ($remoteType === '' && $remoteRef === '') {
        return;
    }

    $localType = (string)($local['subject_type'] ?? '');
    $localRef = $localType === 'business'
        ? trim((string)($local['business_public_id'] ?? ''))
        : trim((string)($local['user_public_id'] ?? ''));
    $conflict = $remoteType !== '' && !hash_equals($localType,$remoteType);
    if (!$conflict && $remoteRef !== '' && $localRef !== '') {
        $conflict = !hash_equals($localRef,$remoteRef);
    }
    if (!$conflict) {
        return;
    }

    coveted_audit(
        'billing.subscription_resync_blocked',
        'billing_subscription',
        (string)$local['public_id'],
        [
            'provider'=>'stripe',
            'provider_subscription_ref'=>(string)$local['provider_subscription_ref'],
            'local_subject_type'=>$localType,
            'local_subject_ref'=>$localRef,
            'remote_subject_type'=>$remoteType,
            'remote_subject_ref'=>$remoteRef,
        ],
        (int)$admin['id']
    );
    throw new RuntimeException('Stripe ownership metadata conflicts with the Coveted billing subject. Resync was blocked for review.');
}

function coveted_billing_ops_resync_subscription(array $admin, string $ref, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('Only a System Admin can reconcile subscriptions.');
    }
    $pdo ??= coveted_db();
    $local = coveted_billing_ops_subscription_by_ref($ref,$pdo);
    if (!$local) {
        throw new InvalidArgumentException('Billing subscription not found.');
    }
    $remote = coveted_billing_ops_remote_subscription($local);
    coveted_billing_ops_assert_resync_ownership($local,$remote,$admin);
    $before = coveted_billing_ops_reconciliation($local,$remote);
    $synced = coveted_stripe_sync_subscription($remote,$pdo);
    $fresh = coveted_billing_ops_subscription_by_ref((string)$synced['public_id'],$pdo);
    coveted_audit(
        'billing.subscription_admin_resync',
        'billing_subscription',
        (string)$local['public_id'],
        [
            'provider'=>'stripe',
            'provider_subscription_ref'=>(string)$local['provider_subscription_ref'],
            'difference_count'=>count($before['differences']),
            'status_before'=>(string)$local['status'],
            'status_after'=>(string)($fresh['status'] ?? $synced['status']),
        ],
        (int)$admin['id']
    );
    return ['subscription'=>$fresh ?: $local,'remote'=>$remote,'before'=>$before];
}

/** @return list<array<string,mixed>> */
function coveted_billing_ops_invoices(array $local, int $limit = 20): array
{
    coveted_stripe_require_ready();
    $customerRef = trim((string)($local['provider_customer_ref'] ?? ''));
    if ($customerRef === '' || preg_match('/^cus_[A-Za-z0-9_]+$/', $customerRef) !== 1) {
        return [];
    }
    $limit = max(1, min($limit, 25));
    $response = coveted_stripe_api_request('GET','/invoices',['customer'=>$customerRef,'limit'=>$limit]);
    $subscriptionRef = trim((string)($local['provider_subscription_ref'] ?? ''));
    $rows = [];
    foreach ((array)($response['data'] ?? []) as $invoice) {
        if (!is_array($invoice)) {
            continue;
        }
        $invoiceSubscription = coveted_stripe_invoice_subscription_ref($invoice);
        if ($subscriptionRef !== '' && ($invoiceSubscription === '' || !hash_equals($subscriptionRef,$invoiceSubscription))) {
            continue;
        }
        $rows[] = [
            'id'=>(string)($invoice['id'] ?? ''),
            'number'=>(string)($invoice['number'] ?? ''),
            'status'=>(string)($invoice['status'] ?? ''),
            'currency'=>strtoupper((string)($invoice['currency'] ?? 'USD')),
            'amount_due'=>(int)($invoice['amount_due'] ?? 0),
            'amount_paid'=>(int)($invoice['amount_paid'] ?? 0),
            'amount_remaining'=>(int)($invoice['amount_remaining'] ?? 0),
            'attempt_count'=>(int)($invoice['attempt_count'] ?? 0),
            'created'=>coveted_stripe_sql_datetime($invoice['created'] ?? null),
            'next_payment_attempt'=>coveted_stripe_sql_datetime($invoice['next_payment_attempt'] ?? null),
            'billing_reason'=>(string)($invoice['billing_reason'] ?? ''),
        ];
    }
    return $rows;
}

function coveted_billing_ops_health_label(array $metrics): array
{
    $failed = (int)($metrics['failed_webhooks_24h'] ?? 0);
    $stale = (int)($metrics['stale_webhooks'] ?? 0);
    $pastDue = (int)($metrics['past_due'] ?? 0);
    $checkout = (int)($metrics['failed_checkouts_24h'] ?? 0);
    if ($failed > 0 || $stale > 0) {
        return ['state'=>'attention','label'=>'Needs attention','detail'=>'Webhook delivery or processing requires review.'];
    }
    if ($pastDue > 0 || $checkout > 0) {
        return ['state'=>'watch','label'=>'Watch','detail'=>'Billing is processing, but customer payment issues need follow-up.'];
    }
    return ['state'=>'healthy','label'=>'Healthy','detail'=>'No current billing-processing issues detected.'];
}
