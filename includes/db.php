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
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_slug_status_type ON comments(post_slug, status, type);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_source_url ON comments(source_url);');
}

function insert_comment(array $config, array $data): int
{
    $pdo = db($config);
    $stmt = $pdo->prepare(
        'INSERT INTO comments (post_slug, parent_id, name, email, website, content_md, content_html, created_at, status, type, source_url, avatar_url)
         VALUES (:post_slug, :parent_id, :name, :email, :website, :content_md, :content_html, :created_at, :status, :type, :source_url, :avatar_url)'
    );
    $stmt->execute([
        ':post_slug' => $data['post_slug'],
        ':parent_id' => $data['parent_id'],
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
    ]);

    return (int)$pdo->lastInsertId();
}

function fetch_published_comments(array $config, string $slug): array
{
    $pdo = db($config);
    $stmt = $pdo->prepare(
        "SELECT id, post_slug, parent_id, name, website, content_html, created_at, email, type, source_url, avatar_url
         FROM comments
         WHERE post_slug = :slug AND status = :status AND type NOT IN ('like', 'repost')
         ORDER BY created_at ASC"
    );
    $stmt->execute([
        ':slug' => $slug,
        ':status' => 'published',
    ]);
    return $stmt->fetchAll() ?: [];
}

function fetch_post_reactions(array $config, string $slug): array
{
    $pdo = db($config);
    $stmt = $pdo->prepare(
        "SELECT id, name, website, avatar_url, source_url, created_at, type
         FROM comments
         WHERE post_slug = :slug AND status = 'published' AND type IN ('like', 'repost')
         ORDER BY created_at ASC"
    );
    $stmt->execute([':slug' => $slug]);
    $rows = $stmt->fetchAll() ?: [];

    $likes = [];
    $reposts = [];
    foreach ($rows as $row) {
        $reaction = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'website' => !empty($row['website']) ? $row['website'] : ($row['source_url'] ?? null),
            'avatar_url' => $row['avatar_url'] ?? null,
            'source_url' => $row['source_url'] ?? null,
            'created_at' => $row['created_at'],
        ];
        if ($row['type'] === 'like') {
            $likes[] = $reaction;
        } elseif ($row['type'] === 'repost') {
            $reposts[] = $reaction;
        }
    }

    return [
        'likes' => $likes,
        'reposts' => $reposts,
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

function insert_or_update_webmention(array $config, array $data): int
{
    $pdo = db($config);
    $existing = fetch_comment_by_source($config, $data['source_url'], $data['post_slug']);
    if ($existing) {
        $stmt = $pdo->prepare(
            'UPDATE comments SET
                name = :name,
                website = :website,
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
            ':avatar_url' => $data['avatar_url'] ?? null,
            ':type' => $data['type'] ?? 'comment',
            ':content_md' => $data['content_md'] ?? '',
            ':content_html' => $data['content_html'] ?? '',
            ':status' => $data['status'] ?? 'published',
        ]);
        return (int)$existing['id'];
    }

    return insert_comment($config, $data);
}

function fetch_comments_by_status(
    array $config,
    string $status,
    string $direction = 'ASC',
    ?int $limit = null,
    int $offset = 0,
    ?string $slug = null,
    ?string $search = null,
    bool $includeReactions = false
): array
{
    $pdo = db($config);
    $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    $offset = max(0, $offset);

    $where = 'WHERE status = :status';
    if (!$includeReactions) {
        $where .= " AND (type IS NULL OR type NOT IN ('like', 'repost'))";
    }
    if ($slug !== null && $slug !== '') {
        $where .= ' AND post_slug = :slug';
    }
    if ($search !== null && $search !== '') {
        $where .= ' AND (name LIKE :search OR content_md LIKE :search)';
    }

    $sql =
        "SELECT id, post_slug, parent_id, name, email, website, content_md, content_html, created_at, status, type, source_url, avatar_url
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

function fetch_pending_comments(array $config, ?int $limit = null, int $offset = 0, ?string $slug = null, ?string $search = null, bool $includeReactions = false): array
{
    return fetch_comments_by_status($config, 'pending', 'ASC', $limit, $offset, $slug, $search, $includeReactions);
}

function fetch_published_comments_admin(array $config, ?int $limit = null, int $offset = 0, ?string $slug = null, ?string $search = null, bool $includeReactions = false): array
{
    return fetch_comments_by_status($config, 'published', 'DESC', $limit, $offset, $slug, $search, $includeReactions);
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
