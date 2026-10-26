<?php
/*
 * websimon-stats – Besucherstatistik ohne Cookies und ohne Datenbank
 * (c) 2026 Ralf Simon, websimon.de – MIT-Lizenz
 * https://github.com/websimonde/websimon-stats
 */
// Icons for the statistics page: UI icons, browser, OS and device icons, language flags.
// Every function returns ready-to-print SVG markup.

// Icons for the header button and the summary cards
function getUiIcon($name) {
    static $icons = [
        'print' => '<path d="M19 8h-1V3H6v5H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zM8 5h8v3H8V5zm8 14H8v-4h8v4zm4-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1z"/>',
        'views-today' => '<path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/>',
        'views-total' => '<path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/>',
        'visitors-today' => '<path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>',
        'visitors-total' => '<path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>',
        'updated' => '<path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/>'
,
        'report' => '<path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>',
        'moon' => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
        'logout' => '<path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/>',
        'settings' => '<path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.488.488 0 0 0-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>',
        'calendar' => '<path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V10h14v10zm0-12H5V6h14v2zM7 12h5v5H7z"/>',
        'sun' => '<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'
    ];
    if (!isset($icons[$name])) {
        return '';
    }
    $classes = ['print' => '', 'logout' => '', 'settings' => '', 'report' => '', 'calendar' => ' class="period-icon"', 'moon' => ' class="icon-moon"', 'sun' => ' class="icon-sun"'];
    $class = $classes[$name] ?? ' class="card-icon"';
    return '<svg' . $class . ' viewBox="0 0 24 24">' . $icons[$name] . '</svg>';
}

function getBrowserIcon($b) {
    $b = strtolower($b);
    if (strpos($b, 'chrome') !== false) {
        return '<svg class="table-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#4285F4"/><path fill="#34A853" d="M12 2a10 10 0 0 1 8.66 5H12v5h8.66A10 10 0 0 1 12 22z"/><path fill="#FBBC05" d="M12 12h8.66a10 10 0 0 1-14.32 7.5L12 12z"/><path fill="#EA4335" d="M12 12L6.34 19.5A10 10 0 0 1 3.34 12H12z"/><circle cx="12" cy="12" r="4" fill="#FFFFFF"/><circle cx="12" cy="12" r="2" fill="#4285F4"/></svg>';
    } elseif (strpos($b, 'firefox') !== false) {
        return '<svg class="table-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#00539F"/><path fill="#FF9500" d="M12 2C8 2 4.5 4.5 3 8c2.5 1 5 .5 7-1-1 3-3 4.5-6 6 3 2 7 2 10-1-2-3-2-6-4-12z"/><path fill="#FF4500" d="M12 2c3.5 0 6.5 2 8 5-1.5-.5-3-.5-4 0 2 2.5 2 6-.5 8-2.5 2-6 1.5-8-1 1.5 2 4 2.5 6 1.5-2-.5-3.5-2-4.5-4.5 1 2.5 3.5 3.5 6 1.5-1.5-2-3-4.5-3.5-7.5z"/><circle cx="13" cy="14" r="3" fill="#FFCC00"/></svg>';
    } elseif (strpos($b, 'safari') !== false) {
        return '<svg class="table-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#006CFF"/><path fill="#FFFFFF" d="M12 5l2 5 5 2-5 2-2 5-2-5-5-2 5-2 2-5z"/></svg>';
    } elseif (strpos($b, 'opera') !== false) {
        return '<svg class="table-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#E8222F"/><path d="M6.5 15a5.5 5.5 0 0 1 11 0" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round"/><path d="M12 15l2.6-3.4" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round"/></svg>';
    } elseif (strpos($b, 'samsung') !== false) {
        return '<svg class="table-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#6E4BD8"/><path d="M6.5 13.5c1.8-2.4 3.7-2.4 5.5 0s3.7 2.4 5.5 0" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round"/></svg>';
    } elseif (strpos($b, 'edge') !== false) {
        return '<svg class="table-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#0078D4"/><path fill="#00BCF2" d="M7 11h6v6H7z"/><path fill="#107C10" d="M13 7h4v4h-4z"/></svg>';
    }
    return '<svg class="table-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#94A3B8"/></svg>';
}

