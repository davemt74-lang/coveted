<?php
declare(strict_types=1);

require_once __DIR__ . '/service_packages.php';

function coveted_stripe_settings(): array
{
    $billing = coveted_config('billing');
    return (array)($billing['stripe'] ?? []);
}

function coveted_stripe_schema_available(?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    if (!coveted_service_packages_schema_available($pdo)) {
        return false;
    }

    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('billing_customers','billing_checkout_sessions','billing_webhook_events')"
        );
        return (int)$stmt->fetchColumn() === 3;
    } catch (Throwable) {
        return false;
    }
}

function coveted_stripe_enabled(): bool
{
    $settings = coveted_stripe_settings();
    return !empty($settings['enabled']);
}

function coveted_stripe_checkout_ready(): bool
{
    $settings = coveted_stripe_settings();
    $secret = trim((string)($settings['secret_key'] ?? ''));
    return !empty($settings['enabled']) && $secret !== '' && str_starts_with($secret, 'sk_');
}

function coveted_stripe_webhook_ready(): bool
{
    $settings = coveted_stripe_settings();
    $secret = trim((string)($settings['webhook_secret'] ?? ''));
    return coveted_stripe_checkout_ready() && $secret !== '' && str_starts_with($secret, 'whsec_');
}

function coveted_stripe_ready(?PDO $pdo = null): bool
{
    return coveted_stripe_schema_available($pdo) && coveted_stripe_webhook_ready();
}

function coveted_stripe_require_ready(?PDO $pdo = null): void
{
    if (!coveted_stripe_schema_available($pdo)) {
        throw new RuntimeException('Import the Stripe Billing migration before using checkout.');
    }
    if (!coveted_stripe_checkout_ready()) {
        throw new RuntimeException('Stripe checkout is not configured. Add the Stripe secret key in config.php.');
    }
    if (!coveted_stripe_webhook_ready()) {
        throw new RuntimeException('Stripe webhook verification is not configured. Add the webhook signing secret before accepting payments.');
    }
}

function coveted_stripe_api_request(
    string $method,
    string $path,
    array $params = [],
    ?string $idempotencyKey = null
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Stripe billing.');
    }

    $settings = coveted_stripe_settings();
    $secret = trim((string)($settings['secret_key'] ?? ''));
    if ($secret === '' || !str_starts_with($secret, 'sk_')) {
        throw new RuntimeException('Stripe secret key is not configured.');
    }

    $method = strtoupper(trim($method));
    if (!in_array($method, ['GET', 'POST'], true)) {
        throw new InvalidArgumentException('Unsupported Stripe API method.');
    }

    $path = '/' . ltrim($path, '/');
    if (!str_starts_with($path, '/v1/')) {
        $path = '/v1' . $path;
    }
    $url = 'https://api.stripe.com' . $path;
    $encoded = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    if ($method === 'GET' && $encoded !== '') {
        $url .= '?' . $encoded;
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: Coveted-Billing/1.0',
    ];
    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $headers[] = 'Idempotency-Key: ' . preg_replace('/[^A-Za-z0-9_.:-]/', '', $idempotencyKey);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize Stripe request.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERPWD => $secret . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
    }

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $curlError !== '') {
        throw new RuntimeException('Unable to reach Stripe. Try again shortly.');
    }

    try {
        $decoded = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new RuntimeException('Stripe returned an unreadable response.', 0, $e);
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('Stripe returned an invalid response.');
    }

    if ($status < 200 || $status >= 300) {
        $message = trim((string)($decoded['error']['message'] ?? ''));
        if ($status === 429) {
            throw new RuntimeException('Stripe is temporarily rate-limiting requests. Try again shortly.');
        }
        if ($status >= 500) {
            throw new RuntimeException('Stripe is temporarily unavailable. Try again shortly.');
        }
        throw new InvalidArgumentException($message !== '' ? $message : 'Stripe could not complete that billing request.');
    }

    return $decoded;
}

