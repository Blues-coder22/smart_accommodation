<?php
// Printable booking and payment record. Shared page: ?as=tourist|host|admin&ref=BOOKINGREF. Each role sees only what it should:
// the guest sees both payments, the host sees their share and commission, the admin sees everything.
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/db.php';
require_once 'includes/commission.php';
require_once 'includes/payment_audit.php';
require_once 'includes/booking_events.php';
require_once 'includes/email.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
ensureCommissionTables($pdo); ensurePaymentAuditTable($pdo); ensureBookingEventsTable($pdo);
$uid = (int)$_SESSION['user_id']; $isAdmin = hasRole('admin');
$bid = bookingIdFromRef($_GET['ref'] ?? '');
$st = $pdo->prepare("SELECT b.*, a.title, a.location_name, a.host_id, t.full_name AS tourist_name, hu.full_name AS host_name, hu.phone_number AS host_phone
    FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id JOIN users t ON t.user_id = b.tourist_id JOIN users hu ON hu.user_id = a.host_id WHERE b.booking_id = ?");
$st->execute([$bid]); $b = $st->fetch(PDO::FETCH_ASSOC);
$isGuest = $b && $uid === (int)$b['tourist_id']; $isHost = $b && $uid === (int)$b['host_id'];
if (!$b || !($isAdmin || $isGuest || $isHost)) { http_response_code(404); exit('Record not found.'); }
$sp = bookingSplit($b);
$pay = $pdo->prepare("SELECT * FROM payments WHERE booking_id = ? ORDER BY payment_id DESC LIMIT 1"); $pay->execute([$bid]); $p = $pay->fetch(PDO::FETCH_ASSOC);
$pp = $pdo->prepare("SELECT * FROM platform_payments WHERE booking_id = ?"); $pp->execute([$bid]); $pl = $pp->fetch(PDO::FETCH_ASSOC);
$ev = $pdo->prepare("SELECT created_at, event, note FROM booking_events WHERE booking_id = ? AND event NOT IN ('evidence_viewed') ORDER BY event_id"); $ev->execute([$bid]); $events = $ev->fetchAll(PDO::FETCH_ASSOC);
$nights = max(1, (int)((strtotime($b['check_out_date']) - strtotime($b['check_in_date'])) / 86400));
$role = $isAdmin ? 'admin' : ($isHost ? 'host' : 'tourist');
$back = ['admin' => 'admin/bookings.php', 'host' => 'host/bookings.php', 'tourist' => 'tourist/bookings.php'][$role];
$pm = fn($m) => paymentMethodLabel($m);
?>
<!DOCTYPE html>
<html lang="en" translate="no" class="notranslate">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking record <?= h(bookingLabel($bid)) ?> — StayExplore Kenya</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/finance.css">
    <style>body{background:#f6f1e7}.br-wrap{max-width:820px;margin:20px auto;padding:0 12px}.br-sheet{background:#fff;border:1px solid #d9d2c3;border-radius:8px;padding:28px 32px}.br-sheet h1{margin:0 0 2px;font-size:24px}.br-sheet h2{font-size:16px;margin:20px 0 8px;border-bottom:1px solid #e4ddcf;padding-bottom:4px}.br-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px 24px}.br-grid dt{font-size:12px;text-transform:uppercase;color:#565c55;margin-top:8px}.br-grid dd{margin:2px 0 0;font-weight:500}@media print{body{background:#fff}.br-wrap{max-width:none;margin:0;padding:0}.br-sheet{border:0;padding:0}.no-print{display:none!important}}@media(max-width:600px){.br-grid{grid-template-columns:1fr}.br-sheet{padding:18px}}</style>
</head>
<body>
<div class="br-wrap">
<div class="no-print fin-actions" style="margin-bottom:10px"><button class="fin-btn" type="button" onclick="window.print()">Print or save as PDF</button><a class="fin-btn ghost" href="<?= h($back) ?>">Back</a></div>
<main class="br-sheet" id="main-content">
    <h1>Booking and payment record</h1>
    <div class="small">StayExplore Kenya · <?= h(bookingLabel($bid)) ?> · printed <?= h(date('j M Y, H:i')) ?> for <?= h($role === 'admin' ? 'the admin' : ($role === 'host' ? 'the host' : 'the guest')) ?></div>
    <h2>Booking</h2>
    <dl class="br-grid">
        <div><dt>Stay</dt><dd><?= h($b['title']) ?><br><span class="small"><?= h($b['location_name']) ?></span></dd></div>
        <div><dt>Status</dt><dd><?= h(ucfirst($b['status'])) ?></dd></div>
        <div><dt>Check-in</dt><dd><?= h($b['check_in_date']) ?></dd></div>
        <div><dt>Check-out</dt><dd><?= h($b['check_out_date']) ?> (<?= $nights ?> night<?= $nights > 1 ? 's' : '' ?>, <?= (int)$b['num_guests'] ?> guest<?= (int)$b['num_guests'] > 1 ? 's' : '' ?>)</dd></div>
        <div><dt>Guest</dt><dd><?= h($b['tourist_name']) ?></dd></div>
        <div><dt>Host</dt><dd><?= h($b['host_name']) ?><?= $b['host_phone'] ? ' · ' . h($b['host_phone']) : '' ?></dd></div>
        <div><dt>Requested</dt><dd><?= h(date('j M Y, H:i', strtotime($b['created_at']))) ?></dd></div>
    </dl>
    <h2>Amounts</h2>
    <dl class="br-grid">
    <?php if ($isHost && !$isAdmin && !$sp['legacy']): ?>
        <div><dt>Your price</dt><dd><?= kes($sp['total'] - $sp['service_fee']) ?></dd></div>
        <div><dt>Platform commission (<?= h(pct($b['commission_rate'])) ?>%)</dt><dd>– <?= kes($sp['commission']) ?></dd></div>
        <div><dt>The guest pays you</dt><dd><strong><?= kes($sp['host_amount']) ?></strong></dd></div>
    <?php else: ?>
        <div><dt>Booking total</dt><dd><strong><?= kes($sp['total']) ?></strong></dd></div>
        <?php if (!$sp['legacy'] && $sp['platform'] > 0): ?>
        <div><dt>Paid to the host</dt><dd><?= kes($sp['host_amount']) ?></dd></div>
        <div><dt>Paid to StayExplore Kenya</dt><dd><?= kes($sp['platform']) ?> <span class="small">(service fee <?= kes($sp['service_fee']) ?><?= $isAdmin ? ' + commission ' . kes($sp['commission']) : ' and the platform share of the price' ?>)</span></dd></div>
        <?php endif; ?>
    <?php endif; ?>
    </dl>
    <h2>Payments</h2>
    <?php if (!$p && !$pl): ?><p>No payment has been entered for this booking.</p><?php endif; ?>
    <?php if ($p): ?>
    <p><strong><?= $sp['legacy'] ? 'Payment to the host' : 'Host\'s share' ?>:</strong> <?= kes($p['amount']) ?> · <?= h($pm($p['payment_method'])) ?> · reference <?= h($p['payment_reference'] ?: 'none') ?> · entered <?= h($p['payment_date']) ?> · status <strong><?= h($p['payment_status']) ?></strong>
        <?php if ($p['payment_status'] === 'confirmed'): ?><br>Receipt <?= h(receiptNumber($p['payment_id'])) ?> · verification code <span style="font-family:monospace"><?= h(receiptVerificationCode($p)) ?></span><?php endif; ?></p>
    <?php endif; ?>
    <?php if ($pl && ($isGuest || $isAdmin)): ?>
    <p><strong>StayExplore Kenya's share:</strong> <?= kes($pl['amount']) ?> · <?= h($pl['method'] === 'mpesa' ? 'M-Pesa' : 'Bank transfer') ?> · reference <?= h($pl['reference']) ?> · entered <?= h($pl['created_at']) ?> · status <strong><?= h($pl['status']) ?></strong>
        <?php if ($pl['status'] === 'confirmed' || $pl['status'] === 'refunded'): ?><br>Receipt <?= h(receiptNumber($pl['platform_payment_id'], true)) ?> · verification code <span style="font-family:monospace"><?= h(platformReceiptVerificationCode($pl)) ?></span><?php endif; ?>
        <?php if ($pl['refund_due'] !== null): ?><br>Refund: <?= (float)$pl['refund_due'] > 0 ? 'KES ' . number_format((float)$pl['refund_due'], 2) . ' due' : 'none due' ?> (<?= h((string)$pl['refund_note']) ?>)<?= $pl['status'] === 'refunded' ? ' · refunded KES ' . number_format((float)$pl['refunded_amount'], 2) : '' ?><?php endif; ?></p>
    <?php endif; ?>
    <h2>History</h2>
    <?php if (!$events): ?><p>No events recorded.</p><?php else: ?><ul style="margin:0;padding-left:18px"><?php foreach ($events as $e): ?><li><?= h(date('j M Y, H:i', strtotime($e['created_at']))) ?> · <?= h(str_replace('_', ' ', $e['event'])) ?><?= $e['note'] ? ' — ' . h($e['note']) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
    <p class="small" style="margin-top:22px;border-top:1px solid #e4ddcf;padding-top:10px">Produced by StayExplore Kenya from its records. The platform does not hold guests' money: each part is paid directly to its owner and confirmed by that owner. Keep this record as evidence.</p>
</main>
</div>
</body>
</html>