function getOsIcon($os) {
    $os = strtolower($os);
    if (strpos($os, 'windows') !== false) {
        return '<svg class="table-icon" viewBox="0 0 24 24"><path fill="#00A4EF" d="M3 5.5L10.5 4v7.5H3V5.5zm8.5-1.75L21 2.5v9h-9.5V3.75zM3 12.5h7.5V20L3 18.5v-6zm8.5 0H21v9.5l-9.5-1.75V12.5z"/></svg>';
    } elseif (strpos($os, 'android') !== false) {
        return '<svg class="table-icon" viewBox="0 0 24 24"><path fill="#3DDC84" d="M9 3h2v2H9V3zm4 0h2v2h-2V3zM5 8h14v7a3 3 0 0 1-3 3h-8a3 3 0 0 1-3-3V8zm-1 2H3v4h1v-4zm16 0h1v4h-1v-4zM8 18h2v3H8v-3zm6 0h2v3h-2v-3z"/></svg>';
    } elseif (strpos($os, 'ios') !== false || strpos($os, 'iphone') !== false || strpos($os, 'ipad') !== false || strpos($os, 'macos') !== false) {
        return '<svg class="table-icon ink" viewBox="0 0 24 24"><path fill="#111111" d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.81-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M15.97 6.12c.64-.78 1.07-1.86.95-2.94-0.93.04-2.06.62-2.71 1.4-.58.68-1.1 1.77-.96 2.83 1.04.08 2.08-.5 2.72-1.29z"/></svg>';
    } elseif (strpos($os, 'linux') !== false) {
        return '<svg class="table-icon ink" viewBox="0 0 24 24"><path fill="#1E293B" d="M12 2C8.5 2 6 4.5 6 8c0 2.2 1 4.1 2.5 5.3-.3.8-.5 1.7-.5 2.7 0 1.1.4 2 1 2.5H8c-.6 0-1 .4-1 1s.4 1 1 1h8c.6 0 1-.4 1-1s-.4-1-1-1h-1c.6-.5 1-1.4 1-2.5 0-1-.2-1.9-.5-2.7C17 12.1 18 10.2 18 8c0-3.5-2.5-6-6-6z"/><path fill="#FFFFFF" d="M12 7c-2 0-3.5 1.5-3.5 4 0 2.2 1.3 4.2 3.5 5 2.2-.8 3.5-2.8 3.5-5 0-2.5-1.5-4-3.5-4z"/><circle cx="10.5" cy="9.5" r="0.8" fill="#1E293B"/><circle cx="13.5" cy="9.5" r="0.8" fill="#1E293B"/><polygon points="11,11 13,11 12,13" fill="#F59E0B"/><path fill="#F59E0B" d="M8 20c-.5 0-1-.4-1-1s.4-1 1-1h2.5c.3 0 .5.2.5.5s-.2.5-.5.5H8zm8 0h-2.5c-.3 0-.5-.2-.5-.5s.2-.5.5-.5H16c.5 0 1 .4 1 1s-.4 1-1 1z"/></svg>';
    }
    return '<svg class="table-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#94A3B8"/></svg>';
}

function getDeviceIcon($d) {
    $d = strtolower($d);
    if (strpos($d, 'mobile') !== false) {
        return '<svg class="table-icon ink" viewBox="0 0 24 24"><rect x="7" y="2" width="10" height="20" rx="2" fill="#334155"/><rect x="9" y="4" width="6" height="14" fill="#FFFFFF"/><circle cx="12" cy="20" r="1" fill="#FFFFFF"/></svg>';
    } elseif (strpos($d, 'tablet') !== false) {
        return '<svg class="table-icon ink" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2" fill="#334155"/><rect x="5" y="6" width="14" height="12" fill="#FFFFFF"/><circle cx="12" cy="19" r="0.8" fill="#334155"/></svg>';
    }
    return '<svg class="table-icon ink" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="13" rx="1.5" fill="#334155"/><path d="M8 20h8M12 17v3" stroke="#334155" stroke-width="2" stroke-linecap="round"/></svg>';
}

// Flag SVG for a lower-case country code (data lives in flags.php).
function getFlagSvg($cc) {
    static $flags = null;
    if ($flags === null) {
        $flags = require __DIR__ . '/flags.php';
    }
    if (!isset($flags[$cc])) {
        return null;
    }
    return '<svg class="table-icon" viewBox="0 0 640 480">' . $flags[$cc] . '</svg>';
}

// Flag for a language code: de-AT -> Austria, en-US -> USA, en-GB -> United Kingdom.
// Without a region (e.g. "en") the default country of the language is used.
function getLangIcon($lang) {
    $globe = '<svg class="table-icon" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#3b82f6"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" fill="none" stroke="#fff" stroke-width="2"/></svg>';

    $parts = preg_split('/[-_]/', strtolower(trim((string)$lang)));
    $primary = $parts[0] ?? '';
    $region = '';
    foreach (array_slice($parts, 1) as $p) {
        if (preg_match('/^[a-z]{2}$/', $p)) {
            $region = $p;
            break;
        }
    }

    if ($region !== '') {
        $svg = getFlagSvg($region);
        return $svg !== null ? $svg : $globe; // better neutral than a wrong flag
    }

    if ($primary === 'zh' && in_array('hant', $parts, true)) {
        $svg = getFlagSvg('tw');
        return $svg !== null ? $svg : $globe;
    }

    $langToCountry = [
        'de' => 'de', 'en' => 'gb', 'fr' => 'fr', 'es' => 'es', 'it' => 'it', 'nl' => 'nl',
        'pl' => 'pl', 'cs' => 'cz', 'sk' => 'sk', 'sl' => 'si', 'hr' => 'hr', 'sr' => 'rs',
        'bs' => 'ba', 'mk' => 'mk', 'sq' => 'al', 'bg' => 'bg', 'ro' => 'ro', 'hu' => 'hu',
        'el' => 'gr', 'tr' => 'tr', 'ru' => 'ru', 'uk' => 'ua', 'be' => 'by', 'sv' => 'se',
        'da' => 'dk', 'nb' => 'no', 'nn' => 'no', 'no' => 'no', 'fi' => 'fi', 'is' => 'is',
        'et' => 'ee', 'lv' => 'lv', 'lt' => 'lt', 'mt' => 'mt', 'ga' => 'ie', 'lb' => 'lu',
        'ca' => 'es', 'eu' => 'es', 'gl' => 'es', 'pt' => 'pt', 'ka' => 'ge', 'hy' => 'am',
        'az' => 'az', 'ja' => 'jp', 'zh' => 'cn', 'ko' => 'kr', 'hi' => 'in', 'he' => 'il',
        'th' => 'th', 'vi' => 'vn', 'id' => 'id', 'ms' => 'my', 'fil' => 'ph', 'tl' => 'ph',
        'af' => 'za'
    ];
    $cc = $langToCountry[$primary] ?? '';
    $svg = $cc !== '' ? getFlagSvg($cc) : null;
    return $svg !== null ? $svg : $globe;
}
