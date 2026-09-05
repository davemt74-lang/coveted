<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/site_settings.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$error = '';
$notice = isset($_GET['saved']) ? 'Landing page setting updated.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action !== 'set_landing_events') {
            throw new InvalidArgumentException('Unsupported landing page action.');
        }

        $enabled = (string)($_POST['enabled'] ?? '0') === '1';
        coveted_site_setting_set_bool(COVETED_SETTING_LANDING_EVENTS, $enabled, $admin, $pdo);
        coveted_redirect('/admin/landing.php?saved=1');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted landing setting update failed: ' . $e->getMessage());
        $error = 'Unable to update the landing page setting. Check database permissions and try again.';
    }
}

$landingEventsEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_EVENTS, false, $pdo);
$previewEvents = [];

try {
    $previewEvents = $pdo->query(
        "SELECT e.public_id, e.title, e.event_type, e.timezone, e.starts_at, g.name AS group_name
         FROM events e
         JOIN social_groups g ON g.id = e.group_id
         WHERE e.status = 'published'
           AND e.audience = 'group'
           AND e.starts_at >= UTC_TIMESTAMP()
         ORDER BY e.starts_at ASC
         LIMIT 4"
    )->fetchAll();
} catch (Throwable $e) {
    error_log('Coveted landing event preview unavailable: ' . $e->getMessage());
    if ($error === '') {
        $error = 'The landing setting is available, but upcoming event preview data could not be loaded.';
    }
}

coveted_page_start('Landing Page', '', true);
coveted_admin_ui_start($admin, 'landing', 'Landing Page');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">PUBLIC EXPERIENCE</span>
        <h1>Landing page.</h1>
        <p>Control which live event content is visible before a visitor signs in.</p>
    </div>
    <a class="cv-button cv-button-soft" href="/" target="_blank" rel="noopener">Preview Landing Page</a>
</div>

<?php if ($error !== ''): ?><div class="cv-alert"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<section class="cv-admin-panel">
    <div class="cv-admin-panel-head">
        <div>
            <span class="cv-eyebrow">UPCOMING EVENTS</span>
            <h2>Landing page event section</h2>
        </div>
        <span class="cv-status"><?= $landingEventsEnabled ? 'ON' : 'OFF' ?></span>
    </div>

    <p>
        When enabled, an Upcoming Events section appears directly below the landing-page hero.
        It shows up to four future published group events. Invitation-only events, group names,
        locations, RSVP counts and private reveal information are never exposed.
    </p>

    <form method="post" class="cv-action-row">
        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
        <input type="hidden" name="action" value="set_landing_events">
        <input type="hidden" name="enabled" value="<?= $landingEventsEnabled ? '0' : '1' ?>">
        <button class="cv-button <?= $landingEventsEnabled ? 'cv-button-soft' : 'cv-button-primary' ?>" type="submit">
            <?= $landingEventsEnabled ? 'Hide Upcoming Events' : 'Show Upcoming Events' ?>
        </button>
    </form>
</section>

<div class="cv-section-head cv-admin-section-gap">
    <div>
        <span class="cv-eyebrow">PUBLIC PREVIEW</span>
        <h2>Events eligible for the section</h2>
        <p>The public page uses this same filter and ordering.</p>
    </div>
    <span class="cv-pill"><?= count($previewEvents) ?> shown</span>
</div>

<section class="cv-stack">
    <?php if (!$previewEvents): ?>
        <div class="cv-card cv-empty">
            <h3>No eligible upcoming events.</h3>
            <p>The section can still be enabled; visitors will see a simple “new gatherings are being planned” state until a published group event is available.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($previewEvents as $event): ?>
        <article class="cv-card cv-admin-row">
            <div>
                <div class="cv-tag-row">
                    <span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$event['event_type']))) ?></span>
                    <span class="cv-pill">Published</span>
                </div>
                <h3><?= coveted_e($event['title']) ?></h3>
                <p><?= coveted_e(coveted_event_format($event, 'D, M j · g:i A')) ?> · <?= coveted_e($event['group_name']) ?></p>
            </div>
            <a class="cv-button cv-button-soft" href="/host.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Manage Event</a>
        </article>
    <?php endforeach; ?>
</section>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
