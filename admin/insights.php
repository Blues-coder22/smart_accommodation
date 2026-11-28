<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';
require_once '../includes/report_helpers.php';
require_once '../includes/chart_helpers.php';
require_once '../includes/refund_requests.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('admin');
ensureCommissionTables($pdo);

// Same period filter as the overview (default: last 30 days).
$per = reportPeriod('last_30'); $pr = [$per['from'], $per['to']];
$pq = $pdo->prepare("SELECT status, COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS v FROM bookings WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status");
$pq->execute($pr);
$pBook = ['all' => 0, 'value' => 0.0, 'won' => 0, 'lost' => 0];
foreach ($pq->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $pBook['all'] += (int)$r['n']; $pBook['value'] += (float)$r['v'];
    if (in_array($r['status'], ['confirmed', 'completed'], true)) $pBook['won'] += (int)$r['n'];
    elseif (in_array($r['status'], ['cancelled', 'rejected', 'declined', 'expired', 'no_show'], true)) $pBook['lost'] += (int)$r['n'];
}
$pGuestPaid = $pPlatform = $pRefunded = $pCommission = 0.0;
try {
    $q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status = 'confirmed' AND DATE(payment_date) BETWEEN ? AND ?"); $q->execute($pr); $pGuestPaid = (float)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status='confirmed' THEN amount END),0), COALESCE(SUM(CASE WHEN status='refunded' THEN COALESCE(refunded_amount, amount) END),0)
        FROM platform_payments WHERE status IN ('confirmed','refunded') AND DATE(COALESCE(reviewed_at, created_at)) BETWEEN ? AND ?"); $q->execute($pr); [$pPlatform, $pRefunded] = array_map('floatval', $q->fetch(PDO::FETCH_NUM));
    $q = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM host_commission_payments WHERE status = 'confirmed' AND DATE(COALESCE(reviewed_at, created_at)) BETWEEN ? AND ?"); $q->execute($pr); $pCommission = (float)$q->fetchColumn();
} catch (Throwable $e) { error_log('dashboard period summary: ' . $e->getMessage()); }
// Guest refunds recorded in the period (by hosts or by an administrator), from the refund cases
$pGuestRefund = 0.0; $pGuestRefundN = 0;
try {
    ensureRefundRequestTables($pdo);
    $q = $pdo->prepare("SELECT COALESCE(SUM(refund_amount),0), COUNT(*) FROM refund_requests WHERE status = 'refunded' AND DATE(refunded_at) BETWEEN ? AND ?");
    $q->execute($pr); [$pGuestRefund, $pGuestRefundN] = array_map('floatval', $q->fetch(PDO::FETCH_NUM));
} catch (Throwable $e) { error_log('insights refunds: ' . $e->getMessage()); }
// v9.38.1: chart data for the same period
$cb = chartBuckets($per['from'], $per['to']); $cKeys = $cb['keys']; $cUnit = $cb['unit'];
$cLabels = array_map(fn($k) => chartLabel($k, $cUnit), $cKeys);
$bx = $cb['expr']('b.created_at');
$cBook = chartSeries($pdo, "SELECT $bx AS k, COUNT(*) AS made, SUM(b.status IN ('confirmed','completed')) AS won FROM bookings b WHERE DATE(b.created_at) BETWEEN ? AND ? GROUP BY k", $pr, $cKeys, ['made', 'won']);
$px = $cb['expr']('p.payment_date'); $hx = $cb['expr']('COALESCE(c.reviewed_at, c.created_at)'); $ux = $cb['expr']('u.created_at');
$cGuestPaid = chartSeries($pdo, "SELECT $px AS k, SUM(p.amount) AS v FROM payments p WHERE p.payment_status = 'confirmed' AND DATE(p.payment_date) BETWEEN ? AND ? GROUP BY k", $pr, $cKeys, ['v']);
$cCommission = chartSeries($pdo, "SELECT $hx AS k, SUM(c.amount) AS v FROM host_commission_payments c WHERE c.status = 'confirmed' AND DATE(COALESCE(c.reviewed_at, c.created_at)) BETWEEN ? AND ? GROUP BY k", $pr, $cKeys, ['v']);
$cUsers = chartSeries($pdo, "SELECT $ux AS k, SUM(u.role = 'tourist') AS guests, SUM(u.role = 'host') AS hosts FROM users u WHERE u.role IN ('tourist','host') AND DATE(u.created_at) BETWEEN ? AND ? GROUP BY k", $pr, $cKeys, ['guests', 'hosts']);
$cStatus = []; $cTop = [];
try {
    $q = $pdo->prepare("SELECT status, COUNT(*) AS n FROM bookings WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status ORDER BY n DESC"); $q->execute($pr);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $cStatus[] = ['label' => ucfirst(str_replace('_', ' ', $r['status'])), 'value' => (int)$r['n']];
    $q = $pdo->prepare("SELECT a.title, COUNT(*) AS n FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE b.status IN ('confirmed','completed') AND DATE(b.created_at) BETWEEN ? AND ? GROUP BY a.accommodation_id, a.title ORDER BY n DESC LIMIT 5"); $q->execute($pr);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $cTop[] = ['label' => $r['title'], 'value' => (int)$r['n']];
} catch (Throwable $e) { error_log('dashboard chart lists: ' . $e->getMessage()); }

