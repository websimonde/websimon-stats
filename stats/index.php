<?php
/*
 * websimon-stats – Besucherstatistik ohne Cookies und ohne Datenbank
 * (c) 2026 Ralf Simon, websimon.de – MIT-Lizenz
 * https://github.com/websimonde/websimon-stats
 */
date_default_timezone_set('Europe/Berlin');

// Login (first call: set a password) - see auth.php
require_once __DIR__ . '/auth.php';

// Merge language codes: de-DE, de-AT, de -> de
function normLang($l) {
    $l = trim((string)$l);
    if ($l === '' || $l === 'Unbekannt' || $l === 'Unknown') {
        return 'Unknown';
    }
    return strtolower(preg_replace('/[-_].*$/', '', $l));
}

$currentDomain = STATS_DEMO ? stats_own_host() : ($_SERVER['HTTP_HOST'] ?? 'not readable');

// Header logo: stats/logo.png (optional: stats/logo-dark.png for the dark theme)
$logoUrl = is_file(__DIR__ . '/logo.png') ? 'logo.png' : '';
$logoDarkUrl = is_file(__DIR__ . '/logo-dark.png') ? 'logo-dark.png' : '';
$statsPath = stats_web_path();

// Protected log (visits.log.php); older visits.log files are taken over automatically
stats_prepare_log();
$logFile = STATS_LOG;

$timestamp = time();
$updatedDate = ldate('fmt_date', $timestamp);
$updatedTime = date('H:i:s', $timestamp);
$dateOnly = date('Y-m-d', $timestamp);

// Settings: how long visits are kept (saved in config.php)
$keepMonths = stats_keep_months($statsConfig);
if (($_POST['action'] ?? '') === 'keep') {
    $newKeep = (int)($_POST['keep_months'] ?? 0);
    if (stats_csrf_ok() && in_array($newKeep, stats_keep_options(), true)) {
        $statsConfig['keep_months'] = $newKeep;
        $_SESSION['stats_flash'] = stats_save_config($statsConfig) ? 'keep_saved' : 'err_save';
    } else {
        $_SESSION['stats_flash'] = 'err_expired';
    }
    $_SESSION['stats_reopen'] = 'settings-keep';
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? './'), true, 303);
    exit;
}

// Settings: do not count visits without a browser language (saved in config.php)
$filterNoLang = stats_filter_nolang($statsConfig);
if (($_POST['action'] ?? '') === 'filter_nolang') {
    if (stats_csrf_ok()) {
        $statsConfig['filter_nolang'] = !empty($_POST['filter_nolang']);
        $_SESSION['stats_flash_filter'] = stats_save_config($statsConfig) ? 'keep_saved' : 'err_save';
    } else {
        $_SESSION['stats_flash_filter'] = 'err_expired';
    }
    $_SESSION['stats_reopen'] = 'settings-filter';
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? './'), true, 303);
    exit;
}
$filterFlash = $_SESSION['stats_flash_filter'] ?? '';
unset($_SESSION['stats_flash_filter']);

$keepFlash = $_SESSION['stats_flash'] ?? '';
$reopenSettings = $_SESSION['stats_reopen'] ?? '';
unset($_SESSION['stats_flash'], $_SESSION['stats_reopen']);

