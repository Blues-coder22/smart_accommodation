<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/email.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('tourist');

$booking_id = (int)($_GET['booking_id'] ?? 0);

if (!$booking_id) {
    redirect('bookings.php');
}

// Get booking with accommodation details
$stmt = $pdo->prepare("
    SELECT b.*,
           a.title, a.location_name, a.price_per_night,
           a.host_id,
           u.full_name AS host_name,
           u.email AS host_email,
           (SELECT photo_path FROM accommodation_photos
            WHERE accommodation_id = a.accommodation_id
            AND is_primary = 1 LIMIT 1) AS primary_photo
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users u ON u.user_id = a.host_id
    WHERE b.booking_id = ?
    AND b.tourist_id = ?
    AND b.status = 'pending'
");
$stmt->execute([$booking_id, $_SESSION['user_id']]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlash('error', 'Booking not found or already processed.');
    redirect('bookings.php');
}

// Calculate nights
$check_in  = new DateTime($booking['check_in_date']);
$check_out = new DateTime($booking['check_out_date']);
$nights    = $check_in->diff($check_out)->days;

// Handle form submission
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method    = sanitize($_POST['payment_method'] ?? '');
    $reference = sanitize($_POST['payment_reference'] ?? '');
    $confirmed = isset($_POST['confirmed']) ? 1 : 0;

    $allowed_methods = ['cash', 'bank_transfer', 'mpesa'];

    if (!in_array($method, $allowed_methods)) {
        $errors[] = 'Please select a payment method.';
    }
    if (!$confirmed) {
        $errors[] = 'Please confirm the amount and booking details.';
    }

    if (empty($errors)) {
        // Record payment
        $stmt = $pdo->prepare("
            INSERT INTO payments
            (booking_id, tourist_id, host_id, amount,
             payment_method, payment_status, payment_date)
            VALUES (?, ?, ?, ?, ?, 'confirmed', NOW())
        ");
        $stmt->execute([
            $booking_id,
            $_SESSION['user_id'],
            $booking['host_id'],
            $booking['total_amount'],
            $method
        ]);

        // Update booking status to confirmed
        $pdo->prepare("
            UPDATE bookings SET status = 'confirmed'
            WHERE booking_id = ?
        ")->execute([$booking_id]);

        // Get tourist details for email
        $tourist = $pdo->prepare("
            SELECT full_name, email FROM users WHERE user_id = ?
        ");
        $tourist->execute([$_SESSION['user_id']]);
        $tourist = $tourist->fetch();

        // Send confirmation emails
        $booking_data = [
            'booking_id'    => $booking_id,
            'title'         => $booking['title'],
            'check_in_date' => $booking['check_in_date'],
            'check_out_date'=> $booking['check_out_date'],
            'total_amount'  => $booking['total_amount']
        ];

        // Email tourist
        sendPaymentConfirmationEmail(
            $tourist['email'],
            $tourist['full_name'],
            $booking_data
        );

        // Email host
        sendBookingConfirmationEmail(
            $booking['host_email'],
            $booking['host_name'],
            $booking_data
        );

        redirect("confirmation.php?booking_id=$booking_id");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .page-wrap   { max-width:900px; margin:0 auto; padding:32px 24px }
        .page-tag    { font-size:11px; font-weight:600; letter-spacing:.06em;
                       color:#1D9E75; text-transform:uppercase; margin-bottom:8px }
        .page-title  { font-size:28px; font-weight:400; margin-bottom:4px }
        .page-sub    { font-size:14px; color:var(--text-secondary);
                       margin-bottom:32px }
        .pay-layout  { display:grid; grid-template-columns:1fr 300px; gap:28px }
        .pay-card    { background:var(--surface-2); border:0.5px solid var(--border);
                       border-radius:8px; padding:28px }
        .pay-card h3 { font-size:15px; font-weight:500; margin-bottom:20px }
        .method-list { display:flex; flex-direction:column; gap:8px;
                       margin-bottom:20px }
        .method-opt  { display:flex; align-items:flex-start; gap:12px;
                       padding:14px; border:0.5px solid var(--border);
                       border-radius:6px; cursor:pointer; transition:.15s }
        .method-opt:has(input:checked) {
                       border-color:#1D9E75;
                       background:#F0FAF6 }
        .method-opt input[type=radio] { margin-top:3px; accent-color:#1D9E75;
                                        flex-shrink:0 }
        .method-opt strong { font-size:14px; font-weight:500;
                             display:block; margin-bottom:2px }
        .method-opt small  { font-size:12px; color:var(--text-secondary) }
        .ref-field   { margin-top:16px }
        .notice-box  { background:#FFF8E1; border:0.5px solid #F9D57C;
                       border-radius:6px; padding:12px 14px; font-size:13px;
                       color:#9A6A00; margin-top:16px; display:none }
        .confirm-row { display:flex; align-items:flex-start; gap:10px;
                       margin:20px 0 }
        .confirm-row input { margin-top:2px; accent-color:#1D9E75;
                             width:15px; height:15px; flex-shrink:0 }
        .confirm-row label { font-size:13px; color:var(--text-secondary);
                             line-height:1.5 }
        .pay-btn     { width:100%; padding:12px; background:#085041;
                       color:#fff; border:none; border-radius:6px;
                       font-size:14px; font-weight:500; cursor:pointer }
        .pay-btn:hover { background:#0a6350 }
        .summary-card { background:var(--surface-2);
                        border:0.5px solid var(--border);
                        border-radius:8px; overflow:hidden;
                        position:sticky; top:80px }
        .summary-img  { height:150px; background:#E1F5EE;
                        display:flex; align-items:center;
                        justify-content:center; font-size:36px }
        .summary-img img { width:100%; height:100%; object-fit:cover }
        .summary-body { padding:18px }
        .summary-name { font-size:15px; font-weight:500; margin-bottom:4px }
        .summary-loc  { font-size:12px; color:var(--text-secondary);
                        margin-bottom:16px }
        .p-row        { display:flex; justify-content:space-between;
                        font-size:13px; color:var(--text-secondary);
                        margin-bottom:8px }
        .p-row.total  { font-size:14px; font-weight:600;
                        color:var(--text-primary);
                        border-top:0.5px solid var(--border);
                        padding-top:10px; margin-top:4px }
        .status-note  { font-size:12px; color:var(--text-secondary);
                        margin-top:12px; padding-top:12px;
                        border-top:0.5px solid var(--border) }
        @media(max-width:768px){
            .pay-layout { grid-template-columns:1fr }
            .summary-card { position:static }
        }
    </style>
</head>
<body>

<?php include '../includes/header.php'; ?>

<div class="page-wrap">

    <div class="page-tag">Payment</div>
    <h1 class="page-title">Choose how you will pay.</h1>
    <p class="page-sub">
        StayExplore records and tracks your payment for you and your host.
        We do not process card details or move money —
        your host confirms when they have received it.
    </p>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error" style="margin-bottom:20px">
        <?php foreach ($errors as $e): ?>
            <p><?= $e ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST">
    <div class="pay-layout">

        <!-- Left — Payment Form -->
        <div class="pay-card">
            <h3>Payment method</h3>

            <div class="method-list">
                <label class="method-opt">
                    <input type="radio" name="payment_method"
                           value="cash" required
                           onchange="showHint(this)">
                    <div>
                        <strong>Pay on arrival (cash)</strong>
                        <small>Pay your host in cash at check-in.</small>
                    </div>
                </label>
                <label class="method-opt">
                    <input type="radio" name="payment_method"
                           value="bank_transfer"
                           onchange="showHint(this)">
                    <div>
                        <strong>Bank transfer</strong>
                        <small>
                            Your host will send their bank details
                            after booking is confirmed.
                        </small>
                    </div>
                </label>
                <label class="method-opt">
                    <input type="radio" name="payment_method"
                           value="mpesa"
                           onchange="showHint(this)">
                    <div>
                        <strong>M-Pesa (sent manually)</strong>
                        <small>
                            Send the money yourself to your host's
                            M-Pesa number, then enter the code below.
                        </small>
                    </div>
                </label>
            </div>

            <!-- Hint box -->
            <div class="notice-box" id="payHint"></div>

            <!-- Reference field — shown for bank and mpesa -->
            <div class="ref-field" id="refField" style="display:none">
                <div class="form-group">
                    <label for="payment_reference">
                        Transaction reference (optional)
                    </label>
                    <input type="text" id="payment_reference"
                           name="payment_reference"
                           maxlength="40"
                           placeholder="Bank reference or M-Pesa code">
                </div>
            </div>

            <div class="confirm-row">
                <input type="checkbox" id="confirmed"
                       name="confirmed" required>
                <label for="confirmed">
                    I confirm the booking amount of
                    <strong>KES <?= number_format($booking['total_amount']) ?></strong>
                    and agree to complete payment to my host
                    by the selected method.
                </label>
            </div>

            <button type="submit" class="pay-btn">
                Confirm booking &mdash;
                KES <?= number_format($booking['total_amount']) ?>
            </button>
        </div>

        <!-- Right — Summary -->
        <div>
            <div class="summary-card">
                <div class="summary-img">
                    <?php if ($booking['primary_photo']): ?>
                        <img src="../uploads/accommodations/
                             <?= htmlspecialchars($booking['primary_photo']) ?>"
                             alt="<?= htmlspecialchars($booking['title']) ?>">
                    <?php else: ?>
                        &#127968;
                    <?php endif; ?>
                </div>
                <div class="summary-body">
                    <div class="summary-name">
                        <?= htmlspecialchars($booking['title']) ?>
                    </div>
                    <div class="summary-loc">
                        <?= htmlspecialchars($booking['location_name']) ?>
                    </div>
                    <div class="p-row">
                        <span>
                            KES <?= number_format($booking['price_per_night']) ?>
                            &times; <?= $nights ?> nights
                        </span>
                        <span>
                            KES <?= number_format(
                                $booking['price_per_night'] * $nights
                            ) ?>
                        </span>
                    </div>
                    <div class="p-row">
                        <span>
                            <?= date('d M', strtotime($booking['check_in_date'])) ?>
                            &ndash;
                            <?= date('d M Y',
                                strtotime($booking['check_out_date'])) ?>
                        </span>
                        <span>
                            <?= $booking['num_guests'] ?> guest
                            <?= $booking['num_guests'] > 1 ? 's' : '' ?>
                        </span>
                    </div>
                    <div class="p-row total">
                        <span>Total to pay host</span>
                        <span>
                            KES <?= number_format($booking['total_amount']) ?>
                        </span>
                    </div>
                    <div class="status-note">
                        Payment status: awaiting confirmation until
                        your host marks it as received.
                    </div>
                </div>
            </div>
        </div>

    </div>
    </form>

</div>

<?php include '../includes/footer.php'; ?>

<script>
const hints = {
    cash: 'Bring the exact amount in cash on your check-in date. ' +
          'Your host will confirm receipt.',
    bank_transfer: 'Your host will send their bank account details ' +
                   'by email after this booking is confirmed. ' +
                   'Enter the reference number below once transferred.',
    mpesa: 'Send the full amount to your host\'s M-Pesa number. ' +
           'Your host will share their number by email. ' +
           'Enter the M-Pesa transaction code below.'
};

function showHint(input) {
    const hintBox  = document.getElementById('payHint');
    const refField = document.getElementById('refField');

    hintBox.textContent  = hints[input.value] || '';
    hintBox.style.display = 'block';

    // Show reference field for bank and mpesa
    refField.style.display =
        (input.value === 'bank_transfer' || input.value === 'mpesa')
        ? 'block' : 'none';
}
</script>

</body>
</html>