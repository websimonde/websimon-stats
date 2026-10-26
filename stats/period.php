<?php
/*
 * websimon-stats – Besucherstatistik ohne Cookies und ohne Datenbank
 * (c) 2026 Ralf Simon, websimon.de – MIT-Lizenz
 * https://github.com/websimonde/websimon-stats
 */
// Time period for the chart and the tables below it.
// URL parameters: ?p=today|yesterday|7d|30d|month|lastmonth|12m|year|all
//                 ?p=custom&from=2026-10-01&to=2026-10-15

// "Last 12 months" only when more than a year is kept (otherwise it equals "all")
function stats_period_presets($keepMonths = 12) {
    $presets = ['today', 'yesterday', '7d', '30d', 'month', 'lastmonth', '12m', 'year', 'all'];
    if ($keepMonths <= 12) {
        $presets = array_values(array_diff($presets, ['12m']));
    }
    return $presets;
}

function stats_day_start($ts) {
    return strtotime(date('Y-m-d 00:00:00', $ts));
}

function stats_day_end($ts) {
    return strtotime(date('Y-m-d 23:59:59', $ts));
}

// $oldest: timestamp of the oldest log entry (for "all")
function stats_period($get, $oldest, $now, $presets = null) {
    $p = (string)($get['p'] ?? '30d');
    if ($p !== 'custom' && !in_array($p, $presets ?? stats_period_presets(24), true)) {
        $p = '30d';
    }
    $today = stats_day_start($now);
    switch ($p) {
        case 'today':
            $start = $today;
            $end = stats_day_end($now);
            break;
        case 'yesterday':
            $start = strtotime('-1 day', $today);
            $end = $today - 1;
            break;
        case '7d':
            $start = strtotime('-6 days', $today);
            $end = stats_day_end($now);
            break;
        case 'month':
            $start = strtotime(date('Y-m-01', $now));
            $end = strtotime('+1 month', $start) - 1;
            break;
        case 'lastmonth':
            $start = strtotime('-1 month', strtotime(date('Y-m-01', $now)));
            $end = strtotime(date('Y-m-01', $now)) - 1;
            break;
        case '12m':
            $start = strtotime('+1 day', strtotime('-12 months', $today));
            $end = stats_day_end($now);
            break;
        case 'year':
            $start = strtotime(date('Y-01-01', $now));
            $end = strtotime('+1 year', $start) - 1;
            break;
        case 'all':
            $start = stats_day_start(min($oldest ?: $now, $now));
            $end = stats_day_end($now);
            break;
        case 'custom':
            $from = (string)($get['from'] ?? '');
            $to = (string)($get['to'] ?? '');
            $fromTs = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? strtotime($from . ' 00:00:00') : false;
            $toTs = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? strtotime($to . ' 00:00:00') : false;
            if ($fromTs && $toTs) {
                if ($fromTs > $toTs) {
                    [$fromTs, $toTs] = [$toTs, $fromTs];
                }
                $start = $fromTs;
                $end = stats_day_end($toTs);
                break;
            }
            // invalid: fall through to the default
        default:
            $p = '30d';
            $start = strtotime('-29 days', $today);
            $end = stats_day_end($now);
    }

    // Arrows: the same length before / after. Whole calendar months move by months.
    $firstOfMonth = date('j', $start) === '1';
    $lastOfMonth = date('Y-m-d', $end) === date('Y-m-t', $end);
    if ($firstOfMonth && $lastOfMonth) {
        $months = ((int)date('Y', $end) - (int)date('Y', $start)) * 12 + (int)date('n', $end) - (int)date('n', $start) + 1;
        $prevStart = strtotime("-$months months", $start);
        $nextStart = strtotime("+$months months", $start);
        $prevEnd = $start - 1;
        $nextEnd = strtotime("+$months months", $nextStart) - 1;
    } else {
        $days = (int)round((stats_day_start($end) - $start) / 86400) + 1;
        $prevStart = strtotime("-$days days", $start);
        $prevEnd = $start - 1;
        $nextStart = stats_day_end($end) + 1;
        $nextEnd = stats_day_end(strtotime('+' . ($days - 1) . ' days', $nextStart));
    }

    return [
        'key' => $p,
        'start' => $start,
        'end' => $end,
        'prev' => ($oldest && $prevEnd < $oldest) ? null : ['from' => date('Y-m-d', $prevStart), 'to' => date('Y-m-d', $prevEnd)],
        'next' => ($nextStart > $now) ? null : ['from' => date('Y-m-d', $nextStart), 'to' => date('Y-m-d', $nextEnd)],
    ];
}

// Resolution of the chart: hour, day, week or month - depending on the length.
// $coarse: fewer points for small screens (no sideways scrolling).
function stats_granularity($start, $end, $coarse = false) {
    $days = ($end - $start + 1) / 86400;
    if ($days <= 2.01) {
        return 'hour';
    }
    if ($days <= ($coarse ? 31.01 : 92.01)) {
        return 'day';
    }
    if ($days <= ($coarse ? 190 : 731)) {
        return 'week';
    }
    return 'month';
}

function stats_bucket_key($ts, $gran) {
    switch ($gran) {
        case 'hour':
            return date('Y-m-d H', $ts);
        case 'day':
            return date('Y-m-d', $ts);
        case 'week':
            return date('o-W', $ts);
        default:
            return date('Y-m', $ts);
    }
}

// All buckets of the period with label and tooltip text
function stats_buckets($start, $end, $gran, $now) {
    $out = [];
    if ($gran === 'week') {
        $t = strtotime('monday this week', $start);
    } elseif ($gran === 'month') {
        $t = strtotime(date('Y-m-01', $start));
    } else {
        $t = $start;
    }
    $prevYear = null;
    while ($t <= $end) {
        switch ($gran) {
            case 'hour':
                $label = date('H', $t);
                $tip = ldate('fmt_day_hour', $t);
                $next = strtotime('+1 hour', $t);
                break;
            case 'day':
                $label = ldate('fmt_day', $t);
                $tip = ldate('fmt_date', $t);
                $next = strtotime('+1 day', $t);
                break;
            case 'week':
                $label = ldate('fmt_day', $t);
                $tip = t('week_from', ldate('fmt_date', $t));
                $next = strtotime('+1 week', $t);
                break;
            default:
                $year = date('Y', $t);
                $label = ($prevYear !== null && $year !== $prevYear) || $prevYear === null ? ldate('fmt_month_tick', $t) : ldate('M', $t);
                $prevYear = $year;
                $tip = ldate('F Y', $t);
                $next = strtotime('+1 month', $t);
        }
        $out[stats_bucket_key($t, $gran)] = ['label' => $label, 'tip' => $tip, 'value' => 0];
        $t = $next;
    }
    return $out;
}
