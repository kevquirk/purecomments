<?php
declare(strict_types=1);

function ses_send_email(array $config, string $to, string $subject, string $textBody, string $htmlBody = ''): bool
{
    if (empty($config['aws']['access_key']) || empty($config['aws']['secret_key'])) {
        return false;
    }

    if (!function_exists('curl_init')) {
        error_log('SES send failed: cURL extension not available.');
        return false;
    }

    $aws = $config['aws'];
    $host = 'email.' . $aws['region'] . '.amazonaws.com';
    $endpoint = 'https://' . $host . '/';
    $service = 'ses';
    $contentType = 'application/x-www-form-urlencoded';
    $dateTime = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $amzDate = $dateTime->format('Ymd\THis\Z');
    $dateStamp = $dateTime->format('Ymd');

    $sourceEmail = $aws['source_email'];
    $sourceName = trim($aws['source_name'] ?? '');
    $source = $sourceName !== '' ? sprintf('%s <%s>', $sourceName, $sourceEmail) : $sourceEmail;

    $payloadArray = [
        'Action' => 'SendEmail',
        'Source' => $source,
        'Destination.ToAddresses.member.1' => $to,
        'Message.Subject.Charset' => 'UTF-8',
        'Message.Subject.Data' => $subject,
        'Message.Body.Text.Charset' => 'UTF-8',
        'Message.Body.Text.Data' => $textBody,
        'Version' => '2010-12-01',
    ];

    if ($htmlBody !== '') {
        $payloadArray['Message.Body.Html.Charset'] = 'UTF-8';
        $payloadArray['Message.Body.Html.Data'] = $htmlBody;
    }

    $payload = http_build_query($payloadArray, '', '&', PHP_QUERY_RFC3986);
    $hashedPayload = hash('sha256', $payload);

    $canonicalHeaders =
        'content-type:' . $contentType . "\n" .
        'host:' . $host . "\n" .
        'x-amz-content-sha256:' . $hashedPayload . "\n" .
        'x-amz-date:' . $amzDate . "\n";
    $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest = implode("\n", [
        'POST',
        '/',
        '',
        $canonicalHeaders,
        $signedHeaders,
        $hashedPayload,
    ]);

    $credentialScope = $dateStamp . '/' . $aws['region'] . '/' . $service . '/aws4_request';
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $credentialScope,
        hash('sha256', $canonicalRequest),
    ]);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $aws['secret_key'], true);
    $kRegion = hash_hmac('sha256', $aws['region'], $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorizationHeader = sprintf(
        'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
        $aws['access_key'],
        $credentialScope,
        $signedHeaders,
        $signature
    );

    $headers = [
        'Content-Type: ' . $contentType,
        'X-Amz-Date: ' . $amzDate,
        'X-Amz-Content-Sha256: ' . $hashedPayload,
        'Authorization: ' . $authorizationHeader,
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        error_log('SES send failed: ' . ($curlError ?: $response));
        return false;
    }

    return true;
}
