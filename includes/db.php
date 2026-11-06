<?php
declare(strict_types=1);

function db(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPath = $config['db_path'];
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_slug TEXT NOT NULL,
            parent_id INTEGER DEFAULT NULL,
            name TEXT NOT NULL,
            email TEXT,
            website TEXT,
            content_md TEXT NOT NULL,
            content_html TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('pending','published'))
        );"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS remember_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at INTEGER NOT NULL
        );"
    );
    ensure_schema($pdo);

    return $pdo;
}

function validate_post_slug(string $slug): bool
{
    return preg_match('/^[a-z0-9\-]+$/', $slug) === 1;
}

function sanitize_post_path(string $path, string $expectedSlug): ?string
{
    $parsed = parse_url($path, PHP_URL_PATH);
    if (!is_string($parsed) || $parsed === '') {
        return null;
    }

    if (strlen($parsed) > 300 || str_contains($parsed, '..') || str_contains($parsed, '\\')) {
        return null;
    }

    if (preg_match('#^/[A-Za-z0-9\-_.~%/]*$#', $parsed) !== 1) {
        return null;
    }

    $trimmed = trim($parsed, '/');
    if ($trimmed === '') {
        return null;
    }

    $parts = explode('/', $trimmed);
    $last = rawurldecode((string)end($parts));
    $lastWithoutExt = preg_replace('/\.[a-z0-9]{1,10}$/i', '', $last);
    $norm = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9\-]+/', '-', strtolower($lastWithoutExt))), '-');

    if ($norm !== $expectedSlug) {
        return null;
    }

    $cleanPath = '/' . $trimmed;
    if (str_ends_with($parsed, '/') || !preg_match('/\.[a-z0-9]{1,10}$/i', $last)) {
        $cleanPath .= '/';
    }

    return $cleanPath;
}

function encrypt_email(?string $email, array $config): ?string
{
    if ($email === null || $email === '') {
        return null;
    }
    if (!extension_loaded('sodium')) {
        throw new RuntimeException('libsodium extension is required for email encryption.');
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($email, $nonce, $config['sodium_key']);
    return base64_encode($nonce . $cipher);
}

function decrypt_email(?string $value, array $config): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!extension_loaded('sodium')) {
        throw new RuntimeException('libsodium extension is required for email encryption.');
    }
    $decoded = base64_decode($value, true);
    if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, $config['sodium_key']);
    return $plain === false ? null : $plain;
}

