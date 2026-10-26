<?php
/*
 * websimon-stats – Besucherstatistik ohne Cookies und ohne Datenbank
 * (c) 2026 Ralf Simon, websimon.de – MIT-Lizenz
 * https://github.com/websimonde/websimon-stats
 */
// Counts one page view.
//
// Recommended: include it at the very top of a PHP page - counting then
// happens on the server, without JavaScript and without cookies:
//     include_once __DIR__ . '/stats/track.php';
//
// Also works when called directly (older JavaScript snippet or as an
// invisible image): /stats/track.php?page=/path&ref=...

require_once __DIR__ . '/core.php';

if (!function_exists('stats_track')) {
    function stats_track() {
        // The live demo never counts real visits
        if (STATS_DEMO) {
            return;
        }
        $direct = (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'track.php');

        // Only real page views: GET requests, no link previews / prefetching
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && !$direct) {
            return;
        }
        $purpose = strtolower(($_SERVER['HTTP_SEC_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_PURPOSE'] ?? '') . ' ' . ($_SERVER['HTTP_X_MOZ'] ?? ''));
        if (strpos($purpose, 'prefetch') !== false || strpos($purpose, 'prerender') !== false) {
            return;
        }

        // Strip control characters and limit the length, so nobody can tamper with the log
        $clean = function ($s, $max = 200) {
            $s = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$s);
            return substr(trim($s), 0, $max);
        };

        $ownHost = stats_own_host();

        if ($direct) {
            // Only accept requests from the own site
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            if ($origin !== '') {
                $originHost = preg_replace('/^www\./', '', strtolower((string)parse_url($origin, PHP_URL_HOST)));
                if ($originHost !== $ownHost) {
                    return;
                }
            }
            $uri = $clean($_GET['page'] ?? '/', 200);
            $incomingRef = $clean($_GET['ref'] ?? '', 300);
            $lang = $clean($_GET['lang'] ?? '', 20);
        } else {
            $uri = $clean(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', 200);
            $incomingRef = $clean($_SERVER['HTTP_REFERER'] ?? '', 300);
            $lang = '';
        }

        // Language: the browser sends it anyway with every request
        if ($lang === '') {
            $al = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
            $lang = $clean(trim(explode(',', explode(';', $al)[0])[0]), 20);
        }

        // No language at all: almost always a script posing as a browser (switch in the settings)
        // (also catches nonsense values like "*" that the statistics show as "Unknown")
        if (!preg_match('/^[A-Za-z]{2,3}([-_][A-Za-z0-9]{1,8})*$/', $lang) && stats_filter_nolang()) {
            return;
        }

        // Never count the statistics themselves
        $statsPath = stats_web_path();
        if ($statsPath !== '' && (strpos($uri, $statsPath . '/') === 0 || $uri === $statsPath)) {
            return;
        }

        // No bots: real browsers always send "Mozilla/", bots usually name themselves
        $userAgent = $clean($_SERVER['HTTP_USER_AGENT'] ?? '', 400);
        if ($userAgent === '' || strpos($userAgent, 'Mozilla/') === false
            || preg_match('/bot\b|bot\/|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|curl|wget|python|monitor|uptime|'
                . 'facebookexternalhit|facebookcatalog|whatsapp|telegram|discord|slack|skype|vkshare|pinterest|'
                . 'google-inspectiontool|google-read-aloud|feedfetcher|mediapartners|adsbot|apis-google|'
                . 'bingpreview|petal|semrush|ahrefs|mj12|dotbot|yandex|baidu|bytespider|sogou|exabot|seznam|'
                . 'gptbot|chatgpt|claude|anthropic|perplexity|amazonbot|applebot|ccbot|diffbot|'
                . 'httpclient|java\/|go-http|okhttp|axios|node-fetch|scrapy|phantom|selenium|puppeteer|playwright|'
                . 'validator|checker|scanner|nagios|pingdom|statuscake|site24x7|gtmetrix/i', $userAgent)) {
            return;
        }

        $logFile = STATS_LOG;
        if (!stats_prepare_log()) {
            return;
        }

        $timestamp = time();
        $dateOnly = date('Y-m-d', $timestamp);
        $dedupeSeconds = 10; // same visitor + same page within this time = one page view

        // Browser
        $browser = 'Other';
        if (preg_match('/Edg(e|A|iOS)?\//', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR\/|Opera/', $userAgent)) {
            $browser = 'Opera';
        } elseif (strpos($userAgent, 'SamsungBrowser') !== false) {
            $browser = 'Samsung Internet';
        } elseif (preg_match('/Firefox|FxiOS/', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Chrome|CriOS/', $userAgent)) {
            $browser = 'Chrome';
        } elseif (strpos($userAgent, 'Safari') !== false) {
            $browser = 'Safari';
        }

        // Operating system
        $os = 'Other OS';
        if (strpos($userAgent, 'Android') !== false) {
            $os = 'Android';
        } elseif (preg_match('/iPhone|iPad|iPod/', $userAgent)) {
            $os = 'iOS';
        } elseif (strpos($userAgent, 'Windows') !== false) {
            $os = 'Windows';
        } elseif (strpos($userAgent, 'CrOS') !== false) {
            $os = 'ChromeOS';
        } elseif (strpos($userAgent, 'Macintosh') !== false) {
            $os = 'macOS';
        } elseif (strpos($userAgent, 'Linux') !== false || strpos($userAgent, 'X11') !== false) {
            $os = 'Linux';
        }

        // Device type
        $device = 'Desktop';
        if (preg_match('/iPad|Tablet/i', $userAgent) || (strpos($userAgent, 'Android') !== false && strpos($userAgent, 'Mobi') === false)) {
            $device = 'Tablet';
        } elseif (preg_match('/Mobi|iPhone|iPod|Android/i', $userAgent)) {
            $device = 'Mobile';
        }

        // Anonymous daily visitor id: no IP address, changes every day
        $dailyVisitorHash = substr(md5($userAgent . $dateOnly), 0, 8);

        // Referrer: only the domain, links within the own site count as "Direct"
        $referrer = 'Direct';
        if ($incomingRef !== '') {
            $parsedHost = strtolower((string)parse_url($incomingRef, PHP_URL_HOST));
            if ($parsedHost !== '' && preg_replace('/^www\./', '', $parsedHost) !== $ownHost) {
                $referrer = $clean($parsedHost, 100);
            }
        }

        // Language (only plausible values like "de", "de-DE", "en-US")
        $language = substr($lang, 0, 5);
        if (!preg_match('/^[A-Za-z0-9_-]{2,5}$/', $language)) {
            $language = 'Unknown';
        }

        $logEntry = implode("\t", [
            $timestamp, $dateOnly, $uri, $dailyVisitorHash, $browser, $os, $referrer, $language, $device
        ]) . "\n";

        // Append with an exclusive lock (avoids conflicts with the pruning in index.php)
        $fp = @fopen($logFile, 'a+');
        if (!$fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            $isDuplicate = false;
            $stat = fstat($fp);
            $size = (int)($stat['size'] ?? 0);
            if ($size > 0) {
                fseek($fp, max(0, $size - 2048));
                $tail = (string)fread($fp, 2048);
                foreach (array_reverse(explode("\n", rtrim($tail, "\n"))) as $l) {
                    $p = explode("\t", $l);
                    if (count($p) < 4 || !ctype_digit($p[0])) {
                        continue;
                    }
                    if ($timestamp - (int)$p[0] > $dedupeSeconds) {
                        break;
                    }
                    if ($p[3] === $dailyVisitorHash && $p[2] === $uri) {
                        $isDuplicate = true;
                        break;
                    }
                }
            }
            if (!$isDuplicate) {
                fwrite($fp, $logEntry);
                fflush($fp);
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}

// Counting must never break the page it is included in
try {
    stats_track();
} catch (\Throwable $e) {
    // ignore
}

// Called directly: answer without content (or with a 1x1 image for ?img)
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'track.php') {
    if (isset($_GET['img'])) {
        header('Content-Type: image/gif');
        header('Cache-Control: no-store');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    } else {
        http_response_code(204);
    }
}
