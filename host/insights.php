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
requireRole('host');
$host_id = $_SESSION['user_id'];

// Same period filter as the overview (default: last 30 days).
$per = reportPeriod('last_30'); $prHost = [$host_id, $per['from'], $per['to']];
$pbq = $pdo->prepare("SELECT b.status, COUNT(*) AS n, COALESCE(SUM(b.total_amount),0) AS v FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    WHERE a.host_id = ? AND DATE(b.created_at) BETWEEN ? AND ? GROUP BY b.status");
$pbq->execute($prHost);
$pBook = ['all' => 0, 'value' => 0.0, 'won' => 0, 'lost' => 0];
foreach ($pbq->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $pBook['all'] += (int)$r['n']; $pBook['value'] += (float)$r['v'];
    if (in_array($r['status'], ['confirmed', 'completed'], true)) $pBook['won'] += (int)$r['n'];
    elseif (in_array($r['status'], ['cancelled', 'rejected', 'declined', 'expired', 'no_show'], true)) $pBook['lost'] += (int)$r['n'];
}
$pe = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN payment_status='confirmed' THEN amount END),0) AS got, COALESCE(SUM(CASE WHEN payment_status='pending' THEN amount END),0) AS waiting
    FROM payments WHERE host_id = ? AND DATE(payment_date) BETWEEN ? AND ?");
$pe->execute($prHost); $pEarn = $pe->fetch(PDO::FETCH_ASSOC);
// Refunds this host recorded to guests in the period (from the refund cases)
$pRefund = 0.0; $pRefundN = 0;
try {
    ensureRefundRequestTables($pdo);
    $rq = $pdo->prepare("SELECT COALESCE(SUM(refund_amount),0), COUNT(*) FROM refund_requests WHERE host_id = ? AND status = 'refunded' AND DATE(refunded_at) BETWEEN ? AND ?");
    $rq->execute($prHost); [$pRefund, $pRefundN] = array_map('floatval', $rq->fetch(PDO::FETCH_NUM));
} catch (Throwable $e) { error_log('host insights refunds: ' . $e->getMessage()); }
// v9.38.1: chart data for the same period (this host only)
$cb = chartBuckets($per['from'], $per['to']); $cKeys = $cb['keys']; $cUnit = $cb['unit'];
$cLabels = array_map(fn($k) => chartLabel($k, $cUnit), $cKeys);
$bx = $cb['expr']('b.created_at'); $px = $cb['expr']('p.payment_date');
$cBook = chartSeries($pdo, "SELECT $bx AS k, COUNT(*) AS made, SUM(b.status IN ('confirmed','completed')) AS won FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE a.host_id = ? AND DATE(b.created_at) BETWEEN ? AND ? GROUP BY k", $prHost, $cKeys, ['made', 'won']);
$cEarn = chartSeries($pdo, "SELECT $px AS k, SUM(p.amount) AS v FROM payments p WHERE p.host_id = ? AND p.payment_status = 'confirmed' AND DATE(p.payment_date) BETWEEN ? AND ? GROUP BY k", $prHost, $cKeys, ['v']);
$cStatus = []; $cTop = [];
try {
    $q = $pdo->prepare("SELECT b.status, COUNT(*) AS n FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE a.host_id = ? AND DATE(b.created_at) BETWEEN ? AND ? GROUP BY b.status ORDER BY n DESC"); $q->execute($prHost);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $cStatus[] = ['label' => ucfirst(str_replace('_', ' ', $r['status'])), 'value' => (int)$r['n']];
    $q = $pdo->prepare("SELECT a.title, SUM(p.amount) AS v FROM payments p JOIN bookings b ON b.booking_id = p.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE a.host_id = ? AND p.payment_status = 'confirmed' AND DATE(p.payment_date) BETWEEN ? AND ? GROUP BY a.accommodation_id, a.title ORDER BY v DESC LIMIT 5"); $q->execute($prHost);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $cTop[] = ['label' => $r['title'], 'value' => round((float)$r['v'])];
} catch (Throwable $e) { error_log('host dashboard chart lists: ' . $e->getMessage()); }

$backHref = 'dashboard.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your progress — StayExplore Kenya</title>
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
        <h1 class="page-title">Your progress</h1>
        <p class="page-sub">See how your earnings and bookings are growing.</p>

        <section class="section-card" style="margin-bottom:20px" aria-label="Summary for a period">
            <h2 style="margin:0 0 6px">Summary for a period</h2>
            <p style="margin:0 0 12px;opacity:.8"><?= h($per['label']) ?>: <?= h(date('j M Y', strtotime($per['from']))) ?> to <?= h(date('j M Y', strtotime($per['to']))) ?>. Bookings are counted by the day they were made; payments by the day they were reported.</p>
            <?= reportFilterHtml($per) ?>
            <div class="stats-grid" style="margin-bottom:12px">
                <div class="stat-card accent"><div class="stat-label">Bookings made</div><div class="stat-value"><?= (int)$pBook['all'] ?></div><div class="stat-note">KES <?= number_format($pBook['value'], 2) ?> booked</div></div>
                <div class="stat-card"><div class="stat-label">Confirmed or completed</div><div class="stat-value"><?= (int)$pBook['won'] ?></div><div class="stat-note"><?= (int)$pBook['lost'] ?> cancelled, declined or expired</div></div>
                <div class="stat-card"><div class="stat-label">Earnings confirmed</div><div class="stat-value">KES <?= number_format((float)$pEarn['got'], 2) ?></div><div class="stat-note">KES <?= number_format((float)$pEarn['waiting'], 2) ?> still to confirm</div></div>
                <div class="stat-card"><div class="stat-label">Refunded to guests</div><div class="stat-value">KES <?= number_format($pRefund, 2) ?></div><div class="stat-note"><?= (int)$pRefundN ?> refund(s) on your bookings; not taken off the earnings above</div></div>
            </div>
            <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap">
                <?php $ex = http_build_query(['from' => $per['from'], 'to' => $per['to']]); ?>
                <strong style="align-self:center">Download CSV:</strong>
                <a href="export.php?type=bookings&amp;<?= h($ex) ?>">My bookings</a> &middot;
                <a href="export.php?type=payments&amp;<?= h($ex) ?>">My payments</a>
            </div>
        </section>

        <h2 style="margin:0 0 4px">Charts</h2>
        <p style="margin:0 0 14px;opacity:.8"><?= h($per['label']) ?>, grouped by <?= h($cUnit) ?>.</p>
        <div class="chart-grid">
            <div class="chart-card"><h3>Earnings confirmed (KES)</h3><?= svgBarChart($cLabels, [['name' => 'Earnings confirmed', 'values' => $cEarn['v']]], ['title' => 'Confirmed earnings over time', 'money' => true]) ?></div>
            <div class="chart-card"><h3>Bookings made</h3><?= svgBarChart($cLabels, [['name' => 'Bookings made', 'values' => $cBook['made']], ['name' => 'Confirmed or completed', 'values' => $cBook['won']]], ['title' => 'Bookings made and confirmed or completed over time']) ?></div>
            <div class="chart-card"><h3>Bookings by status</h3><?= htmlBarList($cStatus, ['title' => 'Bookings by status']) ?></div>
            <div class="chart-card"><h3>Earnings by listing (KES)</h3><?= htmlBarList($cTop, ['title' => 'Earnings by listing', 'money' => true, 'empty' => 'No confirmed payments in this period.']) ?></div>
        </div>
    <?php appShellEnd(); ?>

</body>
</html>