function ensure_schema(PDO $pdo): void
{
    $columns = $pdo->query("PRAGMA table_info(comments)")->fetchAll();
    $names = array_column($columns, 'name');
    if (!in_array('website', $names, true)) {
        $pdo->exec('ALTER TABLE comments ADD COLUMN website TEXT');
    }
    if (!in_array('type', $names, true)) {
        $pdo->exec("ALTER TABLE comments ADD COLUMN type TEXT NOT NULL DEFAULT 'comment'");
    }
    if (!in_array('source_url', $names, true)) {
        $pdo->exec('ALTER TABLE comments ADD COLUMN source_url TEXT DEFAULT NULL');
    }
    if (!in_array('avatar_url', $names, true)) {
        $pdo->exec('ALTER TABLE comments ADD COLUMN avatar_url TEXT DEFAULT NULL');
    }
    if (!in_array('title', $names, true)) {
        $pdo->exec('ALTER TABLE comments ADD COLUMN title TEXT DEFAULT NULL');
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_slug_status_type ON comments(post_slug, status, type);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_source_url ON comments(source_url);');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS post_paths (
            post_slug TEXT PRIMARY KEY,
            post_path TEXT NOT NULL,
            updated_at DATETIME NOT NULL
        );"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS blocked_webmentions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_url TEXT NOT NULL UNIQUE,
            created_at DATETIME NOT NULL
        );"
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_blocked_webmentions_url ON blocked_webmentions(source_url);');

    // Purge legacy Fediverse and Bridgy data (likes, reposts, fedi replies) and all their child replies
    $pdo->exec(
        "WITH RECURSIVE legacy_thread(id) AS (
            SELECT id FROM comments WHERE type IN ('like', 'repost', 'reply') OR (source_url IS NOT NULL AND source_url LIKE '%brid.gy%')
            UNION ALL
            SELECT c.id FROM comments c INNER JOIN legacy_thread t ON c.parent_id = t.id
        )
        DELETE FROM comments WHERE id IN (SELECT id FROM legacy_thread);"
    );
    // Purge any remaining orphaned comments whose parent comment no longer exists
    $pdo->exec(
        "WITH RECURSIVE orphan_thread(id) AS (
            SELECT id FROM comments WHERE parent_id IS NOT NULL AND parent_id NOT IN (SELECT id FROM comments)
            UNION ALL
            SELECT c.id FROM comments c INNER JOIN orphan_thread o ON c.parent_id = o.id
        )
        DELETE FROM comments WHERE id IN (SELECT id FROM orphan_thread);"
    );

    // Reformat all existing mentions in the database to match modern webmentions format
    // (strip images, plain text excerpt truncated to 220 chars wrapped in <p>, type = 'webmention')
    $mentionsStmt = $pdo->query("SELECT id, name, title, type, content_md, content_html FROM comments WHERE type IN ('webmention', 'mention')");
    $existingMentions = $mentionsStmt ? $mentionsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!empty($existingMentions)) {
        $updateMention = $pdo->prepare(
            "UPDATE comments SET
                type = 'webmention',
                title = :title,
                content_md = :content_md,
                content_html = :content_html
             WHERE id = :id"
        );
        foreach ($existingMentions as $wm) {
            $raw = !empty($wm['content_md']) ? (string)$wm['content_md'] : (string)($wm['content_html'] ?? '');
            // Strip markdown image syntax ![alt](url)
            $raw = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $raw);
            // Strip <img> HTML tags
            $raw = preg_replace('/<img\b[^>]*>/i', '', $raw);
            // Strip any remaining HTML tags
            $raw = strip_tags($raw);
            // If source was HTML, decode entities to prevent double-encoding
            if (empty($wm['content_md'])) {
                $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $excerpt = trim(preg_replace('/\s+/', ' ', $raw));
            if (mb_strlen($excerpt) > 220) {
                $excerpt = rtrim(mb_substr($excerpt, 0, 217)) . '…';
            }
            $formattedHtml = $excerpt !== '' ? '<p>' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</p>' : '';
            $title = !empty($wm['title']) ? trim((string)$wm['title']) : null;

            if (
                ($wm['type'] ?? '') !== 'webmention' ||
                ($wm['content_html'] ?? '') !== $formattedHtml ||
                ($wm['content_md'] ?? '') !== $excerpt ||
                ($wm['title'] ?? '') !== $title
            ) {
                $updateMention->execute([
                    ':title' => $title,
                    ':content_md' => $excerpt,
                    ':content_html' => $formattedHtml,
                    ':id' => $wm['id'],
                ]);
            }
        }
    }

    // One-time backfill of titles for legacy mentions that had no title before the title column was added
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schema_migrations (
            migration_key TEXT PRIMARY KEY,
            applied_at DATETIME NOT NULL
        );"
    );

    $backfilled = $pdo->query("SELECT 1 FROM schema_migrations WHERE migration_key = 'backfill_webmention_titles'")->fetchColumn();
    if (!$backfilled) {
        $stmt = $pdo->query("SELECT id, source_url, name FROM comments WHERE type IN ('webmention', 'mention') AND source_url IS NOT NULL");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!empty($rows)) {
            require_once __DIR__ . '/webmention.php';
            $updateTitle = $pdo->prepare("UPDATE comments SET title = :title WHERE id = :id");
            foreach ($rows as $row) {
                $sourceUrl = (string)$row['source_url'];
                $resolvedTitle = null;
                if (is_safe_public_url($sourceUrl) && function_exists('curl_init')) {
                    $ch = curl_init($sourceUrl);
                    if ($ch !== false) {
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: PureComments-Webmention/1.0 (+https://purecommons.org)']);
                        $html = curl_exec($ch);
                        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $sourceUrl;
                        if (is_string($html) && $httpCode >= 200 && $httpCode < 300) {
                            $parsed = parse_microformats_or_html($html, $effectiveUrl, '');
                            if (!empty($parsed['title'])) {
                                $resolvedTitle = trim((string)$parsed['title']);
                            }
                        }
                    }
                }
                if ($resolvedTitle === null || $resolvedTitle === '') {
                    $resolvedTitle = !empty($row['name']) ? trim((string)$row['name']) : 'Mention';
                }
                $updateTitle->execute([
                    ':title' => $resolvedTitle,
                    ':id' => $row['id'],
                ]);
            }
        }
        $pdo->exec("INSERT OR IGNORE INTO schema_migrations (migration_key, applied_at) VALUES ('backfill_webmention_titles', datetime('now'))");
    }
}

