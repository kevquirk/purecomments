<?php
declare(strict_types=1);

require __DIR__ . '/includes/url.php';

if (!is_file(__DIR__ . '/config.php')) {
    header('Location: ' . pc_url('/setup.php'), true, 302);
    exit;
}

require __DIR__ . '/includes/session.php';
start_secure_session();

$config = require __DIR__ . '/config.php';
require __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/i18n.php';
pc_set_language((string)($config['language'] ?? 'en'));

if (is_admin_logged_in($config)) {
    header('Location: ' . pc_url('/', $config), true, 302);
    exit;
}

$styleVersion = filemtime(__DIR__ . '/public/style.css');
$error = '';
$setupNotice = '';
$setupWarning = '';

if (($_GET['setup'] ?? '') === 'complete') {
    $setupNotice = t('login.setup_complete');
    if (($_GET['setup_cleanup'] ?? '') === 'failed') {
        $setupWarning = t('login.setup_cleanup_failed');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = t('login.err_required');
    } elseif (attempt_admin_login($config, $username, $password)) {
        header('Location: ' . pc_url('/', $config), true, 302);
        exit;
    } else {
        $retryAfter = login_rate_limit_retry_after($config, $username, get_client_ip());
        if ($retryAfter > 0) {
            $error = t('login.err_rate_limited', ['seconds' => $retryAfter]);
        } else {
            $error = t('login.err_invalid');
        }
    }
}
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars(_pc_lang_code(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars(t('login.title'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(pc_url('/public/style.css', $config) . '?v=' . (string)$styleVersion, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="admin">
    <main class="admin-container">
        <h1><?php echo htmlspecialchars(t('login.heading'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($setupNotice !== '') : ?>
            <p class="notice success"><?php echo htmlspecialchars($setupNotice, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <?php if ($setupWarning !== '') : ?>
            <p class="notice error"><?php echo htmlspecialchars($setupWarning, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <?php if ($error !== '') : ?>
            <p class="notice error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <form method="post" class="admin-reply">
            <label for="username"><?php echo htmlspecialchars(t('login.username'), ENT_QUOTES, 'UTF-8'); ?></label>
            <input id="username" name="username" autocomplete="username" required value="<?php echo htmlspecialchars((string)($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

            <label for="password"><?php echo htmlspecialchars(t('login.password'), ENT_QUOTES, 'UTF-8'); ?></label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button type="submit">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo htmlspecialchars(pc_url('/public/icons/sprite.svg', $config), ENT_QUOTES, 'UTF-8'); ?>#icon-login"></use></svg>
                <span><?php echo htmlspecialchars(t('login.submit'), ENT_QUOTES, 'UTF-8'); ?></span>
            </button>
        </form>
    </main>
</body>
</html>
