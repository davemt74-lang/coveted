<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/member_sample_data.php';
require_once dirname(__DIR__) . '/app/sample_data.php';
require_once dirname(__DIR__) . '/app/nationwide_cities.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
$error = '';
$notice = isset($_GET['saved']) ? 'Sample data setting updated.' : '';

try {
    coveted_sync_nationwide_cities($pdo);
} catch (Throwable $e) {
    error_log('Coveted nationwide city sync failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        $action = trim((string)($_POST['action'] ?? ''));
        $enabled = (string)($_POST['enabled'] ?? '0') === '1';

        if ($action === 'set_member_sample_data') {
            coveted_site_setting_set_bool(COVETED_SETTING_MEMBER_SAMPLE_DATA, $enabled, $admin, $pdo);
            coveted_redirect('/admin/sample-data.php?saved=1');
        }
        if ($action === 'set_landing_city_strip') {
            coveted_site_setting_set_bool(COVETED_SETTING_LANDING_CITY_STRIP, $enabled, $admin, $pdo);
            coveted_redirect('/admin/sample-data.php?saved=1');
        }
        if ($action === 'set_landing_network_stats') {
            coveted_site_setting_set_bool(COVETED_SETTING_LANDING_NETWORK_STATS, $enabled, $admin, $pdo);
            coveted_redirect('/admin/sample-data.php?saved=1');
        }

        throw new InvalidArgumentException('Unsupported sample data action.');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted sample data update failed: ' . $e->getMessage());
        $error = 'Unable to update sample data. Check database permissions and try again.';
    }
}

$enabled = coveted_site_setting_bool(COVETED_SETTING_MEMBER_SAMPLE_DATA, false, $pdo);
$cityStripEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_CITY_STRIP, true, $pdo);
$networkStatsEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_NETWORK_STATS, true, $pdo);
$sample = coveted_member_sample_data();
$landingCities = coveted_sample_landing_cities();
$landingStats = coveted_sample_landing_network_stats();

coveted_page_start('Sample Data', '', true);
coveted_admin_ui_start($admin, 'sample-data', 'Sample Data');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">PREVIEW DATA</span>
        <h1>Sample data.</h1>
        <p>Control synthetic member and public landing-page previews without creating fake live users, events, groups, benefits or relationship records.</p>
    </div>
    <a class="cv-button cv-button-soft" href="/" target="_blank" rel="noopener">Open Public / Member View</a>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<section class="cv-admin-panel">
    <div class="cv-admin-panel-head">
        <div>
            <span class="cv-eyebrow">MEMBER PREVIEW</span>
            <h2>Signed-in member sample data</h2>
        </div>
        <span class="cv-status"><?= $enabled ? 'ON' : 'OFF' ?></span>
    </div>

    <p>When enabled, System Admins see the synthetic member dataset while using Member View. Ordinary member accounts continue to use their real database state.</p>
    <p class="cv-form-help">The preview data is generated in memory. It cannot receive invitations, RSVPs, attendance, claims, rewards, messages or other live mutations.</p>

    <form method="post" class="cv-action-row">
        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
        <input type="hidden" name="action" value="set_member_sample_data">
        <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
        <button class="cv-button <?= $enabled ? 'cv-button-soft' : 'cv-button-primary' ?>" type="submit">
            <?= $enabled ? 'Turn Member Sample Data Off' : 'Turn Member Sample Data On' ?>
        </button>
    </form>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div>
            <span class="cv-eyebrow">PUBLIC LANDING PREVIEW</span>
            <h2>Homepage sample sections</h2>
        </div>
        <span class="cv-pill">Sample data only</span>
    </div>
    <p>These two sections appear directly below the public landing hero. Each can be turned on or off independently while we use sample network data.</p>

    <div class="cv-admin-definition-list">
        <div>
            <dt>Nationwide city slider</dt>
            <dd>
                <strong><?= $cityStripEnabled ? 'ON' : 'OFF' ?></strong> · <?= count($landingCities) ?> launch cities
                <form method="post" class="cv-action-row cv-sample-toggle-row">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="set_landing_city_strip">
                    <input type="hidden" name="enabled" value="<?= $cityStripEnabled ? '0' : '1' ?>">
                    <button class="cv-button <?= $cityStripEnabled ? 'cv-button-soft' : 'cv-button-primary' ?>" type="submit">
                        <?= $cityStripEnabled ? 'Turn City Slider Off' : 'Turn City Slider On' ?>
                    </button>
                </form>
            </dd>
        </div>
        <div>
            <dt>Network count-up totals</dt>
            <dd>
                <strong><?= $networkStatsEnabled ? 'ON' : 'OFF' ?></strong> · sample members, events, partners and connections
                <form method="post" class="cv-action-row cv-sample-toggle-row">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="set_landing_network_stats">
                    <input type="hidden" name="enabled" value="<?= $networkStatsEnabled ? '0' : '1' ?>">
                    <button class="cv-button <?= $networkStatsEnabled ? 'cv-button-soft' : 'cv-button-primary' ?>" type="submit">
                        <?= $networkStatsEnabled ? 'Turn Network Totals Off' : 'Turn Network Totals On' ?>
                    </button>
                </form>
            </dd>
        </div>
    </div>
