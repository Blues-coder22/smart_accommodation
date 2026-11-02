<?php
// Streams a host's ID photo to an administrator. Files live outside any public folder (private_uploads/).
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

requireLogin();
requireRole('admin');

$stmt = $pdo->prepare("SELECT id_photo FROM host_verifications WHERE verification_id = ? AND status = 'pending'");
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$name = (string)$stmt->fetchColumn();
$file = hostVerificationPrivateDir() . DIRECTORY_SEPARATOR . basename($name);
if ($name === '' || !is_file($file)) {
    http_response_code(404);
    exit('No ID photo available (it is erased once a decision is made).');
}
$mime = mime_content_type($file);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(404); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="id-photo"');
readfile($file);
