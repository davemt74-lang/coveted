<?php
declare(strict_types=1);

require_once __DIR__ . '/site_settings.php';

const COVETED_SETTING_BILLING_GRACE_DAYS = 'billing_grace_days';
const COVETED_BILLING_DEFAULT_GRACE_DAYS = 7;
const COVETED_BILLING_MAX_GRACE_DAYS = 30;

function coveted_subscription_lifecycle_grace_days(?PDO $pdo = null): int
{
    $pdo ??= coveted_db();
    $raw = coveted_site_setting_get(
        COVETED_SETTING_BILLING_GRACE_DAYS,
        (string)COVETED_BILLING_DEFAULT_GRACE_DAYS,
        $pdo
    );
    if (!is_string($raw) || preg_match('/^\d{1,2}$/', trim($raw)) !== 1) {
        return COVETED_BILLING_DEFAULT_GRACE_DAYS;
    }
    return max(0, min(COVETED_BILLING_MAX_GRACE_DAYS, (int)$raw));
}

function coveted_subscription_lifecycle_set_grace_days(array $admin, int $days, ?PDO $pdo = null): void
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('Only a System Admin can change billing grace policy.');
    }
    if ($days < 0 || $days > COVETED_BILLING_MAX_GRACE_DAYS) {
        throw new InvalidArgumentException('Grace period must be between 0 and ' . COVETED_BILLING_MAX_GRACE_DAYS . ' days.');
    }
    coveted_site_setting_set(COVETED_SETTING_BILLING_GRACE_DAYS, (string)$days, $admin, $pdo);
}

function coveted_subscription_lifecycle_sql_time(mixed $value): ?DateTimeImmutable
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable) {
        return null;
    }
}

/**
 * Return the first unresolved payment-failure timestamp for this canonical
 * subscription. Recovery events close the preceding failure episode, so
 * repeated provider syncs cannot extend a grace period.
 */
function coveted_subscription_lifecycle_failure_since(array $subscription, ?PDO $pdo = null): ?DateTimeImmutable
{
    $pdo ??= coveted_db();
    $publicId = trim((string)($subscription['public_id'] ?? ''));
    if ($publicId === '') {
        return null;
    }
    try {
        $recovery = $pdo->prepare(
            "SELECT MAX(created_at) FROM audit_events
             WHERE entity_type='billing_subscription' AND entity_id=?
               AND event_type='billing.payment_recovered'"
        );
        $recovery->execute([$publicId]);
        $recoveredAt = trim((string)$recovery->fetchColumn());

        $sql = "SELECT MIN(created_at) FROM audit_events
                WHERE entity_type='billing_subscription' AND entity_id=?
                  AND event_type='billing.payment_failed'";
        $params = [$publicId];
        if ($recoveredAt !== '') {
            $sql .= ' AND created_at > ?';
            $params[] = $recoveredAt;
        }
        $failed = $pdo->prepare($sql);
        $failed->execute($params);
        return coveted_subscription_lifecycle_sql_time($failed->fetchColumn());
    } catch (Throwable $e) {
        error_log('Billing lifecycle audit read failed: ' . $e->getMessage());
        return null;
    }
}

function coveted_subscription_lifecycle_record_failure(
    array $subscription,
    array $metadata = [],
    ?PDO $pdo = null
): void {
    $pdo ??= coveted_db();
    $publicId = trim((string)($subscription['public_id'] ?? ''));
    if ($publicId === '' || coveted_subscription_lifecycle_failure_since($subscription, $pdo) !== null) {
        return;
    }
    coveted_audit('billing.payment_failed', 'billing_subscription', $publicId, $metadata, 0);
}

function coveted_subscription_lifecycle_record_recovery(
    array $subscription,
    array $metadata = [],
    ?PDO $pdo = null
): void {
    $pdo ??= coveted_db();
    $publicId = trim((string)($subscription['public_id'] ?? ''));
    if ($publicId === '' || coveted_subscription_lifecycle_failure_since($subscription, $pdo) === null) {
        return;
    }
    coveted_audit('billing.payment_recovered', 'billing_subscription', $publicId, $metadata, 0);
}

/**
 * Provider-neutral paid-access state. Providers synchronize status into the
 * canonical billing record; authorization uses only local status, canonical
 * billing lifecycle events and the Admin-configured grace policy.
 *
 * @return array{access:bool,state:string,label:string,grace_days:int,past_due_since:?string,grace_until:?string,days_remaining:int}
 */