function coveted_stripe_safe_redirect(string $url, array $allowedHosts): never
{
    $url = trim($url);
    $parts = filter_var($url, FILTER_VALIDATE_URL) !== false ? parse_url($url) : false;
    $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
    $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
    if ($scheme !== 'https' || $host === '' || !in_array($host, $allowedHosts, true)) {
        throw new RuntimeException('Stripe returned an unexpected redirect URL.');
    }
    header('Location: ' . $url, true, 303);
    exit;
}

function coveted_stripe_sql_datetime(mixed $timestamp): ?string
{
    $value = is_numeric($timestamp) ? (int)$timestamp : 0;
    if ($value < 1) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $value);
}

function coveted_stripe_object_id(mixed $value): string
{
    if (is_string($value)) {
        return trim($value);
    }
    if (is_array($value)) {
        return trim((string)($value['id'] ?? ''));
    }
    return '';
}

function coveted_stripe_subject_from_actor(array $user, ?array $business = null): array
{
    if ($business !== null) {
        return [
            'type' => 'business',
            'id' => (int)$business['id'],
            'ref' => (string)$business['public_id'],
            'label' => (string)$business['name'],
            'email' => (string)($user['email'] ?? ''),
        ];
    }

    return [
        'type' => 'user',
        'id' => (int)$user['id'],
        'ref' => (string)$user['public_id'],
        'label' => (string)$user['display_name'],
        'email' => (string)$user['email'],
    ];
}

function coveted_stripe_subject_by_ref(string $type, string $ref, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $type = strtolower(trim($type));
    $ref = trim($ref);
    if ($type === 'user') {
        $stmt = $pdo->prepare("SELECT id,public_id,display_name,email FROM users WHERE public_id=? AND status='active' LIMIT 1");
        $stmt->execute([$ref]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Stripe subscription user no longer exists.');
        }
        return ['type'=>'user','id'=>(int)$row['id'],'ref'=>(string)$row['public_id'],'label'=>(string)$row['display_name'],'email'=>(string)$row['email']];
    }
    if ($type === 'business') {
        $stmt = $pdo->prepare("SELECT id,public_id,name FROM businesses WHERE public_id=? AND status<>'archived' LIMIT 1");
        $stmt->execute([$ref]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Stripe subscription partner no longer exists.');
        }
        return ['type'=>'business','id'=>(int)$row['id'],'ref'=>(string)$row['public_id'],'label'=>(string)$row['name'],'email'=>''];
    }
    throw new InvalidArgumentException('Invalid Stripe billing subject.');
}

function coveted_stripe_actor_business(array $user, string $businessRef): ?array
{
    $businessRef = trim($businessRef);
    if ($businessRef === '') {
        return null;
    }
    if (coveted_is_system_admin($user)) {
        $business = coveted_business_by_ref($businessRef);
        if (!$business || (string)$business['status'] === 'archived') {
            throw new InvalidArgumentException('Partner business not found.');
        }
        return $business;
    }
    return coveted_business_resolve_context($user, $businessRef);
}

function coveted_stripe_actor_can_manage_subject(array $user, string $type, string $ref): ?array
{
    if ($type === 'user') {
        if (!hash_equals((string)$user['public_id'], $ref)) {
            throw new InvalidArgumentException('That checkout does not belong to this account.');
        }
        return null;
    }
    if ($type === 'business') {
        return coveted_stripe_actor_business($user, $ref);
    }
    throw new InvalidArgumentException('Invalid checkout subject.');
}

