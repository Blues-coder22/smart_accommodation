<?php
/**
 * Refunds for cancelled bookings, with evidence, in one place.
 *
 *  Guest  : asks for a refund on a cancelled booking they paid for (reason + screenshot of what they paid), can ask the admin to step in.
 *  Host   : records that they refunded the guest (screenshot of the transfer) or declines with a reason.
 *  Admin  : sees every case, and can record a refund or close a case, with evidence and a note.
 *
 * StayExplore does not move the host's money: a "refund" here is the RECORD of a refund, with the proof attached.
 * Evidence files are private (private_uploads/refund_evidence) and are only served by refund_proof.php to the guest, the host and admins.
 * Every step is also written to the finance audit trail. One case per booking.
 */
require_once __DIR__ . '/opaque_id.php';
require_once __DIR__ . '/booking_ref.php';
require_once __DIR__ . '/finance_audit.php';
require_once __DIR__ . '/notifications.php';

if (!function_exists('ensureRefundRequestTables')) {
    function ensureRefundRequestTables($pdo) {
        static $done = false; if ($done) return; $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS refund_requests (
                request_id INT AUTO_INCREMENT PRIMARY KEY,
                booking_id INT NOT NULL,
                tourist_id INT NOT NULL,
                host_id INT NOT NULL,
                amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
                amount_requested DECIMAL(10,2) NOT NULL DEFAULT 0,
                reason VARCHAR(500) NULL,
                evidence_file VARCHAR(80) NULL,
                status ENUM('requested','refunded','declined','escalated','closed') NOT NULL DEFAULT 'requested',
                host_response VARCHAR(300) NULL,
                escalation_note VARCHAR(500) NULL,
                refund_amount DECIMAL(10,2) NULL,
                refund_method VARCHAR(20) NULL,
                refund_reference VARCHAR(120) NULL,
                refund_note VARCHAR(300) NULL,
                refund_evidence VARCHAR(80) NULL,
                refunded_by VARCHAR(10) NULL,
                refunded_at DATETIME NULL,
                admin_note VARCHAR(300) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_refund_booking (booking_id), KEY idx_rr_host (host_id), KEY idx_rr_tourist (tourist_id), KEY idx_rr_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { error_log('ensureRefundRequestTables: ' . $e->getMessage()); }
    }
}

if (!function_exists('refundEvidenceDir')) {
    function refundEvidenceDir() {
        $d = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private_uploads' . DIRECTORY_SEPARATOR . 'refund_evidence';
        if (!is_dir($d)) @mkdir($d, 0755, true);
        return $d;
    }
}

if (!function_exists('refundSaveEvidence')) {
    /** $file is one $_FILES entry. Returns ['ok'=>bool,'name'=>?string,'error'=>string]. No file chosen -> ok with name null. */
    function refundSaveEvidence($file) {
        if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return ['ok' => true, 'name' => null, 'error' => ''];
        if ($file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'name' => null, 'error' => 'The evidence file could not be uploaded. Try a smaller picture.'];
        if ($file['size'] > 5 * 1024 * 1024) return ['ok' => false, 'name' => null, 'error' => 'The evidence picture is too big (5 MB at most).'];
        if (!is_uploaded_file($file['tmp_name'])) return ['ok' => false, 'name' => null, 'error' => 'The evidence file could not be uploaded.'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext) return ['ok' => false, 'name' => null, 'error' => 'Evidence must be a JPG, PNG or WebP picture (a screenshot or a photo of the receipt).'];
        $name = 'rf_' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!@move_uploaded_file($file['tmp_name'], refundEvidenceDir() . DIRECTORY_SEPARATOR . $name)) return ['ok' => false, 'name' => null, 'error' => 'The evidence file could not be saved.'];
        return ['ok' => true, 'name' => $name, 'error' => ''];
    }
}

if (!function_exists('refundPaidAmount')) {
    /** What the guest paid the host for this booking: a confirmed payment, or one the guest reported as paid. */
    function refundPaidAmount($pdo, $bookingId) {
        $q = $pdo->prepare("SELECT amount FROM payments WHERE booking_id = ? AND payment_status IN ('confirmed','pending') ORDER BY (payment_status = 'confirmed') DESC, payment_id DESC LIMIT 1");
        $q->execute([(int)$bookingId]);
        return (float)($q->fetchColumn() ?: 0);
    }
}

