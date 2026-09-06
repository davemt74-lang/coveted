<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/member_sample_data.php';
require_once dirname(__DIR__) . '/app/system_sample_data.php';
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

        if ($action === 'set_system_sample_data') {
            coveted_site_setting_set_bool(COVETED_SETTING_SYSTEM_SAMPLE_DATA, $enabled, $admin, $pdo);
            // Keep the established member preview in lockstep with full-system mode.
            coveted_site_setting_set_bool(COVETED_SETTING_MEMBER_SAMPLE_DATA, $enabled, $admin, $pdo);
            coveted_redirect('/admin/sample-data.php?saved=1');
        }
        // Legacy action remains accepted for older bookmarked/forms deployments.
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

$enabled = coveted_site_setting_bool(COVETED_SETTING_SYSTEM_SAMPLE_DATA, false, $pdo);
$memberEnabled = coveted_site_setting_bool(COVETED_SETTING_MEMBER_SAMPLE_DATA, false, $pdo);
$cityStripEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_CITY_STRIP, true, $pdo);
$networkStatsEnabled = coveted_site_setting_bool(COVETED_SETTING_LANDING_NETWORK_STATS, true, $pdo);
$sample = coveted_system_sample_data();
$inventory = coveted_system_sample_inventory($sample);
$landingCities = coveted_sample_landing_cities();
$landingStats = coveted_sample_landing_network_stats();

$coverage = [
    'People & access' => [
        'Users' => $inventory['people'],
        'Role requests' => $inventory['role_requests'],
        'Invite CRM' => $inventory['invite_crm'],
        'Cities' => $inventory['cities'],
    ],
    'Community & events' => [
        'Businesses' => $inventory['businesses'],
        'Locations' => $inventory['locations'],
        'Groups' => $inventory['groups'],
        'Events' => $inventory['events'],
        'Daily Events' => $inventory['daily_events'],
    ],
    'Value system' => [
        'Rewards' => $inventory['rewards'],
        'Campaigns' => $inventory['campaigns'],
        'Benefit Programs' => $inventory['benefit_programs'],
        'Sponsorships' => $inventory['sponsorships'],
        'Loyalty groups' => $inventory['loyalty'],
        'Claims' => $inventory['claims'],
        'Distribution runs' => $inventory['distribution'],
    ],
    'Partner relationships' => [
        'Relationships' => $inventory['partner_relationships'],
        'Contacts' => $inventory['partner_contacts'],
        'Notes' => $inventory['partner_notes'],
        'Conversations' => $inventory['partner_interactions'],
        'Follow-ups' => $inventory['partner_followups'],
        'Partner Perks' => $inventory['partner_perks'],
    ],
    'Artists & media' => [
        'Artists' => $inventory['artists'],
        'Media' => $inventory['artist_media'],
        'Appearances' => $inventory['artist_appearances'],
        'Notifications' => $inventory['notifications'],
    ],
    'Agent intelligence' => [
        'Opportunities' => $inventory['agent_opportunities'],
        'Tasks' => $inventory['agent_tasks'],
        'Memory events' => $inventory['agent_memory'],
    ],
];

$totalRecords = array_sum($inventory);

coveted_page_start('Sample Data', '', true);
coveted_admin_ui_start($admin, 'sample-data', 'Sample Data');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">FULL SYSTEM PREVIEW</span>
        <h1>Sample data.</h1>
        <p>One coherent synthetic Coveted network for testing the complete product without creating fake production records.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/admin/agent.php">Open Admin Agent</a>
        <a class="cv-button cv-button-soft" href="/" target="_blank" rel="noopener">Open Member View</a>
    </div>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<section class="cv-admin-panel">
    <div class="cv-admin-panel-head">
        <div>
            <span class="cv-eyebrow">SYSTEM SAMPLE MODE</span>
            <h2>Full Coveted demo network</h2>
        </div>
        <span class="cv-status"><?= $enabled ? 'ON' : 'OFF' ?></span>
    </div>

    <p>When enabled, System Admin sample-aware surfaces can use the synthetic network below, and Member View uses the matching member projection. Ordinary users, hosts, businesses and artists continue to use live canonical data.</p>
    <p class="cv-form-help"><strong>Read-only by design.</strong> The pack is generated in memory and never inserts fake users, events, claims, Loyalty balances, CRM contacts or Agent tasks into production tables. Autonomous Agent execution is disabled while full system sample mode is active.</p>

    <form method="post" class="cv-action-row">
        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
        <input type="hidden" name="action" value="set_system_sample_data">
        <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
        <button class="cv-button <?= $enabled ? 'cv-button-soft' : 'cv-button-primary' ?>" type="submit">
            <?= $enabled ? 'Turn Full System Sample Data Off' : 'Turn Full System Sample Data On' ?>
        </button>
    </form>

    <?php if (!$enabled && $memberEnabled): ?>
        <p class="cv-form-help">Legacy member-only sample mode is still enabled. Turning full system mode on and back off will synchronize both settings.</p>
    <?php endif; ?>
