<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/businesses.php';

/** @return list<string> */
function coveted_service_user_types(): array
{
    return ['attendee', 'attendee_host', 'artist_partner', 'system_admin'];
}

function coveted_service_packages_schema_available(?PDO $pdo = null): bool
{
    $pdo ??= coveted_db();
    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('service_packages','service_package_entitlements','billing_subscriptions','service_package_assignments')"
        );
        return (int)$stmt->fetchColumn() === 4;
    } catch (Throwable) {
        return false;
    }
}

/** @return list<array<string,mixed>> */
function coveted_service_packages(bool $activeOnly = true, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $sql = 'SELECT * FROM service_packages';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, id';
    return $pdo->query($sql)->fetchAll();
}

function coveted_service_package(int|string $ref, ?PDO $pdo = null): ?array
{
    $pdo ??= coveted_db();
    $value = trim((string)$ref);
    if ($value === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM service_packages WHERE id = ? OR package_key = ? LIMIT 1');
    $stmt->execute([(int)$value, $value]);
    return $stmt->fetch() ?: null;
}

/** @return array<string,string> */
function coveted_service_entitlements_for_package(int $packageId, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $stmt = $pdo->prepare(
        'SELECT entitlement_key, entitlement_value
         FROM service_package_entitlements
         WHERE package_id = ? AND enabled = 1
         ORDER BY entitlement_key'
    );
    $stmt->execute([$packageId]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(string)$row['entitlement_key']] = (string)($row['entitlement_value'] ?? '1');
    }
    return $result;
}

function coveted_service_assignment_active_sql(string $alias = 'a'): string
{
    return "{$alias}.revoked_at IS NULL
        AND ({$alias}.starts_at IS NULL OR {$alias}.starts_at <= NOW())
        AND ({$alias}.ends_at IS NULL OR {$alias}.ends_at > NOW())";
}

function coveted_service_assignment_for_scope(
    string $scopeType,
    int|string $scopeValue,
    ?PDO $pdo = null
): ?array {
    $pdo ??= coveted_db();
    $where = match ($scopeType) {
        'user' => 'a.scope_type = \'user\' AND a.user_id = ?',
        'business' => 'a.scope_type = \'business\' AND a.business_id = ?',
        'user_role' => 'a.scope_type = \'user_role\' AND a.role_key = ?',
        default => throw new InvalidArgumentException('Unsupported package assignment scope.'),
    };
    $stmt = $pdo->prepare(
        "SELECT a.*, p.package_key, p.name AS package_name, p.description AS package_description,
                p.monthly_price_cents, p.currency, p.sort_order, p.is_default, p.is_active
         FROM service_package_assignments a
         JOIN service_packages p ON p.id = a.package_id
         WHERE {$where} AND " . coveted_service_assignment_active_sql('a') . "
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 1"
    );
    $stmt->execute([$scopeValue]);
    return $stmt->fetch() ?: null;
}

/** @return list<array<string,mixed>> */
function coveted_service_subscriptions_for_subject(
    string $subjectType,
    int $subjectId,
    bool $currentOnly = false,
    ?PDO $pdo = null
): array {
    $pdo ??= coveted_db();
    if (!in_array($subjectType, ['user', 'business'], true) || $subjectId < 1) {
        throw new InvalidArgumentException('Invalid billing subject.');
    }
    $column = $subjectType === 'user' ? 's.user_id' : 's.business_id';
    $sql = "SELECT s.*, p.package_key, p.name AS package_name, p.monthly_price_cents, p.currency, p.sort_order
            FROM billing_subscriptions s
            JOIN service_packages p ON p.id = s.package_id
            WHERE s.subject_type = ? AND {$column} = ?";
    if ($currentOnly) {
        $sql .= " AND s.status IN ('trialing','active') AND (s.current_period_end IS NULL OR s.current_period_end > NOW())";
    }
    $sql .= ' ORDER BY s.updated_at DESC, s.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$subjectType, $subjectId]);
    return $stmt->fetchAll();
}

