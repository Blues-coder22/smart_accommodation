<?php
/**
 * In-app notifications (v9.26): every important event is also saved here, so people can see it on the site
 * (bell icon + Notifications page) even when an email does not arrive. Emails are still sent as before.
 */
require_once __DIR__ . '/functions.php';

if (!function_exists('ensureNotificationsTable')) {
    function ensureNotificationsTable($pdo) {
        if (function_exists('schemaIsUpgraded') && schemaIsUpgraded()) return;   // v9.33: tables are created once by the upgrade, not on every request
        static $done = false; if ($done) return; $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
                notification_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(160) NOT NULL,
                message VARCHAR(500) NOT NULL,
                url VARCHAR(255) NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_user_read (user_id, is_read, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { error_log('ensureNotificationsTable: ' . $e->getMessage()); }
    }
}
if (!function_exists('notify')) {
    /** $url is a path inside the site, e.g. 'host/payments.php' (no leading slash). Never throws. */
    function notify($pdo, $userId, $title, $message, $url = '') {
        if (!$userId) return;
        try {
            ensureNotificationsTable($pdo);
            // v9.34: a notification about one booking opens THAT booking (in the right tab), not just the list.
            if (preg_match('#^(tourist|host)/bookings\.php$#', (string)$url) && preg_match('/BK-([A-Z0-9]{4,12})/', (string)$message, $m)) $url .= '?ref=' . $m[1];
            $pdo->prepare('INSERT INTO notifications (user_id, title, message, url) VALUES (?,?,?,?)')
                ->execute([(int)$userId, mb_substr((string)$title, 0, 160), mb_substr((string)$message, 0, 500), $url !== '' ? mb_substr(ltrim((string)$url, '/'), 0, 255) : null]);
        } catch (Throwable $e) { error_log('notify: ' . $e->getMessage()); }
    }
}
if (!function_exists('notifyAdmins')) {
    function notifyAdmins($pdo, $title, $message, $url = '') {
        try { foreach ($pdo->query("SELECT user_id FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $id) notify($pdo, (int)$id, $title, $message, $url); }
        catch (Throwable $e) { error_log('notifyAdmins: ' . $e->getMessage()); }
    }
}
if (!function_exists('notifyUserOfBooking')) {
    /** Tells the guest or host of a booking. $who = 'tourist' | 'host'. */
    function notifyUserOfBooking($pdo, $bookingId, $who, $title, $message, $url = '') {
        try {
            $q = $pdo->prepare('SELECT b.tourist_id, a.host_id FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE b.booking_id = ?');
            $q->execute([(int)$bookingId]); $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r) notify($pdo, (int)($who === 'host' ? $r['host_id'] : $r['tourist_id']), $title, $message, $url);
        } catch (Throwable $e) { error_log('notifyUserOfBooking: ' . $e->getMessage()); }
    }
}
if (!function_exists('unreadNotificationCount')) {
    function unreadNotificationCount($pdo, $userId) {
        try { ensureNotificationsTable($pdo); $q = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0'); $q->execute([(int)$userId]); return (int)$q->fetchColumn(); }
        catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('markPageNotificationsRead')) {
    /**
     * Opening the page a notification is about marks it read, however the person got there (menu, link or the bell).
     * A notification matches when its page is the current page and every query value it carries (ref, thread_id ...) is also in the address.
     */
    function markPageNotificationsRead($pdo, $userId) {
        try {
            if (!$userId || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
            $base = function_exists('appBasePath') ? appBasePath() : '';
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
            if ($base !== '' && strpos($path, $base) === 0) $path = substr($path, strlen($base));
            $path = ltrim($path, '/');
            if ($path === '' || $path === 'notifications.php') return;
            ensureNotificationsTable($pdo);
            $q = $pdo->prepare('SELECT notification_id, url FROM notifications WHERE user_id = ? AND is_read = 0 AND url IS NOT NULL AND url LIKE ? LIMIT 100');
            $q->execute([(int)$userId, $path . '%']);
            $ids = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $n) {
                $u = parse_url('/' . ltrim((string)$n['url'], '/'));
                if (ltrim($u['path'] ?? '', '/') !== $path) continue;
                parse_str($u['query'] ?? '', $want);
                $ok = true;
                foreach ($want as $k => $v) { if ((string)($_GET[$k] ?? '') !== (string)$v) { $ok = false; break; } }
                if ($ok) $ids[] = (int)$n['notification_id'];
            }
            if ($ids) $pdo->exec('UPDATE notifications SET is_read = 1 WHERE user_id = ' . (int)$userId . ' AND notification_id IN (' . implode(',', $ids) . ')');
        } catch (Throwable $e) { error_log('markPageNotificationsRead: ' . $e->getMessage()); }
    }
}
