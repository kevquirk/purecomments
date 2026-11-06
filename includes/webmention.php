<?php
declare(strict_types=1);

require_once __DIR__ . '/url.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/render.php';

/**
 * Resolves an incoming target URL into a local post slug.
 */
function resolve_target_slug(string $targetUrl, array $config): ?string
{
    $targetUrl = trim($targetUrl);
    if ($targetUrl === '') {
        return null;
    }

    $targetParts = parse_url($targetUrl);
    $targetPath = isset($targetParts['path']) ? trim($targetParts['path'], '/') : '';

    // Strip common file extensions like .html, .htm, .php
    $cleanedPath = preg_replace('/\.(html|htm|php)$/i', '', $targetPath);
    $cleanedPath = trim((string)$cleanedPath, '/');

    // 1. If post_base_url is configured, check if target starts with post_base_url
    $postBaseUrl = trim((string)($config['post_base_url'] ?? ''));
    if ($postBaseUrl !== '') {
        $baseParts = parse_url($postBaseUrl);
        $basePath = isset($baseParts['path']) ? trim($baseParts['path'], '/') : '';

        // If target host does not match configured host (when configured with host), reject
        if (!empty($baseParts['host']) && !empty($targetParts['host'])) {
            if (strcasecmp($baseParts['host'], $targetParts['host']) !== 0) {
                return null;
            }
        }

        if ($basePath !== '' && strpos($cleanedPath, $basePath) === 0) {
            $remainder = trim(substr($cleanedPath, strlen($basePath)), '/');
            if ($remainder !== '' && validate_post_slug($remainder)) {
                return $remainder;
            }
        }
    }

    // 2. Check configured post_titles
    if (!empty($config['post_titles']) && is_array($config['post_titles'])) {
        foreach (array_keys($config['post_titles']) as $slug) {
            if ($slug === $cleanedPath || $slug === $targetPath) {
                return $slug;
            }
        }
    }

    // 3. Fallback: try the final path segment
    if ($cleanedPath !== '') {
        $segments = explode('/', $cleanedPath);
        $lastSegment = end($segments);
        if ($lastSegment !== false && validate_post_slug($lastSegment)) {
            return $lastSegment;
        }
    }

    // 4. If target URL is the root site/homepage (empty path)
    if ($cleanedPath === '') {
        return 'home';
    }

    return null;
}

/**
 * Fetches and parses a remote Webmention source page.
 */
function fetch_webmention_source(string $sourceUrl, string $targetUrl): array
{
    $sourceUrl = trim($sourceUrl);
    $targetUrl = trim($targetUrl);

    if (!is_safe_public_url($sourceUrl) || !is_safe_public_url($targetUrl)) {
        return ['ok' => false, 'error' => 'Invalid or disallowed source or target URL'];
    }

    $headers = [
        'User-Agent: PureComments-Webmention/1.0 (+https://purecommons.org)',
        'Accept: text/html, application/xhtml+xml, */*;q=0.9',
    ];

    $html = null;
    $finalUrl = $sourceUrl;

    if (function_exists('curl_init')) {
        $ch = curl_init($sourceUrl);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'cURL initialisation failed'];
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if ($effective !== '') {
            $finalUrl = $effective;
        }
        $curlErr = curl_error($ch);

        if (!is_string($raw) || $status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => 'Failed to fetch source page: ' . ($curlErr ?: "HTTP {$status}")];
        }
        $html = $raw;
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => implode("\r\n", $headers),
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
        ]);
        $raw = @file_get_contents($sourceUrl, false, $context);
        if (!is_string($raw)) {
            return ['ok' => false, 'error' => 'Failed to fetch source page'];
        }
        $html = $raw;
    }

    // Verify target URL exists in source page
    if (!source_links_to_target($html, $targetUrl)) {
        return ['ok' => false, 'error' => 'Target URL not found in source document'];
    }

    $parsed = parse_microformats_or_html($html, $finalUrl, $targetUrl);
    $parsed['ok'] = true;
    return $parsed;
}

/**
 * Checks if the source document links to the target URL.
 */
function source_links_to_target(string $html, string $targetUrl): bool
{
    $targetNoScheme = preg_replace('#^https?://#i', '', rtrim($targetUrl, '/'));
    $targetEncoded = htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8');

    if (stripos($html, $targetUrl) !== false || stripos($html, $targetEncoded) !== false) {
        return true;
    }

    if ($targetNoScheme !== '' && stripos($html, $targetNoScheme) !== false) {
        return true;
    }

    return false;
}

/**
 * Parses page title, author/site info, and a short excerpt from the source page.
 */
