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
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/render.php';
require __DIR__ . '/includes/ses.php';
require __DIR__ . '/includes/parsedown.php';
require_once __DIR__ . '/includes/i18n.php';
pc_set_language((string)($config['language'] ?? 'en'));

require_admin_login($config);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$messages = [];
$errors = [];
$perPage = 20;
$pendingPage = max(1, (int)($_GET['pending_page'] ?? $_POST['pending_page'] ?? 1));
$publishedPage = max(1, (int)($_GET['published_page'] ?? $_POST['published_page'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = t('dashboard.err_csrf');
    } else {
        $action = $_POST['action'] ?? '';
        $commentId = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : 0;
        $context = $_POST['context'] ?? 'pending';
        $comment = fetch_comment_by_id($config, $commentId);

        if (!$comment) {
            $errors[] = t('dashboard.err_not_found');
        } elseif ($action === 'publish') {
            if ($comment['status'] !== 'pending') {
                $errors[] = t('dashboard.err_not_pending');
            } elseif (publish_comment($config, $commentId)) {
                $messages[] = t('dashboard.msg_published');
                if ($comment['parent_id']) {
                    $parent = fetch_comment_by_id($config, (int)$comment['parent_id']);
                    if ($parent && !empty($parent['email_plain'])) {
                        send_reply_notification($config, $parent, $comment);
                    }
                }
            } else {
                $errors[] = t('dashboard.err_publish');
            }
        } elseif ($action === 'delete') {
            $isThreadRoot = (int)($comment['parent_id'] ?? 0) === 0;
            if ($isThreadRoot) {
                if (delete_comment_thread($config, $commentId)) {
                    $messages[] = t('dashboard.msg_thread_deleted');
                } else {
                    $errors[] = t('dashboard.err_delete_thread');
                }
            } elseif (delete_comment($config, $commentId)) {
                $messages[] = $context === 'published' ? t('dashboard.msg_pub_deleted') : t('dashboard.msg_comment_deleted');
            } else {
                $errors[] = t('dashboard.err_delete_comment');
            }
        } elseif ($action === 'reply') {
            $replyContent = trim($_POST['reply_content'] ?? '');
            if ($replyContent === '') {
                $errors[] = t('dashboard.err_reply_empty');
            } else {
                $wasPending = $comment['status'] === 'pending';
                if ($wasPending) {
                    if (!publish_comment($config, $commentId)) {
                        $errors[] = t('dashboard.err_pub_for_reply');
                    } else {
                        $comment = fetch_comment_by_id($config, $commentId) ?? $comment;
                    }
                }

                if (empty($errors)) {
                    $reply = save_author_reply($config, $comment, $replyContent);
                    if ($reply) {
                        $messages[] = $wasPending
                            ? t('dashboard.msg_pub_and_replied')
                            : t('dashboard.msg_reply_posted');
                        if (!empty($comment['email_plain'])) {
                            send_reply_notification($config, $comment, $reply);
                        }
                    } else {
                        $errors[] = t('dashboard.err_save_reply');
                    }
                }
            }
        }
    }
}

$pendingAllComments = fetch_pending_comments($config);
$publishedAllComments = fetch_published_comments_admin($config);

[$pendingComments, $pendingPage, $pendingPages] = paginate_comments_for_admin_view(
    $pendingAllComments,
    $config,
    $perPage,
    $pendingPage
);
[$publishedComments, $publishedPage, $publishedPages] = paginate_comments_for_admin_view(
    $publishedAllComments,
    $config,
    $perPage,
    $publishedPage
);
$csrfToken = $_SESSION['csrf_token'];
$styleVersion = filemtime(__DIR__ . '/public/style.css');

?>
<!DOCTYPE html>
<html lang="<?php echo e(_pc_lang_code()); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo e(t('dashboard.title')); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 128 128'%3E%3Ctext x='50%25' y='70%25' font-size='96' text-anchor='middle'%3E%F0%9F%92%AD%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="<?php echo e(pc_url('/public/style.css', $config)); ?>?v=<?php echo e((string)$styleVersion); ?>">
</head>
<body class="admin">
    <main class="admin-container">
        <div class="admin-top-actions">
            <a class="button" href="<?php echo e(pc_url('/settings.php', $config)); ?>">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo e(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-settings"></use></svg>
                <span><?php echo e(t('dashboard.settings_btn')); ?></span>
            </a>
            <a class="button danger" href="<?php echo e(pc_url('/logout.php', $config)); ?>">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo e(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-logout"></use></svg>
                <span><?php echo e(t('dashboard.logout_btn')); ?></span>
            </a>
        </div>
        <?php foreach ($messages as $message): ?>
            <p class="notice success"><?php echo e($message); ?></p>
        <?php endforeach; ?>

        <?php foreach ($errors as $error): ?>
            <p class="notice error"><?php echo e($error); ?></p>
        <?php endforeach; ?>

        <section class="admin-section" id="pending-comments">
            <h2><?php echo e(t('dashboard.pending_heading')); ?></h2>
            <?php echo render_admin_comments_table($pendingComments, $csrfToken, $config, 'pending', $pendingPage, $publishedPage); ?>
            <?php echo render_admin_pagination($pendingPage, $pendingPages, 'pending', $pendingPage, $publishedPage); ?>
        </section>

        <section class="admin-section" id="published-comments">
            <h2><?php echo e(t('dashboard.published_heading')); ?></h2>
            <?php echo render_admin_comments_table($publishedComments, $csrfToken, $config, 'published', $pendingPage, $publishedPage); ?>
            <?php echo render_admin_pagination($publishedPage, $publishedPages, 'published', $pendingPage, $publishedPage); ?>
        </section>
    </main>
    <script>
        (function () {
            var fields = document.querySelectorAll('textarea.auto-grow');
            fields.forEach(function (field) {
                var grow = function () {
                    field.style.height = 'auto';
                    field.style.height = field.scrollHeight + 'px';
                };
                field.style.overflowY = 'hidden';
                grow();
                field.addEventListener('input', grow);
            });
        })();
    </script>
</body>
</html>
<?php

function send_reply_notification(array $config, array $parent, array $reply): void
{
    if (is_author_comment($config, $parent['email'] ?? null, (string)($parent['name'] ?? ''))) {
        return;
    }

    $to = $parent['email_plain'];
    if ($to === null || $to === '') {
        return;
    }

    $subject = t('notifications.reply_subject');
    $postSlug = $reply['post_slug'] ?? $parent['post_slug'];
    $postTitle = resolve_post_title($postSlug, $config);
    $postUrl = build_post_url($config, $postSlug) . '#comments';
    $bodyText = t('notifications.reply_body', [
        'recipient' => $parent['name'],
        'replier'   => $reply['name'],
        'post'      => $postTitle,
        'reply'     => trim(strip_tags($reply['content_html'])),
        'url'       => $postUrl,
    ]);

    ses_send_email($config, $to, $subject, $bodyText);
}

function save_author_reply(array $config, array $parent, string $content): ?array
{
    $author = $config['author'] ?? [];
    $authorName = trim($author['name'] ?? '');
    $authorEmail = trim($author['email'] ?? '');

    if ($authorName === '' || $authorEmail === '') {
        return null;
    }

    $parsedown = new Parsedown();
    $parsedown->setSafeMode(true);
    $parsedown->setBreaksEnabled(true);

    $replyData = [
        'post_slug' => $parent['post_slug'],
        'parent_id' => $parent['id'],
        'name' => $authorName,
        'email_encrypted' => encrypt_email($authorEmail, $config),
        'website' => null,
        'content_md' => $content,
        'content_html' => $parsedown->text($content),
        'created_at' => gmdate('Y-m-d H:i:s'),
        'status' => 'published',
    ];

    $replyId = insert_comment($config, $replyData);
    return fetch_comment_by_id($config, $replyId);
}

function paginate_comments_for_admin_view(array $comments, array $config, int $perPage, int $page): array
{
    [$primaryComments, $repliesByParent] = split_admin_comments_for_admin_view($comments, $config);

    $totalPrimary = count($primaryComments);
    $totalPages = max(1, (int)ceil($totalPrimary / $perPage));
    $currentPage = max(1, min($page, $totalPages));
    $offset = ($currentPage - 1) * $perPage;

    $primaryPage = array_slice($primaryComments, $offset, $perPage);
    $commentsForRender = $primaryPage;

    foreach ($primaryPage as $primaryComment) {
        $primaryId = (int)$primaryComment['id'];
        foreach (collect_thread_replies_for_render($primaryId, $repliesByParent) as $reply) {
            $commentsForRender[] = $reply;
        }
    }

    return [$commentsForRender, $currentPage, $totalPages];
}

function collect_thread_replies_for_render(int $parentId, array $repliesByParent): array
{
    if (empty($repliesByParent[$parentId])) {
        return [];
    }

    $out = [];
    foreach ($repliesByParent[$parentId] as $reply) {
        $out[] = $reply;
        foreach (collect_thread_replies_for_render((int)$reply['id'], $repliesByParent) as $childReply) {
            $out[] = $childReply;
        }
    }

    return $out;
}
