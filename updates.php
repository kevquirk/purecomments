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
require_once __DIR__ . '/includes/i18n.php';
pc_set_language((string)($config['language'] ?? 'en'));

require_admin_login($config);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

const PURECOMMENTS_BASE_PATH = __DIR__;

function fetch_latest_purecomments_release(): array
{
    $endpoint = 'https://api.github.com/repos/kevquirk/purecomments/releases/latest';
    $headers = [
        'User-Agent: PureComments-Updates-Check',
        'Accept: application/vnd.github+json',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['ok' => false, 'error' => t('updates.err_curl_init')];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $status < 200 || $status >= 300) {
            $message = $curlErr !== '' ? $curlErr : t('updates.err_github_failed', ['status' => $status]);
            return ['ok' => false, 'error' => $message];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'header' => implode("\r\n", $headers),
            ],
        ]);

        $raw = @file_get_contents($endpoint, false, $context);
        if (!is_string($raw)) {
            return ['ok' => false, 'error' => t('updates.err_github_network')];
        }
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => t('updates.err_github_json')];
    }

    return [
        'ok' => true,
        'tag' => (string) ($json['tag_name'] ?? ''),
        'name' => (string) ($json['name'] ?? ''),
        'url' => (string) ($json['html_url'] ?? 'https://github.com/kevquirk/purecomments/releases'),
        'zipball_url' => (string) ($json['zipball_url'] ?? ''),
        'published_at' => (string) ($json['published_at'] ?? ''),
    ];
}

function detect_current_purecomments_version(): string
{
    $versionFile = PURECOMMENTS_BASE_PATH . '/VERSION';
    if (is_file($versionFile)) {
        $raw = @file_get_contents($versionFile);
        if (is_string($raw)) {
            $fromFile = trim($raw);
            if ($fromFile !== '') {
                return $fromFile;
            }
        }
    }

    return 'unknown';
}

function normalize_version_label(string $version): string
{
    $trimmed = trim($version);
    if ($trimmed === '') {
        return 'unknown';
    }

    return ltrim($trimmed, "vV");
}

function versions_match(string $current, string $latest): bool
{
    $a = ltrim(strtolower(trim($current)), 'v');
    $b = ltrim(strtolower(trim($latest)), 'v');

    if ($a === '' || $b === '') {
        return false;
    }

    return $a === $b;
}

function preserved_top_level_paths(): array
{
    return [
        'config.php',
        'db',
        'setup.php',
        '.htaccess',
        'VERSION',
        'backup',
    ];
}

function core_top_level_paths(): array
{
    return [
        '.gitignore',
        '.htaccess',
        'VERSION',
        'README.md',
        'api',
        'includes',
        'index.php',
        'lang',
        'login.php',
        'logout.php',
        'public',
        'settings.php',
        'setup.php',
        'updates.php',
    ];
}

function remove_directory_recursive(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }

    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $itemPath = $path . '/' . $item;
        if (is_dir($itemPath)) {
            remove_directory_recursive($itemPath);
        } else {
            @unlink($itemPath);
        }
    }

    @rmdir($path);
}

function download_url_to_file(string $url, string $destination): ?string
{
    $headers = [
        'User-Agent: PureComments-Upgrader',
        'Accept: */*',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return 'Unable to initialize curl.';
        }

        $fp = @fopen($destination, 'wb');
        if ($fp === false) {
            curl_close($ch);
            return 'Unable to create temporary download file.';
        }

        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        fclose($fp);
        curl_close($ch);

        if ($ok !== true || $status < 200 || $status >= 300) {
            return $curlErr !== '' ? $curlErr : t('updates.err_curl_download', ['status' => $status]);
        }

        return null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'header' => implode("\r\n", $headers),
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw)) {
        return t('updates.err_curl_download_net');
    }

    if (@file_put_contents($destination, $raw) === false) {
        return 'Unable to write temporary download file.';
    }

    return null;
}

function collect_relative_files(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }

        $fullPath = str_replace('\\', '/', $item->getPathname());
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if (!str_starts_with($fullPath, $prefix)) {
            continue;
        }

        $relative = substr($fullPath, strlen($prefix));
        if ($relative === '' || str_starts_with($relative, '.git/')) {
            continue;
        }

        $files[] = $relative;
    }

    sort($files);
    return $files;
}

