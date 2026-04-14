<?php
declare(strict_types=1);

/**
 * Set the language for subsequent t() calls. Must be called before any t()
 * call if you want a language other than the default ('en').
 */
function pc_set_language(string $lang): void
{
    _pc_lang_code($lang);
}

/**
 * Translate a dot-notation key with optional {placeholder} replacements.
 * Returns the key itself if no translation is found.
 *
 * Example: t('login.heading')
 * Example: t('updates.err_github_failed', ['status' => 404])
 */
function t(string $key, array $replacements = []): string
{
    static $strings   = null;
    static $loadedFor = null;

    $lang = _pc_lang_code();
    if ($strings === null || $loadedFor !== $lang) {
        $strings   = _pc_load_lang_file($lang);
        $loadedFor = $lang;
    }

    $parts = explode('.', $key);
    $value = $strings;
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $key;
        }
        $value = $value[$part];
    }

    if (!is_string($value)) {
        return $key;
    }

    foreach ($replacements as $placeholder => $replacement) {
        $value = str_replace('{' . $placeholder . '}', (string) $replacement, $value);
    }

    return $value;
}

/** @internal */
function _pc_lang_code(?string $set = null): string
{
    static $code = 'en';
    if ($set !== null) {
        $code = $set;
    }
    return $code;
}

/** @internal */
function _pc_load_lang_file(string $lang): array
{
    $safe = preg_replace('/[^a-z0-9_-]/i', '', $lang);
    if ($safe === '') {
        $safe = 'en';
    }

    $path = __DIR__ . '/../lang/' . $safe . '.php';
    if (!is_file($path)) {
        $path = __DIR__ . '/../lang/en.php';
    }

    $data = require $path;
    return is_array($data) ? $data : [];
}

/**
 * Return a map of available language codes to display names (based on files in lang/).
 * The display name is taken from the 'name' key in each language file.
 * Always includes 'en' as a fallback even if the file is absent.
 *
 * @return array<string, string>
 */
function pc_available_languages(): array
{
    $dir = __DIR__ . '/../lang/';
    $langs = [];
    if (is_dir($dir)) {
        foreach (glob($dir . '*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            if (preg_match('/^[a-z]{2}([_-][a-zA-Z]{2,4})?$/', $code)) {
                $data = require $file;
                $name = is_array($data) && isset($data['name']) && is_string($data['name'])
                    ? $data['name']
                    : $code;
                $langs[$code] = $name;
            }
        }
    }
    if (!array_key_exists('en', $langs)) {
        $langs = array_merge(['en' => 'English'], $langs);
    }
    ksort($langs);
    return $langs;
}
