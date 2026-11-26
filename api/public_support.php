<?php
// Public: the address the Contact page should use (so it always matches the admin's configured address).
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../includes/support.php';
header('Content-Type: application/json'); header('Cache-Control: max-age=300');
echo json_encode(['email' => supportEmailAddress($pdo)]);
