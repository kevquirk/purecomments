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
pc_set_language((string)($config['language'] ?? 'en'));

require_admin_login($config);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$messages = [];

$form = [
    'language'       => (string)($config['language'] ?? default_comments_language()),
    'admin_username' => (string)($config['admin_username'] ?? ''),
    'timezone' => (string)($config['timezone'] ?? default_comments_timezone()),
    'date_format' => (string)($config['date_format'] ?? default_comments_date_format()),
    'privacy_policy_url' => (string)($config['privacy_policy_url'] ?? '/privacy#commenting'),
    'spam_challenge_question' => (string)($config['spam_challenge']['question'] ?? ''),
    'spam_challenge_answer' => (string)($config['spam_challenge']['answer'] ?? ''),
    'spam_challenge_placeholder' => (string)($config['spam_challenge']['placeholder'] ?? ''),
    'post_base_url' => (string)($config['post_base_url'] ?? ''),
    'author_name' => (string)($config['author']['name'] ?? ''),
    'author_email' => (string)($config['author']['email'] ?? ''),
    'notify_email' => (string)($config['moderation']['notify_email'] ?? ''),
    'moderation_base_url' => (string)($config['moderation']['base_url'] ?? ''),
    'aws_region' => (string)($config['aws']['region'] ?? ''),
    'aws_access_key' => (string)($config['aws']['access_key'] ?? ''),
    'aws_secret_key' => (string)($config['aws']['secret_key'] ?? ''),
    'source_email' => (string)($config['aws']['source_email'] ?? ''),
    'source_name' => (string)($config['aws']['source_name'] ?? ''),
    'smtp_host' => (string)($config['smtp']['host'] ?? ''),
    'smtp_port' => (string)($config['smtp']['port'] ?? '587'),
    'smtp_user' => (string)($config['smtp']['user'] ?? ''),
    'smtp_pwd' => (string)($config['smtp']['pwd'] ?? ''),
    'smtp_enc' => (string)($config['smtp']['enc'] ?? 'tls'),
    'smtp_debug' => (bool)($config['smtp']['debug'] ?? false),
];