$backHref = 'dashboard.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Platform growth — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <style>
        .main        { padding:28px; overflow-y:auto }
        .page-date   { font-size:11px; font-weight:600; letter-spacing:.06em; color:var(--accent); text-transform:uppercase; margin-bottom:6px }
        .back-link   { display:inline-block; font-size:13px; color:var(--accent); text-decoration:none; margin-bottom:10px }
        .back-link:hover { text-decoration:underline }
        .section-card { background:var(--surface-2); border:1px solid var(--border); border-radius:8px; overflow:hidden; padding:18px }
        .stats-grid  { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; margin-bottom:12px }
        .stat-card   { background:var(--surface-1); border:1px solid var(--border); border-radius:8px; padding:16px }
        .stat-card.accent { border-left:3px solid var(--accent) }
        .stat-label  { font-size:10px; font-weight:700; letter-spacing:.06em; color:var(--text-secondary); text-transform:uppercase; margin-bottom:8px }
        .stat-value  { font-size:22px; font-weight:500; color:var(--text-primary); margin-bottom:3px }
        .stat-note   { font-size:12px; color:var(--text-secondary) }
        .chart-grid  { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:22px }
        .chart-card  { background:var(--surface-2); border:1px solid var(--border); border-radius:8px; padding:18px; min-width:0 }
        .chart-card h3 { margin:0 0 8px; font-size:1rem }
        @media print { .no-print { display:none !important } }
    </style>
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>

<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <a class="back-link no-print" href="<?= h($backHref) ?>">&larr; Back to overview</a>
        <div class="page-date"><?= strtoupper(date('l, j F')) ?></div>
        <h1 class="page-title">Platform growth</h1>
        <p class="page-sub">Bookings, money in and new sign-ups over time.</p>

        <section class="section-card" style="margin-bottom:20px" aria-label="Summary for a period">
            <h2 style="margin:0 0 6px">Summary for a period</h2>
            <p style="margin:0 0 12px;opacity:.8"><?= h($per['label']) ?>: <?= h(date('j M Y', strtotime($per['from']))) ?> to <?= h(date('j M Y', strtotime($per['to']))) ?>. Bookings are counted by the day they were made; payments by the day they were confirmed.</p>
            <?= reportFilterHtml($per) ?>
            <div class="stats-grid" style="margin-bottom:12px">
                <div class="stat-card accent"><div class="stat-label">Bookings made</div><div class="stat-value"><?= (int)$pBook['all'] ?></div><div class="stat-note">KES <?= number_format($pBook['value'], 2) ?> booked</div></div>
                <div class="stat-card"><div class="stat-label">Confirmed or completed</div><div class="stat-value"><?= (int)$pBook['won'] ?></div><div class="stat-note"><?= (int)$pBook['lost'] ?> cancelled, declined or expired</div></div>
                <div class="stat-card"><div class="stat-label">Guest payments confirmed</div><div class="stat-value">KES <?= number_format($pGuestPaid, 2) ?></div><div class="stat-note">paid to hosts</div></div>
                <div class="stat-card"><div class="stat-label">Refunds to guests recorded</div><div class="stat-value">KES <?= number_format($pGuestRefund, 2) ?></div><div class="stat-note"><?= (int)$pGuestRefundN ?> refund(s) with evidence; not taken off the guest payments</div></div>
                <div class="stat-card"><div class="stat-label">Guest service fees received</div><div class="stat-value">KES <?= number_format($pPlatform - $pRefunded, 2) ?></div><div class="stat-note"><?= serviceFeePercent() > 0 ? 'after KES ' . number_format($pRefunded, 2) . ' refunded' : 'service fee is switched off, so guests pay none' ?></div></div>
                <div class="stat-card"><div class="stat-label">Commission paid by hosts</div><div class="stat-value">KES <?= number_format($pCommission, 2) ?></div><div class="stat-note">confirmed in this period</div></div>
            </div>
            <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap">
                <?php $ex = http_build_query(['from' => $per['from'], 'to' => $per['to']]); ?>
                <strong style="align-self:center">Download CSV:</strong>
                <a href="export.php?type=bookings&amp;<?= h($ex) ?>">Bookings</a> &middot;
                <a href="export.php?type=payments&amp;<?= h($ex) ?>">Guest payments</a> &middot;
                <a href="export.php?type=platform_payments&amp;<?= h($ex) ?>">Platform payments</a> &middot;
                <a href="export.php?type=commission&amp;<?= h($ex) ?>">Commission payments</a> &middot;
                <a href="revenue_report.php?<?= h($ex) ?>">Printable revenue report</a>
            </div>
        </section>

        <h2 style="margin:0 0 4px">Charts</h2>
        <p style="margin:0 0 14px;opacity:.8"><?= h($per['label']) ?>, grouped by <?= h($cUnit) ?>.</p>
        <div class="chart-grid">
            <div class="chart-card"><h3>Bookings made</h3><?= svgBarChart($cLabels, [['name' => 'Bookings made', 'values' => $cBook['made']], ['name' => 'Confirmed or completed', 'values' => $cBook['won']]], ['title' => 'Bookings made and confirmed or completed over time']) ?></div>
            <div class="chart-card"><h3>Money confirmed (KES)</h3><?= svgBarChart($cLabels, [['name' => 'Guest payments to hosts', 'values' => $cGuestPaid['v']], ['name' => 'Commission paid by hosts', 'values' => $cCommission['v']]], ['title' => 'Guest payments and host commission payments over time', 'money' => true]) ?></div>
            <div class="chart-card"><h3>New accounts</h3><?= svgBarChart($cLabels, [['name' => 'New guests', 'values' => $cUsers['guests']], ['name' => 'New hosts', 'values' => $cUsers['hosts']]], ['title' => 'New guest and host accounts over time']) ?></div>
            <div class="chart-card"><h3>Bookings by status</h3><?= htmlBarList($cStatus, ['title' => 'Bookings by status']) ?></div>
            <div class="chart-card"><h3>Most booked stays</h3><?= htmlBarList($cTop, ['title' => 'Most booked stays', 'empty' => 'No confirmed or completed bookings in this period.']) ?></div>
        </div>
    <?php appShellEnd(); ?>

</body>
</html>
