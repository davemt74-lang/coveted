<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/service_packages.php';

$admin = coveted_require_system_admin();
$pdo = coveted_db();
if (coveted_system_sample_mode($admin, $pdo)) {
    coveted_redirect('/admin/agent.php');
}

$schemaReady = coveted_service_packages_schema_available($pdo);
$error = '';
$notice = trim((string)($_SESSION['service_packages_notice'] ?? ''));
unset($_SESSION['service_packages_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        if (!$schemaReady) {
            throw new RuntimeException('Import the Service Packages & Billing migration before changing package access.');
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'package_save') {
            $package = coveted_service_save_package($admin, [
                'id' => (int)($_POST['package_id'] ?? 0),
                'package_key' => (string)($_POST['package_key'] ?? ''),
                'name' => (string)($_POST['name'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'monthly_price' => (string)($_POST['monthly_price'] ?? ''),
                'currency' => (string)($_POST['currency'] ?? 'USD'),
                'sort_order' => (int)($_POST['sort_order'] ?? 100),
                'is_active' => isset($_POST['is_active']),
                'is_default' => isset($_POST['is_default']),
            ], $pdo);
            $_SESSION['service_packages_notice'] = 'Saved ' . (string)$package['name'] . ' package.';
        } elseif ($action === 'entitlements_save') {
            $packageId = (int)($_POST['package_id'] ?? 0);
            coveted_service_replace_entitlements(
                $admin,
                $packageId,
                coveted_service_parse_entitlements((string)($_POST['entitlements'] ?? '')),
                $pdo
            );
            $_SESSION['service_packages_notice'] = 'Package entitlements saved.';
        } elseif ($action === 'assignment_save') {
            $result = coveted_service_assign_package(
                $admin,
                (string)($_POST['scope_type'] ?? ''),
                (string)($_POST['scope_ref'] ?? ''),
                (int)($_POST['package_id'] ?? 0),
                (string)($_POST['reason'] ?? ''),
                trim((string)($_POST['starts_at'] ?? '')) ?: null,
                trim((string)($_POST['ends_at'] ?? '')) ?: null,
                $pdo
            );
            $_SESSION['service_packages_notice'] = 'Assigned ' . (string)$result['package']['name'] . ' to ' . (string)$result['scope']['scope_label'] . '. Payment is bypassed for this Admin assignment.';
        } elseif ($action === 'assignment_revoke') {
            coveted_service_revoke_assignment(
                $admin,
                (string)($_POST['assignment_ref'] ?? ''),
                (string)($_POST['reason'] ?? ''),
                $pdo
            );
            $_SESSION['service_packages_notice'] = 'Package assignment revoked. Normal precedence will apply on the next request.';
        } else {
            throw new InvalidArgumentException('Unsupported service package action.');
        }
        coveted_redirect('/admin/service-packages.php');
    } catch (InvalidArgumentException|RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Service Packages action failed: ' . $e->getMessage());
        $error = 'Unable to save that service package change.';
    }
}

$packages = [];
$assignments = [];
$users = [];
$businesses = [];
$activeSubscriptionCount = 0;
$conflicts = [];
if ($schemaReady) {
    try {
        $packages = coveted_service_packages(false, $pdo);
        $assignments = coveted_service_active_assignments($pdo);
        $users = coveted_service_admin_users($pdo);
        $businesses = coveted_service_admin_businesses($pdo);
        $activeSubscriptionCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM billing_subscriptions
             WHERE status IN ('trialing','active') AND (current_period_end IS NULL OR current_period_end > NOW())"
        )->fetchColumn();
        foreach ($assignments as $assignment) {
            $subjectType = (string)$assignment['scope_type'];
            $subjectId = $subjectType === 'user' ? (int)$assignment['user_id'] : ($subjectType === 'business' ? (int)$assignment['business_id'] : 0);
            if ($subjectId > 0) {
                $active = coveted_service_subscriptions_for_subject($subjectType, $subjectId, true, $pdo);
                if ($active) {
                    $conflicts[(string)$assignment['public_id']] = $active;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Service Packages workspace unavailable: ' . $e->getMessage());
        if ($error === '') {
            $error = 'Service Packages is temporarily unavailable.';
        }
    }
}

$moneyValue = static function (array $package): string {
    return $package['monthly_price_cents'] === null ? '' : number_format((int)$package['monthly_price_cents'] / 100, 2, '.', '');
};
$entitlementText = static function (array $values): string {
    $lines = [];
    foreach ($values as $key => $value) {
        $lines[] = (string)$key . '=' . (string)$value;
    }
    return implode("\n", $lines);
};

coveted_page_start('Service Packages', '', true);
coveted_admin_ui_start($admin, 'service-packages', 'Service Packages');
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">PLATFORM · BILLING & ACCESS</span>
        <h1>Service packages and billing overrides.</h1>
        <p>Define package pricing and entitlements, then assign packages directly to a user, partner business, or Coveted user type. Admin assignments bypass payment and always resolve ahead of subscription status.</p>
    </div>
    <div class="cv-action-row">
        <a class="cv-button cv-button-soft" href="/billing.php">Open member Billing & Plan</a>
    </div>
</div>

<?php if (!$schemaReady): ?>
    <div class="cv-alert cv-alert-error"><strong>Database migration required.</strong> Import <code>database/migrations/20260907_service_packages_billing.sql</code>, then reload this workspace.</div>
<?php endif; ?>
<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<?php if ($schemaReady): ?>
<div class="cv-admin-metric-grid cv-admin-section-gap">
    <div><span>Packages</span><strong><?= count($packages) ?></strong><small>catalog entries</small></div>
    <div><span>Admin overrides</span><strong><?= count($assignments) ?></strong><small>active billing bypasses</small></div>
    <div><span>Active subscriptions</span><strong><?= $activeSubscriptionCount ?></strong><small>provider-backed records</small></div>
    <div><span>Billing overlaps</span><strong><?= count($conflicts) ?></strong><small>override + active subscription</small></div>
</div>

<?php if ($conflicts): ?>
<div class="cv-alert cv-alert-error cv-admin-section-gap"><strong>Billing review needed.</strong> <?= count($conflicts) ?> Admin assignment<?= count($conflicts) === 1 ? '' : 's' ?> also <?= count($conflicts) === 1 ? 'has' : 'have' ?> an active/trial subscription. The Admin package governs access, but Coveted does not auto-cancel provider billing.</div>
<?php endif; ?>

<div class="cv-admin-dashboard-grid cv-admin-section-gap">
<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PACKAGE CATALOG</span><h2>Plans</h2></div><span class="cv-status">System Admin only</span></div>
    <div class="cv-admin-list">
        <?php foreach ($packages as $package): ?>
        <details class="cv-admin-list-row">
            <summary class="cv-admin-list-copy">
                <strong><?= coveted_e((string)$package['name']) ?><?= (int)$package['is_default'] === 1 ? ' · Default' : '' ?></strong>
                <small><code><?= coveted_e((string)$package['package_key']) ?></code> · <?= coveted_e(coveted_service_format_price($package['monthly_price_cents'] !== null ? (int)$package['monthly_price_cents'] : null, (string)$package['currency'])) ?> · <?= (int)$package['is_active'] === 1 ? 'Active' : 'Inactive' ?></small>
            </summary>
            <form method="post" class="cv-stack">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                <input type="hidden" name="action" value="package_save">
                <input type="hidden" name="package_id" value="<?= (int)$package['id'] ?>">
                <label>Package key <input value="<?= coveted_e((string)$package['package_key']) ?>" disabled></label>
                <label>Name <input name="name" maxlength="100" required value="<?= coveted_e((string)$package['name']) ?>"></label>
                <label>Description <textarea name="description" rows="3" maxlength="2000"><?= coveted_e((string)($package['description'] ?? '')) ?></textarea></label>
                <label>Monthly price <input name="monthly_price" inputmode="decimal" placeholder="29.99" value="<?= coveted_e($moneyValue($package)) ?>"></label>
                <label>Currency <input name="currency" maxlength="3" value="<?= coveted_e((string)$package['currency']) ?>"></label>
                <label>Sort order <input name="sort_order" type="number" min="0" max="10000" value="<?= (int)$package['sort_order'] ?>"></label>
                <label><input name="is_active" type="checkbox" value="1" <?= (int)$package['is_active'] === 1 ? 'checked' : '' ?>> Active</label>
                <label><input name="is_default" type="checkbox" value="1" <?= (int)$package['is_default'] === 1 ? 'checked' : '' ?>> Default package</label>
                <button class="cv-button cv-button-primary" type="submit">Save package</button>
            </form>
            <form method="post" class="cv-stack cv-admin-section-gap">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                <input type="hidden" name="action" value="entitlements_save">
                <input type="hidden" name="package_id" value="<?= (int)$package['id'] ?>">
                <label>Entitlements <textarea name="entitlements" rows="6" spellcheck="false" placeholder="membership.plus=1"><?= coveted_e($entitlementText(coveted_service_entitlements_for_package((int)$package['id'], $pdo))) ?></textarea></label>
                <small>One <code>key=value</code> per line. These become canonical feature gates; existing features are unaffected until they explicitly call the entitlement helper.</small>
                <button class="cv-button cv-button-soft" type="submit">Save entitlements</button>
            </form>
        </details>
        <?php endforeach; ?>
    </div>
</section>

<section class="cv-admin-panel">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">NEW PACKAGE</span><h2>Add catalog entry</h2></div></div>
    <form method="post" class="cv-stack">
        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
        <input type="hidden" name="action" value="package_save">
        <label>Package key <input name="package_key" maxlength="64" placeholder="premium" required></label>
        <label>Name <input name="name" maxlength="100" placeholder="Premium" required></label>
        <label>Description <textarea name="description" rows="3" maxlength="2000"></textarea></label>
        <label>Monthly price <input name="monthly_price" inputmode="decimal" placeholder="29.99"></label>
        <label>Currency <input name="currency" maxlength="3" value="USD"></label>
        <label>Sort order <input name="sort_order" type="number" min="0" max="10000" value="100"></label>
        <label><input name="is_active" type="checkbox" value="1" checked> Active</label>
        <label><input name="is_default" type="checkbox" value="1"> Make default</label>
        <button class="cv-button cv-button-primary" type="submit">Create package</button>
    </form>
</section>
</div>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">PAYMENT BYPASS</span><h2>Assign package without payment</h2></div><span class="cv-status">Admin authority</span></div>
    <p>Precedence is fixed: <strong>User override → Partner override → User-type override → Subscription → Default.</strong> Assignments do not create or modify payment-provider subscriptions.</p>
    <div class="cv-admin-dashboard-grid">
        <form method="post" class="cv-stack">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="assignment_save">
            <input type="hidden" name="scope_type" value="user">
            <span class="cv-eyebrow">USER</span>
            <label>User
                <select name="scope_ref" required>
                    <option value="">Choose user</option>
                    <?php foreach ($users as $row): ?><option value="<?= coveted_e((string)$row['public_id']) ?>"><?= coveted_e((string)$row['display_name'] . ' · ' . (string)$row['email']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Package
                <select name="package_id" required><option value="">Choose package</option><?php foreach ($packages as $package): if((int)$package['is_active']!==1)continue; ?><option value="<?= (int)$package['id'] ?>"><?= coveted_e((string)$package['name']) ?></option><?php endforeach; ?></select>
            </label>
            <label>Reason <textarea name="reason" rows="2" maxlength="1000" placeholder="Comped account, staff access, migration, partnership…"></textarea></label>
            <button class="cv-button cv-button-primary" type="submit">Assign to user</button>
        </form>

        <form method="post" class="cv-stack">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="assignment_save">
            <input type="hidden" name="scope_type" value="business">
            <span class="cv-eyebrow">PARTNER</span>
            <label>Business / partner
                <select name="scope_ref" required>
                    <option value="">Choose partner</option>
                    <?php foreach ($businesses as $row): ?><option value="<?= coveted_e((string)$row['public_id']) ?>"><?= coveted_e((string)$row['name']) ?> · <?= coveted_e((string)$row['status']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Package
                <select name="package_id" required><option value="">Choose package</option><?php foreach ($packages as $package): if((int)$package['is_active']!==1)continue; ?><option value="<?= (int)$package['id'] ?>"><?= coveted_e((string)$package['name']) ?></option><?php endforeach; ?></select>
            </label>
            <label>Reason <textarea name="reason" rows="2" maxlength="1000" placeholder="Partner agreement, comp, launch program…"></textarea></label>
            <button class="cv-button cv-button-primary" type="submit">Assign to partner</button>
        </form>

        <form method="post" class="cv-stack">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="assignment_save">
            <input type="hidden" name="scope_type" value="user_role">
            <span class="cv-eyebrow">USER TYPE</span>
            <label>User type
                <select name="scope_ref" required><?php foreach (coveted_service_user_types() as $role): ?><option value="<?= coveted_e($role) ?>"><?= coveted_e(ucwords(str_replace('_',' ',$role))) ?></option><?php endforeach; ?></select>
            </label>
            <label>Package
                <select name="package_id" required><option value="">Choose package</option><?php foreach ($packages as $package): if((int)$package['is_active']!==1)continue; ?><option value="<?= (int)$package['id'] ?>"><?= coveted_e((string)$package['name']) ?></option><?php endforeach; ?></select>
            </label>
            <label>Reason <textarea name="reason" rows="2" maxlength="1000" placeholder="Default package for this user type…"></textarea></label>
            <button class="cv-button cv-button-primary" type="submit">Assign to user type</button>
        </form>
    </div>
    <p><small>Optional scheduled start/end timestamps are supported by the canonical service API even though this first Admin surface creates immediate, open-ended assignments.</small></p>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">ACTIVE OVERRIDES</span><h2>Package assignments</h2></div><span class="cv-status"><?= count($assignments) ?> active</span></div>
    <div class="cv-admin-list">
        <?php if (!$assignments): ?><div class="cv-admin-empty"><strong>No Admin billing bypasses.</strong><span>Subscription or default package precedence applies to everyone.</span></div><?php endif; ?>
        <?php foreach ($assignments as $assignment):
            $assignmentRef=(string)$assignment['public_id'];
            $hasPaid=isset($conflicts[$assignmentRef]);
        ?>
            <div class="cv-admin-list-row">
                <span class="cv-admin-list-copy">
                    <strong><?= coveted_e(coveted_service_assignment_label($assignment)) ?> → <?= coveted_e((string)$assignment['package_name']) ?></strong>
                    <small><?= coveted_e(ucwords(str_replace('_',' ',(string)$assignment['scope_type']))) ?> · assigned <?= coveted_e((string)$assignment['created_at']) ?> UTC</small>
                    <?php if (!empty($assignment['reason'])): ?><small><?= coveted_e((string)$assignment['reason']) ?></small><?php endif; ?>
                    <?php if ($hasPaid): ?><small><strong>Billing overlap:</strong> <?= count($conflicts[$assignmentRef]) ?> active/trial subscription<?= count($conflicts[$assignmentRef])===1?'':'s' ?> still exists.</small><?php endif; ?>
                </span>
                <form method="post" onsubmit="return confirm('Revoke this Admin package assignment? Normal subscription/default precedence will resume.');">
                    <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                    <input type="hidden" name="action" value="assignment_revoke">
                    <input type="hidden" name="assignment_ref" value="<?= coveted_e($assignmentRef) ?>">
                    <input type="hidden" name="reason" value="Revoked from Service Packages Admin workspace">
                    <button class="cv-button cv-button-soft" type="submit">Revoke</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="cv-admin-panel cv-admin-section-gap">
    <div class="cv-admin-panel-head"><div><span class="cv-eyebrow">AUTHORIZATION MODEL</span><h2>Payments do not own access control.</h2></div></div>
    <p><strong>Admin assignments always resolve before billing.</strong> Partner/user-type grants are real authorization inputs, not fake subscriptions. Provider state is stored separately in <code>billing_subscriptions</code>, so Stripe or another processor can be changed without rewriting feature access rules.</p>
</section>
<?php endif; ?>

<?php coveted_admin_ui_end(); coveted_page_end(); ?>
