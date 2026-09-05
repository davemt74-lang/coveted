<?php
declare(strict_types=1);

require_once __DIR__ . '/reconnect.php';
require_once __DIR__ . '/member_sample_data.php';
require_once __DIR__ . '/rewards.php';

/** @return array{interests:array<int,string>,gathering_styles:array<int,string>} */
function coveted_member_v2_profile_preferences(?string $json): array
{
    $json = trim((string)$json);
    if ($json === '') {
        return ['interests' => [], 'gathering_styles' => []];
    }

    try {
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return ['interests' => [], 'gathering_styles' => []];
    }

    if (!is_array($decoded)) {
        return ['interests' => [], 'gathering_styles' => []];
    }

    if (array_is_list($decoded)) {
        $decoded = ['interests' => $decoded, 'gathering_styles' => []];
    }

    $normalize = static function (mixed $values): array {
        if (!is_array($values)) {
            return [];
        }

        $clean = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '' || mb_strlen($value) > 60) {
                continue;
            }
            $clean[] = $value;
            if (count($clean) >= 12) {
                break;
            }
        }
        return array_values(array_unique($clean));
    };

    return [
        'interests' => $normalize($decoded['interests'] ?? []),
        'gathering_styles' => $normalize($decoded['gathering_styles'] ?? []),
    ];
}

/** @return array<string,mixed> */
function coveted_member_v2_profile_data(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();

    if (coveted_member_sample_mode($user, $pdo)) {
        $sample = coveted_member_sample_data();
        $profile = (array)($sample['profile'] ?? []);
        return [
            'display_name' => (string)($profile['display_name'] ?? 'Taylor Kim'),
            'email' => 'preview@coveted.local',
            'bio' => (string)($profile['bio'] ?? ''),
            'city' => (string)($profile['city'] ?? 'Phoenix, Arizona'),
            'avatar_url' => (string)($profile['avatar_url'] ?? '/assets/images/sample/people/taylor-kim.webp'),
            'cover_url' => (string)($profile['cover_url'] ?? '/assets/images/sample/events/saturday-night-supper-club-hero.webp'),
            'interests' => array_values((array)($profile['interests'] ?? [])),
            'gathering_styles' => array_values((array)($profile['gathering_styles'] ?? [])),
            'member_since' => 'September 2026',
            'group_count' => count((array)($sample['groups'] ?? [])),
            'event_count' => count((array)($sample['reconnect_events'] ?? [])),
            'benefit_count' => count(array_filter((array)($sample['benefits'] ?? []), static fn(array $benefit): bool => ($benefit['state'] ?? 'inbox') === 'inbox')),
            'reconnect_count' => count(array_filter((array)($sample['reconnects'] ?? []), static fn(array $person): bool => ($person['status'] ?? '') === 'mutual')),
            'is_sample' => true,
        ];
    }

    $stmt = $pdo->prepare('SELECT bio, city, avatar_url, cover_url, interests_json FROM profiles WHERE user_id = ? LIMIT 1');
    $stmt->execute([(int)$user['id']]);
    $profile = $stmt->fetch() ?: [];
    $preferences = coveted_member_v2_profile_preferences((string)($profile['interests_json'] ?? ''));

    $groupStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM group_memberships gm
         JOIN social_groups g ON g.id = gm.group_id
         WHERE gm.user_id = ?
           AND gm.membership_status = 'active'
           AND g.status <> 'archived'"
    );
    $groupStmt->execute([(int)$user['id']]);

    $eventStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT ea.event_id)
         FROM event_attendance ea
         JOIN events e ON e.id = ea.event_id
         WHERE ea.user_id = ?
           AND ea.status IN ('checked_in','attended','left_early')
           AND e.status = 'completed'"
    );
    $eventStmt->execute([(int)$user['id']]);

    $benefitCount = 0;
    try {
        $benefitCount = count(coveted_reward_list_for_user((int)$user['id'], [], 'inbox'));
    } catch (Throwable $e) {
        error_log('Coveted profile benefit count error: ' . $e->getMessage());
    }

    $reconnectCount = 0;
    try {
        $reconnectCount = count(coveted_reconnect_matches_for_user($user, 100));
    } catch (Throwable $e) {
        error_log('Coveted profile reconnect count error: ' . $e->getMessage());
    }

    return [
        'display_name' => (string)$user['display_name'],
        'email' => (string)$user['email'],
        'bio' => trim((string)($profile['bio'] ?? '')),
        'city' => trim((string)($profile['city'] ?? '')),
        'avatar_url' => coveted_safe_url((string)($profile['avatar_url'] ?? ''), true),
        'cover_url' => coveted_safe_url((string)($profile['cover_url'] ?? ''), true),
        'interests' => $preferences['interests'],
        'gathering_styles' => $preferences['gathering_styles'],
        'member_since' => !empty($user['created_at'])
            ? coveted_utc_datetime((string)$user['created_at'])->setTimezone(coveted_timezone())->format('F Y')
            : '',
        'group_count' => (int)$groupStmt->fetchColumn(),
        'event_count' => (int)$eventStmt->fetchColumn(),
        'benefit_count' => $benefitCount,
        'reconnect_count' => $reconnectCount,
        'is_sample' => false,
    ];
}

