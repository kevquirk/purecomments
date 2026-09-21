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
require __DIR__ . '/includes/config_builder.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/render.php';
require_once __DIR__ . '/includes/totp.php';
pc_set_language((string)($config['language'] ?? 'en'));

maybe_restore_admin_from_cookie($config);

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

$isMfaPending = !empty($_SESSION['mfa_pending']);

if (isset($_GET['cancel_mfa']) && $isMfaPending) {
    unset($_SESSION['mfa_pending'], $_SESSION['mfa_remember_me'], $_SESSION['mfa_username']);
    header('Location: ' . pc_url('/login.php', $config), true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = get_client_ip();

    if ($isMfaPending) {
        $mfaCode = trim((string)($_POST['mfa_code'] ?? ''));
        $mfaSecret = (string)($config['mfa_secret'] ?? '');
        $mfaBackupCodes = (array)($config['mfa_backup_codes'] ?? []);
        $username = (string)($_SESSION['mfa_username'] ?? $config['admin_username'] ?? 'admin');

        $mfaVerified = false;
        $usedBackupIndex = null;

        if ($mfaCode !== '' && $mfaSecret !== '' && totp_verify_code($mfaSecret, $mfaCode)) {
            $mfaVerified = true;
        } elseif ($mfaCode !== '' && !empty($mfaBackupCodes)) {
            $usedBackupIndex = totp_verify_backup_code($mfaCode, $mfaBackupCodes);
            if ($usedBackupIndex !== null) {
                $mfaVerified = true;
                array_splice($mfaBackupCodes, $usedBackupIndex, 1);
                $config['mfa_backup_codes'] = $mfaBackupCodes;

                $configArray = array_merge($config, [
                    'mfa_backup_codes' => $mfaBackupCodes,
                    'sodium_key_hex'   => bin2hex($config['sodium_key']),
                    'date_format'      => $config['date_format'] ?? default_comments_date_format(),
                    'timezone'         => $config['timezone'] ?? default_comments_timezone(),
                    'post_base_url'    => $config['post_base_url'] ?? '',
                    'author_name'      => $config['author']['name'] ?? '',
                    'author_email'     => $config['author']['email'] ?? '',
                    'author_avatar_url'=> $config['author']['avatar_url'] ?? '',
                    'author_bio'       => $config['author']['bio'] ?? '',
                    'aws_region'       => $config['aws']['region'] ?? '',
                    'aws_access_key'   => $config['aws']['access_key'] ?? '',
                    'aws_secret_key'   => $config['aws']['secret_key'] ?? '',
                    'source_email'     => $config['aws']['source_email'] ?? '',
                    'source_name'      => $config['aws']['source_name'] ?? '',
                    'smtp_host'        => $config['smtp']['host'] ?? '',
                    'smtp_port'        => $config['smtp']['port'] ?? 587,
                    'smtp_user'        => $config['smtp']['user'] ?? '',
                    'smtp_pwd'         => $config['smtp']['pwd'] ?? '',
                    'smtp_enc'         => $config['smtp']['enc'] ?? 'tls',
                    'smtp_debug'       => $config['smtp']['debug'] ?? false,
                    'notify_email'     => $config['moderation']['notify_email'] ?? '',
                    'moderation_base_url' => $config['moderation']['base_url'] ?? '',
                    'spam_challenge_question' => $config['spam_challenge']['question'] ?? '',
                    'spam_challenge_answer' => $config['spam_challenge']['answer'] ?? '',
                    'spam_challenge_placeholder' => $config['spam_challenge']['placeholder'] ?? '',
                    'webmentions_enabled' => $config['webmentions']['enabled'] ?? true,
                    'fediverse_profile_url' => $config['webmentions']['fediverse_profile_url'] ?? '',
                    'auto_approve_reactions' => $config['webmentions']['auto_approve_reactions'] ?? true,
                    'auto_approve_replies' => $config['webmentions']['auto_approve_replies'] ?? false,
                ]);
                $configPhp = build_config_php($configArray);
                @file_put_contents(__DIR__ . '/config.php', $configPhp, LOCK_EX);
            }
        }

        if ($mfaVerified) {
            clear_login_failures($config, $username, $ip);
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = (string)($config['admin_username'] ?? $username);
            $rememberMe = !empty($_SESSION['mfa_remember_me']);
            unset($_SESSION['mfa_pending'], $_SESSION['mfa_remember_me'], $_SESSION['mfa_username']);

            if ($rememberMe) {
                set_remember_me_cookie($config);
            }
            header('Location: ' . pc_url('/', $config), true, 302);
            exit;
        }

        register_login_failure($config, $username, $ip);
        $retryAfter = login_rate_limit_retry_after($config, $username, $ip);
        if ($retryAfter > 0) {
            $error = t('login.err_rate_limited', ['seconds' => $retryAfter]);
        } else {
            $error = t('login.mfa_err_invalid');
        }
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            $error = t('login.err_required');
        } elseif (is_login_rate_limited($config, $username, $ip)) {
            $retryAfter = login_rate_limit_retry_after($config, $username, $ip);
            $error = t('login.err_rate_limited', ['seconds' => $retryAfter]);
        } elseif (verify_admin_credentials($config, $username, $password)) {
            $hasMfa = !empty($config['mfa_secret']);
            if ($hasMfa) {
                session_regenerate_id(true);
                $_SESSION['mfa_pending'] = true;
                $_SESSION['mfa_remember_me'] = !empty($_POST['remember_me']);
                $_SESSION['mfa_username'] = $username;
                $isMfaPending = true;
            } else {
                clear_login_failures($config, $username, $ip);
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username'] = $config['admin_username'] ?? $username;
                if (!empty($_POST['remember_me'])) {
                    set_remember_me_cookie($config);
                }
                header('Location: ' . pc_url('/', $config), true, 302);
                exit;
            }
        } else {
            register_login_failure($config, $username, $ip);
            $retryAfter = login_rate_limit_retry_after($config, $username, $ip);
            if ($retryAfter > 0) {
                $error = t('login.err_rate_limited', ['seconds' => $retryAfter]);
            } else {
                $error = t('login.err_invalid');
            }
        }
    }
}

