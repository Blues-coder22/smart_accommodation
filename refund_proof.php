<?php
// Streams private refund evidence (a guest's proof of payment, or the screenshot of a refund) to the guest, the host of that booking and admins only.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/refund_requests.php';
requireLogin();
ensureRefundRequestTables($pdo);
$id = opaqueIdDecode('rfd', $_GET['ref'] ?? '');
$which = ($_GET['file'] ?? '') === 'refund' ? 'refund_evidence' : 'evidence_file';
$uid = (int)$_SESSION['user_id']; $isAdmin = hasRole('admin');
$st = $pdo->prepare('SELECT evidence_file, refund_evidence, tourist_id, host_id FROM refund_requests WHERE request_id = ?'); $st->execute([$id]);
$r = $st->fetch(PDO::FETCH_ASSOC);
$name = $r ? (string)$r[$which] : '';
$allowed = $r && ($isAdmin || $uid === (int)$r['tourist_id'] || $uid === (int)$r['host_id']);
$file = refundEvidenceDir() . DIRECTORY_SEPARATOR . basename($name);
if (!$allowed || $name === '' || !is_file($file)) { http_response_code(404); exit('Not available.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(404); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="evidence"');
readfile($file);