function coveted_service_best_subscription(array $subjects, ?PDO $pdo = null): ?array
{
    $pdo ??= coveted_db();
    $best = null;
    foreach ($subjects as $subject) {
        $type = (string)($subject['type'] ?? '');
        $id = (int)($subject['id'] ?? 0);
        if ($id < 1 || !in_array($type, ['user', 'business'], true)) {
            continue;
        }
        foreach (coveted_service_subscriptions_for_subject($type, $id, true, $pdo) as $row) {
            if ($best === null || (int)$row['sort_order'] > (int)$best['sort_order']) {
                $best = $row;
            }
        }
    }
    return $best;
}

/**
 * Resolve the package used for authorization. Precedence is deliberate:
 * user Admin override > business/partner Admin override > user-type Admin
 * override > paid/trial subscription > active default package.
 *
 * @return array<string,mixed>
 */
function coveted_service_effective_package(array $user, ?int $businessId = null, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid user is required to resolve service access.');
    }

    $assignment = coveted_service_assignment_for_scope('user', $userId, $pdo);
    if ($assignment) {
        $package = $assignment;
        $source = 'user_override';
    } else {
        $package = null;
        $source = '';
    }

    if ($package === null && $businessId !== null && $businessId > 0) {
        $assignment = coveted_service_assignment_for_scope('business', $businessId, $pdo);
        if ($assignment) {
            $package = $assignment;
            $source = 'partner_override';
        }
    }

    if ($package === null) {
        $roles = array_values(array_intersect(coveted_service_user_types(), array_map('strval', (array)($user['roles'] ?? []))));
        $roleCandidates = [];
        foreach ($roles as $role) {
            $candidate = coveted_service_assignment_for_scope('user_role', $role, $pdo);
            if ($candidate) {
                $roleCandidates[] = $candidate;
            }
        }
        usort($roleCandidates, static function (array $a, array $b): int {
            return [(int)$b['sort_order'], (int)$b['id']] <=> [(int)$a['sort_order'], (int)$a['id']];
        });
        if ($roleCandidates) {
            $assignment = $roleCandidates[0];
            $package = $assignment;
            $source = 'user_type_override';
        }
    }

    $subscription = null;
    if ($package === null) {
        $subjects = [];
        if ($businessId !== null && $businessId > 0) {
            $subjects[] = ['type' => 'business', 'id' => $businessId];
        }
        $subjects[] = ['type' => 'user', 'id' => $userId];
        $subscription = coveted_service_best_subscription($subjects, $pdo);
        if ($subscription) {
            $package = coveted_service_package((int)$subscription['package_id'], $pdo);
            $source = 'subscription';
        }
    }

    if ($package === null) {
        $stmt = $pdo->query(
            'SELECT * FROM service_packages WHERE is_active = 1 AND is_default = 1 ORDER BY sort_order, id LIMIT 1'
        );
        $package = $stmt->fetch() ?: null;
        $source = 'default';
    }

    if (!$package) {
        throw new RuntimeException('No active default service package is configured.');
    }

    $packageId = (int)($package['package_id'] ?? $package['id']);
    $canonical = coveted_service_package($packageId, $pdo);
    if (!$canonical) {
        throw new RuntimeException('Resolved service package no longer exists.');
    }

    return [
        'package' => $canonical,
        'source' => $source,
        'assignment' => $source !== 'subscription' && $source !== 'default' ? $assignment : null,
        'subscription' => $subscription,
        'entitlements' => coveted_service_entitlements_for_package($packageId, $pdo),
        'business_id' => $businessId,
    ];
}

