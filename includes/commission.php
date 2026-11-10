<?php
/**
 * Commission, receipts and payment evidence (v9.18).
 *
 * Model B: guests still pay the host directly; the platform never holds the money. When the host confirms a
 * payment, a commission of COMMISSION_RATE_PERCENT is recorded as EARNED and shown as OUTSTANDING until the
 * host pays it and the admin marks it RECEIVED. Earned is never reported as received.
 */
if (is_file(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!defined('COMMISSION_RATE_PERCENT')) define('COMMISSION_RATE_PERCENT', 10);
// Service fee added to the guest's total (percent of stay + extras). 0 turns it off. It is paid to the host with the booking
// and then passed on to the platform together with the commission.
if (!defined('SERVICE_FEE_PERCENT')) define('SERVICE_FEE_PERCENT', 5);
if (!defined('COMMISSION_PAY_INSTRUCTIONS')) define('COMMISSION_PAY_INSTRUCTIONS', 'Send the commission to the StayExplore Kenya account given to you by the administrator (M-Pesa or bank transfer), then submit the reference and a screenshot below.');
// Only payments entered on or after this date earn commission (leave empty to include every confirmed payment, e.g. while demonstrating).
if (!defined('COMMISSION_START_DATE')) define('COMMISSION_START_DATE', '');
if (!defined('RECEIPT_SECRET')) define('RECEIPT_SECRET', 'change-this-secret-in-config-php');

if (!function_exists('kes')) {
    function kes($n) { return 'KES ' . number_format((float)$n, 2); }
}

if (!function_exists('ensureCommissionTables')) {
    function ensureCommissionTables($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS commission_remittances (
                remittance_id INT AUTO_INCREMENT PRIMARY KEY,
                host_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                reference VARCHAR(120) NOT NULL,
                note TEXT NULL,
                proof_file VARCHAR(255) NULL,
                status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL DEFAULT NULL,
                reviewed_by INT NULL,
                review_note VARCHAR(300) NULL,
                KEY idx_host (host_id), KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS commission_ledger (
                ledger_id INT AUTO_INCREMENT PRIMARY KEY,
                booking_id INT NOT NULL,
                payment_id INT NOT NULL,
                host_id INT NOT NULL,
                booking_amount DECIMAL(10,2) NOT NULL,
                rate_percent DECIMAL(5,2) NOT NULL,
                commission_amount DECIMAL(10,2) NOT NULL,
                status ENUM('accrued','submitted','settled','void') NOT NULL DEFAULT 'accrued',
                remittance_id INT NULL,
                accrued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                settled_at TIMESTAMP NULL DEFAULT NULL,
                settled_by INT NULL,
                settle_ref VARCHAR(120) NULL,
                flag_note VARCHAR(255) NULL,
                UNIQUE KEY uq_booking (booking_id), KEY idx_host_status (host_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            if (!$pdo->query("SHOW COLUMNS FROM bookings LIKE 'service_fee'")->fetch()) $pdo->exec("ALTER TABLE bookings ADD COLUMN service_fee DECIMAL(10,2) NOT NULL DEFAULT 0");
            if (!$pdo->query("SHOW COLUMNS FROM commission_ledger LIKE 'service_fee'")->fetch()) $pdo->exec("ALTER TABLE commission_ledger ADD COLUMN service_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER commission_amount");
            $col = $pdo->query("SHOW COLUMNS FROM payments LIKE 'payment_proof'")->fetch();
            if (!$col) $pdo->exec("ALTER TABLE payments ADD COLUMN payment_proof VARCHAR(255) NULL");
        } catch (Throwable $e) { error_log('ensureCommissionTables: ' . $e->getMessage()); }
    }
}

if (!function_exists('reconcileCommissionLedger')) {
    /**
     * Brings the ledger in line with payments and bookings. Safe to run any time (idempotent):
     * a confirmed payment on a booking that is not cancelled earns commission at the current rate (the rate is
     * saved on the row, so later rate changes never alter history); if the payment is reversed or the booking
     * cancelled before the commission is paid, the row is voided; if it comes back, the row is revived.
     */
    function reconcileCommissionLedger($pdo) {
        ensureCommissionTables($pdo);
        try {
            $rate = (float)COMMISSION_RATE_PERCENT;
            $startSql = (COMMISSION_START_DATE !== '' && strtotime(COMMISSION_START_DATE)) ? " AND p.payment_date >= " . $pdo->quote(date('Y-m-d', strtotime(COMMISSION_START_DATE))) : '';
            // Commission is worked out on what the host charges (the amount WITHOUT the service fee); the service fee is added on top.
            $pdo->prepare("INSERT INTO commission_ledger (booking_id, payment_id, host_id, booking_amount, rate_percent, commission_amount, service_fee)
                SELECT p.booking_id, p.payment_id, p.host_id, p.amount, ?, ROUND((p.amount - COALESCE(b.service_fee,0)) * ? / 100, 2), COALESCE(b.service_fee,0)
                FROM payments p JOIN bookings b ON b.booking_id = p.booking_id
                WHERE p.payment_status = 'confirmed' AND b.status <> 'cancelled'
                  AND p.payment_id = (SELECT MAX(p2.payment_id) FROM payments p2 WHERE p2.booking_id = p.booking_id)
                  AND NOT EXISTS (SELECT 1 FROM commission_ledger l WHERE l.booking_id = p.booking_id)" . $startSql)->execute([$rate, $rate]);
            $pdo->exec("UPDATE commission_ledger l JOIN payments p ON p.payment_id = l.payment_id JOIN bookings b ON b.booking_id = l.booking_id
                SET l.status = 'void' WHERE l.status = 'accrued' AND (p.payment_status <> 'confirmed' OR b.status = 'cancelled')");
            $pdo->exec("UPDATE commission_ledger l JOIN payments p ON p.payment_id = l.payment_id JOIN bookings b ON b.booking_id = l.booking_id
                SET l.status = 'accrued' WHERE l.status = 'void' AND p.payment_status = 'confirmed' AND b.status <> 'cancelled'");
            $pdo->exec("UPDATE commission_ledger l JOIN payments p ON p.payment_id = l.payment_id JOIN bookings b ON b.booking_id = l.booking_id
                SET l.flag_note = 'Payment was reversed or the booking cancelled after this commission was paid. Review it.'
                WHERE l.status = 'settled' AND l.flag_note IS NULL AND (p.payment_status <> 'confirmed' OR b.status = 'cancelled')");
        } catch (Throwable $e) { error_log('reconcileCommissionLedger: ' . $e->getMessage()); }
    }
}

if (!function_exists('commissionSummary')) {
    /** earned = everything not void; received = settled; awaiting = submitted by host, not yet reviewed; outstanding = still owed. */
    function commissionSummary($pdo, $hostId = null) {
        ensureCommissionTables($pdo);
        $sql = "SELECT COUNT(*) AS bookings, COALESCE(SUM(booking_amount),0) AS booking_value,
                       COALESCE(SUM(commission_amount + service_fee),0) AS earned,
                       COALESCE(SUM(commission_amount),0) AS earned_commission,
                       COALESCE(SUM(service_fee),0) AS earned_fee,
                       COALESCE(SUM(CASE WHEN status='settled'   THEN commission_amount + service_fee END),0) AS received,
                       COALESCE(SUM(CASE WHEN status='submitted' THEN commission_amount + service_fee END),0) AS awaiting,
                       COALESCE(SUM(CASE WHEN status='accrued'   THEN commission_amount + service_fee END),0) AS outstanding
                FROM commission_ledger WHERE status <> 'void'" . ($hostId ? " AND host_id = ?" : "");
        $st = $pdo->prepare($sql); $st->execute($hostId ? [(int)$hostId] : []);
        return $st->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('commissionPrivateDir')) {
    function commissionPrivateDir($kind) {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private_uploads' . DIRECTORY_SEPARATOR . ($kind === 'payment' ? 'payment_proofs' : 'commission_proofs');
    }
}

if (!function_exists('savePrivateProofImage')) {
    /** Saves an uploaded screenshot OUTSIDE the public folders. Checks the real file type, not the name. Returns file name, null (none sent) or false (rejected). */
    function savePrivateProofImage($fileField, $kind, $maxBytes = 5242880) {
        if (empty($_FILES[$fileField]) || ($_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        $f = $_FILES[$fileField];
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > $maxBytes || !is_uploaded_file($f['tmp_name'])) return false;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext) return false;
        $dir = commissionPrivateDir($kind);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) return false;
        $name = $kind . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
        return move_uploaded_file($f['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name) ? $name : false;
    }
}

if (!function_exists('receiptNumber')) {
    function receiptNumber($paymentId) { return 'RC-' . str_pad((int)$paymentId, 6, '0', STR_PAD_LEFT); }
}
if (!function_exists('receiptVerificationCode')) {
    /** Short code that only the system can produce for these exact details; shown on the receipt and on the admin evidence pack. */
    function receiptVerificationCode($payment) {
        $data = (int)$payment['payment_id'] . '|' . (int)$payment['booking_id'] . '|' . number_format((float)$payment['amount'], 2, '.', '') . '|' . (string)$payment['payment_reference'];
        return strtoupper(substr(hash_hmac('sha256', $data, RECEIPT_SECRET), 0, 10));
    }
}

if (!function_exists('serviceFeeFor')) {
    /** Service fee for a booking subtotal (stay + extras), in whole shillings. */
    function serviceFeeFor($subtotal) {
        $pct = (float)SERVICE_FEE_PERCENT;
        return $pct > 0 ? (float)round((float)$subtotal * $pct / 100) : 0.0;
    }
}
if (!function_exists('platformDueForBooking')) {
    /** [commission, service_fee, total] a host will owe the platform for a confirmed payment of $amount on this booking. */
    function platformDueForBooking($pdo, $bookingId, $amount) {
        $fee = 0.0;
        try { ensureCommissionTables($pdo); $q = $pdo->prepare('SELECT service_fee FROM bookings WHERE booking_id = ?'); $q->execute([(int)$bookingId]); $fee = (float)$q->fetchColumn(); } catch (Throwable $e) {}
        $commission = round(((float)$amount - $fee) * (float)COMMISSION_RATE_PERCENT / 100, 2);
        return [$commission, $fee, $commission + $fee];
    }
}

