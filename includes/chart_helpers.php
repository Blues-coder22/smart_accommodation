<?php
/**
 * v9.38.1: dependency-free charts for the dashboards (inline SVG and plain HTML, so they work offline, follow light and dark mode, and print).
 * Every chart carries a text description and a "Show as table" version of its numbers for screen readers.
 */
if (!function_exists('chartBuckets')) {
    /** Groups a date range into days (up to 31 days), weeks (up to about 6 months) or months. Returns unit, the ordered bucket keys and an SQL expression builder. */
    function chartBuckets($from, $to) {
        $f = new DateTimeImmutable($from); $t = new DateTimeImmutable($to);
        $days = (int)$f->diff($t)->days + 1;
        $unit = $days <= 31 ? 'day' : ($days <= 183 ? 'week' : 'month');
        $keys = [];
        if ($unit === 'day') { for ($d = $f; $d <= $t; $d = $d->modify('+1 day')) $keys[] = $d->format('Y-m-d'); }
        elseif ($unit === 'week') { for ($d = $f->modify('monday this week'); $d <= $t; $d = $d->modify('+7 days')) $keys[] = $d->format('Y-m-d'); }
        else { for ($d = $f->modify('first day of this month'); $d <= $t; $d = $d->modify('first day of next month')) $keys[] = $d->format('Y-m-d'); }
        $expr = function ($col) use ($unit) {
            if ($unit === 'day')  return "DATE($col)";
            if ($unit === 'week') return "DATE_SUB(DATE($col), INTERVAL WEEKDAY($col) DAY)";
            return "DATE_FORMAT($col, '%Y-%m-01')";
        };
        return ['unit' => $unit, 'keys' => $keys, 'expr' => $expr];
    }
}
if (!function_exists('chartLabel')) {
    function chartLabel($key, $unit) { $ts = strtotime($key); return $unit === 'month' ? date('M Y', $ts) : date('j M', $ts); }
}
if (!function_exists('chartSeries')) {
    /** Runs "SELECT <bucket> AS k, <value columns> ..." and returns one aligned array of numbers per value column. */
    function chartSeries($pdo, $sql, $params, array $keys, array $cols) {
        $out = []; foreach ($cols as $c) $out[$c] = array_fill_keys($keys, 0.0);
        try {
            $q = $pdo->prepare($sql); $q->execute($params);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) foreach ($cols as $c) if (isset($out[$c][$r['k']])) $out[$c][$r['k']] = (float)$r[$c];
        } catch (Throwable $e) { error_log('chartSeries: ' . $e->getMessage()); }
        return array_map('array_values', $out);
    }
}
if (!function_exists('chartNiceMax')) {
    function chartNiceMax($v) {
        if ($v <= 0) return 1;
        $p = pow(10, floor(log10($v))); $n = $v / $p;
        return ($n <= 1 ? 1 : ($n <= 2 ? 2 : ($n <= 5 ? 5 : 10))) * $p;
    }
}
if (!function_exists('chartCompact')) {
    function chartCompact($v) { return $v >= 1000000 ? round($v / 1000000, 1) . 'M' : ($v >= 1000 ? round($v / 1000, 1) . 'K' : (string)round($v)); }
}
if (!function_exists('svgBarChart')) {
    /**
     * Grouped bar chart. $series = [['name' => 'Bookings', 'values' => [...]], ...] (one value per label).
     * $opts: 'title' (what it shows), 'money' (true for KES), 'empty' (message when every value is 0).
     */
    function svgBarChart(array $labels, array $series, array $opts = []) {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $title = $opts['title'] ?? 'Chart'; $money = !empty($opts['money']);
        $all = []; foreach ($series as $s) foreach ($s['values'] as $v) $all[] = $v;
        if (!$all || max($all) <= 0) return '<p class="small" style="opacity:.8;margin:6px 0">' . $e($opts['empty'] ?? 'Nothing to show for this period yet.') . '</p>';
        $colors = ['var(--accent, #1f6f50)', 'var(--accent-2, #c8873a)', 'currentColor'];
        $W = 640; $H = 250; $ml = 46; $mb = 34; $mt = 10; $mr = 8; $pw = $W - $ml - $mr; $ph = $H - $mt - $mb;
        $max = chartNiceMax(max($all)); $n = count($labels); $ns = count($series);
        $slot = $pw / max(1, $n); $bw = max(2, min(28, ($slot * 0.8) / $ns));
        $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . $e($title) . '" style="width:100%;height:auto;max-height:300px;overflow:visible" font-family="inherit" font-size="11">';
        for ($i = 0; $i <= 4; $i++) {
            $y = $mt + $ph - ($ph * $i / 4); $val = $max * $i / 4;
            $svg .= '<line x1="' . $ml . '" x2="' . ($W - $mr) . '" y1="' . $y . '" y2="' . $y . '" stroke="currentColor" stroke-opacity=".15"/>';
            $svg .= '<text x="' . ($ml - 6) . '" y="' . ($y + 4) . '" text-anchor="end" fill="currentColor" fill-opacity=".75">' . $e(chartCompact($val)) . '</text>';
        }
        $step = max(1, (int)ceil($n / 8));
        foreach ($labels as $i => $lab) {
            $cx = $ml + $slot * $i + $slot / 2;
            foreach ($series as $si => $s) {
                $v = (float)$s['values'][$i]; $h = $max > 0 ? $ph * $v / $max : 0;
                $x = $cx - ($bw * $ns) / 2 + $bw * $si;
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($mt + $ph - $h, 1) . '" width="' . round($bw - 1, 1) . '" height="' . round($h, 1) . '" rx="2" fill="' . $colors[$si % 3] . '" fill-opacity="' . ($si === 2 ? '.5' : '1') . '"><title>' . $e($lab . ' | ' . $s['name'] . ': ' . ($money ? 'KES ' . number_format($v, 0) : number_format($v, 0))) . '</title></rect>';
            }
            if ($i % $step === 0) $svg .= '<text x="' . round($cx, 1) . '" y="' . ($H - 12) . '" text-anchor="middle" fill="currentColor" fill-opacity=".75">' . $e($lab) . '</text>';
        }
        $svg .= '</svg>';
        $legend = '';
        if ($ns > 1) { foreach ($series as $si => $s) $legend .= '<span style="margin-right:14px;font-size:.85rem"><span aria-hidden="true" style="display:inline-block;width:10px;height:10px;border-radius:2px;background:' . $colors[$si % 3] . ';margin-right:5px"></span>' . $e($s['name']) . '</span>'; $legend = '<div style="margin:0 0 6px">' . $legend . '</div>'; }
        $table = '<details style="margin-top:6px"><summary class="small" style="cursor:pointer">Show as table</summary><div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:.85rem;margin-top:6px"><thead><tr><th style="text-align:left;padding:3px 8px">Period</th>';
        foreach ($series as $s) $table .= '<th style="text-align:right;padding:3px 8px">' . $e($s['name']) . '</th>';
        $table .= '</tr></thead><tbody>';
        foreach ($labels as $i => $lab) { $table .= '<tr><td style="padding:3px 8px">' . $e($lab) . '</td>'; foreach ($series as $s) $table .= '<td style="text-align:right;padding:3px 8px">' . ($money ? number_format((float)$s['values'][$i], 0) : number_format((float)$s['values'][$i], 0)) . '</td>'; $table .= '</tr>'; }
        $table .= '</tbody></table></div></details>';
        return $legend . $svg . $table;
    }
}
if (!function_exists('htmlBarList')) {
    /** Horizontal bars for rankings and breakdowns. $items = [['label' => 'Confirmed', 'value' => 12], ...]. */
    function htmlBarList(array $items, array $opts = []) {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); $money = !empty($opts['money']);
        if (!$items || max(array_column($items, 'value')) <= 0) return '<p class="small" style="opacity:.8;margin:6px 0">' . $e($opts['empty'] ?? 'Nothing to show for this period yet.') . '</p>';
        $max = max(array_column($items, 'value')); $html = '<ul style="list-style:none;margin:0;padding:0;display:grid;gap:8px" aria-label="' . $e($opts['title'] ?? 'Ranking') . '">';
        foreach ($items as $it) {
            $pct = $max > 0 ? max(2, round(100 * $it['value'] / $max)) : 0;
            $html .= '<li><div style="display:flex;justify-content:space-between;gap:10px;font-size:.88rem"><span>' . $e($it['label']) . '</span><strong>' . ($money ? 'KES ' : '') . number_format((float)$it['value'], 0) . '</strong></div>'
                  . '<div aria-hidden="true" style="height:8px;border-radius:6px;background:rgba(128,128,128,.25);background:color-mix(in srgb, currentColor 12%, transparent)"><div style="height:8px;width:' . $pct . '%;border-radius:6px;background:var(--accent, #1f6f50)"></div></div></li>';
        }
        return $html . '</ul>';
    }
}
