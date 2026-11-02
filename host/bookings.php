<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
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
    $booking_id = (int)($_POST['booking_id'] ?? 0);
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
            case 'confirm':
                if ($booking['status'] === 'pending') {
                    $pay = $pdo->prepare("SELECT payment_id, payment_status FROM payments WHERE booking_id = ? ORDER BY payment_id DESC LIMIT 1");
                    $pay->execute([$booking_id]);
                    $payment = $pay->fetch();
                    if (!$payment) {
                        setFlash('error', 'The tourist has not submitted payment details yet.');
                    } else {
                        $pdo->beginTransaction();
                        $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE booking_id = ? AND status = 'pending'")->execute([$booking_id]);
                        // Booking acceptance and payment verification are separate decisions.
                        // The payment remains pending until the host verifies it in Payments.
                        $pdo->commit();
                        sendEmail($booking['tourist_email'], 'Booking confirmed — Smart Accommodation Kenya', '<p>Your host has confirmed booking #BK' . str_pad((string)$booking_id, 5, '0', STR_PAD_LEFT) . ' for <strong>' . h($booking['title'], ENT_QUOTES, 'UTF-8') . '</strong>.</p>');
                        setFlash('success', 'Booking confirmed. The tourist has been notified.');
                    }
                }
                break;

            case 'decline':
                if ($booking['status'] === 'pending') {
                    $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE booking_id = ? AND status = 'pending'")->execute([$booking_id]);
                    sendEmail($booking['tourist_email'], 'Booking request not accepted — Smart Accommodation Kenya', '<p>Your host could not accept booking #BK' . str_pad((string)$booking_id, 5, '0', STR_PAD_LEFT) . ' for <strong>' . h($booking['title'], ENT_QUOTES, 'UTF-8') . '</strong>. Please choose another property or contact support about any payment details you submitted.</p>');
                    setFlash('rejected', 'Booking declined. The tourist has been notified.');
                }
                break;

            case 'cancel':
                if (in_array($booking['status'],
                    ['pending', 'confirmed'])) {
                    $pdo->prepare("
                        UPDATE bookings SET status = 'cancelled'
                        WHERE booking_id = ?
                    ")->execute([$booking_id]);

                    // Notify tourist
                    sendEmail(
                        $booking['tourist_email'],
                        'Booking Cancelled — Smart Accommodation Kenya',
                        "
                        <h2>Booking Cancelled</h2>
                        <p>Dear {$booking['tourist_name']},</p>
                        <p>Your booking for <strong>
                           {$booking['title']}</strong> has been
                           cancelled by the host.</p>
                        <p>Booking reference:
                           #BK" . str_pad($booking_id, 5, '0',
                               STR_PAD_LEFT) . "</p>
                        <p>Please contact us if you have any questions.
                        </p>
                        "
                    );
                    setFlash('success', 'Booking cancelled.');
                }
                break;

            case 'no_show':
                if ($booking['status'] === 'confirmed' && $booking['check_in_date'] > date('Y-m-d')) {
                    setFlash('warning', 'A no-show can only be recorded from the check-in day (' . date('d M Y', strtotime($booking['check_in_date'])) . ') onwards.');
                } elseif ($booking['status'] === 'confirmed') {
                    logBookingEvent($pdo, $booking_id, $host_id, 'no_show', 'Host recorded the guest as a no-show');
                    $pdo->prepare("
                        UPDATE bookings SET status = 'no_show'
                        WHERE booking_id = ?
                    ")->execute([$booking_id]);
                    setFlash('success',
                        'No-show recorded for this booking.');
                }
                break;

            case 'complete':
                if ($booking['status'] === 'confirmed') {
                    $early = $booking['check_out_date'] > date('Y-m-d');
                    $reason = trim((string)($_POST['early_reason'] ?? ''));
                    if ($early && mb_strlen($reason) < 5) {
                        setFlash('error', 'The check-out date has not arrived yet, so please say why the stay is being completed early (for example: the guest left early).');
                        break;
                    }
                    $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE booking_id = ?")->execute([$booking_id]);
                    if ($early) {
                        // Allowed, but recorded: the administrator can see it and the guest is told so they can object.
                        logBookingEvent($pdo, $booking_id, $host_id, 'early_completion', $reason);
                        try {
                            sendEmail($booking['tourist_email'], 'Your stay was marked as completed early — Smart Accommodation Kenya',
                                '<p>Your host marked your stay at <strong>' . h($booking['title']) . '</strong> as completed before the check-out date (' . h($booking['check_out_date']) . ').</p><p>Reason given: ' . h($reason) . '</p><p>If this is not correct, please report it to the administrator through the Contact page.</p>');
                        } catch (Throwable $e) {}
                        setFlash('success', 'Booking marked as completed early. Your reason was recorded and the guest was told.');
                    } else {
                        setFlash('success', 'Booking marked as completed.');
                    }
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
                $admin = $pdo->query("
                    SELECT email FROM users
                    WHERE role = 'admin' LIMIT 1
                ")->fetch();

                if ($admin) {
                    sendEmail(
                        $admin['email'],
                        'Tourist Flagged by Host — Review Required',
                        "
                        <h2>Tourist flagged by host</h2>
                        <p><strong>Tourist:</strong>
                           {$booking['tourist_name']}</p>
                        <p><strong>Booking:</strong> #BK" .
                           str_pad($booking_id, 5, '0',
                               STR_PAD_LEFT) . "</p>
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

// Build query based on tab
$where = "a.host_id = ?";
$params = [$host_id];

if ($tab === 'pending') {
    $where .= " AND b.status = 'pending'";
} elseif ($tab === 'confirmed') {
    $where .= " AND b.status = 'confirmed'";
} elseif ($tab === 'completed') {
    $where .= " AND b.status = 'completed'";
} elseif ($tab === 'cancelled') {
    $where .= " AND b.status IN ('cancelled','no_show')";
}

$bookings = $pdo->prepare("
    SELECT b.*,
           a.title AS property_title,
           u.full_name AS tourist_name,
           u.phone_number AS tourist_phone,
           (SELECT g.rating FROM guest_reviews g WHERE g.booking_id = b.booking_id) AS my_guest_rating,
           (SELECT p.payment_status FROM payments p WHERE p.booking_id=b.booking_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_status,
           (SELECT p.payment_method FROM payments p WHERE p.booking_id=b.booking_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_method,
           (SELECT p.payment_reference FROM payments p WHERE p.booking_id=b.booking_id ORDER BY p.payment_id DESC LIMIT 1) AS payment_reference
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users u ON u.user_id = b.tourist_id
    WHERE $where
    ORDER BY b.created_at DESC
");
$bookings->execute($params);
$bookings = $bookings->fetchAll();

// Tab counts
$counts = $pdo->prepare("
    SELECT
        COUNT(*) AS all_count,
        SUM(CASE WHEN b.status='pending' THEN 1 ELSE 0 END) AS pending,
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
    <title>Bookings — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        
        .dash-wrap  { display:grid;
                      grid-template-columns:200px 1fr; flex:1 }
        .sidebar    { background:var(--surface-2);
                      border-right:1px solid var(--border);
                      padding:24px 0; position:sticky;
                      top:52px; height:calc(100vh - 52px);
                      overflow-y:auto }
        .sidebar-role { font-size:10px; font-weight:700;
                        letter-spacing:.08em; color:#1D9E75;
                        text-transform:uppercase;
                        padding:0 20px; margin-bottom:12px }
        .sidebar-link { display:flex; align-items:center; gap:10px;
                        padding:9px 20px; font-size:13px;
                        color:var(--text-secondary);
                        text-decoration:none; width:100% }
        .sidebar-link:hover { color:var(--text-primary);
                              background:var(--surface-1) }
        .sidebar-link.active { color:#085041; font-weight:500;
                               background:#E1F5EE;
                               border-left:3px solid #1D9E75 }
        .badge      { font-size:10px; background:#DC2626;
                      color:#fff; border-radius:10px;
                      padding:1px 6px; margin-left:auto }
        .main       { padding:28px }
        .page-tag   { font-size:11px; font-weight:600;
                      letter-spacing:.06em; color:#1D9E75;
                      text-transform:uppercase; margin-bottom:8px }
        .page-title { font-size:26px; font-weight:400;
                      margin-bottom:20px }
        .tabs       { display:flex; border-bottom:1px solid
                      var(--border); margin-bottom:20px }
        .tab-btn    { padding:10px 16px; font-size:13px;
                      font-weight:500; color:var(--text-secondary);
                      border:none; background:none; cursor:pointer;
                      border-bottom:2px solid transparent;
                      margin-bottom:-1px; text-decoration:none;
                      display:inline-flex; align-items:center;
                      gap:6px }
        .tab-btn.active { color:#085041;
                          border-bottom-color:#085041 }
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
        .s-confirmed  { background:#E1F5EE; color:#0F6E56;
                        border:1px solid #9FE1CB }
        .s-pending    { background:#FFF8E1; color:#9A6A00;
                        border:1px solid #F9D57C }
        .s-completed  { background:#F0F4FF; color:#1A4DB3;
                        border:1px solid #B3C5F5 }
        .s-cancelled  { background:#FEF2F2; color:#991B1B;
                        border:1px solid #FECACA }
        .s-no_show    { background:#FDF2F8; color:#86198F;
                        border:1px solid #F0ABFC }
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
        .btn-sm:hover { border-color:#1D9E75; color:#1D9E75 }
        .btn-sm-danger { border-color:#FECACA; color:#DC2626 }
        .btn-sm-danger:hover { background:#FEF2F2 }
        .pay-confirmed { color:#0F6E56; font-weight:500 }
        .pay-pending   { color:#D97706 }
        .flag-form     { display:flex; gap:6px; margin-top:8px;
                         flex-wrap:wrap }
        .flag-form input { flex:1; padding:6px 10px;
                           border:1px solid var(--border-strong);
                           border-radius:4px; font-size:12px;
                           min-width:180px;
                           background:var(--surface-2) }
        .empty-state   { text-align:center; padding:48px 20px;
                         color:var(--text-secondary); font-size:14px }
        @media(max-width:900px){
            .dash-wrap { grid-template-columns:1fr }
            .sidebar   { display:none }
            .booking-details { grid-template-columns:repeat(2,1fr) }
        }
    </style>
</head>
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
                Pending
                <span class="tab-count"><?= $counts['pending'] ?></span>
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
                No <?= $tab === 'all' ? '' : $tab ?> bookings found.
            </div>

        <?php else: ?>
            <?php foreach ($bookings as $b): ?>
            <div class="booking-card">

                <!-- Top row -->
                <div class="booking-top">
                    <div>
                        <div class="booking-prop">
                            <?= h($b['property_title']) ?>
                        </div>
                        <div class="booking-ref">
                            #BK<?= str_pad($b['booking_id'], 5,
                                '0', STR_PAD_LEFT) ?>
                            &middot; Booked
                            <?= date('d M Y',
                                strtotime($b['created_at'])) ?>
                        </div>
                    </div>
                    <?php
                    $pill = [
                        'confirmed' => 's-confirmed',
                        'pending'   => 's-pending',
                        'completed' => 's-completed',
                        'cancelled' => 's-cancelled',
                        'no_show'   => 's-no_show'
                    ][$b['status']] ?? 's-pending';

                    $label = [
                        'confirmed' => 'Confirmed',
                        'pending'   => 'Pending',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                        'no_show'   => 'No show'
                    ][$b['status']] ?? ucfirst($b['status']);
                    ?>
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
                            <?php $rep = getGuestReputation($pdo, $b['tourist_id']); ?>
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
                            KES <?= number_format($b['total_amount']) ?>
                            <div class="<?= $b['payment_status'] === 'confirmed'
                                ? 'pay-confirmed' : 'pay-pending' ?>"
                                 style="font-size:11px;font-weight:400">
                                <?= $b['payment_status'] === 'confirmed'
                                    ? 'Payment details reviewed'
                                    : ($b['payment_status'] === 'pending' ? 'Payment details submitted — review needed' : 'Awaiting payment details') ?>
                                <?php if (!empty($b['payment_reference'])): ?><div>Reference: <?= h($b['payment_reference'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="booking-actions">
                    <a class="btn-sm" href="messages.php?accommodation_id=<?= (int)$b['accommodation_id'] ?>&amp;tourist_id=<?= (int)$b['tourist_id'] ?>">Message tourist</a>
                    <?php if ($b['status'] === 'completed'): ?>
                        <?php if ($b['my_guest_rating'] !== null): ?>
                            <span class="btn-sm" style="cursor:default">You rated this guest &#9733; <?= (int)$b['my_guest_rating'] ?></span>
                        <?php else: ?>
                            <a class="btn-sm" href="rate_guest.php?booking_id=<?= (int)$b['booking_id'] ?>">Rate guest</a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($b['status'] === 'pending'): ?>
                        <form method="POST" style="margin:0">
                            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="booking_id" value="<?= (int)$b['booking_id'] ?>">
                            <input type="hidden" name="action" value="confirm">
                            <button type="submit" class="btn-sm" <?= empty($b['payment_status']) ? 'disabled title="Waiting for the tourist to submit payment details"' : '' ?> onclick="return confirm('Confirm this booking and mark the submitted payment details as reviewed?')">Confirm booking</button>
                        </form>
                        <form method="POST" style="margin:0">
                            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="booking_id" value="<?= (int)$b['booking_id'] ?>">
                            <input type="hidden" name="action" value="decline">
                            <button type="submit" class="btn-sm btn-sm-danger" onclick="return confirm('Decline this booking request? The tourist will be notified.')">Decline booking</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($b['status'] === 'confirmed'): ?>
                        <form method="POST" style="margin:0">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="booking_id"
                                   value="<?= $b['booking_id'] ?>">
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
                            <input type="hidden" name="booking_id"
                                   value="<?= $b['booking_id'] ?>">
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
                        ['pending','confirmed'])): ?>
                        <form method="POST" style="margin:0">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="booking_id"
                                   value="<?= $b['booking_id'] ?>">
                            <input type="hidden" name="action"
                                   value="cancel">
                            <button type="submit"
                                    class="btn-sm btn-sm-danger"
                                    onclick="return confirm(
                                        'Cancel this booking? The guest will be notified.')">
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
                        <input type="hidden" name="booking_id"
                               value="<?= $b['booking_id'] ?>">
                        <input type="hidden" name="action"
                               value="flag_tourist">
                        <input type="text" name="flag_reason"
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
        <?php endif; ?>

    <?php appShellEnd(); ?>

<script>
function toggleFlagForm(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none'
        ? 'block' : 'none';
}
</script>

</body>
</html>