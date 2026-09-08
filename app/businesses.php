<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function coveted_business_by_ref(string $ref): ?array
{
    $stmt = coveted_db()->prepare(
        'SELECT * FROM businesses WHERE public_id = ? OR CAST(id AS CHAR) = ? LIMIT 1'
    );
    $stmt->execute([$ref, $ref]);
    $business = $stmt->fetch();

    return $business ?: null;
}

function coveted_businesses_for_actor(array $actor): array
{
    $pdo = coveted_db();

    if (coveted_is_system_admin($actor)) {
        return $pdo->query(
            "SELECT b.*, 'system_admin' AS permission_level
             FROM businesses b
             WHERE b.status <> 'archived'
             ORDER BY b.name, b.id"
        )->fetchAll();
    }

    $stmt = $pdo->prepare(
        "SELECT b.*, 'business_admin' AS permission_level
         FROM business_admins ba
         JOIN businesses b ON b.id = ba.business_id
         WHERE ba.user_id = ?
           AND b.status <> 'archived'
         ORDER BY b.name, b.id"
    );
    $stmt->execute([(int)$actor['id']]);

    return $stmt->fetchAll();
}

function coveted_business_actor_permission(array $actor, int $businessId): ?string
{
    if (coveted_is_system_admin($actor)) {
        return 'system_admin';
    }

    $stmt = coveted_db()->prepare(
        'SELECT 1 FROM business_admins WHERE business_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$businessId, (int)$actor['id']]);

    return $stmt->fetchColumn() ? 'business_admin' : null;
}

function coveted_business_actor_can_manage(array $actor, int $businessId): bool
{
    return coveted_business_actor_permission($actor, $businessId) !== null;
}

function coveted_business_actor_can_view(array $actor, int $businessId): bool
{
    return coveted_business_actor_can_manage($actor, $businessId);
}

function coveted_business_resolve_context(array $actor, string $requestedRef = ''): ?array
{
    $businesses = coveted_businesses_for_actor($actor);
    if (!$businesses) {
        return null;
    }

    if ($requestedRef !== '') {
        foreach ($businesses as $business) {
            if (
                hash_equals((string)$business['public_id'], $requestedRef)
                || (string)$business['id'] === $requestedRef
            ) {
                return $business;
            }
        }

        throw new InvalidArgumentException('Business not found or unavailable to this account.');
    }

    return $businesses[0];
}

function coveted_business_require_mutable(array $actor, int $businessId): array
{
    if (!coveted_business_actor_can_manage($actor, $businessId)) {
        throw new InvalidArgumentException('You cannot manage this business.');
    }

    $stmt = coveted_db()->prepare('SELECT * FROM businesses WHERE id = ? LIMIT 1');
    $stmt->execute([$businessId]);
    $business = $stmt->fetch();

    if (!$business || $business['status'] === 'archived') {
        throw new InvalidArgumentException('This business is archived and cannot be changed.');
    }

    return $business;
}

function coveted_business_create(
    array $actor,
    string $name,
    string $description = '',
    ?int $initialAdminUserId = null
): array {
    if (!coveted_is_system_admin($actor)) {
        throw new InvalidArgumentException('Only a System Admin can create a business.');
    }

    $name = trim($name);
    $description = trim($description);

    if ($name === '' || mb_strlen($name) > 180) {
        throw new InvalidArgumentException('Enter a business name.');
    }
    if (mb_strlen($description) > 4000) {
        throw new InvalidArgumentException('Business description is too long.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        if ($initialAdminUserId !== null) {
            $userStmt = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            $userStmt->execute([$initialAdminUserId]);
            if ($userStmt->fetchColumn() !== 'active') {
                throw new InvalidArgumentException('Initial Business Admin account must be active.');
            }
        }

        $publicId = coveted_uuid('biz');
        $pdo->prepare(
            "INSERT INTO businesses (public_id, name, description, status, created_by)
             VALUES (?, ?, ?, 'active', ?)"
        )->execute([
            $publicId,
            $name,
            $description !== '' ? $description : null,
            (int)$actor['id'],
        ]);
        $businessId = (int)$pdo->lastInsertId();

        if ($initialAdminUserId !== null) {
            $pdo->prepare(
                'INSERT INTO business_admins (business_id, user_id) VALUES (?, ?)'
            )->execute([$businessId, $initialAdminUserId]);
        }

        coveted_audit(
            'business.created',
            'business',
            $publicId,
            ['name' => $name, 'initial_admin_user_id' => $initialAdminUserId],
            (int)$actor['id']
        );

        $pdo->commit();
        return ['id' => $businessId, 'public_id' => $publicId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_business_add_admin(array $actor, int $businessId, int $userId): void
{
    coveted_business_require_mutable($actor, $businessId);

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $businessLock = $pdo->prepare('SELECT id FROM businesses WHERE id = ? FOR UPDATE');
        $businessLock->execute([$businessId]);
        if (!$businessLock->fetchColumn()) {
            throw new InvalidArgumentException('Business not found.');
        }

        $stmt = $pdo->prepare('SELECT public_id, status FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user || $user['status'] !== 'active') {
            throw new InvalidArgumentException('Business Admin account must be active.');
        }

        $pdo->prepare(
            'INSERT IGNORE INTO business_admins (business_id, user_id) VALUES (?, ?)'
        )->execute([$businessId, $userId]);

        coveted_audit(
            'business.admin_added',
            'business',
            (string)$businessId,
            ['user_id' => $userId],
            (int)$actor['id']
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_business_remove_admin(array $actor, int $businessId, int $userId): void
{
    coveted_business_require_mutable($actor, $businessId);

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $businessStmt = $pdo->prepare('SELECT status FROM businesses WHERE id = ? LIMIT 1 FOR UPDATE');
        $businessStmt->execute([$businessId]);
        $businessStatus = $businessStmt->fetchColumn();
        if ($businessStatus === false) {
            throw new InvalidArgumentException('Business not found.');
        }

        $admins = $pdo->prepare(
            'SELECT user_id FROM business_admins WHERE business_id = ? ORDER BY user_id FOR UPDATE'
        );
        $admins->execute([$businessId]);
        $adminIds = array_map('intval', array_column($admins->fetchAll(), 'user_id'));

        if (!in_array($userId, $adminIds, true)) {
            throw new InvalidArgumentException('Business Admin not found.');
        }

        if ($businessStatus === 'active' && count($adminIds) <= 1) {
            throw new InvalidArgumentException('An active business must keep at least one Business Admin.');
        }

        $pdo->prepare('DELETE FROM business_admins WHERE business_id = ? AND user_id = ?')
            ->execute([$businessId, $userId]);

        coveted_audit(
            'business.admin_removed',
            'business',
            (string)$businessId,
            ['user_id' => $userId],
            (int)$actor['id']
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_location_by_ref(string $ref): ?array
{
    $stmt = coveted_db()->prepare(
        'SELECT * FROM locations WHERE public_id = ? OR CAST(id AS CHAR) = ? LIMIT 1'
    );
    $stmt->execute([$ref, $ref]);
    $location = $stmt->fetch();

    return $location ?: null;
}

function coveted_locations_for_business(int $businessId, bool $activeOnly = false): array
{
    $sql = 'SELECT * FROM locations WHERE business_id = ?';
    if ($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= ' ORDER BY name, id';

    $stmt = coveted_db()->prepare($sql);
    $stmt->execute([$businessId]);
    return $stmt->fetchAll();
}

function coveted_locations_for_businesses(array $businessIds): array
{
    $businessIds = array_values(array_unique(array_filter(array_map('intval', $businessIds), static fn(int $id): bool => $id > 0)));
    if (!$businessIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($businessIds), '?'));
    $stmt = coveted_db()->prepare(
        "SELECT * FROM locations
         WHERE business_id IN ({$placeholders}) AND status = 'active'
         ORDER BY business_id, name, id"
    );
    $stmt->execute($businessIds);
    return $stmt->fetchAll();
}

function coveted_location_create(array $actor, int $businessId, array $data): array
{
    coveted_business_require_mutable($actor, $businessId);

    $name = trim((string)($data['name'] ?? ''));
    $address1 = trim((string)($data['address1'] ?? ''));
    $address2 = trim((string)($data['address2'] ?? ''));
    $city = trim((string)($data['city'] ?? ''));
    $region = trim((string)($data['region'] ?? ''));
    $postalCode = trim((string)($data['postal_code'] ?? ''));
    $country = strtoupper(trim((string)($data['country'] ?? 'US')));
    $timezone = coveted_require_timezone((string)($data['timezone'] ?? ''));

    if ($name === '' || mb_strlen($name) > 180) {
        throw new InvalidArgumentException('Enter a location name.');
    }
    foreach ([$address1, $address2, $city, $region, $postalCode] as $value) {
        if (mb_strlen($value) > 255) {
            throw new InvalidArgumentException('Location information is too long.');
        }
    }
    if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
        throw new InvalidArgumentException('Use a two-letter country code.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $publicId = coveted_uuid('loc');
        $pdo->prepare(
            "INSERT INTO locations
                (public_id, business_id, name, address1, address2, city, region, postal_code,
                 country, timezone, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
        )->execute([
            $publicId,
            $businessId,
            $name,
            $address1 !== '' ? $address1 : null,
            $address2 !== '' ? $address2 : null,
            $city !== '' ? $city : null,
            $region !== '' ? $region : null,
            $postalCode !== '' ? $postalCode : null,
            $country,
            $timezone->getName(),
        ]);
        $locationId = (int)$pdo->lastInsertId();

        coveted_audit(
            'location.created',
            'location',
            $publicId,
            ['business_id' => $businessId],
            (int)$actor['id']
        );

        $pdo->commit();
        return ['id' => $locationId, 'public_id' => $publicId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_claim_code_validate(string $claimCode): string
{
    $claimCode = strtoupper(trim($claimCode));
    if (preg_match('/^[A-Z0-9]{5,10}$/', $claimCode) !== 1) {
        throw new InvalidArgumentException('Claim code must be 5–10 letters or numbers.');
    }

    return $claimCode;
}

function coveted_claim_code_lookup_key(): string
{
    $key = trim((string)(coveted_config('app')['claim_code_lookup_key'] ?? ''));
    if (strlen($key) < 32) {
        throw new RuntimeException('Coveted claim_code_lookup_key must be configured with at least 32 characters.');
    }

    return $key;
}

function coveted_claim_code_lookup(string $claimCode): string
{
    return hash_hmac('sha256', coveted_claim_code_validate($claimCode), coveted_claim_code_lookup_key());
}

function coveted_claim_codes_for_business(int $businessId, bool $activeOnly = false): array
{
    $sql = "SELECT cc.*, l.name AS location_name
            FROM business_claim_codes cc
            LEFT JOIN locations l ON l.id = cc.location_id
            WHERE cc.business_id = ?";
    if ($activeOnly) {
        $sql .= " AND cc.status = 'active'";
    }
    $sql .= ' ORDER BY cc.label, cc.id';

    $stmt = coveted_db()->prepare($sql);
    $stmt->execute([$businessId]);
    return $stmt->fetchAll();
}

function coveted_claim_code_create(array $actor, int $businessId, array $data): array
{
    coveted_business_require_mutable($actor, $businessId);

    $codeType = strtolower(trim((string)($data['code_type'] ?? 'location')));
    $label = trim((string)($data['label'] ?? ''));
    $claimCode = coveted_claim_code_validate((string)($data['claim_code'] ?? ''));
    $codeLookup = coveted_claim_code_lookup($claimCode);
    $locationId = isset($data['location_id']) && (int)$data['location_id'] > 0
        ? (int)$data['location_id']
        : null;

    if (!in_array($codeType, ['location', 'employee'], true)) {
        throw new InvalidArgumentException('Invalid claim code type.');
    }
    if ($label === '' || mb_strlen($label) > 180) {
        throw new InvalidArgumentException('Enter a claim code label.');
    }
    if ($codeType === 'location' && $locationId === null) {
        throw new InvalidArgumentException('Location claim codes must be assigned to a location.');
    }

    if ($locationId !== null) {
        $stmt = coveted_db()->prepare(
            "SELECT 1
             FROM locations
             WHERE id = ? AND business_id = ? AND status <> 'archived'
             LIMIT 1"
        );
        $stmt->execute([$locationId, $businessId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Claim-code location is not part of this business.');
        }
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $publicId = coveted_uuid('clmcode');
        $pdo->prepare(
            "INSERT INTO business_claim_codes
                (public_id, business_id, location_id, code_type, label, code_lookup, code_hash, status, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)"
        )->execute([
            $publicId,
            $businessId,
            $locationId,
            $codeType,
            $label,
            $codeLookup,
            password_hash($claimCode, PASSWORD_DEFAULT),
            (int)$actor['id'],
        ]);
        $claimCodeId = (int)$pdo->lastInsertId();

        coveted_audit(
            'business.claim_code_created',
            'business_claim_code',
            $publicId,
            [
                'business_id' => $businessId,
                'location_id' => $locationId,
                'code_type' => $codeType,
                'label' => $label,
            ],
            (int)$actor['id']
        );

        $pdo->commit();
        return ['id' => $claimCodeId, 'public_id' => $publicId];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string)$e->getCode() === '23000') {
            throw new InvalidArgumentException('That claim code is already in use for this business.', 0, $e);
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_claim_code_rotate(array $actor, int $claimCodeId, string $claimCode): void
{
    $claimCode = coveted_claim_code_validate($claimCode);
    $codeLookup = coveted_claim_code_lookup($claimCode);
    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM business_claim_codes WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$claimCodeId]);
        $row = $stmt->fetch();

        if (!$row || !coveted_business_actor_can_manage($actor, (int)$row['business_id'])) {
            throw new InvalidArgumentException('You cannot change this claim code.');
        }
        coveted_business_require_mutable($actor, (int)$row['business_id']);

        $pdo->prepare(
            'UPDATE business_claim_codes
             SET code_lookup = ?, code_hash = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([$codeLookup, password_hash($claimCode, PASSWORD_DEFAULT), $claimCodeId]);

        coveted_audit(
            'business.claim_code_rotated',
            'business_claim_code',
            (string)$row['public_id'],
            [],
            (int)$actor['id']
        );

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string)$e->getCode() === '23000') {
            throw new InvalidArgumentException('That claim code is already in use for this business.', 0, $e);
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_claim_code_set_status(array $actor, int $claimCodeId, string $status): void
{
    if (!in_array($status, ['active', 'paused', 'archived'], true)) {
        throw new InvalidArgumentException('Invalid claim-code status.');
    }

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'SELECT public_id, business_id FROM business_claim_codes WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$claimCodeId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new InvalidArgumentException('Claim code not found.');
        }
        coveted_business_require_mutable($actor, (int)$row['business_id']);

        $pdo->prepare('UPDATE business_claim_codes SET status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$status, $claimCodeId]);

        coveted_audit(
            'business.claim_code_status',
            'business_claim_code',
            (string)$row['public_id'],
            ['status' => $status],
            (int)$actor['id']
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return array<int,array{key:string,limit:int}> */
function coveted_claim_attempt_entries(int $userId, int $issuanceId): array
{
    return [
        [
            'key' => hash('sha256', 'claim-member-issuance|' . $userId . '|' . $issuanceId),
            'limit' => 8,
        ],
        [
            'key' => hash('sha256', 'claim-member-ip|' . $userId . '|' . coveted_client_ip()),
            'limit' => 40,
        ],
    ];
}

function coveted_claim_assert_attempt_allowed(int $userId, int $issuanceId): void
{
    $entries = coveted_claim_attempt_entries($userId, $issuanceId);
    $keys = array_column($entries, 'key');
    $placeholders = implode(',', array_fill(0, count($keys), '?'));

    $stmt = coveted_db()->prepare(
        "SELECT blocked_until FROM claim_attempts WHERE attempt_key IN ({$placeholders})"
    );
    $stmt->execute($keys);

    foreach ($stmt->fetchAll() as $row) {
        if (!empty($row['blocked_until']) && strtotime((string)$row['blocked_until']) > time()) {
            throw new InvalidArgumentException('Too many incorrect claim-code attempts. Try again later.');
        }
    }
}

function coveted_claim_record_failure(int $userId, int $issuanceId): void
{
    $entries = coveted_claim_attempt_entries($userId, $issuanceId);
    usort($entries, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));

    $pdo = coveted_db();
    $pdo->beginTransaction();

    try {
        foreach ($entries as $entry) {
            $pdo->prepare(
                'INSERT INTO claim_attempts (attempt_key, failures, window_started_at, updated_at)
                 VALUES (?, 0, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE updated_at = updated_at'
            )->execute([$entry['key']]);

            $stmt = $pdo->prepare(
                'SELECT id, failures, window_started_at
                 FROM claim_attempts
                 WHERE attempt_key = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([$entry['key']]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('Unable to update claim-code throttle.');
            }

            $windowFresh = strtotime((string)$row['window_started_at']) >= time() - 900;
            $failures = ($windowFresh ? (int)$row['failures'] : 0) + 1;
            $blockedUntil = $failures >= (int)$entry['limit']
                ? date('Y-m-d H:i:s', time() + 900)
                : null;
            $windowStartedAt = $windowFresh
                ? (string)$row['window_started_at']
                : date('Y-m-d H:i:s');

            $pdo->prepare(
                'UPDATE claim_attempts
                 SET failures = ?, window_started_at = ?, blocked_until = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute([$failures, $windowStartedAt, $blockedUntil, (int)$row['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function coveted_claim_clear_attempts(int $userId, int $issuanceId): void
{
    $keys = array_column(coveted_claim_attempt_entries($userId, $issuanceId), 'key');
    $placeholders = implode(',', array_fill(0, count($keys), '?'));

    coveted_db()->prepare("DELETE FROM claim_attempts WHERE attempt_key IN ({$placeholders})")
        ->execute($keys);
}

function coveted_claim_code_verify_for_location(PDO $pdo, array $location, string $claimCode): ?array
{
    $claimCode = coveted_claim_code_validate($claimCode);
    $lookup = coveted_claim_code_lookup($claimCode);

    $stmt = $pdo->prepare(
        "SELECT cc.*
         FROM business_claim_codes cc
         JOIN businesses b ON b.id = cc.business_id
         WHERE cc.business_id = ?
           AND cc.code_lookup = ?
           AND cc.status = 'active'
           AND b.status = 'active'
           AND (cc.location_id = ? OR (cc.code_type = 'employee' AND cc.location_id IS NULL))
         LIMIT 1
         FOR UPDATE"
    );
    $stmt->execute([(int)$location['business_id'], $lookup, (int)$location['id']]);
    $code = $stmt->fetch();

    if (!$code || !password_verify($claimCode, (string)$code['code_hash'])) {
        return null;
    }

    if (password_needs_rehash((string)$code['code_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE business_claim_codes SET code_hash = ?, updated_at = NOW() WHERE id = ?')
            ->execute([password_hash($claimCode, PASSWORD_DEFAULT), (int)$code['id']]);
    }

    return $code;
}
