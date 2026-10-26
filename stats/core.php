<?php
/*
 * websimon-stats – Besucherstatistik ohne Cookies und ohne Datenbank
 * (c) 2026 Ralf Simon, websimon.de – MIT-Lizenz
 * https://github.com/websimonde/websimon-stats
 */
// Shared functions for the statistics (used by track.php and index.php).
//
// Works on any web server that runs PHP (Apache, nginx, LiteSpeed, IIS, ...):
// no .htaccess and no server configuration needed.
// All data files end in .php and start with an exit line, so the web server
// never hands out their content - it runs them as PHP and they print nothing.

if (defined('STATS_CORE_LOADED')) {
    return;
}
define('STATS_CORE_LOADED', true);

define('STATS_DIR', __DIR__);
define('STATS_GUARD', "<?php exit; ?>\n");
define('STATS_LOG', STATS_DIR . '/visits.log.php');
define('STATS_CONFIG', STATS_DIR . '/config.php');
define('STATS_ATTEMPTS', STATS_DIR . '/login-attempts.php');

// Demo mode: a file demo.php in the statistics folder turns it into a public
// live demo (no login, settings read-only, no counting, generated sample data).
define('STATS_DEMO', is_file(STATS_DIR . '/demo.php'));
if (STATS_DEMO) {
    require_once STATS_DIR . '/demo.php';
}

// Web path of the statistics folder, e.g. "/stats" - works for any folder name
function stats_web_path() {
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $dir = realpath(STATS_DIR);
    if ($docRoot && $dir && strpos($dir, $docRoot) === 0) {
        $p = str_replace('\\', '/', substr($dir, strlen($docRoot)));
        return '/' . trim($p, '/');
    }
    // Fallback: the folder of the running script (works when index.php itself runs)
    $script = $_SERVER['SCRIPT_NAME'] ?? '/stats/index.php';
    return rtrim(str_replace('\\', '/', dirname($script)), '/');
}

// Own host name without "www." and port, e.g. "websimon.de"
function stats_own_host() {
    if (STATS_DEMO) {
        return STATS_DEMO_HOST;
    }
    $h = strtolower($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
    $h = preg_replace('/:\d+$/', '', $h);
    return preg_replace('/^www\./', '', $h);
}

// Settings (password hash). Returns null if the statistics are not set up yet.
function stats_config() {
    if (STATS_DEMO) {
        return stats_demo_config();
    }
    if (!is_file(STATS_CONFIG)) {
        return null;
    }
    $data = @json_decode(stats_read_guarded(STATS_CONFIG), true);
    return is_array($data) && !empty($data['password_hash']) ? $data : null;
}

function stats_save_config($data) {
    if (STATS_DEMO) {
        return false;
    }
    return stats_write_guarded(STATS_CONFIG, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// How long visits are kept (months). Default: 1 year.
function stats_keep_options() {
    return [3, 6, 12, 24];
}

function stats_keep_months($config = null) {
    $config = $config ?? stats_config();
    $m = (int)($config['keep_months'] ?? 12);
    return in_array($m, stats_keep_options(), true) ? $m : 12;
}

// Do not count visits without a browser language (switch in the settings).
// Real browsers always send one, scripts disguised as a browser often don't.
// Default: on.
function stats_filter_nolang($config = null) {
    $config = $config ?? stats_config();
    return !isset($config['filter_nolang']) || $config['filter_nolang'] !== false;
}

// Read / write a protected data file (content after the exit line)
function stats_read_guarded($file) {
    $raw = @file_get_contents($file);
    if ($raw === false) {
        return '';
    }
    return strpos($raw, STATS_GUARD) === 0 ? substr($raw, strlen(STATS_GUARD)) : $raw;
}

function stats_write_guarded($file, $content) {
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, STATS_GUARD . $content, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        // Windows cannot rename over an existing file
        @unlink($file);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
    }
    return true;
}

// Make sure the protected log exists. Takes over the data of older versions
// (visits.log and visits.log-old, readable by anyone without .htaccess) once
// and removes the old files afterwards.
function stats_prepare_log() {
    if (STATS_DEMO) {
        return stats_demo_refresh();
    }
    $old = [];
    foreach (['visits.log', 'visits.log-old'] as $name) {
        if (is_file(STATS_DIR . '/' . $name)) {
            $old[] = STATS_DIR . '/' . $name;
        }
    }
    if (is_file(STATS_LOG) && !$old) {
        return true;
    }

    $lines = [];
    if (is_file(STATS_LOG)) {
        $lines = preg_split('/\R/', stats_read_guarded(STATS_LOG), -1, PREG_SPLIT_NO_EMPTY);
    }
    foreach ($old as $f) {
        $content = @file_get_contents($f);
        if ($content === false) {
            return false; // do not lose anything: try again next time
        }
        foreach (preg_split('/\R/', $content, -1, PREG_SPLIT_NO_EMPTY) as $l) {
            $lines[] = $l;
        }
    }
    // keep the log chronological (the pruning relies on it), drop exact duplicates
    $lines = array_values(array_unique($lines));
    usort($lines, function ($a, $b) {
        return (int)$a <=> (int)$b;
    });

    $body = $lines ? implode("\n", $lines) . "\n" : '';
    if (!stats_write_guarded(STATS_LOG, $body)) {
        return false;
    }
    // check what was written before deleting the old files
    $check = preg_split('/\R/', stats_read_guarded(STATS_LOG), -1, PREG_SPLIT_NO_EMPTY);
    if (count($check) === count($lines)) {
        foreach ($old as $f) {
            @unlink($f);
        }
    }
    return true;
}

// Log lines (without the exit line), oldest first
function stats_log_lines() {
    stats_prepare_log();
    $lines = @file(STATS_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [];
    }
    if (strpos($lines[0], '<?php') === 0) {
        array_shift($lines);
    }
    return $lines;
}