function coveted_service_has_entitlement(
    array $user,
    string $entitlementKey,
    ?int $businessId = null,
    ?PDO $pdo = null
): bool {
    $key = strtolower(trim($entitlementKey));
    if ($key === '') {
        return false;
    }
    $effective = coveted_service_effective_package($user, $businessId, $pdo);
    if (!array_key_exists($key, $effective['entitlements'])) {
        return false;
    }
    $value = strtolower(trim((string)$effective['entitlements'][$key]));
    return !in_array($value, ['', '0', 'false', 'off', 'no'], true);
}

function coveted_service_require_entitlement(array $user, string $entitlementKey, ?int $businessId = null): void
{
    if (!coveted_service_has_entitlement($user, $entitlementKey, $businessId)) {
        http_response_code(403);
        exit('Your current Coveted package does not include this feature. Review Billing & Plan for upgrade options.');
    }
}

function coveted_service_parse_money_to_cents(string $value): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', $value)) {
        throw new InvalidArgumentException('Enter a valid monthly price, for example 29.99.');
    }
    [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');
    $decimal = str_pad(substr($decimal, 0, 2), 2, '0');
    return ((int)$whole * 100) + (int)$decimal;
}

function coveted_service_format_price(?int $cents, string $currency = 'USD'): string
{
    if ($cents === null) {
        return 'Not priced';
    }
    return ($currency === 'USD' ? '$' : strtoupper($currency) . ' ') . number_format($cents / 100, 2) . '/month';
}

function coveted_service_save_package(array $admin, array $data, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('Only a System Admin can manage service packages.');
    }
    $pdo ??= coveted_db();
    $id = (int)($data['id'] ?? 0);
    $key = strtolower(trim((string)($data['package_key'] ?? '')));
    $name = trim((string)($data['name'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $priceCents = array_key_exists('monthly_price', $data)
        ? coveted_service_parse_money_to_cents((string)$data['monthly_price'])
        : (isset($data['monthly_price_cents']) ? (int)$data['monthly_price_cents'] : null);
    $currency = strtoupper(trim((string)($data['currency'] ?? 'USD')));
    $sortOrder = max(0, min(10000, (int)($data['sort_order'] ?? 100)));
    $active = !empty($data['is_active']);
    $default = !empty($data['is_default']);

    if ($name === '' || mb_strlen($name) > 100) {
        throw new InvalidArgumentException('Enter a package name under 100 characters.');
    }
    if (mb_strlen($description) > 2000) {
        throw new InvalidArgumentException('Package description is too long.');
    }
    if ($currency === '' || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
        throw new InvalidArgumentException('Use a three-letter currency code.');
    }
    if ($id < 1 && preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/', $key) !== 1) {
        throw new InvalidArgumentException('Package key must be 2–64 lowercase letters, numbers, dots, dashes or underscores.');
    }
    if ($default) {
        $active = true;
    }

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $lock = $pdo->prepare('SELECT * FROM service_packages WHERE id = ? LIMIT 1 FOR UPDATE');
            $lock->execute([$id]);
            $existing = $lock->fetch();
            if (!$existing) {
                throw new InvalidArgumentException('Service package not found.');
            }
            $key = (string)$existing['package_key'];
            if ((int)$existing['is_default'] === 1 && !$default) {
                $otherDefault = $pdo->prepare('SELECT id FROM service_packages WHERE id <> ? AND is_default = 1 AND is_active = 1 LIMIT 1');
                $otherDefault->execute([$id]);
                if (!$otherDefault->fetchColumn()) {
                    throw new InvalidArgumentException('Set another active package as the default before removing this default.');
                }
            }
        } else {
            $existing = null;
        }

        if ($default) {
            $pdo->exec('UPDATE service_packages SET is_default = 0 WHERE is_default = 1');
        }

        if ($id > 0) {
            $pdo->prepare(
                'UPDATE service_packages
                 SET name = ?, description = ?, monthly_price_cents = ?, currency = ?, sort_order = ?, is_active = ?, is_default = ?
                 WHERE id = ?'
            )->execute([$name, $description !== '' ? $description : null, $priceCents, $currency, $sortOrder, $active ? 1 : 0, $default ? 1 : 0, $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO service_packages
                    (package_key, name, description, monthly_price_cents, currency, sort_order, is_active, is_default)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$key, $name, $description !== '' ? $description : null, $priceCents, $currency, $sortOrder, $active ? 1 : 0, $default ? 1 : 0]);
            $id = (int)$pdo->lastInsertId();
        }

        coveted_audit(
            $existing ? 'service_package.updated' : 'service_package.created',
            'service_package',
            $key,
            ['package_id' => $id, 'monthly_price_cents' => $priceCents, 'is_active' => $active, 'is_default' => $default],
            (int)$admin['id']
        );
        $pdo->commit();
        return coveted_service_package($id, $pdo) ?: [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            throw new InvalidArgumentException('That package key is already in use.');
        }
        throw $e;
    }
}