function is_htaccess_path(string $relativePath): bool
{
    return basename(str_replace('\\', '/', $relativePath)) === '.htaccess';
}

function collect_existing_htaccess_files(): array
{
    $files = [];
    $all = collect_relative_files(PURECOMMENTS_BASE_PATH);
    foreach ($all as $relative) {
        if (!is_htaccess_path($relative)) {
            continue;
        }

        $fullPath = PURECOMMENTS_BASE_PATH . '/' . $relative;
        $content = @file_get_contents($fullPath);
        if (!is_string($content)) {
            continue;
        }

        $files[$relative] = $content;
    }

    return $files;
}

function restore_htaccess_files(array $files): void
{
    foreach ($files as $relative => $content) {
        $target = PURECOMMENTS_BASE_PATH . '/' . $relative;
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create directory for preserved .htaccess: ' . $relative);
        }

        if (@file_put_contents($target, $content) === false) {
            throw new RuntimeException('Unable to restore preserved .htaccess: ' . $relative);
        }
    }
}

function remove_non_preserved_htaccess(array $preservedFiles): void
{
    $preservedSet = array_fill_keys(array_keys($preservedFiles), true);
    $all = collect_relative_files(PURECOMMENTS_BASE_PATH);
    foreach ($all as $relative) {
        if (!is_htaccess_path($relative)) {
            continue;
        }

        if (isset($preservedSet[$relative])) {
            continue;
        }

        @unlink(PURECOMMENTS_BASE_PATH . '/' . $relative);
    }
}

function build_package_upgrade_plan(string $zipballUrl): array
{
    if ($zipballUrl === '') {
        return ['ok' => false, 'error' => t('updates.err_no_zipball')];
    }
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => t('updates.err_no_ziparchive')];
    }

    $tmpBase = rtrim(sys_get_temp_dir(), '/') . '/purecomments-upgrader-' . bin2hex(random_bytes(6));
    $tmpZip = $tmpBase . '.zip';
    $tmpExtract = $tmpBase . '-extract';
    @mkdir($tmpExtract, 0700, true);

    try {
        $downloadError = download_url_to_file($zipballUrl, $tmpZip);
        if ($downloadError !== null) {
            return ['ok' => false, 'error' => $downloadError];
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            return ['ok' => false, 'error' => t('updates.err_open_zip')];
        }
        if (!$zip->extractTo($tmpExtract)) {
            $zip->close();
            return ['ok' => false, 'error' => t('updates.err_extract_zip')];
        }
        $zip->close();

        $entries = array_values(array_filter(scandir($tmpExtract) ?: [], static fn(string $e): bool => $e !== '.' && $e !== '..'));
        $sourceRoot = $tmpExtract;
        if (count($entries) === 1 && is_dir($tmpExtract . '/' . $entries[0])) {
            $sourceRoot = $tmpExtract . '/' . $entries[0];
        }

        $sourceFiles = collect_relative_files($sourceRoot);
        if (!$sourceFiles) {
            return ['ok' => false, 'error' => t('updates.err_no_files')];
        }

        $preserveTop = preserved_top_level_paths();
        $willAdd = [];
        $willReplace = [];
        $unchanged = [];
        $willSkip = [];
        $sourceCoreSet = [];

        foreach ($sourceFiles as $relative) {
            if (is_htaccess_path($relative)) {
                $willSkip[] = '/' . $relative;
                continue;
            }

            $top = strtok($relative, '/');
            if (in_array($top, $preserveTop, true)) {
                $willSkip[] = '/' . $relative;
                continue;
            }

            $sourceCoreSet[$relative] = true;
            $sourcePath = $sourceRoot . '/' . $relative;
            $targetPath = PURECOMMENTS_BASE_PATH . '/' . $relative;

            if (is_file($targetPath)) {
                $same = @sha1_file($sourcePath) === @sha1_file($targetPath);
                if ($same) {
                    $unchanged[] = '/' . $relative;
                } else {
                    $willReplace[] = '/' . $relative;
                }
            } else {
                $willAdd[] = '/' . $relative;
            }
        }

        $localOnly = [];
        $localCoreTop = array_fill_keys(core_top_level_paths(), true);
        $localFiles = collect_relative_files(PURECOMMENTS_BASE_PATH);
        foreach ($localFiles as $relative) {
            $top = strtok($relative, '/');
            if (!isset($localCoreTop[$top])) {
                continue;
            }
            if (is_htaccess_path($relative)) {
                continue;
            }
            if (in_array($top, $preserveTop, true)) {
                continue;
            }
            if (!isset($sourceCoreSet[$relative])) {
                $localOnly[] = '/' . $relative;
            }
        }

        sort($willAdd);
        sort($willReplace);
        sort($unchanged);
        sort($willSkip);
        sort($localOnly);

        return [
            'ok' => true,
            'counts' => [
                'add' => count($willAdd),
                'replace' => count($willReplace),
                'unchanged' => count($unchanged),
                'skip' => count($willSkip),
                'local_only' => count($localOnly),
            ],
            'will_add' => $willAdd,
            'will_replace' => $willReplace,
            'unchanged' => $unchanged,
            'will_skip' => $willSkip,
            'local_only' => $localOnly,
        ];
    } finally {
        @unlink($tmpZip);
        remove_directory_recursive($tmpExtract);
    }
}