function insert_comment(array $config, array $data): int
{
    $pdo = db($config);
    $stmt = $pdo->prepare(
        'INSERT INTO comments (post_slug, parent_id, name, email, website, content_md, content_html, created_at, status, type, source_url, avatar_url, title)
         VALUES (:post_slug, :parent_id, :name, :email, :website, :content_md, :content_html, :created_at, :status, :type, :source_url, :avatar_url, :title)'
    );
    $stmt->execute([
        ':post_slug' => $data['post_slug'],
        ':parent_id' => $data['parent_id'] ?? null,
        ':name' => $data['name'],
        ':email' => $data['email_encrypted'] ?? null,
        ':website' => $data['website'] ?? null,
        ':content_md' => $data['content_md'],
        ':content_html' => $data['content_html'],
        ':created_at' => $data['created_at'],
        ':status' => $data['status'],
        ':type' => $data['type'] ?? 'comment',
        ':source_url' => $data['source_url'] ?? null,
        ':avatar_url' => $data['avatar_url'] ?? null,
        ':title' => $data['title'] ?? null,
    ]);

    return (int)$pdo->lastInsertId();
}

function fetch_published_comments(array $config, string $slug): array
{
    $pdo = db($config);
    $stmt = $pdo->prepare(
        "SELECT id, post_slug, parent_id, name, website, content_html, created_at, email, type, source_url, avatar_url, title
         FROM comments
         WHERE post_slug = :slug AND status = :status AND (type IS NULL OR type = 'comment')
         ORDER BY created_at ASC"
    );
    $stmt->execute([
        ':slug' => $slug,
        ':status' => 'published',
    ]);
    return $stmt->fetchAll() ?: [];
}

function fetch_published_webmentions(array $config, string $slug): array
{
    $pdo = db($config);
    $stmt = $pdo->prepare(
        "SELECT id, post_slug, name, website, title, source_url, content_html, created_at
         FROM comments
         WHERE post_slug = :slug AND status = 'published' AND type IN ('webmention', 'mention')
         ORDER BY created_at ASC"
    );
    $stmt->execute([':slug' => $slug]);
    return $stmt->fetchAll() ?: [];
}

function fetch_post_reactions(array $config, string $slug): array
{
    return [
        'likes' => [],
        'reposts' => [],
    ];
}