/** @return array<string,string> */
function coveted_service_parse_entitlements(string $raw): array
{
    $result = [];
    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '1');
        $key = strtolower(trim($key));
        $value = trim($value);
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{1,99}$/', $key) !== 1 || mb_strlen($value) > 255) {
            throw new InvalidArgumentException('Entitlements must use key=value lines with safe 2–100 character keys.');
        }
        $result[$key] = $value === '' ? '1' : $value;
    }
    return $result;
}

function coveted_service_replace_entitlements(array $admin, int $packageId, array $entitlements, ?PDO $pdo = null): void
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('Only a System Admin can manage service entitlements.');
    }
    $pdo ??= coveted_db();
    $package = coveted_service_package($packageId, $pdo);
    if (!$package) {
        throw new InvalidArgumentException('Service package not found.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM service_package_entitlements WHERE package_id = ?')->execute([$packageId]);
        $insert = $pdo->prepare(
            'INSERT INTO service_package_entitlements (package_id, entitlement_key, entitlement_value, enabled)
             VALUES (?, ?, ?, 1)'
        );
        foreach ($entitlements as $key => $value) {
            $insert->execute([$packageId, (string)$key, (string)$value]);
        }
        coveted_audit(
            'service_package.entitlements_replaced',
            'service_package',
            (string)$package['package_key'],
            ['entitlement_keys' => array_keys($entitlements)],
            (int)$admin['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return array{scope_type:string,user_id:?int,business_id:?int,role_key:?string,scope_label:string,scope_ref:string} */
function coveted_service_resolve_assignment_scope(
    string $scopeType,
    string $scopeRef,
    ?PDO $pdo = null
): array {
    $pdo ??= coveted_db();
    $scopeType = strtolower(trim($scopeType));
    $scopeRef = trim($scopeRef);
    if ($scopeType === 'user') {
        $stmt = $pdo->prepare(
            "SELECT id, public_id, display_name, email FROM users
             WHERE (CAST(id AS CHAR) = ? OR public_id = ? OR email = ?) AND status <> 'deleted' LIMIT 1"
        );
        $stmt->execute([$scopeRef, $scopeRef, strtolower($scopeRef)]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('User not found.');
        }
        return [
            'scope_type' => 'user', 'user_id' => (int)$row['id'], 'business_id' => null, 'role_key' => null,
            'scope_label' => (string)$row['display_name'] . ' · ' . (string)$row['email'], 'scope_ref' => (string)$row['public_id'],
        ];
    }
    if ($scopeType === 'business') {
        $stmt = $pdo->prepare(
            "SELECT id, public_id, name FROM businesses
             WHERE (CAST(id AS CHAR) = ? OR public_id = ?) AND status <> 'archived' LIMIT 1"
        );
        $stmt->execute([$scopeRef, $scopeRef]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Partner business not found.');
        }
        return [
            'scope_type' => 'business', 'user_id' => null, 'business_id' => (int)$row['id'], 'role_key' => null,
            'scope_label' => (string)$row['name'], 'scope_ref' => (string)$row['public_id'],
        ];
    }
    if ($scopeType === 'user_role') {
        $role = strtolower($scopeRef);
        if (!in_array($role, coveted_service_user_types(), true)) {
            throw new InvalidArgumentException('Choose a valid Coveted user type.');
        }
        return [
            'scope_type' => 'user_role', 'user_id' => null, 'business_id' => null, 'role_key' => $role,
            'scope_label' => ucwords(str_replace('_', ' ', $role)), 'scope_ref' => $role,
        ];
    }
    throw new InvalidArgumentException('Choose User, Partner, or User type for the package assignment.');
}

function coveted_service_assign_package(
    array $admin,
    string $scopeType,
    string $scopeRef,
    int $packageId,
    string $reason = '',
    ?string $startsAt = null,
    ?string $endsAt = null,
    ?PDO $pdo = null
): array {
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('Only a System Admin can bypass billing or assign packages.');
    }
    $pdo ??= coveted_db();
    $reason = trim($reason);
    if (mb_strlen($reason) > 1000) {
        throw new InvalidArgumentException('Keep the assignment reason under 1,000 characters.');
    }
    $scope = coveted_service_resolve_assignment_scope($scopeType, $scopeRef, $pdo);
    $package = coveted_service_package($packageId, $pdo);
    if (!$package || (int)$package['is_active'] !== 1) {
        throw new InvalidArgumentException('Choose an active service package.');
    }
    $packageEntitlements = coveted_service_entitlements_for_package($packageId, $pdo);
    $partnerWorkspaceValue = strtolower(trim((string)($packageEntitlements['partner.workspace'] ?? '')));
    $packageActivatesPartner = $scope['scope_type'] === 'business'
        && !in_array($partnerWorkspaceValue, ['', '0', 'false', 'off', 'no'], true);
    $start = $startsAt !== null && trim($startsAt) !== '' ? coveted_utc_datetime($startsAt)->format('Y-m-d H:i:s') : null;
    $end = $endsAt !== null && trim($endsAt) !== '' ? coveted_utc_datetime($endsAt)->format('Y-m-d H:i:s') : null;
    if ($start !== null && $end !== null && strtotime($end) <= strtotime($start)) {
        throw new InvalidArgumentException('Package assignment end must be after its start.');
    }

    $pdo->beginTransaction();
    try {
        $conditions = ["scope_type = ?", 'revoked_at IS NULL'];
        $params = [$scope['scope_type']];
        if ($scope['scope_type'] === 'user') {
            $conditions[] = 'user_id = ?';
            $params[] = $scope['user_id'];
        } elseif ($scope['scope_type'] === 'business') {
            $conditions[] = 'business_id = ?';
            $params[] = $scope['business_id'];
        } else {
            $conditions[] = 'role_key = ?';
            $params[] = $scope['role_key'];
        }
        $params[] = (int)$admin['id'];
        $pdo->prepare(
            'UPDATE service_package_assignments
             SET revoked_at = NOW(), revoked_by_user_id = ?
             WHERE ' . implode(' AND ', $conditions)
        )->execute(array_merge([(int)$admin['id']], array_slice($params, 0, -1)));

        $publicId = coveted_uuid('pkgasg');
        $pdo->prepare(
            'INSERT INTO service_package_assignments
                (public_id, scope_type, user_id, business_id, role_key, package_id, starts_at, ends_at, reason, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $publicId,
            $scope['scope_type'],
            $scope['user_id'],
            $scope['business_id'],
            $scope['role_key'],
            $packageId,
            $start,
            $end,
            $reason !== '' ? $reason : null,
            (int)$admin['id'],
        ]);

        $assignmentActiveNow = ($start === null || strtotime($start) <= time())
            && ($end === null || strtotime($end) > time());
        if ($packageActivatesPartner && $assignmentActiveNow && (int)($scope['business_id'] ?? 0) > 0) {
            $activate = $pdo->prepare("UPDATE businesses SET status='active',updated_at=NOW() WHERE id=? AND status='prospective'");
            $activate->execute([(int)$scope['business_id']]);
            if ($activate->rowCount() === 1) {
                coveted_audit(
                    'partner.activated_by_admin_package',
                    'business',
                    (string)$scope['scope_ref'],
                    ['package_key'=>(string)$package['package_key'],'assignment_ref'=>$publicId],
                    (int)$admin['id']
                );
            }
        }

        coveted_audit(
            'service_package.assigned',
            'service_package_assignment',
            $publicId,
            [
                'scope_type' => $scope['scope_type'], 'scope_ref' => $scope['scope_ref'], 'scope_label' => $scope['scope_label'],
                'package_key' => (string)$package['package_key'], 'billing_bypass' => true,
                'starts_at' => $start, 'ends_at' => $end, 'reason' => $reason !== '' ? $reason : null,
            ],
            (int)$admin['id']
        );
        $pdo->commit();
        return ['public_id' => $publicId, 'scope' => $scope, 'package' => $package];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_service_revoke_assignment(array $admin, string $assignmentRef, string $reason = '', ?PDO $pdo = null): void
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('Only a System Admin can revoke a package assignment.');
    }
    $pdo ??= coveted_db();
    $assignmentRef = trim($assignmentRef);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT a.*, p.package_key FROM service_package_assignments a
             JOIN service_packages p ON p.id = a.package_id
             WHERE (a.public_id = ? OR CAST(a.id AS CHAR) = ?) LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$assignmentRef, $assignmentRef]);
        $assignment = $stmt->fetch();
        if (!$assignment || $assignment['revoked_at'] !== null) {
            throw new InvalidArgumentException('Active package assignment not found.');
        }
        $pdo->prepare(
            'UPDATE service_package_assignments SET revoked_at = NOW(), revoked_by_user_id = ? WHERE id = ?'
        )->execute([(int)$admin['id'], (int)$assignment['id']]);
        coveted_audit(
            'service_package.assignment_revoked',
            'service_package_assignment',
            (string)$assignment['public_id'],
            ['package_key' => (string)$assignment['package_key'], 'reason' => trim($reason) ?: null],
            (int)$admin['id']
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return list<array<string,mixed>> */
function coveted_service_active_assignments(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    $stmt = $pdo->query(
        "SELECT a.*, p.package_key, p.name AS package_name,
                u.display_name AS user_name, u.email AS user_email, u.public_id AS user_ref,
                b.name AS business_name, b.public_id AS business_ref
         FROM service_package_assignments a
         JOIN service_packages p ON p.id = a.package_id
         LEFT JOIN users u ON u.id = a.user_id
         LEFT JOIN businesses b ON b.id = a.business_id
         WHERE " . coveted_service_assignment_active_sql('a') . "
         ORDER BY FIELD(a.scope_type,'user','business','user_role'), a.created_at DESC"
    );
    return $stmt->fetchAll();
}

function coveted_service_assignment_label(array $assignment): string
{
    return match ((string)$assignment['scope_type']) {
        'user' => trim((string)($assignment['user_name'] ?? 'User')) . ' · ' . trim((string)($assignment['user_email'] ?? '')),
        'business' => (string)($assignment['business_name'] ?? 'Partner business'),
        'user_role' => ucwords(str_replace('_', ' ', (string)($assignment['role_key'] ?? 'User type'))),
        default => 'Unknown scope',
    };
}

/** @return list<array<string,mixed>> */
function coveted_service_admin_users(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    return $pdo->query(
        "SELECT id, public_id, display_name, email, status
         FROM users WHERE status <> 'deleted' ORDER BY display_name, email, id LIMIT 500"
    )->fetchAll();
}

/** @return list<array<string,mixed>> */
function coveted_service_admin_businesses(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    return $pdo->query(
        "SELECT id, public_id, name, status
         FROM businesses WHERE status <> 'archived' ORDER BY name, id LIMIT 500"
    )->fetchAll();
}