function coveted_stripe_customer_for_subject(string $type, int $id, ?PDO $pdo = null): ?array
{
    $pdo ??= coveted_db();
    $column = $type === 'user' ? 'user_id' : ($type === 'business' ? 'business_id' : '');
    if ($column === '' || $id < 1) {
        throw new InvalidArgumentException('Invalid billing customer subject.');
    }
    $stmt = $pdo->prepare(
        "SELECT * FROM billing_customers
         WHERE provider='stripe' AND subject_type=? AND {$column}=?
         ORDER BY updated_at DESC,id DESC LIMIT 1"
    );
    $stmt->execute([$type,$id]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    $sub = $pdo->prepare(
        "SELECT provider_customer_ref FROM billing_subscriptions
         WHERE provider='stripe' AND subject_type=? AND {$column}=? AND provider_customer_ref IS NOT NULL
         ORDER BY updated_at DESC,id DESC LIMIT 1"
    );
    $sub->execute([$type,$id]);
    $customerRef = trim((string)$sub->fetchColumn());
    if ($customerRef === '') {
        return null;
    }
    return ['provider_customer_ref'=>$customerRef];
}

function coveted_stripe_upsert_customer(array $subject, string $customerRef, ?PDO $pdo = null): void
{
    $pdo ??= coveted_db();
    $customerRef = trim($customerRef);
    if ($customerRef === '' || !str_starts_with($customerRef, 'cus_')) {
        return;
    }

    $existing = $pdo->prepare("SELECT * FROM billing_customers WHERE provider='stripe' AND provider_customer_ref=? LIMIT 1");
    $existing->execute([$customerRef]);
    $row = $existing->fetch();
    if ($row) {
        $same = (string)$row['subject_type'] === (string)$subject['type']
            && (($subject['type'] === 'user' && (int)$row['user_id'] === (int)$subject['id'])
                || ($subject['type'] === 'business' && (int)$row['business_id'] === (int)$subject['id']));
        if (!$same) {
            throw new RuntimeException('Stripe customer is already linked to another Coveted billing subject.');
        }
        $pdo->prepare('UPDATE billing_customers SET updated_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
        return;
    }

    $pdo->prepare(
        'INSERT INTO billing_customers
            (public_id,subject_type,user_id,business_id,provider,provider_customer_ref)
         VALUES (?,?,?,?,\'stripe\',?)'
    )->execute([
        coveted_uuid('bilcus'),
        $subject['type'],
        $subject['type'] === 'user' ? (int)$subject['id'] : null,
        $subject['type'] === 'business' ? (int)$subject['id'] : null,
        $customerRef,
    ]);
}

function coveted_stripe_checkout_row_for_subject(array $subject, int $packageId, ?PDO $pdo = null): ?array
{
    $pdo ??= coveted_db();
    $column = $subject['type'] === 'user' ? 'user_id' : 'business_id';
    $stmt = $pdo->prepare(
        "SELECT * FROM billing_checkout_sessions
         WHERE provider='stripe' AND subject_type=? AND {$column}=? AND package_id=?
           AND status IN ('creating','open')
           AND created_at >= DATE_SUB(NOW(),INTERVAL 30 MINUTE)
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$subject['type'],(int)$subject['id'],$packageId]);
    return $stmt->fetch() ?: null;
}

function coveted_stripe_create_checkout(array $user, int $packageId, ?array $business = null, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    coveted_stripe_require_ready($pdo);
    $subject = coveted_stripe_subject_from_actor($user,$business);
    $package = coveted_service_package($packageId,$pdo);
    if (!$package || (int)$package['is_active'] !== 1) {
        throw new InvalidArgumentException('Choose an active Coveted package.');
    }
    $priceCents = $package['monthly_price_cents'] !== null ? (int)$package['monthly_price_cents'] : 0;
    if ($priceCents < 50) {
        throw new InvalidArgumentException('This package is not configured for paid monthly checkout.');
    }

    $effective = coveted_service_effective_package($user,$business ? (int)$business['id'] : null,$pdo);
    if (in_array((string)$effective['source'],['user_override','partner_override','user_type_override'],true)) {
        throw new InvalidArgumentException('This account currently has an Admin-granted package. Remove the payment bypass before starting a paid subscription.');
    }

    $subjectSubscriptions = coveted_service_subscriptions_for_subject((string)$subject['type'],(int)$subject['id'],false,$pdo);
    $openSubscriptions = array_values(array_filter(
        $subjectSubscriptions,
        static fn(array $row): bool => coveted_subscription_lifecycle_is_open($row)
    ));
    if ($openSubscriptions) {
        throw new InvalidArgumentException('An existing subscription still requires billing management. Use Manage billing instead of creating a second subscription.');
    }

    $checkout = coveted_stripe_checkout_row_for_subject($subject,$packageId,$pdo);
    if ($checkout && !empty($checkout['provider_session_ref'])) {
        try {
            $remote = coveted_stripe_api_request('GET','/checkout/sessions/' . rawurlencode((string)$checkout['provider_session_ref']));
            if ((string)($remote['status'] ?? '') === 'open' && !empty($remote['url'])) {
                return ['checkout'=>$checkout,'session'=>$remote,'reused'=>true];
            }
            $nextStatus = (string)($remote['status'] ?? '') === 'complete' ? 'completed' : 'expired';
            $pdo->prepare('UPDATE billing_checkout_sessions SET status=?,updated_at=NOW() WHERE id=?')->execute([$nextStatus,(int)$checkout['id']]);
            $checkout = null;
        } catch (Throwable) {
            $checkout = null;
        }
    }

    if (!$checkout) {
        $checkoutRef = coveted_uuid('bilchk');
        $pdo->prepare(
            'INSERT INTO billing_checkout_sessions
                (public_id,provider,subject_type,user_id,business_id,package_id,status,created_by_user_id)
             VALUES (?,\'stripe\',?,?,?,?,\'creating\',?)'
        )->execute([
            $checkoutRef,
            $subject['type'],
            $subject['type'] === 'user' ? (int)$subject['id'] : null,
            $subject['type'] === 'business' ? (int)$subject['id'] : null,
            $packageId,
            (int)$user['id'],
        ]);
        $checkout = ['id'=>(int)$pdo->lastInsertId(),'public_id'=>$checkoutRef];
    } else {
        $checkoutRef = (string)$checkout['public_id'];
    }

    $baseUrl = rtrim((string)(coveted_config('app')['base_url'] ?? ''),'/');
    $cancelUrl = $baseUrl . '/billing.php?checkout=cancelled';
    if ($business !== null) {
        $cancelUrl .= '&business=' . rawurlencode((string)$business['public_id']);
    }
    $settings = coveted_stripe_settings();
    $params = [
        'mode' => 'subscription',
        'success_url' => $baseUrl . '/billing-return.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $cancelUrl,
        'client_reference_id' => $checkoutRef,
        'customer_email' => (string)$subject['email'],
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => strtolower((string)$package['currency']),
                'unit_amount' => $priceCents,
                'recurring' => ['interval'=>'month'],
                'product_data' => ['name'=>'Coveted ' . (string)$package['name']],
            ],
        ]],
        'metadata' => [
            'coveted_checkout_ref' => $checkoutRef,
            'coveted_subject_type' => (string)$subject['type'],
            'coveted_subject_ref' => (string)$subject['ref'],
            'coveted_package_key' => (string)$package['package_key'],
        ],
        'subscription_data' => ['metadata'=>[
            'coveted_checkout_ref' => $checkoutRef,
            'coveted_subject_type' => (string)$subject['type'],
            'coveted_subject_ref' => (string)$subject['ref'],
            'coveted_package_key' => (string)$package['package_key'],
        ]],
    ];

    $customer = coveted_stripe_customer_for_subject((string)$subject['type'],(int)$subject['id'],$pdo);
    $customerRef = trim((string)($customer['provider_customer_ref'] ?? ''));
    if ($customerRef !== '') {
        unset($params['customer_email']);
        $params['customer'] = $customerRef;
    }
    if (!empty($settings['allow_promotion_codes'])) {
        $params['allow_promotion_codes'] = 'true';
    }
    if (!empty($settings['automatic_tax'])) {
        $params['automatic_tax'] = ['enabled'=>'true'];
    }
    $billingAddress = strtolower(trim((string)($settings['billing_address_collection'] ?? 'auto')));
    if (in_array($billingAddress,['auto','required'],true)) {
        $params['billing_address_collection'] = $billingAddress;
    }

    try {
        $session = coveted_stripe_api_request('POST','/checkout/sessions',$params,$checkoutRef);
        $sessionRef = trim((string)($session['id'] ?? ''));
        $url = trim((string)($session['url'] ?? ''));
        if (!str_starts_with($sessionRef,'cs_') || $url === '') {
            throw new RuntimeException('Stripe did not return a usable Checkout Session.');
        }
        $pdo->prepare(
            "UPDATE billing_checkout_sessions
             SET provider_session_ref=?,status='open',expires_at=?,last_error=NULL,updated_at=NOW()
             WHERE id=?"
        )->execute([$sessionRef,coveted_stripe_sql_datetime($session['expires_at'] ?? null),(int)$checkout['id']]);
        coveted_audit(
            'billing.checkout_created',
            'billing_checkout',
            $checkoutRef,
            ['provider'=>'stripe','package_key'=>(string)$package['package_key'],'subject_type'=>$subject['type'],'subject_ref'=>$subject['ref']],
            (int)$user['id']
        );
        return ['checkout'=>array_merge($checkout,['provider_session_ref'=>$sessionRef]),'session'=>$session,'reused'=>false];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE billing_checkout_sessions SET status='failed',last_error=?,updated_at=NOW() WHERE id=?")
            ->execute([mb_substr($e->getMessage(),0,1000),(int)$checkout['id']]);
        throw $e;
    }
}