function copy_path_recursive(string $source, string $destination): void
{
    if (is_file($source)) {
        $parent = dirname($destination);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('Unable to create directory: ' . $parent);
        }
        if (!@copy($source, $destination)) {
            throw new RuntimeException('Unable to copy file: ' . $source);
        }
        return;
    }

    if (!is_dir($source)) {
        throw new RuntimeException('Source path not found: ' . $source);
    }

    if (!is_dir($destination) && !@mkdir($destination, 0755, true) && !is_dir($destination)) {
        throw new RuntimeException('Unable to create directory: ' . $destination);
    }

    $items = scandir($source);
    if (!is_array($items)) {
        throw new RuntimeException('Unable to read directory: ' . $source);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $src = $source . '/' . $item;
        $dst = $destination . '/' . $item;
        if (is_dir($src)) {
            copy_path_recursive($src, $dst);
        } else {
            if (!@copy($src, $dst)) {
                throw new RuntimeException('Unable to copy file: ' . $src);
            }
        }
    }
}

function backup_core_paths(string $backupRoot): void
{
    foreach (core_top_level_paths() as $relative) {
        $src = PURECOMMENTS_BASE_PATH . '/' . $relative;
        if (!file_exists($src)) {
            continue;
        }

        $dst = $backupRoot . '/' . $relative;
        copy_path_recursive($src, $dst);
    }
}

function restore_core_paths_from_backup(string $backupRoot): void
{
    foreach (core_top_level_paths() as $relative) {
        $target = PURECOMMENTS_BASE_PATH . '/' . $relative;
        if (file_exists($target)) {
            remove_directory_recursive($target);
        }

        $backup = $backupRoot . '/' . $relative;
        if (file_exists($backup)) {
            copy_path_recursive($backup, $target);
        }
    }
}

function list_available_backups(): array
{
    $backupBase = PURECOMMENTS_BASE_PATH . '/backup';
    if (!is_dir($backupBase)) {
        return [];
    }

    $entries = scandir($backupBase);
    if (!is_array($entries)) {
        return [];
    }

    $backups = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (!str_starts_with($entry, 'purecomments-backup-')) {
            continue;
        }

        $fullPath = $backupBase . '/' . $entry;
        if (is_dir($fullPath)) {
            $backups[] = $entry;
        }
    }

    rsort($backups);
    return $backups;
}

function format_backup_timestamp(string $backupName): string
{
    if (preg_match('/^purecomments-backup-(\d{8})-(\d{6})-/', $backupName, $matches) !== 1) {
        return '';
    }

    $dt = DateTime::createFromFormat('Ymd His', $matches[1] . ' ' . $matches[2]);
    if (!$dt) {
        return '';
    }

    return $dt->format('d M Y H:i:s');
}

