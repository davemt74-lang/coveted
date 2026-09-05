<?php
declare(strict_types=1);

require_once __DIR__ . '/invite_crm.php';

/** @return array<string,string> */
function coveted_invite_goal_options(): array
{
    return [
        'expand_circle' => 'Meet new people / expand my social circle',
        'local_discovery' => 'Discover new places',
        'dinners_nightlife' => 'Dinners & nightlife',
        'music_culture' => 'Live music & culture',
        'unique_experiences' => 'Unique or mystery experiences',
        'community_networking' => 'Community / professional connections',
        'host_contribute' => 'Host, partner or contribute',
    ];
}

/** @return array<string,string> */
function coveted_invite_source_options(): array
{
    return [
        'friend_member' => 'Friend or Coveted member',
        'event_venue' => 'Event or venue',
        'local_business' => 'Local business',
        'artist' => 'Artist or performer',
        'instagram' => 'Instagram',
        'other_social' => 'Other social media',
        'search_web' => 'Search / web',
        'other' => 'Other',
    ];
}

/** @return array<string,string> */
function coveted_invite_gender_options(): array
{
    return [
        'woman' => 'Woman',
        'man' => 'Man',
        'nonbinary' => 'Non-binary',
        'self_describe' => 'Prefer to self-describe',
        'prefer_not' => 'Prefer not to say',
    ];
}

/** @param array<int,mixed> $values @param array<string,string> $options @return array<int,string> */
function coveted_invite_profile_normalize_keys(array $values, array $options, int $limit = 20): array
{
    $clean = [];
    foreach ($values as $value) {
        $key = trim((string)$value);
        if (isset($options[$key])) {
            $clean[] = $key;
        }
        if (count($clean) >= $limit) {
            break;
        }
    }
    return array_values(array_unique($clean));
}

function coveted_invite_profile_require_https_url(string $value, string $label): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (strlen($value) > 700 || filter_var($value, FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException('Enter a valid ' . $label . ' link.');
    }
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        throw new InvalidArgumentException('Use a full https:// link for ' . $label . '.');
    }
    return $value;
}

