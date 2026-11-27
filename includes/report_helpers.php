<?php
/**
 * v9.38.1: shared date-range and CSV helpers for the dashboards, the revenue report and the exports.
 */
if (!function_exists('reportValidDate')) {
    function reportValidDate($d) {
        if (!is_string($d) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    }
}
if (!function_exists('reportPeriod')) {
    /** Returns ['from' => 'Y-m-d', 'to' => 'Y-m-d', 'preset' => string, 'label' => string]. Custom dates win; otherwise ?period=, otherwise $default. */
    function reportPeriod($default = 'last_30') {
        $today = new DateTimeImmutable('today');
        $from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? ''); $preset = (string)($_GET['period'] ?? '');
        if (reportValidDate($from) && reportValidDate($to)) { $preset = 'custom'; }
        else {
            if (!in_array($preset, ['this_month', 'last_month', 'last_30', 'this_year', 'all'], true)) $preset = $default;
            switch ($preset) {
                case 'this_month': $f = $today->modify('first day of this month'); $t = $today; break;
                case 'last_month': $f = $today->modify('first day of last month'); $t = $today->modify('last day of last month'); break;
                case 'this_year':  $f = new DateTimeImmutable($today->format('Y') . '-01-01'); $t = $today; break;
                case 'all':        $f = new DateTimeImmutable('2020-01-01'); $t = $today; break;
                default:           $f = $today->modify('-29 days'); $t = $today;
            }
            $from = $f->format('Y-m-d'); $to = $t->format('Y-m-d');
        }
        if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }                                  // dates entered the wrong way round
        $limit = (new DateTimeImmutable($to))->modify('-5 years')->format('Y-m-d');
        if ($from < $limit) $from = $limit;                                                           // keep exports a sensible size
        $labels = ['this_month' => 'This month', 'last_month' => 'Last month', 'last_30' => 'Last 30 days', 'this_year' => 'This year', 'all' => 'All time', 'custom' => 'Custom range'];
        return ['from' => $from, 'to' => $to, 'preset' => $preset, 'label' => $labels[$preset] ?? 'Custom range'];
    }
}
if (!function_exists('reportFilterHtml')) {
    /** A small GET form: quick ranges plus two date boxes. $extra = ['name' => 'value'] hidden fields to keep. */
    function reportFilterHtml($period, $extra = []) {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $links = '';
        foreach (['this_month' => 'This month', 'last_month' => 'Last month', 'last_30' => 'Last 30 days', 'this_year' => 'This year', 'all' => 'All time'] as $k => $l) {
            $on = $period['preset'] === $k;
            $links .= '<a href="?' . $e(http_build_query($extra + ['period' => $k])) . '" style="padding:6px 12px;border:1px solid currentColor;border-radius:999px;text-decoration:none;font-size:.85rem;' . ($on ? 'font-weight:700;text-decoration:underline' : 'opacity:.85') . '"' . ($on ? ' aria-current="true"' : '') . '>' . $e($l) . '</a> ';
        }
        $hidden = ''; foreach ($extra as $k => $v) $hidden .= '<input type="hidden" name="' . $e($k) . '" value="' . $e($v) . '">';
        $in = 'padding:8px 10px;border:1px solid currentColor;border-radius:8px;background:transparent;color:inherit';
        return '<div class="report-filter no-print" style="margin:0 0 16px"><div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">' . $links . '</div>'
             . '<form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">' . $hidden
             . '<div><label for="rf_from" style="display:block;font-size:.8rem">From</label><input style="' . $in . '" type="date" id="rf_from" name="from" value="' . $e($period['from']) . '"></div>'
             . '<div><label for="rf_to" style="display:block;font-size:.8rem">To</label><input style="' . $in . '" type="date" id="rf_to" name="to" value="' . $e($period['to']) . '"></div>'
             . '<button type="submit" style="' . $in . ';cursor:pointer;font-weight:600">Show</button></form></div>';
    }
}
if (!function_exists('csvDownload')) {
    /** Sends a CSV file and stops. Text starting with = + - @ is prefixed with an apostrophe so spreadsheets never run it as a formula. */
    function csvDownload($filename, array $headers, array $rows) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // so Excel reads accents correctly
        $safe = function ($v) { if (is_string($v) && $v !== '' && !is_numeric($v) && strpbrk($v[0], "=+-@\t\r") !== false) return "'" . $v; return $v; };
        fputcsv($out, array_map($safe, $headers));
        foreach ($rows as $r) fputcsv($out, array_map($safe, $r));
        fclose($out); exit;
    }
}