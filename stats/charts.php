<?php
/*
 * websimon-stats – Besucherstatistik ohne Cookies und ohne Datenbank
 * (c) 2026 Ralf Simon, websimon.de – MIT-Lizenz
 * https://github.com/websimonde/websimon-stats
 */
// Charts as SVG (bar chart and smooth line chart).
// Colors come from CSS classes, so the charts follow the light/dark theme.
// Every point gets an invisible hit area with data-tip; a small script in
// index.php shows the tip on hover (mouse) and on tap (phone).
//
// $points: [['label' => 'Oct 22', 'value' => 12, 'tip' => 'Oct 22, 2026: 12 views'], ...]
// Options:
//   width     viewBox width (the SVG always scales to 100% of its box)
//   padX      space left and right
//   labelEvery  draw every n-th label (0 = automatic)
//   values    draw the numbers on the bars (bar chart only)

function stats_chart_label_step($n, $maxLabels) {
    return max(1, (int)ceil($n / max(1, $maxLabels)));
}

function renderBarChart($points, $o = []) {
    $vbW     = $o['width']   ?? 1000;
    $padX    = $o['padX']    ?? 30;
    $barMax  = $o['barMax']  ?? 32;
    $fontVal = $o['fontVal'] ?? 11;
    $fontLbl = $o['fontLbl'] ?? 12;
    $values  = $o['values']  ?? true;
    $maxLabels = $o['maxLabels'] ?? 16;

    $vbH = 200;
    $baseY = 170;
    $topY = 20;
    $plotH = $baseY - $topY;
    $n = count($points);
    $maxV = max(array_merge([1], array_column($points, 'value')));
    $step = $o['labelEvery'] ?? stats_chart_label_step($n, $maxLabels);

    $svg = '<svg class="chart-svg" viewBox="0 0 ' . $vbW . ' ' . $vbH . '">';
    foreach ([20, 70, 120] as $gy) {
        $svg .= '<line class="cg" x1="0" y1="' . $gy . '" x2="' . $vbW . '" y2="' . $gy . '" vector-effect="non-scaling-stroke" />';
    }
    $svg .= '<line class="cb" x1="0" y1="' . $baseY . '" x2="' . $vbW . '" y2="' . $baseY . '" vector-effect="non-scaling-stroke" />';
    if ($n < 1) {
        return $svg . '</svg>';
    }
    $slotW = ($vbW - 2 * $padX) / $n;
    $barW = max(2, min($slotW * 0.72, $barMax));
    foreach ($points as $i => $pt) {
        $val = (int)$pt['value'];
        $cx = $padX + ($i + 0.5) * $slotW;
        if ($val > 0) {
            $barH = max(3, ($val / $maxV) * $plotH);
            $y = $baseY - $barH;
            $svg .= '<rect class="cbar" x="' . round($cx - $barW / 2, 1) . '" y="' . round($y, 1) . '" width="' . round($barW, 1) . '" height="' . round($barH, 1) . '" rx="2" />';
            if ($values) {
                $digits = strlen((string)$val);
                $fs = min($fontVal, ($barW - 3) / ($digits * 0.62));
                if ($fs >= 6.5 && $barH >= $fs + 8) {
                    $svg .= '<text class="cv-in" x="' . round($cx, 1) . '" y="' . round($y + $fs + 4, 1) . '" font-size="' . round($fs, 1) . '" text-anchor="middle">' . $val . '</text>';
                } elseif ($slotW >= $digits * 7) {
                    $svg .= '<text class="cv-out" x="' . round($cx, 1) . '" y="' . round($y - 5, 1) . '" font-size="' . $fontVal . '" text-anchor="middle">' . $val . '</text>';
                }
            }
        }
        if ($i % $step === 0 && ($pt['label'] ?? '') !== '') {
            $svg .= '<text class="cl" x="' . round($cx, 1) . '" y="' . ($baseY + 20) . '" font-size="' . $fontLbl . '" text-anchor="middle">' . htmlspecialchars($pt['label']) . '</text>';
        }
        $svg .= '<rect class="hit" x="' . round($cx - $slotW / 2, 2) . '" y="0" width="' . round($slotW, 2) . '" height="' . $vbH . '" data-tip="' . htmlspecialchars($pt['tip'] ?? '') . '" />';
    }
    return $svg . '</svg>';
}

