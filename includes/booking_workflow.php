<?php
/**
 * Booking workflow (v9.33). The ONLY place that changes a booking's status.
 *
 *   pending          guest asked, waiting for the host. No payment is requested yet.
 *   accepted         host accepted. The guest may now pay the host directly, before payment_due_at.
 *   payment_pending  guest reported a payment; waiting for the host to confirm receiving it.
 *   confirmed        host confirmed receiving the money. Never reached by a guest's click alone.
 *   completed        stay finished. Commission can become payable (invoice model).
 *   cancelled        declined, cancelled, or expired. The dates are released.
 *   no_show          guest did not arrive.
 *
 * Every change is validated here, on the server: the transition must be allowed for the actor's role, the actor must own
 * the booking, the booking row is locked (SELECT ... FOR UPDATE) and the update is a compare-and-set on the old status,
 * so two simultaneous requests can never both succeed. Each change is written to booking_events.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/commission.php';
require_once __DIR__ . '/booking_events.php';
require_once __DIR__ . '/notifications.php';

if (!function_exists('bookingTransitionMatrix')) {
    /** from => [to => roles allowed to do it]. 'system' is the scheduled job. */
    function bookingTransitionMatrix() {
        return [
            'pending'         => ['accepted' => ['host', 'admin'], 'cancelled' => ['host', 'tourist', 'system', 'admin']],
            'accepted'        => ['payment_pending' => ['tourist'], 'confirmed' => ['host', 'admin'], 'cancelled' => ['host', 'tourist', 'system', 'admin']],
            'payment_pending' => ['confirmed' => ['host', 'admin'], 'accepted' => ['host', 'admin'], 'cancelled' => ['host', 'tourist', 'system', 'admin']],
            'confirmed'       => ['completed' => ['host', 'system', 'admin'], 'no_show' => ['host', 'admin'], 'cancelled' => ['host', 'tourist', 'admin'], 'payment_pending' => ['host', 'admin']],   // payment reversed by the host or admin
        ];
    }
}
if (!function_exists('bookingStatusLabel')) {
    /** Plain wording for a status, from the guest's or the host's point of view. */
    function bookingStatusLabel($status, $viewer = 'tourist') {
        $g = ['pending' => 'Waiting for host', 'accepted' => 'Accepted: pay your host', 'payment_pending' => 'Payment being checked', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'];
        $h = ['pending' => 'Needs your reply', 'accepted' => 'Accepted: awaiting guest payment', 'payment_pending' => 'Confirm payment received', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'];
        $m = $viewer === 'host' ? $h : $g;
        return $m[$status] ?? ucfirst(str_replace('_', ' ', (string)$status));
    }
}
if (!function_exists('bookingStatusClass')) {
    function bookingStatusClass($status) {
        return ['pending' => 's-pending', 'accepted' => 's-accepted', 'payment_pending' => 's-pending', 'confirmed' => 's-confirmed', 'completed' => 's-completed', 'cancelled' => 's-cancelled', 'no_show' => 's-cancelled'][$status] ?? 's-pending';
    }
}

if (!function_exists('transitionBooking')) {
    /**
     * @param string $role  'host' | 'tourist' | 'admin' | 'system'
     * @param array  $opts  event (string), note (string), expect (string|array, the status(es) the caller believes it is in)
     * @return array ['ok'=>bool, 'error'=>string, 'from'=>string, 'booking'=>array|null]
     */
    function transitionBooking($pdo, $bookingId, $to, $role, $actorId = null, array $opts = []) {
        ensureCommissionTables($pdo);
        $own = !$pdo->inTransaction();
        try {
            if ($own) $pdo->beginTransaction();
            $q = $pdo->prepare("SELECT b.*, a.host_id, a.title FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE b.booking_id = ? FOR UPDATE");
            $q->execute([(int)$bookingId]);
            $b = $q->fetch(PDO::FETCH_ASSOC);
            $fail = function ($msg) use ($pdo, $own, $b) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); return ['ok' => false, 'error' => $msg, 'from' => $b['status'] ?? '', 'booking' => $b ?: null]; };
            if (!$b) return $fail('Booking not found.');

            // 1. Who may touch this booking?
            if ($role === 'host' && (int)$b['host_id'] !== (int)$actorId) return $fail('This booking does not belong to you.');
            if ($role === 'tourist' && (int)$b['tourist_id'] !== (int)$actorId) return $fail('This booking does not belong to you.');
            if (!in_array($role, ['host', 'tourist', 'admin', 'system'], true)) return $fail('Not allowed.');

            // 2. Is the caller looking at the current state?
            $from = $b['status'];
            if (isset($opts['expect']) && !in_array($from, (array)$opts['expect'], true)) return $fail('This booking has changed. Please refresh and check its current status.');

            // 3. Is the move allowed, for this role?
            $allowed = bookingTransitionMatrix()[$from][$to] ?? null;
            if ($allowed === null || !in_array($role, $allowed, true)) return $fail('That change is not allowed for a booking that is ' . str_replace('_', ' ', $from) . '.');

            $set = ['status = ?']; $args = [$to];
            if ($to === 'accepted') {
                // Re-check the nights under a lock on the listing, so the host cannot accept dates that are no longer free.
                $pdo->prepare('SELECT accommodation_id FROM accommodations WHERE accommodation_id = ? FOR UPDATE')->execute([(int)$b['accommodation_id']]);
                if (isStayUnavailable($pdo, (int)$b['accommodation_id'], $b['check_in_date'], $b['check_out_date'], (int)$b['booking_id'])) return $fail('These dates are no longer free (another booking or a blocked night overlaps). Decline this request instead.');
                if ($b['check_in_date'] <= date('Y-m-d')) return $fail('The check-in date has already arrived, so this request can no longer be accepted.');
                $set[] = 'accepted_at = IF(accepted_at IS NULL, NOW(), accepted_at)'; $set[] = 'payment_due_at = DATE_ADD(NOW(), INTERVAL ' . (int)paymentDeadlineHours() . ' HOUR)'; $set[] = 'payment_reminder_sent = 0';
            }
            if ($to === 'confirmed') {
                // A booking is confirmed only when the HOST has marked the guest's payment as received.
                $pc = $pdo->prepare("SELECT 1 FROM payments WHERE booking_id = ? AND payment_status = 'confirmed'");
                $pc->execute([(int)$bookingId]);
                if (!$pc->fetchColumn()) return $fail('The payment has not been confirmed as received yet.');
                $set[] = 'payment_due_at = NULL';
            }
            if ($to === 'cancelled') $set[] = 'payment_due_at = NULL';

            $args[] = (int)$bookingId; $args[] = $from;
            $u = $pdo->prepare('UPDATE bookings SET ' . implode(', ', $set) . ' WHERE booking_id = ? AND status = ?');
            $u->execute($args);
            if ($u->rowCount() !== 1) return $fail('This booking was changed by someone else a moment ago. Please refresh.');

            logBookingEvent($pdo, (int)$bookingId, $actorId, $opts['event'] ?? ($from . '_to_' . $to), (string)($opts['note'] ?? ''));
            // v9.34: the host's commission is worked out as soon as the payment is confirmed, and withdrawn if the booking is cancelled.
            if (function_exists('syncBookingCommissionInvoice')) syncBookingCommissionInvoice($pdo, (int)$bookingId, $to);
            if ($own) $pdo->commit();
            $b['status'] = $to;
            return ['ok' => true, 'error' => '', 'from' => $from, 'booking' => $b];
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            error_log('transitionBooking: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Something went wrong. Please try again.', 'from' => '', 'booking' => null];
        }
    }
}

