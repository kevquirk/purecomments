<?php
declare(strict_types=1);

/**
 * Cached version and update checker for Pure Comments
 */

function check_cached_updates(bool $forceRefresh = false): array
{
    $versionFile = __DIR__ . '/../VERSION';
    $currentVersion = 'unknown';
    if (is_file($versionFile)) {
        $raw = @file_get_contents($versionFile);
        if (is_string($raw) && trim($raw) !== '') {
            $currentVersion = trim($raw);
        }
    }

    $cacheDir = __DIR__ . '/../data';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $versionCacheFile = $cacheDir . '/.version-cache';
    $latestVersion = '';
    $cacheAge = is_file($versionCacheFile) ? (time() - (int) @filemtime($versionCacheFile)) : PHP_INT_MAX;

    if ($forceRefresh || $cacheAge > 21600) { // 6 hours
        $endpoint = 'https://packages.purecommons.org/comments/latest.json';
        $headers = [
            'User-Agent: PureComments-Updates-Check',
            'Accept: application/json',
        ];

        $raw = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            if ($ch !== false) {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                $exec = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                if (is_string($exec) && $status >= 200 && $status < 300) {
                    $raw = $exec;
                }
            }
        }
        if ($raw === null) {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 3,
                    'header' => implode("\r\n", $headers),
                ],
            ]);
            $exec = @file_get_contents($endpoint, false, $ctx);
            if (is_string($exec)) {
                $raw = $exec;
            }
        }

        if ($raw !== null) {
            $json = json_decode($raw, true);
            if (is_array($json) && !empty($json['tag_name'])) {
                $latestVersion = (string) $json['tag_name'];
                @file_put_contents($versionCacheFile, $latestVersion);
            }
        }
    } else {
        $cached = @file_get_contents($versionCacheFile);
        $latestVersion = is_string($cached) ? trim($cached) : '';
    }

    $updateAvailable = false;
    if ($latestVersion !== '' && $currentVersion !== 'unknown') {
        $normCurrent = ltrim($currentVersion, 'v');
        $normLatest = ltrim($latestVersion, 'v');
        if (version_compare($normCurrent, $normLatest, '<')) {
            $updateAvailable = true;
        }
    }

    return [
        'current_version' => $currentVersion,
        'latest_version'  => $latestVersion,
        'update_available'=> $updateAvailable,
    ];
}
