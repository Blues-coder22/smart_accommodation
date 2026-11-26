<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/notifications.php';
require_once '../includes/commission.php';
ensureCommissionTables($pdo);
require_once '../includes/booking_workflow.php';
require_once '../includes/booking_holds.php';
expireStaleBookingRequests($pdo);   // safety net only: the scheduled job (cron/maintenance.php) normally does this
require_once '../includes/booking_events.php';
ensureBookingEventsTable($pdo);
require_once '../includes/email.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$host_id = $_SESSION['user_id'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $booking_id = bookingIdFromRef($_POST['booking_ref'] ?? '');
    $action     = sanitize($_POST['action'] ?? '');

    // Verify booking belongs to this host
    $check = $pdo->prepare("
        SELECT b.*, a.title, u.email AS tourist_email,
               u.full_name AS tourist_name
        FROM bookings b
        JOIN accommodations a ON a.accommodation_id = b.accommodation_id
        JOIN users u ON u.user_id = b.tourist_id
        WHERE b.booking_id = ? AND a.host_id = ?
    ");
    $check->execute([$booking_id, $host_id]);
    $booking = $check->fetch();

    if ($booking) {
        switch ($action) {
            case 'accept':
                // Step 3: the host accepts the request. The guest is now invited to pay, within the payment deadline.
                $r = hostAcceptBooking($pdo, $booking_id, $host_id);
                setFlash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Booking accepted. The guest was notified and has ' . (int)paymentDeadlineHours() . ' hours to pay you directly.' : $r['error']);
                break;

            case 'decline':
                // The host declines a pending request. The dates are released and no payment is ever requested.
                $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300);
                $r = hostDeclineBooking($pdo, $booking_id, $host_id, $note);
                setFlash($r['ok'] ? 'rejected' : 'error', $r['ok'] ? 'Request declined. The dates were released and the guest was notified.' : $r['error']);
                break;

            case 'confirm_payment':
                // Step 5: the host says the guest's money arrived. Only now does the booking become 'confirmed'.
                $pq = $pdo->prepare("SELECT payment_id FROM payments WHERE booking_id = ? ORDER BY payment_id DESC LIMIT 1");
                $pq->execute([$booking_id]); $pid = (int)$pq->fetchColumn();
                $r = $pid ? hostConfirmPaymentReceived($pdo, $pid, $host_id) : ['ok' => false, 'error' => 'The guest has not submitted payment details yet.'];
                setFlash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Payment confirmed. The booking is confirmed and the guest was notified.' : $r['error']);
                break;

            case 'reject_payment':
                $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 300);
                if (mb_strlen($reason) < 5) { setFlash('error', 'Please tell the guest why you did not receive the payment (at least 5 characters).'); break; }
                $pq = $pdo->prepare("SELECT payment_id FROM payments WHERE booking_id = ? ORDER BY payment_id DESC LIMIT 1");
                $pq->execute([$booking_id]); $pid = (int)$pq->fetchColumn();
                $r = $pid ? hostRejectPayment($pdo, $pid, $host_id, $reason) : ['ok' => false, 'error' => 'No payment details to check.'];
                setFlash($r['ok'] ? 'warning' : 'error', $r['ok'] ? 'The guest was asked to check and send their payment details again.' : $r['error']);
                break;

            case 'cancel':
                // Cancelling is a serious step for the guest, so a written reason is required, it is logged,
                // the guest is told, and the admins are alerted when a confirmed booking is cancelled.
                $reason = mb_substr(trim((string)($_POST['cancel_reason'] ?? '')), 0, 500);
                if (mb_strlen($reason) < 5) {
                    setFlash('error', 'Please give the guest a reason for cancelling (at least 5 characters).');
                    break;
                }
                $ps = $pdo->prepare("SELECT payment_status FROM payments WHERE booking_id = ? ORDER BY payment_id DESC LIMIT 1");
                $ps->execute([$booking_id]);
                $payStatus = (string)($ps->fetchColumn() ?: '');
                $wasStatus = $booking['status'];
                $r = transitionBooking($pdo, $booking_id, 'cancelled', 'host', $host_id, ['event' => 'host_cancelled', 'note' => $reason]);
                if (!$r['ok']) { setFlash('error', $r['error']); break; }
                try {
                    sendHostCancelledGuestEmail($booking['tourist_email'], $booking['tourist_name'], $booking, $reason, $payStatus);
                    if ($wasStatus === 'confirmed') {
                        foreach (adminEmailAddresses($pdo) as $adminTo) {
                            sendHostCancelledAdminEmail($adminTo, $booking, $_SESSION['full_name'] ?? 'Host', $booking['tourist_name'], $reason, $payStatus);
                        }
                    }
                } catch (Throwable $e) { error_log('Host cancel emails: ' . $e->getMessage()); }
                notifyUserOfBooking($pdo, $booking_id, 'tourist', 'Booking cancelled by your host', bookingLabel($booking_id) . ' was cancelled. Reason: ' . $reason, 'tourist/bookings.php');
                applyCancellationRefund($pdo, $booking_id, 'host');
                setFlash('success', 'Booking cancelled. The guest has been told your reason' . ($payStatus === 'confirmed' ? ' and reminded to expect a refund from you.' : '.'));
                break;

            case 'no_show':
                if ($booking['status'] === 'confirmed' && $booking['check_in_date'] > date('Y-m-d')) {
                    setFlash('warning', 'A no-show can only be recorded from the check-in day (' . date('d M Y', strtotime($booking['check_in_date'])) . ') onwards.');
                } else {
                    $r = transitionBooking($pdo, $booking_id, 'no_show', 'host', $host_id, ['event' => 'no_show', 'note' => 'Host recorded the guest as a no-show']);
                    setFlash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'No-show recorded for this booking.' : $r['error']);
                }
                break;

            case 'complete':
                $early = $booking['check_out_date'] > date('Y-m-d');
                $reason = trim((string)($_POST['early_reason'] ?? ''));
                if ($booking['status'] === 'confirmed' && $early && mb_strlen($reason) < 5) {
                    setFlash('error', 'The check-out date has not come yet. Please say why you are ending the stay early (for example, the guest left early).');
                    break;
                }
                $r = transitionBooking($pdo, $booking_id, 'completed', 'host', $host_id, ['event' => $early ? 'early_completion' : 'completed', 'note' => $early ? $reason : 'Host marked the stay as completed']);
                if (!$r['ok']) { setFlash('error', $r['error']); break; }
                if ($early) {
                    // Allowed, but recorded: the admin can see it and the guest is told so they can object.
                    try {
                        sendEmail($booking['tourist_email'], 'Your stay was marked as completed early — StayExplore Kenya',
                            '<p>Your host marked your stay at <strong>' . h($booking['title']) . '</strong> as completed before the check-out date (' . h($booking['check_out_date']) . ').</p><p>Reason given: ' . h($reason) . '</p><p>If this is not right, please contact support.</p>');
                    } catch (Throwable $e) {}
                    setFlash('success', 'Booking marked as complete early. Your reason was saved and the guest was told.');
                } else {
                    setFlash('success', 'Booking marked as completed.');
                }
                break;

            case 'flag_tourist':
                // Flag tourist to admin
                $pdo->prepare("
                    INSERT INTO reports
                    (reporter_id, report_type, target_id,
                     description, status)
                    VALUES (?, 'tourist', ?, ?, 'pending')
                ")->execute([
                    $host_id,
                    $booking['tourist_id'],
                    sanitize($_POST['flag_reason'] ?? 
                             'Flagged by host')
                ]);

                // Notify admin
                $admin = adminNotificationRecipient($pdo);

                if ($admin) {
                    sendEmail(
                        $admin['email'],
                        'Tourist Flagged by Host — Review Required',
                        "
                        <h2>Tourist flagged by host</h2>
                        <p><strong>Tourist:</strong>
                           {$booking['tourist_name']}</p>
                        <p><strong>Booking:</strong> " . bookingLabel($booking_id) . "</p>
                        <p><strong>Reason:</strong> " .
                           sanitize($_POST['flag_reason'] ?? 
                                    'Not specified') . "</p>
                        <p>Please log in to the admin panel
                           to review this report.</p>
                        "
                    );
                }
                setFlash('success',
                    'Tourist has been flagged for admin review.');
                break;
        }
    }
    redirect('bookings.php?tab=' . sanitize($_GET['tab'] ?? 'all'));
}