if (!function_exists('bookingStayFacts')) {
    /** Nights and accommodation total for notifications (never includes any commission information). */
    function bookingStayFacts($pdo, $bookingId) {
        $q = $pdo->prepare("SELECT b.booking_id, b.check_in_date, b.check_out_date, b.num_guests, b.total_amount, b.payment_due_at, a.title, a.location_name, a.price_per_night,
                t.user_id AS t_id, t.full_name AS t_name, t.email AS t_email, h.user_id AS h_id, h.full_name AS h_name, h.email AS h_email, h.phone_number AS h_phone
            FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id JOIN users t ON t.user_id = b.tourist_id JOIN users h ON h.user_id = a.host_id WHERE b.booking_id = ?");
        $q->execute([(int)$bookingId]); $r = $q->fetch(PDO::FETCH_ASSOC);
        if ($r) $r['nights'] = max(1, (int)(new DateTime($r['check_in_date']))->diff(new DateTime($r['check_out_date']))->days);
        return $r ?: null;
    }
}

if (!function_exists('hostAcceptBooking')) {
    function hostAcceptBooking($pdo, $bookingId, $hostId) {
        $r = transitionBooking($pdo, $bookingId, 'accepted', 'host', $hostId, ['event' => 'host_accepted', 'expect' => 'pending']);
        if (!$r['ok']) return $r;
        $f = bookingStayFacts($pdo, $bookingId); $label = bookingLabel($bookingId);
        $due = date('j M Y, H:i', strtotime($f['payment_due_at']));
        notify($pdo, (int)$f['t_id'], 'Host accepted your request', "$label is accepted. You can now pay your host: KES " . number_format($f['total_amount']) . ". Pay before $due or the dates are released.", 'tourist/bookings.php');
        try {
            require_once __DIR__ . '/email.php';
            $body = '<h2>Your host accepted your request</h2><p>Hello ' . h($f['t_name']) . ',</p><p>Good news: your host accepted booking <strong>' . h($label) . '</strong> for <strong>' . h($f['title']) . '</strong>.</p>'
                . '<table cellpadding="6" style="border-collapse:collapse"><tr><td>Dates</td><td>' . h($f['check_in_date']) . ' to ' . h($f['check_out_date']) . ' (' . (int)$f['nights'] . ' night' . ($f['nights'] == 1 ? '' : 's') . ')</td></tr>'
                . '<tr><td>Price per night</td><td>KES ' . number_format($f['price_per_night']) . '</td></tr><tr><td>Total to pay your host</td><td><strong>KES ' . number_format($f['total_amount']) . '</strong></td></tr>'
                . '<tr><td>Pay before</td><td><strong>' . h($due) . '</strong></td></tr></table>'
                . '<p>Open My bookings, choose how you will pay, and enter the details. Your host confirms when the money arrives; the booking is confirmed only then. If you do not pay in time the dates are released.</p>';
            sendEmail($f['t_email'], 'Your booking request was accepted — StayExplore Kenya', $body);
        } catch (Throwable $e) { error_log('accept email: ' . $e->getMessage()); }
        return $r;
    }
}
if (!function_exists('hostDeclineBooking')) {
    function hostDeclineBooking($pdo, $bookingId, $hostId, $note = '') {
        $r = transitionBooking($pdo, $bookingId, 'cancelled', 'host', $hostId, ['event' => 'host_declined', 'expect' => 'pending', 'note' => $note !== '' ? $note : 'Host declined the request']);
        if (!$r['ok']) return $r;
        $f = bookingStayFacts($pdo, $bookingId); $label = bookingLabel($bookingId);
        notify($pdo, (int)$f['t_id'], 'Booking request declined', "Your host could not accept $label. You have not been charged and the dates were released." . ($note !== '' ? ' Note: ' . $note : ''), 'tourist/bookings.php');
        try { require_once __DIR__ . '/email.php'; sendBookingReleasedEmail($f['t_email'], $f['t_name'], $f, $note); } catch (Throwable $e) {}
        return $r;
    }
}
if (!function_exists('hostConfirmPaymentReceived')) {
    /** The host says the guest's money arrived: the payment row is confirmed and the booking becomes 'confirmed', atomically. */
    function hostConfirmPaymentReceived($pdo, $paymentId, $hostId) {
        require_once __DIR__ . '/payment_audit.php';
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare("SELECT p.*, b.status AS b_status FROM payments p JOIN bookings b ON b.booking_id = p.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE p.payment_id = ? AND a.host_id = ? FOR UPDATE");
            $q->execute([(int)$paymentId, (int)$hostId]); $p = $q->fetch(PDO::FETCH_ASSOC);
            if (!$p) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Payment record not found for your listings.']; }
            if ($p['payment_status'] === 'confirmed' && in_array($p['b_status'], ['confirmed', 'completed'], true)) { $pdo->rollBack(); return ['ok' => true, 'error' => '', 'already' => true, 'booking_id' => (int)$p['booking_id']]; }
            if (!in_array($p['b_status'], ['accepted', 'payment_pending'], true)) { $pdo->rollBack(); return ['ok' => false, 'error' => 'This booking is ' . str_replace('_', ' ', $p['b_status']) . ', so its payment cannot be confirmed now.']; }
            $pdo->prepare("UPDATE payments SET payment_status = 'confirmed' WHERE payment_id = ? AND payment_status IN ('pending','failed')")->execute([(int)$paymentId]);
            $t = transitionBooking($pdo, (int)$p['booking_id'], 'confirmed', 'host', $hostId, ['event' => 'payment_confirmed', 'note' => 'Host confirmed receiving the payment']);
            if (!$t['ok']) { $pdo->rollBack(); return $t; }
            logPaymentChange($pdo, (int)$paymentId, $hostId, 'host', 'host_confirmed', $p['payment_status'], 'confirmed');
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('hostConfirmPaymentReceived: ' . $e->getMessage()); return ['ok' => false, 'error' => 'Something went wrong. Please try again.']; }

        $f = bookingStayFacts($pdo, (int)$p['booking_id']); $label = bookingLabel((int)$p['booking_id']);
        notify($pdo, (int)$f['t_id'], 'Booking confirmed', "$label is confirmed. Your host received your payment of KES " . number_format($p['amount']) . '. Your receipt and the host\'s contact details are under My bookings.', 'tourist/bookings.php');
        notify($pdo, (int)$f['h_id'], 'Payment confirmed', "You confirmed the payment for $label. The booking is now confirmed.", 'host/bookings.php');
        try {
            require_once __DIR__ . '/email.php';
            $body = '<h2>Your booking is confirmed</h2><p>Hello ' . h($f['t_name']) . ',</p><p>Booking reference <strong>' . h($label) . '</strong> is confirmed.</p>'
                . '<table cellpadding="6" style="border-collapse:collapse"><tr><td>Accommodation</td><td>' . h($f['title']) . ', ' . h($f['location_name']) . '</td></tr>'
                . '<tr><td>Dates</td><td>' . h($f['check_in_date']) . ' to ' . h($f['check_out_date']) . '</td></tr>'
                . '<tr><td>Payment</td><td>KES ' . number_format($p['amount']) . ' received by your host' . ($p['payment_reference'] ? ' (ref ' . h($p['payment_reference']) . ')' : '') . '</td></tr>'
                . '<tr><td>Your host</td><td>' . h($f['h_name']) . ($f['h_phone'] ? ', ' . h($f['h_phone']) : '') . '</td></tr></table>'
                . '<p>You can message your host from the Messages page in your account.</p>';
            sendEmail($f['t_email'], 'Booking confirmed ' . $label . ' — StayExplore Kenya', $body);
            sendPaymentVerifiedEmail($f['t_email'], $f['t_name'], ['booking_id' => $p['booking_id'], 'title' => $f['title'], 'check_in_date' => $f['check_in_date'], 'check_out_date' => $f['check_out_date'], 'total_amount' => $p['amount']], $f['h_name'], (string)$p['payment_reference'], (int)$paymentId);
        } catch (Throwable $e) { error_log('confirm emails: ' . $e->getMessage()); }
        return ['ok' => true, 'error' => '', 'booking_id' => (int)$p['booking_id']];
    }
}
if (!function_exists('hostRejectPayment')) {
    /** The host did not receive the money: the guest is asked to check and send the details again, within a fresh window. */
    function hostRejectPayment($pdo, $paymentId, $hostId, $reason) {
        require_once __DIR__ . '/payment_audit.php';
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare("SELECT p.*, b.status AS b_status FROM payments p JOIN bookings b ON b.booking_id = p.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE p.payment_id = ? AND a.host_id = ? FOR UPDATE");
            $q->execute([(int)$paymentId, (int)$hostId]); $p = $q->fetch(PDO::FETCH_ASSOC);
            if (!$p || $p['b_status'] !== 'payment_pending' || $p['payment_status'] !== 'pending') { $pdo->rollBack(); return ['ok' => false, 'error' => 'There is no payment waiting for your check on this booking.']; }
            $pdo->prepare("UPDATE payments SET payment_status = 'failed' WHERE payment_id = ?")->execute([(int)$paymentId]);
            $t = transitionBooking($pdo, (int)$p['booking_id'], 'accepted', 'host', $hostId, ['event' => 'payment_rejected', 'note' => $reason]);
            if (!$t['ok']) { $pdo->rollBack(); return $t; }
            logPaymentChange($pdo, (int)$paymentId, $hostId, 'host', 'host_rejected', 'pending', 'failed', $reason);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('hostRejectPayment: ' . $e->getMessage()); return ['ok' => false, 'error' => 'Something went wrong. Please try again.']; }
        $f = bookingStayFacts($pdo, (int)$p['booking_id']); $label = bookingLabel((int)$p['booking_id']);
        notify($pdo, (int)$f['t_id'], 'Payment not received', "Your host did not find your payment for $label. Reason: $reason. Please check and send the details again before " . date('j M Y, H:i', strtotime($f['payment_due_at'])) . '.', 'tourist/bookings.php');
        return ['ok' => true, 'error' => ''];
    }
}
if (!function_exists('guestSubmitPaymentAllowed')) {
    /** True only for a booking the host has accepted and whose payment window is still open. */
    function guestSubmitPaymentAllowed(array $booking) {
        return ($booking['status'] ?? '') === 'accepted' && (empty($booking['payment_due_at']) || strtotime($booking['payment_due_at']) > time());
    }
}
