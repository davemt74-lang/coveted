<?php
declare(strict_types=1);

require_once __DIR__ . '/businesses.php';

/**
 * Create a prospective partner business owned by the authenticated member.
 * This is deliberately separate from the System Admin business-creation path:
 * self-service creation never grants platform roles and only creates a
 * resource-scoped business_admin relationship.
 */
function coveted_partner_create_self_service(array $user, string $name, string $description = '', ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $userId = (int)($user['id'] ?? 0);
    $name = trim($name);
    $description = trim($description);

    if ($userId < 1 || (string)($user['status'] ?? '') !== 'active') {
        throw new InvalidArgumentException('An active Coveted account is required to create a partner account.');
    }
    if ($name === '' || mb_strlen($name) > 180) {
        throw new InvalidArgumentException('Enter a business name under 180 characters.');
    }
    if (mb_strlen($description) > 4000) {
        throw new InvalidArgumentException('Business description is too long.');
    }

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT status FROM users WHERE id = ? LIMIT 1 FOR UPDATE");
        $lock->execute([$userId]);
        if ($lock->fetchColumn() !== 'active') {
            throw new InvalidArgumentException('Your Coveted account is not currently active.');
        }

        // Reuse an existing same-name business this member already administers
        // instead of creating accidental duplicates after a double submit.
        $existing = $pdo->prepare(
            "SELECT b.id,b.public_id,b.name,b.status
             FROM business_admins ba
             JOIN businesses b ON b.id = ba.business_id
             WHERE ba.user_id = ? AND b.name = ? AND b.status <> 'archived'
             ORDER BY b.id DESC LIMIT 1 FOR UPDATE"
        );
        $existing->execute([$userId, $name]);
        $row = $existing->fetch();
        if ($row) {
            $pdo->commit();
            return [
                'id' => (int)$row['id'],
                'public_id' => (string)$row['public_id'],
                'name' => (string)$row['name'],
                'status' => (string)$row['status'],
                'reused' => true,
            ];
        }

        $publicId = coveted_uuid('biz');
        $pdo->prepare(
            "INSERT INTO businesses (public_id,name,description,status,created_by)
             VALUES (?,?,?,'prospective',?)"
        )->execute([
            $publicId,
            $name,
            $description !== '' ? $description : null,
            $userId,
        ]);
        $businessId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO business_admins (business_id,user_id) VALUES (?,?)')
            ->execute([$businessId,$userId]);

        coveted_audit(
            'partner.self_service_created',
            'business',
            $publicId,
            ['name'=>$name,'status'=>'prospective','first_business_admin_user_id'=>$userId],
            $userId
        );
        $pdo->commit();

        return [
            'id'=>$businessId,
            'public_id'=>$publicId,
            'name'=>$name,
            'status'=>'prospective',
            'reused'=>false,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_partner_activate_business(int $businessId, string $businessRef, string $billingStatus, ?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    $billingStatus = strtolower(trim($billingStatus));
    if ($businessId < 1 || !in_array($billingStatus,['active','trialing'],true)) {
        return false;
    }

    $stmt = $pdo->prepare("UPDATE businesses SET status='active',updated_at=NOW() WHERE id=? AND status='prospective'");
    $stmt->execute([$businessId]);
    if ($stmt->rowCount() !== 1) {
        return false;
    }

    coveted_audit(
        'partner.activated_by_subscription',
        'business',
        $businessRef !== '' ? $businessRef : (string)$businessId,
        ['billing_status'=>$billingStatus],
        0
    );
    return true;
}

/**
 * Run before a signed Stripe subscription webhook is marked processed. This
 * keeps activation retry-safe even if the later provider-neutral sync fails.
 */
function coveted_partner_activate_from_stripe_subscription_payload(array $subscription, ?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    $status = strtolower(trim((string)($subscription['status'] ?? '')));
    if (!in_array($status,['active','trialing'],true)) {
        return false;
    }

    $metadata = (array)($subscription['metadata'] ?? []);
    if (strtolower(trim((string)($metadata['coveted_subject_type'] ?? ''))) !== 'business') {
        return false;
    }
    $businessRef = trim((string)($metadata['coveted_subject_ref'] ?? ''));
    if ($businessRef === '') {
        return false;
    }

    $stmt = $pdo->prepare("SELECT id FROM businesses WHERE public_id=? AND status<>'archived' LIMIT 1");
    $stmt->execute([$businessRef]);
    $businessId = (int)$stmt->fetchColumn();
    if ($businessId < 1) {
        throw new RuntimeException('Stripe subscription references an unavailable Coveted partner.');
    }

    return coveted_partner_activate_business($businessId,$businessRef,$status,$pdo);
}

/**
 * Paid/trial business subscriptions promote self-created prospective partners
 * to active. Cancellation does not archive or delete partner data; package
 * entitlements decide paid feature access independently of business existence.
 */
function coveted_partner_activate_from_billing_result(array $result, ?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    $activated = false;

    $walk = function (mixed $value) use (&$walk,&$activated,$pdo): void {
        if (!is_array($value)) {
            return;
        }
        $subject = isset($value['subject']) && is_array($value['subject']) ? $value['subject'] : null;
        $status = strtolower(trim((string)($value['status'] ?? '')));
        if ($subject && (string)($subject['type'] ?? '') === 'business') {
            $businessId = (int)($subject['id'] ?? 0);
            if ($businessId > 0 && coveted_partner_activate_business(
                $businessId,
                (string)($subject['ref'] ?? ''),
                $status,
                $pdo
            )) {
                $activated = true;
            }
        }
        foreach ($value as $nested) {
            if (is_array($nested)) {
                $walk($nested);
            }
        }
    };
    $walk($result);
    return $activated;
}
