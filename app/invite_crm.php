<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** @return array<string,string> */
function coveted_invite_event_interest_options(): array
{
    return [
        'private_dinners' => 'Private dinners',
        'social_gatherings' => 'Social gatherings',
        'artist_sessions' => 'Live music & artist sessions',
        'mystery_events' => 'Mystery events',
        'local_experiences' => 'Local experiences',
        'community_events' => 'Community & networking',
    ];
}

function coveted_invite_crm_ensure_schema(?PDO $pdo = null): void
{
    $pdo ??= coveted_db();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS cities (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(64) NOT NULL UNIQUE,
            name VARCHAR(160) NOT NULL,
            region VARCHAR(160) NULL,
            country CHAR(2) NOT NULL DEFAULT 'US',
            timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
            status ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 100,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_city_identity (name,region,country),
            KEY idx_cities_status_sort (status,sort_order,name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS invite_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(64) NOT NULL UNIQUE,
            full_name VARCHAR(180) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(80) NULL,
            city_id BIGINT UNSIGNED NULL,
            city_other VARCHAR(180) NULL,
            event_interests_json JSON NOT NULL,
            how_heard VARCHAR(180) NULL,
            message TEXT NULL,
            admin_note TEXT NULL,
            status ENUM('new','contacted','qualified','converted','declined') NOT NULL DEFAULT 'new',
            converted_user_id BIGINT UNSIGNED NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            source_ip_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_invite_requests_status_created (status,created_at),
            KEY idx_invite_requests_email_created (email,created_at),
            KEY idx_invite_requests_city_status (city_id,status),
            KEY idx_invite_requests_converted_user (converted_user_id),
            CONSTRAINT fk_invite_requests_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL,
            CONSTRAINT fk_invite_requests_converted_user FOREIGN KEY (converted_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_invite_requests_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_activation_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_user_activation_user (user_id,used_at,expires_at),
            CONSTRAINT fk_user_activation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_activation_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $seed = $pdo->prepare(
        "INSERT IGNORE INTO cities (public_id,name,region,country,timezone,status,sort_order)
         VALUES (?, ?, ?, 'US', 'America/Phoenix', 'active', ?)"
    );
    foreach ([
        ['Phoenix', 'Arizona', 10],
        ['Scottsdale', 'Arizona', 20],
        ['Tempe', 'Arizona', 30],
        ['Mesa', 'Arizona', 40],
        ['Chandler', 'Arizona', 50],
        ['Gilbert', 'Arizona', 60],
    ] as [$name, $region, $sort]) {
        $seed->execute(['city_' . strtolower(str_replace(' ', '_', $name)) . '_az', $name, $region, $sort]);
    }
}

/** @return array<int,array<string,mixed>> */
function coveted_cities_list(string $status = 'active', ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    coveted_invite_crm_ensure_schema($pdo);

    $allowed = ['active', 'paused', 'archived', 'all'];
    if (!in_array($status, $allowed, true)) {
        $status = 'active';
    }

    $sql = "SELECT c.*,
            (SELECT COUNT(*) FROM invite_requests ir WHERE ir.city_id = c.id) AS lead_count,
            (SELECT COUNT(*) FROM profiles p WHERE LOWER(TRIM(p.city)) IN (
                LOWER(TRIM(CONCAT(c.name, ', ', COALESCE(c.region,'')))), LOWER(TRIM(c.name))
            )) AS member_count,
            (SELECT COUNT(*) FROM social_groups g WHERE LOWER(TRIM(g.city)) IN (
                LOWER(TRIM(CONCAT(c.name, ', ', COALESCE(c.region,'')))), LOWER(TRIM(c.name))
            )) AS group_count,
            (SELECT COUNT(*) FROM locations l WHERE LOWER(TRIM(l.city)) = LOWER(TRIM(c.name)) AND l.status <> 'archived') AS location_count
        FROM cities c";
    $params = [];
    if ($status !== 'all') {
        $sql .= ' WHERE c.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY c.sort_order ASC, c.name ASC, c.region ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function coveted_city_label(array $city): string
{
    $name = trim((string)($city['name'] ?? ''));
    $region = trim((string)($city['region'] ?? ''));
    return $region !== '' ? $name . ', ' . $region : $name;
}

function coveted_city_create(array $admin, array $input, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $pdo ??= coveted_db();
    coveted_invite_crm_ensure_schema($pdo);

    $name = trim((string)($input['name'] ?? ''));
    $region = trim((string)($input['region'] ?? ''));
    $country = strtoupper(trim((string)($input['country'] ?? 'US')));
    $timezone = trim((string)($input['timezone'] ?? ''));
    $sortOrder = max(0, min(10000, (int)($input['sort_order'] ?? 100)));

    if ($name === '' || mb_strlen($name) > 160) {
        throw new InvalidArgumentException('Enter a city name.');
    }
    if (mb_strlen($region) > 160) {
        throw new InvalidArgumentException('Region/state is too long.');
    }
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        throw new InvalidArgumentException('Use a two-letter country code.');
    }
    coveted_require_timezone($timezone);

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO cities (public_id,name,region,country,timezone,status,sort_order)
             VALUES (?, ?, ?, ?, ?, 'active', ?)"
        );
        $publicId = coveted_uuid('city');
        $stmt->execute([$publicId, $name, $region !== '' ? $region : null, $country, $timezone, $sortOrder]);
        coveted_audit('admin.city_created', 'city', $publicId, ['name' => $name, 'region' => $region], (int)$admin['id']);
        return ['id' => (int)$pdo->lastInsertId(), 'public_id' => $publicId, 'name' => $name];
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            throw new InvalidArgumentException('That city is already in the database.');
        }
        throw $e;
    }
}

function coveted_city_set_status(array $admin, int $cityId, string $status, ?PDO $pdo = null): void
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    if (!in_array($status, ['active', 'paused', 'archived'], true)) {
        throw new InvalidArgumentException('Invalid city status.');
    }

    $pdo ??= coveted_db();
    coveted_invite_crm_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT public_id FROM cities WHERE id = ? LIMIT 1');
    $stmt->execute([$cityId]);
    $publicId = (string)($stmt->fetchColumn() ?: '');
    if ($publicId === '') {
        throw new InvalidArgumentException('City not found.');
    }

    $pdo->prepare('UPDATE cities SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $cityId]);
    coveted_audit('admin.city_status', 'city', $publicId, ['status' => $status], (int)$admin['id']);
}

/** @return array<int,string> */
function coveted_invite_normalize_interests(array $values): array
{
    $options = coveted_invite_event_interest_options();
    $clean = [];
    foreach ($values as $value) {
        $key = trim((string)$value);
        if (isset($options[$key])) {
            $clean[] = $key;
        }
    }
    return array_values(array_unique($clean));
}

function coveted_invite_request_submit(array $input, ?PDO $pdo = null): string
{
    $pdo ??= coveted_db();
    coveted_invite_crm_ensure_schema($pdo);

    if (trim((string)($input['company'] ?? '')) !== '') {
        return 'accepted';
    }

    $name = trim((string)($input['name'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $phone = trim((string)($input['phone'] ?? ''));
    $cityId = (int)($input['city_id'] ?? 0);
    $cityOther = trim((string)($input['city_other'] ?? ''));
    $interests = coveted_invite_normalize_interests((array)($input['event_interests'] ?? []));
    $howHeard = trim((string)($input['how_heard'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));

    if ($name === '' || mb_strlen($name) > 180 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
        throw new InvalidArgumentException('Enter your name.');
    }
    if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if (mb_strlen($phone) > 80) {
        throw new InvalidArgumentException('Phone number is too long.');
    }
    if (count($interests) < 1) {
        throw new InvalidArgumentException('Choose at least one type of event you are interested in.');
    }
    if (mb_strlen($howHeard) > 180 || mb_strlen($message) > 2000 || mb_strlen($cityOther) > 180) {
        throw new InvalidArgumentException('One of the fields is too long.');
    }

    $selectedCityId = null;
    if ($cityId > 0) {
        $cityStmt = $pdo->prepare("SELECT id FROM cities WHERE id = ? AND status = 'active' LIMIT 1");
        $cityStmt->execute([$cityId]);
        $selectedCityId = $cityStmt->fetchColumn() ? $cityId : null;
        if ($selectedCityId === null) {
            throw new InvalidArgumentException('Choose an available city.');
        }
    } elseif ($cityOther === '') {
        throw new InvalidArgumentException('Choose your city or enter another city.');
    }

    $recent = $pdo->prepare(
        "SELECT id FROM invite_requests
         WHERE email = ? AND status IN ('new','contacted','qualified')
           AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
         LIMIT 1"
    );
    $recent->execute([$email]);
    if ($recent->fetchColumn()) {
        throw new InvalidArgumentException('We already have a recent invite request for that email address.');
    }

    $publicId = coveted_uuid('lead');
    $ip = coveted_client_ip();
    $ipHash = $ip !== 'unknown' ? hash('sha256', 'invite-request|' . $ip) : null;

    $stmt = $pdo->prepare(
        "INSERT INTO invite_requests
            (public_id,full_name,email,phone,city_id,city_other,event_interests_json,how_heard,message,status,source_ip_hash)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?)"
    );
    $stmt->execute([
        $publicId,
        $name,
        $email,
        $phone !== '' ? $phone : null,
        $selectedCityId,
        $selectedCityId === null && $cityOther !== '' ? $cityOther : null,
        coveted_json($interests),
        $howHeard !== '' ? $howHeard : null,
        $message !== '' ? $message : null,
        $ipHash,
    ]);

    try {
        coveted_audit('invite_request.created', 'invite_request', $publicId, ['city_id' => $selectedCityId], null);
    } catch (Throwable $e) {
        error_log('Invite request audit failed: ' . $e->getMessage());
    }

    return $publicId;
}

/** @return array<int,array<string,mixed>> */
function coveted_invite_requests_list(string $status = 'new', string $search = '', ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    coveted_invite_crm_ensure_schema($pdo);

    $allowed = ['new', 'contacted', 'qualified', 'converted', 'declined', 'all'];
    if (!in_array($status, $allowed, true)) {
        $status = 'new';
    }
    $search = trim($search);

    $where = [];
    $params = [];
    if ($status !== 'all') {
        $where[] = 'ir.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $where[] = '(ir.full_name LIKE ? OR ir.email LIKE ? OR ir.phone LIKE ? OR c.name LIKE ? OR ir.city_other LIKE ?)';
        $needle = '%' . $search . '%';
        array_push($params, $needle, $needle, $needle, $needle, $needle);
    }

    $sql = "SELECT ir.*, c.name AS city_name, c.region AS city_region, c.country AS city_country,
                   u.display_name AS converted_user_name, u.public_id AS converted_user_public_id
            FROM invite_requests ir
            LEFT JOIN cities c ON c.id = ir.city_id
            LEFT JOIN users u ON u.id = ir.converted_user_id";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY FIELD(ir.status,'new','qualified','contacted','converted','declined'), ir.created_at DESC LIMIT 250";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** @return array<string,int> */
function coveted_invite_request_counts(?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    coveted_invite_crm_ensure_schema($pdo);
    $result = ['new' => 0, 'contacted' => 0, 'qualified' => 0, 'converted' => 0, 'declined' => 0];
    foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM invite_requests GROUP BY status')->fetchAll() as $row) {
        if (array_key_exists((string)$row['status'], $result)) {
            $result[(string)$row['status']] = (int)$row['total'];
        }
    }
    return $result;
}

function coveted_invite_request_update(array $admin, int $requestId, string $status, string $adminNote = '', ?PDO $pdo = null): void
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }
    if (!in_array($status, ['new', 'contacted', 'qualified', 'declined'], true)) {
        throw new InvalidArgumentException('Invalid CRM status.');
    }
    if (mb_strlen($adminNote) > 3000) {
        throw new InvalidArgumentException('Keep the CRM note under 3,000 characters.');
    }

    $pdo ??= coveted_db();
    coveted_invite_crm_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT public_id,status FROM invite_requests WHERE id = ? LIMIT 1");
    $stmt->execute([$requestId]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] === 'converted') {
        throw new InvalidArgumentException('That CRM record cannot be changed.');
    }

    $pdo->prepare(
        'UPDATE invite_requests SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?'
    )->execute([$status, trim($adminNote) !== '' ? trim($adminNote) : null, (int)$admin['id'], $requestId]);
    coveted_audit('admin.invite_request_updated', 'invite_request', (string)$row['public_id'], ['status' => $status], (int)$admin['id']);
}

/** @return array{user_id:int,email:string,existing_user:bool,activation_url:?string} */
function coveted_invite_request_convert(array $admin, int $requestId, ?PDO $pdo = null): array
{
    if (!coveted_is_system_admin($admin)) {
        throw new InvalidArgumentException('System Admin access is required.');
    }

    $pdo ??= coveted_db();
    coveted_invite_crm_ensure_schema($pdo);
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            "SELECT ir.*, c.name AS city_name, c.region AS city_region
             FROM invite_requests ir
             LEFT JOIN cities c ON c.id = ir.city_id
             WHERE ir.id = ? LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$requestId]);
        $lead = $stmt->fetch();
        if (!$lead) {
            throw new InvalidArgumentException('CRM record not found.');
        }
        if ($lead['status'] === 'converted' && (int)$lead['converted_user_id'] > 0) {
            throw new InvalidArgumentException('That request has already been converted.');
        }

        $email = strtolower(trim((string)$lead['email']));
        $existing = $pdo->prepare('SELECT id, public_id, status FROM users WHERE email = ? LIMIT 1 FOR UPDATE');
        $existing->execute([$email]);
        $user = $existing->fetch();
        $existingUser = (bool)$user;
        $activationUrl = null;

        $cityLabel = trim((string)$lead['city_other']);
        if (!empty($lead['city_name'])) {
            $cityLabel = trim((string)$lead['city_name']);
            if (trim((string)$lead['city_region']) !== '') {
                $cityLabel .= ', ' . trim((string)$lead['city_region']);
            }
        }

        $interestKeys = [];
        try {
            $decoded = json_decode((string)$lead['event_interests_json'], true, 32, JSON_THROW_ON_ERROR);
            $interestKeys = is_array($decoded) ? coveted_invite_normalize_interests($decoded) : [];
        } catch (Throwable) {
            $interestKeys = [];
        }
        $options = coveted_invite_event_interest_options();
        $interestLabels = array_values(array_filter(array_map(
            static fn(string $key): ?string => $options[$key] ?? null,
            $interestKeys
        )));
        $profileJson = coveted_json(['interests' => $interestLabels, 'gathering_styles' => []]);

        if (!$user) {
            $publicId = coveted_uuid('usr');
            $placeholder = bin2hex(random_bytes(32));
            $pdo->prepare(
                "INSERT INTO users (public_id,email,password_hash,display_name,status)
                 VALUES (?, ?, ?, ?, 'invited')"
            )->execute([
                $publicId,
                $email,
                password_hash($placeholder, PASSWORD_DEFAULT),
                (string)$lead['full_name'],
            ]);
            $userId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO user_roles (user_id,role_key,granted_by) VALUES (?, 'attendee', ?)")
                ->execute([$userId, (int)$admin['id']]);
            $pdo->prepare('INSERT INTO profiles (user_id,city,interests_json) VALUES (?, ?, ?)')
                ->execute([$userId, $cityLabel !== '' ? $cityLabel : null, $profileJson]);

            $rawToken = bin2hex(random_bytes(32));
            $pdo->prepare(
                "INSERT INTO user_activation_tokens (user_id,token_hash,expires_at,created_by)
                 VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY), ?)"
            )->execute([$userId, hash('sha256', $rawToken), (int)$admin['id']]);
            $activationUrl = coveted_url('/activate.php?token=' . rawurlencode($rawToken));
            $user = ['id' => $userId, 'public_id' => $publicId, 'status' => 'invited'];
        } else {
            $userId = (int)$user['id'];
            $pdo->prepare(
                "INSERT INTO profiles (user_id,city,interests_json)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    city = CASE WHEN city IS NULL OR city = '' THEN VALUES(city) ELSE city END,
                    interests_json = CASE WHEN interests_json IS NULL THEN VALUES(interests_json) ELSE interests_json END"
            )->execute([$userId, $cityLabel !== '' ? $cityLabel : null, $profileJson]);
        }

        $pdo->prepare(
            "UPDATE invite_requests
             SET status = 'converted', converted_user_id = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = ?"
        )->execute([(int)$user['id'], (int)$admin['id'], $requestId]);

        coveted_audit(
            'admin.invite_request_converted',
            'invite_request',
            (string)$lead['public_id'],
            ['user_public_id' => (string)$user['public_id'], 'existing_user' => $existingUser],
            (int)$admin['id']
        );

        $pdo->commit();
        return [
            'user_id' => (int)$user['id'],
            'email' => $email,
            'existing_user' => $existingUser,
            'activation_url' => $activationUrl,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            throw new InvalidArgumentException('A user or CRM record already exists for that email address.');
        }
        throw $e;
    }
}

