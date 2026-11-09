<?php
/**
 * Stops dates being held for free (date-hoarding / "reserved with no payment").
 *
 * A booking holds its dates from the moment it is created, so two rules release them again:
 *   1. No payment details submitted within BOOKING_NO_PAYMENT_HOLD_HOURS  -> the request is cancelled.
 *   2. The host has not accepted or declined within BOOKING_HOST_REPLY_HOURS -> the request is cancelled.
 * Only PENDING requests expire. Once a host has confirmed a booking it is never cancelled by this file.
 * Runs lazily (no cron needed) when bookings, search, details or booking pages are opened, at most once every 5 minutes.
 */
if (!defined('BOOKING_NO_PAYMENT_HOLD_HOURS')) define('BOOKING_NO_PAYMENT_HOLD_HOURS', 2);
if (!defined('BOOKING_HOST_REPLY_HOURS'))      define('BOOKING_HOST_REPLY_HOURS', 48);

if (!function_exists('adminEmailAddresses')) {
    /** Email addresses of active administrators (used to alert them about reversed payments). */
    function adminEmailAddresses($pdo) {
        if (function_exists('adminNotificationRecipient')) {   // honours ADMIN_NOTIFY_EMAIL in includes/config.php
            $r = adminNotificationRecipient($pdo);
            return $r ? array_map('trim', explode(',', $r['email'])) : [];
        }
        try {
            return $pdo->query("SELECT email FROM users WHERE LOWER(TRIM(role)) = 'admin' AND (status IS NULL OR status NOT IN ('suspended','banned')) AND account_closed_at IS NULL")
                       ->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('expireStaleBookingRequests')) {
    function expireStaleBookingRequests($pdo, $force = false) {
        $stamp = __DIR__ . '/../logs/.hold_check';
        if (!$force && is_file($stamp) && (time() - (int)@filemtime($stamp)) < 300) return 0;
        if (!is_dir(dirname($stamp))) @mkdir(dirname($stamp), 0775, true);
        @touch($stamp);

        $count = 0;
        try {
            require_once __DIR__ . '/booking_events.php';
            require_once __DIR__ . '/email.php';
            $q = $pdo->prepare("
                SELECT b.booking_id, b.check_in_date, b.check_out_date, a.title,
                       t.email AS t_email, t.full_name AS t_name, h.email AS h_email, h.full_name AS h_name,
                       (SELECT COUNT(*) FROM payments p WHERE p.booking_id = b.booking_id) AS pay_rows
                FROM bookings b
                JOIN accommodations a ON a.accommodation_id = b.accommodation_id
                JOIN users t ON t.user_id = b.tourist_id
                JOIN users h ON h.user_id = a.host_id
                WHERE b.status = 'pending' AND (
                      (NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.booking_id)
                        AND b.created_at < DATE_SUB(NOW(), INTERVAL " . (int)BOOKING_NO_PAYMENT_HOLD_HOURS . " HOUR))
                   OR b.created_at < DATE_SUB(NOW(), INTERVAL " . (int)BOOKING_HOST_REPLY_HOURS . " HOUR))
                LIMIT 200");
            $q->execute();
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $u = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE booking_id = ? AND status = 'pending'");
                $u->execute([(int)$r['booking_id']]);
                if ($u->rowCount() < 1) continue;
                $count++;
                $noPay = ((int)$r['pay_rows'] === 0);
                logBookingEvent($pdo, (int)$r['booking_id'], null, 'auto_expired',
                    $noPay ? 'No payment details within ' . BOOKING_NO_PAYMENT_HOLD_HOURS . ' hours; dates released'
                           : 'Host did not respond within ' . BOOKING_HOST_REPLY_HOURS . ' hours; dates released');
                try {
                    sendBookingExpiredEmail($r['t_email'], $r['t_name'], $r, $noPay ? 'no_payment' : 'no_reply', false);
                    if (!$noPay) sendBookingExpiredEmail($r['h_email'], $r['h_name'], $r, 'no_reply', true);
                } catch (Throwable $e) {}
            }
            sendPaymentDeadlineReminders($pdo);
        } catch (Throwable $e) { error_log('expireStaleBookingRequests: ' . $e->getMessage()); }
        return $count;
    }
}

if (!function_exists('sendPaymentDeadlineReminders')) {
    /**
     * Warns a guest about 30 minutes before a request with no payment details is cancelled. Sent once per booking.
     */
    function sendPaymentDeadlineReminders($pdo) {
        try {
            $holdMin = (int)BOOKING_NO_PAYMENT_HOLD_HOURS * 60;
            $q = $pdo->prepare("
                SELECT b.booking_id, b.check_in_date, b.check_out_date, a.title, t.email AS t_email, t.full_name AS t_name,
                       TIMESTAMPDIFF(MINUTE, b.created_at, NOW()) AS age_min
                FROM bookings b
                JOIN accommodations a ON a.accommodation_id = b.accommodation_id
                JOIN users t ON t.user_id = b.tourist_id
                WHERE b.status = 'pending'
                  AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.booking_id)
                  AND NOT EXISTS (SELECT 1 FROM booking_events e WHERE e.booking_id = b.booking_id AND e.event = 'payment_reminder')
                  AND b.created_at <= DATE_SUB(NOW(), INTERVAL " . ($holdMin - 30) . " MINUTE)
                  AND b.created_at >  DATE_SUB(NOW(), INTERVAL " . $holdMin . " MINUTE)
                LIMIT 100");
            $q->execute();
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                logBookingEvent($pdo, (int)$r['booking_id'], null, 'payment_reminder', 'Reminder sent before the payment hold ends');
                $left = max(1, $holdMin - (int)$r['age_min']);
                try { sendPaymentDeadlineReminderEmail($r['t_email'], $r['t_name'], $r, $left); } catch (Throwable $e) {}
            }
        } catch (Throwable $e) { error_log('sendPaymentDeadlineReminders: ' . $e->getMessage()); }
    }
}
