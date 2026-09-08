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
        curl_close($ch);

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
 * Parses Microformats2 or standard HTML tags from the source page.
 */
function parse_microformats_or_html(string $html, string $sourceUrl, string $targetUrl): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Determine type: like, repost, reply, or mention
    $type = 'mention';

    $likeNodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " u-like-of ") or contains(concat(" ", normalize-space(@class), " "), " u-like ")]');
    if ($likeNodes && $likeNodes->length > 0) {
        $type = 'like';
    }

    if ($type === 'mention') {
        $repostNodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " u-repost-of ") or contains(concat(" ", normalize-space(@class), " "), " u-repost ")]');
        if ($repostNodes && $repostNodes->length > 0) {
            $type = 'repost';
        }
    }

    if ($type === 'mention') {
        $replyNodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " u-in-reply-to ")]');
        if ($replyNodes && $replyNodes->length > 0) {
            $type = 'reply';
        }
    }

    // Author discovery
    $authorName = '';
    $authorUrl = '';
    $authorAvatar = '';
    $contentHtml = '';
    $contentMd = '';

    // 1. Check Schema.org / ActivityStreams JSON-LD
    $jsonLdNodes = $xpath->query('//script[@type="application/ld+json"]');
    if ($jsonLdNodes && $jsonLdNodes->length > 0) {
        foreach ($jsonLdNodes as $node) {
            $jsonText = trim($node->textContent);
            $data = json_decode($jsonText, true);
            if (is_array($data)) {
                $items = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if (isset($item['author']) && is_array($item['author'])) {
                        $authorObj = $item['author'];
                        if ($authorName === '' && !empty($authorObj['name'])) {
                            $authorName = trim((string)$authorObj['name']);
                        }
                        if ($authorUrl === '' && !empty($authorObj['url'])) {
                            $authorUrl = trim((string)$authorObj['url']);
                        }
                        if ($authorAvatar === '' && !empty($authorObj['image'])) {
                            $authorAvatar = is_array($authorObj['image']) ? (string)($authorObj['image']['url'] ?? '') : (string)$authorObj['image'];
                        }
                    }
                    if ($contentHtml === '' && !empty($item['text'])) {
                        $contentHtml = (string)$item['text'];
                    } elseif ($contentHtml === '' && !empty($item['articleBody'])) {
                        $contentHtml = '<p>' . nl2br(htmlspecialchars((string)$item['articleBody'], ENT_QUOTES, 'UTF-8')) . '</p>';
                    }
                }
            }
        }
    }

    // 2. Check Microformats p-author / h-card (prioritise p-author)
    $cardNodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " p-author ")]');
    if (!$cardNodes || $cardNodes->length === 0) {
        $cardNodes = $xpath->query('//body//*[contains(concat(" ", normalize-space(@class), " "), " h-card ") and not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " e-content ")]) and not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " u-category ")])]');
    }
    if ($cardNodes && $cardNodes->length > 0) {
        $card = $cardNodes->item(0);

        if ($authorName === '') {
            $nameNodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " p-name ")]', $card);
            if ($nameNodes && $nameNodes->length > 0) {
                $authorName = trim($nameNodes->item(0)->textContent);
            } elseif ($card->nodeName === 'a' || $card->nodeName === 'span') {
                $authorName = trim($card->textContent);
            }
        }

        if ($authorAvatar === '') {
            $photoNodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " u-photo ")] | .//img[contains(concat(" ", normalize-space(@class), " "), " u-photo ")]', $card);
            if ($photoNodes && $photoNodes->length > 0) {
                $pNode = $photoNodes->item(0);
                $authorAvatar = $pNode->getAttribute('src') ?: $pNode->getAttribute('href');
            }
        }

        if ($authorUrl === '') {
            $urlNodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " u-url ")]', $card);
            if ($urlNodes && $urlNodes->length > 0) {
                $uNode = $urlNodes->item(0);
                $authorUrl = $uNode->getAttribute('href') ?: trim($uNode->textContent);
            } elseif ($card->getAttribute('href')) {
                $authorUrl = $card->getAttribute('href');
            }
        }
    }

    // 3. Fallbacks for author name (e.g. Mastodon "Name (@handle@server)" in og:title)
    if ($authorName === '') {
        $metaTitle = $xpath->query('//meta[@property="og:title"]/@content | //meta[@name="twitter:title"]/@content');
        if ($metaTitle && $metaTitle->length > 0) {
            $rawTitle = trim($metaTitle->item(0)->nodeValue);
            if (preg_match('/^(.+?)\s*\((@[^)]+)\)$/', $rawTitle, $m)) {
                $authorName = trim($m[1]);
            } elseif (!str_starts_with($rawTitle, 'http')) {
                $authorName = $rawTitle;
            }
        }
    }

    if ($authorName === '') {
        $metaAuthor = $xpath->query('//meta[@name="author"]/@content | //meta[@property="article:author"]/@content | //meta[@property="og:author"]/@content | //meta[@property="profile:username"]/@content | //meta[@name="twitter:creator"]/@content');
        if ($metaAuthor && $metaAuthor->length > 0) {
            $rawAuthor = trim($metaAuthor->item(0)->nodeValue);
            $authorName = $rawAuthor;
        }
    }

    if ($authorAvatar === '') {
        $metaImage = $xpath->query('//meta[@property="og:image"]/@content | //meta[@name="twitter:image"]/@content');
        if ($metaImage && $metaImage->length > 0) {
            $authorAvatar = trim($metaImage->item(0)->nodeValue);
        }
    }

    if ($authorUrl === '') {
        $metaUrl = $xpath->query('//meta[@property="og:url"]/@content');
        if ($metaUrl && $metaUrl->length > 0) {
            $authorUrl = trim($metaUrl->item(0)->nodeValue);
        }
    }

    if ($authorName === '') {
        $sourceHost = parse_url($sourceUrl, PHP_URL_HOST);
        $authorName = $sourceHost ?: 'Fediverse User';
    }

    if ($authorUrl === '') {
        $authorUrl = $sourceUrl;
    }

    // Resolve relative avatar or author URLs
    if ($authorAvatar !== '' && !preg_match('#^https?://#i', $authorAvatar)) {
        $authorAvatar = resolve_relative_url($sourceUrl, $authorAvatar);
    }
    if ($authorUrl !== '' && !preg_match('#^https?://#i', $authorUrl)) {
        $authorUrl = resolve_relative_url($sourceUrl, $authorUrl);
    }

    // Content extraction
    if ($type !== 'like' && $type !== 'repost') {
        if ($contentHtml === '') {
            $contentNodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " e-content ")]');
            if ($contentNodes && $contentNodes->length > 0) {
                $contentHtml = $dom->saveHTML($contentNodes->item(0));
            } else {
                $summaryNodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " p-summary ")]');
                if ($summaryNodes && $summaryNodes->length > 0) {
                    $contentHtml = $dom->saveHTML($summaryNodes->item(0));
                } else {
                    $metaDesc = $xpath->query('//meta[@name="description"]/@content | //meta[@property="og:description"]/@content');
                    if ($metaDesc && $metaDesc->length > 0) {
                        $contentHtml = '<p>' . htmlspecialchars(trim($metaDesc->item(0)->nodeValue), ENT_QUOTES, 'UTF-8') . '</p>';
                    }
                }
            }
        }
        $contentHtml = sanitize_webmention_html($contentHtml);
        $contentMd = strip_tags($contentHtml);
    }

    return [
        'name' => mb_substr($authorName, 0, 80),
        'website' => filter_var($authorUrl, FILTER_VALIDATE_URL) ? $authorUrl : null,
        'avatar_url' => filter_var($authorAvatar, FILTER_VALIDATE_URL) ? $authorAvatar : null,
        'content_html' => $contentHtml,
        'content_md' => $contentMd,
        'type' => $type,
        'source_url' => $sourceUrl,
        'created_at' => gmdate('Y-m-d H:i:s'),
    ];
}

