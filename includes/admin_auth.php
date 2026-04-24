<?php
declare(strict_types=1);

require_once __DIR__ . '/url.php';

function remember_me_token(array $config): string
{
    return hash_hmac('sha256', 'purecomments_remember_me', $config['admin_password_hash'] ?? '');
}

function set_remember_me_cookie(array $config): void
{
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    setcookie('pc_remember', remember_me_token($config), [
        'expires'  => time() + (90 * 24 * 60 * 60),
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_remember_me_cookie(): void
{
    setcookie('pc_remember', '', ['expires' => time() - 3600, 'path' => '/']);
}

function maybe_restore_admin_from_cookie(array $config): void
{
    if (is_admin_logged_in($config)) {
        return;
    }
    $cookie = $_COOKIE['pc_remember'] ?? '';
    if ($cookie === '') {
        return;
    }
    if (hash_equals(remember_me_token($config), $cookie)) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $config['admin_username'] ?? '';
    }
}

function require_admin_login(array $config): void
{
    maybe_restore_admin_from_cookie($config);
    if (!is_admin_logged_in($config)) {
        header('Location: ' . pc_url('/login.php', $config), true, 302);
        exit;
    }
}

function attempt_admin_login(array $config, string $username, string $password): bool
{
    $ip = get_client_ip();
    if (is_login_rate_limited($config, $username, $ip)) {
        return false;
    }

    if (!verify_admin_credentials($config, $username, $password)) {
        register_login_failure($config, $username, $ip);
        return false;
    }

    clear_login_failures($config, $username, $ip);
    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username'] = $config['admin_username'] ?? $username;
    return true;
}

function verify_admin_credentials(array $config, string $username, string $password): bool
{
    $configUsername = (string)($config['admin_username'] ?? '');
    if (!hash_equals($configUsername, $username)) {
        return false;
    }

    $hash = (string)($config['admin_password_hash'] ?? '');
    if ($hash === '') {
        return false;
    }

    return password_verify($password, $hash);
}

function is_admin_logged_in(array $config): bool
{
    $sessionLoggedIn = !empty($_SESSION['admin_logged_in']);
    $sessionUser = (string)($_SESSION['admin_username'] ?? '');
    $configUser = (string)($config['admin_username'] ?? '');
    return $sessionLoggedIn && $sessionUser !== '' && hash_equals($sessionUser, $configUser);
}

function admin_logout(): void
{
    clear_remember_me_cookie();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function get_client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function login_rate_limit_retry_after(array $config, string $username, string $ip): int
{
    $store = load_login_rate_limit_store($config);
    $key = login_rate_limit_key($username, $ip);
    if (empty($store[$key]['blocked_until'])) {
        return 0;
    }

    $retry = (int)$store[$key]['blocked_until'] - time();
    return max(0, $retry);
}

function is_login_rate_limited(array $config, string $username, string $ip): bool
{
    return login_rate_limit_retry_after($config, $username, $ip) > 0;
}

function register_login_failure(array $config, string $username, string $ip): void
{
    $windowSeconds = 5 * 60;
    $maxAttempts = 5;
    $lockoutSeconds = 5 * 60;
    $now = time();
    $key = login_rate_limit_key($username, $ip);
    $store = load_login_rate_limit_store($config);
    $entry = $store[$key] ?? ['attempts' => [], 'blocked_until' => 0, 'updated_at' => 0];

    $attempts = array_values(array_filter(
        (array)$entry['attempts'],
        static fn ($ts): bool => is_int($ts) ? ($ts >= ($now - $windowSeconds)) : false
    ));
    $attempts[] = $now;

    $entry['attempts'] = $attempts;
    if (count($attempts) >= $maxAttempts) {
        $entry['blocked_until'] = $now + $lockoutSeconds;
    }
    $entry['updated_at'] = $now;
    $store[$key] = $entry;
    save_login_rate_limit_store($config, $store);
}

function clear_login_failures(array $config, string $username, string $ip): void
{
    $store = load_login_rate_limit_store($config);
    $key = login_rate_limit_key($username, $ip);
    if (isset($store[$key])) {
        unset($store[$key]);
        save_login_rate_limit_store($config, $store);
    }
}

function login_rate_limit_key(string $username, string $ip): string
{
    return hash('sha256', strtolower(trim($username)) . '|' . $ip);
}

function load_login_rate_limit_store(array $config): array
{
    $path = login_rate_limit_store_path($config);
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    // Drop very old entries.
    $cutoff = time() - (24 * 60 * 60);
    foreach ($decoded as $key => $entry) {
        $updatedAt = (int)($entry['updated_at'] ?? 0);
        $blockedUntil = (int)($entry['blocked_until'] ?? 0);
        if ($updatedAt < $cutoff && $blockedUntil < time()) {
            unset($decoded[$key]);
        }
    }

    return $decoded;
}

function save_login_rate_limit_store(array $config, array $store): void
{
    $path = login_rate_limit_store_path($config);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $encoded = json_encode($store);
    if ($encoded === false) {
        return;
    }
    @file_put_contents($path, $encoded, LOCK_EX);
}

function login_rate_limit_store_path(array $config): string
{
    $dbPath = (string)($config['db_path'] ?? (__DIR__ . '/../db/comments.sqlite'));
    return dirname($dbPath) . '/login-rate-limit.json';
}
