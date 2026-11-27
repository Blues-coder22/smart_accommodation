<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/notifications.php';
require_once '../includes/booking_rules.php';
require_once '../includes/booking_workflow.php';
require_once '../includes/booking_countdown.php';
require_once '../includes/refund_requests.php';
ensureRefundRequestTables($pdo);
ensureCommissionTables($pdo);
require_once '../includes/booking_holds.php';
expireStaleBookingRequests($pdo);   // releases dates held by unpaid or unanswered requests
require_once '../includes/onsite_experience.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('tourist');

$tab = sanitize($_GET['tab'] ?? 'upcoming');

// Tourists may cancel their own pending or confirmed future booking. State changes require POST + CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_booking') {
    requireCsrf();
    $cancelId = bookingIdFromRef($_POST['booking_ref'] ?? '');
    $check = $pdo->prepare("SELECT booking_id, status, check_in_date FROM bookings WHERE booking_id = ? AND tourist_id = ? LIMIT 1");
    $check->execute([$cancelId, (int)$_SESSION['user_id']]);
    $owned = $check->fetch();
    if (!$owned) {
        setFlash('error', 'Booking not found.');
    } elseif (!in_array($owned['status'], ['pending','accepted','payment_pending','confirmed'], true) || $owned['check_in_date'] <= date('Y-m-d')) {
        setFlash('error', 'This booking can no longer be cancelled online. Please contact support if you need help.');
    } else {
        $cx = transitionBooking($pdo, $cancelId, 'cancelled', 'tourist', (int)$_SESSION['user_id'], ['event' => 'guest_cancelled', 'note' => 'Cancelled by the guest']);
        $upd = new class($cx['ok']) { public $ok; function __construct($o) { $this->ok = $o; } function rowCount() { return $this->ok ? 1 : 0; } };
        if ($upd->rowCount()) {
            $late = isLateCancellation($owned['check_in_date']);
            notifyUserOfBooking($pdo, $cancelId, 'host', 'Booking cancelled by the guest', bookingLabel($cancelId) . ' was cancelled' . ($late ? ' (late cancellation)' : '') . '. The dates are open again.', 'host/bookings.php');
            applyCancellationRefund($pdo, $cancelId, 'guest');
            setFlash('success', $late
                ? 'Your booking has been cancelled and your host has been told. This is closer than ' . freeCancelHours() . ' hours to check-in, so any refund of money you already paid is up to your host.'
                : 'Your booking has been cancelled and your host has been told. If you already paid, please agree the refund directly with your host.');
            // Let the host know so the dates are not held for a guest who is no longer coming.
            try {
                $info = $pdo->prepare("SELECT a.title, h.email AS host_email, b.check_in_date, b.check_out_date, t.full_name AS guest_name
                    FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id
                    JOIN users h ON h.user_id = a.host_id JOIN users t ON t.user_id = b.tourist_id WHERE b.booking_id = ?");
                $info->execute([$cancelId]);
                if ($i = $info->fetch()) {
                    require_once '../includes/email.php';
                    sendEmail($i['host_email'], 'Booking cancelled by guest — StayExplore Kenya',
                        '<p>' . h($i['guest_name'], ENT_QUOTES, 'UTF-8') . ' has cancelled their booking for <strong>' . h($i['title'], ENT_QUOTES, 'UTF-8') . '</strong> (' .
                        h($i['check_in_date']) . ' to ' . h($i['check_out_date']) . ').</p><p>Those dates are open for other guests again.</p>' .
                        ($late ? '<p><strong>Late cancellation:</strong> this was within ' . (int)freeCancelHours() . ' hours of check-in, so any refund is at your discretion.</p>' : ''));
                }
            } catch (Throwable $e) { /* the booking is cancelled even if the email fails */ }
        } else {
            setFlash('error', 'The booking could not be cancelled. Refresh and try again.');
        }
    }
    redirect('bookings.php?tab=' . urlencode($_GET['tab'] ?? 'upcoming'));
}