function coveted_stripe_portal_session(array $user, ?array $business = null, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    coveted_stripe_require_ready($pdo);
    $subject = coveted_stripe_subject_from_actor($user,$business);
    $customer = coveted_stripe_customer_for_subject((string)$subject['type'],(int)$subject['id'],$pdo);
    $customerRef = trim((string)($customer['provider_customer_ref'] ?? ''));
    if ($customerRef === '' || !str_starts_with($customerRef,'cus_')) {
        throw new InvalidArgumentException('No Stripe billing account exists yet for this account.');
    }
    $returnUrl = rtrim((string)(coveted_config('app')['base_url'] ?? ''),'/') . '/billing.php';
    if ($business !== null) {
        $returnUrl .= '?business=' . rawurlencode((string)$business['public_id']);
    }
    $session = coveted_stripe_api_request('POST','/billing_portal/sessions',[
        'customer'=>$customerRef,
        'return_url'=>$returnUrl,
    ],'portal-' . bin2hex(random_bytes(12)));
    if (empty($session['url'])) {
        throw new RuntimeException('Stripe did not return a Billing Portal URL.');
    }
    coveted_audit(
        'billing.portal_opened',
        'billing_customer',
        (string)$subject['ref'],
        ['provider'=>'stripe','subject_type'=>$subject['type']],
        (int)$user['id']
    );
    return $session;
}

