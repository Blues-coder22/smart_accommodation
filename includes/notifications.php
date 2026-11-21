<?php
/**
 * In-app notifications (v9.26): every important event is also saved here, so people can see it on the site
 * (bell icon + Notifications page) even when an email does not arrive. Emails are still sent as before.
 */
require_once __DIR__ . '/functions.php';

if (!function_exists('ensureNotificationsTable')) {
    function ensureNotificationsTable($pdo) {
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
