<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'error' => 'Service not configured',
        'setup_url' => '/setup.php',
    ]);
    exit;
}

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/parsedown.php';
require __DIR__ . '/../includes/render.php';
require __DIR__ . '/../includes/ses.php';

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

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath !== '' && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}
$path = '/' . ltrim($path, '/');

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
    ]);
}

function handle_submit_comment(array $config): void
{
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
    $subject = 'New comment awaiting moderation';
    $bodyText = sprintf(
        "New comment waiting for review\n\nPost: %s\nName: %s\nContent:\n%s\n\nModerate: %s",
        $postTitle,
        $name,
        $content,
        $moderationLink
    );
    ses_send_email($config, $config['moderation']['notify_email'], $subject, $bodyText);

    respond_json([
        'success' => true,
        'message' => 'Your comment is awaiting moderation.',
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

function get_privacy_policy_url(array $config): string
{
    $url = trim((string)($config['privacy_policy_url'] ?? ''));
    return $url !== '' ? $url : '/privacy#commenting';
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