/** @return array<string,mixed>|null */
function coveted_activation_lookup(string $rawToken, ?PDO $pdo = null): ?array
{
    $rawToken = trim($rawToken);
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        return null;
    }
    $pdo ??= coveted_db();
    coveted_invite_crm_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT uat.id AS token_id, uat.user_id, uat.expires_at, u.display_name, u.email, u.status
         FROM user_activation_tokens uat
         JOIN users u ON u.id = uat.user_id
         WHERE uat.token_hash = ? AND uat.used_at IS NULL AND uat.expires_at > UTC_TIMESTAMP()
         LIMIT 1"
    );
    $stmt->execute([hash('sha256', $rawToken)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function coveted_activation_complete(string $rawToken, string $password, string $passwordConfirm, ?PDO $pdo = null): array
{
    if (strlen($password) < 10 || strlen($password) > 4096) {
        throw new InvalidArgumentException('Use at least 10 characters for your password.');
    }
    if (!hash_equals($password, $passwordConfirm)) {
        throw new InvalidArgumentException('Passwords do not match.');
    }

    $pdo ??= coveted_db();
    coveted_invite_crm_ensure_schema($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT uat.id AS token_id, uat.user_id, u.public_id, u.status
             FROM user_activation_tokens uat
             JOIN users u ON u.id = uat.user_id
             WHERE uat.token_hash = ? AND uat.used_at IS NULL AND uat.expires_at > UTC_TIMESTAMP()
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([hash('sha256', trim($rawToken))]);
        $row = $stmt->fetch();
        if (!$row || !in_array((string)$row['status'], ['invited', 'active'], true)) {
            throw new InvalidArgumentException('That activation link is invalid or has expired.');
        }

        $pdo->prepare("UPDATE users SET password_hash = ?, status = 'active', updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$row['user_id']]);
        $pdo->prepare('UPDATE user_activation_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([(int)$row['token_id']]);
        coveted_audit('user.activated', 'user', (string)$row['public_id'], [], (int)$row['user_id']);
        $pdo->commit();
        return ['user_id' => (int)$row['user_id']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
