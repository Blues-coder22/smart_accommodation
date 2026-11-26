<?php
/**
 * v9.34: emoji support. MySQL's old "utf8" cannot store emoji (they need 4 bytes), so people's messages, reviews and
 * reports silently lost them. The connection now uses utf8mb4 (includes/db.php); this converts older tables once.
 * Safe to run again. Marker file: logs/.emoji_v934
 */
if (!function_exists('ensureEmojiSupport')) {
    function ensureEmojiSupport($pdo) {
        $done = [];
        try {
            $q = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_collation NOT LIKE 'utf8mb4%'");
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $t) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$t)) continue;
                try { $pdo->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $done[] = $t; }
                catch (Throwable $e) { error_log("ensureEmojiSupport $t: " . $e->getMessage()); }
            }
        } catch (Throwable $e) { error_log('ensureEmojiSupport: ' . $e->getMessage()); }
        return $done;
    }
}
