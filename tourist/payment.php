<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/currency.php';
require_once '../includes/notifications.php';
require_once '../includes/commission.php';
ensureCommissionTables($pdo);
require_once '../includes/booking_workflow.php';
require_once '../includes/booking_rules.php';
require_once '../includes/booking_holds.php';
expireStaleBookingRequests($pdo);   // releases dates held by unpaid or unanswered requests
require_once '../includes/email.php';
require_once '../includes/onsite_experience.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('tourist');

$booking_id = bookingIdFromRef($_GET['ref'] ?? '');

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
           u.payment_instructions AS host_instr,
           (SELECT photo_path FROM accommodation_photos
            WHERE accommodation_id = a.accommodation_id
            ORDER BY is_primary DESC, photo_id ASC LIMIT 1) AS primary_photo
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users u ON u.user_id = a.host_id
    WHERE b.booking_id = ?
    AND b.tourist_id = ?
");
$stmt->execute([$booking_id, $_SESSION['user_id']]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlash('error', 'Booking not found.');
    redirect('bookings.php');
}
// PAYMENT IS OPEN ONLY AFTER THE HOST ACCEPTED, and only until the payment deadline.
if ($booking['status'] === 'pending') {
    setFlash('warning', 'Your host has not accepted this request yet. You will be asked to pay only after they accept, and we will notify you.');
    redirect('bookings.php');
}
if (in_array($booking['status'], ['payment_pending', 'confirmed', 'completed'], true)) {
    redirect('confirmation.php?ref=' . urlencode(bookingRef($booking_id)));
}
if ($booking['status'] !== 'accepted') {
    setFlash('error', 'This booking can no longer be paid for.');
    redirect('bookings.php');
}
if (!guestSubmitPaymentAllowed($booking)) {
    expireStaleBookingRequests($pdo, true);
    setFlash('error', 'The payment deadline for this booking has passed, so the dates were released. You can send a new request.');
    redirect('bookings.php');
}

// Calculate nights
$check_in  = new DateTime($booking['check_in_date']);
$check_out = new DateTime($booking['check_out_date']);
$nights    = $check_in->diff($check_out)->days;
$br        = bookingPriceBreakdown($booking);
$split     = bookingSplit($booking);                // host_amount -> host, platform -> admin
$needPlatform = !$split['legacy'] && $split['platform'] > 0;
$platformInstructions = siteSettingGet('platform_pay_instructions', COMMISSION_PAY_INSTRUCTIONS);

