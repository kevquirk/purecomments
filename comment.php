<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(503);
    exit('Config not found.');
}

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/render.php';
require_once __DIR__ . '/includes/url.php';
require_once __DIR__ . '/includes/i18n.php';
pc_set_language((string)($config['language'] ?? 'en'));

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Not Found</title></head><body><p>Comment not found.</p></body></html>';
    exit;
}

$comment = fetch_comment_by_id($config, $id);
if (!$comment || $comment['status'] !== 'published') {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Not Found</title></head><body><p>Comment not found.</p></body></html>';
    exit;
}

$baseUrl = rtrim((string)($config['moderation']['base_url'] ?? ''), '/');
$publicBaseUrl = rtrim((string)($config['post_base_url'] ?? ''), '/');
$canonicalBaseUrl = $publicBaseUrl !== '' ? $publicBaseUrl : $baseUrl;
$commentUrl = $canonicalBaseUrl . '/comment.php?id=' . $comment['id'];
$webmentionEndpoint = $baseUrl . '/api/webmention';
$fediverseProfileUrl = trim((string)($config['webmentions']['fediverse_profile_url'] ?? ''));

// Find parent target URL if this is a reply
$inReplyToUrl = null;
if (!empty($comment['parent_id'])) {
    $parent = fetch_comment_by_id($config, (int)$comment['parent_id']);
    if ($parent) {
        $inReplyToUrl = !empty($parent['source_url']) ? $parent['source_url'] : (!empty($parent['website']) ? $parent['website'] : null);
    }
}

$authorName = $comment['name'] ?: (string)($config['author']['name'] ?? 'Admin');
$authorAvatar = !empty($comment['avatar_url']) ? $comment['avatar_url'] : (string)($config['author']['avatar_url'] ?? '');
$authorUrl = !empty($comment['website']) ? $comment['website'] : (string)($config['post_base_url'] ?? '/');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Comment #<?php echo (int)$comment['id']; ?> by <?php echo htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="canonical" href="<?php echo htmlspecialchars($commentUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="webmention" href="<?php echo htmlspecialchars($webmentionEndpoint, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($fediverseProfileUrl !== '') : ?>
        <link rel="me" href="<?php echo htmlspecialchars($fediverseProfileUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
</head>
<body>
    <article class="h-entry" id="comment-<?php echo (int)$comment['id']; ?>">
        <div class="p-author h-card">
            <a class="p-name u-url" href="<?php echo htmlspecialchars($authorUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8'); ?></a>
            <?php if ($fediverseProfileUrl !== '') : ?>
                <a class="u-url" rel="me" href="<?php echo htmlspecialchars($fediverseProfileUrl, ENT_QUOTES, 'UTF-8'); ?>" hidden></a>
            <?php endif; ?>
            <?php if ($authorAvatar !== '') : ?>
                <img class="u-photo" src="<?php echo htmlspecialchars($authorAvatar, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8'); ?>" />
            <?php endif; ?>
        </div>
        <?php if (!empty($inReplyToUrl)) : ?>
            <p><a class="u-in-reply-to" href="<?php echo htmlspecialchars($inReplyToUrl, ENT_QUOTES, 'UTF-8'); ?>" rel="in-reply-to">In reply to original post</a></p>
        <?php endif; ?>
        <div class="e-content">
            <?php echo $comment['content_html']; ?>
        </div>
        <a class="u-bridgy-publish u-bridgy-omit-link" href="https://brid.gy/publish/mastodon" hidden>Bridgy Publish</a>
        <p>
            <a class="u-url" href="<?php echo htmlspecialchars($commentUrl, ENT_QUOTES, 'UTF-8'); ?>">
                <time class="dt-published" datetime="<?php echo htmlspecialchars(str_replace(' ', 'T', $comment['created_at']) . 'Z', ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($comment['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                </time>
            </a>
        </p>
    </article>
</body>
</html>