$emailProvider = '';
if (!empty($config['smtp']['host'])) {
    $emailProvider = 'smtp';
} elseif (!empty($config['aws']['access_key'])) {
    $emailProvider = 'ses';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['csrf_token'], $token)) {
        $errors[] = t('settings.err_csrf');
    } elseif (($_POST['action'] ?? '') === 'test_email') {
        $testTo = (string)($config['moderation']['notify_email'] ?? '');
        $smtpDebugLog = null;
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
    } else {
        $form['language']       = trim((string)($_POST['language'] ?? default_comments_language()));
        $form['admin_username'] = trim((string)($_POST['admin_username'] ?? ''));
        $form['timezone'] = trim((string)($_POST['timezone'] ?? default_comments_timezone()));
        $form['date_format'] = trim((string)($_POST['date_format'] ?? default_comments_date_format()));
        $form['privacy_policy_url'] = trim((string)($_POST['privacy_policy_url'] ?? ''));
        $adminPassword = (string)($_POST['admin_password'] ?? '');
        $adminPasswordConfirm = (string)($_POST['admin_password_confirm'] ?? '');
        $form['spam_challenge_question'] = trim((string)($_POST['spam_challenge_question'] ?? ''));
        $form['spam_challenge_answer'] = trim((string)($_POST['spam_challenge_answer'] ?? ''));
        $form['spam_challenge_placeholder'] = trim((string)($_POST['spam_challenge_placeholder'] ?? ''));
        $form['post_base_url'] = trim((string)($_POST['post_base_url'] ?? ''));
        $form['author_name'] = trim((string)($_POST['author_name'] ?? ''));
        $form['author_email'] = trim((string)($_POST['author_email'] ?? ''));
        $form['notify_email'] = trim((string)($_POST['notify_email'] ?? ''));
        $form['moderation_base_url'] = trim((string)($_POST['moderation_base_url'] ?? ''));
        $emailProvider = trim((string)($_POST['email_provider'] ?? ''));
        if ($emailProvider === 'ses') {
            $form['aws_region'] = trim((string)($_POST['aws_region'] ?? ''));
            $form['aws_access_key'] = trim((string)($_POST['aws_access_key'] ?? ''));
            $form['aws_secret_key'] = trim((string)($_POST['aws_secret_key'] ?? ''));
            $form['source_email'] = trim((string)($_POST['source_email'] ?? ''));
            $form['source_name'] = trim((string)($_POST['source_name'] ?? ''));
            $form['smtp_host'] = '';
            $form['smtp_port'] = '587';
            $form['smtp_user'] = '';
            $form['smtp_pwd'] = '';
            $form['smtp_enc'] = 'tls';
        } elseif ($emailProvider === 'smtp') {
            $form['smtp_host']  = trim((string)($_POST['smtp_host'] ?? ''));
            $form['smtp_port']  = trim((string)($_POST['smtp_port'] ?? '587'));
            $form['smtp_user']  = trim((string)($_POST['smtp_user'] ?? ''));
            $form['smtp_pwd']   = trim((string)($_POST['smtp_pwd'] ?? ''));
            $form['smtp_enc']   = trim((string)($_POST['smtp_enc'] ?? 'tls'));
            $form['smtp_debug'] = isset($_POST['smtp_debug']);
            $form['aws_region'] = '';
            $form['aws_access_key'] = '';
            $form['aws_secret_key'] = '';
            $form['source_email'] = '';
            $form['source_name'] = '';
        } else {
            $form['aws_region'] = '';
            $form['aws_access_key'] = '';
            $form['aws_secret_key'] = '';
            $form['source_email'] = '';
            $form['source_name'] = '';
            $form['smtp_host'] = '';
            $form['smtp_port'] = '587';
            $form['smtp_user'] = '';
            $form['smtp_pwd'] = '';
            $form['smtp_enc'] = 'tls';
        }

        if ($form['admin_username'] === '') {
            $errors[] = t('settings.err_username');
        }
        if (!is_valid_timezone_id($form['timezone'])) {
            $errors[] = t('settings.err_timezone');
        }
        if ($form['date_format'] === '') {
            $errors[] = t('settings.err_date_format');
        }
        if ($form['spam_challenge_question'] === '') {
            $errors[] = t('settings.err_challenge_question');
        }
        if ($form['spam_challenge_answer'] === '') {
            $errors[] = t('settings.err_challenge_answer');
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

        if ($form['author_name'] === '') {
            $errors[] = t('settings.err_author_name');
        }

        if (!filter_var($form['author_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('settings.err_author_email');
        }

        if (!filter_var($form['notify_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('settings.err_notify_email');
        }

        if ($form['post_base_url'] === '' || !filter_var($form['post_base_url'], FILTER_VALIDATE_URL)) {
            $errors[] = t('settings.err_post_base_url');
        }

        if ($form['moderation_base_url'] === '' || !filter_var($form['moderation_base_url'], FILTER_VALIDATE_URL)) {
            $errors[] = t('settings.err_service_url');
        }

        if ($emailProvider === 'ses') {
            if ($form['source_email'] !== '' && !filter_var($form['source_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = t('settings.err_ses_source_email');
            }
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

        if (empty($errors)) {
            $passwordHash = (string)($config['admin_password_hash'] ?? '');
            if ($adminPassword !== '') {
                $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            }

            $existingSodium = $config['sodium_key'] ?? null;
            if (!is_string($existingSodium) || strlen($existingSodium) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                $existingSodium = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            }

            $configPhp = build_config_php([
                'language' => $form['language'],
                'admin_username' => $form['admin_username'],
                'admin_password_hash' => $passwordHash,
                'sodium_key_hex' => bin2hex($existingSodium),
                'timezone' => normalize_comments_timezone($form['timezone']),
                'date_format' => normalize_comments_date_format($form['date_format']),
                'privacy_policy_url' => $form['privacy_policy_url'],
                'post_titles' => is_array($config['post_titles'] ?? null) ? $config['post_titles'] : [],
                'spam_challenge_question' => $form['spam_challenge_question'],
                'spam_challenge_answer' => $form['spam_challenge_answer'],
                'spam_challenge_placeholder' => $form['spam_challenge_placeholder'],
                'post_base_url' => rtrim($form['post_base_url'], '/'),
                'author_name' => $form['author_name'],
                'author_email' => $form['author_email'],
                'aws_region' => $form['aws_region'],
                'aws_access_key' => $form['aws_access_key'],
                'aws_secret_key' => $form['aws_secret_key'],
                'source_email' => $form['source_email'],
                'source_name' => $form['source_name'],
                'smtp_host'  => $form['smtp_host'],
                'smtp_port'  => $form['smtp_port'],
                'smtp_user'  => $form['smtp_user'],
                'smtp_pwd'   => $form['smtp_pwd'],
                'smtp_enc'   => $form['smtp_enc'],
                'smtp_debug' => $form['smtp_debug'],
                'notify_email' => $form['notify_email'],
                'moderation_base_url' => rtrim($form['moderation_base_url'], '/') . '/',
            ]);

            if (@file_put_contents($configPath, $configPhp, LOCK_EX) === false) {
                $errors[] = t('settings.err_save_config');
            } else {
                $config = require $configPath;
                pc_set_language((string)($config['language'] ?? 'en'));
                $_SESSION['admin_username'] = (string)$config['admin_username'];
                $messages[] = t('settings.msg_saved');
            }
        }
    }
}

$styleVersion = filemtime(__DIR__ . '/public/style.css');
?>
<!doctype html>
<html lang="<?php echo h(_pc_lang_code()); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h(t('settings.title')); ?></title>
    <link rel="stylesheet" href="<?php echo h(pc_url('/public/style.css', $config)); ?>?v=<?php echo h((string)$styleVersion); ?>">
</head>
<body class="admin">
    <main class="admin-container">
        <div class="admin-top-actions">
            <a class="button" href="<?php echo h(pc_url('/', $config)); ?>">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-back"></use></svg>
                <span><?php echo h(t('settings.back_btn')); ?></span>
            </a>
            <a class="button" href="<?php echo h(pc_url('/updates.php', $config)); ?>">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-upgrade"></use></svg>
                <span><?php echo h(t('settings.updates_btn')); ?></span>
            </a>
            <a class="button danger" href="<?php echo h(pc_url('/logout.php', $config)); ?>">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-logout"></use></svg>
                <span><?php echo h(t('settings.logout_btn')); ?></span>
            </a>
        </div>

        <h1><?php echo h(t('settings.heading')); ?></h1>

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

        <?php if (!empty($smtpDebugLog)) : ?>
            <h2><?php echo h(t('settings.smtp_debug_heading')); ?></h2>
            <pre class="smtp-debug-log"><?php echo h($smtpDebugLog); ?></pre>
        <?php endif; ?>

        <form method="post" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?php echo h((string)$_SESSION['csrf_token']); ?>">

            <h2><?php echo h(t('settings.section_admin')); ?></h2>
            <label for="admin_username"><?php echo h(t('settings.field_username')); ?></label>
            <input id="admin_username" name="admin_username" required value="<?php echo h($form['admin_username']); ?>">

            <label for="admin_password"><?php echo h(t('settings.field_password')); ?></label>
            <input id="admin_password" name="admin_password" type="password" minlength="10" autocomplete="new-password">

            <label for="admin_password_confirm"><?php echo h(t('settings.field_password_confirm')); ?></label>
            <input id="admin_password_confirm" name="admin_password_confirm" type="password" minlength="10" autocomplete="new-password">

            <?php $availableLangs = pc_available_languages(); ?>
            <?php if (count($availableLangs) > 1) : ?>
                <label for="language"><?php echo h(t('settings.field_language')); ?></label>
                <select id="language" name="language" onchange="this.form.submit()">
                    <?php foreach ($availableLangs as $langCode => $langName) : ?>
                        <option value="<?php echo h($langCode); ?>" <?php echo $form['language'] === $langCode ? 'selected' : ''; ?>><?php echo h($langName); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else : ?>
                <input type="hidden" name="language" value="<?php echo h($form['language']); ?>">
            <?php endif; ?>

            <h2><?php echo h(t('settings.section_site')); ?></h2>
            <label for="post_base_url"><?php echo h(t('settings.field_post_base_url')); ?></label>
            <input id="post_base_url" name="post_base_url" required placeholder="https://example.com/blog" value="<?php echo h($form['post_base_url']); ?>">

            <label for="moderation_base_url"><?php echo h(t('settings.field_service_url')); ?></label>
            <input id="moderation_base_url" name="moderation_base_url" required placeholder="https://comments.example.com" value="<?php echo h($form['moderation_base_url']); ?>">

            <label for="timezone">
                <?php echo h(t('settings.field_timezone')); ?>
                <small>(<a href="https://www.php.net/manual/en/timezones.php" target="_blank" rel="noopener noreferrer">PHP timezone list</a>)</small>
            </label>
            <input id="timezone" name="timezone" required placeholder="UTC" value="<?php echo h($form['timezone']); ?>">

            <label for="date_format">
                <?php echo h(t('settings.field_date_format')); ?>
                <small>(<a href="https://www.php.net/manual/en/datetime.format.php" target="_blank" rel="noopener noreferrer">PHP date format docs</a>)</small>
            </label>
            <input id="date_format" name="date_format" required placeholder="Y-m-d H:i" value="<?php echo h($form['date_format']); ?>">

            <label for="privacy_policy_url"><?php echo h(t('settings.field_privacy_url')); ?></label>
            <input id="privacy_policy_url" name="privacy_policy_url" placeholder="/privacy#commenting" value="<?php echo h($form['privacy_policy_url']); ?>">

            <h2><?php echo h(t('settings.section_spam')); ?></h2>
            <label for="spam_challenge_question"><?php echo h(t('settings.field_challenge_question')); ?></label>
            <input id="spam_challenge_question" name="spam_challenge_question" required value="<?php echo h($form['spam_challenge_question']); ?>">

            <label for="spam_challenge_answer"><?php echo h(t('settings.field_challenge_answer')); ?></label>
            <input id="spam_challenge_answer" name="spam_challenge_answer" required value="<?php echo h($form['spam_challenge_answer']); ?>">

            <label for="spam_challenge_placeholder"><?php echo h(t('settings.field_challenge_ph')); ?></label>
            <input id="spam_challenge_placeholder" name="spam_challenge_placeholder" value="<?php echo h($form['spam_challenge_placeholder']); ?>">

            <h2><?php echo h(t('settings.section_author')); ?></h2>
            <label for="author_name"><?php echo h(t('settings.field_author_name')); ?></label>
            <input id="author_name" name="author_name" required value="<?php echo h($form['author_name']); ?>">

            <label for="author_email"><?php echo h(t('settings.field_author_email')); ?></label>
            <input id="author_email" name="author_email" type="email" required value="<?php echo h($form['author_email']); ?>">

            <h2><?php echo h(t('settings.section_email')); ?></h2>
            <label for="email_provider"><?php echo h(t('settings.field_email_provider')); ?></label>
            <select id="email_provider" name="email_provider">
                <option value="" <?php echo $emailProvider === '' ? 'selected' : ''; ?>><?php echo h(t('settings.email_none')); ?></option>
                <option value="ses" <?php echo $emailProvider === 'ses' ? 'selected' : ''; ?>><?php echo h(t('settings.email_ses')); ?></option>
                <option value="smtp" <?php echo $emailProvider === 'smtp' ? 'selected' : ''; ?>><?php echo h(t('settings.email_smtp')); ?></option>
            </select>

            <label for="notify_email"><?php echo h(t('settings.field_notify_email')); ?></label>
            <input id="notify_email" name="notify_email" type="email" value="<?php echo h($form['notify_email']); ?>">

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

                <label class="inline-checkbox" for="smtp_debug">
                    <input id="smtp_debug" name="smtp_debug" type="checkbox" value="1" <?php echo $form['smtp_debug'] ? 'checked' : ''; ?>>
                    <?php echo h(t('settings.field_smtp_debug')); ?>
                </label>
            </div>

            <div class="admin-form-buttons">
                <button type="submit">
                    <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-settings"></use></svg>
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
    </main>
<script>
(function () {
    var sel = document.getElementById('email_provider');
    function update() {
        document.getElementById('ses-settings').hidden = sel.value !== 'ses';
        document.getElementById('smtp-settings').hidden = sel.value !== 'smtp';
    }
    sel.addEventListener('change', update);
    update();
}());
</script>
</body>
</html>
<?php

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
