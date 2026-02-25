<?php
declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function build_comment_tree(array $comments): array
{
    $indexed = [];
    foreach ($comments as $comment) {
        $comment['children'] = [];
        $indexed[$comment['id']] = $comment;
    }

    $tree = [];
    foreach ($indexed as $id => &$comment) {
        $parentId = $comment['parent_id'];
        if ($parentId !== null && isset($indexed[$parentId])) {
            $indexed[$parentId]['children'][] = &$comment;
        } else {
            $tree[] = &$comment;
        }
    }

    return $tree;
}

function resolve_post_title(string $slug, array $config): string
{
    if (!empty($config['post_titles'][$slug])) {
        return $config['post_titles'][$slug];
    }
    $fallback = str_replace('-', ' ', $slug);
    return ucwords($fallback);
}

function build_post_url(array $config, string $slug): string
{
    $base = rtrim($config['post_base_url'] ?? '/blog', '/');
    return $base . '/' . rawurlencode($slug) . '/';
}

function render_admin_comments_table(
    array $comments,
    string $csrfToken,
    array $config,
    string $context,
    int $pendingPage,
    int $publishedPage
): string
{
    [$primaryComments, $repliesByParent] = split_admin_comments_for_admin_view($comments, $config);

    ob_start();
    ?>
    <?php if (empty($primaryComments)) : ?>
        <p class="comments-empty">
            <?php echo e($context === 'published' ? 'No published comments.' : 'No pending comments.'); ?>
        </p>
    <?php else : ?>
        <div class="comments-admin-headings" aria-hidden="true">
            <span>Author</span>
            <span>Comment</span>
            <span>Response To</span>
            <span>Date</span>
        </div>
        <div class="comments-admin-list">
            <?php foreach ($primaryComments as $index => $comment) : ?>
                <?php
                    $postTitle = resolve_post_title($comment['post_slug'], $config);
                    $postUrl = build_post_url($config, $comment['post_slug']);
                    $parent = null;
                    if (!empty($comment['parent_id'])) {
                        $parent = fetch_comment_by_id($config, (int)$comment['parent_id']);
                    }
                ?>
                <details class="admin-comment-item<?php echo $index % 2 === 1 ? ' accent-bg' : ''; ?>" id="comment-<?php echo e((string)$comment['id']); ?>" name="comment">
                    <summary>
                        <span class="comment-summary-author">
                            <strong>
                                <?php if (!empty($comment['website'])) : ?>
                                    <a href="<?php echo e($comment['website']); ?>" target="_blank" rel="noopener">
                                        <?php echo e($comment['name']); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo e($comment['name']); ?>
                                <?php endif; ?>
                            </strong>
                            <?php if (!empty($comment['email_plain'])) : ?>
                                <span class="comment-author-email"><?php echo e($comment['email_plain']); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="comment-summary-preview"><?php echo e(admin_comment_preview_text($comment['content_html'])); ?></span>
                        <span class="comment-summary-response">
                            <?php if ($parent) : ?>
                                <span>Reply to <?php echo e($parent['name']); ?></span>
                            <?php endif; ?>
                            <a href="<?php echo e($postUrl); ?>" target="_blank" rel="noopener">
                                <?php echo e($postTitle); ?>
                            </a>
                        </span>
                        <time datetime="<?php echo e(str_replace(' ', 'T', $comment['created_at']) . 'Z'); ?>">
                            <?php echo e(format_admin_datetime($comment['created_at'])); ?>
                        </time>
                    </summary>

                    <div class="admin-comment-detail">
                        <?php if ($parent) : ?>
                            <div class="admin-comment-parent">
                                <p><strong>In reply to <?php echo e($parent['name']); ?>:</strong></p>
                                <div class="comment-body"><?php echo $parent['content_html']; ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="admin-comment-body">
                            <?php echo $comment['content_html']; ?>
                        </div>

                        <?php echo render_admin_author_replies(
                            (int)$comment['id'],
                            $repliesByParent,
                            $config,
                            $csrfToken,
                            $context,
                            $pendingPage,
                            $publishedPage
                        ); ?>

                        <form method="post" class="admin-action">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="comment_id" value="<?php echo e((string)$comment['id']); ?>">
                            <input type="hidden" name="context" value="<?php echo e($context); ?>">
                            <input type="hidden" name="pending_page" value="<?php echo e((string)$pendingPage); ?>">
                            <input type="hidden" name="published_page" value="<?php echo e((string)$publishedPage); ?>">
                            <?php if ($context === 'pending') : ?>
                                <button type="submit" name="action" value="publish">
                                    <svg class="button-icon" aria-hidden="true" focusable="false"><use href="/public/icons/sprite.svg#icon-login"></use></svg>
                                    <span>Publish</span>
                                </button>
                            <?php endif; ?>
                            <?php $isThreadRoot = empty($comment['parent_id']); ?>
                            <button
                                type="submit"
                                name="action"
                                value="delete"
                                class="danger"
                                onclick="return confirm('<?php echo e($isThreadRoot ? 'Delete this thread and all replies?' : 'Delete this comment?'); ?>');"
                            >
                                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="/public/icons/sprite.svg#icon-delete"></use></svg>
                                <span><?php echo e($isThreadRoot ? 'Delete thread' : 'Delete'); ?></span>
                            </button>
                        </form>

                        <form method="post" class="admin-reply">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="comment_id" value="<?php echo e((string)$comment['id']); ?>">
                            <input type="hidden" name="context" value="<?php echo e($context); ?>">
                            <input type="hidden" name="pending_page" value="<?php echo e((string)$pendingPage); ?>">
                            <input type="hidden" name="published_page" value="<?php echo e((string)$publishedPage); ?>">
                            <label>
                                <span>Reply as author</span>
                                <textarea name="reply_content" rows="3" placeholder="Write a reply..." class="auto-grow"></textarea>
                            </label>
                            <button type="submit" name="action" value="reply">
                                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="/public/icons/sprite.svg#icon-reply"></use></svg>
                                <span>Send reply</span>
                            </button>
                        </form>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}

