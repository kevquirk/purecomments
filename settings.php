<?php
declare(strict_types=1);

require __DIR__ . '/includes/url.php';

if (!is_file(__DIR__ . '/config.php')) {
    header('Location: ' . pc_url('/setup.php'), true, 302);
    exit;
}

require __DIR__ . '/includes/session.php';
start_secure_session();

$configPath = __DIR__ . '/config.php';
$config = require $configPath;
require __DIR__ . '/includes/admin_auth.php';
require __DIR__ . '/includes/config_builder.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/render.php';
require_once __DIR__ . '/includes/updates_check.php';
require_once __DIR__ . '/includes/totp.php';
require_once __DIR__ . '/includes/qrcode.php';
pc_set_language((string)($config['language'] ?? 'en'));

require_admin_login($config);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$allowedTabs = ['general', 'notifications', 'webmentions', 'customise', 'user'];
$activeTab = (string)($_GET['tab'] ?? $_POST['tab'] ?? 'general');
if ($activeTab === 'security') {
    $activeTab = 'user';
}
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'general';
}

$errors = [];
$messages = [];

if (!empty($_SESSION['flash_message'])) {
    $messages[] = (string)$_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

$newBackupCodes = $_SESSION['new_backup_codes'] ?? null;
unset($_SESSION['new_backup_codes']);

$mfaEnabled = !empty($config['mfa_secret']);
$isSettingUpMfa = false;

if (isset($_GET['cancel_mfa_setup'])) {
    unset($_SESSION['mfa_setup_secret']);
    header('Location: ' . pc_url('/settings.php?tab=user', $config), true, 302);
    exit;
}

if (isset($_GET['setup_mfa']) && !$mfaEnabled) {
    if (empty($_SESSION['mfa_setup_secret'])) {
        $_SESSION['mfa_setup_secret'] = totp_generate_secret(16);
    }
    $isSettingUpMfa = true;
    $activeTab = 'user';
}

$cssDir = __DIR__ . '/data/css';
$adminCssPath = $cssDir . '/admin-custom.css';

$adminCustomCss = is_file($adminCssPath) ? (string)file_get_contents($adminCssPath) : '';

$form = [
    'language'                => (string)($config['language'] ?? default_comments_language()),
    'admin_username'          => (string)($config['admin_username'] ?? ''),
    'timezone'                => (string)($config['timezone'] ?? default_comments_timezone()),
    'date_format'             => (string)($config['date_format'] ?? default_comments_date_format()),
    'admin_font_stack'        => (string)($config['admin_font_stack'] ?? 'mono'),
    'privacy_policy_url'      => (string)($config['privacy_policy_url'] ?? '/privacy#commenting'),
    'spam_challenge_question' => (string)($config['spam_challenge']['question'] ?? ''),
    'spam_challenge_answer'   => (string)($config['spam_challenge']['answer'] ?? ''),
    'spam_challenge_placeholder' => (string)($config['spam_challenge']['placeholder'] ?? ''),
    'post_base_url'           => (string)($config['post_base_url'] ?? ''),
    'author_name'             => (string)($config['author']['name'] ?? ''),
    'author_email'            => (string)($config['author']['email'] ?? ''),
    'author_avatar_url'       => (string)($config['author']['avatar_url'] ?? ''),
    'author_bio'              => (string)($config['author']['bio'] ?? ''),
    'notify_email'            => (string)($config['moderation']['notify_email'] ?? ''),
    'moderation_base_url'     => (string)($config['moderation']['base_url'] ?? ''),
    'aws_region'              => (string)($config['aws']['region'] ?? ''),
    'aws_access_key'          => (string)($config['aws']['access_key'] ?? ''),
    'aws_secret_key'          => (string)($config['aws']['secret_key'] ?? ''),
    'source_email'            => (string)($config['aws']['source_email'] ?? ''),
    'source_name'             => (string)($config['aws']['source_name'] ?? ''),
    'smtp_host'               => (string)($config['smtp']['host'] ?? ''),
    'smtp_port'               => (string)($config['smtp']['port'] ?? '587'),
    'smtp_user'               => (string)($config['smtp']['user'] ?? ''),
    'smtp_pwd'                => (string)($config['smtp']['pwd'] ?? ''),
    'smtp_enc'                => (string)($config['smtp']['enc'] ?? 'tls'),
    'smtp_debug'              => (bool)($config['smtp']['debug'] ?? false),
    'webmentions_enabled'     => (bool)($config['webmentions']['enabled'] ?? true),
    'fediverse_profile_url'   => (string)($config['webmentions']['fediverse_profile_url'] ?? ''),
    'auto_approve_reactions'  => (bool)($config['webmentions']['auto_approve_reactions'] ?? true),
    'auto_approve_replies'    => (bool)($config['webmentions']['auto_approve_replies'] ?? false),
    'mfa_secret'              => (string)($config['mfa_secret'] ?? ''),
    'mfa_backup_codes'        => (array)($config['mfa_backup_codes'] ?? []),
];

$emailProvider = '';
if (!empty($config['smtp']['host'])) {
    $emailProvider = 'smtp';
} elseif (!empty($config['aws']['access_key'])) {
    $emailProvider = 'ses';
}

$smtpDebugLog = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf_token'], $token)) {
        $errors[] = t('settings.err_csrf');
    } elseif (($_POST['action'] ?? '') === 'test_email') {
        $testTo = (string)($config['moderation']['notify_email'] ?? '');
        if ($testTo === '') {
            $errors[] = t('settings.err_no_notify_email');
        } else {
            if (!empty($config['smtp']['host'])) {
                require_once __DIR__ . '/includes/smtpmail.php';
                $ok = smtp_send_email($config, $testTo, t('notifications.test_subject'), t('notifications.test_body'), '', $smtpDebugLog);
            } else {
                require_once __DIR__ . '/includes/ses.php';
                $ok = ses_send_email($config, $testTo, t('notifications.test_subject'), t('notifications.test_body'));
            }
            if ($ok) {
                $messages[] = t('settings.msg_test_sent', ['email' => $testTo]);
            } else {
                $errors[] = t('settings.err_test_email');
            }
        }
    } elseif (isset($_POST['mfa_action'])) {
        $mfaAction = (string)$_POST['mfa_action'];
        $activeTab = 'user';

        if ($mfaAction === 'confirm_setup' && !empty($_SESSION['mfa_setup_secret'])) {
            $secret = (string)$_SESSION['mfa_setup_secret'];
            $code = trim((string)($_POST['mfa_code'] ?? ''));

            if (totp_verify_code($secret, $code)) {
                $plainCodes = totp_generate_backup_codes(8);
                $form['mfa_secret'] = $secret;
                $form['mfa_backup_codes'] = totp_hash_backup_codes($plainCodes);

                $existingSodium = $config['sodium_key'] ?? null;
                if (!is_string($existingSodium) || strlen($existingSodium) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                    $existingSodium = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
                }

                $configArray = array_merge($config, [
                    'mfa_secret'       => $secret,
                    'mfa_backup_codes' => $form['mfa_backup_codes'],
                    'sodium_key_hex'   => bin2hex($existingSodium),
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
                if (@file_put_contents($configPath, $configPhp, LOCK_EX) !== false) {
                    unset($_SESSION['mfa_setup_secret']);
                    $_SESSION['new_backup_codes'] = $plainCodes;
                    $_SESSION['flash_message'] = t('settings.mfa_notice_enabled');
                    header('Location: ' . pc_url('/settings.php?tab=user', $config), true, 302);
                    exit;
                }
                $errors[] = t('settings.err_save_config');
            } else {
                $errors[] = t('settings.mfa_error_verify_code');
                $isSettingUpMfa = true;
            }
        } elseif ($mfaAction === 'disable_mfa' && $mfaEnabled) {
            $password = (string)($_POST['mfa_disable_password'] ?? '');
            if (password_verify($password, (string)($config['admin_password_hash'] ?? ''))) {
                $existingSodium = $config['sodium_key'] ?? null;
                if (!is_string($existingSodium) || strlen($existingSodium) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                    $existingSodium = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
                }

                $configArray = array_merge($config, [
                    'mfa_secret'       => '',
                    'mfa_backup_codes' => [],
                    'sodium_key_hex'   => bin2hex($existingSodium),
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
                if (@file_put_contents($configPath, $configPhp, LOCK_EX) !== false) {
                    $_SESSION['flash_message'] = t('settings.mfa_notice_disabled');
                    header('Location: ' . pc_url('/settings.php?tab=user', $config), true, 302);
                    exit;
                }
                $errors[] = t('settings.err_save_config');
            } else {
                $errors[] = t('settings.mfa_error_password');
            }
        } elseif ($mfaAction === 'regenerate_backup_codes' && $mfaEnabled) {
            $password = (string)($_POST['mfa_regenerate_password'] ?? '');
            if (password_verify($password, (string)($config['admin_password_hash'] ?? ''))) {
                $plainCodes = totp_generate_backup_codes(8);
                $existingSodium = $config['sodium_key'] ?? null;
                if (!is_string($existingSodium) || strlen($existingSodium) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                    $existingSodium = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
                }

                $configArray = array_merge($config, [
                    'mfa_backup_codes' => totp_hash_backup_codes($plainCodes),
                    'sodium_key_hex'   => bin2hex($existingSodium),
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
                if (@file_put_contents($configPath, $configPhp, LOCK_EX) !== false) {
                    $_SESSION['new_backup_codes'] = $plainCodes;
                    $_SESSION['flash_message'] = t('settings.mfa_notice_codes_regenerated');
                    header('Location: ' . pc_url('/settings.php?tab=user', $config), true, 302);
                    exit;
                }
                $errors[] = t('settings.err_save_config');
            } else {
                $errors[] = t('settings.mfa_error_password');
            }
        }
    } else {
        $submittedTab = (string)($_POST['tab'] ?? $activeTab);
        if (in_array($submittedTab, $allowedTabs, true)) {
            $activeTab = $submittedTab;
        }

        if ($activeTab === 'general') {
            $form['language']           = trim((string)($_POST['language'] ?? $form['language']));
            $form['timezone']           = trim((string)($_POST['timezone'] ?? $form['timezone']));
            $form['date_format']        = trim((string)($_POST['date_format'] ?? $form['date_format']));
            $form['privacy_policy_url'] = trim((string)($_POST['privacy_policy_url'] ?? ''));
            $form['post_base_url']      = trim((string)($_POST['post_base_url'] ?? ''));
            $form['moderation_base_url']= trim((string)($_POST['moderation_base_url'] ?? ''));

            if (!is_valid_timezone_id($form['timezone'])) {
                $errors[] = t('settings.err_timezone');
            }
            if ($form['date_format'] === '') {
                $errors[] = t('settings.err_date_format');
            }
            if ($form['post_base_url'] === '' || !filter_var($form['post_base_url'], FILTER_VALIDATE_URL)) {
                $errors[] = t('settings.err_post_base_url');
            }
            if ($form['moderation_base_url'] === '' || !filter_var($form['moderation_base_url'], FILTER_VALIDATE_URL)) {
                $errors[] = t('settings.err_service_url');
            }
        } elseif ($activeTab === 'notifications') {
            $form['author_name']        = trim((string)($_POST['author_name'] ?? ''));
            $form['author_email']       = trim((string)($_POST['author_email'] ?? ''));
            $form['author_avatar_url']  = trim((string)($_POST['author_avatar_url'] ?? ''));
            $form['author_bio']         = trim((string)($_POST['author_bio'] ?? ''));
            $form['notify_email']       = trim((string)($_POST['notify_email'] ?? ''));
            $emailProvider              = trim((string)($_POST['email_provider'] ?? ''));

            if ($emailProvider === 'ses') {
                $form['aws_region']     = trim((string)($_POST['aws_region'] ?? ''));
                $form['aws_access_key'] = trim((string)($_POST['aws_access_key'] ?? ''));
                $form['aws_secret_key'] = trim((string)($_POST['aws_secret_key'] ?? ''));
                $form['source_email']   = trim((string)($_POST['source_email'] ?? ''));
                $form['source_name']    = trim((string)($_POST['source_name'] ?? ''));
                $form['smtp_host']      = '';
                $form['smtp_port']      = '587';
                $form['smtp_user']      = '';
                $form['smtp_pwd']       = '';
                $form['smtp_enc']       = 'tls';
                $form['smtp_debug']     = false;
            } elseif ($emailProvider === 'smtp') {
                $form['smtp_host']      = trim((string)($_POST['smtp_host'] ?? ''));
                $form['smtp_port']      = trim((string)($_POST['smtp_port'] ?? '587'));
                $form['smtp_user']      = trim((string)($_POST['smtp_user'] ?? ''));
                $form['smtp_pwd']       = trim((string)($_POST['smtp_pwd'] ?? ''));
                $form['smtp_enc']       = trim((string)($_POST['smtp_enc'] ?? 'tls'));
                $form['smtp_debug']     = isset($_POST['smtp_debug']);
                $form['aws_region']     = '';
                $form['aws_access_key'] = '';
                $form['aws_secret_key'] = '';
                $form['source_email']   = '';
                $form['source_name']    = '';
            } else {
                $form['aws_region']     = '';
                $form['aws_access_key'] = '';
                $form['aws_secret_key'] = '';
                $form['source_email']   = '';
                $form['source_name']    = '';
                $form['smtp_host']      = '';
                $form['smtp_port']      = '587';
                $form['smtp_user']      = '';
                $form['smtp_pwd']       = '';
                $form['smtp_enc']       = 'tls';
                $form['smtp_debug']     = false;
            }

            if ($form['author_name'] === '') {
                $errors[] = t('settings.err_author_name');
            }
            if (!filter_var($form['author_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = t('settings.err_author_email');
            }
            if ($form['notify_email'] !== '' && !filter_var($form['notify_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = t('settings.err_notify_email');
            }
            if ($emailProvider === 'ses' && $form['source_email'] !== '' && !filter_var($form['source_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = t('settings.err_ses_source_email');
            }
            if ($emailProvider === 'smtp') {
                if ($form['smtp_host'] === '') {
                    $errors[] = t('settings.err_smtp_host');
                }
                if ($form['smtp_port'] === '' || !ctype_digit($form['smtp_port'])) {
                    $errors[] = t('settings.err_smtp_port');
                }
                if (!in_array($form['smtp_enc'], ['tls', 'ssl', ''], true)) {
                    $errors[] = t('settings.err_smtp_enc');
                }
            }
        } elseif ($activeTab === 'webmentions') {
            $form['spam_challenge_question']    = trim((string)($_POST['spam_challenge_question'] ?? ''));
            $form['spam_challenge_answer']      = trim((string)($_POST['spam_challenge_answer'] ?? ''));
            $form['spam_challenge_placeholder'] = trim((string)($_POST['spam_challenge_placeholder'] ?? ''));
            $form['webmentions_enabled']        = isset($_POST['webmentions_enabled']);
            $form['fediverse_profile_url']      = trim((string)($_POST['fediverse_profile_url'] ?? ''));
            $form['auto_approve_reactions']     = isset($_POST['auto_approve_reactions']);
            $form['auto_approve_replies']       = isset($_POST['auto_approve_replies']);

            if ($form['spam_challenge_question'] === '') {
                $errors[] = t('settings.err_challenge_question');
            }
            if ($form['spam_challenge_answer'] === '') {
                $errors[] = t('settings.err_challenge_answer');
            }
        } elseif ($activeTab === 'customise') {
            $adminFontChoice = (string)($_POST['admin_font_stack'] ?? 'mono');
            if (in_array($adminFontChoice, ['sans', 'serif', 'mono'], true)) {
                $form['admin_font_stack'] = $adminFontChoice;
            }

            $adminCustomCss = (string)($_POST['admin_css'] ?? '');

            if (!is_dir($cssDir) && !@mkdir($cssDir, 0755, true) && !is_dir($cssDir)) {
                $errors[] = t('settings.err_save_config');
            } else {
                if (@file_put_contents($adminCssPath, $adminCustomCss) === false) {
                    $errors[] = t('settings.err_save_config');
                }
            }
        } elseif ($activeTab === 'user') {
            $form['admin_username'] = trim((string)($_POST['admin_username'] ?? ''));
            $adminPassword          = (string)($_POST['admin_password'] ?? '');
            $adminPasswordConfirm   = (string)($_POST['admin_password_confirm'] ?? '');

            if ($form['admin_username'] === '') {
                $errors[] = t('settings.err_username');
            }
            if ($adminPassword !== '' || $adminPasswordConfirm !== '') {
                if ($adminPassword === '') {
                    $errors[] = t('settings.err_password_empty');
                } elseif (strlen($adminPassword) < 10) {
                    $errors[] = t('settings.err_password_length');
                }
                if (!hash_equals($adminPassword, $adminPasswordConfirm)) {
                    $errors[] = t('settings.err_passwords_match');
                }
            }
        }

        if (empty($errors)) {
            $passwordHash = (string)($config['admin_password_hash'] ?? '');
            if ($activeTab === 'user' && !empty($adminPassword)) {
                $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            }

            $existingSodium = $config['sodium_key'] ?? null;
            if (!is_string($existingSodium) || strlen($existingSodium) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                $existingSodium = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            }

            $configPhp = build_config_php([
                'language'                => $form['language'],
                'admin_username'          => $form['admin_username'],
                'admin_password_hash'     => $passwordHash,
                'sodium_key_hex'          => bin2hex($existingSodium),
                'timezone'                => normalize_comments_timezone($form['timezone']),
                'date_format'             => normalize_comments_date_format($form['date_format']),
                'admin_font_stack'        => $form['admin_font_stack'],
                'mfa_secret'              => $form['mfa_secret'],
                'mfa_backup_codes'        => $form['mfa_backup_codes'],
                'privacy_policy_url'      => $form['privacy_policy_url'],
                'post_titles'             => is_array($config['post_titles'] ?? null) ? $config['post_titles'] : [],
                'spam_challenge_question' => $form['spam_challenge_question'],
                'spam_challenge_answer'   => $form['spam_challenge_answer'],
                'spam_challenge_placeholder' => $form['spam_challenge_placeholder'],
                'post_base_url'           => rtrim($form['post_base_url'], '/'),
                'author_name'             => $form['author_name'],
                'author_email'            => $form['author_email'],
                'author_avatar_url'       => $form['author_avatar_url'],
                'author_bio'              => $form['author_bio'],
                'aws_region'              => $form['aws_region'],
                'aws_access_key'          => $form['aws_access_key'],
                'aws_secret_key'          => $form['aws_secret_key'],
                'source_email'            => $form['source_email'],
                'source_name'             => $form['source_name'],
                'smtp_host'               => $form['smtp_host'],
                'smtp_port'               => $form['smtp_port'],
                'smtp_user'               => $form['smtp_user'],
                'smtp_pwd'                => $form['smtp_pwd'],
                'smtp_enc'                => $form['smtp_enc'],
                'smtp_debug'              => $form['smtp_debug'],
                'notify_email'            => $form['notify_email'],
                'moderation_base_url'     => rtrim($form['moderation_base_url'], '/') . '/',
                'webmentions_enabled'     => $form['webmentions_enabled'],
                'fediverse_profile_url'   => $form['fediverse_profile_url'],
                'auto_approve_reactions'  => $form['auto_approve_reactions'],
                'auto_approve_replies'    => $form['auto_approve_replies'],
            ]);

            if (@file_put_contents($configPath, $configPhp, LOCK_EX) === false) {
                $errors[] = t('settings.err_save_config');
            } else {
                $_SESSION['admin_username'] = (string)$form['admin_username'];
                $_SESSION['flash_message'] = t('settings.msg_saved');
                header('Location: ' . pc_url('/settings.php?tab=' . urlencode($activeTab), $config), true, 302);
                exit;
            }
        }
    }
}

$mfaQrSvg = '';
$mfaProvisioningUri = '';
$mfaSecret = '';
if ($isSettingUpMfa && !empty($_SESSION['mfa_setup_secret'])) {
    $mfaSecret = (string)$_SESSION['mfa_setup_secret'];
    $siteTitle = 'Pure Comments';
    $accountName = $config['admin_username'] ?? 'admin';
    $mfaProvisioningUri = totp_get_provisioning_uri($mfaSecret, $accountName, $siteTitle);
    $mfaQrSvg = qrcode_svg($mfaProvisioningUri, 200);
}

$adminFontStack = font_stack_css($form['admin_font_stack'] ?? 'mono');
$styleVersion = filemtime(__DIR__ . '/public/style.css');
?>
<!doctype html>
<html lang="<?php echo h(_pc_lang_code()); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h(t('settings.title')); ?></title>
    <link rel="icon" type="image/png" href="<?php echo h(pc_url('/public/favicon.png', $config)); ?>">
    <link rel="stylesheet" href="<?php echo h(pc_url('/public/style.css', $config)); ?>?v=<?php echo h((string)$styleVersion); ?>">
    <style>
        :root {
            --font: <?php echo $adminFontStack; ?>;
            --body-font-size: <?php echo font_size_css((string)($form['admin_font_stack'] ?? 'mono')); ?>;
            --logo-font-size: <?php echo logo_font_size_css((string)($form['admin_font_stack'] ?? 'mono')); ?>;
        }
        <?php if (is_file($adminCssPath)): ?>
        <?php readfile($adminCssPath); ?>
        <?php endif; ?>
    </style>
</head>
<body class="admin">
    <main class="admin-container">
        <div class="admin-top-actions">
            <span class="admin-logo"><span class="pure">PURE</span><span class="service">COMMENTS</span></span>
            <a class="button" href="<?php echo h(pc_url('/', $config)); ?>">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-back"></use></svg>
                <span><?php echo h(t('settings.back_btn')); ?></span>
            </a>
            <a class="button danger" href="<?php echo h(pc_url('/logout.php', $config)); ?>">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-logout"></use></svg>
                <span><?php echo h(t('settings.logout_btn')); ?></span>
            </a>
        </div>

        <div class="settings-container">
            <?php
            $updateInfo = check_cached_updates();
            if (!empty($updateInfo['update_available']) && $activeTab !== 'updates'):
            ?>
                <p class="notice success admin-update-banner">
                    <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-upgrade"></use></svg>
                    <span><?php echo t('dashboard.update_banner', ['latest' => e($updateInfo['latest_version']), 'url' => pc_url('/updates.php', $config)]); ?></span>
                </p>
            <?php endif; ?>

            <h1><?php echo h(t('settings.heading')); ?></h1>

            <?php require __DIR__ . '/includes/admin_settings_nav.php'; ?>

            <?php foreach ($messages as $message) : ?>
                <p class="notice success"><?php echo h($message); ?></p>
            <?php endforeach; ?>

            <?php if (!empty($errors)) : ?>
                <div class="notice error">
                    <ul>
                        <?php foreach ($errors as $error) : ?>
                            <li><?php echo h($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($activeTab === 'general'): ?>
                <!-- General Settings Tab -->
                <form method="post" action="<?php echo h(pc_url('/settings.php?tab=general', $config)); ?>" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?php echo h((string)$_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="tab" value="general">

                    <h2><?php echo h(t('settings.section_site')); ?></h2>

                    <?php $availableLangs = pc_available_languages(); ?>
                    <?php if (count($availableLangs) > 1) : ?>
                        <label for="language"><?php echo h(t('settings.field_language')); ?></label>
                        <select id="language" name="language">
                            <?php foreach ($availableLangs as $langCode => $langName) : ?>
                                <option value="<?php echo h($langCode); ?>" <?php echo $form['language'] === $langCode ? 'selected' : ''; ?>><?php echo h($langName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else : ?>
                        <input type="hidden" name="language" value="<?php echo h($form['language']); ?>">
                    <?php endif; ?>

                    <label for="post_base_url"><?php echo h(t('settings.field_post_base_url')); ?></label>
                    <input id="post_base_url" name="post_base_url" required placeholder="https://example.com/blog" value="<?php echo h($form['post_base_url']); ?>">

                    <label for="moderation_base_url"><?php echo h(t('settings.field_service_url')); ?></label>
                    <input id="moderation_base_url" name="moderation_base_url" required placeholder="https://comments.example.com" value="<?php echo h($form['moderation_base_url']); ?>">

                    <label for="timezone">
                        <?php echo h(t('settings.field_timezone')); ?>
                        <small>(<a href="<?php echo h(t('settings.link_timezone_list_url')); ?>" target="_blank" rel="noopener noreferrer"><?php echo h(t('settings.link_timezone_list')); ?></a>)</small>
                    </label>
                    <input id="timezone" name="timezone" required placeholder="UTC" value="<?php echo h($form['timezone']); ?>">

                    <label for="date_format">
                        <?php echo h(t('settings.field_date_format')); ?>
                        <small>(<a href="<?php echo h(t('settings.link_date_format_docs_url')); ?>" target="_blank" rel="noopener noreferrer"><?php echo h(t('settings.link_date_format_docs')); ?></a>)</small>
                    </label>
                    <input id="date_format" name="date_format" required placeholder="Y-m-d H:i" value="<?php echo h($form['date_format']); ?>">

                    <label for="privacy_policy_url"><?php echo h(t('settings.field_privacy_url')); ?></label>
                    <input id="privacy_policy_url" name="privacy_policy_url" placeholder="/privacy#commenting" value="<?php echo h($form['privacy_policy_url']); ?>">

                    <div class="admin-form-buttons">
                        <button type="submit">
                            <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-save"></use></svg>
                            <span><?php echo h(t('settings.save_btn')); ?></span>
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($activeTab === 'notifications'): ?>
                <!-- Author & Email Notifications Tab -->
                <?php if (!empty($smtpDebugLog)) : ?>
                    <h2><?php echo h(t('settings.smtp_debug_heading')); ?></h2>
                    <pre class="smtp-debug-log"><?php echo h($smtpDebugLog); ?></pre>
                <?php endif; ?>

                <form method="post" action="<?php echo h(pc_url('/settings.php?tab=notifications', $config)); ?>" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?php echo h((string)$_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="tab" value="notifications">

                    <h2><?php echo h(t('settings.section_author')); ?></h2>
                    <label for="author_name"><?php echo h(t('settings.field_author_name')); ?></label>
                    <input id="author_name" name="author_name" required value="<?php echo h($form['author_name']); ?>">

                    <label for="author_email"><?php echo h(t('settings.field_author_email')); ?></label>
                    <input id="author_email" name="author_email" type="email" required value="<?php echo h($form['author_email']); ?>">

                    <label for="author_avatar_url"><?php echo h(t('settings.field_author_avatar_url')); ?></label>
                    <input id="author_avatar_url" name="author_avatar_url" placeholder="https://example.com/avatar.jpg" value="<?php echo h($form['author_avatar_url']); ?>">

                    <label for="author_bio"><?php echo h(t('settings.field_author_bio')); ?></label>
                    <input id="author_bio" name="author_bio" placeholder="Blogger & developer." value="<?php echo h($form['author_bio']); ?>">

                    <h2><?php echo h(t('settings.section_email')); ?></h2>
                    <label for="notify_email"><?php echo h(t('settings.field_notify_email')); ?></label>
                    <input id="notify_email" name="notify_email" type="email" value="<?php echo h($form['notify_email']); ?>">

                    <label for="email_provider"><?php echo h(t('settings.field_email_provider')); ?></label>
                    <select id="email_provider" name="email_provider">
                        <option value="" <?php echo $emailProvider === '' ? 'selected' : ''; ?>><?php echo h(t('settings.email_none')); ?></option>
                        <option value="ses" <?php echo $emailProvider === 'ses' ? 'selected' : ''; ?>><?php echo h(t('settings.email_ses')); ?></option>
                        <option value="smtp" <?php echo $emailProvider === 'smtp' ? 'selected' : ''; ?>><?php echo h(t('settings.email_smtp')); ?></option>
                    </select>

                    <div id="ses-settings" class="admin-form-section" hidden>
                        <label for="aws_region"><?php echo h(t('settings.field_aws_region')); ?></label>
                        <input id="aws_region" name="aws_region" placeholder="eu-west-1" value="<?php echo h($form['aws_region']); ?>">

                        <label for="aws_access_key"><?php echo h(t('settings.field_aws_access_key')); ?></label>
                        <input id="aws_access_key" name="aws_access_key" value="<?php echo h($form['aws_access_key']); ?>">

                        <label for="aws_secret_key"><?php echo h(t('settings.field_aws_secret_key')); ?></label>
                        <input id="aws_secret_key" name="aws_secret_key" value="<?php echo h($form['aws_secret_key']); ?>">

                        <label for="source_email"><?php echo h(t('settings.field_source_email')); ?></label>
                        <input id="source_email" name="source_email" type="email" value="<?php echo h($form['source_email']); ?>">

                        <label for="source_name"><?php echo h(t('settings.field_source_name')); ?></label>
                        <input id="source_name" name="source_name" value="<?php echo h($form['source_name']); ?>">
                    </div>

                    <div id="smtp-settings" class="admin-form-section" hidden>
                        <label for="smtp_host"><?php echo h(t('settings.field_smtp_host')); ?></label>
                        <input id="smtp_host" name="smtp_host" placeholder="smtp.example.com" value="<?php echo h($form['smtp_host']); ?>">

                        <label for="smtp_port"><?php echo h(t('settings.field_smtp_port')); ?></label>
                        <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" placeholder="587" value="<?php echo h($form['smtp_port']); ?>">

                        <label for="smtp_enc"><?php echo h(t('settings.field_smtp_enc')); ?></label>
                        <select id="smtp_enc" name="smtp_enc">
                            <option value="tls" <?php echo $form['smtp_enc'] === 'tls' ? 'selected' : ''; ?>><?php echo h(t('settings.smtp_enc_tls')); ?></option>
                            <option value="ssl" <?php echo $form['smtp_enc'] === 'ssl' ? 'selected' : ''; ?>><?php echo h(t('settings.smtp_enc_ssl')); ?></option>
                            <option value="" <?php echo $form['smtp_enc'] === '' ? 'selected' : ''; ?>><?php echo h(t('settings.smtp_enc_none')); ?></option>
                        </select>

                        <label for="smtp_user"><?php echo h(t('settings.field_smtp_user')); ?></label>
                        <input id="smtp_user" name="smtp_user" autocomplete="off" value="<?php echo h($form['smtp_user']); ?>">

                        <label for="smtp_pwd"><?php echo h(t('settings.field_smtp_pwd')); ?></label>
                        <input id="smtp_pwd" name="smtp_pwd" type="password" autocomplete="new-password" value="<?php echo h($form['smtp_pwd']); ?>">

                        <label class="inline-checkbox checkbox-label" for="smtp_debug">
                            <input id="smtp_debug" name="smtp_debug" type="checkbox" value="1" <?php echo $form['smtp_debug'] ? 'checked' : ''; ?>>
                            <span><?php echo h(t('settings.field_smtp_debug')); ?></span>
                        </label>
                    </div>

                    <div class="admin-form-buttons">
                        <button type="submit">
                            <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-save"></use></svg>
                            <span><?php echo h(t('settings.save_btn')); ?></span>
                        </button>
                        <?php if ($emailProvider !== '') : ?>
                        <button type="submit" name="action" value="test_email" class="danger">
                            <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-mail"></use></svg>
                            <span><?php echo h(t('settings.test_email_btn')); ?></span>
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($activeTab === 'webmentions'): ?>
                <!-- Spam & Webmentions Tab -->
                <form method="post" action="<?php echo h(pc_url('/settings.php?tab=webmentions', $config)); ?>" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?php echo h((string)$_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="tab" value="webmentions">

                    <h2><?php echo h(t('settings.section_spam')); ?></h2>
                    <label for="spam_challenge_question"><?php echo h(t('settings.field_challenge_question')); ?></label>
                    <input id="spam_challenge_question" name="spam_challenge_question" required value="<?php echo h($form['spam_challenge_question']); ?>">

                    <label for="spam_challenge_answer"><?php echo h(t('settings.field_challenge_answer')); ?></label>
                    <input id="spam_challenge_answer" name="spam_challenge_answer" required value="<?php echo h($form['spam_challenge_answer']); ?>">

                    <label for="spam_challenge_placeholder"><?php echo h(t('settings.field_challenge_ph')); ?></label>
                    <input id="spam_challenge_placeholder" name="spam_challenge_placeholder" value="<?php echo h($form['spam_challenge_placeholder']); ?>">

                    <h2><?php echo h(t('settings.section_webmentions')); ?></h2>

                    <p class="notice error">Before enabling Webmentions, please ensure you have <a target="_blank" href="https://docs.purecomments.org/webmentions-and-fediverse/">read the docs</a> first.</p>
                    <label class="inline-checkbox checkbox-label" for="webmentions_enabled">
                        <input id="webmentions_enabled" name="webmentions_enabled" type="checkbox" value="1" <?php echo $form['webmentions_enabled'] ? 'checked' : ''; ?>>
                        <span><?php echo h(t('settings.field_webmentions_enabled')); ?></span>
                    </label>

                    <?php
                        $webmentionUrl = rtrim($form['moderation_base_url'], '/') . '/api/webmention';
                        $fediverseProfileUrl = trim($form['fediverse_profile_url']);

                        $snippet = "<!-- Webmention & Fediverse Discovery -->\n"
                            . "<link rel=\"webmention\" href=\"{$webmentionUrl}\">";
                        if ($fediverseProfileUrl !== '') {
                            $snippet .= "\n<link rel=\"me\" href=\"{$fediverseProfileUrl}\">";
                        }
                    ?>

                    <div id="webmentions-settings" class="admin-form-section">
                        <label><?php echo h(t('settings.field_webmention_endpoint_tag')); ?></label>
                        <pre class="webmention-code-block"><code><?php echo h($snippet); ?></code></pre>

                        <label for="fediverse_profile_url">
                            <?php echo h(t('settings.field_fediverse_profile_url')); ?>
                            <small><?php echo t('settings.bridgy_connect_help'); ?></small>
                        </label>
                        <input id="fediverse_profile_url" name="fediverse_profile_url" placeholder="https://mastodon.social/@username" value="<?php echo h($form['fediverse_profile_url']); ?>">

                        <label class="inline-checkbox checkbox-label" for="auto_approve_reactions">
                            <input id="auto_approve_reactions" name="auto_approve_reactions" type="checkbox" value="1" <?php echo $form['auto_approve_reactions'] ? 'checked' : ''; ?>>
                            <span><?php echo h(t('settings.field_auto_approve_reactions')); ?></span>
                        </label>

                        <label class="inline-checkbox checkbox-label" for="auto_approve_replies">
                            <input id="auto_approve_replies" name="auto_approve_replies" type="checkbox" value="1" <?php echo $form['auto_approve_replies'] ? 'checked' : ''; ?>>
                            <span><?php echo h(t('settings.field_auto_approve_replies')); ?></span>
                        </label>
                    </div>

                    <div class="admin-form-buttons">
                        <button type="submit">
                            <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-save"></use></svg>
                            <span><?php echo h(t('settings.save_btn')); ?></span>
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($activeTab === 'customise'): ?>
                <!-- Customisation (Font & CSS) Tab -->
                <form method="post" action="<?php echo h(pc_url('/settings.php?tab=customise', $config)); ?>" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?php echo h((string)$_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="tab" value="customise">

                    <h2><?php echo h(t('settings.section_font')); ?></h2>

                    <div class="font-preview-card">
                        <label class="font-preview-option font-preview-mono" for="admin_font_stack_mono">
                            <input type="radio" id="admin_font_stack_mono" name="admin_font_stack" value="mono" <?php echo $form['admin_font_stack'] === 'mono' ? 'checked' : ''; ?>>
                            <span><?php echo h(t('settings.font_mono')); ?></span>
                        </label>
                        <label class="font-preview-option font-preview-sans" for="admin_font_stack_sans">
                            <input type="radio" id="admin_font_stack_sans" name="admin_font_stack" value="sans" <?php echo $form['admin_font_stack'] === 'sans' ? 'checked' : ''; ?>>
                            <span><?php echo h(t('settings.font_sans')); ?></span>
                        </label>
                        <label class="font-preview-option font-preview-serif" for="admin_font_stack_serif">
                            <input type="radio" id="admin_font_stack_serif" name="admin_font_stack" value="serif" <?php echo $form['admin_font_stack'] === 'serif' ? 'checked' : ''; ?>>
                            <span><?php echo h(t('settings.font_serif')); ?></span>
                        </label>
                    </div>

                    <h2><?php echo h(t('settings.section_custom_css')); ?></h2>

                    <label for="admin_css"><?php echo h(t('settings.field_admin_custom_css')); ?></label>
                    <textarea id="admin_css" name="admin_css" class="editor-textarea-code" rows="12" spellcheck="false" placeholder="<?php echo h(t('settings.placeholder_admin_custom_css')); ?>"><?php echo h($adminCustomCss); ?></textarea>

                    <div class="admin-form-buttons">
                        <button type="submit">
                            <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-save"></use></svg>
                            <span><?php echo h(t('settings.save_btn')); ?></span>
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($activeTab === 'user'): ?>
                <!-- User & Security Tab -->
                <?php if (!empty($newBackupCodes)): ?>
                    <div class="notice" style="margin-bottom: 1.5rem;">
                        <strong><?php echo h(t('settings.mfa_backup_codes_heading')); ?></strong>
                        <p><?php echo h(t('settings.mfa_backup_codes_warning')); ?></p>
                        <pre style="background: var(--input-bg); padding: 1rem; font-size: 1.1rem; line-height: 1.8; user-select: all; border: var(--standard-border); margin: 0.75rem 0;"><code><?php echo h(implode("\n", $newBackupCodes)); ?></code></pre>
                        <button type="button" class="button" onclick="navigator.clipboard.writeText(<?php echo h(json_encode(implode("\n", $newBackupCodes))); ?>).then(()=>{this.innerHTML='<svg class=\'button-icon\' aria-hidden=\'true\'><use href=\'<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-circle-check\'></use></svg> <span><?php echo h(addslashes(t('settings.mfa_copied'))); ?></span>'; setTimeout(()=>{this.innerHTML='<svg class=\'button-icon\' aria-hidden=\'true\'><use href=\'<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-copy\'></use></svg> <span><?php echo h(addslashes(t('settings.mfa_copy_codes'))); ?></span>';}, 2500);})">
                            <svg class="button-icon" aria-hidden="true"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-copy"></use></svg>
                            <span><?php echo h(t('settings.mfa_copy_codes')); ?></span>
                        </button>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo h(pc_url('/settings.php?tab=user', $config)); ?>" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?php echo h((string)$_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="tab" value="user">

                    <h2><?php echo h(t('settings.section_admin')); ?></h2>
                    <label for="admin_username"><?php echo h(t('settings.field_username')); ?></label>
                    <input id="admin_username" name="admin_username" required value="<?php echo h($form['admin_username']); ?>">

                    <label for="admin_password"><?php echo h(t('settings.field_password')); ?></label>
                    <input id="admin_password" name="admin_password" type="password" minlength="10" autocomplete="new-password">

                    <label for="admin_password_confirm"><?php echo h(t('settings.field_password_confirm')); ?></label>
                    <input id="admin_password_confirm" name="admin_password_confirm" type="password" minlength="10" autocomplete="new-password">

                    <div class="admin-form-buttons">
                        <button type="submit">
                            <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-save"></use></svg>
                            <span><?php echo h(t('settings.save_btn')); ?></span>
                        </button>
                    </div>
                </form>

                <section class="admin-section" style="max-width: 45rem; padding-top: 1rem;">
                    <h2><?php echo h(t('settings.section_mfa')); ?></h2>

                    <?php if ($mfaEnabled): ?>
                        <p>
                            <span style="display: inline-flex; align-items: center; gap: 0.35rem; color: var(--green); font-weight: bold;">
                                <svg class="button-icon" aria-hidden="true"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-circle-check"></use></svg>
                                <?php echo h(t('settings.mfa_status_enabled')); ?>
                            </span>
                        </p>
                        <p><?php echo h(t('settings.mfa_enabled_desc')); ?></p>

                        <details style="margin: 1.25rem 0;">
                            <summary style="cursor: pointer; font-weight: bold;"><?php echo h(t('settings.mfa_regenerate_codes_title')); ?></summary>
                            <form method="post" action="<?php echo h(pc_url('/settings.php?tab=user', $config)); ?>" class="admin-form" style="margin-top: 1rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo h((string)$_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="mfa_action" value="regenerate_backup_codes">
                                <label for="mfa_regenerate_password"><?php echo h(t('settings.mfa_confirm_password_label')); ?></label>
                                <input type="password" id="mfa_regenerate_password" name="mfa_regenerate_password" required autocomplete="current-password">
                                <button type="submit">
                                    <svg class="button-icon" aria-hidden="true"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-save"></use></svg>
                                    <span><?php echo h(t('settings.mfa_regenerate_codes_btn')); ?></span>
                                </button>
                            </form>
                        </details>

                        <details style="margin-bottom: 1.25rem;">
                            <summary style="cursor: pointer; font-weight: bold; color: var(--red);"><?php echo h(t('settings.mfa_disable_title')); ?></summary>
                            <form method="post" action="<?php echo h(pc_url('/settings.php?tab=user', $config)); ?>" class="admin-form" style="margin-top: 1rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo h((string)$_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="mfa_action" value="disable_mfa">
                                <label for="mfa_disable_password"><?php echo h(t('settings.mfa_confirm_password_label')); ?></label>
                                <input type="password" id="mfa_disable_password" name="mfa_disable_password" required autocomplete="current-password">
                                <button type="submit" class="danger">
                                    <svg class="button-icon" aria-hidden="true"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-delete"></use></svg>
                                    <span><?php echo h(t('settings.mfa_disable_btn')); ?></span>
                                </button>
                            </form>
                        </details>
                    <?php elseif ($isSettingUpMfa): ?>
                        <p><?php echo h(t('settings.mfa_setup_instructions')); ?></p>

                        <div style="display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: flex-start; margin: 1.5rem 0;">
                            <div style="background: #ffffff; padding: 0.75rem; border: var(--standard-border); display: inline-block;">
                                <?php echo $mfaQrSvg; ?>
                            </div>
                            <div style="flex: 1; min-width: 250px;">
                                <p style="margin-top: 0;"><?php echo h(t('settings.mfa_manual_entry_intro')); ?></p>
                                <div style="display: flex; align-items: center; gap: 0.5rem; margin: 0.5rem 0 1.25rem 0;">
                                    <code style="font-size: 1.15rem; font-weight: bold; padding: 0.35rem 0.6rem; letter-spacing: 0.05em; user-select: all; background: var(--input-bg); border: var(--standard-border);"><?php echo h($mfaSecret); ?></code>
                                    <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo h($mfaSecret); ?>').then(()=>{this.innerHTML='<svg class=\'button-icon\' aria-hidden=\'true\'><use href=\'<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-circle-check\'></use></svg> <span><?php echo h(addslashes(t('settings.mfa_copied'))); ?></span>'; setTimeout(()=>{this.innerHTML='<svg class=\'button-icon\' aria-hidden=\'true\'><use href=\'<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-copy\'></use></svg> <span><?php echo h(addslashes(t('settings.mfa_copy'))); ?></span>';}, 2000);})">
                                        <svg class="button-icon" aria-hidden="true"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-copy"></use></svg>
                                        <span><?php echo h(t('settings.mfa_copy')); ?></span>
                                    </button>
                                </div>

                                <form method="post" action="<?php echo h(pc_url('/settings.php?tab=user', $config)); ?>" class="admin-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo h((string)$_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="mfa_action" value="confirm_setup">
                                    <label for="mfa_code"><?php echo h(t('settings.mfa_enter_code_label')); ?></label>
                                    <input type="text" id="mfa_code" name="mfa_code" autofocus inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required>
                                    <div class="admin-form-buttons">
                                        <button type="submit">
                                            <svg class="button-icon" aria-hidden="true"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-circle-check"></use></svg>
                                            <span><?php echo h(t('settings.mfa_activate_btn')); ?></span>
                                        </button>
                                        <a href="<?php echo h(pc_url('/settings.php?tab=user&cancel_mfa_setup=1', $config)); ?>" class="button danger">
                                            <svg class="button-icon" aria-hidden="true"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-delete"></use></svg>
                                            <span><?php echo h(t('settings.mfa_cancel_btn')); ?></span>
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <p><?php echo h(t('settings.mfa_disabled_desc')); ?></p>
                        <a href="<?php echo h(pc_url('/settings.php?tab=user&setup_mfa=1', $config)); ?>" class="button">
                            <svg class="button-icon" aria-hidden="true"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-shield"></use></svg>
                            <span><?php echo h(t('settings.mfa_setup_btn')); ?></span>
                        </a>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    </main>

<script>
(function () {
    var emailSel = document.getElementById('email_provider');
    if (emailSel) {
        function updateEmail() {
            var ses = document.getElementById('ses-settings');
            var smtp = document.getElementById('smtp-settings');
            if (ses) ses.hidden = (emailSel.value !== 'ses');
            if (smtp) smtp.hidden = (emailSel.value !== 'smtp');
        }
        emailSel.addEventListener('change', updateEmail);
        updateEmail();
    }

    var wmEnabled = document.getElementById('webmentions_enabled');
    var wmSection = document.getElementById('webmentions-settings');
    if (wmEnabled && wmSection) {
        function updateWebmentions() {
            wmSection.hidden = !wmEnabled.checked;
        }
        wmEnabled.addEventListener('change', updateWebmentions);
        updateWebmentions();
    }

    // Ctrl+S / Cmd+S shortcut to save settings
    document.addEventListener('keydown', function (event) {
        if ((event.metaKey || event.ctrlKey) && event.key && event.key.toLowerCase() === 's') {
            var target = event.target;
            var activeForm = (target && typeof target.closest === 'function') ? target.closest('form') : null;
            var saveForm = activeForm || document.querySelector('form.admin-form') || document.querySelector('form[method="post"]');
            if (!saveForm) {
                return;
            }
            var submitBtn = saveForm.querySelector('button[type="submit"], input[type="submit"]');
            if (submitBtn && submitBtn.disabled) {
                return;
            }
            event.preventDefault();
            if (typeof saveForm.requestSubmit === 'function') {
                saveForm.requestSubmit(submitBtn || undefined);
            } else {
                saveForm.submit();
            }
        }
    });
}());
</script>
</body>
</html>
<?php

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
