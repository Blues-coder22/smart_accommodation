<?php
/**
 * Display currencies (v9.26). Prices are stored and PAID in Kenya shillings (KES). A visitor can choose another currency
 * to see converted prices while browsing; the admin sets the exchange rates (no outside service is used).
 * Payments, receipts and emails always stay in KES; converted amounts there are shown only as an approximation.
 */
require_once __DIR__ . '/functions.php';

if (!function_exists('ensureCurrenciesTable')) {
    function ensureCurrenciesTable($pdo) {
        if (function_exists('schemaIsUpgraded') && schemaIsUpgraded()) return;   // v9.33: tables are created once by the upgrade, not on every request
        static $done = false; if ($done) return; $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS currencies (
                code CHAR(3) NOT NULL PRIMARY KEY,
                name VARCHAR(60) NOT NULL,
                kes_per_unit DECIMAL(14,4) NOT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            if ((int)$pdo->query('SELECT COUNT(*) FROM currencies')->fetchColumn() === 0) {
                // EXAMPLE rates only: the admin must update them under Admin > Currencies.
                $ins = $pdo->prepare('INSERT INTO currencies (code, name, kes_per_unit, is_enabled, sort_order) VALUES (?,?,?,?,?)');
                foreach ([['USD', 'US dollar', 129.0000, 1, 1], ['EUR', 'Euro', 140.0000, 1, 2], ['GBP', 'British pound', 165.0000, 1, 3],
                          ['UGX', 'Ugandan shilling', 0.0350, 0, 4], ['TZS', 'Tanzanian shilling', 0.0500, 0, 5], ['ZAR', 'South African rand', 7.2000, 0, 6]] as $r) $ins->execute($r);
            }
        } catch (Throwable $e) { error_log('ensureCurrenciesTable: ' . $e->getMessage()); }
    }
}
if (!function_exists('enabledCurrencies')) {
    /** [code => ['name'=>..., 'rate'=>kes per 1 unit]] for the currencies the admin switched on. */
    function enabledCurrencies() {
        global $pdo; static $list = null;
        if ($list !== null) return $list;
        $list = [];
        try { ensureCurrenciesTable($pdo); foreach ($pdo->query('SELECT code, name, kes_per_unit FROM currencies WHERE is_enabled = 1 AND kes_per_unit > 0 ORDER BY sort_order, code') as $r) $list[$r['code']] = ['name' => $r['name'], 'rate' => (float)$r['kes_per_unit']]; }
        catch (Throwable $e) {}
        return $list;
    }
}
if (!function_exists('selectedCurrency')) {
    function selectedCurrency() {
        $c = strtoupper((string)($_COOKIE['se_currency'] ?? 'KES'));
        return ($c !== 'KES' && isset(enabledCurrencies()[$c])) ? $c : 'KES';
    }
}
if (!function_exists('convertKes')) {
    function convertKes($kes, $code) { $l = enabledCurrencies(); return isset($l[$code]) ? (float)$kes / $l[$code]['rate'] : (float)$kes; }
}
if (!function_exists('formatCurrency')) {
    function formatCurrency($amount, $code) { return $code . ' ' . number_format($amount, ($code === 'KES' || abs($amount) >= 100) ? 0 : 2); }
}
if (!function_exists('moneyHtml')) {
    /** The visitor's chosen currency; hovering shows the real KES price. Use on browsing pages. */
    function moneyHtml($kes) {
        $c = selectedCurrency(); $kesText = 'KES ' . number_format((float)$kes);
        if ($c === 'KES') return $kesText;
        return '<span class="money" title="' . htmlspecialchars($kesText . ' (you pay in KES)', ENT_QUOTES, 'UTF-8') . '">≈ ' . htmlspecialchars(formatCurrency(convertKes($kes, $c), $c), ENT_QUOTES, 'UTF-8') . '</span>';
    }
}
if (!function_exists('approxHtml')) {
    /** " (≈ USD 190)" next to a KES amount, only when another currency is chosen. */
    function approxHtml($kes) {
        $c = selectedCurrency(); if ($c === 'KES') return '';
        return ' <span class="money-approx">(≈ ' . htmlspecialchars(formatCurrency(convertKes($kes, $c), $c), ENT_QUOTES, 'UTF-8') . ')</span>';
    }
}
if (!function_exists('currencyScript')) {
    /** Makes seMoney(kes) available to page scripts (price calculators). Print once per page. */
    function currencyScript() {
        $c = selectedCurrency(); $rate = $c === 'KES' ? 1 : enabledCurrencies()[$c]['rate'];
        echo "<script>window.SE_CUR={code:" . json_encode($c) . ",rate:" . (float)$rate . "};window.seMoney=function(k){var c=window.SE_CUR;if(c.code==='KES'){return 'KES '+Math.round(k).toLocaleString();}var v=k/c.rate;return '≈ '+c.code+' '+v.toLocaleString(undefined,{maximumFractionDigits:(v>=100?0:2),minimumFractionDigits:(v>=100?0:2)});};</script>\n";
    }
}
if (!function_exists('currencySelectorHtml')) {
    function currencySelectorHtml($base) {
        $list = enabledCurrencies(); if (!$list) return '';
        $cur = selectedCurrency(); $back = $_SERVER['REQUEST_URI'] ?? '/';
        $o = '<option value="KES"' . ($cur === 'KES' ? ' selected' : '') . '>KES</option>';
        foreach ($list as $code => $_) $o .= '<option value="' . $code . '"' . ($cur === $code ? ' selected' : '') . '>' . $code . '</option>';
        return '<label class="se-cur notranslate" translate="no"><span class="sr-only">Currency</span><select aria-label="Currency" onchange="location=\'' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '/set_currency.php?c=\'+this.value+\'&amp;back=\'+encodeURIComponent(' . htmlspecialchars(json_encode($back), ENT_QUOTES, 'UTF-8') . ')">' . $o . '</select></label>';
    }
}
