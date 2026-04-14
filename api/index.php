<?php
declare(strict_types=1);

require __DIR__ . '/../includes/url.php';

if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'error' => 'Service not configured',
        'setup_url' => pc_url('/setup.php'),
    ]);
    exit;
}

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/parsedown.php';
require __DIR__ . '/../includes/render.php';
require __DIR__ . '/../includes/ses.php';
require_once __DIR__ . '/../includes/i18n.php';
pc_set_language((string)($config['language'] ?? 'en'));

$missingConfig = missing_required_config_keys($config);
if ($missingConfig !== []) {
    respond_json([
        'error' => 'Service misconfigured',
        'missing_config' => $missingConfig,
    ], 503);
}

set_cors_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Optional rewrite-free routing fallback (e.g. /api/index.php?endpoint=comments/my-slug).
$endpoint = trim((string)($_GET['endpoint'] ?? ''));
if ($endpoint !== '') {
    $path = '/' . ltrim($endpoint, '/');
} else {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    if ($basePath !== '' && strpos($path, $basePath) === 0) {
        $path = substr($path, strlen($basePath));
    }
    $path = '/' . ltrim($path, '/');
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (preg_match('#^/comments/([a-z0-9\-]+)$#', $path, $matches)) {
            handle_comments_index($config, $matches[1]);
            break;
        }
        respond_json(['error' => 'Not Found'], 404);
        break;
    case 'POST':
        if ($path === '/submit-comment') {
            handle_submit_comment($config);
            break;
        }
        respond_json(['error' => 'Not Found'], 404);
        break;
    default:
        respond_json(['error' => 'Method Not Allowed'], 405);
}

function set_cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

function respond_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload);
    exit;
}

function handle_comments_index(array $config, string $slug): void
{
    if (!validate_post_slug($slug)) {
        respond_json(['error' => 'Invalid request'], 422);
    }

    $comments = fetch_published_comments($config, $slug);
    $formatted = array_map(static function (array $comment) use ($config): array {
        return [
            'id' => (int)$comment['id'],
            'post_slug' => $comment['post_slug'],
            'parent_id' => $comment['parent_id'] !== null ? (int)$comment['parent_id'] : null,
            'name' => $comment['name'],
            'content_html' => $comment['content_html'],
            'created_at' => $comment['created_at'],
            'website' => $comment['website'] ?? null,
            'is_author' => is_author_comment($config, $comment['email'] ?? null, $comment['name']),
        ];
    }, $comments);

    $tree = build_comment_tree($formatted);
    respond_json([
        'comments' => $tree,
        'privacy_policy_url' => get_privacy_policy_url($config),
        'challenge_question' => get_spam_challenge_question($config),
        'challenge_placeholder' => get_spam_challenge_placeholder($config),
        'strings' => get_embed_strings(),
    ]);
}

function handle_submit_comment(array $config): void
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    if (is_submit_rate_limited($config, $ip)) {
        respond_json(['error' => t('api.rate_limited')], 429);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        respond_json(['error' => 'Validation failed'], 422);
    }

    $postSlug = isset($input['post_slug']) ? trim((string)$input['post_slug']) : '';
    $name = isset($input['name']) ? trim((string)$input['name']) : '';
    $email = isset($input['email']) ? trim((string)$input['email']) : '';
    $websiteInput = isset($input['website']) ? trim((string)$input['website']) : '';
    $content = isset($input['content']) ? trim((string)$input['content']) : '';
    $parentId = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int)$input['parent_id'] : null;
    $honeypot = isset($input['trap_field']) ? trim((string)$input['trap_field']) : '';
    $challenge = isset($input['surname']) ? trim((string)$input['surname']) : '';

    if ($honeypot !== '' || !is_valid_spam_challenge_answer($config, $challenge)) {
        respond_json(['error' => 'Validation failed'], 422);
    }

    register_submit_attempt($config, $ip);

    if (!validate_post_slug($postSlug)) {
        respond_json(['error' => 'Validation failed'], 422);
    }

    if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        respond_json(['error' => 'Validation failed'], 422);
    }

    if ($content === '' || mb_strlen($content) > 5000) {
        respond_json(['error' => 'Validation failed'], 422);
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond_json(['error' => 'Validation failed'], 422);
    }

    $website = normalize_website($websiteInput);
    if ($websiteInput !== '' && $website === null) {
        respond_json(['error' => 'Validation failed'], 422);
    }

    if ($parentId !== null) {
        $parent = fetch_comment_by_id($config, $parentId);
        if (!$parent || $parent['post_slug'] !== $postSlug) {
            respond_json(['error' => 'Validation failed'], 422);
        }
    }

    $parsedown = new Parsedown();
    $parsedown->setSafeMode(true);
    $parsedown->setBreaksEnabled(true);
    $contentHtml = $parsedown->text($content);

    $postTitle = resolve_post_title($postSlug, $config);

    $data = [
        'post_slug' => $postSlug,
        'parent_id' => $parentId,
        'name' => $name,
        'email_encrypted' => encrypt_email($email === '' ? null : $email, $config),
        'website' => $website,
        'content_md' => $content,
        'content_html' => $contentHtml,
        'created_at' => gmdate('Y-m-d H:i:s'),
        'status' => 'pending',
    ];

    $commentId = insert_comment($config, $data);

    $moderationLink = rtrim($config['moderation']['base_url'], '/') . '/';
    $subject = t('notifications.moderation_subject');
    $bodyText = t('notifications.moderation_body', [
        'post'    => $postTitle,
        'name'    => $name,
        'content' => $content,
        'url'     => $moderationLink,
    ]);
    ses_send_email($config, $config['moderation']['notify_email'], $subject, $bodyText);

    respond_json([
        'success' => true,
        'message' => t('api.comment_awaiting'),
        'comment_id' => $commentId,
    ]);
}

