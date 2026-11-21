<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';
require_once '../includes/email.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('host');
$host_id = (int)$_SESSION['user_id'];
ensureCommissionTables($pdo);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$q = $pdo->prepare("SELECT p.*, b.booking_id, b.total_amount, b.service_fee, b.commission_amount, b.check_in_date, a.title, t.full_name AS guest
    FROM payments p JOIN bookings b ON b.booking_id = p.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id JOIN users t ON t.user_id = p.tourist_id
    WHERE p.host_id = ? AND DATE(p.payment_date) BETWEEN ? AND ? ORDER BY p.payment_date");
$q->execute([$host_id, $from, $to]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
$tConf = $tPend = $tComm = 0.0;
foreach ($rows as $r) { if ($r['payment_status'] === 'confirmed') { $tConf += (float)$r['amount']; $tComm += (float)($r['commission_amount'] ?? 0); } elseif ($r['payment_status'] === 'pending') $tPend += (float)$r['amount']; }
?>
<!DOCTYPE html>
<html lang="en" translate="no" class="notranslate">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment statement — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/finance.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <div class="page-tag no-print">Hosting</div>
        <h1 class="page-title">Payment statement</h1>
        <form method="GET" class="fin-box no-print" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
            <div><label class="fin-field" for="f">From</label><input class="fin-input" style="max-width:170px" type="date" id="f" name="from" value="<?= h($from) ?>"></div>
            <div><label class="fin-field" for="t">To</label><input class="fin-input" style="max-width:170px" type="date" id="t" name="to" value="<?= h($to) ?>"></div>
            <button class="fin-btn" type="submit">Show</button><button class="fin-btn ghost" type="button" onclick="window.print()">Print or save as PDF</button>
        </form>
        <p class="page-sub">Payments from guests to <strong><?= h($_SESSION['full_name'] ?? 'you') ?></strong> entered between <?= h($from) ?> and <?= h($to) ?>. Printed <?= h(date('j M Y, H:i')) ?>. Amounts are what the guest pays you (your price minus the platform commission).</p>
        <div class="fin-cards">
            <div class="fin-card accent"><div class="fin-label">Confirmed received</div><div class="fin-value"><?= kes($tConf) ?></div></div>
            <div class="fin-card warn"><div class="fin-label">Waiting for you to confirm</div><div class="fin-value"><?= kes($tPend) ?></div></div>
            <div class="fin-card"><div class="fin-label">Commission on these (paid by guests)</div><div class="fin-value"><?= kes($tComm) ?></div></div>
        </div>
        <div class="fin-box"><div class="fin-scroll"><table class="fin-table">
            <thead><tr><th>Entered</th><th>Booking</th><th>Guest</th><th>Method / reference</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>
            <?php if (!$rows): ?><tr><td colspan="6">No payments in this period.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?><tr><td><?= h(date('j M Y', strtotime($r['payment_date']))) ?></td><td><?= h(bookingLabel($r['booking_id'])) ?><br><span class="small"><?= h($r['title']) ?></span></td><td><?= h($r['guest']) ?></td>
                <td><?= h(paymentMethodLabel($r['payment_method'])) ?><br><span class="small"><?= h($r['payment_reference'] ?: '') ?></span></td><td><?= h(ucfirst($r['payment_status'])) ?></td><td class="num"><?= kes($r['amount']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div>
<?php appShellEnd(); ?>
</body>
</html>
