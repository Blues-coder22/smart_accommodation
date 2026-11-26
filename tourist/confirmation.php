<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/booking_rules.php';
ensureCommissionTables($pdo);
require_once '../includes/onsite_experience.php';
require_once '../includes/booking_countdown.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('tourist');

$booking_id = bookingIdFromRef($_GET['ref'] ?? '');

if (!$booking_id) {
    redirect('bookings.php');
}

ensureOnsiteExperienceColumns($pdo);
// Get full booking details
$stmt = $pdo->prepare("
    SELECT b.*,
           a.title, a.location_name, a.price_per_night,
           a.onsite_experience_name,
           a.latitude, a.longitude,
           u.full_name AS host_name,
           u.phone_number AS host_phone,
           (SELECT pp.platform_payment_id FROM platform_payments pp WHERE pp.booking_id = b.booking_id AND pp.status = 'confirmed' LIMIT 1) AS pp_id,
           p.payment_id, p.payment_method, p.payment_status,
           (SELECT photo_path FROM accommodation_photos
            WHERE accommodation_id = a.accommodation_id
            ORDER BY is_primary DESC, photo_id ASC LIMIT 1) AS primary_photo
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
$br        = bookingPriceBreakdown($booking);

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
    <title>Booking Request — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <script src="../assets/js/leaflet-loader.js"></script>
    <style>
        
        .confirm-hero { text-align:center; padding:32px 0 24px }
        .confirm-icon { width:56px; height:56px; background:var(--accent-soft);
                        border-radius:50%; display:flex; align-items:center;
                        justify-content:center; margin:0 auto 16px;
                        border:1px solid var(--accent-line) }
        .confirm-icon svg { width:24px; height:24px; stroke:#126b4e }
        .confirm-hero h1 { font-size:24px; font-weight:500;
                           color:var(--accent-strong); margin-bottom:6px }
        .confirm-hero p  { font-size:14px; color:var(--text-secondary) }
        .ref-tag { display:inline-block; font-size:13px; font-weight:600;
                   color:var(--accent-strong); background:var(--accent-soft);
                   border:1px solid var(--accent-line);
                   padding:6px 14px; border-radius:4px; margin-top:12px }
        .detail-card { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; overflow:hidden;
                       margin-bottom:16px }
        .detail-card-img { height:180px; background:var(--accent-soft);
                           display:flex; align-items:center;
                           justify-content:center; font-size:40px }
        .detail-card-img img { width:100%; height:100%; object-fit:cover }
        .detail-card-body { padding:20px }
        .detail-card-body h2 { font-size:18px; font-weight:500;
                               margin-bottom:4px }
        .detail-card-body .loc { font-size:13px; color:var(--text-secondary);
                                 margin-bottom:16px }
        .detail-row { display:flex; justify-content:space-between;
                      padding:10px 0; border-bottom:1px solid var(--border);
                      font-size:14px }
        .detail-row:last-child { border-bottom:none }
        .detail-label { color:var(--text-secondary) }
        .detail-value { font-weight:500 }
        .payment-status { display:inline-flex; align-items:center; gap:6px;
                          font-size:12px; font-weight:600;
                          padding:3px 10px; border-radius:3px }
        .status-confirmed { background:var(--accent-soft); color:var(--accent-strong);
                            border:1px solid var(--accent-line) }
        .status-pending { background:var(--warn-bg); color:var(--warn-fg); border:1px solid var(--warn-line) }
        .what-next { background:var(--surface-2);
                     border:1px solid var(--border);
                     border-radius:8px; padding:20px;
                     margin-bottom:16px }
        .what-next h3 { font-size:14px; font-weight:500;
                        margin-bottom:14px }
        .next-item { display:flex; gap:12px; padding:10px 0;
                     border-bottom:1px solid var(--border);
                     font-size:13px }
        .next-item:last-child { border-bottom:none }
        .next-num  { width:22px; height:22px; border-radius:50%;
                     background:var(--btn); color:#fff; font-size:11px;
                     font-weight:600; display:flex; align-items:center;
                     justify-content:center; flex-shrink:0; margin-top:1px }
        .next-text strong { display:block; margin-bottom:2px }
        .next-text span   { color:var(--text-secondary) }
        #osmConfirmationMap { height:300px; border-radius:10px; margin-bottom:10px }
        .osm-nearby-controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0 14px}
        .osm-nearby-controls button{border:1px solid #d5a16f;background:#fff7ed;color:#9a4d08;border-radius:8px;padding:9px 13px;font:inherit;font-size:13px;cursor:pointer}
        .osm-nearby-controls button:disabled{opacity:.65;cursor:wait}
        .osm-nearby-status{font-size:12px;color:var(--text-secondary)}
        .nearby-item { display:flex; justify-content:space-between;
                       align-items:center; padding:10px 0;
                       border-bottom:1px solid var(--border);
                       font-size:13px }
        .nearby-item:last-child { border-bottom:none }
        .nearby-name  { font-weight:500 }
        .nearby-meta  { font-size:12px; color:var(--text-secondary);
                        margin-top:2px }
        .nearby-dist  { font-size:12px; color:var(--accent); font-weight:500 }
        .actions-row  { display:flex; gap:10px; margin-top:24px }
        .actions-row .btn { flex:1; text-align:center; padding:11px;
                            border-radius:6px; font-size:14px;
                            font-weight:500; cursor:pointer;
                            text-decoration:none }
        .btn-primary-dark { background:var(--btn-hover); color:#fff; border:none }
        .btn-secondary { background:none; color:var(--text-primary);
                         border:1px solid var(--border-strong) }
    </style>
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body class="tourist-confirmation">

<?php include '../includes/header.php'; ?>

<div class="page-wrap narrow">

    <!-- Confirmation Hero -->
    <div class="confirm-hero">
        <div class="confirm-icon">
            <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M4.5 12.75l6 6 9-13.5"/>
            </svg>
        </div>
        <?php
        $heads = [
            'pending'         => ['Request sent', 'Your host will review it and reply. You are not asked to pay until the host accepts.'],
            'accepted'        => ['Your host accepted', 'You can now pay your host directly. Your dates are held until the payment deadline.'],
            'payment_pending' => ['Payment details sent', 'Your host is checking that the money arrived. The booking is confirmed when they confirm it.'],
            'confirmed'       => ['Your booking is confirmed', 'Your host confirmed they received your payment.'],
            'completed'       => ['Stay completed', 'Thank you for staying with us. You can leave a review from My bookings.'],
            'cancelled'       => ['Booking not going ahead', 'This booking was declined, cancelled or expired. You have not been charged by this website.'],
            'no_show'         => ['Marked as no-show', 'Your host recorded that the stay did not take place.'],
        ][$booking['status']] ?? ['Booking', ''];
        ?>
        <h1><?= h($heads[0]) ?></h1>
        <p><?= h($heads[1]) ?></p>
        <?php if ($booking['status'] === 'accepted'): ?>
        <p style="margin:14px 0 0"><a class="btn-sm btn-sm-primary" style="padding:10px 20px;font-size:14px" href="payment.php?ref=<?= h(bookingRef($booking_id)) ?>">Pay your host &mdash; KES <?= number_format($booking['total_amount']) ?></a><?php if (!empty($booking['payment_due_at'])): ?><br><span class="small">Pay before <?= h(date('j M Y, H:i', strtotime($booking['payment_due_at']))) ?></span><?php endif; ?></p>
        <?php endif; ?>
        <p style="margin:10px 0 0"><?php bookingRulesDialog(); ?></p>
        <div class="ref-tag">
            Booking <?= h(bookingLabel($booking_id)) ?>
        </div>
    </div>

    <?= bookingCountdownHtml($booking['check_in_date'], $booking['status']) ?>

    <!-- Booking Details -->
    <div class="detail-card">
        <div class="detail-card-img">
            <?php if ($booking['primary_photo']): ?>
                <img src="../uploads/accommodations/<?= h($booking['primary_photo']) ?>"
                     alt="<?= h($booking['title']) ?>" loading="lazy" decoding="async">
            <?php else: ?>
                <span style="font-size:12px;color:var(--text-tertiary)">No photo</span>
            <?php endif; ?>
        </div>
        <div class="detail-card-body">
            <h2><?= h($booking['title']) ?></h2>
            <div class="loc">
                <?= h($booking['location_name']) ?>
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
                    <?= h($booking['host_name']) ?>
                    <?php if ($booking['host_phone']): ?>
                        <span style="font-size:12px;
                                     color:var(--text-secondary);
                                     font-weight:400">
                            &nbsp;&middot;&nbsp;
                            <?= h($booking['host_phone']) ?>
                        </span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Stay (<?= (int)$br['nights'] ?> night<?= $br['nights'] > 1 ? 's' : '' ?>)</span>
                <span class="detail-value">KES <?= number_format($br['stay']) ?></span>
            </div>
            <?php if ($br['extra'] > 0): ?>
            <div class="detail-row">
                <span class="detail-label"><?= h($br['extra_name']) ?></span>
                <span class="detail-value">KES <?= number_format($br['extra']) ?></span>
            </div>
            <?php endif; ?>
            <div class="detail-row">
                <span class="detail-label">Booking total</span>
                <span class="detail-value">
                    KES <?= number_format($booking['total_amount']) ?>
                    <?php if ($br['service_fee'] > 0): ?><span style="display:block;font-size:11px;font-weight:400">includes KES <?= number_format($br['service_fee']) ?> service fee</span><?php endif; ?>
                </span>
            </div>
            <?php if ($br['extra'] > 0): ?>
            <div style="padding:0 0 10px"><?= bookingExtraNoteHtml($br) ?></div>
            <?php endif; ?>
            <div class="detail-row">
                <span class="detail-label">Payment method</span>
                <span class="detail-value">
                    <?= h($method_label) ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Payment status</span>
                <span class="detail-value">
                    <?php $payStatus = $booking['payment_status'] ?? ''; ?>
                    <span class="payment-status <?= $payStatus === 'confirmed' ? 'status-confirmed' : 'status-pending' ?>">
                        <?= $payStatus === 'confirmed' ? 'Payment received' : ($payStatus === 'pending' ? 'Awaiting host verification' : 'Not submitted') ?>
                    </span>
                    <?php if ($payStatus === 'confirmed' && !empty($booking['payment_id'])): ?><a href="../receipt.php?as=tourist&amp;pid=<?= h(opaqueId('pay', $booking['payment_id'])) ?>" target="_blank" rel="noopener" style="margin-left:8px">View or print host receipt</a><?php endif; ?>
                    <?php if (!empty($booking['pp_id'])): ?><a href="../platform_receipt.php?as=tourist&amp;ref=<?= h(opaqueId('plat', $booking['pp_id'])) ?>" target="_blank" rel="noopener" style="margin-left:8px">View StayExplore receipt</a><?php endif; ?>
                    <a href="../booking_record.php?as=tourist&amp;ref=<?= h(bookingRef($booking['booking_id'])) ?>" target="_blank" rel="noopener" style="margin-left:8px">Print booking record</a>
                </span>
            </div>
        </div>
    </div>

    <!-- What Happens Next -->
    <div class="what-next">
        <h3>What happens next</h3>
        <?php $S = $booking['status']; $done = ['pending' => 0, 'accepted' => 1, 'payment_pending' => 2, 'confirmed' => 3, 'completed' => 3][$S] ?? 0; ?>
        <div class="next-item">
            <div class="next-num"><?= $done >= 1 ? '&#10003;' : '1' ?></div>
            <div class="next-text">
                <strong><?= $done >= 1 ? 'Host accepted your request' : 'Host reviews your request' ?></strong>
                <span><?= $done >= 1 ? h($booking['host_name']) . ' accepted it.' : h($booking['host_name']) . ' will accept or decline. You do not pay yet.' ?></span>
            </div>
        </div>
        <div class="next-item">
            <div class="next-num"><?= $done >= 2 ? '&#10003;' : '2' ?></div>
            <div class="next-text">
                <strong>You pay your host directly</strong>
                <span><?= $done >= 2 ? 'Payment details sent' . ($method_label ? ' (' . h($method_label) . ')' : '') . '.' : 'After acceptance you have ' . (int)paymentDeadlineHours() . ' hours to pay by M-Pesa or bank transfer.' ?></span>
            </div>
        </div>
        <div class="next-item">
            <div class="next-num"><?= $done >= 3 ? '&#10003;' : '3' ?></div>
            <div class="next-text">
                <strong><?= $done >= 3 ? 'Check in on ' . h(date('d M Y', strtotime($booking['check_in_date']))) : 'Host confirms your payment' ?></strong>
                <span><?= $done >= 3 ? 'Contact your host if you need directions.' : 'Do not assume the stay is confirmed until the status changes to Confirmed.' ?></span>
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

    <!-- Map near the booked accommodation -->
    <?php if (!empty($booking['latitude']) && !empty($booking['longitude'])): ?>
    <div class="detail-card">
        <div class="detail-card-body">
            <h3 style="font-size:14px;font-weight:500;margin-bottom:14px">Explore nearby places</h3>
            <div id="osmConfirmationMap" aria-label="Map around your booked accommodation"></div>
            <div class="osm-nearby-controls">
                <button type="button" id="osmConfirmationSearch">Show nearby places</button>
                <span class="osm-nearby-status" id="osmConfirmationStatus" role="status" aria-live="polite">Green pin: your stay. Terracotta pins: attractions nearby.</span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Nearby Attractions -->
    <?php if (!empty($nearby)): ?>
    <div class="detail-card">
        <div class="detail-card-body">
            <h3 style="font-size:14px;font-weight:500;margin-bottom:14px">
                Attractions near your stay
            </h3>
            <p style="font-size:12px;color:var(--text-secondary);line-height:1.5;margin:0 0 12px">Entry fees are set by the park or site (often the government) and paid at the gate. They are not part of your booking.</p>
            <?php foreach (array_slice($nearby, 0, 5) as $att): ?>
            <div class="nearby-item">
                <div>
                    <div class="nearby-name">
                        <?= h($att['name']) ?>
                    </div>
                    <div class="nearby-meta">
                        <?= h($att['category']) ?>
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
    <?php if (in_array($booking['status'], ['pending','accepted','payment_pending','confirmed'], true) && $booking['check_in_date'] > date('Y-m-d')): ?>
        <form method="post" action="bookings.php" style="margin-top:14px;text-align:center" onsubmit="return confirm('Cancel this booking? This cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="cancel_booking">
            <input type="hidden" name="booking_ref" value="<?= h(bookingRef($booking['booking_id'])) ?>">
            <button type="submit" style="background:none;border:0;color:var(--bad-fg);font:inherit;font-size:13px;cursor:pointer;text-decoration:underline">Cancel this booking</button>
        </form>
        <?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>

<?php if (!empty($booking['latitude']) && !empty($booking['longitude'])): ?>
<script src="../assets/js/osm-nearby.js"></script>
<script src="../assets/js/map-pins.js"></script>
<script src="../assets/js/directions.js"></script>
<script type="text/se-leaflet" data-target="osmConfirmationMap">
(function () {
    const lat = <?= (float)$booking['latitude'] ?>;
    const lng = <?= (float)$booking['longitude'] ?>;
    const confirmationMap = L.map('osmConfirmationMap').setView([lat, lng], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(confirmationMap);
    window.enableDirections(confirmationMap, { destination: { lat: lat, lng: lng, name: 'Your booked accommodation' } });
    SEPins.legend(confirmationMap, ['stay', 'attraction', 'place']);
    L.marker([lat, lng], { icon: SEPins.stay() }).addTo(confirmationMap).bindPopup('<b>Your booked accommodation</b>').openPopup();
    <?php foreach ($nearby as $att): ?>
    <?php if (!empty($att['latitude']) && !empty($att['longitude'])): ?>
    L.marker([<?= (float)$att['latitude'] ?>, <?= (float)$att['longitude'] ?>], { icon: SEPins.attraction() }).addTo(confirmationMap).bindPopup(<?= json_encode((string)$att['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
    <?php endif; ?>
    <?php endforeach; ?>
    window.enableOsmNearbyPlaces(confirmationMap, { buttonId: 'osmConfirmationSearch', statusId: 'osmConfirmationStatus', radius: 2000, latitude: lat, longitude: lng });
})();
</script>
<?php endif; ?>

</body>
</html>