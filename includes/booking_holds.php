<?php
/**
 * v9.33: the expiry rules now live in includes/maintenance.php (run by cron/maintenance.php). This file keeps the old function names
 * so existing pages keep working: expireStaleBookingRequests() is a cheap safety net that only acts when cron is not running.
 *
 * Older description: stops dates being held for free (date-hoarding / "reserved with no payment").
 *
 * A booking holds its dates from the moment it is created, so two rules release them again:
 *   1. No payment details submitted within bookingHoldHours()  -> the request is cancelled.
 *   2. The host has not accepted or declined within hostReplyHours() -> the request is cancelled.
 * Only PENDING requests expire. Once a host has confirmed a booking it is never cancelled by this file.
 * Runs lazily (no cron needed) when bookings, search, details or booking pages are opened, at most once every 5 minutes.
 */

require_once __DIR__ . '/commission.php';   // admin-editable time limits (site_settings)

if (!function_exists('adminEmailAddresses')) {
    /** Email addresses of active admins (used to alert them about reversed payments). */
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
        require_once __DIR__ . '/maintenance.php';
        if ($force) { $r = runMaintenance($pdo); return (int)(($r['expired_unanswered'] ?? 0) + ($r['expired_unpaid'] ?? 0)); }
        $r = runMaintenanceIfDue($pdo);
        return $r ? (int)(($r['expired_unanswered'] ?? 0) + ($r['expired_unpaid'] ?? 0)) : 0;
    }
}
