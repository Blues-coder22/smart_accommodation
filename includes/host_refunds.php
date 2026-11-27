<?php
/**
 * v9.38.1: a host records that they sent a guest a refund for a cancelled booking (money the guest paid the host directly).
 * The guest is told in the app, the admin is told and the record goes into the finance audit trail. One refund record per booking.
 * StayExplore does not move this money: the host pays the guest, this is only the record of it.
 */
require_once __DIR__ . '/finance_audit.php';
require_once __DIR__ . '/notifications.php';
if (!function_exists('ensureHostRefundsTable')) {
    function ensureHostRefundsTable($pdo) {
        static $done = false; if ($done) return; $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS host_refunds (
                refund_id INT AUTO_INCREMENT PRIMARY KEY,
                booking_id INT NOT NULL,
                host_id INT NOT NULL,
                tourist_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                method VARCHAR(20) NOT NULL,
                reference VARCHAR(120) NULL,
                note VARCHAR(300) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_host_refund_booking (booking_id), KEY idx_hr_host (host_id), KEY idx_hr_tourist (tourist_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { error_log('ensureHostRefundsTable: ' . $e->getMessage()); }
    }
}
if (!function_exists('hostRecordRefund')) {
    /** Kept for older callers. The host's refund is now stored on the refund case (includes/refund_requests.php) together with its evidence. */
    function hostRecordRefund($pdo, $bookingId, $hostId, $amount, $method, $reference, $note, $evidenceName = null) {
        require_once __DIR__ . '/refund_requests.php';
        return refundRecordDone($pdo, $bookingId, 'host', $hostId, $amount, $method, $reference, $note, $evidenceName);
    }
}