$cutoffTime = strtotime('-' . $keepMonths . ' months');
// The log is chronological: only prune when the first line is outdated.
$needsPrune = false;
if (is_readable($logFile)) {
    $fh = @fopen($logFile, 'r');
    if ($fh) {
        $firstLine = fgets($fh);
        if ($firstLine !== false && strpos($firstLine, '<?php') === 0) {
            $firstLine = fgets($fh); // skip the protection line
        }
        fclose($fh);
        $needsPrune = ($firstLine !== false && (int)$firstLine > 0 && (int)$firstLine < $cutoffTime);
    }
}
if ($needsPrune) {
    $fp = @fopen($logFile, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        $keep = [STATS_GUARD];
        while (($l = fgets($fp)) !== false) {
            if ((int)$l >= $cutoffTime) {
                $keep[] = $l;
            }
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, implode('', $keep));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    if ($fp) {
        fclose($fp);
    }
}

$now = time();
require_once __DIR__ . '/period.php';
require_once __DIR__ . '/charts.php';

// Read the log once (newest first)
$lines = stats_log_lines();
$oldest = $lines ? (int)$lines[0] : 0;
$lines = array_reverse($lines);

// Period for the chart and the tables below it
$periodPresets = stats_period_presets($keepMonths);
$period = stats_period($_GET, $oldest, $now, $periodPresets);
$pStart = $period['start'];
$pEnd = $period['end'];
$gran = stats_granularity($pStart, $pEnd);
$granMobile = stats_granularity($pStart, $pEnd, true);
$buckets = stats_buckets($pStart, $pEnd, $gran, $now);
$bucketsMobile = stats_buckets($pStart, $pEnd, $granMobile, $now);

// Query string of the current period (for links)
$periodQuery = $period['key'] === 'custom'
    ? 'p=custom&from=' . date('Y-m-d', $pStart) . '&to=' . date('Y-m-d', $pEnd)
    : 'p=' . $period['key'];

// Report (?report=1): chosen sections and rows per table, opened from the report dialog
$isReport = isset($_GET['report']);
$isEmbed = $isReport && isset($_GET['embed']); // loaded invisibly by the report dialog, printed from there
$reportSections = ['cards', 'chart', 'clients', 'languages', 'referrers', 'pages', 'visits'];
$reportDefault = ['cards', 'chart', 'clients', 'languages', 'referrers', 'pages'];
$show = array_fill_keys($reportSections, true);
$topN = null;
if ($isReport) {
    $chosen = array_values(array_intersect($reportSections, (array)($_GET['s'] ?? [])));
    $show = array_fill_keys($reportSections, false);
    foreach ($chosen ?: $reportDefault as $sec) {
        $show[$sec] = true;
    }
    $topN = in_array((int)($_GET['top'] ?? 0), [10, 25], true) ? (int)$_GET['top'] : null;
}
// Sorted, optionally shortened list for the tables
function stats_top($list, $n) {
    arsort($list);
    return $n ? array_slice($list, 0, $n, true) : $list;
}
$periodVisitList = [];

$totalViews = 0;
$totalViewsToday = 0;
$uniqueVisitorsToday = [];
$uniqueVisitorsTotal = [];
$allVisits = [];
// only for the selected period:
$periodViews = 0;
$periodVisitors = [];
$pagesCount = [];
$clientComboCount = [];
$referrerCount = [];
$languageCount = [];

// Older log lines contain German labels: map them to the English ones
$legacyLabels = ['Direktaufruf' => 'Direct', 'Unbekannt' => 'Unknown', 'Anderer' => 'Other', 'Anderes OS' => 'Other OS'];

foreach ($lines as $line) {
    $parts = explode("\t", $line);
    if (count($parts) >= 8) {
        list($t, $d, $u, $hash, $browser, $os, $ref, $lang) = $parts;
        $device = (isset($parts[8]) && $parts[8] !== '')
            ? $parts[8]
            : (in_array($os, ['Android', 'iOS'], true) ? 'Mobile' : 'Desktop'); // old lines without a device column
    } else {
        $t = $parts[0] ?? $timestamp;
        $d = $parts[1] ?? $dateOnly;
        $u = $parts[2] ?? '/';
        $hash = $parts[3] ?? 'unknown';
        $browser = $parts[4] ?? 'Other';
        $os = $parts[5] ?? 'Other OS';
        $ref = 'Direct';
        $lang = 'Unknown';
        $device = in_array($os, ['Android', 'iOS'], true) ? 'Mobile' : 'Desktop';
    }
    $t = (int)$t;
    $browser = $legacyLabels[$browser] ?? $browser;
    $os = $legacyLabels[$os] ?? $os;
    $ref = $legacyLabels[$ref] ?? $ref;
    $lang = $legacyLabels[$lang] ?? $lang;

    if (strpos($u . '/', $statsPath . '/') === 0 || strpos($u, 'stats.php') !== false) {
        continue;
    }

    // Cards and "Recent Activity": all data
    $totalViews++;
    $uniqueVisitorsTotal[$hash] = true;
    if ($d === $dateOnly) {
        $totalViewsToday++;
        $uniqueVisitorsToday[$hash] = true;
    }
    $allVisits[] = [
        'time' => date('H:i', $t),
        'date' => ldate('fmt_day', $t),
        'uri' => $u,
        'browser' => $browser,
        'os' => $os,
        'device' => $device,
        'lang' => $lang,
        'ref' => $ref
    ];

    // Chart and tables: selected period only
    if ($t < $pStart || $t > $pEnd) {
        continue;
    }
    $periodViews++;
    $periodVisitors[$d . $hash] = true;
    $periodVisitList[] = $allVisits[count($allVisits) - 1];
    $k = stats_bucket_key($t, $gran);
    if (isset($buckets[$k])) {
        $buckets[$k]['value']++;
    }
    $k = stats_bucket_key($t, $granMobile);
    if (isset($bucketsMobile[$k])) {
        $bucketsMobile[$k]['value']++;
    }
    $pagesCount[$u] = ($pagesCount[$u] ?? 0) + 1;
    $comboKey = $browser . "§" . $os . "§" . $device;
    $clientComboCount[$comboKey] = ($clientComboCount[$comboKey] ?? 0) + 1;
    $referrerCount[$ref] = ($referrerCount[$ref] ?? 0) + 1;
    $langKey = normLang($lang);
    $languageCount[$langKey] = ($languageCount[$langKey] ?? 0) + 1;
}

$uniqueCountToday = count($uniqueVisitorsToday);
$uniqueCountTotal = count($uniqueVisitorsTotal);

// Tooltip texts of the chart points
foreach ([&$buckets, &$bucketsMobile] as &$list) {
    foreach ($list as &$b) {
        $b['tip'] = t('views_tip', $b['tip'], lnum($b['value']));
    }
    unset($b);
}
unset($list);
$chartPoints = array_values($buckets);
$chartPointsMobile = array_values($bucketsMobile);

// Label of the period: name of the preset, or the dates
$periodLabel = $period['key'] === 'custom'
    ? (date('Y-m-d', $pStart) === date('Y-m-d', $pEnd)
        ? ldate('fmt_date', $pStart)
        : ldate('fmt_date', $pStart) . ' – ' . ldate('fmt_date', $pEnd))
    : t('p_' . $period['key']);
$periodDates = date('Y-m-d', $pStart) === date('Y-m-d', $pEnd)
    ? ldate('fmt_date', $pStart)
    : ldate('fmt_date', $pStart) . ' – ' . ldate('fmt_date', min($pEnd, $now));

// "Recent Activity": 12 per page, all data
$totalVisitsCount = count($allVisits);
$totalPages = max(1, ceil($totalVisitsCount / 12));
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * 12;
$recentVisits = array_slice($allVisits, $offset, 12);
if ($isReport) {
    // report: visits of the chosen period, newest first
    $recentVisits = array_slice($periodVisitList, 0, $topN ?: 500);
}
// Days in the period (for the average per day)
$periodDays = max(1, (int)round((stats_day_start(min($pEnd, $now)) - stats_day_start($pStart)) / 86400) + 1);

// Paging links keep the selected period
$pageUrl = function ($p) use ($periodQuery) {
    return '?page=' . (int)$p . '&' . $periodQuery;
};

// SVG icons (browser, OS, device, flags, UI) live in icons.php
require_once __DIR__ . '/icons.php';

// Line for including the counting in PHP pages. Starting from the web root, the same line
// works for every page, no matter in which folder the page or the statistics are.
$trackFile = realpath(__DIR__ . '/track.php');
$docRoot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$viaDocRoot = $docRoot !== '' && realpath($docRoot . $statsPath . '/track.php') === $trackFile;
if ($viaDocRoot) {
    $trackExpr = "\$_SERVER['DOCUMENT_ROOT'] . '" . $statsPath . "/track.php'";
} else {
    // fallback: full path on this server
    $trackExpr = var_export(str_replace('\\', '/', $trackFile), true);
}
$includeLine = '<?php if (is_file(' . $trackExpr . ')) { include_once ' . $trackExpr . '; } ?>';

// Has anything been counted since the statistics were set up? If not, show the help at the top.
$setupTime = strtotime($statsConfig['created'] ?? '') ?: 0;
$countedSinceSetup = false;
if (!empty($lines)) {
    foreach ($lines as $l) { // newest first
        if ((int)$l >= $setupTime) {
            $countedSinceSetup = true;
        }
        break;
    }
}

// Settings panel (overlay): theme and how to include the counting
function renderSettingsPanel($includeLine, $viaDocRoot, $keepMonths, $oldest, $keepFlash, $filterNoLang = true, $filterFlash = '') {
    ?>
    <dialog class="settings-panel" id="settings-panel" aria-labelledby="settings-title">
        <div class="sp-head">
            <h2 id="settings-title"><?php echo te('settings'); ?></h2>
            <button type="button" class="sp-close" id="settings-close" aria-label="<?php echo te('close_settings'); ?>">&times;</button>
        </div>

        <section class="sp-section">
            <h3><?php echo te('appearance'); ?></h3>
            <div class="sp-segmented" role="radiogroup" aria-label="<?php echo te('theme'); ?>">
                <label><input type="radio" name="theme" value="light"> <?php echo te('light'); ?></label>
                <label><input type="radio" name="theme" value="dark"> <?php echo te('dark'); ?></label>
                <label><input type="radio" name="theme" value="system"> <?php echo te('system'); ?></label>
            </div>
            <p class="sp-hint"><?php echo te('theme_hint'); ?></p>
        </section>

        <section class="sp-section">
            <h3><?php echo te('language'); ?></h3>
            <select class="btn-range sp-lang" id="lang-select" aria-label="<?php echo te('language'); ?>">
                <?php foreach (stats_languages() as $code => $name): ?>
                    <option value="<?php echo htmlspecialchars($code); ?>"<?php echo $code === stats_lang() ? ' selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="sp-hint"><?php echo te('lang_hint'); ?></p>
        </section>

        <section class="sp-section" id="settings-keep">
            <h3><?php echo te('keep_title'); ?></h3>
            <form method="post" action="" class="sp-keep-form">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(stats_csrf_token()); ?>">
                <input type="hidden" name="action" value="keep">
                <div class="sp-keep-row">
                    <div class="sp-segmented" role="radiogroup" aria-label="<?php echo te('keep_title'); ?>">
                        <?php foreach (stats_keep_options() as $m):
                            $cut = strtotime('-' . $m . ' months');
                            $loses = ($m < $keepMonths && $oldest && $oldest < $cut);
                        ?>
                            <label><input type="radio" name="keep_months" value="<?php echo $m; ?>"<?php echo $m === $keepMonths ? ' checked' : ''; ?><?php echo $loses ? ' data-before="' . htmlspecialchars(ldate('fmt_date', $cut)) . '"' : ''; ?>> <?php echo te('keep_' . $m); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn-range active" id="keep-save" data-current="<?php echo $keepMonths; ?>"><?php echo te('save'); ?></button>
                </div>
                <p class="sp-warn" id="keep-warn" hidden data-text="<?php echo te('keep_warn'); ?>"></p>
                <?php if ($keepFlash === 'keep_saved'): ?>
                    <p class="sp-ok"><?php echo te('keep_saved'); ?></p>
                <?php elseif ($keepFlash !== ''): ?>
                    <p class="sp-warn sp-flash"><?php echo te($keepFlash); ?></p>
                <?php endif; ?>
            </form>
            <p class="sp-hint"><?php echo te('keep_hint'); ?></p>
        </section>

        <section class="sp-section" id="settings-filter">
            <h3><?php echo te('filter_title'); ?></h3>
            <form method="post" action="" class="sp-filter-form">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(stats_csrf_token()); ?>">
                <input type="hidden" name="action" value="filter_nolang">
                <label class="sp-switch">
                    <input type="checkbox" name="filter_nolang" value="1"<?php echo $filterNoLang ? ' checked' : ''; ?>>
                    <span class="sp-switch-track" aria-hidden="true"></span>
                    <span><?php echo te('filter_nolang'); ?></span>
                </label>
                <noscript><button type="submit" class="btn-range active"><?php echo te('save'); ?></button></noscript>
                <?php if ($filterFlash === 'keep_saved'): ?>
                    <p class="sp-ok"><?php echo te('keep_saved'); ?></p>
                <?php elseif ($filterFlash !== ''): ?>
                    <p class="sp-warn sp-flash"><?php echo te($filterFlash); ?></p>
                <?php endif; ?>
            </form>
            <p class="sp-hint"><?php echo te('filter_hint'); ?></p>
        </section>

        <section class="sp-section" id="settings-include">
            <h3><?php echo te('count_title'); ?></h3>
            <p><?php echo t('count_intro'); ?></p>
            <div class="code-row">
                <code id="include-line"><?php echo htmlspecialchars($includeLine); ?></code>
                <button type="button" class="btn-range" id="copy-line" data-copied="<?php echo te('copied'); ?>"><?php echo te('copy'); ?></button>
            </div>
            <ul class="sp-list">
                <li><?php echo t('count_hint_1'); ?></li>
                <li><?php echo t('count_hint_2'); ?></li>
                <li><?php echo t('count_hint_3'); ?></li>
                <?php if (!$viaDocRoot): ?>
                    <li><?php echo t('count_hint_4'); ?></li>
                <?php endif; ?>
            </ul>
        </section>
    </dialog>
    <?php
}

// Report dialog: period and contents, then the report opens in a new tab
function renderReportPanel($period, $periodPresets, $periodLabel, $pStart, $pEnd, $oldest, $now) {
    $min = $oldest ? date('Y-m-d', $oldest) : '';
    $max = date('Y-m-d', $now);
    $isCustom = $period['key'] === 'custom';
    ?>
    <dialog class="settings-panel report-panel" id="report-panel" aria-labelledby="report-title">
        <form method="get" action="./" target="_blank" id="report-form">
            <input type="hidden" name="report" value="1">
            <div class="sp-head">
                <h2 id="report-title"><?php echo te('report'); ?></h2>
                <button type="button" class="sp-close" data-close-report aria-label="<?php echo te('report_close'); ?>">&times;</button>
            </div>

            <section class="sp-section">
                <h3><?php echo te('report_period'); ?></h3>
                <div class="rp-period">
                    <select name="p" class="btn-range rp-select" aria-label="<?php echo te('report_period'); ?>">
                        <?php foreach ($periodPresets as $key): ?>
                            <option value="<?php echo $key; ?>"<?php echo $period['key'] === $key ? ' selected' : ''; ?>><?php echo te('p_' . $key); ?></option>
                        <?php endforeach; ?>
                        <option value="custom"<?php echo $isCustom ? ' selected' : ''; ?>><?php echo te('p_custom'); ?></option>
                    </select>
                    <span class="rp-custom"<?php echo $isCustom ? '' : ' hidden'; ?>>
                        <label><span><?php echo te('from'); ?></span>
                            <input type="date" name="from" class="btn-range" value="<?php echo date('Y-m-d', $pStart); ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>"<?php echo $isCustom ? '' : ' disabled'; ?>>
                        </label>
                        <label><span><?php echo te('to'); ?></span>
                            <input type="date" name="to" class="btn-range" value="<?php echo date('Y-m-d', min($pEnd, $now)); ?>" min="<?php echo $min; ?>" max="<?php echo $max; ?>"<?php echo $isCustom ? '' : ' disabled'; ?>>
                        </label>
                    </span>
                </div>
            </section>

            <section class="sp-section">
                <h3><?php echo te('report_scope'); ?></h3>
                <div class="rp-checks">
                    <?php foreach (['cards' => 'r_cards', 'chart' => 'r_chart', 'clients' => 'clients', 'languages' => 'languages', 'referrers' => 'referrers', 'pages' => 'top_pages', 'visits' => 'r_visits'] as $sec => $label): ?>
                        <label><input type="checkbox" name="s[]" value="<?php echo $sec; ?>"<?php echo $sec !== 'visits' ? ' checked' : ''; ?>> <?php echo te($label); ?></label>
                    <?php endforeach; ?>
                </div>
                <p class="sp-warn" id="report-none" hidden><?php echo te('report_none'); ?></p>
            </section>

            <section class="sp-section">
                <h3><?php echo te('report_rows'); ?></h3>
                <div class="sp-segmented" role="radiogroup" aria-label="<?php echo te('report_rows'); ?>">
                    <label><input type="radio" name="top" value="10" checked> 10</label>
                    <label><input type="radio" name="top" value="25"> 25</label>
                    <label><input type="radio" name="top" value="0"> <?php echo te('rows_all'); ?></label>
                </div>
            </section>

            <div class="rp-actions">
                <p class="sp-hint"><?php echo te('report_hint'); ?></p>
                <div class="rp-buttons">
                    <button type="button" class="btn-range" data-close-report><?php echo te('cancel'); ?></button>
                    <button type="submit" class="btn-range active" id="report-submit"><?php echo getUiIcon('print'); ?><?php echo te('report_go'); ?></button>
                </div>
            </div>
        </form>
    </dialog>
    <?php
}

?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(stats_lang()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isReport ? te('report_heading') . ' ' . htmlspecialchars($currentDomain) . ' – ' . htmlspecialchars($periodDates) : te('title') . ' - ' . htmlspecialchars($currentDomain); ?></title>
    <script>
        (function () {
            try {
                var t = localStorage.getItem('stats-theme');
                if (t !== 'dark' && t !== 'light') {
                    t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
                }
                document.documentElement.setAttribute('data-theme', t);
            } catch (e) {}
        })();
    </script>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="stats.css?v=<?php echo @filemtime(__DIR__ . '/stats.css'); ?>">
</head>
<body<?php echo $isReport ? ' class="is-report"' : ''; ?>>

    <div class="site-header">
        <div class="header-inner">
            <?php if ($logoUrl !== ''): ?>
                <span class="header-logo">
                    <img class="logo-light<?php echo $logoDarkUrl !== '' ? ' has-dark' : ''; ?>" src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars(stats_own_host()); ?>" width="129" height="32">
                    <?php if ($logoDarkUrl !== ''): ?>
                        <img class="logo-dark" src="<?php echo htmlspecialchars($logoDarkUrl); ?>" alt="<?php echo htmlspecialchars(stats_own_host()); ?>" width="129" height="32">
                    <?php endif; ?>
                </span>
            <?php endif; ?>
            <h1><?php echo $isReport ? te('report_heading') : te('stats_for'); ?> <span><?php echo htmlspecialchars($currentDomain); ?></span></h1>
            <?php if (!$isReport): ?>
            <button class="btn-theme" id="settings-open" type="button" title="<?php echo te('settings'); ?>" aria-label="<?php echo te('settings'); ?>" aria-haspopup="dialog">
                <?php echo getUiIcon('settings'); ?>
            </button>
            <?php if (!STATS_DEMO): ?>
            <form class="logout-form" method="post" action="">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(stats_csrf_token()); ?>">
                <input type="hidden" name="action" value="logout">
                <button class="btn-logout" type="submit" title="<?php echo te('logout'); ?>" aria-label="<?php echo te('logout'); ?>">
                    <?php echo getUiIcon('logout'); ?>
                </button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="container">

        <?php if ($isReport && !$isEmbed): ?>
            <div class="report-toolbar">
                <button type="button" class="btn-range active" onclick="window.print();"><?php echo getUiIcon('print'); ?><?php echo te('report_print'); ?></button>
                <button type="button" class="btn-range" onclick="window.close();"><?php echo te('report_close'); ?></button>
            </div>
        <?php endif; ?>
        <?php if ($isReport): ?>
            <div class="report-meta">
                <strong><?php echo te('period'); ?>: <?php echo htmlspecialchars($period['key'] === 'custom' ? $periodDates : $periodLabel . ' (' . $periodDates . ')'); ?></strong>
                <span><?php echo te('report_created', htmlspecialchars(ldate('fmt_date', $now) . ', ' . date('H:i', $now))); ?></span>
            </div>
            <?php if ($show['cards']): ?>
            <div class="cards report-cards">
                <div class="card">
                    <?php echo getUiIcon('views-total'); ?>
                    <div class="title"><?php echo te('page_views'); ?></div>
                    <div class="sub-title"><?php echo te('period'); ?></div>
                    <div class="number"><?php echo lnum($periodViews); ?></div>
                </div>
                <div class="card">
                    <?php echo getUiIcon('visitors-total'); ?>
                    <div class="title"><?php echo te('visitors'); ?></div>
                    <div class="sub-title"><?php echo te('period'); ?></div>
                    <div class="number"><?php echo lnum(count($periodVisitors)); ?></div>
                </div>
                <div class="card">
                    <?php echo getUiIcon('views-today'); ?>
                    <div class="title"><?php echo te('page_views'); ?></div>
                    <div class="sub-title"><?php echo te('avg_day'); ?></div>
                    <div class="number"><?php echo lnum(round($periodViews / $periodDays)); ?></div>
                </div>
                <div class="card">
                    <?php echo getUiIcon('visitors-today'); ?>
                    <div class="title"><?php echo te('visitors'); ?></div>
                    <div class="sub-title"><?php echo te('avg_day'); ?></div>
                    <div class="number"><?php echo lnum(round(count($periodVisitors) / $periodDays)); ?></div>
                </div>
            </div>
            <?php endif; ?>
        <?php else: ?>

        <?php if (STATS_DEMO): ?>
            <div class="setup-banner demo-banner">
                <span><?php echo te('demo_banner'); ?></span>
            </div>
        <?php endif; ?>
        <?php if (!$countedSinceSetup && !STATS_DEMO): ?>
            <div class="setup-banner">
                <span><?php echo te('banner'); ?></span>
                <button type="button" class="btn-range" data-open-settings="settings-include"><?php echo te('banner_button'); ?></button>
            </div>
        <?php endif; ?>
        <?php renderSettingsPanel($includeLine, $viaDocRoot, $keepMonths, $oldest, $keepFlash, $filterNoLang, $filterFlash); ?>

        <div class="cards">
            <div class="card">
                <?php echo getUiIcon('views-today'); ?>
                <div class="title"><?php echo te('page_views'); ?></div>
                <div class="sub-title"><?php echo te('today'); ?></div>
                <div class="number"><?php echo lnum($totalViewsToday); ?></div>
            </div>
            <div class="card">
                <?php echo getUiIcon('views-total'); ?>
                <div class="title"><?php echo te('page_views'); ?></div>
                <div class="sub-title"><?php echo te('total'); ?></div>
                <div class="number"><?php echo lnum($totalViews); ?></div>
            </div>
            <div class="card">
                <?php echo getUiIcon('visitors-today'); ?>
                <div class="title"><?php echo te('visitors'); ?></div>
                <div class="sub-title"><?php echo te('today'); ?></div>
                <div class="number"><?php echo lnum($uniqueCountToday); ?></div>
            </div>
            <div class="card">
                <?php echo getUiIcon('visitors-total'); ?>
                <div class="title"><?php echo te('visitors'); ?></div>
                <div class="sub-title"><?php echo te('total'); ?></div>
                <div class="number"><?php echo lnum($uniqueCountTotal); ?></div>
            </div>
            <div class="card">
                <?php echo getUiIcon('updated'); ?>
                <div class="title"><?php echo te('updated'); ?></div>
                <div class="number" style="font-size: 0.95rem; margin-top: 1px;"><?php echo $updatedDate; ?></div>
                <div class="time-sub"><?php echo $updatedTime; ?></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($show['visits']): ?>
        <?php if ($isReport) { ob_start(); } // report: the list is printed at the end ?>
        <div class="section section-visits">
            <div class="section-header">
                <h2><?php echo $isReport ? te('r_visits') : te('recent'); ?></h2>
                <?php if ($isReport): ?>
                    <?php if (count($recentVisits) < count($periodVisitList)): ?>
                        <span class="chart-note"><?php echo te('report_limited', lnum(count($recentVisits)), lnum(count($periodVisitList))); ?></span>
                    <?php endif; ?>
                <?php else: ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo $pageUrl(1); ?>" title="<?php echo te('first_page'); ?>"><?php echo te('first'); ?></a>
                        <a href="<?php echo $pageUrl($page - 1); ?>" title="<?php echo te('previous_page'); ?>">&lsaquo;<span class="pg-text"> <?php echo te('previous'); ?></span></a>
                    <?php else: ?>
                        <span class="disabled"><?php echo te('first'); ?></span>
                        <span class="disabled">&lsaquo;<span class="pg-text"> <?php echo te('previous'); ?></span></span>
                    <?php endif; ?>

                    <form class="pg-jump" method="get" action="./">
                        <input type="hidden" name="p" value="<?php echo htmlspecialchars($period['key']); ?>">
                        <?php if ($period['key'] === 'custom'): ?>
                            <input type="hidden" name="from" value="<?php echo date('Y-m-d', $pStart); ?>">
                            <input type="hidden" name="to" value="<?php echo date('Y-m-d', $pEnd); ?>">
                        <?php endif; ?>
                        <label><?php echo te('page'); ?>
                            <input type="number" name="page" min="1" max="<?php echo $totalPages; ?>" value="<?php echo $page; ?>" inputmode="numeric" onchange="statsRememberScroll(); this.form.submit()">
                            <?php echo te('of'); ?> <?php echo lnum($totalPages); ?>
                        </label>
                    </form>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?php echo $pageUrl($page + 1); ?>" title="<?php echo te('next_page'); ?>"><span class="pg-text"><?php echo te('next'); ?> </span>&rsaquo;</a>
                        <a href="<?php echo $pageUrl($totalPages); ?>" title="<?php echo te('last_page'); ?>"><?php echo te('last'); ?></a>
                    <?php else: ?>
                        <span class="disabled"><span class="pg-text"><?php echo te('next'); ?> </span>&rsaquo;</span>
                        <span class="disabled"><?php echo te('last'); ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="table-responsive">
                <table class="activity-table">
                    <tr>
                        <th class="col-time"><?php echo te('time'); ?></th>
                        <th><?php echo te('page'); ?></th>
                        <th colspan="4"><?php echo te('client'); ?></th>
                        <th><?php echo te('referrer'); ?></th>
                    </tr>
                    <?php 
                    if (!empty($recentVisits)) {
                        foreach ($recentVisits as $visit) {
                            $bIcon = getBrowserIcon($visit['browser']);
                            $oIcon = getOsIcon($visit['os']);
                            $dIcon = getDeviceIcon($visit['device']);
                            $lIcon = getLangIcon($visit['lang']);
                            echo "<tr>";
                            echo "<td class='col-time'><div class='time-main'>" . $visit['time'] . "</div><div class='date-sub'>" . $visit['date'] . "</div></td>";
                            $uriParts = preg_split('~(?<=.)(?=/)~', $visit['uri']);
                            $uriHtml = implode('<wbr>', array_map(function ($p) {
                                return '<span class="nb">' . htmlspecialchars($p) . '</span>';
                            }, $uriParts));
                            echo "<td class='col-page'>" . $uriHtml . "</td>";
                            echo "<td class='col-c col-c-first'><span class='client-item' title='" . te('browser') . ": " . htmlspecialchars(tv($visit['browser'])) . "'>" . $bIcon . "<span class='client-label'>" . htmlspecialchars(tv($visit['browser'])) . "</span></span></td>";
                            echo "<td class='col-c'><span class='client-item' title='" . te('os') . ": " . htmlspecialchars(tv($visit['os'])) . "'>" . $oIcon . "<span class='client-label'>" . htmlspecialchars(tv($visit['os'])) . "</span></span></td>";
                            echo "<td class='col-c'><span class='client-item' title='" . te('device') . ": " . htmlspecialchars(tv($visit['device'])) . "'>" . $dIcon . "<span class='client-label'>" . htmlspecialchars(tv($visit['device'])) . "</span></span></td>";
                            echo "<td class='col-c col-c-last'><span class='client-item' title='" . te('language') . ": " . htmlspecialchars(tv($visit['lang'])) . "'>" . $lIcon . "<span class='lang-label'>" . htmlspecialchars(tv($visit['lang'])) . "</span></span></td>";
                            echo "<td class='col-ref' title='" . htmlspecialchars(tv($visit['ref'])) . "'>" . str_replace('.', '.<wbr>', htmlspecialchars(tv($visit['ref']))) . "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='7' style='color:var(--text-muted);'>" . te('no_data') . "</td></tr>";
                    }
                    ?>
                </table>
            </div>
        </div>
        <?php if ($isReport) { $visitsHtml = ob_get_clean(); } ?>
        <?php endif; ?>

        <?php if (!$isReport): ?>
        <!-- Period: applies to the chart and all tables below -->
        <div class="period-bar" id="period">
            <form class="period-form" method="get" action="./#period">
                <span class="period-title"><?php echo getUiIcon('calendar'); ?><?php echo te('period'); ?></span>
                <?php if ($period['prev']): ?>
                    <a class="btn-range period-arrow" href="?p=custom&amp;from=<?php echo $period['prev']['from']; ?>&amp;to=<?php echo $period['prev']['to']; ?>#period" title="<?php echo te('prev_period'); ?>" aria-label="<?php echo te('prev_period'); ?>">&lsaquo;</a>
                <?php else: ?>
                    <span class="btn-range period-arrow disabled" aria-hidden="true">&lsaquo;</span>
                <?php endif; ?>
                <select name="p" class="btn-range period-select" aria-label="<?php echo te('period'); ?>">
                    <?php foreach ($periodPresets as $key): ?>
                        <option value="<?php echo $key; ?>"<?php echo $period['key'] === $key ? ' selected' : ''; ?>><?php echo te('p_' . $key); ?></option>
                    <?php endforeach; ?>
                    <option value="custom"<?php echo $period['key'] === 'custom' ? ' selected' : ''; ?>><?php echo $period['key'] === 'custom' ? htmlspecialchars($periodLabel) : te('p_custom'); ?></option>
                </select>
                <?php if ($period['next']): ?>
                    <a class="btn-range period-arrow" href="?p=custom&amp;from=<?php echo $period['next']['from']; ?>&amp;to=<?php echo $period['next']['to']; ?>#period" title="<?php echo te('next_period'); ?>" aria-label="<?php echo te('next_period'); ?>">&rsaquo;</a>
                <?php else: ?>
                    <span class="btn-range period-arrow disabled" aria-hidden="true">&rsaquo;</span>
                <?php endif; ?>
                <span class="period-custom"<?php echo $period['key'] === 'custom' ? '' : ' hidden'; ?>>
                    <label><span><?php echo te('from'); ?></span>
                        <input type="date" name="from" class="btn-range" value="<?php echo date('Y-m-d', $pStart); ?>" min="<?php echo $oldest ? date('Y-m-d', $oldest) : ''; ?>" max="<?php echo date('Y-m-d', $now); ?>">
                    </label>
                    <label><span><?php echo te('to'); ?></span>
                        <input type="date" name="to" class="btn-range" value="<?php echo date('Y-m-d', min($pEnd, $now)); ?>" min="<?php echo $oldest ? date('Y-m-d', $oldest) : ''; ?>" max="<?php echo date('Y-m-d', $now); ?>">
                    </label>
                    <button type="submit" class="btn-range active"><?php echo te('apply'); ?></button>
                </span>
                <span class="period-dates"><?php echo htmlspecialchars($periodDates); ?> &middot; <?php echo te('views_count', lnum($periodViews)); ?> &middot; <?php echo te('visitors_count', lnum(count($periodVisitors))); ?></span>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($show['chart']): ?>
        <div class="section">
            <div class="section-header">
                <h2><?php echo te('traffic'); ?></h2>
            </div>
            <?php
            $useBars = count($chartPoints) <= 31;
            $useBarsMobile = count($chartPointsMobile) <= 31;
            ?>
            <div class="chart-box">
                <div class="chart-desktop">
                    <div class="chart-note"><?php echo te('per_' . $gran); ?></div>
                    <?php echo $useBars
                        ? renderBarChart($chartPoints, ['width' => 1000, 'maxLabels' => 16])
                        : renderLineChart($chartPoints, ['width' => 1000, 'maxLabels' => 12]); ?>
                </div>
                <div class="chart-mobile">
                    <div class="chart-note"><?php echo te('per_' . $granMobile); ?></div>
                    <?php echo $useBarsMobile
                        ? renderBarChart($chartPointsMobile, ['width' => 420, 'padX' => 8, 'values' => false, 'maxLabels' => 6, 'fontLbl' => 13])
                        : renderLineChart($chartPointsMobile, ['width' => 420, 'padX' => 30, 'maxLabels' => 4, 'fontLbl' => 13]); ?>
                </div>
                <div class="chart-tip" role="status" aria-live="polite" hidden></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($show['clients'] || $show['languages']): ?>
        <div class="section">
            <div class="grid-row">
                <?php if ($show['clients']): ?>
                <div class="grid-col">
                    <div class="section-header"><h2><?php echo te('clients'); ?></h2></div>
                    <div class="table-responsive">
                        <table class="clients-table">
                            <tr>
                                <th><?php echo te('browser'); ?></th>
                                <th><?php echo te('os'); ?></th>
                                <th><?php echo te('device'); ?></th>
                                <th class="col-num"><?php echo te('views'); ?></th>
                            </tr>
                            <?php 
                            if (!empty($clientComboCount)) {
                                foreach (stats_top($clientComboCount, $topN) as $combo => $count) {
                                    list($bName, $oName, $dName) = explode("§", $combo);
                                    $bIcon = getBrowserIcon($bName);
                                    $oIcon = getOsIcon($oName);
                                    $dIcon = getDeviceIcon($dName);
                                    echo "<tr>";
                                    echo "<td><div class='table-cell-flex'>" . $bIcon . "<span>" . htmlspecialchars(tv($bName)) . "</span></div></td>";
                                    echo "<td><div class='table-cell-flex'>" . $oIcon . htmlspecialchars(tv($oName)) . "</div></td>";
                                    echo "<td><div class='table-cell-flex'>" . $dIcon . htmlspecialchars(tv($dName)) . "</div></td>";
                                    echo "<td class='col-num'><strong>" . lnum($count) . "</strong></td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='4' style='color:var(--text-muted);'>" . te('no_data') . "</td></tr>";
                            }
                            ?>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($show['languages']): ?>
                <div class="grid-col">
                    <div class="section-header"><h2><?php echo te('languages'); ?></h2></div>
                    <div class="table-responsive">
                        <table>
                            <tr>
                                <th><?php echo te('language'); ?></th>
                                <th class="col-num"><?php echo te('views'); ?></th>
                            </tr>
                            <?php 
                            if (!empty($languageCount)) {
                                foreach (stats_top($languageCount, $topN) as $lang => $count) {
                                    $lIcon = getLangIcon($lang);
                                    echo "<tr><td><div class='table-cell-flex'>" . $lIcon . "<span>" . htmlspecialchars(tv($lang)) . "</span></div></td><td class='col-num'><strong>" . lnum($count) . "</strong></td></tr>";
                                }
                            } else {
                                echo "<tr><td colspan='2' style='color:var(--text-muted);'>" . te('no_data') . "</td></tr>";
                            }
                            ?>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($show['referrers'] || $show['pages']): ?>
        <div class="section">
            <div class="grid-row">
                <?php if ($show['referrers']): ?>
                <div class="grid-col">
                    <div class="section-header"><h2><?php echo te('referrers'); ?></h2></div>
                    <div class="table-responsive">
                        <table class="text-table">
                            <tr>
                                <th><?php echo te('source'); ?></th>
                                <th class="col-num"><?php echo te('views'); ?></th>
                            </tr>
                            <?php 
                            if (!empty($referrerCount)) {
                                foreach (stats_top($referrerCount, $topN) as $ref => $count) {
                                    echo "<tr><td>" . htmlspecialchars(tv($ref)) . "</td><td class='col-num'><strong>" . lnum($count) . "</strong></td></tr>";
                                }
                            } else {
                                echo "<tr><td colspan='2' style='color:var(--text-muted);'>" . te('no_data') . "</td></tr>";
                            }
                            ?>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($show['pages']): ?>
                <div class="grid-col">
                    <div class="section-header"><h2><?php echo te('top_pages'); ?></h2></div>
                    <div class="table-responsive">
                        <table class="text-table">
                            <tr>
                                <th><?php echo te('page_uri'); ?></th>
                                <th class="col-num"><?php echo te('views'); ?></th>
                            </tr>
                            <?php 
                            if (!empty($pagesCount)) {
                                foreach (stats_top($pagesCount, $topN) as $pageUri => $count) {
                                    echo "<tr><td>" . htmlspecialchars($pageUri) . "</td><td class='col-num'><strong>" . lnum($count) . "</strong></td></tr>";
                                }
                            } else {
                                echo "<tr><td colspan='2' style='color:var(--text-muted);'>" . te('no_data') . "</td></tr>";
                            }
                            ?>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php echo $visitsHtml ?? ''; ?>

        <?php if (!$isReport): ?>
            <div class="report-bar">
                <button type="button" class="btn-report" id="report-open" aria-haspopup="dialog"><?php echo getUiIcon('report'); ?><?php echo te('report'); ?></button>
            </div>
            <?php renderReportPanel($period, $periodPresets, $periodLabel, $pStart, $pEnd, $oldest, $now); ?>
        <?php endif; ?>

        <div class="site-footer">&copy; <?php echo date('Y'); ?> <a href="https://websimon.de">websimon.de</a></div>

    </div>

    <?php if ($isEmbed): ?>
    <?php elseif ($isReport): ?>
    <script>
        // Report: open the print dialog right away (print or save as PDF)
        window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
    </script>
    <?php else: ?>
    <script>
        // Remember the scroll position when paging / switching the range, so the page does not jump to the top
        function statsRememberScroll() {
            try { sessionStorage.setItem('stats-scroll', String(window.pageYOffset)); } catch (e) {}
        }
        (function () {
            try {
                var y = sessionStorage.getItem('stats-scroll');
                if (y !== null) {
                    sessionStorage.removeItem('stats-scroll');
                    window.scrollTo(0, parseInt(y, 10) || 0);
                }
            } catch (e) {}
            document.addEventListener('click', function (e) {
                var a = e.target.closest ? e.target.closest('a[href^="?"]') : null;
                if (a) { statsRememberScroll(); }
            });

            // Period: presets apply at once, "custom" shows the two date fields
            var pForm = document.querySelector('.period-form');
            if (pForm) {
                var pSelect = pForm.querySelector('.period-select');
                var pCustom = pForm.querySelector('.period-custom');
                pSelect.addEventListener('change', function () {
                    if (pSelect.value === 'custom') {
                        pCustom.hidden = false;
                        var f = pCustom.querySelector('input[name=from]');
                        if (f) { f.focus(); }
                    } else {
                        pCustom.hidden = true;
                        pForm.submit();
                    }
                });
            }

            // Chart tooltips: hover with the mouse, tap on the phone
            document.querySelectorAll('.chart-box').forEach(function (box) {
                var tip = box.querySelector('.chart-tip');
                function hide() {
                    tip.hidden = true;
                    box.querySelectorAll('.chart-marker').forEach(function (m) { m.setAttribute('cx', -10); m.setAttribute('cy', -10); });
                    box.querySelectorAll('.hit.is-active').forEach(function (h) { h.classList.remove('is-active'); });
                }
                function show(hit, clientX) {
                    var svg = hit.ownerSVGElement;
                    var text = hit.getAttribute('data-tip');
                    if (!text) { return; }
                    box.querySelectorAll('.hit.is-active').forEach(function (h) { h.classList.remove('is-active'); });
                    hit.classList.add('is-active');
                    var marker = svg.querySelector('.chart-marker');
                    if (marker && hit.hasAttribute('data-x')) {
                        marker.setAttribute('cx', hit.getAttribute('data-x'));
                        marker.setAttribute('cy', hit.getAttribute('data-y'));
                    }
                    tip.textContent = text;
                    tip.hidden = false;
                    var b = box.getBoundingClientRect();
                    var h = hit.getBoundingClientRect();
                    var x = h.left + h.width / 2 - b.left;
                    var w = tip.offsetWidth;
                    tip.style.left = Math.max(4, Math.min(b.width - w - 4, x - w / 2)) + 'px';
                }
                box.addEventListener('pointermove', function (e) {
                    if (e.pointerType === 'mouse' && e.target.classList && e.target.classList.contains('hit')) { show(e.target, e.clientX); }
                });
                box.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') { hide(); } });
                box.addEventListener('click', function (e) {
                    if (e.target.classList && e.target.classList.contains('hit')) { show(e.target, e.clientX); }
                });
                document.addEventListener('click', function (e) { if (!box.contains(e.target)) { hide(); } });
            });

            // Settings panel
            var root = document.documentElement;
            var panel = document.getElementById('settings-panel');
            var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

            function storedTheme() {
                try { return localStorage.getItem('stats-theme'); } catch (e) { return null; }
            }
            function applyTheme(choice) {
                var t = (choice === 'light' || choice === 'dark') ? choice : ((media && media.matches) ? 'dark' : 'light');
                root.setAttribute('data-theme', t);
            }
            function openPanel(focusId) {
                var current = storedTheme();
                var value = (current === 'light' || current === 'dark') ? current : 'system';
                panel.querySelectorAll('input[name=theme]').forEach(function (r) { r.checked = (r.value === value); });
                if (panel.showModal) { panel.showModal(); } else { panel.setAttribute('open', ''); }
                if (focusId) {
                    var target = document.getElementById(focusId);
                    if (target) { target.scrollIntoView({ block: 'start' }); }
                }
            }
            function closePanel() {
                if (panel.close) { panel.close(); } else { panel.removeAttribute('open'); }
            }

            document.getElementById('settings-open').addEventListener('click', function () { openPanel(); });
            document.querySelectorAll('[data-open-settings]').forEach(function (b) {
                b.addEventListener('click', function () { openPanel(b.getAttribute('data-open-settings')); });
            });
            document.getElementById('settings-close').addEventListener('click', closePanel);
            // click on the dark background closes the panel
            panel.addEventListener('click', function (e) {
                if (e.target === panel) { closePanel(); }
            });

            panel.querySelectorAll('input[name=theme]').forEach(function (r) {
                r.addEventListener('change', function () {
                    try {
                        if (r.value === 'system') { localStorage.removeItem('stats-theme'); }
                        else { localStorage.setItem('stats-theme', r.value); }
                    } catch (e) {}
                    applyTheme(r.value);
                });
            });
            // "System": follow changes of the device setting
            if (media && media.addEventListener) {
                media.addEventListener('change', function () {
                    var c = storedTheme();
                    if (c !== 'light' && c !== 'dark') { applyTheme('system'); }
                });
            }

            // Language: saved in a cookie for this folder, the page is rebuilt in the new language
            var langSelect = document.getElementById('lang-select');
            langSelect.addEventListener('change', function () {
                document.cookie = 'stats_lang=' + langSelect.value + '; path=<?php echo htmlspecialchars($statsPath); ?>/; max-age=31536000; samesite=Lax';
                try { sessionStorage.setItem('stats-reopen-settings', '1'); } catch (e) {}
                statsRememberScroll();
                location.reload();
            });
            // Keep data: save button only after a change, warning before older data is deleted
            var keepSave = document.getElementById('keep-save');
            var keepWarn = document.getElementById('keep-warn');
            function keepUpdate() {
                var r = panel.querySelector('input[name=keep_months]:checked');
                keepSave.disabled = !r || r.value === keepSave.getAttribute('data-current');
                var before = r ? r.getAttribute('data-before') : null;
                keepWarn.hidden = !before;
                if (before) { keepWarn.textContent = keepWarn.getAttribute('data-text').replace('%s', before); }
            }
            panel.querySelectorAll('input[name=keep_months]').forEach(function (r) {
                r.addEventListener('change', function () {
                    // the "Saved" note only belongs to the last save: hide it after a new choice
                    panel.querySelectorAll('.sp-keep-form .sp-ok, .sp-keep-form .sp-flash').forEach(function (m) { m.hidden = true; });
                    keepUpdate();
                });
            });
            keepUpdate();
            panel.querySelector('.sp-keep-form').addEventListener('submit', statsRememberScroll);
            // Filter switch: saves right away
            var filterForm = panel.querySelector('.sp-filter-form');
            filterForm.querySelector('input[name=filter_nolang]').addEventListener('change', function () {
                statsRememberScroll();
                filterForm.submit();
            });
            <?php if ($reopenSettings !== ''): ?>
            openPanel(<?php echo json_encode($reopenSettings); ?>);
            <?php endif; ?>

            try {
                if (sessionStorage.getItem('stats-reopen-settings')) {
                    sessionStorage.removeItem('stats-reopen-settings');
                    openPanel();
                }
            } catch (e) {}

            // Report dialog
            var rPanel = document.getElementById('report-panel');
            var rForm = document.getElementById('report-form');
            var rSelect = rForm.querySelector('.rp-select');
            var rCustom = rForm.querySelector('.rp-custom');
            var rSubmit = document.getElementById('report-submit');
            var rNone = document.getElementById('report-none');
            function rClose() { if (rPanel.close) { rPanel.close(); } else { rPanel.removeAttribute('open'); } }
            document.getElementById('report-open').addEventListener('click', function () {
                if (rPanel.showModal) { rPanel.showModal(); } else { rPanel.setAttribute('open', ''); }
            });
            rPanel.querySelectorAll('[data-close-report]').forEach(function (b) { b.addEventListener('click', rClose); });
            rPanel.addEventListener('click', function (e) { if (e.target === rPanel) { rClose(); } });
            rSelect.addEventListener('change', function () {
                var custom = rSelect.value === 'custom';
                rCustom.hidden = !custom;
                rCustom.querySelectorAll('input').forEach(function (i) { i.disabled = !custom; });
            });
            function rCheck() {
                var any = rForm.querySelector('input[name="s[]"]:checked');
                rSubmit.disabled = !any;
                rNone.hidden = !!any;
            }
            rForm.querySelectorAll('input[name="s[]"]').forEach(function (c) { c.addEventListener('change', rCheck); });
            // Print without a new tab: load the report invisibly, open the print dialog, then remove it again
            rForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var url = './?' + new URLSearchParams(new FormData(rForm)).toString() + '&embed=1';
                var old = document.getElementById('report-frame');
                if (old) { old.remove(); }
                var frame = document.createElement('iframe');
                frame.id = 'report-frame';
                frame.setAttribute('aria-hidden', 'true');
                frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
                rSubmit.disabled = true;
                var printed = false;
                frame.onload = function () {
                    // only once, and only for the loaded report (not the empty start page of the frame)
                    var loc = '';
                    try { loc = frame.contentWindow.location.search; } catch (err) {}
                    if (printed || loc.indexOf('embed=1') === -1) { return; }
                    printed = true;
                    rSubmit.disabled = false;
                    rClose();
                    var w = frame.contentWindow;
                    w.addEventListener('afterprint', function () { setTimeout(function () { frame.remove(); }, 500); });
                    setTimeout(function () { w.focus(); w.print(); }, 200);
                };
                frame.src = url;
                document.body.appendChild(frame);
            });

            // Copy the include line
            var copyBtn = document.getElementById('copy-line');
            var code = document.getElementById('include-line');
            copyBtn.addEventListener('click', function () {
                var text = code.textContent;
                var done = function () {
                    var original = copyBtn.textContent;
                    copyBtn.textContent = copyBtn.getAttribute('data-copied');
                    setTimeout(function () { copyBtn.textContent = original; }, 1800);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done);
                } else {
                    var range = document.createRange();
                    range.selectNodeContents(code);
                    var sel = window.getSelection();
                    sel.removeAllRanges();
                    sel.addRange(range);
                    try { document.execCommand('copy'); done(); } catch (e) {}
                }
            });
        })();
    </script>
    <?php endif; ?>
</body>
</html>