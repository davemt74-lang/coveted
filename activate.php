<?php
declare(strict_types=1);

require_once __DIR__ . '/app/invite_crm.php';

$pdo = coveted_db();
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$complete = false;
$activation = null;

try {
    $activation = coveted_activation_lookup($token, $pdo);
} catch (Throwable $e) {
    error_log('Activation lookup failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();
    try {
        $result = coveted_activation_complete(
            $token,
            (string)($_POST['password'] ?? ''),
            (string)($_POST['password_confirm'] ?? ''),
            $pdo
        );
        coveted_establish_session((int)$result['user_id']);
        $complete = true;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        $activation = coveted_activation_lookup($token, $pdo);
    } catch (Throwable $e) {
        error_log('Activation completion failed: ' . $e->getMessage());
        $error = 'Unable to activate your account right now.';
    }
}

coveted_page_start('Activate Account');
?>
<div class="cv-invite-request-page cv-activation-page">
    <section class="cv-invite-request-intro">
        <a class="cv-invite-request-back" href="/">← Coveted</a>
        <span class="cv-eyebrow">WELCOME TO COVETED</span>
        <h1><?= $complete ? 'You’re in.' : 'Finish your account.' ?></h1>
        <p><?= $complete ? 'Your Coveted membership account is active.' : 'Create your password to activate the account created from your invite request.' ?></p>
    </section>

    <?php if ($complete): ?>
        <section class="cv-card cv-invite-request-success">
            <h2>Account activated.</h2>
            <p>You are signed in and ready to open your member experience.</p>
            <a class="cv-button cv-button-primary" href="/">Open Coveted</a>
        </section>
    <?php elseif (!$activation): ?>
        <section class="cv-card cv-invite-request-success">
            <h2>This activation link is no longer available.</h2>
            <p>The link may have expired or already been used. Contact Coveted if you need a new account activation link.</p>
            <a class="cv-button cv-button-soft" href="/request-invite.php">Request an Invite</a>
        </section>
    <?php else: ?>
        <?php if ($error !== ''): ?><div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div><?php endif; ?>
        <form class="cv-card cv-invite-request-form cv-activation-form" method="post" action="/activate.php">
            <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
            <input type="hidden" name="token" value="<?= coveted_e($token) ?>">
            <div class="cv-invite-form-section">
                <div><span>ACCOUNT</span><h2><?= coveted_e((string)$activation['display_name']) ?></h2><p><?= coveted_e((string)$activation['email']) ?></p></div>
                <div class="cv-invite-form-grid">
                    <label>
                        Create password
                        <input type="password" name="password" minlength="10" maxlength="4096" required autocomplete="new-password">
                    </label>
                    <label>
                        Confirm password
                        <input type="password" name="password_confirm" minlength="10" maxlength="4096" required autocomplete="new-password">
                    </label>
                </div>
            </div>
            <div class="cv-invite-request-submit">
                <p>Your activation link expires after seven days and can only be used once.</p>
                <button class="cv-button cv-button-primary" type="submit">Activate Account</button>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php coveted_page_end(); ?>
