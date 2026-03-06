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
}

function insert_comment(array $config, array $data): int
{
    $pdo = db($config);
    $stmt = $pdo->prepare(
        'INSERT INTO comments (post_slug, parent_id, name, email, website, content_md, content_html, created_at, status)
         VALUES (:post_slug, :parent_id, :name, :email, :website, :content_md, :content_html, :created_at, :status)'
    );
    $stmt->execute([
        ':post_slug' => $data['post_slug'],
        ':parent_id' => $data['parent_id'],
        ':name' => $data['name'],
        ':email' => $data['email_encrypted'],
        ':website' => $data['website'],
        ':content_md' => $data['content_md'],
        ':content_html' => $data['content_html'],
        ':created_at' => $data['created_at'],
        ':status' => $data['status'],
    ]);

    return (int)$pdo->lastInsertId();
}

function fetch_published_comments(array $config, string $slug): array
{
    $pdo = db($config);
    $stmt = $pdo->prepare(
        'SELECT id, post_slug, parent_id, name, website, content_html, created_at, email
         FROM comments
         WHERE post_slug = :slug AND status = :status
         ORDER BY created_at ASC'
    );
    $stmt->execute([
        ':slug' => $slug,
        ':status' => 'published',
    ]);
    return $stmt->fetchAll() ?: [];
}

function fetch_comments_by_status(
    array $config,
    string $status,
    string $direction = 'ASC',
    ?int $limit = null,
    int $offset = 0
): array
{
    $pdo = db($config);
    $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    $offset = max(0, $offset);
    $sql = 
        "SELECT id, post_slug, parent_id, name, email, website, content_md, content_html, created_at, status
         FROM comments
         WHERE status = :status
         ORDER BY created_at {$direction}";

    if ($limit !== null) {
        $limit = max(1, $limit);
        $sql .= ' LIMIT :limit OFFSET :offset';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
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

function fetch_pending_comments(array $config, ?int $limit = null, int $offset = 0): array
{
    return fetch_comments_by_status($config, 'pending', 'ASC', $limit, $offset);
}

function fetch_published_comments_admin(array $config, ?int $limit = null, int $offset = 0): array
{
    return fetch_comments_by_status($config, 'published', 'DESC', $limit, $offset);
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
        if ($plain !== null && strcasecmp($plain, $authorEmail) === 0) {
            return true;
        }
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
