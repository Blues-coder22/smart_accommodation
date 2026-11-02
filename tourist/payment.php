<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/email.php';
require_once '../includes/onsite_experience.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('tourist');

$booking_id = (int)($_GET['booking_id'] ?? 0);

if (!$booking_id) {
    redirect('bookings.php');
}

ensureOnsiteExperienceColumns($pdo);
// Get booking with accommodation details
$stmt = $pdo->prepare("
    SELECT b.*,
           a.title, a.location_name, a.price_per_night,
           a.onsite_experience_name,
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
$br        = bookingPriceBreakdown($booking);

// Handle form submission
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
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
        // Record payment details as pending for host review. Reuse the existing row if the form is resubmitted.
        $existingPayment = $pdo->prepare("SELECT payment_id FROM payments WHERE booking_id = ? ORDER BY payment_id DESC LIMIT 1");
        $existingPayment->execute([$booking_id]);
        $existingPaymentId = (int)$existingPayment->fetchColumn();
        if ($existingPaymentId) {
            $stmt = $pdo->prepare("UPDATE payments SET payment_method = ?, payment_reference = ?, payment_status = 'pending', payment_date = NOW() WHERE payment_id = ? AND booking_id = ?");
            $stmt->execute([$method, $reference !== '' ? $reference : null, $existingPaymentId, $booking_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO payments (booking_id, tourist_id, host_id, amount, payment_method, payment_reference, payment_status, payment_date) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $stmt->execute([$booking_id, $_SESSION['user_id'], $booking['host_id'], $booking['total_amount'], $method, $reference !== '' ? $reference : null]);
        }

        // The tourist has submitted payment details, but the booking remains pending.
        // Notify the host about the request; this is not a confirmation email.
        sendEmail(
            $booking['host_email'],
            'New booking request — Smart Accommodation Kenya',
            '<p>You have a new booking request for <strong>' . h($booking['title'], ENT_QUOTES, 'UTF-8') . '</strong>.</p>' .
            '<p>Booking reference: #BK' . str_pad((string)$booking_id, 5, '0', STR_PAD_LEFT) . '</p>' .
            '<p>The request is still pending. Sign in to review the payment details and either confirm or decline the booking.</p>'
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
        .pay-card    { background:var(--surface-2); border:1px solid var(--border);
                       border-radius:8px; padding:28px }
        .pay-card h3 { font-size:15px; font-weight:500; margin-bottom:20px }
        .method-list { display:flex; flex-direction:column; gap:8px;
                       margin-bottom:20px }
        .method-opt  { display:flex; align-items:flex-start; gap:12px;
                       padding:14px; border:1px solid var(--border);
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
        .notice-box  { background:#FFF8E1; border:1px solid #F9D57C;
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
                        border:1px solid var(--border);
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
                        border-top:1px solid var(--border);
                        padding-top:10px; margin-top:4px }
        .status-note  { font-size:12px; color:var(--text-secondary);
                        margin-top:12px; padding-top:12px;
                        border-top:1px solid var(--border) }
        @media(max-width:768px){
            .pay-layout { grid-template-columns:1fr }
            .summary-card { position:static }
        }
    </style>
</head>
<body class="tourist-payment">

<?php include '../includes/header.php'; ?>

<div class="page-wrap">

    <div class="page-tag">Payment</div>
    <h1 class="page-title">Choose how you will pay.</h1>
    <p class="page-sub">
        This prototype records your selected payment method for host review; it does not process a live M-Pesa or bank transaction.
        We do not process card details or move money —
        your host confirms when they have received it.
    </p>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error" style="margin-bottom:20px">
        <?php foreach ($errors as $e): ?>
            <p><?= h($e) ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
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
                Submit payment details &mdash;
                KES <?= number_format($booking['total_amount']) ?>
            </button>
        </div>

        <!-- Right — Summary -->
        <div>
            <div class="summary-card">
                <div class="summary-img">
                    <?php if ($booking['primary_photo']): ?>
                        <img src="../uploads/accommodations/<?= h($booking['primary_photo']) ?>"
                             alt="<?= h($booking['title']) ?>">
                    <?php else: ?>
                        <span style="font-size:12px;color:#8a948e">No photo</span>
                    <?php endif; ?>
                </div>
                <div class="summary-body">
                    <div class="summary-name">
                        <?= h($booking['title']) ?>
                    </div>
                    <div class="summary-loc">
                        <?= h($booking['location_name']) ?>
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
                    <?php if ($br['extra'] > 0): ?>
                    <div class="p-row">
                        <span><?= h($br['extra_name']) ?> (KES <?= number_format($br['extra_per_guest']) ?> &times; <?= (int)$br['guests'] ?> guest<?= $br['guests'] > 1 ? 's' : '' ?>)</span>
                        <span>KES <?= number_format($br['extra']) ?></span>
                    </div>
                    <?= bookingExtraNoteHtml($br) ?>
                    <?php endif; ?>
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
                        Booking status remains pending until
                        your host marks it as received.
                    </div>
                </div>
            </div>
        </div>

    </div>
    </form>
    <?php if (in_array($booking['status'], ['pending','confirmed'], true) && $booking['check_in_date'] > date('Y-m-d')): ?>
        <form method="post" action="bookings.php" style="margin-top:14px;text-align:center" onsubmit="return confirm('Cancel this booking? This cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="cancel_booking">
            <input type="hidden" name="booking_id" value="<?= (int)$booking['booking_id'] ?>">
            <button type="submit" style="background:none;border:0;color:#b42318;font:inherit;font-size:13px;cursor:pointer;text-decoration:underline">Cancel this booking</button>
        </form>
        <?php endif; ?>

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


<!-- Processing overlay shown briefly while the payment details are submitted -->
<style>
#payProcessing{position:fixed;inset:0;background:rgba(13,59,46,.55);display:none;align-items:center;justify-content:center;z-index:9999;padding:20px}
#payProcessing.show{display:flex}
.pp-box{background:#fff;border-radius:14px;padding:30px 28px;width:100%;max-width:380px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.pp-box h4{margin:0 0 6px;font-size:18px;color:#0d3b2e}
.pp-step{font-size:13px;color:#68776f;min-height:20px;margin:0 0 18px}
.pp-track{height:10px;background:#e8e5dc;border-radius:99px;overflow:hidden;position:relative}
.pp-fill{height:100%;width:0;background:linear-gradient(90deg,#16866b,#c0623a);border-radius:99px;transition:width .35s ease}
.pp-pct{font-size:12px;font-weight:700;color:#0d3b2e;margin-top:10px}
</style>
<div id="payProcessing" role="alertdialog" aria-live="polite" aria-label="Processing payment">
  <div class="pp-box">
    <h4>Processing payment</h4>
    <p class="pp-step" id="ppStep">Getting things ready…</p>
    <div class="pp-track"><div class="pp-fill" id="ppFill"></div></div>
    <div class="pp-pct" id="ppPct">0%</div>
  </div>
</div>
<script>
(function () {
    var pm = document.querySelector('input[name="payment_method"]');
    var form = pm ? pm.form : null;
    if (!form) return;
    var overlay = document.getElementById('payProcessing');
    var fill = document.getElementById('ppFill'), pct = document.getElementById('ppPct'), step = document.getElementById('ppStep');
    var steps = [[0, 'Getting things ready…'], [30, 'Checking your booking details…'], [60, 'Recording your payment…'], [85, 'Letting your host know…'], [100, 'All done, taking you to your confirmation']];
    var busy = false;
    form.addEventListener('submit', function (e) {
        if (busy) return;
        e.preventDefault();               // native validation has already passed at this point
        busy = true;
        overlay.classList.add('show');
        var n = 0, timer = setInterval(function () {
            n = Math.min(100, n + Math.floor(Math.random() * 9) + 5);
            fill.style.width = n + '%'; pct.textContent = n + '%';
            for (var i = steps.length - 1; i >= 0; i--) { if (n >= steps[i][0]) { step.textContent = steps[i][1]; break; } }
            if (n >= 100) { clearInterval(timer); setTimeout(function () { form.submit(); }, 450); }
        }, 160);
    });
})();
</script>
</body>
</html>