function coveted_invite_profile_ensure_schema(?PDO $pdo = null): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo ??= coveted_db();
    coveted_invite_crm_ensure_schema($pdo);

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS invite_request_profiles (
            invite_request_id BIGINT UNSIGNED PRIMARY KEY,
            goals_json JSON NOT NULL,
            source_keys_json JSON NOT NULL,
            gender_key VARCHAR(40) NULL,
            gender_self_description VARCHAR(120) NULL,
            social_links_json JSON NULL,
            additional_note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_invite_request_profiles_request FOREIGN KEY (invite_request_id) REFERENCES invite_requests(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_profile_intake (
            user_id BIGINT UNSIGNED PRIMARY KEY,
            invite_request_id BIGINT UNSIGNED NULL,
            goals_json JSON NOT NULL,
            source_keys_json JSON NOT NULL,
            gender_key VARCHAR(40) NULL,
            gender_self_description VARCHAR(120) NULL,
            social_links_json JSON NULL,
            additional_note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user_profile_intake_request (invite_request_id),
            CONSTRAINT fk_user_profile_intake_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_profile_intake_request FOREIGN KEY (invite_request_id) REFERENCES invite_requests(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

/** @return array{goals:array<int,string>,sources:array<int,string>,gender:string,gender_self:string,links:array<string,string>,note:string} */
function coveted_invite_profile_validate_input(array $input): array
{
    $goals = coveted_invite_profile_normalize_keys((array)($input['goals'] ?? []), coveted_invite_goal_options(), 12);
    $sources = coveted_invite_profile_normalize_keys((array)($input['sources'] ?? []), coveted_invite_source_options(), 12);

    $gender = trim((string)($input['gender'] ?? ''));
    if ($gender !== '' && !isset(coveted_invite_gender_options()[$gender])) {
        throw new InvalidArgumentException('Choose a valid gender option.');
    }

    $genderSelf = trim((string)($input['gender_self'] ?? ''));
    if ($gender === 'self_describe') {
        if ($genderSelf === '' || mb_strlen($genderSelf) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $genderSelf) === 1) {
            throw new InvalidArgumentException('Enter how you would like to describe your gender.');
        }
    } else {
        $genderSelf = '';
    }

    $links = [];
    foreach ([
        'personal_website' => 'personal website',
        'business_website' => 'business website',
        'instagram' => 'Instagram',
        'linkedin' => 'LinkedIn',
        'tiktok' => 'TikTok',
        'x_profile' => 'X / Twitter',
    ] as $key => $label) {
        $url = coveted_invite_profile_require_https_url((string)($input[$key] ?? ''), $label);
        if ($url !== null) {
            $links[$key] = $url;
        }
    }

    $note = trim((string)($input['additional_note'] ?? ''));
    if (mb_strlen($note) > 1500) {
        throw new InvalidArgumentException('Keep the additional note under 1,500 characters.');
    }

    return [
        'goals' => $goals,
        'sources' => $sources,
        'gender' => $gender,
        'gender_self' => $genderSelf,
        'links' => $links,
        'note' => $note,
    ];
}

/** @param array{goals:array<int,string>,sources:array<int,string>,gender:string,gender_self:string,links:array<string,string>,note:string} $data */
function coveted_invite_profile_save(string $requestPublicId, array $data, ?PDO $pdo = null): void
{
    if ($requestPublicId === 'accepted') {
        return;
    }

    $pdo ??= coveted_db();
    coveted_invite_profile_ensure_schema($pdo);

    $stmt = $pdo->prepare('SELECT id FROM invite_requests WHERE public_id = ? LIMIT 1');
    $stmt->execute([$requestPublicId]);
    $requestId = (int)($stmt->fetchColumn() ?: 0);
    if ($requestId < 1) {
        throw new RuntimeException('Invite request profile could not be linked.');
    }

    $pdo->prepare(
        "INSERT INTO invite_request_profiles
            (invite_request_id,goals_json,source_keys_json,gender_key,gender_self_description,social_links_json,additional_note)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            goals_json = VALUES(goals_json),
            source_keys_json = VALUES(source_keys_json),
            gender_key = VALUES(gender_key),
            gender_self_description = VALUES(gender_self_description),
            social_links_json = VALUES(social_links_json),
            additional_note = VALUES(additional_note),
            updated_at = UTC_TIMESTAMP()"
    )->execute([
        $requestId,
        coveted_json($data['goals']),
        coveted_json($data['sources']),
        $data['gender'] !== '' ? $data['gender'] : null,
        $data['gender_self'] !== '' ? $data['gender_self'] : null,
        $data['links'] ? coveted_json($data['links']) : null,
        $data['note'] !== '' ? $data['note'] : null,
    ]);
}

/** @param array<int,int> $requestIds @return array<int,array<string,mixed>> */
function coveted_invite_profile_details_map(array $requestIds, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    coveted_invite_profile_ensure_schema($pdo);
    $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds), static fn(int $id): bool => $id > 0)));
    if (!$requestIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $stmt = $pdo->prepare('SELECT * FROM invite_request_profiles WHERE invite_request_id IN (' . $placeholders . ')');
    $stmt->execute($requestIds);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int)$row['invite_request_id']] = $row;
    }
    return $map;
}

/** @return array<int,string> */
function coveted_invite_profile_decode_list(mixed $json): array
{
    try {
        $decoded = json_decode((string)$json, true, 32, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    } catch (Throwable) {
        return [];
    }
}

/** @return array<string,string> */
function coveted_invite_profile_decode_links(mixed $json): array
{
    try {
        $decoded = json_decode((string)$json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }
        $links = [];
        foreach ($decoded as $key => $url) {
            if (is_string($key) && is_string($url) && coveted_invite_profile_require_https_url($url, 'profile') !== null) {
                $links[$key] = $url;
            }
        }
        return $links;
    } catch (Throwable) {
        return [];
    }
}

function coveted_invite_profile_apply_to_user(int $requestId, int $userId, ?PDO $pdo = null): void
{
    if ($requestId < 1 || $userId < 1) {
        return;
    }

    $pdo ??= coveted_db();
    coveted_invite_profile_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM invite_request_profiles WHERE invite_request_id = ? LIMIT 1');
    $stmt->execute([$requestId]);
    $profile = $stmt->fetch();
    if (!$profile) {
        return;
    }

    $pdo->prepare(
        "INSERT INTO user_profile_intake
            (user_id,invite_request_id,goals_json,source_keys_json,gender_key,gender_self_description,social_links_json,additional_note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            invite_request_id = VALUES(invite_request_id),
            goals_json = VALUES(goals_json),
            source_keys_json = VALUES(source_keys_json),
            gender_key = VALUES(gender_key),
            gender_self_description = VALUES(gender_self_description),
            social_links_json = VALUES(social_links_json),
            additional_note = VALUES(additional_note),
            updated_at = UTC_TIMESTAMP()"
    )->execute([
        $userId,
        $requestId,
        (string)$profile['goals_json'],
        (string)$profile['source_keys_json'],
        $profile['gender_key'],
        $profile['gender_self_description'],
        $profile['social_links_json'],
        $profile['additional_note'],
    ]);
}
