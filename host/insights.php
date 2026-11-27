<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';
require_once '../includes/report_helpers.php';
require_once '../includes/chart_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');
$host_id = $_SESSION['user_id'];

// Same period filter as the overview (default: last 30 days). The filter and the chart data are unchanged from the overview.
$per = reportPeriod('last_30'); $prHost = [$host_id, $per['from'], $per['to']];
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

        <section class="section-card no-print" style="margin-bottom:20px" aria-label="Choose a period">
            <p style="margin:0 0 10px;opacity:.8"><?= h($per['label']) ?>: <?= h(date('j M Y', strtotime($per['from']))) ?> to <?= h(date('j M Y', strtotime($per['to']))) ?>, grouped by <?= h($cUnit) ?>.</p>
            <?= reportFilterHtml($per) ?>
        </section>

        <div class="chart-grid">
            <div class="chart-card"><h3>Earnings confirmed (KES)</h3><?= svgBarChart($cLabels, [['name' => 'Earnings confirmed', 'values' => $cEarn['v']]], ['title' => 'Confirmed earnings over time', 'money' => true]) ?></div>
            <div class="chart-card"><h3>Bookings made</h3><?= svgBarChart($cLabels, [['name' => 'Bookings made', 'values' => $cBook['made']], ['name' => 'Confirmed or completed', 'values' => $cBook['won']]], ['title' => 'Bookings made and confirmed or completed over time']) ?></div>
            <div class="chart-card"><h3>Bookings by status</h3><?= htmlBarList($cStatus, ['title' => 'Bookings by status']) ?></div>
            <div class="chart-card"><h3>Earnings by listing (KES)</h3><?= htmlBarList($cTop, ['title' => 'Earnings by listing', 'money' => true, 'empty' => 'No confirmed payments in this period.']) ?></div>
        </div>
    <?php appShellEnd(); ?>

</body>
</html>