function coveted_subscription_lifecycle_access_state(
    array $subscription,
    ?PDO $pdo = null,
    ?DateTimeImmutable $now = null
): array {
    $pdo ??= coveted_db();
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $status = strtolower(trim((string)($subscription['status'] ?? '')));
    $graceDays = coveted_subscription_lifecycle_grace_days($pdo);

    $base = [
        'access' => false,
        'state' => $status !== '' ? $status : 'unknown',
        'label' => $status !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Unknown',
        'grace_days' => $graceDays,
        'past_due_since' => null,
        'grace_until' => null,
        'days_remaining' => 0,
    ];

    if (in_array($status, ['trialing','active'], true)) {
        $periodEnd = coveted_subscription_lifecycle_sql_time($subscription['current_period_end'] ?? null);
        if ($periodEnd !== null && $periodEnd <= $now) {
            $base['state'] = 'period_expired';
            $base['label'] = 'Billing period expired';
            return $base;
        }
        $base['access'] = true;
        $base['label'] = $status === 'trialing' ? 'Trialing' : 'Active';
        return $base;
    }

    if ($status !== 'past_due') {
        return $base;
    }

    $since = coveted_subscription_lifecycle_failure_since($subscription, $pdo)
        ?? coveted_subscription_lifecycle_sql_time($subscription['updated_at'] ?? null);
    if ($since === null || $graceDays === 0) {
        $base['state'] = 'past_due_expired';
        $base['label'] = 'Past due · access paused';
        $base['past_due_since'] = $since?->format('Y-m-d H:i:s');
        return $base;
    }

    $graceUntil = $since->modify('+' . $graceDays . ' days');
    $seconds = $graceUntil->getTimestamp() - $now->getTimestamp();
    $base['past_due_since'] = $since->format('Y-m-d H:i:s');
    $base['grace_until'] = $graceUntil->format('Y-m-d H:i:s');
    $base['days_remaining'] = max(0, (int)ceil($seconds / 86400));
    if ($seconds > 0) {
        $base['access'] = true;
        $base['state'] = 'past_due_grace';
        $base['label'] = 'Past due · grace period';
    } else {
        $base['state'] = 'past_due_expired';
        $base['label'] = 'Past due · access paused';
    }
    return $base;
}

function coveted_subscription_lifecycle_allows_access(array $subscription, ?PDO $pdo = null): bool
{
    return coveted_subscription_lifecycle_access_state($subscription, $pdo)['access'];
}

function coveted_subscription_lifecycle_is_open(array $subscription): bool
{
    return in_array(
        strtolower(trim((string)($subscription['status'] ?? ''))),
        ['trialing','active','past_due','paused'],
        true
    );
}

/** @return array{state:string,title:string,message:string,action_label:string} */
function coveted_subscription_lifecycle_customer_message(array $subscription, ?PDO $pdo = null): array
{
    $state = coveted_subscription_lifecycle_access_state($subscription, $pdo);
    if ($state['state'] === 'past_due_grace') {
        $days = max(1, (int)$state['days_remaining']);
        return [
            'state' => 'past_due_grace',
            'title' => 'Payment needs attention',
            'message' => 'Your paid Coveted access is still available during a ' . (int)$state['grace_days'] . '-day grace period. Update your payment method to avoid interruption. About ' . $days . ' day' . ($days === 1 ? '' : 's') . ' remain.',
            'action_label' => 'Update payment method',
        ];
    }
    if ($state['state'] === 'past_due_expired') {
        return [
            'state' => 'past_due_expired',
            'title' => 'Paid access is paused',
            'message' => 'The payment grace period has ended. Your account remains intact; paid features will restore automatically when the subscription returns to active status.',
            'action_label' => 'Resolve billing',
        ];
    }
    if (!empty($subscription['cancel_at_period_end']) && in_array((string)($subscription['status'] ?? ''), ['trialing','active'], true)) {
        return [
            'state' => 'scheduled_cancel',
            'title' => 'Cancellation scheduled',
            'message' => 'Your paid access remains available through the current billing period. You can review or change the cancellation in billing management.',
            'action_label' => 'Manage billing',
        ];
    }
    return [
        'state' => (string)$state['state'],
        'title' => '',
        'message' => '',
        'action_label' => 'Manage billing',
    ];
}

/** @return array{past_due_grace:int,past_due_expired:int} */
function coveted_subscription_lifecycle_past_due_counts(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $result = ['past_due_grace' => 0, 'past_due_expired' => 0];
    try {
        $rows = $pdo->query("SELECT * FROM billing_subscriptions WHERE status='past_due' ORDER BY id")->fetchAll();
        foreach ($rows as $row) {
            $state = coveted_subscription_lifecycle_access_state($row, $pdo);
            if ($state['state'] === 'past_due_grace') {
                $result['past_due_grace']++;
            } else {
                $result['past_due_expired']++;
            }
        }
    } catch (Throwable $e) {
        error_log('Billing lifecycle counts unavailable: ' . $e->getMessage());
    }
    return $result;
}