</section>

<div class="cv-admin-metric-grid cv-admin-metric-grid-four cv-admin-section-gap">
    <div><span>Sample members</span><strong><?= number_format((int)$landingStats['members']) ?></strong><small>Landing preview</small></div>
    <div><span>Sample events</span><strong><?= number_format((int)$landingStats['events']) ?></strong><small>Landing preview</small></div>
    <div><span>Business partners</span><strong><?= number_format((int)$landingStats['business_partners']) ?></strong><small>Landing preview</small></div>
    <div><span>Connections made</span><strong><?= number_format((int)$landingStats['connections_made']) ?></strong><small>Landing preview</small></div>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div>
            <span class="cv-eyebrow">NATIONWIDE CITY SAMPLE</span>
            <h2>Public city slider inventory</h2>
        </div>
    </div>
    <div class="cv-admin-definition-list">
        <div><dt>Cities</dt><dd><?= coveted_e(implode(' · ', array_map(static fn(array $city): string => $city['name'], $landingCities))) ?></dd></div>
        <div><dt>Database behavior</dt><dd>The old Scottsdale, Tempe, Mesa, Chandler and Gilbert seed rows are archived during the initial nationwide rollout. Phoenix remains, the new nationwide city records are inserted, and later Admin status choices are preserved.</dd></div>
    </div>
</section>

<div class="cv-admin-metric-grid cv-admin-metric-grid-four cv-admin-section-gap">
    <div><span>People</span><strong><?= count($sample['people']) ?></strong><small>Synthetic members</small></div>
    <div><span>Locations</span><strong><?= count($sample['locations']) ?></strong><small>Member template sample</small></div>
    <div><span>Events</span><strong><?= count($sample['events']) ?></strong><small>Member template sample</small></div>
    <div><span>Groups + benefits</span><strong><?= count($sample['groups']) + count($sample['benefits']) ?></strong><small>Member content</small></div>
</div>

<section class="cv-admin-panel">
    <div class="cv-admin-panel-head">
        <div>
            <span class="cv-eyebrow">MEMBER PREVIEW INVENTORY</span>
            <h2>What the member templates can use</h2>
        </div>
    </div>
    <div class="cv-admin-definition-list">
        <div><dt>Events</dt><dd>Saturday Night Supper Club · Sunset Dinner · Vinyl &amp; Cocktails</dd></div>
        <div><dt>Locations</dt><dd>Ember Room · Harbor House · Velvet Note</dd></div>
        <div><dt>Groups</dt><dd>The Inner Circle · City Table Club · Late Night Listening</dd></div>
        <div><dt>Benefits</dt><dd>Dinner on us · Member welcome</dd></div>
        <div><dt>People</dt><dd>Taylor Kim · Jordan Ellis · Maya Rivera · Leo Martinez · Sienna Cole · Noah Bennett · Elena Park · Marcus Reed · Ava Stone · Eli Thompson</dd></div>
    </div>
</section>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
