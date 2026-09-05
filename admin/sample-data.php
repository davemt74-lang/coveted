<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/member_sample_data.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$error = '';
$notice = isset($_GET['saved']) ? 'Member sample data setting updated.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        if (trim((string)($_POST['action'] ?? '')) !== 'set_member_sample_data') {
            throw new InvalidArgumentException('Unsupported sample data action.');
        }

        $enabled = (string)($_POST['enabled'] ?? '0') === '1';
        coveted_site_setting_set_bool(COVETED_SETTING_MEMBER_SAMPLE_DATA, $enabled, $admin, $pdo);
        coveted_redirect('/admin/sample-data.php?saved=1');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted member sample data update failed: ' . $e->getMessage());
        $error = 'Unable to update member sample data. Check database permissions and try again.';
    }
}

$enabled = coveted_site_setting_bool(COVETED_SETTING_MEMBER_SAMPLE_DATA, false, $pdo);
$sample = coveted_member_sample_data();

coveted_page_start('Sample Data', '', true);
coveted_admin_ui_start($admin, 'sample-data', 'Sample Data');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">MEMBER EXPERIENCE</span>
        <h1>Sample data.</h1>
        <p>Preview a populated Coveted member experience without creating fake users, events, groups, benefits or relationship records in the live database.</p>
    </div>
    <a class="cv-button cv-button-soft" href="/" target="_blank" rel="noopener">Open Member View</a>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<section class="cv-admin-panel">
    <div class="cv-admin-panel-head">
        <div>
            <span class="cv-eyebrow">MASTER PREVIEW</span>
            <h2>Signed-in member sample data</h2>
        </div>
        <span class="cv-status"><?= $enabled ? 'ON' : 'OFF' ?></span>
    </div>

    <p>
        When enabled, System Admins see the synthetic member dataset while using Member View. Ordinary member accounts continue to use their real database state. Turning this off immediately returns the Admin Member View to live data.
    </p>
    <p class="cv-form-help">
        The preview data is generated in memory. It cannot receive invitations, RSVPs, attendance, claims, rewards, messages or other live mutations.
    </p>

    <form method="post" class="cv-action-row">
        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
        <input type="hidden" name="action" value="set_member_sample_data">
        <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
        <button class="cv-button <?= $enabled ? 'cv-button-soft' : 'cv-button-primary' ?>" type="submit">
            <?= $enabled ? 'Turn Sample Data Off' : 'Turn Sample Data On' ?>
        </button>
    </form>
</section>

<div class="cv-admin-metric-grid cv-admin-metric-grid-four cv-admin-section-gap">
    <div><span>People</span><strong><?= count($sample['people']) ?></strong><small>Synthetic members</small></div>
    <div><span>Locations</span><strong><?= count($sample['locations']) ?></strong><small>All Phoenix, Arizona</small></div>
    <div><span>Events</span><strong><?= count($sample['events']) ?></strong><small>Upcoming experiences</small></div>
    <div><span>Groups + benefits</span><strong><?= count($sample['groups']) + count($sample['benefits']) ?></strong><small>Member content</small></div>
</div>

<section class="cv-admin-panel">
    <div class="cv-admin-panel-head">
        <div>
            <span class="cv-eyebrow">PREVIEW INVENTORY</span>
            <h2>What the member templates can use</h2>
        </div>
    </div>
    <div class="cv-admin-definition-list">
        <div><dt>Events</dt><dd>Saturday Night Supper Club · Sunset Dinner · Vinyl &amp; Cocktails</dd></div>
        <div><dt>Locations</dt><dd>Ember Room · Harbor House · Velvet Note · Phoenix, Arizona</dd></div>
        <div><dt>Groups</dt><dd>The Inner Circle · City Table Club · Late Night Listening</dd></div>
        <div><dt>Benefits</dt><dd>Dinner on us · Member welcome</dd></div>
        <div><dt>People</dt><dd>Taylor Kim · Jordan Ellis · Maya Rivera · Leo Martinez · Sienna Cole · Noah Bennett · Elena Park · Marcus Reed · Ava Stone · Eli Thompson</dd></div>
    </div>
</section>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