function parse_microformats_or_html(string $html, string $sourceUrl, string $targetUrl): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Strip images completely from the DOM before extracting text or excerpts
    $imgNodes = $xpath->query('//img');
    if ($imgNodes) {
        foreach ($imgNodes as $imgNode) {
            if ($imgNode->parentNode) {
                $imgNode->parentNode->removeChild($imgNode);
            }
        }
    }

    // 1. Discover Title
    $title = '';
    $ogTitle = $xpath->query('//meta[@property="og:title"]/@content | //meta[@name="twitter:title"]/@content');
    if ($ogTitle && $ogTitle->length > 0) {
        $title = trim((string)$ogTitle->item(0)->nodeValue);
    }
    if ($title === '') {
        $pName = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " p-name ") and not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " p-author ") or contains(concat(" ", normalize-space(@class), " "), " h-card ")])]');
        if ($pName && $pName->length > 0) {
            $title = trim($pName->item(0)->textContent);
        }
    }
    if ($title === '') {
        $titleNodes = $xpath->query('//title');
        if ($titleNodes && $titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->textContent);
        }
    }
    $title = preg_replace('/\s+/', ' ', $title);
    $title = mb_substr((string)$title, 0, 150);

    // 2. Discover Author / Site Name
    $authorName = '';
    $ogSiteName = $xpath->query('//meta[@property="og:site_name"]/@content');
    if ($ogSiteName && $ogSiteName->length > 0) {
        $authorName = trim((string)$ogSiteName->item(0)->nodeValue);
    }
    if ($authorName === '') {
        $pAuthor = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " p-author ")]//*[contains(concat(" ", normalize-space(@class), " "), " p-name ")]');
        if ($pAuthor && $pAuthor->length > 0) {
            $authorName = trim($pAuthor->item(0)->textContent);
        }
    }
    if ($authorName === '') {
        $metaAuthor = $xpath->query('//meta[@name="author"]/@content | //meta[@property="article:author"]/@content');
        if ($metaAuthor && $metaAuthor->length > 0) {
            $authorName = trim((string)$metaAuthor->item(0)->nodeValue);
        }
    }
    if ($authorName === '') {
        $host = parse_url($sourceUrl, PHP_URL_HOST);
        $authorName = $host ? preg_replace('/^www\./i', '', $host) : 'Website';
    }
    $authorName = preg_replace('/\s+/', ' ', $authorName);
    $authorName = mb_substr((string)$authorName, 0, 80);

    // 3. Discover Author / Site Website URL
    $authorUrl = '';
    $ogUrl = $xpath->query('//meta[@property="og:url"]/@content');
    if ($ogUrl && $ogUrl->length > 0) {
        $authorUrl = trim((string)$ogUrl->item(0)->nodeValue);
    }
    if ($authorUrl === '' || !filter_var($authorUrl, FILTER_VALIDATE_URL)) {
        $pAuthorUrl = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " p-author ")]//*[contains(concat(" ", normalize-space(@class), " "), " u-url ")]/@href');
        if ($pAuthorUrl && $pAuthorUrl->length > 0) {
            $authorUrl = trim((string)$pAuthorUrl->item(0)->nodeValue);
        }
    }
    if ($authorUrl === '' || !filter_var($authorUrl, FILTER_VALIDATE_URL)) {
        $parts = parse_url($sourceUrl);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $authorUrl = $parts['scheme'] . '://' . $parts['host'];
        } else {
            $authorUrl = $sourceUrl;
        }
    }

    // 4. Discover Excerpt (summary / brief citation snippet)
    $excerpt = '';
    $pSummary = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " p-summary ")]');
    if ($pSummary && $pSummary->length > 0) {
        $excerpt = trim($pSummary->item(0)->textContent);
    }
    if ($excerpt === '') {
        $metaDesc = $xpath->query('//meta[@property="og:description"]/@content | //meta[@name="description"]/@content | //meta[@name="twitter:description"]/@content');
        if ($metaDesc && $metaDesc->length > 0) {
            $excerpt = trim((string)$metaDesc->item(0)->nodeValue);
        }
    }
    if ($excerpt === '') {
        $targetNoScheme = preg_replace('#^https?://#i', '', rtrim($targetUrl, '/'));
        $linkPara = $xpath->query('//a[contains(@href, "' . $targetNoScheme . '")]/ancestor::p | //a[contains(@href, "' . $targetNoScheme . '")]/ancestor::blockquote');
        if ($linkPara && $linkPara->length > 0) {
            $excerpt = trim($linkPara->item(0)->textContent);
        }
    }
    if ($excerpt === '') {
        $firstP = $xpath->query('//article//p | //*[contains(concat(" ", normalize-space(@class), " "), " e-content ")]//p | //body//p');
        if ($firstP && $firstP->length > 0) {
            $excerpt = trim($firstP->item(0)->textContent);
        }
    }

    $excerpt = preg_replace('/\s+/', ' ', $excerpt);
    $excerpt = trim(strip_tags((string)$excerpt));
    if (mb_strlen($excerpt) > 220) {
        $excerpt = rtrim(mb_substr($excerpt, 0, 217)) . '…';
    }

    $contentHtml = $excerpt !== '' ? '<p>' . htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8') . '</p>' : '';
    $contentMd = $excerpt;

    return [
        'title' => $title !== '' ? $title : $authorName,
        'name' => $authorName,
        'website' => filter_var($authorUrl, FILTER_VALIDATE_URL) ? $authorUrl : null,
        'avatar_url' => null,
        'content_html' => $contentHtml,
        'content_md' => $contentMd,
        'type' => 'webmention',
        'source_url' => $sourceUrl,
        'created_at' => gmdate('Y-m-d H:i:s'),
    ];
}

