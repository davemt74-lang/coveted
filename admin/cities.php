<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/nationwide_cities.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
coveted_invite_crm_ensure_schema($pdo);
coveted_sync_nationwide_cities($pdo);

$error = '';
$notice = trim((string)($_SESSION['cities_notice'] ?? ''));
unset($_SESSION['cities_notice']);
$status = strtolower(trim((string)($_GET['status'] ?? 'active')));
$search = trim((string)($_GET['q'] ?? ''));
if (!in_array($status, ['active', 'paused', 'archived', 'all'], true)) {
    $status = 'active';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'create_city') {
            coveted_city_create($admin, $_POST, $pdo);
            $_SESSION['cities_notice'] = 'City added to the Coveted database.';
            coveted_redirect('/admin/cities.php');
        }
        if ($action === 'city_status') {
            coveted_city_set_status($admin, (int)($_POST['city_id'] ?? 0), (string)($_POST['status'] ?? ''), $pdo);
            $_SESSION['cities_notice'] = 'City status updated.';
            coveted_redirect('/admin/cities.php?status=' . rawurlencode($status));
        }
        throw new InvalidArgumentException('Unsupported city action.');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Admin city action failed: ' . $e->getMessage());
        $error = 'Unable to complete that city action.';
    }
}

$cities = coveted_cities_list($status, $pdo);
if ($search !== '') {
    $needle = mb_strtolower($search);
    $cities = array_values(array_filter($cities, static function (array $city) use ($needle): bool {
        $haystack = mb_strtolower(implode(' ', [
            (string)$city['name'],
            (string)($city['region'] ?? ''),
            (string)$city['country'],
            (string)$city['timezone'],
            (string)$city['status'],
        ]));
        return str_contains($haystack, $needle);
    }));
}

$allCities = coveted_cities_list('all', $pdo);
$cityCounts = ['active' => 0, 'paused' => 0, 'archived' => 0];
foreach ($allCities as $city) {
    if (isset($cityCounts[(string)$city['status']])) {
        $cityCounts[(string)$city['status']]++;
    }
}
$adminCounts = coveted_admin_ui_counts($pdo);

coveted_page_start('Cities', '', true);
coveted_admin_ui_start($admin, 'cities', 'Cities', $adminCounts);
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">COMMUNITY · CITIES</span>
        <h1>City network</h1>
        <p>Manage the cities Coveted operates in and see where invite demand, members, groups and business locations are accumulating.</p>
    </div>
    <button class="cv-button cv-button-primary" type="button" data-dialog-open="create-city">＋ Add City</button>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<div class="cv-city-metrics">
    <a class="<?= $status === 'active' ? 'is-active' : '' ?>" href="/admin/cities.php?status=active"><span>Active</span><strong><?= (int)$cityCounts['active'] ?></strong></a>
    <a class="<?= $status === 'paused' ? 'is-active' : '' ?>" href="/admin/cities.php?status=paused"><span>Paused</span><strong><?= (int)$cityCounts['paused'] ?></strong></a>
    <a class="<?= $status === 'archived' ? 'is-active' : '' ?>" href="/admin/cities.php?status=archived"><span>Archived</span><strong><?= (int)$cityCounts['archived'] ?></strong></a>
    <a class="<?= $status === 'all' ? 'is-active' : '' ?>" href="/admin/cities.php?status=all"><span>All</span><strong><?= count($allCities) ?></strong></a>
</div>

<form class="cv-admin-toolbar cv-city-toolbar" method="get" action="/admin/cities.php">
    <input type="hidden" name="status" value="<?= coveted_e($status) ?>">
    <label>
        <span class="cv-sr-only">Search cities</span>
        <input type="search" name="q" value="<?= coveted_e($search) ?>" placeholder="Search city, state, country or timezone">
    </label>
    <button class="cv-button cv-button-soft" type="submit">Search</button>
    <?php if ($search !== ''): ?><a class="cv-button cv-button-soft" href="/admin/cities.php?status=<?= coveted_e($status) ?>">Clear</a><?php endif; ?>
</form>

<section class="cv-admin-panel cv-city-database-panel">
    <div class="cv-admin-panel-head">
        <div><span class="cv-eyebrow">DATABASE</span><h2><?= coveted_e(ucfirst($status)) ?> cities</h2></div>
        <span class="cv-pill"><?= count($cities) ?> shown</span>
    </div>

    <?php if (!$cities): ?>
        <div class="cv-admin-empty"><strong>No cities in this view.</strong><span>Add a city or change the current filter.</span></div>
    <?php else: ?>
        <div class="cv-city-table-wrap">
            <table class="cv-admin-table cv-city-table">
                <thead>
                    <tr>
                        <th>City</th>
                        <th>Status</th>
                        <th>Invite CRM</th>
                        <th>Members</th>
                        <th>Groups</th>
                        <th>Locations</th>
                        <th>Timezone</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cities as $city): ?>
                        <tr>
                            <td><strong><?= coveted_e(coveted_city_label($city)) ?></strong><small><?= coveted_e((string)$city['country']) ?> · sort <?= (int)$city['sort_order'] ?></small></td>
                            <td><span class="cv-status"><?= coveted_e(ucfirst((string)$city['status'])) ?></span></td>
                            <td><strong><?= (int)$city['lead_count'] ?></strong><small>requests</small></td>
                            <td><strong><?= (int)$city['member_count'] ?></strong><small>profiles</small></td>
                            <td><strong><?= (int)$city['group_count'] ?></strong><small>groups</small></td>
                            <td><strong><?= (int)$city['location_count'] ?></strong><small>business locations</small></td>
                            <td><?= coveted_e((string)$city['timezone']) ?></td>
                            <td>
                                <form method="post" class="cv-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="city_status">
                                    <input type="hidden" name="city_id" value="<?= (int)$city['id'] ?>">
                                    <select name="status" aria-label="City status">
                                        <?php foreach (['active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived'] as $key => $label): ?>
                                            <option value="<?= coveted_e($key) ?>" <?= $city['status'] === $key ? 'selected' : '' ?>><?= coveted_e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="cv-button cv-button-soft" type="submit">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<dialog class="cv-admin-create-dialog cv-city-create-dialog" data-dialog="create-city">
    <div class="cv-admin-create-dialog-content">
        <div class="cv-admin-dialog-head">
            <div><span class="cv-eyebrow">NEW CITY</span><h2>Add operating city</h2><p>This city becomes available on the public invite request form.</p></div>
            <button type="button" class="cv-admin-dialog-close" data-dialog-close aria-label="Close">×</button>
        </div>
        <form method="post" class="cv-form-grid">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="create_city">
            <label>City<input name="name" maxlength="160" required placeholder="San Francisco"></label>
            <label>State / region<input name="region" maxlength="160" placeholder="California"></label>
            <label>Country code<input name="country" maxlength="2" value="US" required></label>
            <label>Timezone<input name="timezone" maxlength="64" value="America/Los_Angeles" required placeholder="America/Los_Angeles"></label>
            <label>Sort order<input type="number" name="sort_order" min="0" max="10000" value="100"></label>
            <div class="cv-admin-dialog-actions">
                <button class="cv-button cv-button-soft" type="button" data-dialog-close>Cancel</button>
                <button class="cv-button cv-button-primary" type="submit">Add City</button>
            </div>
        </form>
    </div>
</dialog>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