// Smooth curve with a light area below
function renderLineChart($points, $o = []) {
    $vbW     = $o['width']   ?? 1000;
    $padX    = $o['padX']    ?? 40;
    $fontLbl = $o['fontLbl'] ?? 12;
    $maxLabels = $o['maxLabels'] ?? 12;
    $axis    = $o['axis']    ?? true;

    $vbH = 200;
    $baseY = 170;
    $topY = 20;
    $plotH = $baseY - $topY;
    $n = count($points);
    $maxV = max(array_merge([1], array_column($points, 'value')));
    $step = $o['labelEvery'] ?? stats_chart_label_step($n, $maxLabels);

    $svg = '<svg class="chart-svg" viewBox="0 0 ' . $vbW . ' ' . $vbH . '">';
    foreach ([20, 70, 120] as $gy) {
        $svg .= '<line class="cg" x1="0" y1="' . $gy . '" x2="' . $vbW . '" y2="' . $gy . '" vector-effect="non-scaling-stroke" />';
        if ($axis) {
            $svg .= '<text class="cl" x="' . ($padX - 8) . '" y="' . ($gy + 4) . '" font-size="10" text-anchor="end">' . (function_exists('lnum') ? lnum(round($maxV * ($baseY - $gy) / $plotH)) : round($maxV * ($baseY - $gy) / $plotH)) . '</text>';
        }
    }
    $svg .= '<line class="cb" x1="0" y1="' . $baseY . '" x2="' . $vbW . '" y2="' . $baseY . '" vector-effect="non-scaling-stroke" />';
    if ($n < 2) {
        return $svg . '</svg>';
    }

    $stepX = ($vbW - 2 * $padX) / ($n - 1);
    $xs = [];
    $ys = [];
    foreach ($points as $i => $pt) {
        $xs[$i] = $padX + $i * $stepX;
        $ys[$i] = $baseY - ((int)$pt['value'] / $maxV) * $plotH;
    }
    // Catmull-Rom spline -> cubic Bezier; control points stay between the neighbours (no overshoot)
    $d = 'M' . round($xs[0], 1) . ',' . round($ys[0], 1);
    for ($i = 0; $i < $n - 1; $i++) {
        $p0 = max($i - 1, 0);
        $p3 = min($i + 2, $n - 1);
        $c1x = $xs[$i] + ($xs[$i + 1] - $xs[$p0]) / 6;
        $c1y = $ys[$i] + ($ys[$i + 1] - $ys[$p0]) / 6;
        $c2x = $xs[$i + 1] - ($xs[$p3] - $xs[$i]) / 6;
        $c2y = $ys[$i + 1] - ($ys[$p3] - $ys[$i]) / 6;
        $lo = min($ys[$i], $ys[$i + 1]);
        $hi = max($ys[$i], $ys[$i + 1]);
        $c1y = max($lo, min($hi, $c1y));
        $c2y = max($lo, min($hi, $c2y));
        $d .= ' C' . round($c1x, 1) . ',' . round($c1y, 1) . ' ' . round($c2x, 1) . ',' . round($c2y, 1) . ' ' . round($xs[$i + 1], 1) . ',' . round($ys[$i + 1], 1);
    }
    $area = $d . ' L' . round($xs[$n - 1], 1) . ',' . $baseY . ' L' . round($xs[0], 1) . ',' . $baseY . ' Z';
    $svg .= '<path class="carea" d="' . $area . '" />';
    $svg .= '<path class="cline" d="' . $d . '" vector-effect="non-scaling-stroke" />';

    foreach ($points as $i => $pt) {
        if (($n - 1 - $i) % $step === 0 && ($pt['label'] ?? '') !== '') {
            $anchor = ($xs[$i] < 28) ? 'start' : (($xs[$i] > $vbW - 28) ? 'end' : 'middle');
            $svg .= '<text class="cl" x="' . round($xs[$i], 1) . '" y="' . ($baseY + 20) . '" font-size="' . $fontLbl . '" text-anchor="' . $anchor . '">' . htmlspecialchars($pt['label']) . '</text>';
        }
        $svg .= '<rect class="hit" x="' . round($xs[$i] - $stepX / 2, 2) . '" y="0" width="' . round($stepX, 2) . '" height="' . $vbH . '" data-tip="' . htmlspecialchars($pt['tip'] ?? '') . '" data-x="' . round($xs[$i], 1) . '" data-y="' . round($ys[$i], 1) . '" />';
    }
    // marker for the point under the finger / mouse
    $svg .= '<circle class="cdot chart-marker" cx="-10" cy="-10" r="4" />';
    return $svg . '</svg>';
}
