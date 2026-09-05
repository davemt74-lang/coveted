<?php
declare(strict_types=1);

require_once __DIR__ . '/app/push.php';

$user = coveted_require_user();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['format'] ?? '') === 'bootstrap') {
    header('Content-Type: application/json; charset=utf-8');
    echo coveted_json([
        'unread_count' => coveted_notification_unread_count($userId),
        'csrf_token' => coveted_csrf_token(),
        'push' => coveted_push_public_config(),
    ]);
    exit;
}

$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'mark_read') {
            coveted_notification_mark_read($userId, (string)($_POST['notification_id'] ?? ''));
            coveted_redirect('/notifications.php');
        }

        if ($action === 'mark_all_read') {
            coveted_notification_mark_all_read($userId);
            coveted_redirect('/notifications.php');
        }

        if ($action === 'open_notification') {
            $ref = trim((string)($_POST['notification_id'] ?? ''));
            if ($ref === '' || strlen($ref) > 64) {
                throw new InvalidArgumentException('Notification not found.');
            }

            $stmt = coveted_db()->prepare(
                'SELECT action_url FROM notifications
                 WHERE user_id = ? AND (public_id = ? OR CAST(id AS CHAR) = ?)
                 LIMIT 1'
            );
            $stmt->execute([$userId, $ref, $ref]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new InvalidArgumentException('Notification not found.');
            }

            coveted_notification_mark_read($userId, $ref);
            coveted_redirect(coveted_safe_internal_path((string)($row['action_url'] ?? ''), '/notifications.php'));
        }

        throw new InvalidArgumentException('Unsupported notification action.');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted notification center error: ' . $e->getMessage());
        $error = 'Unable to update notifications right now.';
    }
}

$notifications = coveted_notifications_for_user($userId, 100);
$unreadCount = coveted_notification_unread_count($userId);
$devices = coveted_notification_devices_for_user($userId);
$activePwaDevices = count(array_filter(
    $devices,
    static fn(array $device): bool => $device['client_type'] === 'pwa' && $device['status'] === 'active'
));
$push = coveted_push_public_config();
$displayTimezone = coveted_timezone();
$formatNotificationTime = static function (?string $value) use ($displayTimezone): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return coveted_utc_datetime($value)->setTimezone($displayTimezone)->format('M j, Y · g:i A');
};

coveted_page_start('Notifications');
?>
<section class="cv-page-heading cv-notification-heading">
    <span class="cv-eyebrow">NOTIFICATIONS</span>
    <h1>What needs your attention.</h1>
    <p>Invitations, event updates, mystery reveals and new benefits appear here first.</p>
</section>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<section class="cv-card cv-form cv-push-preferences" data-push-controls>
    <div>
        <span class="cv-kicker">PWA NOTIFICATIONS</span>
        <h2>Get important Coveted updates on this device.</h2>
        <p>Push notifications are optional. In-app notifications remain available here even when browser notifications are off.</p>
    </div>
    <div class="cv-action-row cv-push-actions">
        <?php if ($push['enabled']): ?>
            <button class="cv-button" type="button" data-push-enable>Enable on this device</button>
            <button class="cv-button cv-button-soft" type="button" data-push-disable>Disable on this device</button>
            <span class="cv-status" data-push-status><?= $activePwaDevices > 0 ? coveted_e($activePwaDevices . ' PWA device' . ($activePwaDevices === 1 ? '' : 's') . ' active') : 'Not enabled on this account yet' ?></span>
        <?php else: ?>
            <span class="cv-status">Web Push is not configured by System Admin yet.</span>
        <?php endif; ?>
    </div>
</section>

<div class="cv-section-head">
    <div>
        <span class="cv-eyebrow">INBOX</span>
        <h2><?= $unreadCount ?> unread</h2>
    </div>
    <?php if ($unreadCount > 0): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="mark_all_read">
            <button class="cv-button cv-button-soft" type="submit">Mark all read</button>
        </form>
    <?php endif; ?>
</div>

<section class="cv-list cv-notification-list">
    <?php if (!$notifications): ?>
        <div class="cv-card cv-empty">
            <h2>Nothing waiting.</h2>
            <p>When something needs your attention, it will show up here.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($notifications as $notification): ?>
        <?php
        $isUnread = empty($notification['read_at']);
        $hasAction = !empty($notification['action_url']);
        ?>
        <article class="cv-card cv-admin-row cv-notification-item <?= $isUnread ? 'is-unread' : '' ?>">
            <div class="cv-notification-copy">
                <div class="cv-meta-row cv-notification-meta">
                    <span><?= coveted_e(strtoupper(str_replace(['.', '_'], ' ', (string)$notification['notification_type']))) ?></span>
                    <span><?= coveted_e($formatNotificationTime((string)$notification['created_at'])) ?></span>
                    <?php if ($isUnread): ?><span class="cv-status">Unread</span><?php endif; ?>
                </div>
                <h3><?= coveted_e($notification['title']) ?></h3>
                <?php if ($notification['body']): ?><p><?= coveted_e($notification['body']) ?></p><?php endif; ?>
            </div>

            <div class="cv-admin-actions cv-notification-actions">
                <?php if ($hasAction): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="open_notification">
                        <input type="hidden" name="notification_id" value="<?= coveted_e($notification['public_id']) ?>">
                        <button class="cv-button" type="submit">Open</button>
                    </form>
                <?php endif; ?>
                <?php if ($isUnread): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                        <input type="hidden" name="action" value="mark_read">
                        <input type="hidden" name="notification_id" value="<?= coveted_e($notification['public_id']) ?>">
                        <button class="cv-button cv-button-soft" type="submit">Mark read</button>
                    </form>
                <?php else: ?>
                    <span class="cv-status">Read</span>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>
<?php coveted_page_end(); ?>