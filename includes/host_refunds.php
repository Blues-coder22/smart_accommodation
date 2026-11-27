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
    /** Returns ['ok' => bool, 'error' => string]. */
    function hostRecordRefund($pdo, $bookingId, $hostId, $amount, $method, $reference, $note) {
        ensureHostRefundsTable($pdo);
        $amount = round((float)$amount, 2); $reference = mb_substr(trim((string)$reference), 0, 120); $note = mb_substr(trim((string)$note), 0, 300);
        if (!in_array($method, ['mpesa', 'bank_transfer', 'cash'], true)) return ['ok' => false, 'error' => 'Choose how you sent the refund.'];
        if ($method !== 'cash' && mb_strlen($reference) < 4) return ['ok' => false, 'error' => 'Enter the transaction reference of the refund (at least 4 characters).'];
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare("SELECT b.booking_id, b.status, b.tourist_id, a.host_id, a.title,
                    (SELECT p.amount FROM payments p WHERE p.booking_id = b.booking_id AND p.payment_status = 'confirmed' ORDER BY p.payment_id DESC LIMIT 1) AS paid
                FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE b.booking_id = ? FOR UPDATE");
            $q->execute([(int)$bookingId]); $b = $q->fetch(PDO::FETCH_ASSOC);
            if (!$b || (int)$b['host_id'] !== (int)$hostId) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Booking not found.']; }
            if ($b['status'] !== 'cancelled') { $pdo->rollBack(); return ['ok' => false, 'error' => 'A refund can only be recorded for a cancelled booking.']; }
            $paid = (float)($b['paid'] ?? 0);
            if ($paid <= 0) { $pdo->rollBack(); return ['ok' => false, 'error' => 'You did not confirm a payment for this booking, so there is nothing to refund.']; }
            if ($amount <= 0 || $amount > $paid + 0.001) { $pdo->rollBack(); return ['ok' => false, 'error' => 'The refund must be more than 0 and no more than the KES ' . number_format($paid, 2) . ' you received.']; }
            $dup = $pdo->prepare('SELECT 1 FROM host_refunds WHERE booking_id = ?'); $dup->execute([(int)$bookingId]);
            if ($dup->fetchColumn()) { $pdo->rollBack(); return ['ok' => false, 'error' => 'A refund is already recorded for this booking.']; }
            $pdo->prepare('INSERT INTO host_refunds (booking_id, host_id, tourist_id, amount, method, reference, note) VALUES (?,?,?,?,?,?,?)')
                ->execute([(int)$bookingId, (int)$hostId, (int)$b['tourist_id'], $amount, $method, $reference !== '' ? $reference : null, $note !== '' ? $note : null]);
            $rid = (int)$pdo->lastInsertId();
            logFinanceEvent($pdo, 'host_refund', $rid, (int)$hostId, (int)$hostId, 'host', 'refund_recorded', null, 'sent', $amount, bookingLabel($bookingId) . ' | ' . $method . ($reference !== '' ? ' ref ' . $reference : '') . ($note !== '' ? ' | ' . $note : ''));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('hostRecordRefund: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the refund. Please try again.'];
        }
        $label = bookingLabel($bookingId); $how = ['mpesa' => 'M-Pesa', 'bank_transfer' => 'bank transfer', 'cash' => 'cash'][$method];
        notify($pdo, (int)$b['tourist_id'], 'Refund sent', 'Your host says they refunded KES ' . number_format($amount, 2) . ' for ' . $label . ' by ' . $how . ($reference !== '' ? ' (ref ' . $reference . ')' : '') . '. If you have not received it, message your host or contact support.', 'tourist/bookings.php');
        notifyAdmins($pdo, 'Host recorded a refund', $label . ': KES ' . number_format($amount, 2) . ' by ' . $how . ($reference !== '' ? ', ref ' . $reference : '') . '.', 'admin/payments.php');
        return ['ok' => true, 'error' => ''];
    }
}