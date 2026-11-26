<?php
/** Phone number helpers (v9.35). SMS codes were removed; the phone number is now only an optional contact detail. */
if (!function_exists('normalizePhone')) {
    /**
     * Kenyan numbers in any usual style (0712 345 678, 712345678, +254712345678, 254 712 345 678) become +254712345678.
     * Other countries need the international form (+256..., 00256...). Returns null when it does not look like a phone number.
     */
    function normalizePhone($raw) {
        $s = preg_replace('/[\s\-\.\(\)]/', '', (string)$raw);
        if ($s === '') return null;
        if (strpos($s, '00') === 0) $s = '+' . substr($s, 2);
        if (preg_match('/^\+?254([17]\d{8})$/', $s, $m)) return '+254' . $m[1];
        if (preg_match('/^0([17]\d{8})$/', $s, $m))      return '+254' . $m[1];
        if (preg_match('/^([17]\d{8})$/', $s, $m))        return '+254' . $m[1];
        if (preg_match('/^\+[1-9]\d{7,14}$/', $s) && strpos($s, '+254') !== 0) return $s;
        return null;
    }
}
if (!function_exists('maskPhone')) {
    function maskPhone($p) { $p = (string)$p; return strlen($p) > 7 ? substr($p, 0, 4) . str_repeat('•', max(0, strlen($p) - 7)) . substr($p, -3) : $p; }
}