if (!function_exists('refundGetCase')) {
    function refundGetCase($pdo, $bookingId) {
        ensureRefundRequestTables($pdo);
        $q = $pdo->prepare('SELECT * FROM refund_requests WHERE booking_id = ?'); $q->execute([(int)$bookingId]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('refundMethodLabel')) {
    function refundMethodLabel($m) { return ['mpesa' => 'M-Pesa', 'bank_transfer' => 'bank transfer', 'cash' => 'cash'][$m] ?? (string)$m; }
}
if (!function_exists('refundStatusLabel')) {
    function refundStatusLabel($s) { return ['requested' => 'Waiting for the host', 'refunded' => 'Refunded', 'declined' => 'Declined by the host', 'escalated' => 'With the admin', 'closed' => 'Closed by the admin'][$s] ?? (string)$s; }
}

if (!function_exists('refundCreateRequest')) {
    /** The guest asks for a refund. Returns ['ok'=>bool,'error'=>string]. */
    function refundCreateRequest($pdo, $bookingId, $touristId, $amount, $reason, $evidenceName) {
        ensureRefundRequestTables($pdo);
        $amount = round((float)$amount, 2); $reason = mb_substr(trim((string)$reason), 0, 500);
        if (mb_strlen($reason) < 5) return ['ok' => false, 'error' => 'Tell the host briefly why you are asking for a refund (at least 5 characters).'];
        if (!$evidenceName) return ['ok' => false, 'error' => 'Attach evidence of your payment (a screenshot of the M-Pesa message or bank receipt).'];
        $q = $pdo->prepare('SELECT b.status, a.host_id, a.title FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE b.booking_id = ? AND b.tourist_id = ?');
        $q->execute([(int)$bookingId, (int)$touristId]); $b = $q->fetch(PDO::FETCH_ASSOC);
        if (!$b) return ['ok' => false, 'error' => 'Booking not found.'];
        if ($b['status'] !== 'cancelled') return ['ok' => false, 'error' => 'You can ask for a refund once the booking is cancelled.'];
        $paid = refundPaidAmount($pdo, $bookingId);
        if ($paid <= 0) return ['ok' => false, 'error' => 'There is no payment on this booking to refund.'];
        if ($amount <= 0 || $amount > $paid + 0.001) return ['ok' => false, 'error' => 'The amount must be more than 0 and no more than the KES ' . number_format($paid, 2) . ' you paid.'];
        if (refundGetCase($pdo, $bookingId)) return ['ok' => false, 'error' => 'A refund request already exists for this booking.'];
        try {
            $pdo->prepare('INSERT INTO refund_requests (booking_id, tourist_id, host_id, amount_paid, amount_requested, reason, evidence_file) VALUES (?,?,?,?,?,?,?)')
                ->execute([(int)$bookingId, (int)$touristId, (int)$b['host_id'], $paid, $amount, $reason, $evidenceName]);
            $rid = (int)$pdo->lastInsertId();
            logFinanceEvent($pdo, 'refund_request', $rid, (int)$b['host_id'], (int)$touristId, 'tourist', 'requested', null, 'requested', $amount, bookingLabel($bookingId) . ' | ' . $reason);
        } catch (Throwable $e) { error_log('refundCreateRequest: ' . $e->getMessage()); return ['ok' => false, 'error' => 'Could not save your request. Please try again.']; }
        $label = bookingLabel($bookingId);
        notify($pdo, (int)$b['host_id'], 'Refund requested', 'The guest of ' . $label . ' asks for a refund of KES ' . number_format($amount, 2) . '. Open the booking to see their evidence and reply.', 'host/bookings.php');
        notifyAdmins($pdo, 'Refund requested', $label . ': the guest asks the host for KES ' . number_format($amount, 2) . '.', 'admin/refunds.php');
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('refundRecordDone')) {
    /**
     * Writes the refund (made by the host, or recorded by an admin) onto the case, creating the case when the guest never asked.
     * $by is 'host' or 'admin'. Does its own notifications. Returns ['ok'=>bool,'error'=>string].
     */
    function refundRecordDone($pdo, $bookingId, $by, $actorId, $amount, $method, $reference, $note, $evidenceName) {
        ensureRefundRequestTables($pdo);
        $amount = round((float)$amount, 2); $reference = mb_substr(trim((string)$reference), 0, 120); $note = mb_substr(trim((string)$note), 0, 300);
        if (!in_array($method, ['mpesa', 'bank_transfer', 'cash'], true)) return ['ok' => false, 'error' => 'Choose how the refund was sent.'];
        if ($method !== 'cash' && mb_strlen($reference) < 4) return ['ok' => false, 'error' => 'Enter the transaction reference of the refund (at least 4 characters).'];
        if ($method !== 'cash' && !$evidenceName) return ['ok' => false, 'error' => 'Attach evidence of the refund (a screenshot of the M-Pesa message or bank receipt).'];
        $q = $pdo->prepare('SELECT b.status, b.tourist_id, a.host_id FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE b.booking_id = ?');
        $q->execute([(int)$bookingId]); $b = $q->fetch(PDO::FETCH_ASSOC);
        if (!$b || ($by === 'host' && (int)$b['host_id'] !== (int)$actorId)) return ['ok' => false, 'error' => 'Booking not found.'];
        if ($b['status'] !== 'cancelled') return ['ok' => false, 'error' => 'A refund can only be recorded for a cancelled booking.'];
        $paid = refundPaidAmount($pdo, $bookingId);
        if ($paid <= 0) return ['ok' => false, 'error' => 'No payment was made on this booking, so there is nothing to refund.'];
        if ($amount <= 0 || $amount > $paid + 0.001) return ['ok' => false, 'error' => 'The refund must be more than 0 and no more than KES ' . number_format($paid, 2) . '.'];
        $case = refundGetCase($pdo, $bookingId);
        if ($case && $case['status'] === 'refunded') return ['ok' => false, 'error' => 'A refund is already recorded for this booking.'];
        try {
            $pdo->beginTransaction();
            if ($case) {
                $pdo->prepare("UPDATE refund_requests SET status='refunded', refund_amount=?, refund_method=?, refund_reference=?, refund_note=?, refund_evidence=?, refunded_by=?, refunded_at=NOW() WHERE request_id=?")
                    ->execute([$amount, $method, $reference !== '' ? $reference : null, $note !== '' ? $note : null, $evidenceName, $by, (int)$case['request_id']]);
                $rid = (int)$case['request_id']; $old = $case['status'];
            } else {
                $pdo->prepare("INSERT INTO refund_requests (booking_id, tourist_id, host_id, amount_paid, amount_requested, reason, status, refund_amount, refund_method, refund_reference, refund_note, refund_evidence, refunded_by, refunded_at)
                    VALUES (?,?,?,?,?,?, 'refunded', ?,?,?,?,?,?, NOW())")
                    ->execute([(int)$bookingId, (int)$b['tourist_id'], (int)$b['host_id'], $paid, $amount, 'Recorded by the ' . $by . ' without a request from the guest', $amount, $method, $reference !== '' ? $reference : null, $note !== '' ? $note : null, $evidenceName, $by]);
                $rid = (int)$pdo->lastInsertId(); $old = null;
            }
            logFinanceEvent($pdo, 'refund_request', $rid, (int)$b['host_id'], (int)$actorId, $by, 'refund_recorded', $old, 'refunded', $amount, bookingLabel($bookingId) . ' | ' . $method . ($reference !== '' ? ' ref ' . $reference : '') . ($evidenceName ? ' | evidence attached' : '') . ($note !== '' ? ' | ' . $note : ''));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('refundRecordDone: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not save the refund. Please try again.'];
        }
        $label = bookingLabel($bookingId); $who = $by === 'admin' ? 'StayExplore Kenya recorded that' : 'Your host says they';
        notify($pdo, (int)$b['tourist_id'], 'Refund sent', $who . ' refunded KES ' . number_format($amount, 2) . ' for ' . $label . ' by ' . refundMethodLabel($method) . ($reference !== '' ? ' (ref ' . $reference . ')' : '') . '. If you have not received it, open the booking and tell the admin.', 'tourist/bookings.php');
        if ($by === 'admin') notify($pdo, (int)$b['host_id'], 'Refund recorded by the admin', 'StayExplore Kenya recorded a refund of KES ' . number_format($amount, 2) . ' to the guest for ' . $label . '.', 'host/bookings.php');
        else notifyAdmins($pdo, 'Host recorded a refund', $label . ': KES ' . number_format($amount, 2) . ' by ' . refundMethodLabel($method) . ($reference !== '' ? ', ref ' . $reference : '') . '.', 'admin/refunds.php');
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('refundHostDecline')) {
    function refundHostDecline($pdo, $bookingId, $hostId, $reason) {
        $case = refundGetCase($pdo, $bookingId); $reason = mb_substr(trim((string)$reason), 0, 300);
        if (!$case || (int)$case['host_id'] !== (int)$hostId) return ['ok' => false, 'error' => 'Refund request not found.'];
        if ($case['status'] !== 'requested') return ['ok' => false, 'error' => 'This request has already been answered.'];
        if (mb_strlen($reason) < 5) return ['ok' => false, 'error' => 'Give the guest a reason (at least 5 characters). The admin can see it too.'];
        $pdo->prepare("UPDATE refund_requests SET status='declined', host_response=? WHERE request_id=? AND status='requested'")->execute([$reason, (int)$case['request_id']]);
        logFinanceEvent($pdo, 'refund_request', (int)$case['request_id'], (int)$hostId, (int)$hostId, 'host', 'declined', 'requested', 'declined', $case['amount_requested'], bookingLabel($bookingId) . ' | ' . $reason);
        notify($pdo, (int)$case['tourist_id'], 'Refund request declined', 'Your host declined the refund request for ' . bookingLabel($bookingId) . ': ' . $reason . ' You can ask the admin to look at it.', 'tourist/bookings.php');
        notifyAdmins($pdo, 'Host declined a refund', bookingLabel($bookingId) . ': ' . $reason, 'admin/refunds.php');
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('refundGuestEscalate')) {
    /** The guest asks the admin to step in (host declined, no answer, or the refund never arrived). */
    function refundGuestEscalate($pdo, $bookingId, $touristId, $message) {
        $case = refundGetCase($pdo, $bookingId); $message = mb_substr(trim((string)$message), 0, 500);
        if (!$case || (int)$case['tourist_id'] !== (int)$touristId) return ['ok' => false, 'error' => 'Refund request not found.'];
        if (in_array($case['status'], ['escalated', 'closed'], true)) return ['ok' => false, 'error' => 'The admin already has this case.'];
        if (mb_strlen($message) < 5) return ['ok' => false, 'error' => 'Tell the admin briefly what is wrong (at least 5 characters).'];
        $pdo->prepare("UPDATE refund_requests SET status='escalated', escalation_note=? WHERE request_id=?")->execute([$message, (int)$case['request_id']]);
        logFinanceEvent($pdo, 'refund_request', (int)$case['request_id'], (int)$case['host_id'], (int)$touristId, 'tourist', 'escalated', $case['status'], 'escalated', $case['amount_requested'], bookingLabel($bookingId) . ' | ' . $message);
        notifyAdmins($pdo, 'Refund case needs you', bookingLabel($bookingId) . ': the guest asks the admin to step in. ' . $message, 'admin/refunds.php');
        notify($pdo, (int)$case['host_id'], 'Guest asked the admin about a refund', 'The guest of ' . bookingLabel($bookingId) . ' asked the admin to look at the refund.', 'host/bookings.php');
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('refundAdminClose')) {
    /** The admin closes a case without a refund (for example the guest is not owed one). A note is required and both sides read it. */
    function refundAdminClose($pdo, $requestId, $adminId, $note) {
        ensureRefundRequestTables($pdo);
        $q = $pdo->prepare('SELECT * FROM refund_requests WHERE request_id = ?'); $q->execute([(int)$requestId]); $c = $q->fetch(PDO::FETCH_ASSOC);
        $note = mb_substr(trim((string)$note), 0, 300);
        if (!$c) return ['ok' => false, 'error' => 'Case not found.'];
        if (in_array($c['status'], ['refunded', 'closed'], true)) return ['ok' => false, 'error' => 'This case is already finished.'];
        if (mb_strlen($note) < 5) return ['ok' => false, 'error' => 'Write the reason for closing (at least 5 characters). The guest and host will read it.'];
        $pdo->prepare("UPDATE refund_requests SET status='closed', admin_note=? WHERE request_id=?")->execute([$note, (int)$requestId]);
        logFinanceEvent($pdo, 'refund_request', (int)$requestId, (int)$c['host_id'], (int)$adminId, 'admin', 'closed', $c['status'], 'closed', $c['amount_requested'], bookingLabel($c['booking_id']) . ' | ' . $note);
        foreach ([(int)$c['tourist_id'], (int)$c['host_id']] as $uid) notify($pdo, $uid, 'Refund case closed', 'The admin closed the refund case for ' . bookingLabel($c['booking_id']) . ': ' . $note, $uid === (int)$c['host_id'] ? 'host/bookings.php' : 'tourist/bookings.php');
        return ['ok' => true, 'error' => ''];
    }
}
