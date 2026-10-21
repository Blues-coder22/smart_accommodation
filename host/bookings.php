<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/email.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$host_id = $_SESSION['user_id'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                if ($booking['status'] === 'confirmed') {
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
                    $pdo->prepare("
                        UPDATE bookings SET status = 'completed'
                        WHERE booking_id = ?
                    ")->execute([$booking_id]);
                    setFlash('success',
                        'Booking marked as completed.');
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
           p.payment_status, p.payment_method
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users u ON u.user_id = b.tourist_id
    LEFT JOIN payments p ON p.booking_id = b.booking_id
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
        body        { display:flex; flex-direction:column;
                      min-height:100vh }
        .dash-wrap  { display:grid;
                      grid-template-columns:200px 1fr; flex:1 }
        .sidebar    { background:var(--surface-2);
                      border-right:0.5px solid var(--border);
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
        .tabs       { display:flex; border-bottom:0.5px solid
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
                      border:0.5px solid var(--border);
                      border-radius:10px; padding:1px 7px }
        .booking-card { background:var(--surface-2);
                        border:0.5px solid var(--border);
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
                        border:0.5px solid #9FE1CB }
        .s-pending    { background:#FFF8E1; color:#9A6A00;
                        border:0.5px solid #F9D57C }
        .s-completed  { background:#F0F4FF; color:#1A4DB3;
                        border:0.5px solid #B3C5F5 }
        .s-cancelled  { background:#FEF2F2; color:#991B1B;
                        border:0.5px solid #FECACA }
        .s-no_show    { background:#FDF2F8; color:#86198F;
                        border:0.5px solid #F0ABFC }
        .booking-details { display:grid;
                           grid-template-columns:repeat(4,1fr);
                           gap:12px; padding:12px 0;
                           border-top:0.5px solid var(--border);
                           border-bottom:0.5px solid var(--border);
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
                      border:0.5px solid var(--border-strong);
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
                           border:0.5px solid var(--border-strong);
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

<?php include '../includes/header.php'; ?>

<div class="dash-wrap">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-role">Host</div>
        <a href="dashboard.php" class="sidebar-link">Overview</a>
        <a href="listings.php" class="sidebar-link">Listings</a>
        <a href="availability.php" class="sidebar-link">Availability</a>
        <a href="bookings.php" class="sidebar-link active">
            Bookings
            <?php if ($counts['pending'] > 0): ?>
                <span class="badge"><?= $counts['pending'] ?></span>
            <?php endif; ?>
        </a>
        <a href="payments.php" class="sidebar-link">Payments</a>
        <a href="reviews.php" class="sidebar-link">Reviews</a>
        <a href="report.php" class="sidebar-link">Reports</a>
        <a href="profile.php" class="sidebar-link">Profile</a>
    </aside>

    <main class="main">

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
                            <?= htmlspecialchars($b['property_title']) ?>
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
                            <?= htmlspecialchars($b['tourist_name']) ?>
                            <?php if ($b['tourist_phone']): ?>
                            <div style="font-size:11px;
                                        font-weight:400;
                                        color:var(--text-secondary)">
                                <?= htmlspecialchars(
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
                                    ? 'Payment confirmed'
                                    : 'Awaiting payment' ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="booking-actions">

                    <?php if ($b['status'] === 'confirmed'): ?>
                        <form method="POST" style="margin:0">
                            <input type="hidden" name="booking_id"
                                   value="<?= $b['booking_id'] ?>">
                            <input type="hidden" name="action"
                                   value="complete">
                            <button type="submit" class="btn-sm"
                                    onclick="return confirm(
                                        'Mark this booking as completed?')">
                                Mark completed
                            </button>
                        </form>

                        <form method="POST" style="margin:0">
                            <input type="hidden" name="booking_id"
                                   value="<?= $b['booking_id'] ?>">
                            <input type="hidden" name="action"
                                   value="no_show">
                            <button type="submit" class="btn-sm"
                                    onclick="return confirm(
                                        'Record this guest as a no-show?')">
                                Record no-show
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if (in_array($b['status'],
                        ['pending','confirmed'])): ?>
                        <form method="POST" style="margin:0">
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
                            border-top:0.5px solid var(--border)">
                    <form method="POST" class="flag-form">
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

    </main>
</div>

<?php include '../includes/footer.php'; ?>

<script>
function toggleFlagForm(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none'
        ? 'block' : 'none';
}
</script>

</body>
</html>