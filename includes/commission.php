<?php
/**
 * Money flow, receipts and payment evidence (v9.21).
 *
 * The guest pays TWO things for a booking, each straight to its owner:
 *   1. the HOST'S SHARE (stay + extras, minus the platform commission)  -> to the host      (recorded in `payments`, confirmed by the host)
 *   2. the PLATFORM'S SHARE (guest service fee + host commission)         -> to the admin     (recorded in `platform_payments`, confirmed by the admin)
 * The platform never receives the host's money and the host never receives the platform's. Nothing is owed later.
 * Rates are set by the admin in Revenue & commission (stored in site_settings); config.php only gives the starting values.
 *
 * v9.28: the admin can choose the COMMISSION MODE (site_settings 'commission_mode'):
 *   upfront - as above: the guest pays the commission to the platform together with the booking (the default).
 *   invoice - the guest pays the host the FULL price; the host owes the commission after the stay, pays it to the platform,
 *             and the admin confirms it (table host_commission_payments). Hosts with overdue commission can be blocked from new bookings.
 * Each booking remembers the mode it was made in (bookings.commission_mode), so changing the setting never alters past bookings.
 * 'commission_base' chooses what the commission is a percentage of: stay + extras (default) or the accommodation price only.
 */
if (is_file(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!defined('COMMISSION_RATE_PERCENT')) define('COMMISSION_RATE_PERCENT', 10);
if (!defined('SERVICE_FEE_PERCENT')) define('SERVICE_FEE_PERCENT', 0);   // no guest fee at launch; the admin can switch it on later
if (!defined('COMMISSION_PAY_INSTRUCTIONS')) define('COMMISSION_PAY_INSTRUCTIONS', 'Send this amount by M-Pesa or bank transfer to the StayExplore Kenya account given by the admin, then enter the reference below.');
if (!defined('RECEIPT_SECRET')) define('RECEIPT_SECRET', 'change-this-secret-in-config-php');

if (!function_exists('kes')) { function kes($n) { return 'KES ' . number_format((float)$n, 2); } }

if (!function_exists('ensureCommissionTables')) {
    function ensureCommissionTables($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
                setting_key VARCHAR(60) PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                updated_by INT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS platform_payments (
                platform_payment_id INT AUTO_INCREMENT PRIMARY KEY,
                booking_id INT NOT NULL,
                tourist_id INT NOT NULL,
                host_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                service_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
                commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                method ENUM('mpesa','bank_transfer') NOT NULL,
                reference VARCHAR(120) NOT NULL,
                proof_file VARCHAR(255) NULL,
                status ENUM('pending','confirmed','rejected','refunded') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL DEFAULT NULL,
                reviewed_by INT NULL,
                review_note VARCHAR(300) NULL,
                UNIQUE KEY uq_booking (booking_id), KEY idx_status (status), KEY idx_host (host_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            foreach (['service_fee' => 'DECIMAL(10,2) NOT NULL DEFAULT 0', 'commission_amount' => 'DECIMAL(10,2) NULL DEFAULT NULL', 'commission_rate' => 'DECIMAL(5,2) NULL DEFAULT NULL'] as $col => $def) {
                if (!$pdo->query("SHOW COLUMNS FROM bookings LIKE '$col'")->fetch()) $pdo->exec("ALTER TABLE bookings ADD COLUMN $col $def");
            }
            if (!$pdo->query("SHOW COLUMNS FROM bookings LIKE 'commission_mode'")->fetch()) $pdo->exec("ALTER TABLE bookings ADD COLUMN commission_mode VARCHAR(10) NOT NULL DEFAULT 'upfront'");
            $pdo->exec("CREATE TABLE IF NOT EXISTS host_commission_payments (
                hcp_id INT AUTO_INCREMENT PRIMARY KEY,
                host_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                method ENUM('mpesa','bank_transfer') NOT NULL,
                reference VARCHAR(120) NOT NULL,
                status ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at TIMESTAMP NULL DEFAULT NULL,
                reviewed_by INT NULL,
                review_note VARCHAR(300) NULL,
                KEY idx_host (host_id), KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS host_agreements (
                agreement_id INT AUTO_INCREMENT PRIMARY KEY,
                host_id INT NOT NULL,
                version VARCHAR(20) NOT NULL,
                terms_snapshot TEXT NOT NULL,
                accepted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                accepted_ip VARCHAR(45) NULL,
                KEY idx_host (host_id, version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            foreach (['refund_due' => 'DECIMAL(10,2) NULL', 'refund_note' => 'VARCHAR(255) NULL', 'refunded_amount' => 'DECIMAL(10,2) NULL'] as $col => $def) {
                if (!$pdo->query("SHOW COLUMNS FROM platform_payments LIKE '$col'")->fetch()) $pdo->exec("ALTER TABLE platform_payments ADD COLUMN $col $def");
            }
            if (!$pdo->query("SHOW COLUMNS FROM payments LIKE 'payment_proof'")->fetch()) $pdo->exec("ALTER TABLE payments ADD COLUMN payment_proof VARCHAR(255) NULL");
        } catch (Throwable $e) { error_log('ensureCommissionTables: ' . $e->getMessage()); }
    }
}

// ---- Rates the admin can change (site_settings), falling back to config.php ----
if (!function_exists('siteSettingGet')) {
    function siteSettingGet($key, $default) {
        global $pdo; static $cache = null;
        try {
            if ($cache === null) { ensureCommissionTables($pdo); $cache = $pdo->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll(PDO::FETCH_KEY_PAIR); }
            return array_key_exists($key, $cache) ? $cache[$key] : $default;
        } catch (Throwable $e) { return $default; }
    }
}
if (!function_exists('siteSettingSet')) {
    function siteSettingSet($key, $value, $userId = null) {
        global $pdo; ensureCommissionTables($pdo);
        $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value, updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)')->execute([$key, (string)$value, $userId]);
    }
}
if (!function_exists('commissionRatePercent')) { function commissionRatePercent() { return max(0.0, min(50.0, (float)siteSettingGet('commission_rate_percent', COMMISSION_RATE_PERCENT))); } }
if (!function_exists('serviceFeePercent'))    { function serviceFeePercent()    { return max(0.0, min(50.0, (float)siteSettingGet('service_fee_percent', SERVICE_FEE_PERCENT))); } }
// ---- v9.28 business-model switches (all in site_settings; the defaults keep the v9.27 behaviour) ----
if (!function_exists('commissionMode'))     { function commissionMode()     { return siteSettingGet('commission_mode', 'upfront') === 'invoice' ? 'invoice' : 'upfront'; } }
if (!function_exists('commissionBase'))     { function commissionBase()     { return siteSettingGet('commission_base', 'stay_extras') === 'stay_only' ? 'stay_only' : 'stay_extras'; } }
if (!function_exists('invoiceDueDays'))     { function invoiceDueDays()     { return max(0, min(90, (int)siteSettingGet('invoice_due_days', 7))); } }
if (!function_exists('blockOverdueHosts'))  { function blockOverdueHosts()  { return siteSettingGet('block_overdue_hosts', '1') === '1'; } }
// ---- Booking time limits the admin can change (Revenue & commission) ----
if (!function_exists('bookingHoldHours')) { function bookingHoldHours() { return max(1, min(48, (int)siteSettingGet('booking_hold_hours', 2))); } }
if (!function_exists('hostReplyHours'))   { function hostReplyHours()   { return max(1, min(168, (int)siteSettingGet('host_reply_hours', 48))); } }
if (!function_exists('freeCancelHours'))  { function freeCancelHours()  { return max(0, min(720, (int)siteSettingGet('free_cancel_hours', 48))); } }
if (!function_exists('pct')) { function pct($n) { return rtrim(rtrim(number_format((float)$n, 2), '0'), '.'); } }

if (!function_exists('serviceFeeFor')) { function serviceFeeFor($subtotal) { $p = serviceFeePercent(); return $p > 0 ? (float)round((float)$subtotal * $p / 100) : 0.0; } }
if (!function_exists('commissionFor')) { function commissionFor($hostPrice) { $p = commissionRatePercent(); return $p > 0 ? (float)round((float)$hostPrice * $p / 100) : 0.0; } }

if (!function_exists('bookingSplit')) {
    /**
     * Who gets what from one booking. host_amount goes to the host, platform goes to the admin AT BOOKING TIME.
     * 'commission' is always the commission on the booking; 'commission_upfront' is the part the guest pays the platform now
     * (all of it in 'upfront' mode, none in 'invoice' mode); 'commission_deferred' is the part the host owes after the stay.
     * Bookings made before the split (commission_amount empty) are "legacy": everything went to the host and there is no platform payment.
     */
    function bookingSplit(array $b) {
        $total = (float)($b['total_amount'] ?? 0); $fee = (float)($b['service_fee'] ?? 0);
        if (!array_key_exists('commission_amount', $b) || $b['commission_amount'] === null) {
            return ['legacy' => true, 'mode' => 'upfront', 'total' => $total, 'service_fee' => $fee, 'commission' => 0.0, 'commission_upfront' => 0.0, 'commission_deferred' => 0.0, 'platform' => 0.0, 'host_amount' => $total];
        }
        $c = (float)$b['commission_amount'];
        $mode = (($b['commission_mode'] ?? 'upfront') === 'invoice') ? 'invoice' : 'upfront';
        $up = $mode === 'invoice' ? 0.0 : $c; $def = $mode === 'invoice' ? $c : 0.0;
        $platform = $fee + $up;
        return ['legacy' => false, 'mode' => $mode, 'total' => $total, 'service_fee' => $fee, 'commission' => $c, 'commission_upfront' => $up, 'commission_deferred' => $def, 'platform' => $platform, 'host_amount' => max(0.0, $total - $platform)];
    }
}

if (!function_exists('platformSummary')) {
    /** Totals for the admin. received = confirmed by the admin; awaiting = reported by the guest, not yet checked. */
    function platformSummary($pdo, $hostId = null) {
        ensureCommissionTables($pdo);
        $q = $pdo->prepare("SELECT COUNT(*) AS n,
              COALESCE(SUM(CASE WHEN status='confirmed' THEN amount END),0) AS received,
              COALESCE(SUM(CASE WHEN status='confirmed' THEN service_fee END),0) AS received_fee,
              COALESCE(SUM(CASE WHEN status='confirmed' THEN commission_amount END),0) AS received_commission,
              COALESCE(SUM(CASE WHEN status='pending' THEN amount END),0) AS awaiting,
              COALESCE(SUM(CASE WHEN status='pending' THEN 1 END),0) AS awaiting_n,
              COALESCE(SUM(CASE WHEN status='refunded' THEN amount END),0) AS refunded
            FROM platform_payments" . ($hostId ? " WHERE host_id = ?" : ''));
        $q->execute($hostId ? [(int)$hostId] : []);
        return $q->fetch(PDO::FETCH_ASSOC);
    }
}

// ---- Private proof screenshots (kept outside the public folders) ----
if (!function_exists('commissionPrivateDir')) {
    function commissionPrivateDir($kind) { return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private_uploads' . DIRECTORY_SEPARATOR . ($kind === 'platform' ? 'platform_proofs' : 'payment_proofs'); }
}
if (!function_exists('savePrivateProofImage')) {
    /** Returns the saved file name, null (nothing sent) or false (rejected). The real image type is checked, not the file name. */
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

// ---- Receipts ----
if (!function_exists('receiptNumber')) {
    /** RC-/RP- plus a short code derived from the id with the secret key, so receipt numbers do not reveal how many payments exist. */
    function receiptNumber($paymentId, $platform = false) {
        $h = hash_hmac('sha256', ($platform ? 'platform|' : 'payment|') . (int)$paymentId, RECEIPT_SECRET, true);
        $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; $out = '';
        for ($i = 0; $i < 10; $i++) $out .= $alpha[ord($h[$i]) % strlen($alpha)];
        return ($platform ? 'RP-' : 'RC-') . $out;
    }
}
if (!function_exists('receiptVerificationCode')) {
    /** Code only the system can produce for these exact details (host payment: payment_id/booking_id/amount/payment_reference). */
    function receiptVerificationCode($payment) {
        $data = (int)$payment['payment_id'] . '|' . (int)$payment['booking_id'] . '|' . number_format((float)$payment['amount'], 2, '.', '') . '|' . (string)($payment['payment_reference'] ?? '');
        return strtoupper(substr(hash_hmac('sha256', $data, RECEIPT_SECRET), 0, 10));
    }
}
if (!function_exists('platformReceiptVerificationCode')) {
    function platformReceiptVerificationCode($pp) {
        $data = 'P' . (int)$pp['platform_payment_id'] . '|' . (int)$pp['booking_id'] . '|' . number_format((float)$pp['amount'], 2, '.', '') . '|' . (string)$pp['reference'];
        return strtoupper(substr(hash_hmac('sha256', $data, RECEIPT_SECRET), 0, 10));
    }
}

if (!function_exists('bookingSplitById')) {
    function bookingSplitById($pdo, $bookingId) {
        ensureCommissionTables($pdo);
        $q = $pdo->prepare('SELECT total_amount, service_fee, commission_amount, commission_mode FROM bookings WHERE booking_id = ?'); $q->execute([(int)$bookingId]);
        return bookingSplit($q->fetch(PDO::FETCH_ASSOC) ?: ['total_amount' => 0]);
    }
}

if (!function_exists('applyCancellationRefund')) {
    /**
     * When a booking is cancelled after the guest's payment to the platform was accepted, work out the refund and tell everyone.
     * Policy: the host cancels, or the system releases the dates -> the platform's share is refunded in full.
     *         The guest cancels at least free-cancel hours before check-in -> refunded in full.
     *         The guest cancels later -> no automatic refund of the platform's share (the admin can still refund it by hand).
     * No money moves here: it records what is due and notifies. The admin sends the money and records it in Revenue & commission.
     * @param string $by 'guest' | 'host' | 'system'
     */
    function applyCancellationRefund($pdo, $bookingId, $by) {
        try {
            ensureCommissionTables($pdo); require_once __DIR__ . '/notifications.php'; require_once __DIR__ . '/email.php';
            $q = $pdo->prepare("SELECT pp.*, b.check_in_date, b.tourist_id, a.title, a.host_id, t.email AS t_email, t.full_name AS t_name,
                    (SELECT p.amount FROM payments p WHERE p.booking_id = pp.booking_id AND p.payment_status = 'confirmed' ORDER BY p.payment_id DESC LIMIT 1) AS host_paid
                FROM platform_payments pp JOIN bookings b ON b.booking_id = pp.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id
                JOIN users t ON t.user_id = pp.tourist_id WHERE pp.booking_id = ? AND pp.status = 'confirmed' AND pp.refund_due IS NULL");
            $q->execute([(int)$bookingId]); $r = $q->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $late = $by === 'guest' && function_exists('isLateCancellation') && isLateCancellation($r['check_in_date']);
            $due = $late ? 0.0 : (float)$r['amount'];
            $note = $by === 'host' ? 'The host cancelled: full refund of the platform share.'
                  : ($by === 'system' ? 'The dates were released automatically: full refund of the platform share.'
                  : ($late ? 'Cancelled less than ' . freeCancelHours() . ' hours before check-in: the platform share is not refunded automatically.'
                           : 'Cancelled at least ' . freeCancelHours() . ' hours before check-in: full refund of the platform share.'));
            $pdo->prepare('UPDATE platform_payments SET refund_due = ?, refund_note = ? WHERE platform_payment_id = ?')->execute([$due, $note, $r['platform_payment_id']]);
            $label = bookingLabel($bookingId); $hostPaid = (float)$r['host_paid'];
            $hostLine = $hostPaid > 0 ? ' Your host received KES ' . number_format($hostPaid, 2) . ' directly: ask your host for that refund (see the cancellation rules).' : '';
            $msg = $due > 0 ? "Booking $label was cancelled. StayExplore Kenya owes you a refund of KES " . number_format($due, 2) . '; the admin will send it.' . $hostLine
                            : "Booking $label was cancelled. $note" . $hostLine;
            notify($pdo, (int)$r['tourist_id'], $due > 0 ? 'Refund due to you' : 'Booking cancelled', $msg, 'tourist/bookings.php');
            if ($hostPaid > 0) notify($pdo, (int)$r['host_id'], 'Booking cancelled: refund the guest', "Booking $label was cancelled. You received KES " . number_format($hostPaid, 2) . ' from the guest: agree the refund with them.', 'host/bookings.php');
            notifyAdmins($pdo, 'Refund to record', "Booking $label cancelled. " . ($due > 0 ? 'Refund due to the guest: KES ' . number_format($due, 2) . '.' : 'No automatic refund (' . $note . ')'), 'admin/revenue.php');
            try { sendRefundNoticeGuestEmail($r['t_email'], $r['t_name'], $bookingId, $r['title'], $due, $note, $hostPaid); } catch (Throwable $e) {}
            return $due;
        } catch (Throwable $e) { error_log('applyCancellationRefund: ' . $e->getMessage()); return null; }
    }
}

// ===================== v9.28: commission invoiced to the host after the stay =====================
if (!function_exists('hostCommissionLedger')) {
    /**
     * What one host owes in 'invoice' mode. A booking's commission becomes due when its stay has ended (check-out date passed) and the
     * booking was confirmed or completed; it must be paid within invoiceDueDays() after check-out. Confirmed payments from the host are
     * applied to the oldest bookings first. Payments still waiting for the admin's check are shown but do not reduce the balance yet.
     * Returns ['due','paid','pending','outstanding','overdue','items'=>[...]].
     */
    function hostCommissionLedger($pdo, $hostId) {
        ensureCommissionTables($pdo);
        $q = $pdo->prepare("SELECT b.booking_id, b.check_in_date, b.check_out_date, b.commission_amount, b.commission_rate, a.title
            FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id
            WHERE a.host_id = ? AND b.commission_mode = 'invoice' AND b.commission_amount > 0 AND b.status IN ('confirmed','completed')
              AND b.check_out_date <= CURDATE() ORDER BY b.check_out_date ASC, b.booking_id ASC");
        $q->execute([(int)$hostId]); $items = $q->fetchAll(PDO::FETCH_ASSOC);
        $p = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status='confirmed' THEN amount END),0) AS paid, COALESCE(SUM(CASE WHEN status='pending' THEN amount END),0) AS pending FROM host_commission_payments WHERE host_id = ?");
        $p->execute([(int)$hostId]); $pay = $p->fetch(PDO::FETCH_ASSOC);
        $credit = (float)$pay['paid']; $due = 0.0; $out = 0.0; $overdue = 0.0; $today = date('Y-m-d'); $days = invoiceDueDays();
        foreach ($items as &$it) {
            $amt = (float)$it['commission_amount']; $due += $amt;
            $applied = min($credit, $amt); $credit -= $applied;
            $it['unpaid'] = $amt - $applied; $it['due_date'] = date('Y-m-d', strtotime($it['check_out_date'] . " +$days days"));
            $it['overdue'] = $it['unpaid'] > 0 && $it['due_date'] < $today;
            $out += $it['unpaid']; if ($it['overdue']) $overdue += $it['unpaid'];
        }
        unset($it);
        return ['due' => $due, 'paid' => (float)$pay['paid'], 'pending' => (float)$pay['pending'], 'outstanding' => $out, 'overdue' => $overdue, 'items' => $items];
    }
}
if (!function_exists('hostIsBlockedForCommission')) {
    /** True when the admin blocks overdue hosts and this host has commission past its due date. */
    function hostIsBlockedForCommission($pdo, $hostId) {
        if (!blockOverdueHosts()) return false;
        try { return hostCommissionLedger($pdo, $hostId)['overdue'] > 0; } catch (Throwable $e) { return false; }
    }
}

// ===================== v9.28: recorded host agreement =====================
if (!defined('HOST_AGREEMENT_VERSION')) define('HOST_AGREEMENT_VERSION', '1');
if (!function_exists('hostAgreementText')) {
    /** The terms as they stand today, in plain words. A copy is stored with each acceptance, so the host's record shows exactly what was agreed. */
    function hostAgreementText() {
        $rate = pct(commissionRatePercent()); $base = commissionBase() === 'stay_only' ? 'the accommodation (stay) price only' : 'your price for the stay plus any extras you charge';
        $how = commissionMode() === 'invoice'
            ? "Guests pay you the full price directly. After each stay ends, the commission on that booking becomes due to StayExplore Kenya and you pay it by M-Pesa or bank transfer within " . invoiceDueDays() . " days of check-out. You report each payment on the Commission page and StayExplore Kenya confirms it."
            : "The guest pays the commission to StayExplore Kenya together with the booking, so you receive your price minus the commission directly from the guest and owe nothing afterwards.";
        $block = commissionMode() === 'invoice' && blockOverdueHosts() ? "If commission stays unpaid after its due date, your listings may be paused for new bookings until it is paid." : "";
        $clauses = array_values(array_filter([
            "Commission. StayExplore Kenya charges a commission of {$rate}% of {$base} on each booking made through the platform. The rate in force when a booking is made is saved on that booking and does not change afterwards.",
            "How it is paid. $how",
            "Your money. StayExplore Kenya does not hold or handle the accommodation payment. The guest pays you directly and you confirm receipt yourself. You are responsible for the tax on your own income.",
            "Bookings outside the platform. A guest you first met through StayExplore Kenya is a platform booking. Taking such a booking off the platform to avoid commission breaks this agreement.",
            "Accuracy. Your listing, photos, price and availability must be true and kept up to date, and you will honour confirmed bookings.",
            $block ? "Overdue commission. $block" : "",
            "Changes. If the commission rate or payment method changes, you will be told and the change applies to new bookings only. You may then accept the updated agreement or stop listing.",
        ]));
        foreach ($clauses as $i => &$c) $c = ($i + 1) . '. ' . $c;
        unset($c);
        return implode("\n\n", $clauses);
    }
}
if (!function_exists('hostAgreementAccepted')) {
    function hostAgreementAccepted($pdo, $hostId) {
        ensureCommissionTables($pdo);
        $q = $pdo->prepare('SELECT 1 FROM host_agreements WHERE host_id = ? AND version = ? LIMIT 1'); $q->execute([(int)$hostId, HOST_AGREEMENT_VERSION]);
        return (bool)$q->fetchColumn();
    }
}
if (!function_exists('hostAgreementRecord')) {
    function hostAgreementRecord($pdo, $hostId) {
        ensureCommissionTables($pdo);
        $q = $pdo->prepare('SELECT * FROM host_agreements WHERE host_id = ? ORDER BY agreement_id DESC LIMIT 1'); $q->execute([(int)$hostId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
