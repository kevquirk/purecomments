<?php
declare(strict_types=1);

function default_comments_timezone(): string
{
    return 'UTC';
}

function default_comments_date_format(): string
{
    return 'Y-m-d H:i';
}

function is_valid_timezone_id(string $timezone): bool
{
    return in_array($timezone, DateTimeZone::listIdentifiers(), true);
}

function normalize_comments_timezone(string $timezone): string
{
    $timezone = trim($timezone);
    if ($timezone === '' || !is_valid_timezone_id($timezone)) {
        return default_comments_timezone();
    }
    return $timezone;
}

function normalize_comments_date_format(string $dateFormat): string
{
    $dateFormat = trim($dateFormat);
    if ($dateFormat === '') {
        return default_comments_date_format();
    }
    return $dateFormat;
}

function default_comments_language(): string
{
    return 'en';
}

function build_config_php(array $d): string
{
    $lines = [];
    $lines[] = '<?php';
    $lines[] = '';
    $lines[] = 'return [';
    $lines[] = "    'db_path' => __DIR__ . '/db/comments.sqlite',";
    $lines[] = "    'language' => " . var_export($d['language'] ?? default_comments_language(), true) . ',';
    $lines[] = "    'admin_username' => " . var_export($d['admin_username'], true) . ',';
    $lines[] = "    'admin_password_hash' => " . var_export($d['admin_password_hash'], true) . ',';
    $lines[] = "    'sodium_key' => hex2bin(" . var_export($d['sodium_key_hex'], true) . '),';
    $lines[] = "    'timezone' => " . var_export(normalize_comments_timezone((string)($d['timezone'] ?? default_comments_timezone())), true) . ',';
    $lines[] = "    'date_format' => " . var_export(normalize_comments_date_format((string)($d['date_format'] ?? default_comments_date_format())), true) . ',';
    $lines[] = "    'privacy_policy_url' => " . var_export($d['privacy_policy_url'] ?? '/privacy#commenting', true) . ',';
    $lines[] = "    'spam_challenge' => [";
    $lines[] = "        'question' => " . var_export($d['spam_challenge_question'], true) . ',';
    $lines[] = "        'answer' => " . var_export($d['spam_challenge_answer'], true) . ',';
    $lines[] = "        'placeholder' => " . var_export($d['spam_challenge_placeholder'], true) . ',';
    $lines[] = '    ],';
    $lines[] = "    'post_titles' => " . export_config_array($d['post_titles'] ?? [], 1) . ',';
    $lines[] = "    'post_base_url' => " . var_export($d['post_base_url'], true) . ',';
    $lines[] = "    'author' => [";
    $lines[] = "        'name' => " . var_export($d['author_name'], true) . ',';
    $lines[] = "        'email' => " . var_export($d['author_email'], true) . ',';
    $lines[] = '    ],';
    $lines[] = "    'aws' => [";
    $lines[] = "        'region' => " . var_export($d['aws_region'], true) . ',';
    $lines[] = "        'access_key' => " . var_export($d['aws_access_key'], true) . ',';
    $lines[] = "        'secret_key' => " . var_export($d['aws_secret_key'], true) . ',';
    $lines[] = "        'source_email' => " . var_export($d['source_email'], true) . ',';
    $lines[] = "        'source_name' => " . var_export($d['source_name'], true) . ',';
    $lines[] = '    ],';
    $lines[] = "    'smtp' => [";
    $lines[] = "        'host' => " . var_export($d['smtp_host'], true) . ',';
    $lines[] = "        'port' => " . var_export((int)$d['smtp_port'], true) . ',';
    $lines[] = "        'user' => " . var_export($d['smtp_user'], true) . ',';
    $lines[] = "        'pwd' => " . var_export($d['smtp_pwd'], true) . ',';
    $lines[] = "        'enc' => " . var_export($d['smtp_enc'], true) . ',';
    $lines[] = '    ],';
    $lines[] = "    'moderation' => [";
    $lines[] = "        'notify_email' => " . var_export($d['notify_email'], true) . ',';
    $lines[] = "        'base_url' => " . var_export($d['moderation_base_url'], true) . ',';
    $lines[] = '    ],';
    $lines[] = '];';
    $lines[] = '';

    return implode("\n", $lines);
}

function export_config_array(array $value, int $indentLevel = 0): string
{
    $indent = str_repeat('    ', $indentLevel);
    $nextIndent = str_repeat('    ', $indentLevel + 1);

    if ($value === []) {
        return '[]';
    }

    $isList = array_keys($value) === range(0, count($value) - 1);
    $lines = ['['];

    foreach ($value as $k => $v) {
        $prefix = $isList ? '' : var_export($k, true) . ' => ';
        if (is_array($v)) {
            $lines[] = $nextIndent . $prefix . export_config_array($v, $indentLevel + 1) . ',';
        } else {
            $lines[] = $nextIndent . $prefix . var_export($v, true) . ',';
        }
    }

    $lines[] = $indent . ']';
    return implode("\n", $lines);
}