function fetch_comment_by_source(array $config, string $sourceUrl, string $slug): ?array
{
    $pdo = db($config);
    $stmt = $pdo->prepare('SELECT * FROM comments WHERE source_url = :source_url AND post_slug = :slug LIMIT 1');
    $stmt->execute([
        ':source_url' => $sourceUrl,
        ':slug' => $slug,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function is_source_url_matched(string $sourceUrl, string $pattern): bool
{
    $pattern = trim($pattern);
    if ($pattern === '') {
        return false;
    }

    $sourceUrl = trim($sourceUrl);
    if ($sourceUrl === '') {
        return false;
    }

    if (strcasecmp(rtrim($sourceUrl, '/'), rtrim($pattern, '/')) === 0) {
        return true;
    }

    $sourceParsed = parse_url($sourceUrl);
    $sourceHost = strtolower($sourceParsed['host'] ?? '');
    if ($sourceHost === '') {
        return false;
    }

    if (preg_match('#^https?://#i', $pattern)) {
        $patternParsed = parse_url($pattern);
        $patternHost = strtolower($patternParsed['host'] ?? '');
        $patternPath = rtrim($patternParsed['path'] ?? '', '/');

        if ($patternPath === '' || $patternPath === '/') {
            if ($sourceHost === $patternHost || str_ends_with($sourceHost, '.' . $patternHost)) {
                return true;
            }
        } else {
            $sourcePath = rtrim($sourceParsed['path'] ?? '', '/');
            if (($sourceHost === $patternHost || str_ends_with($sourceHost, '.' . $patternHost))
                && (str_starts_with($sourcePath, $patternPath) || strcasecmp($sourcePath, $patternPath) === 0)) {
                return true;
            }
        }
    } else {
        $patternClean = ltrim(strtolower($pattern), '*.');
        if (str_contains($patternClean, '/')) {
            $parts = explode('/', $patternClean, 2);
            $patternHost = $parts[0];
            $patternPath = '/' . rtrim($parts[1], '/');
            $sourcePath = rtrim($sourceParsed['path'] ?? '', '/');
            if (($sourceHost === $patternHost || str_ends_with($sourceHost, '.' . $patternHost))
                && (str_starts_with($sourcePath, $patternPath) || strcasecmp($sourcePath, $patternPath) === 0)) {
                return true;
            }
        } else {
            if ($sourceHost === $patternClean || str_ends_with($sourceHost, '.' . $patternClean)) {
                return true;
            }
        }
    }

    return false;
}

function get_blocked_webmentions(array $config): array
{
    $pdo = db($config);
    $stmt = $pdo->query('SELECT source_url FROM blocked_webmentions ORDER BY id DESC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    return is_array($rows) ? $rows : [];
}

function is_webmention_source_blocked(array $config, string $sourceUrl): bool
{
    $sourceUrl = trim($sourceUrl);
    if ($sourceUrl === '') {
        return false;
    }

    $configBlocked = $config['webmentions']['blocked_sources'] ?? [];
    if (is_string($configBlocked)) {
        $configBlocked = preg_split('/[\r\n]+/', $configBlocked);
    }
    if (is_array($configBlocked)) {
        foreach ($configBlocked as $rule) {
            if (is_source_url_matched($sourceUrl, (string)$rule)) {
                return true;
            }
        }
    }

    $dbBlocked = get_blocked_webmentions($config);
    foreach ($dbBlocked as $rule) {
        if (is_source_url_matched($sourceUrl, (string)$rule)) {
            return true;
        }
    }

    return false;
}

function block_webmention_source(array $config, string $sourcePattern): bool
{
    $sourcePattern = trim($sourcePattern);
    if ($sourcePattern === '') {
        return false;
    }
    $pdo = db($config);
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO blocked_webmentions (source_url, created_at) VALUES (:source_url, :created_at)'
    );
    return $stmt->execute([
        ':source_url' => $sourcePattern,
        ':created_at' => gmdate('Y-m-d H:i:s'),
    ]);
}

function unblock_webmention_source(array $config, string $sourcePattern): bool
{
    $sourcePattern = trim($sourcePattern);
    if ($sourcePattern === '') {
        return false;
    }
    $pdo = db($config);
    $stmt = $pdo->prepare('DELETE FROM blocked_webmentions WHERE source_url = :source_url');
    $stmt->execute([':source_url' => $sourcePattern]);
    return $stmt->rowCount() > 0;
}

function save_blocked_webmentions(array $config, array $patterns): void
{
    $pdo = db($config);
    $pdo->exec('DELETE FROM blocked_webmentions');
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO blocked_webmentions (source_url, created_at) VALUES (:source_url, :created_at)');
    $now = gmdate('Y-m-d H:i:s');
    $seen = [];
    foreach ($patterns as $pattern) {
        $p = trim((string)$pattern);
        if ($p !== '' && !isset($seen[$p])) {
            $seen[$p] = true;
            $stmt->execute([
                ':source_url' => $p,
                ':created_at' => $now,
            ]);
        }
    }
}

function insert_or_update_webmention(array $config, array $data): array
{
    $pdo = db($config);
    $existing = fetch_comment_by_source($config, $data['source_url'], $data['post_slug']);
    if ($existing) {
        // Preserve published status so re-pings (e.g. from static site rebuilds) do not demote approved comments
        $finalStatus = ($existing['status'] === 'published') ? 'published' : ($data['status'] ?? 'pending');
        $stmt = $pdo->prepare(
            'UPDATE comments SET
                parent_id = NULL,
                name = :name,
                website = :website,
                title = :title,
                avatar_url = :avatar_url,
                type = :type,
                content_md = :content_md,
                content_html = :content_html,
                status = :status
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $existing['id'],
            ':name' => $data['name'],
            ':website' => $data['website'] ?? null,
            ':title' => $data['title'] ?? null,
            ':avatar_url' => $data['avatar_url'] ?? null,
            ':type' => $data['type'] ?? 'webmention',
            ':content_md' => $data['content_md'] ?? '',
            ':content_html' => $data['content_html'] ?? '',
            ':status' => $finalStatus,
        ]);
        return [
            'id' => (int)$existing['id'],
            'is_new' => false,
            'status' => $finalStatus,
        ];
    }

    $data['type'] = $data['type'] ?? 'webmention';
    $data['parent_id'] = null;
    $id = insert_comment($config, $data);
    return [
        'id' => $id,
        'is_new' => true,
        'status' => $data['status'] ?? 'pending',
    ];
}

function fetch_comments_by_status(
    array $config,
    string $status,
    string $direction = 'ASC',
    ?int $limit = null,
    int $offset = 0,
    ?string $slug = null,
    ?string $search = null
): array
{
    $pdo = db($config);
    $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    $offset = max(0, $offset);

    $where = "WHERE status = :status AND (type IS NULL OR type NOT IN ('like', 'repost'))";
    if ($slug !== null && $slug !== '') {
        $where .= ' AND post_slug = :slug';
    }
    if ($search !== null && $search !== '') {
        $where .= ' AND (name LIKE :search OR content_md LIKE :search OR title LIKE :search)';
    }

    $sql =
        "SELECT id, post_slug, parent_id, name, email, website, content_md, content_html, created_at, status, type, source_url, avatar_url, title
         FROM comments
         {$where}
         ORDER BY created_at {$direction}";

    if ($limit !== null) {
        $limit = max(1, $limit);
        $sql .= ' LIMIT :limit OFFSET :offset';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
    if ($slug !== null && $slug !== '') {
        $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
    }
    if ($search !== null && $search !== '') {
        $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
    }
    if ($limit !== null) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $row['email_plain'] = decrypt_email($row['email'], $config);
    }

    return $rows;
}

function fetch_pending_comments(array $config, ?int $limit = null, int $offset = 0, ?string $slug = null, ?string $search = null): array
{
    return fetch_comments_by_status($config, 'pending', 'ASC', $limit, $offset, $slug, $search);
}

function fetch_published_comments_admin(array $config, ?int $limit = null, int $offset = 0, ?string $slug = null, ?string $search = null): array
{
    return fetch_comments_by_status($config, 'published', 'DESC', $limit, $offset, $slug, $search);
}

function count_comments_by_status(array $config, string $status): int
{
    $pdo = db($config);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM comments WHERE status = :status');
    $stmt->execute([':status' => $status]);
    return (int)$stmt->fetchColumn();
}

function is_author_comment(array $config, ?string $encryptedEmail, string $name): bool
{
    $author = $config['author'] ?? [];
    $authorEmail = $author['email'] ?? '';
    $authorName = $author['name'] ?? '';

    if ($authorEmail !== '') {
        $plain = decrypt_email($encryptedEmail, $config);
        return $plain !== null && strcasecmp($plain, $authorEmail) === 0;
    }

    if ($authorName !== '') {
        return hash_equals($authorName, $name);
    }

    return false;
}

function fetch_comment_by_id(array $config, int $id): ?array
{
    $pdo = db($config);
    $stmt = $pdo->prepare(
        'SELECT * FROM comments WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['email_plain'] = decrypt_email($row['email'], $config);
    return $row;
}

function publish_comment(array $config, int $id): bool
{
    $pdo = db($config);
    $stmt = $pdo->prepare('UPDATE comments SET status = :status WHERE id = :id');
    $stmt->execute([
        ':status' => 'published',
        ':id' => $id,
    ]);
    return $stmt->rowCount() > 0;
}

function delete_comment(array $config, int $id): bool
{
    $pdo = db($config);
    $srcStmt = $pdo->prepare('SELECT source_url FROM comments WHERE id = :id LIMIT 1');
    $srcStmt->execute([':id' => $id]);
    $src = $srcStmt->fetchColumn();
    if (!empty($src)) {
        block_webmention_source($config, (string)$src);
    }

    $stmt = $pdo->prepare('DELETE FROM comments WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}

function delete_comment_thread(array $config, int $rootId): bool
{
    $pdo = db($config);
    $exists = $pdo->prepare('SELECT 1 FROM comments WHERE id = :id LIMIT 1');
    $exists->execute([':id' => $rootId]);
    if (!$exists->fetchColumn()) {
        return false;
    }

    $srcStmt = $pdo->prepare(
        'WITH RECURSIVE thread(id) AS (
            SELECT id FROM comments WHERE id = :root_id
            UNION ALL
            SELECT c.id
            FROM comments c
            INNER JOIN thread t ON c.parent_id = t.id
        )
        SELECT source_url FROM comments WHERE id IN (SELECT id FROM thread) AND source_url IS NOT NULL'
    );
    $srcStmt->execute([':root_id' => $rootId]);
    $sources = $srcStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($sources as $src) {
        if (!empty($src)) {
            block_webmention_source($config, (string)$src);
        }
    }

    $stmt = $pdo->prepare(
        'WITH RECURSIVE thread(id) AS (
            SELECT id FROM comments WHERE id = :root_id
            UNION ALL
            SELECT c.id
            FROM comments c
            INNER JOIN thread t ON c.parent_id = t.id
        )
        DELETE FROM comments
        WHERE id IN (SELECT id FROM thread)'
    );
    $stmt->execute([':root_id' => $rootId]);
    return true;
}

function insert_remember_token(array $config, string $tokenHash, int $expiresAt): void
{
    $pdo = db($config);
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO remember_tokens (token_hash, expires_at) VALUES (:token_hash, :expires_at)');
    $stmt->execute([
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
    ]);
}

function verify_remember_token(array $config, string $tokenHash): bool
{
    $pdo = db($config);
    $stmt = $pdo->prepare('SELECT 1 FROM remember_tokens WHERE token_hash = :token_hash AND expires_at > :now LIMIT 1');
    $stmt->execute([
        ':token_hash' => $tokenHash,
        ':now'        => time(),
    ]);
    return (bool)$stmt->fetchColumn();
}

function delete_remember_token(array $config, string $tokenHash): void
{
    $pdo = db($config);
    $stmt = $pdo->prepare('DELETE FROM remember_tokens WHERE token_hash = :token_hash');
    $stmt->execute([':token_hash' => $tokenHash]);
}

function &post_paths_cache(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
    }
    return $cache;
}

function fetch_post_paths(array $config): array
{
    $cache = &post_paths_cache();
    static $loaded = false;
    if (!$loaded) {
        $pdo = db($config);
        $rows = $pdo->query('SELECT post_slug, post_path FROM post_paths')->fetchAll();
        foreach ($rows as $row) {
            $cache[$row['post_slug']] = (string)$row['post_path'];
        }
        $loaded = true;
    }
    return $cache;
}

function fetch_post_path(array $config, string $slug): ?string
{
    $paths = fetch_post_paths($config);
    return $paths[$slug] ?? null;
}

function record_post_path(array $config, string $slug, string $path): void
{
    $pdo = db($config);
    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO post_paths (post_slug, post_path, updated_at) VALUES (:slug, :path, :updated_at)');
    $stmt->execute([
        ':slug'       => $slug,
        ':path'       => $path,
        ':updated_at' => $now,
    ]);
    $cache = &post_paths_cache();
    $cache[$slug] = $path;
}