/**
 * Validates that a URL is a safe, publicly routable HTTP/HTTPS URL.
 */
function is_safe_public_url(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }
    $host = $parts['host'] ?? '';
    if ($host === '' || strcasecmp($host, 'localhost') === 0 || strcasecmp($host, '127.0.0.1') === 0 || $host === '::1') {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

/**
 * Resolves a relative URL against a base URL.
 */
function resolve_relative_url(string $base, string $relative): string
{
    if (preg_match('#^https?://#i', $relative)) {
        return $relative;
    }
    $parts = parse_url($base);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    if (strpos($relative, '//') === 0) {
        return $scheme . ':' . $relative;
    }
    if (strpos($relative, '/') === 0) {
        return "{$scheme}://{$host}{$port}{$relative}";
    }

    $path = isset($parts['path']) ? dirname($parts['path']) : '';
    $path = rtrim($path, '/');
    return "{$scheme}://{$host}{$port}{$path}/{$relative}";
}

/**
 * Discovers the Webmention endpoint for a given target URL.
 */
function discover_webmention_endpoint(string $targetUrl): ?string
{
    $headers = [
        'User-Agent: PureComments-Webmention/1.0 (+https://purecommons.org)',
        'Accept: text/html, application/xhtml+xml, */*;q=0.9',
    ];

    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($targetUrl);
    if ($ch === false) {
        return null;
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $targetUrl;

    if (!is_string($response)) {
        return null;
    }

    $headerStr = substr($response, 0, $headerSize);
    $bodyStr = substr($response, $headerSize);

    // 1. Check Link: <...>; rel="webmention" in HTTP headers
    if (preg_match('/<([^>]+)>\s*;\s*rel=["\']?(?:[^"\']*\s+)?webmention(?:\s+[^"\']*)?["\']?/i', $headerStr, $m)) {
        return resolve_relative_url($effectiveUrl, $m[1]);
    }

    // 2. Check HTML <link> or <a> with rel="webmention"
    if (preg_match('/<(?:link|a)[^>]+rel=["\']?(?:[^"\']*\s+)?webmention(?:\s+[^"\']*)?["\']?[^>]+href=["\']([^"\']+)["\']/i', $bodyStr, $m)) {
        return resolve_relative_url($effectiveUrl, $m[1]);
    }
    if (preg_match('/<(?:link|a)[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']?(?:[^"\']*\s+)?webmention(?:\s+[^"\']*)?["\']?/i', $bodyStr, $m)) {
        return resolve_relative_url($effectiveUrl, $m[1]);
    }

    return null;
}

/**
 * Sends an outgoing Webmention to the target URL.
 */
function send_outgoing_webmention(string $sourceUrl, string $targetUrl, array $config = []): bool
{
    $endpoint = discover_webmention_endpoint($targetUrl);
    if ($endpoint === null) {
        return false;
    }

    $postData = http_build_query([
        'source' => $sourceUrl,
        'target' => $targetUrl,
    ]);

    $headers = [
        'User-Agent: PureComments-Webmention/1.0 (+https://purecommons.org)',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return false;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $res = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return $status >= 200 && $status < 300;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 10,
            'header' => implode("\r\n", $headers),
            'content' => $postData,
            'ignore_errors' => true,
        ],
    ]);

    $res = @file_get_contents($endpoint, false, $context);
    return is_string($res);
}

/**
 * Determines whether an incoming webmention originates from the site author (self-mention / loopback).
 */
function is_self_webmention(array $config, array $fetchResult): bool
{
    $authorWebsite = trim((string)($fetchResult['website'] ?? ''));
    $sourceUrl = trim((string)($fetchResult['source_url'] ?? ''));

    $postBaseUrl = trim((string)($config['post_base_url'] ?? ''));
    $modBaseUrl = trim((string)($config['moderation']['base_url'] ?? ''));
    foreach ([$postBaseUrl, $modBaseUrl] as $baseUrl) {
        if ($baseUrl !== '') {
            $baseHost = parse_url($baseUrl, PHP_URL_HOST);
            $sourceHost = parse_url($sourceUrl, PHP_URL_HOST);
            $websiteHost = $authorWebsite !== '' ? parse_url($authorWebsite, PHP_URL_HOST) : null;
            if ($baseHost && $sourceHost && strcasecmp($baseHost, $sourceHost) === 0) {
                return true;
            }
            if ($baseHost && $websiteHost && strcasecmp($baseHost, $websiteHost) === 0) {
                return true;
            }
        }
    }

    return false;
}


