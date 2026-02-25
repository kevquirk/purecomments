<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/config.php')) {
    header('Location: /setup.php', true, 302);
    exit;
}

require __DIR__ . '/includes/session.php';
start_secure_session();

$config = require __DIR__ . '/config.php';
require __DIR__ . '/includes/admin_auth.php';

if (is_admin_logged_in($config)) {
    header('Location: /', true, 302);
    exit;
}

$styleVersion = filemtime(__DIR__ . '/public/style.css');
$error = '';
$setupNotice = '';
$setupWarning = '';

if (($_GET['setup'] ?? '') === 'complete') {
    $setupNotice = 'Setup complete. Sign in to start moderating comments.';
    if (($_GET['setup_cleanup'] ?? '') === 'failed') {
        $setupWarning = 'Automatic cleanup failed. Delete or block /setup.php manually.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } elseif (attempt_admin_login($config, $username, $password)) {
        header('Location: /', true, 302);
        exit;
    } else {
        $retryAfter = login_rate_limit_retry_after($config, $username, get_client_ip());
        if ($retryAfter > 0) {
            $error = 'Too many failed attempts. Try again in ' . $retryAfter . ' seconds.';
        } else {
            $error = 'Invalid credentials.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comments Login</title>
    <link rel="stylesheet" href="/public/style.css?v=<?php echo htmlspecialchars((string)$styleVersion, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="admin">
    <main class="admin-container">
        <h1>Comments Admin Login</h1>
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
            <label for="username">Username</label>
            <input id="username" name="username" autocomplete="username" required value="<?php echo htmlspecialchars((string)($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button type="submit">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="/public/icons/sprite.svg#icon-login"></use></svg>
                <span>Sign in</span>
            </button>
        </form>
    </main>
</body>
</html>