</section>

<div class="cv-admin-metric-grid cv-admin-metric-grid-four cv-admin-section-gap">
    <div><span>Sample records</span><strong><?= number_format($totalRecords) ?></strong><small>Generated in memory</small></div>
    <div><span>System domains</span><strong><?= count($coverage) ?></strong><small>End-to-end coverage</small></div>
    <div><span>Businesses / locations</span><strong><?= $inventory['businesses'] ?>/<?= $inventory['locations'] ?></strong><small>Partner network</small></div>
    <div><span>Agent signals</span><strong><?= $inventory['agent_opportunities'] + $inventory['agent_tasks'] ?></strong><small>Opportunities + tasks</small></div>
</div>

<section class="cv-admin-panel cv-admin-section-gap" id="sample-story">
    <div class="cv-admin-panel-head">
        <div>
            <span class="cv-eyebrow">ONE CONNECTED STORY</span>
            <h2><?= coveted_e((string)$sample['meta']['name']) ?></h2>
        </div>
        <span class="cv-pill">v<?= coveted_e((string)$sample['meta']['version']) ?></span>
    </div>
    <p><?= coveted_e((string)$sample['meta']['description']) ?></p>
    <div class="cv-admin-definition-list">
        <div><dt>Core member</dt><dd>Taylor Kim · The Inner Circle · Phoenix</dd></div>
        <div><dt>Hospitality partners</dt><dd>Ember Hospitality · Harbor House Group · Velvet Note</dd></div>
        <div><dt>Prospective partner</dt><dd>Desert Bloom Wellness · intentionally incomplete so the Agent has setup opportunities</dd></div>
        <div><dt>Artist system</dt><dd>Sienna Cole · Rina Foster · media, appearances and artist rewards</dd></div>
        <div><dt>Relationship loop</dt><dd>Events → attendance → Daily Events → Loyalty → rewards → claims → return visits → Partner CRM follow-up</dd></div>
    </div>
</section>

