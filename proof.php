<?php
// Streams a private payment proof or commission proof to the people allowed to see it. Files live outside the public folders.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/commission.php';
requireLogin();
ensureCommissionTables($pdo);

$type = $_GET['type'] ?? ''; $id = (int)($_GET['id'] ?? 0);
$uid = (int)$_SESSION['user_id']; $isAdmin = hasRole('admin');
$name = ''; $allowed = false;
if ($type === 'payment') {
    $st = $pdo->prepare('SELECT payment_proof, tourist_id, host_id FROM payments WHERE payment_id = ?'); $st->execute([$id]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $name = (string)$r['payment_proof']; $allowed = $isAdmin || $uid === (int)$r['tourist_id'] || $uid === (int)$r['host_id']; }
    $dir = commissionPrivateDir('payment');
} elseif ($type === 'commission') {
    $st = $pdo->prepare('SELECT proof_file, host_id FROM commission_remittances WHERE remittance_id = ?'); $st->execute([$id]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $name = (string)$r['proof_file']; $allowed = $isAdmin || $uid === (int)$r['host_id']; }
    $dir = commissionPrivateDir('commission');
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