function restore_named_backup(string $backupName): array
{
    if ($backupName === '' || $backupName !== basename($backupName)) {
        return ['ok' => false, 'error' => t('updates.err_invalid_backup')];
    }

    $backupBase = PURECOMMENTS_BASE_PATH . '/backup';
    $backupBaseReal = realpath($backupBase);
    if ($backupBaseReal === false) {
        return ['ok' => false, 'error' => t('updates.err_backup_dir')];
    }

    $backupPath = $backupBaseReal . '/' . $backupName;
    $backupPathReal = realpath($backupPath);
    if ($backupPathReal === false || !is_dir($backupPathReal)) {
        return ['ok' => false, 'error' => t('updates.err_backup_not_found')];
    }
    if (!str_starts_with($backupPathReal, $backupBaseReal . '/')) {
        return ['ok' => false, 'error' => t('updates.err_backup_path')];
    }

    try {
        restore_core_paths_from_backup($backupPathReal);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => t('updates.err_restore_failed', ['error' => $e->getMessage()])];
    }

    return [
        'ok' => true,
        'message' => t('updates.msg_backup_restored'),
        'backup_path' => $backupPathReal,
    ];
}

function delete_named_backup(string $backupName): array
{
    if ($backupName === '' || $backupName !== basename($backupName)) {
        return ['ok' => false, 'error' => t('updates.err_invalid_backup')];
    }

    $backupBase = PURECOMMENTS_BASE_PATH . '/backup';
    $backupBaseReal = realpath($backupBase);
    if ($backupBaseReal === false) {
        return ['ok' => false, 'error' => t('updates.err_backup_dir')];
    }

    $backupPath = $backupBaseReal . '/' . $backupName;
    $backupPathReal = realpath($backupPath);
    if ($backupPathReal === false || !is_dir($backupPathReal)) {
        return ['ok' => false, 'error' => t('updates.err_backup_not_found')];
    }
    if (!str_starts_with($backupPathReal, $backupBaseReal . '/')) {
        return ['ok' => false, 'error' => t('updates.err_backup_path')];
    }

    try {
        remove_directory_recursive($backupPathReal);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => t('updates.err_delete_failed', ['error' => $e->getMessage()])];
    }

    return [
        'ok' => true,
        'message' => t('updates.msg_backup_deleted'),
    ];
}

function apply_release_update(string $zipballUrl, string $releaseTag = ''): array
{
    if ($zipballUrl === '') {
        return ['ok' => false, 'error' => 'No zipball URL found for this release.'];
    }
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'ZipArchive extension is not available on this host.'];
    }

    $tmpBase = rtrim(sys_get_temp_dir(), '/') . '/purecomments-upgrader-' . bin2hex(random_bytes(6));
    $tmpZip = $tmpBase . '.zip';
    $tmpExtract = $tmpBase . '-extract';
    $backupBase = PURECOMMENTS_BASE_PATH . '/backup';

    if (!is_dir($backupBase) && !@mkdir($backupBase, 0755, true) && !is_dir($backupBase)) {
        return ['ok' => false, 'error' => t('updates.err_backup_create')];
    }

    $versionRaw = detect_current_purecomments_version();
    $versionSlug = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $versionRaw);
    if (!is_string($versionSlug) || $versionSlug === '') {
        $versionSlug = 'unknown';
    }

    $tmpBackup = $backupBase . '/purecomments-backup-' . date('Ymd-His') . '-' . $versionSlug . '-' . bin2hex(random_bytes(4));
    @mkdir($tmpExtract, 0700, true);
    @mkdir($tmpBackup, 0700, true);

    try {
        $downloadError = download_url_to_file($zipballUrl, $tmpZip);
        if ($downloadError !== null) {
            return ['ok' => false, 'error' => $downloadError];
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            return ['ok' => false, 'error' => t('updates.err_open_zip')];
        }
        if (!$zip->extractTo($tmpExtract)) {
            $zip->close();
            return ['ok' => false, 'error' => t('updates.err_extract_zip')];
        }
        $zip->close();

        $entries = array_values(array_filter(scandir($tmpExtract) ?: [], static fn(string $e): bool => $e !== '.' && $e !== '..'));
        $sourceRoot = $tmpExtract;
        if (count($entries) === 1 && is_dir($tmpExtract . '/' . $entries[0])) {
            $sourceRoot = $tmpExtract . '/' . $entries[0];
        }

        if (!is_file($sourceRoot . '/api/index.php') || !is_file($sourceRoot . '/public/embed.js')) {
            return ['ok' => false, 'error' => t('updates.err_invalid_package')];
        }

        $preservedHtaccessFiles = collect_existing_htaccess_files();
        backup_core_paths($tmpBackup);

        $corePaths = core_top_level_paths();
        $preserveTop = preserved_top_level_paths();

        foreach ($corePaths as $relative) {
            if (in_array($relative, $preserveTop, true)) {
                continue;
            }

            $source = $sourceRoot . '/' . $relative;
            $target = PURECOMMENTS_BASE_PATH . '/' . $relative;

            if (file_exists($target)) {
                remove_directory_recursive($target);
            }

            if (file_exists($source)) {
                copy_path_recursive($source, $target);
            }
        }

        $versionFile = PURECOMMENTS_BASE_PATH . '/VERSION';
        $versionFromTag = normalize_version_label($releaseTag);
        if ($versionFromTag !== 'unknown') {
            @file_put_contents($versionFile, $versionFromTag . PHP_EOL);
        }

        restore_htaccess_files($preservedHtaccessFiles);
        remove_non_preserved_htaccess($preservedHtaccessFiles);

        return [
            'ok' => true,
            'message' => t('updates.msg_update_applied'),
            'backup_path' => $tmpBackup,
        ];
    } catch (Throwable $e) {
        try {
            if (is_dir($tmpBackup)) {
                restore_core_paths_from_backup($tmpBackup);
            }
        } catch (Throwable $restoreError) {
            return [
                'ok' => false,
                'error' => t('updates.err_update_rollback', ['error' => $restoreError->getMessage()]),
            ];
        }

        return [
            'ok' => false,
            'error' => t('updates.err_update_rolled_back', ['error' => $e->getMessage()]),
        ];
    } finally {
        @unlink($tmpZip);
        remove_directory_recursive($tmpExtract);
    }
}

