<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'smart_accommodation');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('We can’t load this page right now. Please try again in a moment.');
}

require_once __DIR__ . '/opaque_id.php';    // opaque tokens for payment ids
require_once __DIR__ . '/booking_ref.php';  // public booking references (booking numbers are never shown)

// Photos: restore any missing listing/attraction photo from the database and remove duplicates (see includes/media_store.php).
try {
    require_once __DIR__ . '/media_store.php';
    mediaSelfHeal($pdo);
} catch (Throwable $e) { error_log('media self-heal: ' . $e->getMessage()); }
?>