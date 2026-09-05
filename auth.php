<?php
declare(strict_types=1);

require_once __DIR__ . '/app/notifications.php';
require_once __DIR__ . '/app/admin_onboarding.php';

function coveted_auth_return_path(): string
{
    $return = coveted_safe_internal_path($_POST['return'] ?? $_GET['return'] ?? '/');

    if (str_starts_with($return, '/auth.php')) {
        return '/';
    }

    return $return;
}

$action = strtolower(trim((string)($_GET['action'] ?? 'login')));

if ($action === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method not allowed.');
    }

    $user = coveted_require_user();
    coveted_require_csrf();

    $clientId = trim((string)($_POST['notification_client_id'] ?? $_COOKIE['coveted_push_client'] ?? ''));
    if ($clientId !== '') {
        try {
            coveted_notification_disable_device($user, $clientId);
        } catch (InvalidArgumentException $e) {
            error_log('Coveted logout ignored invalid notification client id: ' . $e->getMessage());
        } catch (Throwable $e) {
            error_log('Coveted logout device deactivation failed: ' . $e->getMessage());
        }
    }

    setcookie('coveted_push_client', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => coveted_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    coveted_logout();
    coveted_redirect('/');
}

$returnPath = coveted_auth_return_path();

if ($existingUser = coveted_current_user()) {
    if ($returnPath === '/' && coveted_is_system_admin($existingUser) && coveted_admin_should_show_onboarding($existingUser)) {
        coveted_redirect('/admin/onboarding.php');
    }
    coveted_redirect($returnPath);
}

if (!in_array($action, ['login', 'register'], true)) {
    $action = 'login';
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    coveted_require_csrf();

    try {
        if ($action === 'register') {
            $created = coveted_register(
                (string)($_POST['name'] ?? ''),
                (string)($_POST['email'] ?? ''),
                (string)($_POST['password'] ?? '')
            );

            coveted_establish_session((int)$created['id']);
            coveted_redirect($returnPath);
        }

        $loggedIn = coveted_login(
            (string)($_POST['email'] ?? ''),
            (string)($_POST['password'] ?? '')
        );

        if (!$loggedIn) {
            throw new InvalidArgumentException('Email or password is incorrect.');
        }

        $signedInUser = coveted_current_user();
        if ($returnPath === '/' && $signedInUser && coveted_is_system_admin($signedInUser) && coveted_admin_should_show_onboarding($signedInUser)) {
            coveted_redirect('/admin/onboarding.php');
        }

        coveted_redirect($returnPath);
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted auth error: ' . $e->getMessage());
        $error = 'Unable to complete that request right now.';
    }
}

$isRegister = $action === 'register';
$switchAction = $isRegister ? 'login' : 'register';

coveted_page_start($isRegister ? 'Join' : 'Sign in');
?>
<section class="cv-auth-shell">
    <div class="cv-auth-copy">
        <span class="cv-eyebrow">COVETED</span>
        <h1><?= $isRegister ? 'Belong somewhere worth showing up for.' : 'Welcome back.' ?></h1>
        <p><?= $isRegister
            ? 'Join the people, places, gatherings, artists and benefits that make real life more interesting.'
            : 'Your invitations, gatherings and member benefits are waiting.' ?></p>
    </div>

    <form class="cv-card cv-auth-card" method="post" action="/auth.php?action=<?= $isRegister ? 'register' : 'login' ?>">
        <input type="hidden" name="csrf_token" value="<?= coveted_e(coveted_csrf_token()) ?>">
        <input type="hidden" name="return" value="<?= coveted_e($returnPath) ?>">

        <h2><?= $isRegister ? 'Create your account' : 'Sign in' ?></h2>

        <?php if ($error !== ''): ?>
            <div class="cv-alert cv-alert-error"><?= coveted_e($error) ?></div>
        <?php endif; ?>

        <?php if ($isRegister): ?>
            <label>
                Name
                <input
                    name="name"
                    autocomplete="name"
                    maxlength="180"
                    required
                    value="<?= coveted_e($_POST['name'] ?? '') ?>"
                >
            </label>
        <?php endif; ?>

        <label>
            Email
            <input
                type="email"
                name="email"
                autocomplete="email"
                maxlength="255"
                required
                value="<?= coveted_e($_POST['email'] ?? '') ?>"
            >
        </label>

        <label>
            Password
            <input
                type="password"
                name="password"
                autocomplete="<?= $isRegister ? 'new-password' : 'current-password' ?>"
                minlength="10"
                maxlength="4096"
                required
            >
        </label>

        <button class="cv-button cv-button-primary cv-button-block" type="submit">
            <?= $isRegister ? 'Join Coveted' : 'Sign in' ?>
        </button>

        <p class="cv-auth-switch">
            <?= $isRegister ? 'Already a member?' : 'New to Coveted?' ?>
            <a href="/auth.php?action=<?= $switchAction ?>&amp;return=<?= rawurlencode($returnPath) ?>">
                <?= $isRegister ? 'Sign in' : 'Create an account' ?>
            </a>
        </p>
    </form>
</section>
<?php coveted_page_end(); ?>