<?php foreach ($coverage as $domain => $rows): ?>
    <section class="cv-admin-panel cv-admin-section-gap" id="sample-<?= coveted_e(strtolower(str_replace([' & ',' '], ['-','-'], $domain))) ?>">
        <div class="cv-admin-panel-head">
            <div><span class="cv-eyebrow">SYSTEM COVERAGE</span><h2><?= coveted_e($domain) ?></h2></div>
            <span class="cv-pill"><?= array_sum($rows) ?> records</span>
        </div>
        <div class="cv-admin-definition-list">
            <?php foreach ($rows as $label => $count): ?>
                <div><dt><?= coveted_e($label) ?></dt><dd><strong><?= (int)$count ?></strong> synthetic record<?= (int)$count === 1 ? '' : 's' ?></dd></div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<section class="cv-admin-panel cv-admin-section-gap" id="sample-partners">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">PARTNER CRM</span><h2>Relationship examples</h2></div>
    </div>
    <div class="cv-admin-definition-list">
        <?php foreach ((array)$sample['partner_relationships'] as $relationship): ?>
            <div>
                <dt><?= coveted_e((string)$relationship['group_name']) ?> × <?= coveted_e((string)$relationship['location_name']) ?></dt>
                <dd><?= coveted_e(ucwords(str_replace('_',' ',(string)$relationship['relationship_status']))) ?> · <?= (int)$relationship['verified_visits'] ?> verified visits · <?= (int)$relationship['return_claims'] ?> return claims</dd>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap" id="sample-events">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">EVENT MIX</span><h2>Live lifecycle examples</h2></div>
    </div>
    <div class="cv-admin-definition-list">
        <?php foreach ((array)$sample['events'] as $event): ?>
            <div><dt><?= coveted_e((string)$event['title']) ?></dt><dd><?= coveted_e((string)$event['group_name']) ?> · <?= coveted_e(ucfirst((string)$event['status'])) ?> · <?= coveted_e((string)$event['location_name']) ?></dd></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap" id="sample-value">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">VALUE SYSTEM</span><h2>Benefit Programs and Loyalty</h2></div>
    </div>
    <div class="cv-admin-definition-list">
        <?php foreach ((array)$sample['benefit_programs'] as $program): ?>
            <div><dt><?= coveted_e((string)$program['title']) ?></dt><dd><?= coveted_e((string)$program['owner']) ?> · <?= (int)$program['issued'] ?> issued · <?= number_format((float)$program['claim_rate'],1) ?>% claim rate</dd></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap" id="sample-artists">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ARTIST SYSTEM</span><h2>Artists, media and appearances</h2></div></div>
    <div class="cv-admin-definition-list">
        <?php foreach ((array)$sample['artists'] as $artist): ?>
            <div><dt><?= coveted_e((string)$artist['artist_name']) ?></dt><dd><?= (int)$artist['team_count'] ?> team · <?= (int)$artist['appearance_count'] ?> appearances · <?= (int)$artist['reward_count'] ?> rewards</dd></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap" id="sample-operations">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">OPERATIONS + AGENT</span><h2>Useful imperfections are included</h2></div></div>
    <p>The demo is intentionally not “all green.” It includes an overdue partner follow-up, a draft event, pending role requests, a prospective partner missing Admin coverage, a submitted sponsorship, lifecycle backlog and one push retry so Operations and the Admin Agent have meaningful work to surface.</p>
    <div class="cv-admin-definition-list">
        <?php foreach ((array)$sample['agent']['opportunities'] as $opportunity): ?>
            <div><dt>P<?= (int)$opportunity['priority'] ?> · <?= coveted_e((string)$opportunity['title']) ?></dt><dd><?= coveted_e((string)$opportunity['evidence']) ?></dd></div>
        <?php endforeach; ?>
    </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">PUBLIC LANDING PREVIEW</span><h2>Homepage sample sections</h2></div>
        <span class="cv-pill">Independent controls</span>
    </div>
    <div class="cv-admin-definition-list">
        <div>
            <dt>Nationwide city slider</dt>
            <dd><strong><?= $cityStripEnabled ? 'ON' : 'OFF' ?></strong> · <?= count($landingCities) ?> launch cities
                <form method="post" class="cv-action-row cv-sample-toggle-row">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="set_landing_city_strip"><input type="hidden" name="enabled" value="<?= $cityStripEnabled ? '0' : '1' ?>">
                    <button class="cv-button <?= $cityStripEnabled ? 'cv-button-soft' : 'cv-button-primary' ?>" type="submit"><?= $cityStripEnabled ? 'Turn City Slider Off' : 'Turn City Slider On' ?></button>
                </form>
            </dd>
        </div>
        <div>
            <dt>Network count-up totals</dt>
            <dd><strong><?= $networkStatsEnabled ? 'ON' : 'OFF' ?></strong> · <?= number_format((int)$landingStats['members']) ?> members / <?= number_format((int)$landingStats['events']) ?> events
                <form method="post" class="cv-action-row cv-sample-toggle-row">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="set_landing_network_stats"><input type="hidden" name="enabled" value="<?= $networkStatsEnabled ? '0' : '1' ?>">
                    <button class="cv-button <?= $networkStatsEnabled ? 'cv-button-soft' : 'cv-button-primary' ?>" type="submit"><?= $networkStatsEnabled ? 'Turn Network Totals Off' : 'Turn Network Totals On' ?></button>
                </form>
            </dd>
        </div>
    </div>
</section>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