/**
 * Sanitises HTML from external webmentions.
 */
function sanitize_webmention_html(string $html): string
{
    $trimmed = trim($html);
    if ($trimmed === '') {
        return '';
    }

    $allowedTags = '<p><br><a><strong><em><b><i><blockquote><code><pre>';
    $stripped = strip_tags($trimmed, $allowedTags);

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $encoded = function_exists('mb_encode_numericentity')
        ? mb_encode_numericentity($stripped, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8')
        : htmlspecialchars_decode(htmlentities($stripped, ENT_QUOTES, 'UTF-8'), ENT_QUOTES);
    @$dom->loadHTML('<div>' . $encoded . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    // Strip unapproved attributes and dangerous link schemes
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//*');
    if ($nodes) {
        foreach ($nodes as $node) {
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            /** @var DOMElement $node */
            $tag = strtolower($node->tagName);
            $attrsToRemove = [];
            foreach ($node->attributes as $attr) {
                $attrName = strtolower($attr->name);
                if ($tag === 'a' && $attrName === 'href') {
                    $href = trim($attr->value);
                    if (preg_match('#^(https?://|/|\#)#i', $href)) {
                        continue;
                    }
                }
                $attrsToRemove[] = $attr->name;
            }
            foreach ($attrsToRemove as $attrName) {
                $node->removeAttribute($attrName);
            }
        }
    }

    $links = $dom->getElementsByTagName('a');
    foreach ($links as $link) {
        $link->setAttribute('rel', 'nofollow noopener noreferrer');
        $link->setAttribute('target', '_blank');
    }

    $wrapper = $dom->getElementsByTagName('div')->item(0);
    if ($wrapper) {
        $out = '';
        foreach ($wrapper->childNodes as $node) {
            $out .= $dom->saveHTML($node);
        }
        return trim($out);
    }

    return trim($dom->saveHTML());
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
        return 'https://fed.brid.gy/webmention';
    }

    $ch = curl_init($targetUrl);
    if ($ch === false) {
        return 'https://fed.brid.gy/webmention';
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
    curl_close($ch);

    if (!is_string($response)) {
        return 'https://fed.brid.gy/webmention';
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

    // Default fallback for Fediverse / Bridgy
    return 'https://fed.brid.gy/webmention';
}

/**
 * Sends an outgoing Webmention to the target URL.
 */
function send_outgoing_webmention(string $sourceUrl, string $targetUrl, array $config = []): bool
{
    $isBridgyOrFediverse = (strpos($targetUrl, 'brid.gy') !== false || strpos($targetUrl, 'mastodon') !== false);

    if ($isBridgyOrFediverse) {
        $endpoint = 'https://brid.gy/publish/webmention';
        $postTarget = 'https://brid.gy/publish/mastodon';
    } else {
        $endpoint = discover_webmention_endpoint($targetUrl) ?: 'https://brid.gy/publish/webmention';
        $postTarget = $targetUrl;
    }

    $postData = http_build_query([
        'source' => $sourceUrl,
        'target' => $postTarget,
        'bridgy_omit_link' => 'true',
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
        curl_close($ch);

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
