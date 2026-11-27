<?php
/**
 * v9.33 LAUNCH MODEL (read this first):
 *   - The guest pays the HOST DIRECTLY, and only AFTER the host has accepted the booking request.
 *   - The HOST pays StayExplore Kenya its commission separately, by invoice, after an eligible completed stay.
 *   - The guest is never charged, shown or told about the host's commission. The platform never holds accommodation money.
 *   The older 'upfront' arrangement below is kept ONLY so bookings made before v9.33 keep their original financial terms.
 *
 * Money flow, receipts and payment evidence (v9.21, legacy description).
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
if (!defined('COMMISSION_PAY_INSTRUCTIONS')) define('COMMISSION_PAY_INSTRUCTIONS', 'Pay StayExplore Kenya by M-Pesa or bank transfer to the account given by the admin, then report the payment reference below.');
if (!defined('RECEIPT_SECRET')) define('RECEIPT_SECRET', 'change-this-secret-in-config-php');

if (!function_exists('commissionPayInstructions')) { function commissionPayInstructions() { return siteSettingGet('platform_pay_instructions', COMMISSION_PAY_INSTRUCTIONS); } }
if (!function_exists('kes')) { function kes($n) { return 'KES ' . number_format((float)$n, 2); } }

if (!function_exists('ensureCommissionTables')) {
    function ensureCommissionTables($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        $marker = dirname(__DIR__) . '/logs/.schema_v933';          // written after the one-time upgrade, so normal page loads skip all schema checks
        if (is_file($marker)) return;
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
            if (!$pdo->query("SHOW COLUMNS FROM bookings LIKE 'commission_mode'")->fetch()) $pdo->exec("ALTER TABLE bookings ADD COLUMN commission_mode VARCHAR(10) NOT NULL DEFAULT 'invoice'");
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
            runSchemaUpgradeV933($pdo);
            if (!is_dir(dirname($marker))) @mkdir(dirname($marker), 0775, true);
            @file_put_contents($marker, '9.33 ' . date('c'));
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
// v9.33: NEW bookings always use the host-paid invoice model. Older bookings keep the mode stored on their own row (bookings.commission_mode).
if (!function_exists('commissionMode'))     { function commissionMode()     { return 'invoice'; } }
if (!function_exists('paymentDeadlineHours')) { function paymentDeadlineHours() { $h = (int)siteSettingGet('payment_deadline_hours', 24); return in_array($h, [12, 24, 48], true) ? $h : 24; } }
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


// ===================== v9.33: one-time, non-destructive schema upgrade =====================
if (!function_exists('runSchemaUpgradeV933')) {
    function runSchemaUpgradeV933($pdo) {
        $hasCol = function ($t, $c) use ($pdo) { return (bool)$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch(); };
        $hasIdx = function ($t, $i) use ($pdo) { return (bool)$pdo->query("SHOW INDEX FROM `$t` WHERE Key_name = '$i'")->fetch(); };
        $addIdx = function ($t, $i, $cols) use ($pdo, $hasIdx) { try { if (!$hasIdx($t, $i)) $pdo->exec("ALTER TABLE `$t` ADD INDEX `$i` ($cols)"); } catch (Throwable $e) { error_log("index $i: " . $e->getMessage()); } };

        // 1. Booking statuses: add 'accepted' and 'payment_pending'. Existing values are untouched.
        $type = (string)($pdo->query("SHOW COLUMNS FROM bookings LIKE 'status'")->fetch()['Type'] ?? '');
        if ($type !== '' && stripos($type, 'payment_pending') === false) {
            $pdo->exec("ALTER TABLE bookings MODIFY status ENUM('pending','accepted','payment_pending','confirmed','cancelled','completed','no_show') NOT NULL DEFAULT 'pending'");
            // Old flow: guest sent payment details while the request was still 'pending'. Those are now 'payment_pending' (awaiting the host's check).
            $pdo->exec("UPDATE bookings b SET b.status = 'payment_pending' WHERE b.status = 'pending' AND EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.booking_id)");
        }
        foreach (['accepted_at' => 'DATETIME NULL DEFAULT NULL', 'payment_due_at' => 'DATETIME NULL DEFAULT NULL', 'payment_reminder_sent' => 'TINYINT(1) NOT NULL DEFAULT 0'] as $c => $d) {
            if (!$hasCol('bookings', $c)) $pdo->exec("ALTER TABLE bookings ADD COLUMN `$c` $d");
        }
        // New records default to the host-paid model. Rows that already exist keep the value they were created with.
        $pdo->exec("ALTER TABLE bookings ALTER COLUMN commission_mode SET DEFAULT 'invoice'");

        if (!$hasCol('users', 'payment_instructions')) $pdo->exec("ALTER TABLE users ADD COLUMN payment_instructions VARCHAR(600) NULL");

        // 2. Host commission invoices (one per eligible booking)
        $pdo->exec("CREATE TABLE IF NOT EXISTS commission_invoices (
            invoice_id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_no VARCHAR(40) NOT NULL,
            host_id INT NOT NULL,
            booking_id INT NOT NULL,
            base_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            rate DECIMAL(5,2) NULL,
            amount DECIMAL(10,2) NOT NULL,
            paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM('unpaid','partial','paid','void') NOT NULL DEFAULT 'unpaid',
            issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            due_date DATE NOT NULL,
            overdue_notified TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uq_invoice_no (invoice_no), UNIQUE KEY uq_invoice_booking (booking_id),
            KEY idx_host_status (host_id, status), KEY idx_due (status, due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 3. Launch settings, applied once: invoice model, no guest fee, 24 hour payment deadline.
        $done = $pdo->query("SELECT 1 FROM site_settings WHERE setting_key = 'schema_version' AND setting_value = '9.33'")->fetchColumn();
        if (!$done) {
            $set = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $set->execute(['commission_mode', 'invoice']);
            $set->execute(['service_fee_percent', '0']);
            $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('payment_deadline_hours', '24')")->execute();
            $set->execute(['schema_version', '9.33']);
        }

        // 3b. Make sure every commission payment is attributable to specific invoices.
        if (!$hasCol('host_commission_payments', 'proof_note')) $pdo->exec("ALTER TABLE host_commission_payments ADD COLUMN proof_note VARCHAR(300) NULL");

        // 3c. Create every supporting table once, here, so normal page loads (and open transactions) never run DDL.
        $dir = __DIR__;
        foreach (['notifications', 'booking_events', 'payment_audit', 'alerts', 'currency', 'login_throttle', 'gallery', 'certificates', 'attraction_reviews', 'attraction_content', 'commons_photos', 'verify_wait', 'review_extras', 'profile_photo', 'onsite_experience', 'media_store'] as $inc) {
            if (is_file("$dir/$inc.php")) { try { require_once "$dir/$inc.php"; } catch (Throwable $e) { error_log("include $inc: " . $e->getMessage()); } }
        }
        foreach (['ensureNotificationsTable', 'ensureBookingEventsTable', 'ensurePaymentAuditTable', 'ensureAlertTables', 'ensureCurrenciesTable', 'ensureLoginAttemptsTable', 'ensureAttractionPhotosTable', 'ensureCertificatesTable', 'ensureAttractionReviewsTable', 'ensureAttractionExtras', 'ensureAttractionPhotoCreditsTable', 'ensureVerifyWaitsTable', 'ensureVerifyCodesTable', 'ensureReviewExtraColumns', 'ensureProfilePhotoColumns', 'ensureOnsiteExperienceColumns', 'ensureMediaTables'] as $fn) {
            if (function_exists($fn)) { try { $fn($pdo); } catch (Throwable $e) { error_log("$fn: " . $e->getMessage()); } }
        }
        if (function_exists('ensureBookingRefColumn')) { try { ensureBookingRefColumn($pdo); } catch (Throwable $e) {} }

        // 4. Indexes for the queries that run most often (each checked first, none is destructive).
        $addIdx('bookings', 'idx_status_created', 'status, created_at');
        $addIdx('bookings', 'idx_tourist_status', 'tourist_id, status');
        $addIdx('bookings', 'idx_status_due', 'status, payment_due_at');
        $addIdx('bookings', 'idx_status_checkout', 'status, check_out_date');
        $addIdx('payments', 'idx_payment_status', 'payment_status');
        $addIdx('reviews', 'idx_acc_published', 'accommodation_id, is_published');
        $addIdx('accommodations', 'idx_status_created', 'status, created_at');
        $addIdx('accommodations', 'idx_status_geo', 'status, latitude, longitude');
        $addIdx('accommodations', 'idx_status_type_price', 'status, property_type, price_per_night');
        $addIdx('accommodation_photos', 'idx_acc_primary', 'accommodation_id, is_primary, photo_id');
        $addIdx('amenities', 'idx_acc_amenity', 'accommodation_id, amenity_name');
        $addIdx('availability', 'idx_acc_date', 'accommodation_id, is_available, `date`');
        $addIdx('host_commission_payments', 'idx_host_status', 'host_id, status');
        $addIdx('platform_payments', 'idx_pp_status', 'status');
    }
}

// ===================== v9.38.1: tell the host when a commission invoice is issued =====================
if (!function_exists('notifyNewCommissionInvoices')) {
    /**
     * Invoices were created silently, so a host first heard about one when a reminder arrived. Each new invoice is now announced once
     * (one notification per host per run, listing how many and the total). The first run only marks the invoices that already exist as
     * announced, so nobody receives a flood about old invoices. Run by the maintenance job; safe to repeat.
     */
    function notifyNewCommissionInvoices($pdo) {
        $sent = 0;
        try {
            if (!$pdo->query("SHOW COLUMNS FROM commission_invoices LIKE 'notified_at'")->fetch()) {
                $pdo->exec("ALTER TABLE commission_invoices ADD COLUMN notified_at DATETIME NULL DEFAULT NULL");
                $pdo->exec("UPDATE commission_invoices SET notified_at = NOW()");
                return 0;
            }
            require_once __DIR__ . '/notifications.php';
            $rows = $pdo->query("SELECT invoice_id, host_id, invoice_no, amount, due_date FROM commission_invoices WHERE notified_at IS NULL AND status <> 'void' ORDER BY invoice_id LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
            $byHost = [];
            foreach ($rows as $r) $byHost[(int)$r['host_id']][] = $r;
            foreach ($byHost as $hid => $list) {
                $total = array_sum(array_map(fn($x) => (float)$x['amount'], $list));
                $due = min(array_map(fn($x) => $x['due_date'], $list));
                $n = count($list);
                notify($pdo, $hid, $n === 1 ? 'New commission invoice' : $n . ' new commission invoices',
                    ($n === 1 ? 'Invoice ' . $list[0]['invoice_no'] : $n . ' invoices') . ' for KES ' . number_format($total, 2) . ' ' . ($n === 1 ? 'has' : 'have') . ' been issued, due ' . date('j M Y', strtotime($due)) . '. You can see and pay it under Commission.',
                    'host/commission.php');
                $ids = array_map(fn($x) => (int)$x['invoice_id'], $list);
                $pdo->exec('UPDATE commission_invoices SET notified_at = NOW() WHERE invoice_id IN (' . implode(',', $ids) . ')');
                $sent++;
            }
        } catch (Throwable $e) { error_log('notifyNewCommissionInvoices: ' . $e->getMessage()); }
        return $sent;
    }
}