$tab   = sanitize($_GET['tab'] ?? 'all');
$today = date('Y-m-d');
$focusRef = strtoupper(preg_replace('/^BK-/i', '', trim((string)($_GET['ref'] ?? ''))));
if ($focusRef !== '' && !isset($_GET['tab'])) {   // from a notification: open the tab that holds this booking
    $fid = bookingIdFromRef($focusRef);
    if ($fid) {
        $fq = $pdo->prepare("SELECT b.status FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE b.booking_id = ? AND a.host_id = ?");
        $fq->execute([$fid, $host_id]); $fs = $fq->fetchColumn();
        if (in_array($fs, ['cancelled', 'no_show'], true)) $tab = 'cancelled';
        elseif ($fs === 'completed') $tab = 'completed';
        elseif ($fs === 'pending') $tab = 'pending';
        elseif (in_array($fs, ['accepted', 'payment_pending'], true)) $tab = 'awaiting';
        elseif ($fs === 'confirmed') $tab = 'confirmed';
    }
}

// Build query based on tab
$where = "a.host_id = ?";
$params = [$host_id];

if ($tab === 'pending') {
    $where .= " AND b.status = 'pending'";
} elseif ($tab === 'awaiting') {
    $where .= " AND b.status IN ('accepted','payment_pending')";
} elseif ($tab === 'confirmed') {
    $where .= " AND b.status = 'confirmed'";
} elseif ($tab === 'completed') {
    $where .= " AND b.status = 'completed'";
} elseif ($tab === 'cancelled') {
    $where .= " AND b.status IN ('cancelled','no_show')";
}

