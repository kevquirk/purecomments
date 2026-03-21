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

require_admin_login($config);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$messages = [];

$form = [
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
        $errors[] = 'Invalid CSRF token.';
    } elseif (($_POST['action'] ?? '') === 'test_email') {
        require_once __DIR__ . '/includes/ses.php';
        $testTo = (string)($config['moderation']['notify_email'] ?? '');
        if ($testTo === '') {
            $errors[] = 'No notification email address is configured.';
        } elseif (ses_send_email($config, $testTo, 'Pure Comments: test email', 'This is a test email from Pure Comments to confirm your email notifications are working correctly.')) {
            $messages[] = 'Test email sent to ' . $testTo . '.';
        } else {
            $errors[] = 'Test email failed to send. Check your email settings and server error logs.';
        }
    } else {
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
            $form['smtp_host'] = trim((string)($_POST['smtp_host'] ?? ''));
            $form['smtp_port'] = trim((string)($_POST['smtp_port'] ?? '587'));
            $form['smtp_user'] = trim((string)($_POST['smtp_user'] ?? ''));
            $form['smtp_pwd'] = trim((string)($_POST['smtp_pwd'] ?? ''));
            $form['smtp_enc'] = trim((string)($_POST['smtp_enc'] ?? 'tls'));
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
            $errors[] = 'Admin username is required.';
        }
        if (!is_valid_timezone_id($form['timezone'])) {
            $errors[] = 'Timezone must be a valid PHP timezone identifier (e.g. UTC, Europe/London).';
        }
        if ($form['date_format'] === '') {
            $errors[] = 'Date format is required.';
        }
        if ($form['spam_challenge_question'] === '') {
            $errors[] = 'Spam challenge question is required.';
        }
        if ($form['spam_challenge_answer'] === '') {
            $errors[] = 'Spam challenge answer is required.';
        }

        if ($adminPassword !== '' || $adminPasswordConfirm !== '') {
            if ($adminPassword === '') {
                $errors[] = 'Enter a new admin password to change credentials.';
            } elseif (strlen($adminPassword) < 10) {
                $errors[] = 'Admin password must be at least 10 characters if set.';
            }
            if (!hash_equals($adminPassword, $adminPasswordConfirm)) {
                $errors[] = 'Admin passwords do not match.';
            }
        }

        if ($form['author_name'] === '') {
            $errors[] = 'Author name is required.';
        }

        if (!filter_var($form['author_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Author email must be valid.';
        }

        if (!filter_var($form['notify_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Notification email must be valid.';
        }

        if ($form['post_base_url'] === '' || !filter_var($form['post_base_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Post base URL must be valid (e.g. https://example.com/blog).';
        }

        if ($form['moderation_base_url'] === '' || !filter_var($form['moderation_base_url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Comments service URL must be valid (e.g. https://comments.example.com).';
        }

        if ($emailProvider === 'ses') {
            if ($form['source_email'] !== '' && !filter_var($form['source_email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'SES source email must be valid if set.';
            }
        }

        if ($emailProvider === 'smtp') {
            if ($form['smtp_host'] === '') {
                $errors[] = 'SMTP host is required.';
            }
            if ($form['smtp_port'] === '' || !ctype_digit($form['smtp_port'])) {
                $errors[] = 'SMTP port must be a number.';
            }
            if (!in_array($form['smtp_enc'], ['tls', 'ssl', ''], true)) {
                $errors[] = 'SMTP encryption must be tls, ssl, or none.';
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
                'smtp_host' => $form['smtp_host'],
                'smtp_port' => $form['smtp_port'],
                'smtp_user' => $form['smtp_user'],
                'smtp_pwd' => $form['smtp_pwd'],
                'smtp_enc' => $form['smtp_enc'],
                'notify_email' => $form['notify_email'],
                'moderation_base_url' => rtrim($form['moderation_base_url'], '/') . '/',
            ]);

            if (@file_put_contents($configPath, $configPhp, LOCK_EX) === false) {
                $errors[] = 'Unable to save config.php. Check filesystem permissions.';
            } else {
                $config = require $configPath;
                $_SESSION['admin_username'] = (string)$config['admin_username'];
                $messages[] = 'Settings saved.';
            }
        }
    }
}

$styleVersion = filemtime(__DIR__ . '/public/style.css');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comments Settings</title>
    <link rel="stylesheet" href="<?php echo h(pc_url('/public/style.css', $config)); ?>?v=<?php echo h((string)$styleVersion); ?>">
</head>
<body class="admin">
    <main class="admin-container">
        <div class="admin-top-actions">
            <a class="button" href="<?php echo h(pc_url('/', $config)); ?>">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-back"></use></svg>
                <span>Back to comments</span>
            </a>
            <a class="button" href="<?php echo h(pc_url('/updates.php', $config)); ?>">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-upgrade"></use></svg>
                <span>Updates</span>
            </a>
            <a class="button danger" href="<?php echo h(pc_url('/logout.php', $config)); ?>">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-logout"></use></svg>
                <span>Log out</span>
            </a>
        </div>

        <h1>Settings</h1>

        <?php foreach ($messages as $message) : ?>
            <p class="notice success"><?php echo h($message); ?></p>
        <?php endforeach; ?>

        <?php if (!empty($errors)) : ?>
            <div class="notice error">
                <strong>Settings errors:</strong>
                <ul>
                    <?php foreach ($errors as $error) : ?>
                        <li><?php echo h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?php echo h((string)$_SESSION['csrf_token']); ?>">

            <h2>Admin</h2>
            <label for="admin_username">Admin username</label>
            <input id="admin_username" name="admin_username" required value="<?php echo h($form['admin_username']); ?>">

            <label for="admin_password">Admin password (leave blank to keep current)</label>
            <input id="admin_password" name="admin_password" type="password" minlength="10" autocomplete="new-password">

            <label for="admin_password_confirm">Confirm admin password</label>
            <input id="admin_password_confirm" name="admin_password_confirm" type="password" minlength="10" autocomplete="new-password">

            <h2>Site</h2>
            <label for="post_base_url">Post base URL</label>
            <input id="post_base_url" name="post_base_url" required placeholder="https://example.com/blog" value="<?php echo h($form['post_base_url']); ?>">

            <label for="moderation_base_url">Comments service URL</label>
            <input id="moderation_base_url" name="moderation_base_url" required placeholder="https://comments.example.com" value="<?php echo h($form['moderation_base_url']); ?>">

            <label for="timezone">
                Timezone
                <small>(<a href="https://www.php.net/manual/en/timezones.php" target="_blank" rel="noopener noreferrer">PHP timezone list</a>)</small>
            </label>
            <input id="timezone" name="timezone" required placeholder="UTC" value="<?php echo h($form['timezone']); ?>">

            <label for="date_format">
                Date format
                <small>(<a href="https://www.php.net/manual/en/datetime.format.php" target="_blank" rel="noopener noreferrer">PHP date format docs</a>)</small>
            </label>
            <input id="date_format" name="date_format" required placeholder="Y-m-d H:i" value="<?php echo h($form['date_format']); ?>">

            <label for="privacy_policy_url">Privacy policy URL</label>
            <input id="privacy_policy_url" name="privacy_policy_url" placeholder="/privacy#commenting" value="<?php echo h($form['privacy_policy_url']); ?>">

            <h2>Spam protection</h2>
            <label for="spam_challenge_question">Challenge question</label>
            <input id="spam_challenge_question" name="spam_challenge_question" required value="<?php echo h($form['spam_challenge_question']); ?>">

            <label for="spam_challenge_answer">Challenge answer</label>
            <input id="spam_challenge_answer" name="spam_challenge_answer" required value="<?php echo h($form['spam_challenge_answer']); ?>">

            <label for="spam_challenge_placeholder">Challenge placeholder (optional)</label>
            <input id="spam_challenge_placeholder" name="spam_challenge_placeholder" value="<?php echo h($form['spam_challenge_placeholder']); ?>">

            <h2>Author</h2>
            <label for="author_name">Author name</label>
            <input id="author_name" name="author_name" required value="<?php echo h($form['author_name']); ?>">

            <label for="author_email">Author email</label>
            <input id="author_email" name="author_email" type="email" required value="<?php echo h($form['author_email']); ?>">

            <h2>Email notifications (optional)</h2>
            <label for="email_provider">Email provider</label>
            <select id="email_provider" name="email_provider">
                <option value="" <?php echo $emailProvider === '' ? 'selected' : ''; ?>>None</option>
                <option value="ses" <?php echo $emailProvider === 'ses' ? 'selected' : ''; ?>>Amazon SES</option>
                <option value="smtp" <?php echo $emailProvider === 'smtp' ? 'selected' : ''; ?>>SMTP</option>
            </select>

            <label for="notify_email">Moderation notify email</label>
            <input id="notify_email" name="notify_email" type="email" value="<?php echo h($form['notify_email']); ?>">

            <div id="ses-settings" class="admin-form-section" hidden>
                <label for="aws_region">AWS region</label>
                <input id="aws_region" name="aws_region" placeholder="eu-west-1" value="<?php echo h($form['aws_region']); ?>">

                <label for="aws_access_key">AWS access key</label>
                <input id="aws_access_key" name="aws_access_key" value="<?php echo h($form['aws_access_key']); ?>">

                <label for="aws_secret_key">AWS secret key</label>
                <input id="aws_secret_key" name="aws_secret_key" value="<?php echo h($form['aws_secret_key']); ?>">

                <label for="source_email">Source email address</label>
                <input id="source_email" name="source_email" type="email" value="<?php echo h($form['source_email']); ?>">

                <label for="source_name">Source name</label>
                <input id="source_name" name="source_name" value="<?php echo h($form['source_name']); ?>">
            </div>

            <div id="smtp-settings" class="admin-form-section" hidden>
                <label for="smtp_host">SMTP host</label>
                <input id="smtp_host" name="smtp_host" placeholder="smtp.example.com" value="<?php echo h($form['smtp_host']); ?>">

                <label for="smtp_port">SMTP port</label>
                <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" placeholder="587" value="<?php echo h($form['smtp_port']); ?>">

                <label for="smtp_enc">Encryption</label>
                <select id="smtp_enc" name="smtp_enc">
                    <option value="tls" <?php echo $form['smtp_enc'] === 'tls' ? 'selected' : ''; ?>>STARTTLS (port 587)</option>
                    <option value="ssl" <?php echo $form['smtp_enc'] === 'ssl' ? 'selected' : ''; ?>>SSL/TLS (port 465)</option>
                    <option value="" <?php echo $form['smtp_enc'] === '' ? 'selected' : ''; ?>>None (port 25)</option>
                </select>

                <label for="smtp_user">SMTP username</label>
                <input id="smtp_user" name="smtp_user" autocomplete="off" value="<?php echo h($form['smtp_user']); ?>">

                <label for="smtp_pwd">SMTP password</label>
                <input id="smtp_pwd" name="smtp_pwd" type="password" autocomplete="new-password" value="<?php echo h($form['smtp_pwd']); ?>">
            </div>

            <div class="admin-form-buttons">
                <button type="submit">
                    <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-settings"></use></svg>
                    <span>Save settings</span>
                </button>
                <?php if ($emailProvider !== '') : ?>
                <button type="submit" name="action" value="test_email" class="danger">
                    <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-mail"></use></svg>
                    <span>Send test email</span>
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
