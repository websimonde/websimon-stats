<?php
/*
 * websimon-stats – Besucherstatistik ohne Cookies und ohne Datenbank
 * (c) 2026 Ralf Simon, websimon.de – MIT-Lizenz
 * https://github.com/websimonde/websimon-stats
 */
// Translations for the statistics pages (only loaded there, never in the counted pages,
// so the short function names cannot clash with functions of the website).

require_once __DIR__ . '/core.php';

// One file per language in lang/ (en.php, de.php, ...). English is the fallback
// for missing texts. The choice in the settings is stored in a cookie for the
// statistics folder only; without a choice the browser language is used.

function stats_languages() {
    static $list = null;
    if ($list === null) {
        $list = [];
        foreach (glob(STATS_DIR . '/lang/*.php') ?: [] as $f) {
            $code = basename($f, '.php');
            if (preg_match('/^[a-z]{2}$/', $code)) {
                $data = include $f;
                $list[$code] = is_array($data) ? ($data['name'] ?? $code) : $code;
            }
        }
        ksort($list);
    }
    return $list;
}

function stats_lang() {
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $available = stats_languages();
    $c = (string)($_COOKIE['stats_lang'] ?? '');
    if (isset($available[$c])) {
        return $lang = $c;
    }
    // browser language, in the order of preference
    foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $code = strtolower(substr(trim($part), 0, 2));
        if (isset($available[$code])) {
            return $lang = $code;
        }
    }
    return $lang = isset($available['en']) ? 'en' : (string)key($available);
}

function stats_texts() {
    static $texts = null;
    if ($texts === null) {
        $en = is_file(STATS_DIR . '/lang/en.php') ? include STATS_DIR . '/lang/en.php' : [];
        $own = include STATS_DIR . '/lang/' . stats_lang() . '.php';
        $texts = array_merge(is_array($en) ? $en : [], is_array($own) ? $own : []);
    }
    return $texts;
}

// Text in the current language (may contain HTML, see the language files)
function t($key, ...$args) {
    $s = stats_texts()[$key] ?? $key;
    return $args ? vsprintf($s, $args) : $s;
}

// Same, HTML-escaped (for texts without markup)
function te($key, ...$args) {
    return htmlspecialchars(t($key, ...$args));
}

// Translated value from the log (Direct, Unknown, Mobile, ...)
function tv($value) {
    return stats_texts()['v_' . $value] ?? $value;
}

// Date with translated month names; $format is a key of the language file or a date() format
function ldate($format, $ts) {
    $texts = stats_texts();
    $format = $texts[$format] ?? $format;
    $out = '';
    $len = strlen($format);
    for ($i = 0; $i < $len; $i++) {
        $ch = $format[$i];
        if ($ch === '\\' && $i + 1 < $len) {
            $out .= $format[++$i];
        } elseif ($ch === 'M') {
            $out .= $texts['months'][(int)date('n', $ts) - 1] ?? date('M', $ts);
        } elseif ($ch === 'F') {
            $out .= $texts['months_note'][(int)date('n', $ts) - 1] ?? date('F', $ts);
        } elseif (ctype_alpha($ch)) {
            $out .= date($ch, $ts);
        } else {
            $out .= $ch;
        }
    }
    return $out;
}

function lnum($n) {
    return number_format((float)$n, 0, ',', stats_texts()['thousands'] ?? ',');
}