function split_admin_comments_for_admin_view(array $comments, array $config): array
{
    $primaryComments = [];
    $repliesByParent = [];
    $availableIds = [];

    foreach ($comments as $comment) {
        $availableIds[(int)$comment['id']] = true;
    }

    foreach ($comments as $comment) {
        $isAuthor = is_author_comment($config, $comment['email'] ?? null, $comment['name']);
        $parentId = (int)($comment['parent_id'] ?? 0);

        if ($parentId > 0) {
            if (isset($availableIds[$parentId])) {
                if (!isset($repliesByParent[$parentId])) {
                    $repliesByParent[$parentId] = [];
                }
                $repliesByParent[$parentId][] = $comment;
                continue;
            }
            // Parent is outside the current status/page dataset, so keep visible as a primary item.
            $primaryComments[] = $comment;
            continue;
        }

        if ($isAuthor) {
            continue;
        }

        $primaryComments[] = $comment;
    }

    return [$primaryComments, $repliesByParent];
}

function render_admin_author_replies(
    int $parentCommentId,
    array $repliesByParent,
    array $config,
    string $csrfToken,
    string $context,
    int $pendingPage,
    int $publishedPage
): string
{
    $flatReplies = flatten_admin_replies_for_thread($parentCommentId, $repliesByParent);
    if (empty($flatReplies)) {
        return '';
    }

    ob_start();
    ?>
    <div class="admin-author-replies">
        <?php foreach ($flatReplies as $reply) : ?>
            <?php $isAuthorReply = is_author_comment($config, $reply['email'] ?? null, $reply['name']); ?>
            <article class="admin-author-reply">
                <div class="admin-author-reply-meta">
                    <strong><?php echo e($reply['name']); ?></strong>
                    <?php if ($isAuthorReply) : ?>
                        <span class="author-badge">You!</span>
                    <?php endif; ?>
                    <time datetime="<?php echo e(str_replace(' ', 'T', $reply['created_at']) . 'Z'); ?>">
                        <?php echo e(format_admin_datetime($reply['created_at'])); ?>
                    </time>
                </div>
                <div class="admin-author-reply-body">
                    <?php echo $reply['content_html']; ?>
                </div>
                <form method="post" class="admin-author-reply-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="comment_id" value="<?php echo e((string)$reply['id']); ?>">
                    <input type="hidden" name="context" value="<?php echo e($context); ?>">
                    <input type="hidden" name="pending_page" value="<?php echo e((string)$pendingPage); ?>">
                    <input type="hidden" name="published_page" value="<?php echo e((string)$publishedPage); ?>">
                    <?php if ($context === 'pending') : ?>
                        <button type="submit" name="action" value="publish">
                            <svg class="button-icon" aria-hidden="true" focusable="false"><use href="/public/icons/sprite.svg#icon-login"></use></svg>
                            <span>Publish</span>
                        </button>
                    <?php endif; ?>
                    <button type="submit" name="action" value="delete" class="danger" onclick="return confirm('Delete this reply?');">
                        <svg class="button-icon" aria-hidden="true" focusable="false"><use href="/public/icons/sprite.svg#icon-delete"></use></svg>
                        <span>Delete reply</span>
                    </button>
                </form>

                <?php if (!$isAuthorReply) : ?>
                    <form method="post" class="admin-reply">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                        <input type="hidden" name="comment_id" value="<?php echo e((string)$reply['id']); ?>">
                        <input type="hidden" name="context" value="<?php echo e($context); ?>">
                        <input type="hidden" name="pending_page" value="<?php echo e((string)$pendingPage); ?>">
                        <input type="hidden" name="published_page" value="<?php echo e((string)$publishedPage); ?>">
                        <label>
                            <span>Reply as author</span>
                            <textarea name="reply_content" rows="2" placeholder="Write a reply..." class="auto-grow"></textarea>
                        </label>
                        <button type="submit" name="action" value="reply">
                            <svg class="button-icon" aria-hidden="true" focusable="false"><use href="/public/icons/sprite.svg#icon-reply"></use></svg>
                            <span>Send reply</span>
                        </button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

function flatten_admin_replies_for_thread(int $rootCommentId, array $repliesByParent): array
{
    $flat = [];
    $stack = [$rootCommentId];

    while (!empty($stack)) {
        $currentParent = array_pop($stack);
        if (empty($repliesByParent[$currentParent])) {
            continue;
        }

        foreach ($repliesByParent[$currentParent] as $reply) {
            $flat[] = $reply;
            $stack[] = (int)$reply['id'];
        }
    }

    usort($flat, static function (array $a, array $b): int {
        $timeA = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $timeB = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        if ($timeA === $timeB) {
            return ((int)$a['id']) <=> ((int)$b['id']);
        }
        return $timeA <=> $timeB;
    });

    return $flat;
}

function render_admin_pagination(
    int $currentPage,
    int $totalPages,
    string $target,
    int $pendingPage,
    int $publishedPage
): string
{
    if ($totalPages <= 1) {
        return '';
    }

    $targetIsPending = $target === 'pending';
    $anchor = $targetIsPending ? '#pending-comments' : '#published-comments';
    $windowStart = max(1, $currentPage - 2);
    $windowEnd = min($totalPages, $currentPage + 2);

    $buildHref = static function (int $page) use ($targetIsPending, $pendingPage, $publishedPage, $anchor): string {
        $query = [
            'pending_page' => $targetIsPending ? $page : $pendingPage,
            'published_page' => $targetIsPending ? $publishedPage : $page,
        ];
        return '?' . http_build_query($query) . $anchor;
    };

    ob_start();
    ?>
    <nav class="admin-pagination" aria-label="Comments pagination">
        <?php if ($currentPage > 1) : ?>
            <a href="<?php echo e($buildHref($currentPage - 1)); ?>" class="pagination-link">Previous</a>
        <?php endif; ?>

        <?php for ($page = $windowStart; $page <= $windowEnd; $page++) : ?>
            <?php if ($page === $currentPage) : ?>
                <span class="pagination-link current" aria-current="page"><?php echo e((string)$page); ?></span>
            <?php else : ?>
                <a href="<?php echo e($buildHref($page)); ?>" class="pagination-link"><?php echo e((string)$page); ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($currentPage < $totalPages) : ?>
            <a href="<?php echo e($buildHref($currentPage + 1)); ?>" class="pagination-link">Next</a>
        <?php endif; ?>
    </nav>
    <?php
    return (string)ob_get_clean();
}

function admin_comment_preview_text(string $html, int $maxLength = 170): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim((string)preg_replace('/\s+/', ' ', $text));
    if ($text === '') {
        return '[No content]';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text, 'UTF-8') <= $maxLength) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $maxLength - 1, 'UTF-8')) . '…';
    }

    if (strlen($text) <= $maxLength) {
        return $text;
    }
    return rtrim(substr($text, 0, $maxLength - 1)) . '…';
}

function format_admin_datetime(string $value): string
{
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return preg_replace('/:\d{2}$/', '', $value) ?? $value;
    }
    return gmdate('Y-m-d H:i', $timestamp);
}
