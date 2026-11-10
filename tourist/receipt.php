<?php
// Printable payment receipt. The guest, the host of the stay and administrators can open it; only for payments the host confirmed.
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';
require_once '../includes/payment_audit.php';
require_once '../includes/email.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
ensurePaymentAuditTable($pdo); ensureCommissionTables($pdo);

$pid = (int)($_GET['payment_id'] ?? 0);
$st = $pdo->prepare("SELECT p.*, b.service_fee, b.check_in_date, b.check_out_date, b.num_guests, b.status AS booking_status, a.title, a.location_name,
        t.full_name AS tourist_name, t.email AS tourist_email, hu.full_name AS host_name, hu.phone_number AS host_phone
    FROM payments p JOIN bookings b ON b.booking_id = p.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users t ON t.user_id = p.tourist_id JOIN users hu ON hu.user_id = p.host_id WHERE p.payment_id = ?");
$st->execute([$pid]); $p = $st->fetch(PDO::FETCH_ASSOC);
$uid = (int)$_SESSION['user_id'];
if (!$p || !(hasRole('admin') || $uid === (int)$p['tourist_id'] || $uid === (int)$p['host_id'])) { http_response_code(404); exit('Receipt not found.'); }

$confirmed = $p['payment_status'] === 'confirmed';
$when = $pdo->prepare("SELECT created_at FROM payment_audit WHERE payment_id = ? AND new_status = 'confirmed' ORDER BY audit_id DESC LIMIT 1");
$when->execute([$pid]); $confirmedAt = $when->fetchColumn() ?: $p['payment_date'];
$nights = max(1, (int)((strtotime($p['check_out_date']) - strtotime($p['check_in_date'])) / 86400));
$back = hasRole('admin') ? '../admin/payments.php' : (hasRole('host') ? '../host/payments.php' : 'bookings.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?= h(receiptNumber($pid)) ?> — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/finance.css">
    <style>
        body{background:#f6f1e7}
        .rc-sheet{max-width:720px;margin:24px auto;background:#fff;border:1px solid #d9d2c3;border-radius:8px;padding:32px 36px}
        .rc-head{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;border-bottom:2px solid #126b4e;padding-bottom:14px;margin-bottom:18px}
        .rc-head h1{margin:0;font-size:26px}.rc-no{font-size:18px;font-weight:700;text-align:right}
        .rc-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 24px}
        .rc-grid dt{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#565c55;margin-top:8px}.rc-grid dd{margin:2px 0 0;font-weight:500}
        .rc-total{margin:20px 0;padding:14px 18px;background:#eaf4ee;border-radius:6px;display:flex;justify-content:space-between;font-size:20px;font-weight:700}
        .rc-void{background:#fff6dc;border:1px solid #e3c36a;border-radius:6px;padding:10px 14px;margin-bottom:14px}
        .rc-foot{font-size:12px;color:#565c55;border-top:1px solid #e4ddcf;padding-top:12px;margin-top:18px;line-height:1.5}
        .rc-bar{max-width:720px;margin:16px auto 0;display:flex;gap:10px;flex-wrap:wrap;padding:0 8px}
        @media print{body{background:#fff}.rc-sheet{border:0;margin:0;padding:0;max-width:none}.rc-bar{display:none}}
        @media(max-width:560px){.rc-sheet{padding:20px}.rc-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="rc-bar no-print"><button class="fin-btn" type="button" onclick="window.print()">Print or save as PDF</button><a class="fin-btn ghost" href="<?= h($back) ?>">Back</a></div>
<main class="rc-sheet" id="main-content">
    <div class="rc-head">
        <div><h1>Payment receipt</h1><div class="small">StayExplore Kenya · records payments made directly to hosts</div></div>
        <div class="rc-no"><?= h(receiptNumber($pid)) ?><div class="small" style="font-weight:400">Issued <?= h(date('j M Y, H:i', strtotime($confirmedAt))) ?></div></div>
    </div>
    <?php if (!$confirmed): ?><div class="rc-void"><strong>Not a valid receipt yet.</strong> The host has not confirmed this payment (status: <?= h($p['payment_status']) ?>).</div><?php endif; ?>
    <dl class="rc-grid">
        <div><dt>Received from (guest)</dt><dd><?= h($p['tourist_name']) ?></dd></div>
        <div><dt>Paid to (host)</dt><dd><?= h($p['host_name']) ?><?= $p['host_phone'] ? ' · ' . h($p['host_phone']) : '' ?></dd></div>
        <div><dt>Stay</dt><dd><?= h($p['title']) ?><br><span class="small"><?= h($p['location_name']) ?></span></dd></div>
        <div><dt>Booking</dt><dd>#BK<?= str_pad($p['booking_id'], 5, '0', STR_PAD_LEFT) ?></dd></div>
        <div><dt>Check-in</dt><dd><?= h($p['check_in_date']) ?></dd></div>
        <div><dt>Check-out</dt><dd><?= h($p['check_out_date']) ?> (<?= $nights ?> night<?= $nights > 1 ? 's' : '' ?>, <?= (int)$p['num_guests'] ?> guest<?= (int)$p['num_guests'] > 1 ? 's' : '' ?>)</dd></div>
        <div><dt>Payment method</dt><dd><?= h(paymentMethodLabel($p['payment_method'])) ?></dd></div>
        <div><dt>Transaction reference</dt><dd><?= h($p['payment_reference'] ?: 'None given') ?></dd></div>
    </dl>
    <?php if (!empty($p['service_fee']) && $p['service_fee'] > 0): ?>
    <dl class="rc-grid" style="margin-top:14px">
        <div><dt>Stay and extras (host)</dt><dd><?= kes($p['amount'] - $p['service_fee']) ?></dd></div>
        <div><dt>Service fee</dt><dd><?= kes($p['service_fee']) ?></dd></div>
    </dl>
    <?php endif; ?>
    <div class="rc-total"><span>Total paid</span><span><?= kes($p['amount']) ?></span></div>
    <p><strong>Status:</strong> <?= $confirmed ? 'Payment confirmed received by the host' : h(ucfirst($p['payment_status'])) ?></p>
    <?php if ($confirmed): ?><p><strong>Verification code:</strong> <span style="font-family:monospace;font-size:18px;letter-spacing:.08em"><?= h(receiptVerificationCode($p)) ?></span></p><?php endif; ?>
    <div class="rc-foot">This receipt was produced by StayExplore Kenya from the host's confirmation of payment. The platform does not hold guests' money; payment is made directly to the host. In a dispute, an administrator can check the verification code against the system record. Keep this receipt as evidence.</div>
</main>
</body>
</html>