/** @return array<int,array<string,mixed>> */
function coveted_member_v2_reconnect_events(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    if (!coveted_member_sample_mode($user, $pdo)) {
        return coveted_reconnect_events_for_user($user, 100);
    }

    $sample = coveted_member_sample_data();
    $rows = [];
    foreach ((array)($sample['reconnect_events'] ?? []) as $index => $event) {
        $rows[] = [
            'id' => 9000 + $index,
            'public_id' => (string)$event['public_id'],
            'title' => (string)$event['title'],
            'starts_at' => (string)$event['starts_at'],
            'ends_at' => null,
            'timezone' => 'America/Phoenix',
            'group_public_id' => 'sample-' . (string)($event['group_id'] ?? 'group'),
            'group_name' => (string)$event['group'],
            'attendance_status' => 'attended',
            'image' => (string)($event['image'] ?? ''),
            'location_name' => (string)($event['location'] ?? ''),
            'location_city' => 'Phoenix, Arizona',
            'is_sample' => true,
        ];
    }
    return $rows;
}

/** @return array<int,array<string,mixed>> */
function coveted_member_v2_reconnect_attendees(array $user, string $eventRef, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    if (!coveted_member_sample_mode($user, $pdo)) {
        return coveted_reconnect_attendees_for_event($user, $eventRef);
    }

    $sample = coveted_member_sample_data();
    $rows = [];
    foreach ((array)($sample['reconnects'] ?? []) as $index => $person) {
        if ((string)($person['event_public_id'] ?? '') !== $eventRef) {
            continue;
        }
        $rows[] = [
            'user_id' => 1000 + $index,
            'display_name' => (string)$person['name'],
            'avatar_url' => (string)$person['image'],
            'my_request_status' => (string)($person['status'] ?? ''),
            'matched_at' => ($person['status'] ?? '') === 'mutual' ? date('Y-m-d H:i:s') : null,
            'context' => (string)($person['context'] ?? ''),
            'is_sample' => true,
        ];
    }
    return $rows;
}

/** @return array<int,array<string,mixed>> */
function coveted_member_v2_reconnect_matches(array $user, ?PDO $pdo = null): array
{
    $pdo ??= coveted_db();
    if (!coveted_member_sample_mode($user, $pdo)) {
        return coveted_reconnect_matches_for_user($user, 100);
    }

    $sample = coveted_member_sample_data();
    $eventNames = [];
    foreach ((array)($sample['reconnect_events'] ?? []) as $event) {
        $eventNames[(string)$event['public_id']] = (string)$event['title'];
    }

    $rows = [];
    foreach ((array)($sample['reconnects'] ?? []) as $index => $person) {
        if (($person['status'] ?? '') !== 'mutual') {
            continue;
        }
        $eventRef = (string)($person['event_public_id'] ?? '');
        $rows[] = [
            'event_id' => 9000,
            'matched_at' => date('Y-m-d H:i:s'),
            'event_public_id' => $eventRef,
            'event_title' => $eventNames[$eventRef] ?? 'Recent gathering',
            'matched_user_id' => 1000 + $index,
            'matched_display_name' => (string)$person['name'],
            'matched_avatar_url' => (string)$person['image'],
            'is_sample' => true,
        ];
    }
    return $rows;
}