// ===================== v9.33: commission invoiced to the host after an eligible completed stay =====================
if (!function_exists('issueCommissionInvoices')) {
    /**
     * Creates an invoice for every booking whose commission has become payable, once. A booking is eligible only when:
     *   - it was made under the host-paid invoice model and carries a commission amount,
     *   - the HOST confirmed receiving the guest's accommodation payment (booking is confirmed or completed).
     * (Changed in v9.34: the commission is worked out the moment the host marks the payment received, instead of after the stay.
     *  It is still DUE N days after check-out, and the invoice is voided if the booking is later cancelled or the payment reversed.)
     * Rejected, unpaid, cancelled and no-show bookings never reach this point. Safe to run repeatedly.
     */
    function issueCommissionInvoices($pdo, $hostId = null) {
        ensureCommissionTables($pdo);
        $days = (int)invoiceDueDays();
        try {
            $sql = "INSERT IGNORE INTO commission_invoices (invoice_no, host_id, booking_id, base_amount, rate, amount, issued_at, due_date)
                SELECT CONCAT('TMP', REPLACE(UUID(), '-', '')), a.host_id, b.booking_id,
                       CASE WHEN b.commission_rate > 0 THEN ROUND(b.commission_amount * 100 / b.commission_rate, 2) ELSE 0 END,
                       b.commission_rate, b.commission_amount, NOW(), DATE_ADD(b.check_out_date, INTERVAL $days DAY)
                FROM bookings b
                JOIN accommodations a ON a.accommodation_id = b.accommodation_id
                JOIN payments p ON p.booking_id = b.booking_id AND p.payment_status = 'confirmed'
                WHERE b.status IN ('confirmed','completed') AND b.commission_mode = 'invoice' AND b.commission_amount > 0
                  AND NOT EXISTS (SELECT 1 FROM commission_invoices ci WHERE ci.booking_id = b.booking_id)"
                . ($hostId ? " AND a.host_id = " . (int)$hostId : '');
            $n = $pdo->exec($sql);
            if ($n) $pdo->exec("UPDATE commission_invoices SET invoice_no = CONCAT('SEK-', DATE_FORMAT(issued_at, '%Y'), '-', LPAD(invoice_id, 5, '0')) WHERE invoice_no LIKE 'TMP%'");
            return (int)$n;
        } catch (Throwable $e) { error_log('issueCommissionInvoices: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('syncBookingCommissionInvoice')) {
    /**
     * Keeps one booking's commission invoice in step with its status (called from transitionBooking, inside its transaction).
     *   confirmed / completed  -> invoice exists (created now, or a voided one is reinstated)
     *   cancelled / no_show / payment_pending (payment reversed) -> an invoice with nothing paid on it is voided
     * Never throws; commission problems must not block a booking change.
     */
    function syncBookingCommissionInvoice($pdo, $bookingId, $newStatus) {
        try {
            $bookingId = (int)$bookingId;
            if (in_array($newStatus, ['confirmed', 'completed'], true)) {
                $v = $pdo->prepare("UPDATE commission_invoices SET status = 'unpaid' WHERE booking_id = ? AND status = 'void'");
                $v->execute([$bookingId]);
                if ($v->rowCount()) return;
                $days = (int)invoiceDueDays();
                $pdo->prepare("INSERT IGNORE INTO commission_invoices (invoice_no, host_id, booking_id, base_amount, rate, amount, issued_at, due_date)
                    SELECT CONCAT('TMP', REPLACE(UUID(), '-', '')), a.host_id, b.booking_id,
                           CASE WHEN b.commission_rate > 0 THEN ROUND(b.commission_amount * 100 / b.commission_rate, 2) ELSE 0 END,
                           b.commission_rate, b.commission_amount, NOW(), DATE_ADD(b.check_out_date, INTERVAL $days DAY)
                    FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id
                    WHERE b.booking_id = ? AND b.commission_mode = 'invoice' AND b.commission_amount > 0")->execute([$bookingId]);
                $pdo->exec("UPDATE commission_invoices SET invoice_no = CONCAT('SEK-', DATE_FORMAT(issued_at, '%Y'), '-', LPAD(invoice_id, 5, '0')) WHERE invoice_no LIKE 'TMP%'");
            } elseif (in_array($newStatus, ['cancelled', 'no_show', 'payment_pending', 'accepted'], true)) {
                $pdo->prepare("UPDATE commission_invoices SET status = 'void' WHERE booking_id = ? AND paid_amount <= 0 AND status IN ('unpaid','partial')")->execute([$bookingId]);
            }
        } catch (Throwable $e) { error_log('syncBookingCommissionInvoice: ' . $e->getMessage()); }
    }
}
if (!function_exists('cronIsFresh')) {
    /** True while the scheduled job (cron/maintenance.php) is running, so pages need not do its work themselves. */
    function cronIsFresh() { $hb = dirname(__DIR__) . '/logs/.maintenance_heartbeat'; return is_file($hb) && (time() - (int)@filemtime($hb)) < 900; }
}
if (!function_exists('hostCommissionLedger')) {
    /**
     * What one host owes, from invoices. Confirmed payments from the host are applied to the oldest invoices first;
     * payments still waiting for the admin's check are shown but do not reduce the balance yet.
     * Returns ['due','paid','pending','outstanding','overdue','items'=>[...]] ('due' = total ever invoiced).
     */
    function hostCommissionLedger($pdo, $hostId) {
        ensureCommissionTables($pdo);
        if (!cronIsFresh()) issueCommissionInvoices($pdo, (int)$hostId);   // with cron running, invoices are issued there
        $q = $pdo->prepare("SELECT ci.*, b.check_in_date, b.check_out_date, a.title
            FROM commission_invoices ci JOIN bookings b ON b.booking_id = ci.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id
            WHERE ci.host_id = ? AND ci.status <> 'void' ORDER BY ci.due_date ASC, ci.invoice_id ASC");
        $q->execute([(int)$hostId]); $items = $q->fetchAll(PDO::FETCH_ASSOC);
        $p = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status='confirmed' THEN amount END),0) AS paid, COALESCE(SUM(CASE WHEN status='pending' THEN amount END),0) AS pending FROM host_commission_payments WHERE host_id = ?");
        $p->execute([(int)$hostId]); $pay = $p->fetch(PDO::FETCH_ASSOC);
        $credit = (float)$pay['paid']; $due = 0.0; $out = 0.0; $overdue = 0.0; $today = date('Y-m-d');
        foreach ($items as &$it) {
            $amt = (float)$it['amount']; $due += $amt;
            $applied = min($credit, $amt); $credit -= $applied;
            $it['commission_amount'] = $amt; $it['commission_rate'] = $it['rate'];
            $it['paid_applied'] = $applied; $it['unpaid'] = $amt - $applied;
            $it['overdue'] = $it['unpaid'] > 0.004 && $it['due_date'] < $today;
            $it['state'] = $it['unpaid'] <= 0.004 ? 'paid' : ($applied > 0 ? 'partial' : 'unpaid');
            $out += $it['unpaid']; if ($it['overdue']) $overdue += $it['unpaid'];
        }
        unset($it);
        return ['due' => $due, 'paid' => (float)$pay['paid'], 'pending' => (float)$pay['pending'], 'outstanding' => $out, 'overdue' => $overdue, 'credit' => max(0.0, round($credit, 2)), 'items' => $items];   // credit = confirmed money not matched to any live invoice (overpayment, or the invoice was voided)
    }
}
if (!function_exists('syncCommissionInvoiceStatuses')) {
    /** Writes the allocation computed by hostCommissionLedger() back onto the invoices (called after the admin decides on a payment). */
    function syncCommissionInvoiceStatuses($pdo, $hostId) {
        try {
            $led = hostCommissionLedger($pdo, $hostId);
            $u = $pdo->prepare("UPDATE commission_invoices SET paid_amount = ?, status = ? WHERE invoice_id = ? AND status <> 'void'");
            foreach ($led['items'] as $it) $u->execute([round($it['paid_applied'], 2), $it['state'], (int)$it['invoice_id']]);
        } catch (Throwable $e) { error_log('syncCommissionInvoiceStatuses: ' . $e->getMessage()); }
    }
}
if (!function_exists('hostCommissionSummary')) {
    /** The numbers shown on the host Commission page and the dashboard. */
    function hostCommissionSummary($pdo, $hostId) {
        $l = hostCommissionLedger($pdo, $hostId);
        $c = $pdo->prepare("SELECT COUNT(*) FROM host_commission_payments WHERE host_id = ? AND status = 'confirmed'"); $c->execute([(int)$hostId]);
        return ['earned' => $l['due'], 'outstanding' => $l['outstanding'], 'overdue' => $l['overdue'], 'pending_payments' => $l['pending'], 'confirmed_payments' => $l['paid'], 'confirmed_count' => (int)$c->fetchColumn(), 'ledger' => $l];
    }
}
if (!function_exists('commissionPlatformSummary')) {
    /**
     * Totals across all hosts for the admin dashboard.
     * v9.38.1: outstanding, overdue and credit are the SUM of each host ledger (hostCommissionLedger), so the admin figures reconcile
     * exactly with what every host sees. (Before, they were worked out from different columns and could disagree.)
     */
    function commissionPlatformSummary($pdo) {
        ensureCommissionTables($pdo); issueCommissionInvoices($pdo);
        $inv = $pdo->query("SELECT COALESCE(SUM(amount),0) AS generated, COUNT(*) AS n FROM commission_invoices WHERE status <> 'void'")->fetch(PDO::FETCH_ASSOC);
        $pay = $pdo->query("SELECT COALESCE(SUM(CASE WHEN status='confirmed' THEN amount END),0) AS confirmed,
                COALESCE(SUM(CASE WHEN status='pending' THEN amount END),0) AS awaiting, COALESCE(SUM(CASE WHEN status='pending' THEN 1 END),0) AS awaiting_n
            FROM host_commission_payments")->fetch(PDO::FETCH_ASSOC);
        $out = 0.0; $overdueAmt = 0.0; $overdueN = 0; $credit = 0.0;
        $hosts = $pdo->query("SELECT host_id FROM commission_invoices UNION SELECT host_id FROM host_commission_payments")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($hosts as $hid) {
            try {
                $l = hostCommissionLedger($pdo, (int)$hid);
                $out += $l['outstanding']; $overdueAmt += $l['overdue']; $credit += $l['credit'];
                foreach ($l['items'] as $it) if (!empty($it['overdue'])) $overdueN++;
            } catch (Throwable $e) { error_log('commissionPlatformSummary host ' . $hid . ': ' . $e->getMessage()); }
        }
        return ['generated' => (float)$inv['generated'], 'invoices' => (int)$inv['n'], 'confirmed' => (float)$pay['confirmed'], 'awaiting' => (float)$pay['awaiting'], 'awaiting_n' => (int)$pay['awaiting_n'],
                'outstanding' => $out, 'overdue_amount' => $overdueAmt, 'overdue_n' => $overdueN, 'credit' => round($credit, 2)];
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
if (!defined('HOST_AGREEMENT_VERSION')) define('HOST_AGREEMENT_VERSION', '2');
if (!function_exists('hostAgreementText')) {
    /** The terms as they stand today, in plain words. A copy is stored with each acceptance, so the host's record shows exactly what was agreed. */
    function hostAgreementText() {
        $rate = pct(commissionRatePercent()); $base = commissionBase() === 'stay_only' ? 'the accommodation (stay) price only' : 'your price for the stay plus any extras you charge';
        $block = blockOverdueHosts() ? "If commission stays unpaid after its due date, your listings may be paused for new bookings until it is paid." : "";
        $clauses = array_values(array_filter([
            "Guests pay you directly. A guest pays you for the accommodation only after you have accepted their booking request. StayExplore Kenya does not hold or handle that money. You confirm that you received it, and you are responsible for the tax on your own income.",
            "Your commission. Your agreed platform commission is payable separately to StayExplore Kenya after eligible completed stays. It is {$rate}% of {$base}. The rate in force when a booking is made is saved on that booking and does not change afterwards.",
            "When commission becomes payable. Commission is charged only on a stay that was completed and whose accommodation payment you confirmed as received. No commission is charged on rejected, unpaid, cancelled or no-show bookings.",
            "How you pay it. Commission is invoiced to you, and each invoice is due " . invoiceDueDays() . " days after the guest's check-out. You pay by M-Pesa or bank transfer, report the payment and its reference on the Commission page, and StayExplore Kenya confirms it.",
            "Guests are not involved. Your commission is a private matter between you and StayExplore Kenya. Do not ask guests to pay it or to pay any part of it.",
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