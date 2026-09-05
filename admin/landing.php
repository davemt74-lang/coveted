<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/site_settings.php';
require_once dirname(__DIR__) . '/app/sample_data.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$error = '';
$saved = trim((string)($_GET['saved'] ?? ''));
$notice = match ($saved) {
    'events' => 'Upcoming Events visibility updated.',
    'sample' => 'Landing page sample events updated.',
    default => '',
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = trim((string)($_POST['action'] ?? ''));
        $enabled = (string)($_POST['enabled'] ?? '0') === '1';

        switch ($action) {
            case 'set_landing_events':
                coveted_site_setting_set_bool(COVETED_SETTING_LANDING_EVENTS, $enabled, $admin, $pdo);

                // The sample-event switch is a content source for this section, not a
                // separate hidden mode. Hiding Upcoming Events also turns sample mode
                // off so Admin can never be left with an ON sample state that is not
                // visible on the public landing page.
                if (!$enabled && coveted_site_setting_bool(COVETED_SETTING_LANDING_SAMPLE_EVENTS, false, $pdo)) {
                    coveted_site_setting_set_bool(COVETED_SETTING_LANDING_SAMPLE_EVENTS, false, $admin, $pdo);
                }

                coveted_redirect('/admin/landing.php?saved=events');
                break;

            case 'set_landing_sample_events':
                // Turning sample events on must make them visible immediately. Keep
                // the public Upcoming Events section enabled before selecting sample
                // data as its source.
                if ($enabled) {
                    coveted_site_setting_set_bool(COVETED_SETTING_LANDING_EVENTS, true, $admin, $pdo);
                }
                coveted_site_setting_set_bool(COVETED_SETTING_LANDING_SAMPLE_EVENTS, $enabled, $admin, $pdo);
                coveted_redirect('/admin/landing.php?saved=sample');
                break;

            default:
                throw new InvalidArgumentException('Unsupported landing page action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted landing setting update failed: ' . $e->getMessage());
        $error = 'Unable to update the landing page setting. Check database permissions and try again.';
    }
}

$landingEventsEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_EVENTS, false, $pdo);
$sampleEventsEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_SAMPLE_EVENTS, false, $pdo);
$previewEvents = [];

if ($sampleEventsEnabled) {
    $previewEvents = coveted_sample_landing_events();
} else {
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
}

coveted_page_start('Landing Page', '', true);
coveted_admin_ui_start($admin, 'landing', 'Landing Page');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">PUBLIC EXPERIENCE</span>
        <h1>Landing page.</h1>
        <p>Control which event content is visible before a visitor signs in.</p>
    </div>
    <a class="cv-button cv-button-soft" href="/" target="_blank" rel="noopener">Open Public Site</a>
</div>

<?php if ($error !== ''): ?><div class="cv-alert"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<div class="cv-admin-settings-grid">
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
            Live mode shows up to four future published group events. Invitation-only events, group
            names, locations, RSVP counts and private reveal information are never exposed.
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

    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head">
            <div>
                <span class="cv-eyebrow">SAMPLE DATA</span>
                <h2>Landing page sample events</h2>
            </div>
            <span class="cv-status"><?= $sampleEventsEnabled ? 'ON' : 'OFF' ?></span>
        </div>

        <p>
            When enabled, the public Upcoming Events section uses four synthetic preview events instead
            of live event records. Nothing is inserted into the database and no sample record can receive
            invitations, RSVPs, attendance, campaigns or rewards.
        </p>
        <p class="cv-form-help">
            Turning sample events ON also turns the public Upcoming Events section ON, so the preview is visible immediately.
            Hiding Upcoming Events turns sample mode OFF as well.
        </p>

        <form method="post" class="cv-action-row">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="set_landing_sample_events">
            <input type="hidden" name="enabled" value="<?= $sampleEventsEnabled ? '0' : '1' ?>">
            <button class="cv-button <?= $sampleEventsEnabled ? 'cv-button-soft' : 'cv-button-primary' ?>" type="submit">
                <?= $sampleEventsEnabled ? 'Turn Sample Events Off' : 'Show Sample Events' ?>
            </button>
        </form>
    </section>
</div>

<div class="cv-section-head cv-admin-section-gap">
    <div>
        <span class="cv-eyebrow">PUBLIC PREVIEW DATA</span>
        <h2><?= $sampleEventsEnabled ? 'Synthetic events selected for the landing page' : 'Live events eligible for the section' ?></h2>
        <p><?= $sampleEventsEnabled ? 'These four sample events replace live event cards without writing any sample records.' : 'The public page uses this same filter and ordering.' ?></p>
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
        <?php $isSample = !empty($event['is_sample']); ?>
        <article class="cv-card cv-admin-row">
            <div>
                <div class="cv-tag-row">
                    <span class="cv-kicker"><?= coveted_e(strtoupper(str_replace('_', ' ', (string)$event['event_type']))) ?></span>
                    <span class="cv-pill"><?= $isSample ? 'Sample' : 'Published' ?></span>
                </div>
                <h3><?= coveted_e($event['event_type'] === 'mystery' ? 'Mystery gathering' : (string)$event['title']) ?></h3>
                <p>
                    <?= coveted_e(coveted_event_format($event, 'D, M j · g:i A')) ?>
                    <?php if (!$isSample): ?> · <?= coveted_e((string)$event['group_name']) ?><?php endif; ?>
                </p>
            </div>
            <?php if ($isSample): ?>
                <span class="cv-pill">Synthetic preview</span>
            <?php else: ?>
                <a class="cv-button cv-button-soft" href="/host.php?event=<?= coveted_e(rawurlencode((string)$event['public_id'])) ?>">Manage Event</a>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