$adminFontStack = font_stack_css($config['admin_font_stack'] ?? 'mono');
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars(_pc_lang_code(), ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars(t('login.title'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(pc_url('/public/favicon.png', $config), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(pc_url('/public/style.css', $config) . '?v=' . (string)$styleVersion, ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        :root {
            --font: <?php echo $adminFontStack; ?>;
            --body-font-size: <?php echo font_size_css((string)($config['admin_font_stack'] ?? 'mono')); ?>;
            --logo-font-size: <?php echo logo_font_size_css((string)($config['admin_font_stack'] ?? 'mono')); ?>;
        }
        <?php if (is_file(__DIR__ . '/data/css/admin-custom.css')): ?>
        <?php readfile(__DIR__ . '/data/css/admin-custom.css'); ?>
        <?php endif; ?>
    </style>
</head>
<body class="admin">
    <main class="admin-container">
        <div class="admin-top-actions">
            <span class="admin-logo"><span class="pure">PURE</span><span class="service">COMMENTS</span></span>
        </div>
        <h1><?php echo htmlspecialchars($isMfaPending ? t('login.mfa_prompt_heading') : t('login.heading'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($setupNotice !== '') : ?>
            <p class="notice success"><?php echo htmlspecialchars($setupNotice, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <?php if ($setupWarning !== '') : ?>
            <p class="notice error"><?php echo htmlspecialchars($setupWarning, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <?php if ($error !== '') : ?>
            <p class="notice error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php if ($isMfaPending): ?>
            <!-- MFA Challenge Form -->
            <p><?php echo htmlspecialchars(t('login.mfa_prompt_desc'), ENT_QUOTES, 'UTF-8'); ?></p>
            <form method="post" class="admin-reply">
                <label for="mfa_code"><?php echo htmlspecialchars(t('login.mfa_code_label'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input id="mfa_code" name="mfa_code" type="text" autofocus inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required>

                <div class="admin-form-buttons">
                    <button type="submit">
                        <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo htmlspecialchars(pc_url('/public/icons/sprite.svg', $config), ENT_QUOTES, 'UTF-8'); ?>#icon-circle-check"></use></svg>
                        <span><?php echo htmlspecialchars(t('login.mfa_verify_btn'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </button>
                    <a class="button danger" href="<?php echo htmlspecialchars(pc_url('/login.php?cancel_mfa=1', $config), ENT_QUOTES, 'UTF-8'); ?>">
                        <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo htmlspecialchars(pc_url('/public/icons/sprite.svg', $config), ENT_QUOTES, 'UTF-8'); ?>#icon-delete"></use></svg>
                        <span><?php echo htmlspecialchars(t('login.mfa_cancel_btn'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                </div>
            </form>
        <?php else: ?>
            <!-- Standard Login Form -->
            <form method="post" class="admin-reply">
                <label for="username"><?php echo htmlspecialchars(t('login.username'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input id="username" name="username" autocomplete="username" required value="<?php echo htmlspecialchars((string)($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                <label for="password"><?php echo htmlspecialchars(t('login.password'), ENT_QUOTES, 'UTF-8'); ?></label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>

                <label class="checkbox-label">
                    <input type="checkbox" name="remember_me" value="1">
                    <?php echo htmlspecialchars(t('login.remember_me'), ENT_QUOTES, 'UTF-8'); ?>
                </label>

                <button type="submit">
                    <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo htmlspecialchars(pc_url('/public/icons/sprite.svg', $config), ENT_QUOTES, 'UTF-8'); ?>#icon-login"></use></svg>
                    <span><?php echo htmlspecialchars(t('login.submit'), ENT_QUOTES, 'UTF-8'); ?></span>
                </button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