$latest = null;
if (isset($_GET['check'])) {
    $latest = fetch_latest_purecomments_release();
}

$currentVersionDisplay = detect_current_purecomments_version();
$packagePlan = null;
$packagePlanError = '';
if (isset($_GET['package_plan'])) {
    $latestForPackage = fetch_latest_purecomments_release();
    if (!($latestForPackage['ok'] ?? false)) {
        $packagePlanError = (string) ($latestForPackage['error'] ?? 'Unable to fetch latest release metadata.');
    } else {
        $latestTag = (string) ($latestForPackage['tag'] ?? '');
        $currentVersion = detect_current_purecomments_version();

        if ($latestTag !== '' && versions_match($currentVersion, $latestTag)) {
            $packagePlan = [
                'ok' => true,
                'already_latest' => true,
                'message' => t('updates.msg_already_latest', ['version' => $latestTag]),
            ];
        } else {
            $packagePlan = build_package_upgrade_plan((string) ($latestForPackage['zipball_url'] ?? ''));
            if (!($packagePlan['ok'] ?? false)) {
                $packagePlanError = (string) ($packagePlan['error'] ?? 'Unable to build package plan.');
            }
        }
    }
}

$applyResult = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_token'], $token)) {
        $applyResult = ['ok' => false, 'error' => t('updates.err_csrf')];
    } elseif (isset($_POST['apply_update'])) {
        $latestForApply = fetch_latest_purecomments_release();
        if (!($latestForApply['ok'] ?? false)) {
            $applyResult = [
                'ok' => false,
                'error' => (string) ($latestForApply['error'] ?? 'Unable to fetch latest release metadata.'),
            ];
        } else {
            $applyResult = apply_release_update(
                (string) ($latestForApply['zipball_url'] ?? ''),
                (string) ($latestForApply['tag'] ?? '')
            );

            if (($applyResult['ok'] ?? false) && !empty($latestForApply['tag'])) {
                $currentVersionDisplay = normalize_version_label((string) $latestForApply['tag']);
            }
        }
    } elseif (isset($_POST['restore_backup'])) {
        $backupName = trim((string) ($_POST['backup_name'] ?? ''));
        $applyResult = restore_named_backup($backupName);
        if (!($applyResult['ok'] ?? false) && $backupName === '') {
            $applyResult = ['ok' => false, 'error' => t('updates.err_please_choose_restore')];
        }
    } elseif (isset($_POST['delete_backup'])) {
        $backupName = trim((string) ($_POST['backup_name'] ?? ''));
        $applyResult = delete_named_backup($backupName);
        if (!($applyResult['ok'] ?? false) && $backupName === '') {
            $applyResult = ['ok' => false, 'error' => t('updates.err_please_choose_delete')];
        }
    }
}

$availableBackups = list_available_backups();
$latestBackup = $availableBackups[0] ?? '';
$latestBackupTimestamp = $latestBackup !== '' ? format_backup_timestamp($latestBackup) : '';