// Handle form submission
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $method    = sanitize($_POST['payment_method'] ?? '');
    $reference = sanitize($_POST['payment_reference'] ?? '');
    $confirmed = isset($_POST['confirmed']) ? 1 : 0;

    $allowed_methods = ['bank_transfer', 'mpesa'];   // cash on arrival cannot be verified before the payment deadline

    if (!in_array($method, $allowed_methods)) {
        $errors[] = 'Please select a payment method.';
    }
    if (!$confirmed) {
        $errors[] = 'Please confirm the amount and booking details.';
    }
    // Reference check, on the server: an M-Pesa code is letters and digits (for example QGH7ABC123); a bank reference is 4 to 40 plain characters.
    $reference = strtoupper(preg_replace('/\s+/', '', (string)$reference));
    if ($reference === '') {
        $errors[] = 'The transaction code is required. Copy it from your M-Pesa confirmation message or your bank receipt, then paste it here.';
    } elseif ($method === 'mpesa' && !preg_match('/^[A-Z0-9]{8,14}$/', $reference)) {
        $errors[] = 'Enter the M-Pesa transaction code from your confirmation message (8 to 14 letters and numbers, for example QGH7ABC123).';
    } elseif ($method === 'bank_transfer' && !preg_match('/^[A-Z0-9\/_.-]{4,40}$/', $reference)) {
        $errors[] = 'Enter the bank transfer reference (4 to 40 letters, numbers or - / _ .).';
    }
    if (empty($errors)) {   // one payment reference cannot be used to support two different bookings
        $dup = $pdo->prepare('SELECT 1 FROM payments WHERE payment_reference = ? AND payment_method = ? AND booking_id <> ? LIMIT 1');
        $dup->execute([$reference, $method, $booking_id]);
        if ($dup->fetchColumn()) $errors[] = 'This payment reference is already used for another booking. Please check it and try again.';
    }

    // Second part of the payment: StayExplore Kenya's share (service fee + commission), paid straight to the admin.
    $pMethod = sanitize($_POST['platform_method'] ?? ''); $pRef = mb_substr(trim((string)($_POST['platform_reference'] ?? '')), 0, 120);
    if ($needPlatform) {
        if (!in_array($pMethod, ['mpesa', 'bank_transfer'], true)) $errors[] = 'Please choose how you paid StayExplore Kenya (M-Pesa or bank transfer).';
        if (mb_strlen($pRef) < 4) $errors[] = 'Please enter the M-Pesa or bank reference for your payment to StayExplore Kenya.';
    }

    // Optional screenshot of the payment. Kept private: only this guest, the host and admins can open it.
    $proofName = null;
    if (empty($errors)) {
        $proofName = savePrivateProofImage('payment_proof', 'payment');
        if ($proofName === false) { $errors[] = 'We could not use that screenshot. Please send a JPG, PNG or WebP image up to 5 MB.'; $proofName = null; }
    }

    $pProof = null;
    if (empty($errors) && $needPlatform) {
        $pProof = savePrivateProofImage('platform_proof', 'platform');
        if ($pProof === false) { $errors[] = 'We could not use that screenshot. Please send a JPG, PNG or WebP image up to 5 MB.'; $pProof = null; }
    }

    if (empty($errors)) {
        // v9.38.1: everything that records this payment happens in ONE transaction. The booking moves to 'payment_pending' first
        // (locks the row, re-checks it is still 'accepted'); then the guest's payment to the host and to StayExplore Kenya are saved.
        // If any step fails, all of it is rolled back, so no payment record is left behind for a booking that did not move on.
        // Admin alerts and emails are sent only AFTER the commit.
        $discardProofs = function () use (&$proofName, &$pProof) {
            foreach ([[$proofName, 'payment'], [$pProof, 'platform']] as [$f, $k]) {
                if ($f) { $path = commissionPrivateDir($k) . DIRECTORY_SEPARATOR . basename($f); if (is_file($path)) @unlink($path); }
            }
        };
        $platformChanged = false; $existingPaymentId = 0;
        try {
            $pdo->beginTransaction();
            $mv = transitionBooking($pdo, $booking_id, 'payment_pending', 'tourist', (int)$_SESSION['user_id'], ['event' => 'payment_submitted', 'expect' => 'accepted', 'note' => 'Guest reported a payment (' . $method . ')']);
            if (!$mv['ok']) { $pdo->rollBack(); $discardProofs(); setFlash('error', $mv['error']); redirect('bookings.php'); }

            // Payment to the host. Reuse the existing row if the form is resubmitted; a payment the host already confirmed is never overwritten.
            $existingPayment = $pdo->prepare("SELECT payment_id, payment_status FROM payments WHERE booking_id = ? ORDER BY payment_id DESC LIMIT 1 FOR UPDATE");
            $existingPayment->execute([$booking_id]);
            $existingRow = $existingPayment->fetch(PDO::FETCH_ASSOC) ?: null;
            $existingPaymentId = $existingRow ? (int)$existingRow['payment_id'] : 0;
            if ($existingRow && $existingRow['payment_status'] === 'confirmed') {
                $pdo->rollBack(); $discardProofs();
                redirect('confirmation.php?ref=' . urlencode(bookingRef($booking_id)));
            }
            if ($existingPaymentId) {
                $pdo->prepare("UPDATE payments SET payment_method = ?, payment_reference = ?, payment_status = 'pending', payment_date = NOW() WHERE payment_id = ? AND booking_id = ?")
                    ->execute([$method, $reference !== '' ? $reference : null, $existingPaymentId, $booking_id]);
            } else {
                $pdo->prepare("INSERT INTO payments (booking_id, tourist_id, host_id, amount, payment_method, payment_reference, payment_status, payment_date) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())")
                    ->execute([$booking_id, $_SESSION['user_id'], $booking['host_id'], $split['host_amount'], $method, $reference !== '' ? $reference : null]);
                $existingPaymentId = (int)$pdo->lastInsertId();
            }
            if ($proofName && $existingPaymentId) {
                $pdo->prepare("UPDATE payments SET payment_proof = ? WHERE payment_id = ? AND tourist_id = ?")->execute([$proofName, $existingPaymentId, $_SESSION['user_id']]);
            }

            // Payment to StayExplore Kenya (service fee + commission), same transaction.
            if ($needPlatform) {
                $ex = $pdo->prepare('SELECT platform_payment_id, status, method, reference FROM platform_payments WHERE booking_id = ? FOR UPDATE'); $ex->execute([$booking_id]); $exP = $ex->fetch(PDO::FETCH_ASSOC);
                if (!$exP) {
                    $pdo->prepare('INSERT INTO platform_payments (booking_id, tourist_id, host_id, amount, service_fee, commission_amount, method, reference, proof_file) VALUES (?,?,?,?,?,?,?,?,?)')
                        ->execute([$booking_id, $_SESSION['user_id'], $booking['host_id'], $split['platform'], $split['service_fee'], $split['commission_upfront'], $pMethod, $pRef, $pProof]);
                    $platformChanged = true;
                } elseif ($exP['status'] !== 'confirmed' && ($exP['method'] !== $pMethod || (string)$exP['reference'] !== $pRef || $pProof)) {
                    $pdo->prepare("UPDATE platform_payments SET method = ?, reference = ?, proof_file = COALESCE(?, proof_file), status = 'pending', reviewed_at = NULL, reviewed_by = NULL, review_note = NULL WHERE platform_payment_id = ?")
                        ->execute([$pMethod, $pRef, $pProof, $exP['platform_payment_id']]);
                    $platformChanged = true;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $discardProofs();
            error_log('Payment submit failed (booking ' . $booking_id . '): ' . $e->getMessage());
            $errors[] = ((int)($e instanceof PDOException ? ($e->errorInfo[1] ?? 0) : 0) === 1062)
                ? 'This payment reference is already used for another payment. Please check it and try again.'   // unique key caught a simultaneous duplicate
                : 'We could not record your payment just now, and nothing was saved. Please try again in a moment.';
        }
    }

    if (empty($errors)) {
        // Committed. Now tell people (a failure here never undoes the payment).
        if ($platformChanged) {
            notifyAdmins($pdo, 'Guest payment to check', 'KES ' . number_format($split['platform'], 2) . ' reported for ' . bookingLabel($booking_id) . ' (ref ' . $pRef . '). Compare it with your statement.', 'admin/revenue.php');
            try { require_once '../includes/booking_holds.php';
                foreach (adminEmailAddresses($pdo) as $to) sendPlatformPaymentAdminEmail($to, $_SESSION['full_name'] ?? 'A guest', $booking['title'], $booking_id, $split['platform'], $pRef); }
            catch (Throwable $e) { error_log('Platform payment email: ' . $e->getMessage()); }
        }
        // Details submitted, but nothing is confirmed yet: tell the host to check their account, and acknowledge to the guest.
        try {
            $mailBooking = ['booking_id' => $booking_id, 'title' => $booking['title'], 'check_in_date' => $booking['check_in_date'],
                            'check_out_date' => $booking['check_out_date'], 'total_amount' => $split['host_amount']];   // the host expects only the host's share
            sendPaymentSubmittedHostEmail($booking['host_email'], $booking['host_name'], $mailBooking, $method, $reference);
            $guestMail = $mailBooking + ['platform_amount' => $needPlatform ? $split['platform'] : 0, 'platform_reference' => $pRef];
            sendPaymentConfirmationEmail($_SESSION['email'] ?? '', $_SESSION['full_name'] ?? '', $guestMail, $method, $reference, $booking['host_name'] ?? '');
            notify($pdo, (int)$booking['host_id'], 'Payment details received', 'KES ' . number_format($split['host_amount'], 2) . ' reported for ' . bookingLabel($booking_id) . ' (' . paymentMethodLabel($method) . ($reference !== '' ? ', ref ' . $reference : '') . '). Check your account, then mark it received.', 'host/payments.php');
        } catch (Throwable $e) { error_log('Payment emails: ' . $e->getMessage()); }

        redirect('confirmation.php?ref=' . urlencode(bookingRef($booking_id)));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay your host — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <style>
        
        .deadline-box { background:var(--surface-2); border:1px solid var(--border); border-left:3px solid var(--accent); border-radius:6px; padding:14px 16px; margin-bottom:22px; font-size:14px; line-height:1.6; display:grid; gap:6px }
        .host-instr  { font-size:13px; color:var(--text-secondary); padding-top:8px; border-top:1px dashed var(--border) }
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
                       border-color:var(--accent);
                       background:#F0FAF6 }
        .method-opt input[type=radio] { margin-top:3px; accent-color:#126b4e;
                                        flex-shrink:0 }
        .method-opt strong { font-size:14px; font-weight:500;
                             display:block; margin-bottom:2px }
        .method-opt small  { font-size:12px; color:var(--text-secondary) }
        .ref-field   { margin-top:16px }
        .notice-box  { background:var(--warn-bg); border:1px solid var(--warn-line);
                       border-radius:6px; padding:12px 14px; font-size:13px;
                       color:var(--warn-fg); margin-top:16px; display:none }
        .confirm-row { display:flex; align-items:flex-start; gap:10px;
                       margin:20px 0 }
        .confirm-row input { margin-top:2px; accent-color:#126b4e;
                             width:15px; height:15px; flex-shrink:0 }
        .confirm-row label { font-size:13px; color:var(--text-secondary);
                             line-height:1.5 }
        .pay-btn     { width:100%; padding:12px; background:var(--btn-hover);
                       color:#fff; border:none; border-radius:6px;
                       font-size:14px; font-weight:500; cursor:pointer }
        .pay-btn:hover { background:#0a6350 }
        .summary-card { background:var(--surface-2);
                        border:1px solid var(--border);
                        border-radius:8px; overflow:hidden;
                        position:sticky; top:80px }
        .summary-img  { height:150px; background:var(--accent-soft);
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
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body class="tourist-payment">

<?php include '../includes/header.php'; ?>

<div class="page-wrap">

    <div class="page-tag">Payment</div>
    <h1 class="page-title">Pay your host</h1>
    <p class="page-sub">
        Your host accepted this booking. Pay your host directly, then enter the payment details below.
        StayExplore Kenya does not receive or hold this money. Your booking is confirmed when your host confirms they received it.
    </p>
    <div class="deadline-box">
        <div><strong>Booking <?= h(bookingLabel($booking_id)) ?></strong> &middot; <?= h($booking['title']) ?> &middot; <?= (int)$nights ?> night<?= $nights == 1 ? '' : 's' ?></div>
        <div>Amount to pay your host: <strong>KES <?= number_format($split['host_amount']) ?></strong> &middot; Pay before <strong><?= h(date('j M Y, H:i', strtotime($booking['payment_due_at']))) ?></strong> or the dates are released.</div>
        <?php if (!empty($booking['host_instr'])): ?><div class="host-instr"><strong>How to pay <?= h($booking['host_name']) ?>:</strong><br><?= nl2br(h($booking['host_instr'])) ?></div>
        <?php else: ?><div class="host-instr">Your host has not added payment details yet. Message your host from the Messages page to ask for their M-Pesa or bank details.</div><?php endif; ?>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error" style="margin-bottom:20px">
        <?php foreach ($errors as $e): ?>
            <p><?= h($e) ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <div class="pay-layout">

        <!-- Left — Payment Form -->
        <div class="pay-card">
            <h3><?= $needPlatform ? '1. Pay your host &mdash; KES ' . number_format($split['host_amount']) : 'Payment method' ?></h3>

            <div class="method-list">
                <label class="method-opt">
                    <input type="radio" name="payment_method"
                           value="bank_transfer" required
                           onchange="showHint(this)">
                    <div>
                        <strong>Bank transfer</strong>
                        <small>
                            Use the bank details your host gave you above or in Messages, then enter the transfer reference below.
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
                        Transaction code <span style="color:var(--bad-fg,#b3261e)" aria-hidden="true">*</span> <span class="small">(required)</span>
                    </label>
                    <input type="text" id="payment_reference"
                           name="payment_reference"
                           maxlength="40" aria-required="true"
                           autocomplete="off" autocapitalize="characters" spellcheck="false"
                           value="<?= h((string)($_POST['payment_reference'] ?? '')) ?>"
                           placeholder="M-Pesa code (e.g. QGH7ABC123) or bank reference">
                    <div class="small">We cannot send your details to the host without it. It is how the host finds your payment.</div>
                </div>
                <div class="form-group">
                    <label for="payment_proof">Screenshot or receipt of your payment (optional)</label>
                    <input type="file" id="payment_proof" name="payment_proof" accept="image/jpeg,image/png,image/webp">
                    <div class="small">JPG, PNG or WebP, up to 5 MB. Your host can see it when checking your payment.</div>
                </div>
            </div>

            <?php if ($needPlatform): ?>
            <div class="pay-card" style="margin-top:18px;background:#f3f8f5">
                <h3>2. Pay StayExplore Kenya &mdash; KES <?= number_format($split['platform']) ?></h3>
                <p class="small"><?= nl2br(h($platformInstructions)) ?></p>
                <div class="method-list">
                    <label class="method-opt"><input type="radio" name="platform_method" value="mpesa" required> M-Pesa</label>
                    <label class="method-opt"><input type="radio" name="platform_method" value="bank_transfer"> Bank transfer</label>
                </div>
                <div class="form-group">
                    <label for="platform_reference">M-Pesa code or bank reference for this payment</label>
                    <input type="text" id="platform_reference" name="platform_reference" maxlength="120" required placeholder="e.g. QGH7XXXXXX">
                </div>
                <div class="form-group">
                    <label for="platform_proof">Screenshot of this payment (optional)</label>
                    <input type="file" id="platform_proof" name="platform_proof" accept="image/jpeg,image/png,image/webp">
                    <div class="small">JPG, PNG or WebP, up to 5 MB. The admin checks it against the money received.</div>
                </div>
            </div>
            <?php endif; ?>

            <div class="confirm-row">
                <input type="checkbox" id="confirmed"
                       name="confirmed" required>
                <label for="confirmed">
                    I confirm the booking amount of
                    <strong>KES <?= number_format($booking['total_amount']) ?></strong><?php if ($needPlatform): ?> (KES <?= number_format($split['host_amount']) ?> to my host and KES <?= number_format($split['platform']) ?> to StayExplore Kenya)<?php endif; ?>
                    <?php if ($needPlatform): ?>(this includes a KES <?= number_format($br['service_fee']) ?> service fee) and agree to pay each part directly, as shown above.<?php else: ?>and agree to complete payment to my host
                    by the selected method.<?php endif; ?>
                </label>
            </div>

            <p class="small" style="margin:0 0 12px"><?php bookingRulesDialog(); ?> (deadlines, cancelling and refunds)</p>
            <button type="submit" class="pay-btn">
                I have paid &mdash; send details to my host
            </button>
        </div>

        <!-- Right — Summary -->
        <div>
            <div class="summary-card">
                <div class="summary-img">
                    <?php if ($booking['primary_photo']): ?>
                        <img src="../uploads/accommodations/<?= h($booking['primary_photo']) ?>"
                             alt="<?= h($booking['title']) ?>" loading="lazy" decoding="async">
                    <?php else: ?>
                        <span style="font-size:12px;color:var(--text-tertiary)">No photo</span>
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
                    <?php if ($needPlatform): ?>
                    <div class="p-row"><span>Stay and extras, paid to your host</span><span>KES <?= number_format($split['host_amount']) ?></span></div>
                    <div class="p-row"><span>Paid to StayExplore Kenya (incl. KES <?= number_format($split['service_fee']) ?> service fee)</span><span>KES <?= number_format($split['platform']) ?></span></div>
                    <div class="p-row total"><span>Total you pay</span><span>KES <?= number_format($split['total']) ?><?= approxHtml($split['total']) ?></span></div>
                    <?php else: ?>
                    <div class="p-row total">
                        <span>Total to pay host</span>
                        <span>KES <?= number_format($booking['total_amount']) ?></span>
                    </div>
                    <?php endif; ?>
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
            <input type="hidden" name="booking_ref" value="<?= h(bookingRef($booking['booking_id'])) ?>">
            <button type="submit" style="background:none;border:0;color:var(--bad-fg);font:inherit;font-size:13px;cursor:pointer;text-decoration:underline">Cancel this booking</button>
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
    var needRef = (input.value === 'bank_transfer' || input.value === 'mpesa');
    refField.style.display = needRef ? 'block' : 'none';
    var refInput = document.getElementById('payment_reference');
    if (refInput) refInput.required = needRef;   // the transaction code is mandatory whenever it is shown
}
(function () {   // keep the field visible after a failed submit
    var c = document.querySelector('input[name="payment_method"]:checked');
    if (c) showHint(c);
})();
</script>


<!-- Processing overlay shown briefly while the payment details are submitted -->
<style>
#payProcessing{position:fixed;inset:0;background:rgba(13,59,46,.55);display:none;align-items:center;justify-content:center;z-index:9999;padding:20px}
#payProcessing.show{display:flex}
.pp-box{background:var(--surface-2);border-radius:14px;padding:30px 28px;width:100%;max-width:380px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.pp-box h4{margin:0 0 6px;font-size:18px;color:var(--heading)}
.pp-step{font-size:13px;color:var(--text-secondary);min-height:20px;margin:0 0 18px}
.pp-track{height:10px;background:#e8e5dc;border-radius:99px;overflow:hidden;position:relative}
.pp-fill{height:100%;width:0;background:linear-gradient(90deg,#16866b,#a3462a);border-radius:99px;transition:width .35s ease}
.pp-pct{font-size:12px;font-weight:700;color:var(--heading);margin-top:10px}
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