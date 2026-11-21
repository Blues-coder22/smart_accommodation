<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');
ensureCommissionTables($pdo);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$q = $pdo->prepare("SELECT pp.*, t.full_name AS guest, h.full_name AS host_name, a.title FROM platform_payments pp JOIN users t ON t.user_id = pp.tourist_id JOIN users h ON h.user_id = pp.host_id
    JOIN bookings b ON b.booking_id = pp.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    WHERE pp.status IN ('confirmed','refunded') AND DATE(COALESCE(pp.reviewed_at, pp.created_at)) BETWEEN ? AND ? ORDER BY COALESCE(pp.reviewed_at, pp.created_at)");
$q->execute([$from, $to]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
$fee = $comm = $total = $refunded = 0.0; $perHost = [];
foreach ($rows as $r) {
    if ($r['status'] === 'confirmed') { $fee += $r['service_fee']; $comm += $r['commission_amount']; $total += $r['amount']; $perHost[$r['host_name']] = ($perHost[$r['host_name']] ?? 0) + $r['amount']; }
    else $refunded += (float)($r['refunded_amount'] ?? $r['amount']);
}
arsort($perHost);
?>
<!DOCTYPE html>
<html lang="en" translate="no" class="notranslate">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue report — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/finance.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <div class="page-tag no-print">Admin</div>
        <h1 class="page-title">Revenue report</h1>
        <form method="GET" class="fin-box no-print" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
            <div><label class="fin-field" for="f">From</label><input class="fin-input" style="max-width:170px" type="date" id="f" name="from" value="<?= h($from) ?>"></div>
            <div><label class="fin-field" for="t">To</label><input class="fin-input" style="max-width:170px" type="date" id="t" name="to" value="<?= h($to) ?>"></div>
            <button class="fin-btn" type="submit">Show</button><button class="fin-btn ghost" type="button" onclick="window.print()">Print or save as PDF</button><a class="fin-btn ghost" href="revenue.php">Back</a>
        </form>
        <p class="page-sub">Platform payments accepted between <?= h($from) ?> and <?= h($to) ?>, printed <?= h(date('j M Y, H:i')) ?> by <?= h($_SESSION['full_name'] ?? 'the admin') ?>. Only payments you checked and accepted are counted as received.</p>
        <div class="fin-cards">
            <div class="fin-card accent"><div class="fin-label">Received</div><div class="fin-value"><?= kes($total) ?></div><div class="fin-note">fees <?= kes($fee) ?> + commission <?= kes($comm) ?></div></div>
            <div class="fin-card"><div class="fin-label">Refunded</div><div class="fin-value"><?= kes($refunded) ?></div></div>
            <div class="fin-card"><div class="fin-label">Net</div><div class="fin-value"><?= kes($total - $refunded) ?></div></div>
        </div>
        <?php if ($perHost): ?><div class="fin-box"><h2>By host</h2><table class="fin-table"><tbody><?php foreach ($perHost as $n => $v): ?><tr><td><?= h($n) ?></td><td class="num"><?= kes($v) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <div class="fin-box"><div class="fin-scroll"><table class="fin-table">
            <thead><tr><th>Date</th><th>Booking</th><th>Guest</th><th>Host</th><th>Reference</th><th class="num">Service fee</th><th class="num">Commission</th><th class="num">Total</th><th>Status</th></tr></thead><tbody>
            <?php if (!$rows): ?><tr><td colspan="9">Nothing in this period.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?><tr><td><?= h(date('j M Y', strtotime($r['reviewed_at'] ?: $r['created_at']))) ?></td><td><?= h(bookingLabel($r['booking_id'])) ?></td><td><?= h($r['guest']) ?></td><td><?= h($r['host_name']) ?></td><td><?= h($r['reference']) ?></td><td class="num"><?= kes($r['service_fee']) ?></td><td class="num"><?= kes($r['commission_amount']) ?></td><td class="num"><?= kes($r['amount']) ?></td><td><?= h(ucfirst($r['status'])) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div>
<?php appShellEnd(); ?>
</body>
</html>
