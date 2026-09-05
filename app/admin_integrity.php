<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function coveted_admin_integrity_normalize(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_strtolower($value);
}

function coveted_admin_integrity_same(string $left, string $right): bool
{
    return hash_equals(
        coveted_admin_integrity_normalize($left),
        coveted_admin_integrity_normalize($right)
    );
}

function coveted_admin_integrity_return_path(): string
{
    $uri = coveted_safe_internal_path((string)($_SERVER['REQUEST_URI'] ?? '/admin/'), '/admin/');
    $hashPos = strpos($uri, '#');
    return $hashPos === false ? $uri : substr($uri, 0, $hashPos);
}

function coveted_admin_integrity_flash_and_redirect(string $message): never
{
    $_SESSION['admin_integrity_notice'] = $message;
    coveted_redirect(coveted_admin_integrity_return_path());
}

/**
 * Stop browser resubmits/double-clicks before they can execute the same Admin
 * create mutation twice. PHP session locking serializes same-session requests,
 * so marking the payload before the handler executes also protects concurrent
 * double-clicks from the same Admin session.
 */
function coveted_admin_integrity_guard_replay(string $action): void
{
    $payload = $_POST;
    unset($payload['csrf_token']);
    ksort($payload);

    $fingerprint = hash('sha256', $action . '|' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $now = time();
    $recent = (array)($_SESSION['admin_recent_mutations'] ?? []);

    foreach ($recent as $key => $timestamp) {
        if (!is_int($timestamp) || $timestamp < $now - 300) {
            unset($recent[$key]);
        }
    }

    if (isset($recent[$fingerprint])) {
        $_SESSION['admin_recent_mutations'] = $recent;
        coveted_admin_integrity_flash_and_redirect('Duplicate submission blocked. The original Admin create action was already received.');
    }

    $recent[$fingerprint] = $now;
    $_SESSION['admin_recent_mutations'] = $recent;
}

function coveted_admin_integrity_assert_unique_user(PDO $pdo): void
{
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if ($email === '') {
        return;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        coveted_admin_integrity_flash_and_redirect('A user already exists for that email address. Open the existing account instead of creating a duplicate.');
    }
}

function coveted_admin_integrity_assert_unique_business(PDO $pdo): void
{
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        return;
    }

    $rows = $pdo->query("SELECT name FROM businesses WHERE status <> 'archived'")->fetchAll();
    foreach ($rows as $row) {
        if (coveted_admin_integrity_same((string)$row['name'], $name)) {
            coveted_admin_integrity_flash_and_redirect('That business already exists. Open the existing business instead of creating a duplicate.');
        }
    }
}

function coveted_admin_integrity_assert_unique_group(PDO $pdo): void
{
    $name = trim((string)($_POST['name'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    if ($name === '') {
        return;
    }

    $rows = $pdo->query("SELECT name, city FROM social_groups WHERE status <> 'archived'")->fetchAll();
    foreach ($rows as $row) {
        if (
            coveted_admin_integrity_same((string)$row['name'], $name)
            && coveted_admin_integrity_same((string)($row['city'] ?? ''), $city)
        ) {
            coveted_admin_integrity_flash_and_redirect('A group with that name already exists in that city. Open the existing group instead of creating a duplicate.');
        }
    }
}

function coveted_admin_integrity_assert_unique_event(PDO $pdo): void
{
    $groupId = (int)($_POST['group_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $startsRaw = trim((string)($_POST['starts_at'] ?? ''));
    $timezone = trim((string)($_POST['timezone'] ?? ''));
    if ($groupId < 1 || $title === '' || $startsRaw === '' || $timezone === '') {
        return;
    }

    try {
        $zone = coveted_require_timezone($timezone);
        $local = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $startsRaw, $zone);
        if (!$local || $local->format('Y-m-d\TH:i') !== $startsRaw) {
            return;
        }
        $startsAt = $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return;
    }

    $stmt = $pdo->prepare(
        "SELECT title
         FROM events
         WHERE group_id = ? AND starts_at = ? AND status <> 'cancelled'"
    );
    $stmt->execute([$groupId, $startsAt]);
    foreach ($stmt->fetchAll() as $row) {
        if (coveted_admin_integrity_same((string)$row['title'], $title)) {
            coveted_admin_integrity_flash_and_redirect('That event already exists for this group at the same start time. Open the existing event instead of creating a duplicate.');
        }
    }
}

function coveted_admin_integrity_assert_unique_artist(PDO $pdo, int $adminUserId): void
{
    $name = trim((string)($_POST['artist_name'] ?? ''));
    if ($name === '') {
        return;
    }

    $stmt = $pdo->prepare("SELECT artist_name FROM artist_profiles WHERE owner_user_id = ? AND status <> 'archived'");
    $stmt->execute([$adminUserId]);
    foreach ($stmt->fetchAll() as $row) {
        if (coveted_admin_integrity_same((string)$row['artist_name'], $name)) {
            coveted_admin_integrity_flash_and_redirect('That artist profile already exists for this owner. Open the existing artist instead of creating a duplicate.');
        }
    }
}

/**
 * Central Admin-only integrity gate. It intentionally runs before the page's
 * mutation switch so every canonical create action receives the same replay
 * and semantic duplicate protection.
 */
function coveted_admin_integrity_guard_request(): void
{
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $user = coveted_current_user();
    if ($user === null || !coveted_is_system_admin($user)) {
        return;
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $protectedActions = ['create_user', 'create_business', 'create_group', 'create_event', 'create_artist'];
    if (!in_array($action, $protectedActions, true)) {
        return;
    }

    coveted_admin_integrity_guard_replay($action);

    $pdo = coveted_db();
    switch ($action) {
        case 'create_user':
            coveted_admin_integrity_assert_unique_user($pdo);
            break;
        case 'create_business':
            coveted_admin_integrity_assert_unique_business($pdo);
            break;
        case 'create_group':
            coveted_admin_integrity_assert_unique_group($pdo);
            break;
        case 'create_event':
            coveted_admin_integrity_assert_unique_event($pdo);
            break;
        case 'create_artist':
            coveted_admin_integrity_assert_unique_artist($pdo, (int)$user['id']);
            break;
    }
}
