<?php
// Streams a private payment proof or commission proof to the people allowed to see it. Files live outside the public folders.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/commission.php';
requireLogin();
ensureCommissionTables($pdo);

$type = $_GET['type'] ?? ''; $id = opaqueIdDecode($type === 'payment' ? 'pay' : 'plat', $_GET['ref'] ?? '');
$uid = (int)$_SESSION['user_id']; $isAdmin = hasRole('admin');
$name = ''; $allowed = false;
if ($type === 'payment') {
    $st = $pdo->prepare('SELECT payment_proof, tourist_id, host_id FROM payments WHERE payment_id = ?'); $st->execute([$id]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $name = (string)$r['payment_proof']; $allowed = $isAdmin || $uid === (int)$r['tourist_id'] || $uid === (int)$r['host_id']; }
    $dir = commissionPrivateDir('payment');
} elseif ($type === 'platform') {
    $st = $pdo->prepare('SELECT proof_file, tourist_id FROM platform_payments WHERE platform_payment_id = ?'); $st->execute([$id]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $name = (string)$r['proof_file']; $allowed = $isAdmin || $uid === (int)$r['tourist_id']; }   // the host does not see the platform's side
    $dir = commissionPrivateDir('platform');
}
$file = isset($dir) ? $dir . DIRECTORY_SEPARATOR . basename($name) : '';
if (!$allowed || $name === '' || !is_file($file)) { http_response_code(404); exit('Not available.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(404); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="proof"');
readfile($file);
