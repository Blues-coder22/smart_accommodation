<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('tourist');

$tab = sanitize($_GET['tab'] ?? 'upcoming');

// Get all bookings for this tourist
$stmt = $pdo->prepare("
    SELECT b.*,
           a.title, a.location_name, a.price_per_night,
           p.payment_method, p.payment_status,
           (SELECT photo_path FROM accommodation_photos
            WHERE accommodation_id = a.accommodation_id
            AND is_primary = 1 LIMIT 1) AS primary_photo,
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
    if ($b['status'] === 'cancelled') {
        $cancelled[] = $b;
    } elseif ($b['check_out_date'] < $today 
              || $b['status'] === 'completed') {
        // Auto mark as completed if checkout passed
        if ($b['status'] !== 'completed') {
            $pdo->prepare("
                UPDATE bookings SET status = 'completed'
                WHERE booking_id = ?
            ")->execute([$b['booking_id']]);
            $b['status'] = 'completed';
        }
        $completed[] = $b;
    } else {
        $upcoming[] = $b;
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
    <title>My Bookings — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .page-wrap  { max-width:900px; margin:0 auto; padding:32px 24px }
        .page-tag   { font-size:11px; font-weight:600; letter-spacing:.06em;
                      color:#1D9E75; text-transform:uppercase;
                      margin-bottom:8px }
        .page-title { font-size:28px; font-weight:400; margin-bottom:4px }
        .page-sub   { font-size:14px; color:var(--text-secondary);
                      margin-bottom:24px }
        .tabs       { display:flex; gap:0; border-bottom:0.5px solid var(--border);
                      margin-bottom:24px }
        .tab-btn    { padding:10px 20px; font-size:13px; font-weight:500;
                      color:var(--text-secondary); border:none; background:none;
                      cursor:pointer; border-bottom:2px solid transparent;
                      margin-bottom:-1px; text-decoration:none;
                      display:inline-block }
        .tab-btn.active { color:#085041;
                          border-bottom-color:#085041 }
        .tab-count  { font-size:11px; background:var(--surface-1);
                      border:0.5px solid var(--border); border-radius:10px;
                      padding:1px 7px; margin-left:6px;
                      color:var(--text-secondary) }
        .booking-row { background:var(--surface-2);
                       border:0.5px solid var(--border);
                       border-radius:8px; display:grid;
                       grid-template-columns:80px 1fr auto;
                       gap:16px; align-items:center;
                       padding:16px; margin-bottom:10px;
                       transition:border-color .15s }
        .booking-row:hover { border-color:var(--border-strong) }
        .booking-thumb { width:80px; height:64px; border-radius:6px;
                         background:#E1F5EE; overflow:hidden;
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
        .status-badge  { display:inline-block; font-size:11px;
                         font-weight:600; padding:3px 8px;
                         border-radius:3px }
        .s-confirmed   { background:#E1F5EE; color:#0F6E56;
                         border:0.5px solid #9FE1CB }
        .s-pending     { background:#FFF8E1; color:#9A6A00;
                         border:0.5px solid #F9D57C }
        .s-completed   { background:#F0F4FF; color:#1A4DB3;
                         border:0.5px solid #B3C5F5 }
        .s-cancelled   { background:#FEF2F2; color:#991B1B;
                         border:0.5px solid #FECACA }
        .s-no-show     { background:#FDF2F8; color:#86198F;
                         border:0.5px solid #F0ABFC }
        .row-actions   { display:flex; gap:8px; margin-top:10px;
                         justify-content:flex-end }
        .btn-sm        { padding:6px 12px; font-size:12px;
                         border-radius:4px; cursor:pointer;
                         font-weight:500; text-decoration:none;
                         display:inline-block; border:0.5px solid var(--border) }
        .btn-sm-primary{ background:#085041; color:#fff; border-color:#085041 }
        .btn-sm-outline{ background:none; color:var(--text-secondary) }
        .empty-state   { text-align:center; padding:60px 20px }
        .empty-state h3{ font-size:16px; font-weight:500;
                         margin-bottom:8px; color:var(--text-primary) }
        .empty-state p { font-size:14px; color:var(--text-secondary);
                         margin-bottom:20px }
    </style>
</head>
<body>

<?php include '../includes/header.php'; ?>

<div class="page-wrap">

    <div class="page-tag">Your journeys</div>
    <h1 class="page-title">My bookings</h1>
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
        <?php foreach ($current as $b): ?>
        <div class="booking-row">

            <!-- Thumbnail -->
            <div class="booking-thumb">
                <?php if ($b['primary_photo']): ?>
                    <img src="../uploads/accommodations/<?= htmlspecialchars($b['primary_photo']) ?>"
                         alt="<?= htmlspecialchars($b['title']) ?>">
                <?php else: ?>
                    &#127968;
                <?php endif; ?>
            </div>

            <!-- Details -->
            <div>
                <div class="booking-title">
                    <?= htmlspecialchars($b['title']) ?>
                </div>
                <div class="booking-meta">
                    <?= htmlspecialchars($b['location_name']) ?>
                </div>
                <div class="booking-dates">
                    <?= date('d M', strtotime($b['check_in_date'])) ?>
                    &ndash;
                    <?= date('d M Y', strtotime($b['check_out_date'])) ?>
                    &middot;
                    <?= $b['num_guests'] ?> guest<?= $b['num_guests']>1?'s':'' ?>
                </div>
            </div>

            <!-- Right -->
            <div class="booking-right">
                <div class="booking-amount">
                    KES <?= number_format($b['total_amount']) ?>
                </div>
                <div class="booking-ref">
                    #BK<?= str_pad($b['booking_id'], 5, '0', STR_PAD_LEFT) ?>
                </div>

                <!-- Status badge -->
                <?php
                $badge_class = [
                    'confirmed' => 's-confirmed',
                    'pending'   => 's-pending',
                    'completed' => 's-completed',
                    'cancelled' => 's-cancelled',
                    'no_show'   => 's-no-show'
                ][$b['status']] ?? 's-pending';

                $badge_label = [
                    'confirmed' => 'Confirmed',
                    'pending'   => 'Pending',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                    'no_show'   => 'No show'
                ][$b['status']] ?? ucfirst($b['status']);
                ?>
                <div>
                    <span class="status-badge <?= $badge_class ?>">
                        <?= $badge_label ?>
                    </span>
                </div>

                <!-- Actions -->
                <div class="row-actions">
                    <?php if ($b['status'] === 'completed'
                              && !$b['has_review']): ?>
                        <a href="review.php?booking_id=<?= $b['booking_id'] ?>"
                           class="btn-sm btn-sm-primary">
                            Leave review
                        </a>
                    <?php endif; ?>

                    <?php if ($b['status'] === 'confirmed'
                              || $b['status'] === 'pending'): ?>
                        <a href="confirmation.php?booking_id=<?= $b['booking_id'] ?>"
                           class="btn-sm btn-sm-outline">
                            View details
                        </a>
                    <?php endif; ?>

                    <?php if ($b['status'] === 'completed'): ?>
                        <a href="report.php?booking_id=<?= $b['booking_id'] ?>"
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

</body>
</html>