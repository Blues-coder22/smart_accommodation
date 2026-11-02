<?php
/**
 * Serves a user's profile photo from the database:  profile_photo.php?id=<user_id>
 * Falls back to the old file in uploads/profiles (and copies it into the database so it is never lost again).
 * Responds 404 when the person has no photo; pages then show the initial letter instead.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_photo.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) { http_response_code(404); exit; }

ensureProfilePhotoColumns($pdo);

try {
    $st = $pdo->prepare("SELECT profile_photo, profile_photo_data, profile_photo_mime, account_deleted_at FROM users WHERE user_id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // account_deleted_at / blob columns not installed yet: fall back to the plain file name.
    try {
        $st = $pdo->prepare("SELECT profile_photo FROM users WHERE user_id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) { $row = null; }
}
if (!$row || !empty($row['account_deleted_at'])) { http_response_code(404); exit; }

$data = $row['profile_photo_data'] ?? null;
$mime = $row['profile_photo_mime'] ?? null;

if ($data === null || $data === '') {
    $file = !empty($row['profile_photo']) ? __DIR__ . '/uploads/profiles/' . basename((string)$row['profile_photo']) : '';
    if ($file === '' || !is_file($file)) { http_response_code(404); exit; }
    $data = file_get_contents($file);
    $mime = function_exists('mime_content_type') ? mime_content_type($file) : 'image/jpeg';
    if ($data !== false && $data !== '') {
        saveProfilePhotoToDb($pdo, $id, $row['profile_photo'], $mime);   // copy into the database for good
    }
}
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) $mime = 'image/jpeg';

$etag = '"' . md5($data) . '"';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
header('Content-Length: ' . strlen($data));
echo $data;
