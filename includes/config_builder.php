<?php
declare(strict_types=1);

function build_config_php(array $d): string
{
    $lines = [];
    $lines[] = '<?php';
    $lines[] = '';
    $lines[] = 'return [';
    $lines[] = "    'db_path' => __DIR__ . '/db/comments.sqlite',";
    $lines[] = "    'admin_username' => " . var_export($d['admin_username'], true) . ',';
    $lines[] = "    'admin_password_hash' => " . var_export($d['admin_password_hash'], true) . ',';
    $lines[] = "    'sodium_key' => hex2bin(" . var_export($d['sodium_key_hex'], true) . '),';
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