// Refunds: the guest asks for a refund on a cancelled booking (with evidence of payment), or asks the admin to step in.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['request_refund', 'escalate_refund'], true)) {
    requireCsrf();
    $rfId = bookingIdFromRef($_POST['booking_ref'] ?? '');
    if ($_POST['action'] === 'request_refund') {
        $ev = refundSaveEvidence($_FILES['evidence'] ?? null);
        $res = $ev['ok'] ? refundCreateRequest($pdo, $rfId, (int)$_SESSION['user_id'], str_replace(',', '.', (string)($_POST['amount'] ?? '')), (string)($_POST['reason'] ?? ''), $ev['name']) : $ev;
        setFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Your refund request was sent to the host and the admin can see it.' : $res['error']);
    } else {
        $res = refundGuestEscalate($pdo, $rfId, (int)$_SESSION['user_id'], (string)($_POST['message'] ?? ''));
        setFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'The admin has been asked to look at your refund.' : $res['error']);
    }
    redirect('bookings.php?tab=cancelled');
}

ensureOnsiteExperienceColumns($pdo);
// Get all bookings for this tourist
$stmt = $pdo->prepare("
    SELECT b.*,
           a.title, a.location_name, a.price_per_night, a.onsite_experience_name,
           (SELECT pp.platform_payment_id FROM platform_payments pp WHERE pp.booking_id = b.booking_id AND pp.status = 'confirmed' LIMIT 1) AS pp_id,
           (SELECT pp.refund_due FROM platform_payments pp WHERE pp.booking_id = b.booking_id AND pp.status IN ('confirmed','refunded') LIMIT 1) AS refund_due,
           (SELECT pp.status FROM platform_payments pp WHERE pp.booking_id = b.booking_id LIMIT 1) AS pp_state,
           (SELECT pp.refunded_amount FROM platform_payments pp WHERE pp.booking_id = b.booking_id LIMIT 1) AS refunded_amount,
           p.payment_id, p.payment_method, p.payment_status,
           (SELECT photo_path FROM accommodation_photos
            WHERE accommodation_id = a.accommodation_id
            ORDER BY is_primary DESC, photo_id ASC LIMIT 1) AS primary_photo,
           (SELECT review_id FROM reviews
            WHERE booking_id = b.booking_id
            AND tourist_id = b.tourist_id
            LIMIT 1) AS has_review
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    LEFT JOIN payments p ON p.booking_id = b.booking_id
    WHERE b.tourist_id = ?
    ORDER BY b.check_in_date DESC
");
$stmt->execute([$_SESSION['user_id']]);
$all_bookings = $stmt->fetchAll();

// Split into tabs
$upcoming   = [];
$completed  = [];
$cancelled  = [];
$today      = date('Y-m-d');

foreach ($all_bookings as $b) {
    if (in_array($b['status'], ['cancelled', 'no_show'], true)) {
        $cancelled[] = $b;
    } elseif ($b['status'] === 'completed' || ($b['status'] === 'confirmed' && $b['check_out_date'] < $today)) {
        // Display only: the scheduled job (cron/maintenance.php) performs the real status change.
        $completed[] = $b;
    } else {
        $upcoming[] = $b;
    }
}

// v9.34: ?ref=BK-XXXX (from a notification) opens the tab that holds that booking and highlights it.
$focusRef = strtoupper(preg_replace('/^BK-/i', '', trim((string)($_GET['ref'] ?? ''))));
if ($focusRef !== '' && !isset($_GET['tab'])) {
    foreach (['cancelled' => $cancelled, 'completed' => $completed, 'upcoming' => $upcoming] as $tn => $list) {
        foreach ($list as $bb) { if (strtoupper((string)bookingRef($bb['booking_id'])) === $focusRef) { $tab = $tn; break 2; } }
    }
}
$current = $tab === 'completed' ? $completed 
         : ($tab === 'cancelled' ? $cancelled : $upcoming);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
 <style>
        .bk-focus{outline:2px solid var(--accent,#16866b);outline-offset:4px;border-radius:10px;animation:bkpulse 2.4s ease 2}
        @keyframes bkpulse{0%,100%{background:transparent}50%{background:rgba(22,134,107,.12)}}
        
        .tabs       { display:flex; gap:0; border-bottom:1px solid var(--border);
                      margin-bottom:24px }
        .tab-btn    { padding:10px 20px; font-size:13px; font-weight:500;
                      color:var(--text-secondary); border:none; background:none;
                      cursor:pointer; border-bottom:2px solid transparent;
                      margin-bottom:-1px; text-decoration:none;
                      display:inline-block }
        .tab-btn.active { color:var(--accent-strong);
                          border-bottom-color:var(--accent-strong) }
        .tab-count  { font-size:11px; background:var(--surface-1);
                      border:1px solid var(--border); border-radius:10px;
                      padding:1px 7px; margin-left:6px;
                      color:var(--text-secondary) }
        .booking-row { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; display:grid;
                       grid-template-columns:80px 1fr auto;
                       gap:16px; align-items:center;
                       padding:16px; margin-bottom:10px;
                       transition:border-color .15s }
        .booking-row:hover { border-color:var(--border-strong) }
        .booking-thumb { width:80px; height:64px; border-radius:6px;
                         background:var(--accent-soft); overflow:hidden;
                         display:flex; align-items:center;
                         justify-content:center; font-size:24px;
                         flex-shrink:0 }
        .booking-thumb img { width:100%; height:100%; object-fit:cover }
        .booking-title { font-size:14px; font-weight:500;
                         margin-bottom:3px }
        .booking-meta  { font-size:12px; color:var(--text-secondary);
                         margin-bottom:6px }
        .booking-dates { font-size:12px; color:var(--text-secondary) }
        .booking-right { text-align:right; flex-shrink:0 }
        .booking-amount{ font-size:14px; font-weight:500;
                         margin-bottom:6px }
        .booking-ref   { font-size:11px; color:var(--text-secondary);
                         margin-bottom:8px }
        .pay-by { font-size:11px; color:var(--text-secondary); margin-top:5px }
        .s-accepted { background:var(--info-bg); color:var(--info-fg) }
        .status-badge  { display:inline-block; font-size:11px;
                         font-weight:600; padding:3px 8px;
                         border-radius:3px }
        .s-confirmed   { background:var(--accent-soft); color:var(--accent-strong);
                         border:1px solid var(--accent-line) }
        .s-pending     { background:var(--warn-bg); color:var(--warn-fg);
                         border:1px solid var(--warn-line) }
        .s-completed   { background:#F0F4FF; color:#1A4DB3;
                         border:1px solid #B3C5F5 }
        .s-cancelled   { background:var(--bad-bg); color:var(--bad-fg);
                         border:1px solid var(--bad-line) }
        .row-actions   { display:flex; gap:8px; margin-top:10px;
                         justify-content:flex-end }
        .btn-sm        { padding:6px 12px; font-size:12px;
                         border-radius:4px; cursor:pointer;
                         font-weight:500; text-decoration:none;
                         display:inline-block; border:1px solid var(--border) }
        .btn-sm-primary{ background:var(--btn-hover); color:#fff; border-color:var(--accent-strong) }
        .btn-sm-outline{ background:none; color:var(--text-secondary) }
        .empty-state   { text-align:center; padding:60px 20px }
        .empty-state h3{ font-size:16px; font-weight:500;
                         margin-bottom:8px; color:var(--text-primary) }
        .empty-state p { font-size:14px; color:var(--text-secondary);
                         margin-bottom:20px }
    </style>
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body class="tourist-bookings">

<?php include '../includes/header.php'; ?>

<div class="page-wrap">
    <?= showFlash() ?>

    <div class="page-tag">Your journeys</div>
    <h1 class="page-title">My bookings</h1>
    <p class="small" style="margin:0 0 14px"><?php bookingRulesDialog(); ?></p>    <h1 class="page-title">My bookings</h1>
    <p class="page-sub">Everything planned, past and remembered.</p>

    <!-- Tabs -->
    <div class="tabs">
        <a href="?tab=upcoming"
           class="tab-btn <?= $tab==='upcoming'?'active':'' ?>">
            Upcoming
            <span class="tab-count"><?= count($upcoming) ?></span>
        </a>
        <a href="?tab=completed"
           class="tab-btn <?= $tab==='completed'?'active':'' ?>">
            Completed
            <span class="tab-count"><?= count($completed) ?></span>
        </a>
        <a href="?tab=cancelled"
           class="tab-btn <?= $tab==='cancelled'?'active':'' ?>">
            Cancelled
            <span class="tab-count"><?= count($cancelled) ?></span>
        </a>
    </div>

    <!-- Booking List -->
    <?php if (empty($current)): ?>
        <div class="empty-state">
            <h3>No <?= $tab ?> bookings</h3>
            <p>
                <?php if ($tab === 'upcoming'): ?>
                    You have no upcoming bookings.
                    Start by searching for a place to stay.
                <?php elseif ($tab === 'completed'): ?>
                    You have no completed stays yet.
                <?php else: ?>
                    You have no cancelled bookings.
                <?php endif; ?>
            </p>
            <?php if ($tab === 'upcoming'): ?>
                <a href="../index.php" class="btn-sm btn-sm-primary">
                    Find accommodation
                </a>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <?php if ($tab === 'cancelled'): ?>
        <div class="notice-box" style="margin-bottom:16px">Cancelled, declined and expired bookings are kept here, with the reason and any refund. Refunds for money you paid your host directly are settled with your host: open <a href="../messages.php">Messages</a> to follow up, or <a href="../support.php">contact StayExplore support</a> if you need help.</div>
        <?php endif; ?>
        <?php foreach ($current as $b): ?>
        <?php $isFocus = ($focusRef !== '' && strtoupper((string)bookingRef($b['booking_id'])) === $focusRef); ?>
        <div class="booking-row<?= $isFocus ? ' bk-focus' : '' ?>" id="bk-<?= h(bookingRef($b['booking_id'])) ?>">

            <!-- Thumbnail -->
            <div class="booking-thumb">
                <?php if ($b['primary_photo']): ?>
                    <img src="../uploads/accommodations/<?= h($b['primary_photo']) ?>"
                         alt="<?= h($b['title']) ?>" loading="lazy" decoding="async">
                <?php else: ?>
                    <span style="font-size:12px;color:var(--text-tertiary)">No photo</span>
                <?php endif; ?>
            </div>

            <!-- Details -->
            <div>
                <div class="booking-title">
                    <?= h($b['title']) ?>
                </div>
                <div class="booking-meta">
                    <?= h($b['location_name']) ?>
                </div>
                <div class="booking-dates">
                    <?= date('d M', strtotime($b['check_in_date'])) ?>
                    &ndash;
                    <?= date('d M Y', strtotime($b['check_out_date'])) ?>
                    &middot;
                    <?= $b['num_guests'] ?> guest<?= $b['num_guests']>1?'s':'' ?>
                </div>
                <?php if ($tab === 'upcoming') echo bookingCountdownHtml($b['check_in_date'], $b['status']); ?>
            </div>

            <!-- Right -->
            <div class="booking-right">
                <div class="booking-amount">
                    KES <?= number_format($b['total_amount']) ?>
                </div>
                <?php $bbr = bookingPriceBreakdown($b); if ($bbr['extra'] > 0): ?>
                <div style="font-size:11px;color:var(--text-secondary);margin-top:2px">incl. KES <?= number_format($bbr['extra']) ?> host tour/guide</div>
                <?php endif; ?>
                <div style="font-size:11px;margin-top:3px"><a href="../booking_record.php?as=tourist&amp;ref=<?= h(bookingRef($b['booking_id'])) ?>" target="_blank" rel="noopener">Print booking record</a></div>
                <?php if ($b['status'] === 'cancelled' && $b['refund_due'] !== null && (float)$b['refund_due'] > 0): ?><div style="font-size:12px;color:#7a4300;margin-top:3px"><?= $b['pp_state'] === 'refunded' ? 'Refunded by StayExplore Kenya: KES ' . number_format((float)$b['refunded_amount']) : 'Refund due from StayExplore Kenya: KES ' . number_format((float)$b['refund_due']) ?></div><?php endif; ?>
                <?php if ($b['status'] === 'cancelled'): $rfPaid = refundPaidAmount($pdo, $b['booking_id']); $rf = refundGetCase($pdo, $b['booking_id']); ?>
                <?php if ($rfPaid > 0 || $rf): ?>
                <div style="margin-top:8px;padding:10px;border:1px solid var(--border);border-radius:8px;font-size:12px;max-width:320px;text-align:left">
                    <strong>Refund from your host</strong>
                    <?php if (!$rf): ?>
                        <details style="margin-top:6px"><summary style="cursor:pointer">Ask for a refund (you paid KES <?= number_format($rfPaid) ?>)</summary>
                            <form method="POST" enctype="multipart/form-data" style="display:grid;gap:6px;margin-top:6px">
                                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                <input type="hidden" name="booking_ref" value="<?= h(bookingRef($b['booking_id'])) ?>">
                                <input type="hidden" name="action" value="request_refund">
                                <label>Amount (KES) <input name="amount" inputmode="decimal" required value="<?= h(number_format($rfPaid, 2, '.', '')) ?>"></label>
                                <label>Why? <input name="reason" maxlength="500" required minlength="5" placeholder="e.g. I cancelled before check-in"></label>
                                <label>Evidence of your payment (screenshot or receipt) <input type="file" name="evidence" accept="image/jpeg,image/png,image/webp" required></label>
                                <button type="submit" class="btn btn-sm">Send refund request</button>
                            </form>
                        </details>
                    <?php else: ?>
                        <div style="margin-top:4px"><strong><?= h(refundStatusLabel($rf['status'])) ?></strong> &middot; you asked for KES <?= number_format((float)$rf['amount_requested'], 2) ?></div>
                        <?php if ($rf['evidence_file']): ?><div><a href="../refund_proof.php?ref=<?= h(opaqueId('rfd', (int)$rf['request_id'])) ?>&amp;file=request" target="_blank" rel="noopener">Your evidence</a></div><?php endif; ?>
                        <?php if ($rf['status'] === 'refunded'): ?>
                            <div>KES <?= number_format((float)$rf['refund_amount'], 2) ?> sent by <?= h(refundMethodLabel($rf['refund_method'])) ?><?= $rf['refund_reference'] ? ' (ref ' . h($rf['refund_reference']) . ')' : '' ?><?= $rf['refunded_by'] === 'admin' ? ', recorded by the admin' : '' ?>
                                <?php if ($rf['refund_evidence']): ?> &middot; <a href="../refund_proof.php?ref=<?= h(opaqueId('rfd', (int)$rf['request_id'])) ?>&amp;file=refund" target="_blank" rel="noopener">See the refund evidence</a><?php endif; ?></div>
                        <?php endif; ?>
                        <?php if ($rf['host_response']): ?><div>Host: <?= h($rf['host_response']) ?></div><?php endif; ?>
                        <?php if ($rf['admin_note']): ?><div>Admin: <?= h($rf['admin_note']) ?></div><?php endif; ?>
                        <?php if (!in_array($rf['status'], ['escalated', 'closed'], true)): ?>
                        <details style="margin-top:6px"><summary style="cursor:pointer"><?= $rf['status'] === 'refunded' ? 'I have not received it' : 'Ask the admin to step in' ?></summary>
                            <form method="POST" style="display:grid;gap:6px;margin-top:6px">
                                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                <input type="hidden" name="booking_ref" value="<?= h(bookingRef($b['booking_id'])) ?>">
                                <input type="hidden" name="action" value="escalate_refund">
                                <label>What is wrong? <input name="message" maxlength="500" required minlength="5"></label>
                                <button type="submit" class="btn btn-sm">Tell the admin</button>
                            </form>
                        </details>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; endif; ?>
                <?php if ($bbr['service_fee'] > 0): ?><div style="font-size:11px;color:var(--text-secondary);margin-top:2px">incl. KES <?= number_format($bbr['service_fee']) ?> service fee</div><?php endif; ?>
                <div class="booking-ref">
                    <?= h(bookingLabel($b['booking_id'])) ?>
                </div>

                <!-- Status badge -->
                <?php $badge_class = bookingStatusClass($b['status']); $badge_label = bookingStatusLabel($b['status'], 'tourist'); ?>
                <div>
                    <span class="status-badge <?= $badge_class ?>">
                        <?= $badge_label ?>
                    </span>
                    <?php if ($b['status'] === 'accepted' && !empty($b['payment_due_at'])): ?>
                        <div class="pay-by">Pay before <?= h(date('j M, H:i', strtotime($b['payment_due_at']))) ?></div>
                    <?php elseif ($b['status'] === 'payment_pending'): ?>
                        <div class="pay-by">Host is checking your payment</div>
                    <?php elseif ($b['status'] === 'pending'): ?>
                        <div class="pay-by">You pay only after the host accepts</div>
                    <?php endif; ?>
                </div>

                <!-- Actions -->
                <div class="row-actions">
                    <?php if ($b['status'] === 'completed'
                              && !$b['has_review']): ?>
                        <a href="review.php?ref=<?= h(bookingRef($b['booking_id'])) ?>"
                           class="btn-sm btn-sm-primary">
                            Leave review
                        </a>
                    <?php endif; ?>

                    <?php if ($b['status'] === 'accepted'): ?>
                        <a href="payment.php?ref=<?= h(bookingRef($b['booking_id'])) ?>" class="btn-sm btn-sm-primary">Pay your host</a>
                    <?php endif; ?>
                    <?php if (in_array($b['status'], ['pending', 'accepted', 'payment_pending', 'confirmed'], true)): ?>
                        <a href="confirmation.php?ref=<?= h(bookingRef($b['booking_id'])) ?>" class="btn-sm btn-sm-outline">View details</a>
                        <?php if ($b['check_in_date'] > date('Y-m-d')): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('<?= isLateCancellation($b['check_in_date']) ? 'Cancel this booking? It is closer than ' . (int)freeCancelHours() . ' hours to check-in, so any refund is up to your host. This cannot be undone.' : 'Cancel this booking? This cannot be undone.' ?>');">
                            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="cancel_booking">
                            <input type="hidden" name="booking_ref" value="<?= h(bookingRef($b['booking_id'])) ?>">
                            <button type="submit" class="btn-sm btn-sm-danger">Cancel booking</button>
                        </form>
                        <?php endif; ?>
                        <a href="../messages.php?accommodation_id=<?= (int)$b['accommodation_id'] ?>" class="btn-sm btn-sm-outline">Message host</a>
                    <?php endif; ?>

                    <?php if ($b['status'] === 'completed'): ?>
                        <a href="../contact.html"
                           class="btn-sm btn-sm-outline">
                            Report
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>

<script>(function(){var f=document.querySelector('.bk-focus');if(f)f.scrollIntoView({block:'center',behavior:'smooth'});})();</script>
</body>
</html>