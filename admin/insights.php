<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';
require_once '../includes/report_helpers.php';
require_once '../includes/chart_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('admin');
ensureCommissionTables($pdo);

// Same period filter as the overview (default: last 30 days). The filter and the chart data are unchanged from the overview.
$per = reportPeriod('last_30'); $pr = [$per['from'], $per['to']];
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

        <section class="section-card no-print" style="margin-bottom:20px" aria-label="Choose a period">
            <p style="margin:0 0 10px;opacity:.8"><?= h($per['label']) ?>: <?= h(date('j M Y', strtotime($per['from']))) ?> to <?= h(date('j M Y', strtotime($per['to']))) ?>, grouped by <?= h($cUnit) ?>.</p>
            <?= reportFilterHtml($per) ?>
        </section>

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