// v9.33: one page at a time. A busy host used to load every booking they ever had, with several lookups per booking.
$perPage = 20;
$tc = $pdo->prepare("SELECT COUNT(*) FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE $where");
$tc->execute($params);
$totalRows  = (int)$tc->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$pageNo     = min($totalPages, max(1, (int)($_GET['page'] ?? 1)));
$offset     = ($pageNo - 1) * $perPage;
$bookings = $pdo->prepare("
    SELECT b.*,
           a.title AS property_title,
           u.full_name AS tourist_name,
           u.phone_number AS tourist_phone,
           (SELECT g.rating FROM guest_reviews g WHERE g.booking_id = b.booking_id) AS my_guest_rating,
           (SELECT p.payment_status FROM payments p WHERE p.booking_id=b.booking_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_status,
           (SELECT pp.status FROM platform_payments pp WHERE pp.booking_id=b.booking_id LIMIT 1) AS pp_status,
           (SELECT p.payment_method FROM payments p WHERE p.booking_id=b.booking_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_method,
           (SELECT p.payment_reference FROM payments p WHERE p.booking_id=b.booking_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_reference
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users u ON u.user_id = b.tourist_id
    WHERE $where
    ORDER BY b.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$bookings->execute($params);
$bookings = $bookings->fetchAll();

// Tab counts
$counts = $pdo->prepare("
    SELECT
        COUNT(*) AS all_count,
        SUM(CASE WHEN b.status='pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN b.status IN ('accepted','payment_pending') THEN 1 ELSE 0 END) AS awaiting,
        SUM(CASE WHEN b.status='confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN b.status='completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN b.status IN ('cancelled','no_show')
            THEN 1 ELSE 0 END) AS cancelled
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    WHERE a.host_id = ?
");
$counts->execute([$host_id]);
$counts = $counts->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bookings — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <style>
        .sidebar    { background:var(--surface-2);
                      border-right:1px solid var(--border);
                      padding:24px 0; position:sticky;
                      top:52px; height:calc(100vh - 52px);
                      overflow-y:auto }
        .badge      { font-size:10px; background:#DC2626;
                      color:#fff; border-radius:10px;
                      padding:1px 6px; margin-left:auto }
        .main       { padding:28px }
        .mini { font-size:11px; font-weight:400; color:var(--text-secondary); margin-top:3px; line-height:1.45 }
        .inline-form { margin:0 }
        .btn-sm-primary { background:var(--btn); color:#fff; border-color:var(--accent) }
        .s-accepted { background:var(--info-bg); color:var(--info-fg) }
        .tabs       { display:flex; border-bottom:1px solid
                      var(--border); margin-bottom:20px }
        .tab-btn    { padding:10px 16px; font-size:13px;
                      font-weight:500; color:var(--text-secondary);
                      border:none; background:none; cursor:pointer;
                      border-bottom:2px solid transparent;
                      margin-bottom:-1px; text-decoration:none;
                      display:inline-flex; align-items:center;
                      gap:6px }
        .tab-btn.active { color:var(--accent-strong);
                          border-bottom-color:var(--accent-strong) }
        .tab-count  { font-size:11px; background:var(--surface-1);
                      border:1px solid var(--border);
                      border-radius:10px; padding:1px 7px }
        .booking-card { background:var(--surface-2);
                        border:1px solid var(--border);
                        border-radius:8px; padding:18px;
                        margin-bottom:10px }
        .booking-top  { display:flex; justify-content:space-between;
                        align-items:flex-start;
                        margin-bottom:12px }
        .booking-prop { font-size:14px; font-weight:500;
                        margin-bottom:3px }
        .booking-ref  { font-size:12px; color:var(--text-secondary) }
        .status-pill  { display:inline-block; font-size:11px;
                        font-weight:600; padding:3px 9px;
                        border-radius:3px }
        .s-confirmed  { background:var(--accent-soft); color:var(--accent-strong);
                        border:1px solid var(--accent-line) }
        .s-pending    { background:var(--warn-bg); color:var(--warn-fg);
                        border:1px solid var(--warn-line) }
        .s-completed  { background:#F0F4FF; color:#1A4DB3;
                        border:1px solid #B3C5F5 }
        .s-cancelled  { background:var(--bad-bg); color:var(--bad-fg);
                        border:1px solid var(--bad-line) }
        .booking-details { display:grid;
                           grid-template-columns:repeat(4,1fr);
                           gap:12px; padding:12px 0;
                           border-top:1px solid var(--border);
                           border-bottom:1px solid var(--border);
                           margin-bottom:12px }
        .detail-item  { font-size:12px }
        .detail-item .label { color:var(--text-secondary);
                              margin-bottom:3px;
                              text-transform:uppercase;
                              font-size:10px; font-weight:600;
                              letter-spacing:.04em }
        .detail-item .value { font-weight:500;
                              color:var(--text-primary) }
        .booking-actions { display:flex; gap:8px;
                           flex-wrap:wrap; align-items:center }
        .btn-sm     { padding:6px 12px; font-size:12px;
                      border-radius:4px; cursor:pointer;
                      font-weight:500; text-decoration:none;
                      display:inline-block;
                      border:1px solid var(--border-strong);
                      background:none;
                      color:var(--text-secondary) }
        .btn-sm:hover { border-color:var(--accent); color:var(--accent) }
        .btn-sm-danger { border-color:var(--bad-line); color:var(--bad-fg) }
        .btn-sm-danger:hover { background:var(--bad-bg) }
        .pay-confirmed { color:var(--accent-strong); font-weight:500 }
        .pay-pending   { color:var(--warn-fg) }
        .flag-form     { display:flex; gap:6px; margin-top:8px;
                         flex-wrap:wrap }
        .flag-form input { flex:1; padding:6px 10px;
                           border:1px solid var(--border-strong);
                           border-radius:4px; font-size:12px;
                           min-width:180px;
                           background:var(--surface-2) }
        .empty-state   { text-align:center; padding:48px 20px;
                         color:var(--text-secondary); font-size:14px }
        @media(max-width:1024px){
            .sidebar   { display:none }
            .booking-details { grid-template-columns:repeat(2,1fr) }
        }
    </style>
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <?= showFlash() ?>

        <div class="page-tag">Host</div>
        <h1 class="page-title">Bookings</h1>

        <!-- Tabs -->
        <div class="tabs">
            <a href="?tab=all"
               class="tab-btn <?= $tab==='all'?'active':'' ?>">
                All
                <span class="tab-count"><?= $counts['all_count'] ?></span>
            </a>
            <a href="?tab=pending"
               class="tab-btn <?= $tab==='pending'?'active':'' ?>">
                Requests
                <span class="tab-count"><?= $counts['pending'] ?></span>
            </a>
            <a href="?tab=awaiting"
               class="tab-btn <?= $tab==='awaiting'?'active':'' ?>">
                Awaiting payment
                <span class="tab-count"><?= $counts['awaiting'] ?></span>
            </a>
            <a href="?tab=confirmed"
               class="tab-btn <?= $tab==='confirmed'?'active':'' ?>">
                Confirmed
                <span class="tab-count"><?= $counts['confirmed'] ?></span>
            </a>
            <a href="?tab=completed"
               class="tab-btn <?= $tab==='completed'?'active':'' ?>">
                Completed
                <span class="tab-count"><?= $counts['completed'] ?></span>
            </a>
            <a href="?tab=cancelled"
               class="tab-btn <?= $tab==='cancelled'?'active':'' ?>">
                Cancelled
                <span class="tab-count"><?= $counts['cancelled'] ?></span>
            </a>
        </div>

        <?php if (empty($bookings)): ?>
            <div class="empty-state">
                No <?= $tab === 'all' ? '' : h($tab === 'pending' ? 'requests' : $tab) ?> bookings found.
            </div>

        <?php else: ?>
            <?php foreach ($bookings as $b): ?>
            <div class="booking-card<?= ($focusRef !== '' && strtoupper((string)bookingRef($b['booking_id'])) === $focusRef) ? ' bk-focus' : '' ?>" id="bk-<?= h(bookingRef($b['booking_id'])) ?>">

                <!-- Top row -->
                <div class="booking-top">
                    <div>
                        <div class="booking-prop">
                            <?= h($b['property_title']) ?>
                        </div>
                        <div class="booking-ref">
                            <?= h(bookingLabel($b['booking_id'])) ?>
                            &middot; Booked
                            <?= date('d M Y',
                                strtotime($b['created_at'])) ?>
                        </div>
                    </div>
                    <?php $pill = bookingStatusClass($b['status']); $label = bookingStatusLabel($b['status'], 'host'); ?>
                    <span class="status-pill <?= $pill ?>">
                        <?= $label ?>
                    </span>
                </div>

                <!-- Details grid -->
                <div class="booking-details">
                    <div class="detail-item">
                        <div class="label">Guest</div>
                        <div class="value">
                            <?= h($b['tourist_name']) ?>
                            <?php static $repCache = []; $rep = $repCache[$b['tourist_id']] ??= getGuestReputation($pdo, $b['tourist_id']); ?>
                            <div style="font-size:11px;font-weight:400;color:var(--text-secondary)">
                                <?= $rep['count'] ? '&#9733; ' . h($rep['avg']) . ' from ' . (int)$rep['count'] . ' host review' . ($rep['count'] === 1 ? '' : 's') : 'No host reviews yet' ?>
                            </div>
                            <?php if ($b['tourist_phone']): ?>
                            <div style="font-size:11px;
                                        font-weight:400;
                                        color:var(--text-secondary)">
                                <?= h(
                                    $b['tourist_phone']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Dates</div>
                        <div class="value">
                            <?= date('d M',
                                strtotime($b['check_in_date'])) ?>
                            &ndash;
                            <?= date('d M Y',
                                strtotime($b['check_out_date'])) ?>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Guests</div>
                        <div class="value">
                            <?= $b['num_guests'] ?>
                            guest<?= $b['num_guests']>1?'s':'' ?>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Amount</div>
                        <div class="value">
                            <?php $sp = bookingSplit($b); ?>
                            <?php if (!$sp['legacy'] && $sp['mode'] === 'invoice'): ?>
                                KES <?= number_format($sp['host_amount']) ?>
                                <div class="mini">Paid to you directly by the guest. Your commission (KES <?= number_format($sp['commission_deferred']) ?>) is invoiced separately after the stay is completed.</div>
                            <?php elseif (!$sp['legacy'] && $sp['platform'] > 0): ?>
                                KES <?= number_format($sp['host_amount']) ?>
                                <div class="mini">Booked before v9.33: the guest pays you this. KES <?= number_format($sp['platform']) ?> goes to StayExplore Kenya as agreed when it was made.</div>
                            <?php else: ?>
                                KES <?= number_format($b['total_amount']) ?>
                            <?php endif; ?>
                            <div class="mini <?= $b['payment_status'] === 'confirmed' ? 'pay-confirmed' : 'pay-pending' ?>">
                                <?php if ($b['status'] === 'pending'): ?>No payment requested yet
                                <?php elseif ($b['payment_status'] === 'confirmed'): ?>You confirmed the payment
                                <?php elseif ($b['payment_status'] === 'pending'): ?>Guest says they paid: check your account
                                <?php elseif ($b['payment_status'] === 'failed'): ?>Payment not received: guest asked to retry
                                <?php elseif ($b['status'] === 'accepted'): ?>Waiting for the guest to pay<?php if (!empty($b['payment_due_at'])): ?> (until <?= h(date('j M, H:i', strtotime($b['payment_due_at']))) ?>)<?php endif; ?>
                                <?php else: ?>&mdash;<?php endif; ?>
                                <?php if (!empty($b['payment_reference'])): ?><div>Method: <?= h(str_replace('_', ' ', (string)$b['payment_method'])) ?> &middot; Ref: <?= h($b['payment_reference'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="booking-actions">
                    <a class="btn-sm" href="../booking_record.php?as=host&amp;ref=<?= h(bookingRef($b['booking_id'])) ?>" target="_blank" rel="noopener">Print record</a>
                    <a class="btn-sm" href="messages.php?accommodation_id=<?= (int)$b['accommodation_id'] ?>&amp;tourist_id=<?= (int)$b['tourist_id'] ?>">Message guest</a>
                    <?php if ($b['status'] === 'completed'): ?>
                        <?php if ($b['my_guest_rating'] !== null): ?>
                            <span class="btn-sm" style="cursor:default">You rated this guest &#9733; <?= (int)$b['my_guest_rating'] ?></span>
                        <?php else: ?>
                            <a class="btn-sm" href="rate_guest.php?ref=<?= h(bookingRef($b['booking_id'])) ?>">Rate guest</a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($b['status'] === 'pending'): ?>
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="booking_ref" value="<?= h(bookingRef($b['booking_id'])) ?>">
                            <input type="hidden" name="action" value="accept">
                            <button type="submit" class="btn-sm btn-sm-primary" onclick="return confirm('Accept this request? The guest will be asked to pay you directly within <?= (int)paymentDeadlineHours() ?> hours, and the dates stay held for them.')">Accept request</button>
                        </form>
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="booking_ref" value="<?= h(bookingRef($b['booking_id'])) ?>">
                            <input type="hidden" name="action" value="decline">
                            <input type="hidden" name="note" value="">
                            <button type="submit" class="btn-sm btn-sm-danger" onclick="var n=prompt('Decline this request? The dates are released and the guest is told. Add a short note for the guest (optional):',''); if(n===null){return false;} this.form.note.value=n; return true;">Decline</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($b['status'] === 'payment_pending'): ?>
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="booking_ref" value="<?= h(bookingRef($b['booking_id'])) ?>">
                            <input type="hidden" name="action" value="confirm_payment">
                            <button type="submit" class="btn-sm btn-sm-primary" onclick="return confirm('Confirm that you have really received this payment in your M-Pesa or bank account? The booking becomes confirmed.')">Confirm payment received</button>
                        </form>
                        <form method="POST" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="booking_ref" value="<?= h(bookingRef($b['booking_id'])) ?>">
                            <input type="hidden" name="action" value="reject_payment">
                            <input type="hidden" name="reason" value="">
                            <button type="submit" class="btn-sm btn-sm-danger" onclick="var r=prompt('Why did you not receive it? The guest will see this and can send the details again.'); if(!r||r.trim().length<5){alert('Please give a reason of at least 5 characters.');return false;} this.form.reason.value=r; return true;">Not received</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($b['status'] === 'confirmed'): ?>
                        <form method="POST" style="margin:0">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="booking_ref"
                                   value="<?= h(bookingRef($b['booking_id'])) ?>">
                            <input type="hidden" name="action"
                                   value="complete">
                            <input type="hidden" name="early_reason" value="">
                            <button type="submit" class="btn-sm"
                                    onclick="<?= $b['check_out_date'] > date('Y-m-d')
                                        ? "var r=prompt('The check-out date (" . date('d M', strtotime($b['check_out_date'])) . ") has not arrived yet. Why is this stay being completed early?'); if(!r||r.trim().length<5){alert('Please give a reason of at least 5 characters.');return false;} this.form.early_reason.value=r; return true;"
                                        : "return confirm('Mark this booking as completed?')" ?>">
                                Mark completed
                            </button>
                        </form>

                        <form method="POST" style="margin:0">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="booking_ref"
                                   value="<?= h(bookingRef($b['booking_id'])) ?>">
                            <input type="hidden" name="action"
                                   value="no_show">
                            <button type="submit" class="btn-sm"
                                    <?= $b['check_in_date'] > date('Y-m-d') ? 'disabled title="Available from the check-in day"' : '' ?>
                                    onclick="return confirm(
                                        'Record this guest as a no-show?')">
                                Record no-show
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if (in_array($b['status'],
                        ['accepted','payment_pending','confirmed'])): ?>
                        <form method="POST" style="margin:0">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="booking_ref"
                                   value="<?= h(bookingRef($b['booking_id'])) ?>">
                            <input type="hidden" name="action"
                                   value="cancel">
                            <input type="hidden" name="cancel_reason" value="">
                            <button type="submit"
                                    class="btn-sm btn-sm-danger"
                                    onclick="var r=prompt('Why are you cancelling? The guest and the admins will see this reason.'); if(!r||r.trim().length<5){alert('Please give a reason of at least 5 characters.');return false;} this.form.cancel_reason.value=r; return true;">
                                Cancel booking
                            </button>
                        </form>
                    <?php endif; ?>

                    <!-- Flag tourist -->
                    <?php if (in_array($b['status'],
                        ['confirmed','completed','no_show'])): ?>
                    <button type="button" class="btn-sm btn-sm-danger"
                            onclick="toggleFlagForm(
                                'flag-<?= $b['booking_id'] ?>')">
                        Flag tourist
                    </button>
                    <?php endif; ?>

                </div>

                <!-- Flag tourist form — hidden by default -->
                <?php if (in_array($b['status'],
                    ['confirmed','completed','no_show'])): ?>
                <div id="flag-<?= $b['booking_id'] ?>"
                     style="display:none;margin-top:10px;
                            padding-top:10px;
                            border-top:1px solid var(--border)">
                    <form method="POST" class="flag-form">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="booking_ref"
                               value="<?= h(bookingRef($b['booking_id'])) ?>">
                        <input type="hidden" name="action"
                               value="flag_tourist">
                        <input aria-label="Reason for flagging this guest" type="text" name="flag_reason"
                               placeholder="Reason for flagging this guest..."
                               required>
                        <button type="submit"
                                class="btn-sm btn-sm-danger">
                            Submit flag
                        </button>
                        <button type="button" class="btn-sm"
                                onclick="toggleFlagForm(
                                    'flag-<?= $b['booking_id'] ?>')">
                            Cancel
                        </button>
                    </form>
                </div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
            <?php if ($totalPages > 1): $q = $_GET; ?>
            <nav class="pager" aria-label="Booking pages">
                <?php if ($pageNo > 1): $q['page'] = $pageNo - 1; ?><a class="btn-sm" href="?<?= h(http_build_query($q)) ?>">&larr; Newer</a><?php else: ?><span></span><?php endif; ?>
                <span class="pager-info">Page <?= (int)$pageNo ?> of <?= (int)$totalPages ?> &middot; <?= (int)$totalRows ?> bookings</span>
                <?php if ($pageNo < $totalPages): $q['page'] = $pageNo + 1; ?><a class="btn-sm" href="?<?= h(http_build_query($q)) ?>">Older &rarr;</a><?php else: ?><span></span><?php endif; ?>
            </nav>
            <?php endif; ?>
        <?php endif; ?>

    <?php appShellEnd(); ?>

<script>
function toggleFlagForm(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none'
        ? 'block' : 'none';
}
</script>

<style>.bk-focus{outline:2px solid var(--accent,#16866b);outline-offset:3px;animation:bkpulse 2.4s ease 2}@keyframes bkpulse{0%,100%{background:transparent}50%{background:rgba(22,134,107,.12)}}</style><script>(function(){var f=document.querySelector('.bk-focus');if(f)f.scrollIntoView({block:'center',behavior:'smooth'});})();</script>
</body>
</html>