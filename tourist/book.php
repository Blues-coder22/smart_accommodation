<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('tourist');

$accommodation_id = (int)($_GET['accommodation_id'] ?? 0);
$check_in         = sanitize($_GET['check_in'] ?? '');
$check_out        = sanitize($_GET['check_out'] ?? '');
$guests           = (int)($_GET['guests'] ?? 1);

if (!$accommodation_id || !$check_in || !$check_out) {
    redirect('search.php?type=accommodation');
}

// Get accommodation
$stmt = $pdo->prepare("
    SELECT a.*, u.full_name AS host_name
    FROM accommodations a
    JOIN users u ON u.user_id = a.host_id
    WHERE a.accommodation_id = ? AND a.status = 'approved'
");
$stmt->execute([$accommodation_id]);
$acc = $stmt->fetch();

if (!$acc) redirect('search.php?type=accommodation');

// Get primary photo
$photo = $pdo->prepare("
    SELECT photo_path FROM accommodation_photos
    WHERE accommodation_id = ? AND is_primary = 1 LIMIT 1
");
$photo->execute([$accommodation_id]);
$photo = $photo->fetchColumn();

// Get house rules
$rules = $pdo->prepare("
    SELECT rule_description FROM house_rules
    WHERE accommodation_id = ?
");
$rules->execute([$accommodation_id]);
$rules = $rules->fetchAll(PDO::FETCH_COLUMN);

// Calculate nights and total
$check_in_dt  = new DateTime($check_in);
$check_out_dt = new DateTime($check_out);
$nights       = $check_in_dt->diff($check_out_dt)->days;
$subtotal     = $nights * $acc['price_per_night'];
$tax          = round($subtotal * 0.08, -2);
$total        = $subtotal + $tax;

// Check availability
$avail = $pdo->prepare("
    SELECT COUNT(*) FROM bookings
    WHERE accommodation_id = ?
    AND status IN ('pending','confirmed')
    AND NOT (check_out_date <= ? OR check_in_date >= ?)
");
$avail->execute([$accommodation_id, $check_in, $check_out]);
$conflict = $avail->fetchColumn();

if ($conflict > 0) {
    setFlash('error', 'These dates are no longer available. 
                       Please select different dates.');
    redirect("details.php?id=$accommodation_id");
}

// Handle form submission
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rules_agreed = isset($_POST['rules_agreed']) ? 1 : 0;

    if (!$rules_agreed && !empty($rules)) {
        $errors[] = 'You must agree to the house rules to continue.';
    }

    if (empty($errors)) {
        // Create booking
        $stmt = $pdo->prepare("
            INSERT INTO bookings
            (tourist_id, accommodation_id, check_in_date,
             check_out_date, num_guests, total_amount,
             rules_agreed, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $accommodation_id,
            $check_in,
            $check_out,
            $guests,
            $total,
            $rules_agreed
        ]);

        $booking_id = $pdo->lastInsertId();
        redirect("payment.php?booking_id=$booking_id");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete your booking — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .page-wrap { max-width:960px; margin:0 auto; padding:32px 24px; }
        .page-tag  { font-size:11px; font-weight:600; letter-spacing:.06em;
                     color:#1D9E75; text-transform:uppercase;
                     margin-bottom:8px; }
        .page-title { font-size:28px; font-weight:400; color:var(--text-primary);
                      margin-bottom:4px; }
        .page-sub { font-size:14px; color:var(--text-secondary);
                    margin-bottom:32px; }
        .booking-layout { display:grid; grid-template-columns:1fr 320px;
                          gap:32px; }
        .booking-main { background:var(--surface-2);
                        border:0.5px solid var(--border);
                        border-radius:8px; padding:28px; }
        .booking-main h3 { font-size:15px; font-weight:500;
                           margin-bottom:20px; color:var(--text-primary); }
        .detail-row { display:flex; justify-content:space-between;
                      padding:10px 0; border-bottom:0.5px solid var(--border);
                      font-size:14px; }
        .detail-row:last-of-type { border-bottom:none; }
        .detail-label { color:var(--text-secondary); }
        .detail-value { font-weight:500; color:var(--text-primary); }
        .rules-box { margin-top:24px; border-top:0.5px solid var(--border);
                     padding-top:20px; }
        .rules-box h3 { font-size:14px; font-weight:500; margin-bottom:12px; }
        .rule-item { font-size:13px; color:var(--text-secondary);
                     padding:6px 0; display:flex; gap:8px;
                     border-bottom:0.5px solid var(--border); }
        .rule-item::before { content:"—"; color:var(--border-strong); 
                             flex-shrink:0; }
        .agree-row { display:flex; align-items:flex-start; gap:10px;
                     margin-top:16px; }
        .agree-row input[type=checkbox] { margin-top:2px; 
                                          accent-color:#1D9E75;
                                          width:15px; height:15px;
                                          flex-shrink:0; }
        .agree-row label { font-size:13px; color:var(--text-secondary);
                           line-height:1.5; }
        .summary-card { background:var(--surface-2);
                        border:0.5px solid var(--border);
                        border-radius:8px; overflow:hidden;
                        position:sticky; top:80px; }
        .summary-img { height:160px; background:#E1F5EE;
                       display:flex; align-items:center;
                       justify-content:center; font-size:36px; }
        .summary-img img { width:100%; height:100%; object-fit:cover; }
        .summary-body { padding:20px; }
        .summary-name { font-size:16px; font-weight:500; margin-bottom:4px; }
        .summary-host { font-size:12px; color:var(--text-secondary);
                        margin-bottom:16px; }
        .price-rows { border-top:0.5px solid var(--border); padding-top:14px; }
        .p-row { display:flex; justify-content:space-between;
                 font-size:13px; margin-bottom:8px;
                 color:var(--text-secondary); }
        .p-row.total { font-size:14px; font-weight:600;
                       color:var(--text-primary);
                       border-top:0.5px solid var(--border);
                       padding-top:10px; margin-top:4px; }
        .secure-note { font-size:12px; color:var(--text-secondary);
                       margin-top:12px; display:flex;
                       align-items:center; gap:6px; }
        .submit-btn { width:100%; padding:12px; background:#085041;
                      color:white; border:none; border-radius:6px;
                      font-size:14px; font-weight:500; cursor:pointer;
                      margin-top:16px; }
        .submit-btn:hover { background:#0a6350; }
        @media(max-width:768px){
            .booking-layout { grid-template-columns:1fr; }
            .summary-card { position:static; }
        }
    </style>
</head>
<body>

<?php include '../includes/header.php'; ?>

<div class="page-wrap">

    <div class="page-tag">Secure checkout</div>
    <h1 class="page-title">Complete your booking</h1>
    <p class="page-sub">
        <?= htmlspecialchars($acc['title']) ?> &middot;
        <?= date('d M', strtotime($check_in)) ?> &ndash;
        <?= date('d M Y', strtotime($check_out)) ?> &middot;
        <?= $guests ?> guest<?= $guests > 1 ? 's' : '' ?>
    </p>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error" style="margin-bottom:20px">
        <?php foreach ($errors as $e): ?>
            <p><?= $e ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST">
    <div class="booking-layout">

        <!-- Left — Details and Rules -->
        <div>
            <div class="booking-main">
                <h3>Booking details</h3>

                <div class="detail-row">
                    <span class="detail-label">Property</span>
                    <span class="detail-value">
                        <?= htmlspecialchars($acc['title']) ?>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Host</span>
                    <span class="detail-value">
                        <?= htmlspecialchars($acc['host_name']) ?>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Location</span>
                    <span class="detail-value">
                        <?= htmlspecialchars($acc['location_name']) ?>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Check-in</span>
                    <span class="detail-value">
                        <?= date('D, d M Y', strtotime($check_in)) ?>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Check-out</span>
                    <span class="detail-value">
                        <?= date('D, d M Y', strtotime($check_out)) ?>
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
                        <?= $guests ?> guest<?= $guests > 1 ? 's' : '' ?>
                    </span>
                </div>

                <?php if (!empty($rules)): ?>
                <div class="rules-box">
                    <h3>House rules</h3>
                    <?php foreach ($rules as $rule): ?>
                    <div class="rule-item">
                        <?= htmlspecialchars($rule) ?>
                    </div>
                    <?php endforeach; ?>

                    <div class="agree-row">
                        <input type="checkbox" id="rules_agreed"
                               name="rules_agreed" required>
                        <label for="rules_agreed">
                            I have read and agree to the house rules
                            for this property. I understand that
                            violations may result in cancellation
                            of my booking.
                        </label>
                    </div>
                </div>
                <?php else: ?>
                    <input type="hidden" name="rules_agreed" value="1">
                <?php endif; ?>

                <p style="font-size:12px;color:var(--text-secondary);
                           margin-top:20px">
                    Your information is encrypted and handled securely.
                </p>

                <button type="submit" class="submit-btn">
                    Continue to payment
                </button>
            </div>
        </div>

        <!-- Right — Summary Card -->
        <div>
            <div class="summary-card">
                <div class="summary-img">
                    <?php if ($photo): ?>
                        <img src="../uploads/accommodations/<?= htmlspecialchars($photo) ?>"
                             alt="<?= htmlspecialchars($acc['title']) ?>">
                    <?php else: ?>
                        &#127968;
                    <?php endif; ?>
                </div>
                <div class="summary-body">
                    <div class="summary-name">
                        <?= htmlspecialchars($acc['title']) ?>
                    </div>
                    <div class="summary-host">
                        <?= htmlspecialchars($acc['location_name']) ?>
                    </div>
                    <div class="price-rows">
                        <div class="p-row">
                            <span>
                                KES <?= number_format($acc['price_per_night'])?>
                                &times; <?= $nights ?> nights
                            </span>
                            <span>KES <?= number_format($subtotal) ?></span>
                        </div>
                        <div class="p-row">
                            <span>Service and local taxes (rounded to nearest KES 100)</span>
                            <span>KES <?= number_format($tax) ?></span>
                        </div>
                        <div class="p-row total">
                            <span>Total</span>
                            <span>KES <?= number_format($total) ?></span>
                        </div>
                    </div>
                    <div class="secure-note">
                        &#x1F512;
                        Payments are tracked securely within the platform
                    </div>
                </div>
            </div>
        </div>

    </div>
    </form>

</div>

<?php include '../includes/footer.php'; ?>

</body>
</html>