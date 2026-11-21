<?php
// Printable payment receipt (shared page: pass ?as=tourist|host|admin so the right signed-in role is used). The guest, the host of the stay and admins can open it; only for payments the host confirmed.
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/db.php';
require_once 'includes/commission.php';
require_once 'includes/payment_audit.php';
require_once 'includes/email.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
ensureCommissionTables($pdo);

$id = opaqueIdDecode('plat', $_GET['ref'] ?? '');
$st = $pdo->prepare("SELECT pp.*, b.check_in_date, b.check_out_date, b.num_guests, a.title, a.location_name,
        t.full_name AS tourist_name, hu.full_name AS host_name
    FROM platform_payments pp JOIN bookings b ON b.booking_id = pp.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users t ON t.user_id = pp.tourist_id JOIN users hu ON hu.user_id = pp.host_id WHERE pp.platform_payment_id = ?");
$st->execute([$id]); $p = $st->fetch(PDO::FETCH_ASSOC);
$uid = (int)$_SESSION['user_id'];
if (!$p || !(hasRole('admin') || $uid === (int)$p['tourist_id'])) { http_response_code(404); exit('Receipt not found.'); }   // the host does not see the platform's side
$confirmed = $p['status'] === 'confirmed';
$confirmedAt = $p['reviewed_at'] ?: $p['created_at'];
$back = hasRole('admin') ? 'admin/revenue.php' : 'tourist/bookings.php';
?>
<!DOCTYPE html>
<html lang="en" translate="no" class="notranslate">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?= h(receiptNumber($id, true)) ?> — StayExplore Kenya</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/finance.css">
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
        <div><h1>Payment receipt</h1><div class="small">StayExplore Kenya · platform share of a booking</div></div>
        <div class="rc-no"><?= h(receiptNumber($id, true)) ?><div class="small" style="font-weight:400">Issued <?= h(date('j M Y, H:i', strtotime($confirmedAt))) ?></div></div>
    </div>
    <?php if (!$confirmed): ?><div class="rc-void"><strong>Not a valid receipt yet.</strong> StayExplore Kenya has not confirmed this payment (status: <?= h($p['status']) ?>).</div><?php endif; ?>
    <dl class="rc-grid">
        <div><dt>Received from (guest)</dt><dd><?= h($p['tourist_name']) ?></dd></div>
        <div><dt>Paid to</dt><dd>StayExplore Kenya</dd></div>
        <div><dt>Stay</dt><dd><?= h($p['title']) ?><br><span class="small"><?= h($p['location_name']) ?> · host <?= h($p['host_name']) ?></span></dd></div>
        <div><dt>Booking</dt><dd><?= h(bookingLabel($p['booking_id'])) ?></dd></div>
        <div><dt>Check-in</dt><dd><?= h($p['check_in_date']) ?></dd></div>
        <div><dt>Check-out</dt><dd><?= h($p['check_out_date']) ?></dd></div>
        <div><dt>Payment method</dt><dd><?= $p['method'] === 'mpesa' ? 'M-Pesa' : 'Bank transfer' ?></dd></div>
        <div><dt>Transaction reference</dt><dd><?= h($p['reference']) ?></dd></div>
        <div><dt>Service fee</dt><dd><?= kes($p['service_fee']) ?></dd></div>
        <div><dt>Platform share of the stay price</dt><dd><?= kes($p['commission_amount']) ?></dd></div>
    </dl>
    <div class="rc-total"><span>Total paid</span><span><?= kes($p['amount']) ?></span></div>
    <p><strong>Status:</strong> <?= $confirmed ? 'Payment confirmed received by StayExplore Kenya' : h(ucfirst($p['status'])) ?></p>
    <?php if ($confirmed): ?><p><strong>Verification code:</strong> <span style="font-family:monospace;font-size:18px;letter-spacing:.08em"><?= h(platformReceiptVerificationCode($p)) ?></span></p><?php endif; ?>
    <div class="rc-foot">This is StayExplore Kenya's share of your booking. Your host's share is paid directly to your host and has its own receipt. Keep both receipts as evidence.</div>
</main>
</body>
</html>
