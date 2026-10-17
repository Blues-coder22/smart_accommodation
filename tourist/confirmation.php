<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('tourist');

$booking_id = (int)($_GET['booking_id'] ?? 0);

if (!$booking_id) {
    redirect('bookings.php');
}

// Get full booking details
$stmt = $pdo->prepare("
    SELECT b.*,
           a.title, a.location_name, a.price_per_night,
           a.latitude, a.longitude,
           u.full_name AS host_name,
           u.phone_number AS host_phone,
           p.payment_method, p.payment_status,
           (SELECT photo_path FROM accommodation_photos
            WHERE accommodation_id = a.accommodation_id
            AND is_primary = 1 LIMIT 1) AS primary_photo
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users u ON u.user_id = a.host_id
    LEFT JOIN payments p ON p.booking_id = b.booking_id
    WHERE b.booking_id = ?
    AND b.tourist_id = ?
");
$stmt->execute([$booking_id, $_SESSION['user_id']]);
$booking = $stmt->fetch();

if (!$booking) {
    redirect('bookings.php');
}

// Calculate nights
$check_in  = new DateTime($booking['check_in_date']);
$check_out = new DateTime($booking['check_out_date']);
$nights    = $check_in->diff($check_out)->days;

// Format payment method
$method_labels = [
    'cash'          => 'Pay on arrival (cash)',
    'bank_transfer' => 'Bank transfer',
    'mpesa'         => 'M-Pesa (sent manually)'
];
$method_label = $method_labels[$booking['payment_method']] ?? 'Not specified';

// Get nearby attractions
$nearby = getNearbyAttractions(
    $booking['latitude'],
    $booking['longitude'],
    10
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .page-wrap   { max-width:760px; margin:0 auto; padding:32px 24px }
        .confirm-hero { text-align:center; padding:32px 0 24px }
        .confirm-icon { width:56px; height:56px; background:#E1F5EE;
                        border-radius:50%; display:flex; align-items:center;
                        justify-content:center; margin:0 auto 16px;
                        border:0.5px solid #9FE1CB }
        .confirm-icon svg { width:24px; height:24px; stroke:#1D9E75 }
        .confirm-hero h1 { font-size:24px; font-weight:500;
                           color:#085041; margin-bottom:6px }
        .confirm-hero p  { font-size:14px; color:var(--text-secondary) }
        .ref-tag { display:inline-block; font-size:13px; font-weight:600;
                   color:#085041; background:#E1F5EE;
                   border:0.5px solid #9FE1CB;
                   padding:6px 14px; border-radius:4px; margin-top:12px }
        .detail-card { background:var(--surface-2);
                       border:0.5px solid var(--border);
                       border-radius:8px; overflow:hidden;
                       margin-bottom:16px }
        .detail-card-img { height:180px; background:#E1F5EE;
                           display:flex; align-items:center;
                           justify-content:center; font-size:40px }
        .detail-card-img img { width:100%; height:100%; object-fit:cover }
        .detail-card-body { padding:20px }
        .detail-card-body h2 { font-size:18px; font-weight:500;
                               margin-bottom:4px }
        .detail-card-body .loc { font-size:13px; color:var(--text-secondary);
                                 margin-bottom:16px }
        .detail-row { display:flex; justify-content:space-between;
                      padding:10px 0; border-bottom:0.5px solid var(--border);
                      font-size:14px }
        .detail-row:last-child { border-bottom:none }
        .detail-label { color:var(--text-secondary) }
        .detail-value { font-weight:500 }
        .payment-status { display:inline-flex; align-items:center; gap:6px;
                          font-size:12px; font-weight:600;
                          padding:3px 10px; border-radius:3px }
        .status-confirmed { background:#E1F5EE; color:#0F6E56;
                            border:0.5px solid #9FE1CB }
        .what-next { background:var(--surface-2);
                     border:0.5px solid var(--border);
                     border-radius:8px; padding:20px;
                     margin-bottom:16px }
        .what-next h3 { font-size:14px; font-weight:500;
                        margin-bottom:14px }
        .next-item { display:flex; gap:12px; padding:10px 0;
                     border-bottom:0.5px solid var(--border);
                     font-size:13px }
        .next-item:last-child { border-bottom:none }
        .next-num  { width:22px; height:22px; border-radius:50%;
                     background:#1D9E75; color:#fff; font-size:11px;
                     font-weight:600; display:flex; align-items:center;
                     justify-content:center; flex-shrink:0; margin-top:1px }
        .next-text strong { display:block; margin-bottom:2px }
        .next-text span   { color:var(--text-secondary) }
        .nearby-section { margin-bottom:16px }
        .nearby-section h3 { font-size:14px; font-weight:500;
                             margin-bottom:12px }
        .nearby-item { display:flex; justify-content:space-between;
                       align-items:center; padding:10px 0;
                       border-bottom:0.5px solid var(--border);
                       font-size:13px }
        .nearby-item:last-child { border-bottom:none }
        .nearby-name  { font-weight:500 }
        .nearby-meta  { font-size:12px; color:var(--text-secondary);
                        margin-top:2px }
        .nearby-dist  { font-size:12px; color:#1D9E75; font-weight:500 }
        .actions-row  { display:flex; gap:10px; margin-top:24px }
        .actions-row .btn { flex:1; text-align:center; padding:11px;
                            border-radius:6px; font-size:14px;
                            font-weight:500; cursor:pointer;
                            text-decoration:none }
        .btn-primary-dark { background:#085041; color:#fff; border:none }
        .btn-secondary { background:none; color:var(--text-primary);
                         border:0.5px solid var(--border-strong) }
    </style>
</head>
<body class="tourist-confirmation">

<?php include '../includes/header.php'; ?>

<div class="page-wrap">

    <!-- Confirmation Hero -->
    <div class="confirm-hero">
        <div class="confirm-icon">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M4.5 12.75l6 6 9-13.5"/>
            </svg>
        </div>
        <h1>Your booking is confirmed.</h1>
        <p>
            A confirmation has been sent to your email address.
        </p>
        <div class="ref-tag">
            Booking #BK<?= str_pad($booking_id, 5, '0', STR_PAD_LEFT) ?>
        </div>
    </div>

    <!-- Booking Details -->
    <div class="detail-card">
        <div class="detail-card-img">
            <?php if ($booking['primary_photo']): ?>
                <img src="../uploads/accommodations/<?= htmlspecialchars($booking['primary_photo']) ?>"
                     alt="<?= htmlspecialchars($booking['title']) ?>">
            <?php else: ?>
                &#127968;
            <?php endif; ?>
        </div>
        <div class="detail-card-body">
            <h2><?= htmlspecialchars($booking['title']) ?></h2>
            <div class="loc">
                <?= htmlspecialchars($booking['location_name']) ?>
            </div>

            <div class="detail-row">
                <span class="detail-label">Check-in</span>
                <span class="detail-value">
                    <?= date('D, d M Y', strtotime($booking['check_in_date'])) ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Check-out</span>
                <span class="detail-value">
                    <?= date('D, d M Y', strtotime($booking['check_out_date'])) ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Duration</span>
                <span class="detail-value">
                    <?= $nights ?> night<?= $nights > 1 ? 's' : '' ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Guests</span>
                <span class="detail-value">
                    <?= $booking['num_guests'] ?>
                    guest<?= $booking['num_guests'] > 1 ? 's' : '' ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Host</span>
                <span class="detail-value">
                    <?= htmlspecialchars($booking['host_name']) ?>
                    <?php if ($booking['host_phone']): ?>
                        <span style="font-size:12px;
                                     color:var(--text-secondary);
                                     font-weight:400">
                            &nbsp;&middot;&nbsp;
                            <?= htmlspecialchars($booking['host_phone']) ?>
                        </span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Total paid</span>
                <span class="detail-value">
                    KES <?= number_format($booking['total_amount']) ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Payment method</span>
                <span class="detail-value">
                    <?= htmlspecialchars($method_label) ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Payment status</span>
                <span class="detail-value">
                    <span class="payment-status status-confirmed">
                        Confirmed
                    </span>
                </span>
            </div>
        </div>
    </div>

    <!-- What Happens Next -->
    <div class="what-next">
        <h3>What happens next</h3>
        <div class="next-item">
            <div class="next-num">1</div>
            <div class="next-text">
                <strong>Your host has been notified</strong>
                <span>
                    <?= htmlspecialchars($booking['host_name']) ?>
                    has received your booking details by email.
                </span>
            </div>
        </div>
        <div class="next-item">
            <div class="next-num">2</div>
            <div class="next-text">
                <strong>Complete your payment</strong>
                <span>
                    Pay <?= htmlspecialchars($booking['host_name']) ?>
                    via <?= htmlspecialchars($method_label) ?>
                    before your check-in date.
                </span>
            </div>
        </div>
        <div class="next-item">
            <div class="next-num">3</div>
            <div class="next-text">
                <strong>Check in on
                    <?= date('d M Y',
                        strtotime($booking['check_in_date'])) ?>
                </strong>
                <span>
                    Your host will be expecting you.
                    Contact them if you need directions.
                </span>
            </div>
        </div>
        <div class="next-item">
            <div class="next-num">4</div>
            <div class="next-text">
                <strong>Leave a review after your stay</strong>
                <span>
                    After check-out you will be able to leave
                    a verified review from your bookings page.
                </span>
            </div>
        </div>
    </div>

    <!-- Nearby Attractions -->
    <?php if (!empty($nearby)): ?>
    <div class="detail-card">
        <div class="detail-card-body">
            <h3 style="font-size:14px;font-weight:500;margin-bottom:14px">
                Attractions near your stay
            </h3>
            <?php foreach (array_slice($nearby, 0, 5) as $att): ?>
            <div class="nearby-item">
                <div>
                    <div class="nearby-name">
                        <?= htmlspecialchars($att['name']) ?>
                    </div>
                    <div class="nearby-meta">
                        <?= htmlspecialchars($att['category']) ?>
                        &nbsp;&middot;&nbsp;
                        <?= $att['entry_fee'] > 0
                            ? 'KES ' . number_format($att['entry_fee'])
                            : 'Free entry' ?>
                    </div>
                </div>
                <div class="nearby-dist">
                    <?= number_format($att['distance'], 1) ?> km
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Action Buttons -->
    <div class="actions-row">
        <a href="bookings.php" class="btn btn-primary-dark">
            View my bookings
        </a>
        <a href="../index.php" class="btn btn-secondary">
            Back to home
        </a>
    </div>

</div>

<?php include '../includes/footer.php'; ?>

</body>
</html>