<?php
declare(strict_types=1);

$root = __DIR__;
if (is_file($root . '/config.php')) {
    header('Location: /auth.php?action=login', true, 302);
    exit;
}

require_once $root . '/app/installer.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
session_name('coveted_setup');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; style-src 'self' 'unsafe-inline'");
header('Cache-Control: no-store, private');

if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}

function setup_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function setup_detect_base_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || preg_match('/[\x00-\x20\x7F]/', $host) === 1) {
        return '';
    }
    return ($https ? 'https' : 'http') . '://' . $host;
}

$error = '';
$complete = false;

$defaults = [
    'site_name' => 'Coveted',
    'base_url' => setup_detect_base_url(),
    'timezone' => 'America/Phoenix',
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'admin_name' => '',
    'admin_email' => '',
];
$values = array_merge($defaults, array_intersect_key($_POST, $defaults));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string)$_SESSION['setup_csrf'], $token)) {
        http_response_code(419);
        exit('Your setup session expired. Refresh and try again.');
    }

    try {
        coveted_installer_run($root, $_POST);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'secure' => (bool)$params['secure'],
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
        $complete = true;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Coveted setup error: ' . $e->getMessage());
        $error = 'Setup could not be completed. Check the server error log for details.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Setup · Coveted</title>
    <style>
        :root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#fff;background:#070707}
        *{box-sizing:border-box} body{margin:0;min-height:100vh;background:radial-gradient(circle at 75% 5%,#202020 0,transparent 32%),#070707;color:#fff}
        a{color:inherit}.shell{width:min(920px,calc(100% - 32px));margin:0 auto;padding:64px 0}.brand{font-size:15px;font-weight:750;letter-spacing:.18em;text-transform:uppercase;margin-bottom:56px}
        .eyebrow{font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:#aaa}.intro{max-width:660px;margin-bottom:32px}.intro h1{font-size:clamp(38px,6vw,72px);line-height:.98;letter-spacing:-.055em;margin:12px 0 18px}.intro p{color:#aaa;line-height:1.6;font-size:16px}
        .card{border:1px solid #292929;background:#0d0d0d;border-radius:22px;padding:28px;box-shadow:0 30px 80px rgba(0,0,0,.35)}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.full{grid-column:1/-1}
        label{display:grid;gap:8px;font-size:13px;color:#bbb}input,select{width:100%;border:1px solid #333;background:#111;color:#fff;border-radius:12px;padding:13px 14px;font:inherit;outline:none}input:focus,select:focus{border-color:#777}
        .section{grid-column:1/-1;border-top:1px solid #242424;margin-top:8px;padding-top:24px}.section:first-child{border-top:0;margin-top:0;padding-top:0}.section h2{font-size:15px;margin:0 0 4px}.section p{font-size:13px;color:#777;margin:0 0 18px}
        button,.button{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:999px;padding:13px 20px;background:#fff;color:#000;font-weight:750;text-decoration:none;cursor:pointer}.actions{display:flex;justify-content:flex-end;margin-top:24px}.alert{border:1px solid #5b2525;background:#1b0d0d;color:#ffd8d8;border-radius:12px;padding:12px 14px;margin-bottom:20px}.success{max-width:620px}.success h1{font-size:48px;letter-spacing:-.04em;margin:12px 0}.success p{color:#aaa;line-height:1.6;margin-bottom:28px}
        @media(max-width:680px){.shell{padding:34px 0}.brand{margin-bottom:36px}.card{padding:20px}.grid{grid-template-columns:1fr}.full,.section{grid-column:1}.intro h1{font-size:44px}}
    </style>
</head>
<body>
<main class="shell">
    <div class="brand">Coveted</div>

    <?php if ($complete): ?>
        <section class="success">
            <span class="eyebrow">SETUP COMPLETE</span>
            <h1>Coveted is ready.</h1>
            <p>Your database is installed, configuration is saved, and your first System Admin account has been created.</p>
            <a class="button" href="/auth.php?action=login">Sign in</a>
        </section>
    <?php else: ?>
        <section class="intro">
            <span class="eyebrow">FIRST INSTALL</span>
            <h1>Set up Coveted.</h1>
            <p>Enter the basic site settings, database connection, and your first admin account. Coveted handles the rest.</p>
        </section>

        <?php if ($error !== ''): ?>
            <div class="alert"><?= setup_e($error) ?></div>
        <?php endif; ?>

        <form class="card" method="post" action="/setup.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= setup_e($_SESSION['setup_csrf']) ?>">
            <div class="grid">
                <div class="section">
                    <h2>Site</h2>
                    <p>Basic Coveted settings.</p>
                </div>
                <label>Site name<input name="site_name" maxlength="100" required value="<?= setup_e($values['site_name']) ?>"></label>
                <label>Timezone<select name="timezone"><option value="America/Phoenix" <?= $values['timezone'] === 'America/Phoenix' ? 'selected' : '' ?>>America/Phoenix</option><option value="America/Los_Angeles" <?= $values['timezone'] === 'America/Los_Angeles' ? 'selected' : '' ?>>America/Los_Angeles</option><option value="America/Denver" <?= $values['timezone'] === 'America/Denver' ? 'selected' : '' ?>>America/Denver</option><option value="America/Chicago" <?= $values['timezone'] === 'America/Chicago' ? 'selected' : '' ?>>America/Chicago</option><option value="America/New_York" <?= $values['timezone'] === 'America/New_York' ? 'selected' : '' ?>>America/New_York</option></select></label>
                <label class="full">Base URL<input type="url" name="base_url" maxlength="500" placeholder="https://coveted.example.com" required value="<?= setup_e($values['base_url']) ?>"></label>

                <div class="section">
                    <h2>Database</h2>
                    <p>Create an empty MySQL 8 database in your hosting panel first.</p>
                </div>
                <label>Database host<input name="db_host" maxlength="255" required value="<?= setup_e($values['db_host']) ?>"></label>
                <label>Port<input name="db_port" inputmode="numeric" maxlength="5" required value="<?= setup_e($values['db_port']) ?>"></label>
                <label>Database name<input name="db_name" maxlength="64" required value="<?= setup_e($values['db_name']) ?>"></label>
                <label>Database username<input name="db_user" maxlength="128" required value="<?= setup_e($values['db_user']) ?>"></label>
                <label class="full">Database password<input type="password" name="db_password" maxlength="4096" autocomplete="new-password"></label>

                <div class="section">
                    <h2>First admin</h2>
                    <p>This becomes the first System Admin account.</p>
                </div>
                <label>Admin name<input name="admin_name" maxlength="180" required value="<?= setup_e($values['admin_name']) ?>"></label>
                <label>Admin email<input type="email" name="admin_email" maxlength="255" autocomplete="email" required value="<?= setup_e($values['admin_email']) ?>"></label>
                <label>Admin password<input type="password" name="admin_password" minlength="10" maxlength="4096" autocomplete="new-password" required></label>
                <label>Confirm password<input type="password" name="admin_password_confirm" minlength="10" maxlength="4096" autocomplete="new-password" required></label>
            </div>
            <div class="actions"><button type="submit">Install Coveted</button></div>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
