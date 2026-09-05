<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/admin_ui.php';
require_once dirname(__DIR__) . '/app/site_branding.php';

$admin = coveted_require_system_admin();
$error = '';
$notice = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    coveted_require_csrf();

    try {
        $action = trim((string)($_POST['action'] ?? ''));
        if ($action === 'upload_logo') {
            coveted_site_logo_store($admin, (array)($_FILES['logo'] ?? []));
            $notice = 'Site logo uploaded and activated.';
        } elseif ($action === 'remove_logo') {
            coveted_site_logo_delete($admin);
            $notice = 'Site logo removed. Text branding is active again.';
        } else {
            throw new InvalidArgumentException('Unsupported branding action.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Site branding update failed: ' . $e->getMessage());
        $error = 'Unable to update the site logo right now.';
    }
}

$logo = coveted_site_logo_asset();
$counts = coveted_admin_ui_counts();

coveted_page_start('Branding', '', true);
coveted_admin_ui_start($admin, 'branding', 'Branding', $counts);
?>
<div class="cv-admin-page-head">
    <div>
        <span class="cv-eyebrow">SITE BRANDING</span>
        <h1>Logo & identity</h1>
        <p>Upload the primary Coveted logo used by the public header and the System Admin shell.</p>
    </div>
    <a class="cv-button cv-button-soft" href="/" target="_blank" rel="noopener">Open Site</a>
</div>

<?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="cv-alert"><?= coveted_e($notice) ?></div><?php endif; ?>

<div class="cv-admin-settings-grid">
    <section class="cv-admin-panel">
        <div class="cv-admin-panel-head">
            <div>
                <span class="cv-eyebrow">PRIMARY LOGO</span>
                <h2><?= $logo ? 'Active site logo' : 'Text branding is active' ?></h2>
            </div>
            <span class="cv-status"><?= $logo ? 'UPLOADED' : 'NOT SET' ?></span>
        </div>

        <?php if ($logo): ?>
            <div style="max-width:320px;padding:24px;border:1px solid #e8e4dd;border-radius:12px;background:#fff;margin:0 0 18px;">
                <img src="<?= coveted_e($logo['public_path']) ?>?v=<?= coveted_e($logo['version']) ?>" alt="Current site logo" style="display:block;max-width:100%;max-height:120px;object-fit:contain;">
            </div>
            <p><?= (int)$logo['width'] ?>×<?= (int)$logo['height'] ?> · <?= coveted_e($logo['mime_type']) ?></p>
        <?php else: ?>
            <p>Until a logo is uploaded, Coveted keeps the existing text wordmark in every shell.</p>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="cv-form">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="action" value="upload_logo">
            <label>
                Logo image
                <input type="file" name="logo" accept="image/png,image/webp,image/jpeg,.png,.webp,.jpg,.jpeg" required>
                <small>PNG, WebP or JPEG · up to 5 MB. Transparent PNG or WebP works best.</small>
            </label>
            <button class="cv-button cv-button-primary" type="submit"><?= $logo ? 'Replace Logo' : 'Upload Logo' ?></button>
        </form>

        <?php if ($logo): ?>
            <form method="post" data-confirm="Remove the active site logo and return to text branding?" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
                <input type="hidden" name="action" value="remove_logo">
                <button class="cv-button cv-button-soft" type="submit">Remove Logo</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="cv-admin-panel">
        <span class="cv-eyebrow">WHERE IT APPEARS</span>
        <h2>One upload, shared identity</h2>
        <div class="cv-stack cv-stack-tight">
            <div class="cv-mini-row"><strong>Public site header</strong><span>Automatic</span></div>
            <div class="cv-mini-row"><strong>Signed-in member shell</strong><span>Automatic</span></div>
            <div class="cv-mini-row"><strong>System Admin sidebar</strong><span>Automatic</span></div>
        </div>
        <p class="cv-form-help">The uploaded image replaces the visible text wordmark. If it is removed or unreadable, Coveted falls back to the existing text brand automatically.</p>
    </section>
</div>
<?php coveted_admin_ui_end(); ?>
<?php coveted_page_end(); ?>