function coveted_stripe_local_status(string $stripeStatus): string
{
    return match (strtolower(trim($stripeStatus))) {
        'trialing' => 'trialing',
        'active' => 'active',
        'past_due', 'unpaid', 'incomplete' => 'past_due',
        'paused' => 'paused',
        'canceled', 'cancelled' => 'cancelled',
        'incomplete_expired' => 'expired',
        default => 'expired',
    };
}

function coveted_stripe_subscription_subject(array $subscription, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $subscriptionRef = coveted_stripe_object_id($subscription['id'] ?? '');
    $metadata = (array)($subscription['metadata'] ?? []);
    $type = strtolower(trim((string)($metadata['coveted_subject_type'] ?? '')));
    $ref = trim((string)($metadata['coveted_subject_ref'] ?? ''));
    $packageKey = strtolower(trim((string)($metadata['coveted_package_key'] ?? '')));

    $existing = null;
    if ($subscriptionRef !== '') {
        $stmt = $pdo->prepare("SELECT * FROM billing_subscriptions WHERE provider='stripe' AND provider_subscription_ref=? LIMIT 1");
        $stmt->execute([$subscriptionRef]);
        $existing = $stmt->fetch() ?: null;
    }

    if (($type === '' || $ref === '') && $existing) {
        $type = (string)$existing['subject_type'];
        if ($type === 'user') {
            $stmt = $pdo->prepare('SELECT public_id FROM users WHERE id=? LIMIT 1');
            $stmt->execute([(int)$existing['user_id']]);
            $ref = (string)$stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare('SELECT public_id FROM businesses WHERE id=? LIMIT 1');
            $stmt->execute([(int)$existing['business_id']]);
            $ref = (string)$stmt->fetchColumn();
        }
    }

    if ($type === '' || $ref === '') {
        throw new InvalidArgumentException('Stripe subscription is missing Coveted subject metadata.');
    }
    $subject = coveted_stripe_subject_by_ref($type,$ref,$pdo);
    $subject['package_key'] = $packageKey;
    return $subject;
}

