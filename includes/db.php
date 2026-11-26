<?php
// v9.33: true once the one-time schema upgrade ran (see runSchemaUpgradeV933 in commission.php). Delete logs/.schema_v933 after restoring a database to run it again.
if (!function_exists('schemaIsUpgraded')) { function schemaIsUpgraded() { return is_file(dirname(__DIR__) . '/logs/.schema_v933'); } }
define('DB_HOST', 'localhost');
define('DB_NAME', 'smart_accommodation2');
define('DB_USER', 'root');
define('DB_PASS', '');

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
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

// v9.34: convert older tables to utf8mb4 once, so emoji can be saved (messages, reviews, reports ...).
try {
    $__emojiMarker = __DIR__ . '/../logs/.emoji_v934';
    if (!is_file($__emojiMarker) && is_dir(dirname($__emojiMarker)) && is_writable(dirname($__emojiMarker))) {
        require_once __DIR__ . '/emoji_support.php';
        @file_put_contents($__emojiMarker, date('c'));   // written first, so a slow conversion is never started twice at once
        ensureEmojiSupport($pdo);
    }
} catch (Throwable $e) { error_log('emoji support: ' . $e->getMessage()); }

// Photos: restore any missing listing/attraction photo from the database and remove duplicates (see includes/media_store.php).
try {
    require_once __DIR__ . '/media_store.php';
    mediaSelfHeal($pdo);
} catch (Throwable $e) { error_log('media self-heal: ' . $e->getMessage()); }
?>