$styleVersion = filemtime(__DIR__ . '/public/style.css');
?>
<!doctype html>
<html lang="<?php echo h(_pc_lang_code()); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo h(t('updates.title')); ?></title>
    <link rel="stylesheet" href="<?php echo h(pc_url('/public/style.css', $config)); ?>?v=<?php echo h((string)$styleVersion); ?>">
</head>
<body class="admin">
    <main class="admin-container">
        <div class="admin-top-actions">
            <a class="button" href="<?php echo h(pc_url('/settings.php', $config)); ?>">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-back"></use></svg>
                <span><?php echo h(t('updates.back_btn')); ?></span>
            </a>
            <a class="button danger" href="<?php echo h(pc_url('/logout.php', $config)); ?>">
                <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-logout"></use></svg>
                <span><?php echo h(t('updates.logout_btn')); ?></span>
            </a>
        </div>

        <h1><?php echo h(t('updates.heading')); ?></h1>

        <section class="admin-section">
            <h2><?php echo h(t('updates.section_version')); ?></h2>
            <p><strong><?php echo h(t('updates.current_version')); ?></strong> <?php echo h($currentVersionDisplay); ?></p>
            <?php if ($latestBackup !== '') : ?>
                <p><strong><?php echo h(t('updates.last_backup')); ?></strong>
                    <?php if ($latestBackupTimestamp !== '') : ?>
                        <?php echo h($latestBackupTimestamp); ?>
                    <?php else : ?>
                        <?php echo h(t('updates.unknown_time')); ?>
                    <?php endif; ?>
                    (<code><?php echo h($latestBackup); ?></code>)
                </p>
            <?php endif; ?>
            <p><strong><?php echo h(t('updates.repository')); ?></strong> <a href="https://github.com/kevquirk/purecomments" target="_blank" rel="noopener noreferrer">github.com/kevquirk/purecomments</a></p>
            <p>
                <a class="button" href="<?php echo h(pc_url('/updates.php', $config)); ?>?check=1">
                    <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-upgrade"></use></svg>
                    <?php echo h(t('updates.check_btn')); ?>
                </a>
                <a class="button" href="<?php echo h(pc_url('/updates.php', $config)); ?>?package_plan=1">
                    <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-upgrade"></use></svg>
                    <?php echo h(t('updates.inspect_btn')); ?>
                </a>
            </p>

            <?php if ($latest !== null && !($latest['ok'] ?? false)) : ?>
                <p class="notice error"><?php echo h((string) ($latest['error'] ?? 'Unable to check for updates.')); ?></p>
            <?php endif; ?>

            <?php if ($latest !== null && ($latest['ok'] ?? false)) : ?>
                <p><strong><?php echo h(t('updates.latest_release')); ?></strong> <?php echo h($latest['tag'] !== '' ? (string) $latest['tag'] : (string) ($latest['name'] ?? 'Unknown')); ?></p>
                <?php if (($latest['published_at'] ?? '') !== '') : ?>
                    <p><strong><?php echo h(t('updates.published_label')); ?></strong> <?php echo h((string) date('Y-m-d', strtotime((string) $latest['published_at']))); ?></p>
                <?php endif; ?>
                <p><a href="<?php echo h((string) ($latest['url'] ?? 'https://github.com/kevquirk/purecomments/releases')); ?>" target="_blank" rel="noopener noreferrer"><?php echo h(t('updates.release_notes')); ?></a></p>
            <?php endif; ?>
        </section>

        <?php if ($packagePlanError !== '') : ?>
            <section class="admin-section">
                <h2><?php echo h(t('updates.section_package')); ?></h2>
                <p class="notice error"><?php echo h($packagePlanError); ?></p>
            </section>
        <?php endif; ?>

        <?php if ($packagePlan !== null && ($packagePlan['ok'] ?? false)) : ?>
            <section class="admin-section">
                <h2><?php echo h(t('updates.section_package')); ?></h2>
                <?php if (!empty($packagePlan['already_latest'])) : ?>
                    <p><?php echo h((string) ($packagePlan['message'] ?? t('updates.msg_already_latest', ['version' => '']))); ?></p>
                <?php else : ?>
                    <p><strong><?php echo h(t('updates.planned_actions')); ?></strong></p>
                    <ul>
                        <li><strong><?php echo h(t('updates.count_add')); ?></strong> <?php echo h((string) ($packagePlan['counts']['add'] ?? 0)); ?></li>
                        <li><strong><?php echo h(t('updates.count_replace')); ?></strong> <?php echo h((string) ($packagePlan['counts']['replace'] ?? 0)); ?></li>
                        <li><strong><?php echo h(t('updates.count_unchanged')); ?></strong> <?php echo h((string) ($packagePlan['counts']['unchanged'] ?? 0)); ?></li>
                        <li><strong><?php echo h(t('updates.count_preserved')); ?></strong> <?php echo h((string) ($packagePlan['counts']['skip'] ?? 0)); ?></li>
                        <li><strong><?php echo h(t('updates.count_local_only')); ?></strong> <?php echo h((string) ($packagePlan['counts']['local_only'] ?? 0)); ?></li>
                    </ul>

                    <?php if (!empty($packagePlan['will_add'])) : ?>
                        <p><strong><?php echo h(t('updates.will_add')); ?></strong></p>
                        <ul>
                            <?php foreach ($packagePlan['will_add'] as $path) : ?>
                                <li><code><?php echo h((string) $path); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($packagePlan['will_replace'])) : ?>
                        <p><strong><?php echo h(t('updates.will_replace')); ?></strong></p>
                        <ul>
                            <?php foreach ($packagePlan['will_replace'] as $path) : ?>
                                <li><code><?php echo h((string) $path); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($packagePlan['local_only'])) : ?>
                        <p><strong><?php echo h(t('updates.local_only_files')); ?></strong></p>
                        <ul>
                            <?php foreach ($packagePlan['local_only'] as $path) : ?>
                                <li><code><?php echo h((string) $path); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <form method="post" action="<?php echo h(pc_url('/updates.php', $config)); ?>" onsubmit="return confirm(<?php echo json_encode(t('updates.confirm_apply')); ?>);">
                        <input type="hidden" name="csrf_token" value="<?php echo h((string) $_SESSION['csrf_token']); ?>">
                        <button class="button" type="submit" name="apply_update" value="1">
                            <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-upgrade"></use></svg>
                            <?php echo h(t('updates.apply_btn')); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if (!empty($availableBackups)) : ?>
            <section class="admin-section">
                <h2><?php echo h(t('updates.section_backup')); ?></h2>
                <p><?php echo h(t('updates.backup_note')); ?></p>
                <form method="post" action="<?php echo h(pc_url('/updates.php', $config)); ?>" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?php echo h((string) $_SESSION['csrf_token']); ?>">
                    <label for="backup_name"><?php echo h(t('updates.available_backups')); ?></label>
                    <select id="backup_name" name="backup_name" required>
                        <?php foreach ($availableBackups as $backupName) : ?>
                            <option value="<?php echo h((string) $backupName); ?>"><?php echo h((string) $backupName); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="admin-action">
                        <button class="button" type="submit" name="restore_backup" value="1" onclick="return confirm(<?php echo json_encode(t('updates.confirm_restore')); ?>);">
                            <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-upgrade"></use></svg>
                            <?php echo h(t('updates.restore_btn')); ?>
                        </button>
                        <button class="button danger" type="submit" name="delete_backup" value="1" onclick="return confirm(<?php echo json_encode(t('updates.confirm_delete_backup')); ?>);">
                            <svg class="button-icon" aria-hidden="true" focusable="false"><use href="<?php echo h(pc_url('/public/icons/sprite.svg', $config)); ?>#icon-delete"></use></svg>
                            <?php echo h(t('updates.delete_backup_btn')); ?>
                        </button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($applyResult !== null) : ?>
            <section class="admin-section">
                <h2><?php echo h(t('updates.section_result')); ?></h2>
                <?php if (!($applyResult['ok'] ?? false)) : ?>
                    <p class="notice error"><?php echo h((string) ($applyResult['error'] ?? '')); ?></p>
                <?php else : ?>
                    <p class="notice success"><?php echo h((string) ($applyResult['message'] ?? '')); ?></p>
                    <?php if (!empty($applyResult['backup_path'])) : ?>
                        <p><strong><?php echo h(t('updates.backup_path')); ?></strong> <code><?php echo h((string) $applyResult['backup_path']); ?></code></p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
<?php

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