function normalize_website(string $input): ?string
{
    if ($input === '') {
        return null;
    }
    $url = $input;
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $sanitized = filter_var($url, FILTER_VALIDATE_URL);
    if ($sanitized === false) {
        return null;
    }
    return $sanitized;
}

function get_embed_strings(): array
{
    $keys = [
        'title', 'unavailable', 'load_btn', 'loading', 'load_error', 'no_comments',
        'author_badge', 'reply_btn', 'replying_to', 'cancel_reply', 'form_heading',
        'privacy_link', 'field_name', 'field_email', 'field_website', 'field_comment',
        'submitting', 'submit_btn', 'submit_success', 'submit_error',
    ];
    $strings = [];
    foreach ($keys as $key) {
        $strings[$key] = t('embed.' . $key);
    }
    return $strings;
}

function get_privacy_policy_url(array $config): string
{
    $url = trim((string)($config['privacy_policy_url'] ?? ''));
    return $url;
}

function get_spam_challenge_question(array $config): string
{
    return trim((string)($config['spam_challenge']['question'] ?? ''));
}

function get_spam_challenge_placeholder(array $config): string
{
    return trim((string)($config['spam_challenge']['placeholder'] ?? ''));
}

function is_valid_spam_challenge_answer(array $config, string $submitted): bool
{
    $submittedNormalized = mb_strtolower(trim($submitted));
    if ($submittedNormalized === '') {
        return false;
    }

    $configured = trim((string)($config['spam_challenge']['answer'] ?? ''));
    if ($configured === '') {
        return false;
    }

    return hash_equals(mb_strtolower($configured), $submittedNormalized);
}

function is_submit_rate_limited(array $config, string $ip): bool
{
    $windowSeconds = 10 * 60;
    $maxAttempts = 5;
    $store = load_submit_rate_limit_store($config);
    $key = hash('sha256', $ip);
    $now = time();
    $recent = array_filter(
        (array)($store[$key]['attempts'] ?? []),
        static fn($ts): bool => is_int($ts) && $ts >= ($now - $windowSeconds)
    );
    return count($recent) >= $maxAttempts;
}

function register_submit_attempt(array $config, string $ip): void
{
    $windowSeconds = 10 * 60;
    $now = time();
    $store = load_submit_rate_limit_store($config);
    $key = hash('sha256', $ip);
    $attempts = array_values(array_filter(
        (array)($store[$key]['attempts'] ?? []),
        static fn($ts): bool => is_int($ts) && $ts >= ($now - $windowSeconds)
    ));
    $attempts[] = $now;
    $store[$key] = ['attempts' => $attempts];
    save_submit_rate_limit_store($config, $store);
}

function load_submit_rate_limit_store(array $config): array
{
    $path = submit_rate_limit_store_path($config);
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function save_submit_rate_limit_store(array $config, array $store): void
{
    $path = submit_rate_limit_store_path($config);
    $encoded = json_encode($store);
    if ($encoded === false) {
        return;
    }
    @file_put_contents($path, $encoded, LOCK_EX);
}

function submit_rate_limit_store_path(array $config): string
{
    $dbPath = (string)($config['db_path'] ?? (__DIR__ . '/../db/comments.sqlite'));
    return dirname($dbPath) . '/submit-rate-limit.json';
}

function missing_required_config_keys(array $config): array
{
    $missing = [];
    if (trim((string)($config['spam_challenge']['question'] ?? '')) === '') {
        $missing[] = 'spam_challenge.question';
    }
    if (trim((string)($config['spam_challenge']['answer'] ?? '')) === '') {
        $missing[] = 'spam_challenge.answer';
    }
    return $missing;
}