function coveted_stripe_sync_subscription(array $subscription, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $subscriptionRef = coveted_stripe_object_id($subscription['id'] ?? '');
    if ($subscriptionRef === '' || !str_starts_with($subscriptionRef,'sub_')) {
        throw new InvalidArgumentException('Stripe subscription reference is invalid.');
    }
    $subject = coveted_stripe_subscription_subject($subscription,$pdo);
    $packageKey = (string)$subject['package_key'];
    $package = $packageKey !== '' ? coveted_service_package($packageKey,$pdo) : null;

    $existingStmt = $pdo->prepare("SELECT * FROM billing_subscriptions WHERE provider='stripe' AND provider_subscription_ref=? LIMIT 1");
    $existingStmt->execute([$subscriptionRef]);
    $existing = $existingStmt->fetch() ?: null;
    if (!$package && $existing) {
        $package = coveted_service_package((int)$existing['package_id'],$pdo);
    }
    if (!$package) {
        throw new InvalidArgumentException('Stripe subscription package is not recognized.');
    }

    $customerRef = coveted_stripe_object_id($subscription['customer'] ?? '');
    $status = coveted_stripe_local_status((string)($subscription['status'] ?? ''));
    $firstItem = (array)($subscription['items']['data'][0] ?? []);
    $periodStart = coveted_stripe_sql_datetime($subscription['current_period_start'] ?? $firstItem['current_period_start'] ?? null);
    $periodEnd = coveted_stripe_sql_datetime($subscription['current_period_end'] ?? $firstItem['current_period_end'] ?? null);
    $cancelAtPeriodEnd = !empty($subscription['cancel_at_period_end']) ? 1 : 0;
    $cancelledAt = coveted_stripe_sql_datetime($subscription['canceled_at'] ?? null);

    if ($customerRef !== '') {
        coveted_stripe_upsert_customer($subject,$customerRef,$pdo);
    }

    if ($existing) {
        $sameSubject = (string)$existing['subject_type'] === (string)$subject['type']
            && (($subject['type'] === 'user' && (int)$existing['user_id'] === (int)$subject['id'])
                || ($subject['type'] === 'business' && (int)$existing['business_id'] === (int)$subject['id']));
        if (!$sameSubject) {
            throw new RuntimeException('Stripe subscription is already linked to another Coveted billing subject.');
        }
        $pdo->prepare(
            'UPDATE billing_subscriptions
             SET package_id=?,provider_customer_ref=?,status=?,current_period_start=?,current_period_end=?,cancel_at_period_end=?,cancelled_at=?,updated_at=NOW()
             WHERE id=?'
        )->execute([
            (int)$package['id'],$customerRef !== '' ? $customerRef : null,$status,$periodStart,$periodEnd,$cancelAtPeriodEnd,$cancelledAt,(int)$existing['id'],
        ]);
        $id = (int)$existing['id'];
    } else {
        $pdo->prepare(
            'INSERT INTO billing_subscriptions
                (public_id,subject_type,user_id,business_id,package_id,provider,provider_customer_ref,provider_subscription_ref,status,current_period_start,current_period_end,cancel_at_period_end,cancelled_at)
             VALUES (?,?,?,?,?,\'stripe\',?,?,?,?,?,?,?)'
        )->execute([
            coveted_uuid('bilsub'),$subject['type'],$subject['type'] === 'user' ? (int)$subject['id'] : null,$subject['type'] === 'business' ? (int)$subject['id'] : null,
            (int)$package['id'],$customerRef !== '' ? $customerRef : null,$subscriptionRef,$status,$periodStart,$periodEnd,$cancelAtPeriodEnd,$cancelledAt,
        ]);
        $id = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->prepare('SELECT * FROM billing_subscriptions WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
}

function coveted_stripe_invoice_subscription_ref(array $invoice): string
{
    $direct = coveted_stripe_object_id($invoice['subscription'] ?? '');
    if ($direct !== '') {
        return $direct;
    }
    $parent = (array)($invoice['parent']['subscription_details'] ?? []);
    return coveted_stripe_object_id($parent['subscription'] ?? '');
}

function coveted_stripe_verify_webhook_signature(string $payload, string $header, ?int $now = null): bool
{
    $settings = coveted_stripe_settings();
    $secret = trim((string)($settings['webhook_secret'] ?? ''));
    if ($secret === '' || $header === '') {
        return false;
    }
    $timestamp = 0;
    $signatures = [];
    foreach (explode(',',$header) as $part) {
        [$key,$value] = array_pad(explode('=',trim($part),2),2,'');
        if ($key === 't') {
            $timestamp = (int)$value;
        } elseif ($key === 'v1' && $value !== '') {
            $signatures[] = $value;
        }
    }
    if ($timestamp < 1 || !$signatures) {
        return false;
    }
    $now ??= time();
    $tolerance = max(60,min(900,(int)($settings['webhook_tolerance_seconds'] ?? 300)));
    if (abs($now-$timestamp) > $tolerance) {
        return false;
    }
    $expected = hash_hmac('sha256',$timestamp . '.' . $payload,$secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected,$signature)) {
            return true;
        }
    }
    return false;
}

function coveted_stripe_claim_webhook(string $eventRef, string $eventType, string $payload, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $digest = hash('sha256',$payload);
    $pdo->prepare(
        "INSERT IGNORE INTO billing_webhook_events (provider,event_ref,event_type,payload_sha256,status,attempts)
         VALUES ('stripe',?,?,?,'received',0)"
    )->execute([$eventRef,$eventType,$digest]);
    $stmt = $pdo->prepare("SELECT * FROM billing_webhook_events WHERE provider='stripe' AND event_ref=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$eventRef]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals((string)$row['payload_sha256'],$digest)) {
        throw new RuntimeException('Stripe webhook event reference collision detected.');
    }
    if ((string)$row['status'] === 'processed') {
        return ['duplicate'=>true,'row'=>$row];
    }
    $pdo->prepare("UPDATE billing_webhook_events SET status='processing',attempts=attempts+1,last_error=NULL,updated_at=NOW() WHERE id=?")
        ->execute([(int)$row['id']]);
    return ['duplicate'=>false,'row'=>$row];
}

function coveted_stripe_process_webhook(array $event, string $payload, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $eventRef = trim((string)($event['id'] ?? ''));
    $eventType = trim((string)($event['type'] ?? ''));
    if ($eventRef === '' || $eventType === '') {
        throw new InvalidArgumentException('Stripe webhook event is incomplete.');
    }

    $pdo->beginTransaction();
    try {
        $claimed = coveted_stripe_claim_webhook($eventRef,$eventType,$payload,$pdo);
        if ($claimed['duplicate']) {
            $pdo->commit();
            return ['duplicate'=>true];
        }

        $object = (array)($event['data']['object'] ?? []);
        if ($eventType === 'checkout.session.completed') {
            $sessionRef = coveted_stripe_object_id($object['id'] ?? '');
            $subscriptionRef = coveted_stripe_object_id($object['subscription'] ?? '');
            if ($sessionRef !== '' && $subscriptionRef !== '') {
                $session = coveted_stripe_api_request('GET','/checkout/sessions/' . rawurlencode($sessionRef),['expand'=>['subscription']]);
                $subscription = is_array($session['subscription'] ?? null)
                    ? (array)$session['subscription']
                    : coveted_stripe_api_request('GET','/subscriptions/' . rawurlencode($subscriptionRef));
                coveted_stripe_sync_subscription($subscription,$pdo);
            }
        } elseif (in_array($eventType,['customer.subscription.created','customer.subscription.updated','customer.subscription.deleted'],true)) {
            coveted_stripe_sync_subscription($object,$pdo);
        } elseif (in_array($eventType,['invoice.paid','invoice.payment_failed'],true)) {
            $subscriptionRef = coveted_stripe_invoice_subscription_ref($object);
            if ($subscriptionRef !== '') {
                $subscription = coveted_stripe_api_request('GET','/subscriptions/' . rawurlencode($subscriptionRef));
                coveted_stripe_sync_subscription($subscription,$pdo);
            }
        }

        $pdo->prepare("UPDATE billing_webhook_events SET status='processed',processed_at=NOW(),updated_at=NOW() WHERE provider='stripe' AND event_ref=?")
            ->execute([$eventRef]);
        $pdo->commit();
        return ['duplicate'=>false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        try {
            $pdo->prepare("UPDATE billing_webhook_events SET status='failed',last_error=?,updated_at=NOW() WHERE provider='stripe' AND event_ref=?")
                ->execute([mb_substr($e->getMessage(),0,1000),$eventRef]);
        } catch (Throwable) {
        }
        throw $e;
    }
}

function coveted_stripe_sync_checkout_return(array $user, string $sessionRef, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    coveted_stripe_require_ready($pdo);
    $sessionRef = trim($sessionRef);
    if ($sessionRef === '' || !str_starts_with($sessionRef,'cs_')) {
        throw new InvalidArgumentException('Invalid Stripe Checkout Session.');
    }
    $session = coveted_stripe_api_request('GET','/checkout/sessions/' . rawurlencode($sessionRef),['expand'=>['subscription']]);
    $checkoutRef = trim((string)($session['client_reference_id'] ?? $session['metadata']['coveted_checkout_ref'] ?? ''));
    if ($checkoutRef === '') {
        throw new InvalidArgumentException('Stripe Checkout Session is missing the Coveted checkout reference.');
    }
    $stmt = $pdo->prepare("SELECT * FROM billing_checkout_sessions WHERE public_id=? AND provider='stripe' LIMIT 1");
    $stmt->execute([$checkoutRef]);
    $checkout = $stmt->fetch();
    if (!$checkout || !hash_equals((string)$checkout['provider_session_ref'],$sessionRef)) {
        throw new InvalidArgumentException('Stripe Checkout Session does not match a Coveted checkout.');
    }
    $subjectType = (string)$checkout['subject_type'];
    $subjectId = $subjectType === 'user' ? (int)$checkout['user_id'] : (int)$checkout['business_id'];
    $subjectStmt = $pdo->prepare($subjectType === 'user' ? 'SELECT public_id FROM users WHERE id=? LIMIT 1' : 'SELECT public_id FROM businesses WHERE id=? LIMIT 1');
    $subjectStmt->execute([$subjectId]);
    $subjectRef = trim((string)$subjectStmt->fetchColumn());
    coveted_stripe_actor_can_manage_subject($user,$subjectType,$subjectRef);

    if ((string)($session['status'] ?? '') !== 'complete' || (string)($session['payment_status'] ?? '') === 'unpaid') {
        throw new InvalidArgumentException('Stripe Checkout has not completed payment setup yet.');
    }
    $subscription = is_array($session['subscription'] ?? null)
        ? (array)$session['subscription']
        : coveted_stripe_api_request('GET','/subscriptions/' . rawurlencode(coveted_stripe_object_id($session['subscription'] ?? '')));
    $synced = coveted_stripe_sync_subscription($subscription,$pdo);
    $pdo->prepare("UPDATE billing_checkout_sessions SET status='completed',updated_at=NOW() WHERE id=?")->execute([(int)$checkout['id']]);
    return ['session'=>$session,'subscription'=>$synced];
}
