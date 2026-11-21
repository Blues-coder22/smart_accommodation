<?php
// Remembers the visitor's display currency, then returns to the page they were on.
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/currency.php';
$c = strtoupper((string)($_GET['c'] ?? 'KES'));
if ($c === 'KES' || isset(enabledCurrencies()[$c])) setcookie('se_currency', $c, ['expires' => time() + 86400 * 365, 'path' => '/', 'samesite' => 'Lax', 'httponly' => false, 'secure' => !empty($_SERVER['HTTPS'])]);
$back = (string)($_GET['back'] ?? '');
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
header('Location: ' . (preg_match('#^/(?!/)#', $back) ? $back : $base . '/index.php'));
exit;
