<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';
require_once '../includes/booking_workflow.php';
require_once '../includes/report_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('admin');

// Platform stats
$stats = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM users
         WHERE role = 'tourist') AS total_tourists,
        (SELECT COUNT(*) FROM users
         WHERE role = 'host') AS total_hosts,
        (SELECT COUNT(*) FROM accommodations
         WHERE status = 'approved') AS active_listings,
        (SELECT COUNT(*) FROM accommodations
         WHERE status = 'pending') AS pending_listings,
        (SELECT COUNT(*) FROM bookings) AS total_bookings,
        (SELECT COUNT(*) FROM bookings
         WHERE status = 'confirmed') AS confirmed_bookings,
        (SELECT COALESCE(SUM(amount),0) FROM payments
         WHERE payment_status = 'confirmed') AS total_payments,
        (SELECT COUNT(*) FROM reports
         WHERE status = 'pending') AS open_reports,
        (SELECT COUNT(*) FROM reviews
         WHERE is_flagged = 1
         AND is_published = 0) AS flagged_reviews,
        (SELECT COUNT(*) FROM attractions) AS total_attractions
")->fetch();

// v9.38.1: summary for a chosen period (default last 30 days). The headline cards above stay all-time.
ensureCommissionTables($pdo);
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

// Recent activity — latest bookings
$recent = $pdo->query("
    SELECT b.booking_id, b.status, b.total_amount,
           b.check_in_date, b.check_out_date,
           a.title AS property_title,
           u.full_name AS tourist_name,
           h.full_name AS host_name
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users u ON u.user_id = b.tourist_id
    JOIN users h ON h.user_id = a.host_id
    ORDER BY b.created_at DESC
    LIMIT 8
")->fetchAll();

// Pending listing approvals
$pending = $pdo->query("
    SELECT a.accommodation_id, a.title,
           a.location_name, a.property_type,
           a.price_per_night, a.created_at,
           u.full_name AS host_name
    FROM accommodations a
    JOIN users u ON u.user_id = a.host_id
    WHERE a.status = 'pending'
    ORDER BY a.created_at ASC
    LIMIT 5
")->fetchAll();

// Monthly revenue trend
$monthly = $pdo->query("
    SELECT
        DATE_FORMAT(payment_date, '%b') AS month,
        DATE_FORMAT(payment_date, '%Y-%m') AS month_key,
        COALESCE(SUM(amount), 0) AS total
    FROM payments
    WHERE payment_status = 'confirmed'
    AND payment_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_key, month
    ORDER BY month_key ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <style>
        .sidebar     { background:var(--surface-2);
                       border-right:1px solid var(--border);
                       padding:24px 0; position:sticky;
                       top:52px; height:calc(100vh - 52px);
                       overflow-y:auto }
        .badge       { font-size:10px; background:#DC2626;
                       color:#fff; border-radius:10px;
                       padding:1px 6px; margin-left:auto }
        .main        { padding:28px; overflow-y:auto }
        .page-date   { font-size:11px; font-weight:600;
                       letter-spacing:.06em; color:var(--accent);
                       text-transform:uppercase; margin-bottom:6px }
        .strip-title { font-size:15px; font-weight:600; margin:8px 0 10px; display:flex; justify-content:space-between; align-items:baseline }
        .strip-title a { font-size:13px; font-weight:400 }
        .stats-grid  { display:grid;
                       grid-template-columns:repeat(4,1fr);
                       gap:12px; margin-bottom:24px }
        .stat-card   { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:16px }
        .stat-card.accent { border-left:3px solid var(--accent) }
        .stat-card.warn { border-left:3px solid #8f4b00 }
        .stat-card.danger { border-left:3px solid #DC2626 }
        .stat-label  { font-size:10px; font-weight:700;
                       letter-spacing:.06em;
                       color:var(--text-secondary);
                       text-transform:uppercase; margin-bottom:8px }
        .stat-value  { font-size:24px; font-weight:500;
                       color:var(--text-primary); margin-bottom:3px }
        .stat-note   { font-size:12px; color:var(--text-secondary) }
        .stat-note.green { color:var(--accent) }
        .stat-note.warn  { color:var(--warn-fg) }
        .stat-note.red   { color:var(--bad-fg) }
        .content-grid { display:grid;
                        grid-template-columns:1fr 300px;
                        gap:20px; margin-bottom:20px }
        .section-card { background:var(--surface-2);
                        border:1px solid var(--border);
                        border-radius:8px; overflow:hidden }
        .card-head   { padding:14px 18px;
                       border-bottom:1px solid var(--border);
                       display:flex; justify-content:space-between;
                       align-items:center }
        .card-head h3 { font-size:13px; font-weight:500 }
        .card-head a  { font-size:12px; color:var(--accent);
                        text-decoration:none }
        table        { width:100%; border-collapse:collapse;
                       font-size:13px }
        th           { font-size:10px; font-weight:700;
                       letter-spacing:.06em; text-transform:uppercase;
                       color:var(--text-secondary); padding:10px 18px;
                       text-align:left; background:var(--surface-1);
                       border-bottom:1px solid var(--border) }
        td           { padding:11px 18px;
                       border-bottom:1px solid var(--border) }
        tr:last-child td { border-bottom:none }
        tr:hover td  { background:var(--surface-1) }
        .s-confirmed { color:var(--accent-strong); font-weight:500 }
        .s-pending   { color:var(--warn-fg); font-weight:500 }
        .s-completed { color:#1A4DB3; font-weight:500 }
        .s-cancelled { color:var(--bad-fg) }
        .pending-row { padding:14px 18px;
                       border-bottom:1px solid var(--border);
                       display:flex; justify-content:space-between;
                       align-items:center }
        .pending-row:last-child { border-bottom:none }
        .pending-info h4 { font-size:13px; font-weight:500;
                           margin-bottom:2px }
        .pending-info p  { font-size:12px;
                           color:var(--text-secondary) }
        .review-btn  { padding:5px 12px; font-size:12px;
                       background:var(--btn-hover); color:#fff;
                       border:none; border-radius:4px;
                       text-decoration:none; white-space:nowrap }
        .chart-wrap  { padding:18px }
        .chart-bars  { display:flex; align-items:flex-end;
                       gap:8px; height:100px;
                       border-bottom:1px solid var(--border);
                       padding-bottom:8px; margin-bottom:8px }
        .bar-col     { flex:1; display:flex; flex-direction:column;
                       align-items:center; gap:4px; height:100% }
        .bar         { width:100%; background:var(--btn);
                       border-radius:3px 3px 0 0; min-height:2px }
        .bar-label   { font-size:10px; color:var(--text-secondary) }
        .needs-attention { background:var(--surface-2);
                           border:1px solid var(--border);
                           border-radius:8px; overflow:hidden;
                           margin-bottom:20px }
        .attention-row { display:flex; justify-content:space-between;
                         align-items:center; padding:12px 18px;
                         border-bottom:1px solid var(--border) }
        .attention-row:last-child { border-bottom:none }
        .attention-label { font-size:13px; font-weight:500 }
        .attention-meta  { font-size:12px;
                           color:var(--text-secondary) }
        .attention-right { display:flex; align-items:center;
                           gap:10px }
        .count-badge { font-size:12px; font-weight:600;
                       padding:3px 10px; border-radius:3px }
        .count-red   { background:var(--bad-bg); color:var(--bad-fg);
                       border:1px solid var(--bad-line) }
        .count-amber { background:var(--warn-bg); color:var(--warn-fg);
                       border:1px solid var(--warn-line) }
        .action-link { font-size:12px; color:var(--accent);
                       text-decoration:none }
        @media(max-width:1024px){
            .sidebar   { display:none }
            .stats-grid { grid-template-columns:repeat(2,1fr) }
            .content-grid { grid-template-columns:1fr }
        }
    </style>
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <div class="page-date">
            <?= strtoupper(date('l, j F')) ?>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
            <h1 class="page-title" style="margin-bottom:0">Admin overview</h1>
        </div>
        <p class="page-sub">
            A clear view of what needs attention today.
        </p>

        <?php
        $pendingIds = 0;
        try { $pendingIds = (int)$pdo->query("SELECT COUNT(*) FROM host_verifications WHERE status = 'pending'")->fetchColumn(); } catch (Throwable $e) {}
        $attention = [
            ['Identity checks', $pendingIds, 'users.php#verification', 'IDs waiting for your decision'],
            ['Listing approvals', (int)$stats['pending_listings'], 'listings.php', 'New listings waiting to go live'],
            ['Incident reports', (int)$stats['open_reports'], 'reports.php', 'Open reports from guests and hosts'],
            ['Flagged reviews', (int)$stats['flagged_reviews'], 'reviews.php', 'Reviews held back to be checked'],
        ];
        ?>
        <section class="attention-grid" aria-label="Needs your attention">
            <?php foreach ($attention as [$label, $n, $href, $note]): ?>
            <a class="attention-tile <?= $n > 0 ? 'urgent' : 'clear' ?>" href="<?= h($href) ?>">
                <span class="attention-label"><?= h($label) ?></span>
                <span class="attention-count"><?= (int)$n ?></span>
                <span class="attention-note"><?= $n > 0 ? h($note) : 'All clear' ?></span>
                <span class="attention-go"><?= $n > 0 ? 'Review now &rarr;' : 'Open &rarr;' ?></span>
            </a>
            <?php endforeach; ?>
        </section>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card accent">
                <div class="stat-label">Total bookings</div>
                <div class="stat-value">
                    <?= number_format($stats['total_bookings']) ?>
                </div>
                <div class="stat-note green">
                    <?= $stats['confirmed_bookings'] ?> confirmed
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active listings</div>
                <div class="stat-value">
                    <?= $stats['active_listings'] ?>
                </div>
                <div class="stat-note">Across Kenya</div>
            </div>
            <div class="stat-card <?= $stats['pending_listings'] > 0
                ? 'warn' : '' ?>">
                <div class="stat-label">Pending approvals</div>
                <div class="stat-value">
                    <?= $stats['pending_listings'] ?>
                </div>
                <div class="stat-note <?= $stats['pending_listings'] > 0
                    ? 'warn' : '' ?>">
                    <?= $stats['pending_listings'] > 0
                        ? 'Needs review' : 'All clear' ?>
                </div>
            </div>
            <div class="stat-card <?= $stats['open_reports'] > 0
                ? 'danger' : '' ?>">
                <div class="stat-label">Open reports</div>
                <div class="stat-value">
                    <?= $stats['open_reports'] ?>
                </div>
                <div class="stat-note <?= $stats['open_reports'] > 0
                    ? 'red' : '' ?>">
                    <?= $stats['open_reports'] > 0
                        ? 'Action required' : 'All clear' ?>
                </div>
            </div>
        </div>

        <!-- v9.38.1: period summary + exports -->
        <section class="section-card" style="margin-bottom:24px" aria-label="Summary for a period">
            <h2 style="margin:0 0 6px">Summary for a period</h2>
            <p style="margin:0 0 12px;opacity:.8"><?= h($per['label']) ?>: <?= h(date('j M Y', strtotime($per['from']))) ?> to <?= h(date('j M Y', strtotime($per['to']))) ?>. Bookings are counted by the day they were made; payments by the day they were confirmed.</p>
            <?= reportFilterHtml($per) ?>
            <div class="stats-grid" style="margin-bottom:12px">
                <div class="stat-card accent"><div class="stat-label">Bookings made</div><div class="stat-value"><?= (int)$pBook['all'] ?></div><div class="stat-note">KES <?= number_format($pBook['value'], 2) ?> booked</div></div>
                <div class="stat-card"><div class="stat-label">Confirmed or completed</div><div class="stat-value"><?= (int)$pBook['won'] ?></div><div class="stat-note"><?= (int)$pBook['lost'] ?> cancelled, declined or expired</div></div>
                <div class="stat-card"><div class="stat-label">Guest payments confirmed</div><div class="stat-value">KES <?= number_format($pGuestPaid, 2) ?></div><div class="stat-note">paid to hosts</div></div>
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

        <!-- Second Stats Row -->
        <div class="stats-grid" style="margin-bottom:24px">
            <div class="stat-card">
                <div class="stat-label">Total tourists</div>
                <div class="stat-value">
                    <?= $stats['total_tourists'] ?>
                </div>
                <div class="stat-note">Registered accounts</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total hosts</div>
                <div class="stat-value">
                    <?= $stats['total_hosts'] ?>
                </div>
                <div class="stat-note">Registered accounts</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Platform revenue</div>
                <div class="stat-value">
                    KES <?= number_format(
                        $stats['total_payments'] / 1000, 0) ?>k
                </div>
                <div class="stat-note">Confirmed payments</div>
            </div>
            <div class="stat-card <?= $stats['flagged_reviews'] > 0
                ? 'warn' : '' ?>">
                <div class="stat-label">Flagged reviews</div>
                <div class="stat-value">
                    <?= $stats['flagged_reviews'] ?>
                </div>
                <div class="stat-note <?= $stats['flagged_reviews'] > 0
                    ? 'warn' : '' ?>">
                    <?= $stats['flagged_reviews'] > 0
                        ? 'Awaiting review' : 'All clear' ?>
                </div>
            </div>
        </div>

        <!-- Needs Attention -->
        <?php if ($stats['pending_listings'] > 0
            || $stats['open_reports'] > 0
            || $stats['flagged_reviews'] > 0): ?>
        <div class="needs-attention">
            <div class="card-head">
                <h3>Needs attention</h3>
            </div>
            <?php if ($stats['pending_listings'] > 0): ?>
            <div class="attention-row">
                <div>
                    <div class="attention-label">
                        Listing approvals
                    </div>
                    <div class="attention-meta">
                        Properties waiting to go live
                    </div>
                </div>
                <div class="attention-right">
                    <span class="count-badge count-amber">
                        <?= $stats['pending_listings'] ?> pending
                    </span>
                    <a href="listings.php" class="action-link">
                        Review &rarr;
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($stats['open_reports'] > 0): ?>
            <div class="attention-row">
                <div>
                    <div class="attention-label">
                        Open incident reports
                    </div>
                    <div class="attention-meta">
                        Reports requiring action
                    </div>
                </div>
                <div class="attention-right">
                    <span class="count-badge count-red">
                        <?= $stats['open_reports'] ?> open
                    </span>
                    <a href="reports.php" class="action-link">
                        Review &rarr;
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($stats['flagged_reviews'] > 0): ?>
            <div class="attention-row">
                <div>
                    <div class="attention-label">
                        Flagged reviews
                    </div>
                    <div class="attention-meta">
                        Reviews held for moderation
                    </div>
                </div>
                <div class="attention-right">
                    <span class="count-badge count-amber">
                        <?= $stats['flagged_reviews'] ?> flagged
                    </span>
                    <a href="reviews.php" class="action-link">
                        Moderate &rarr;
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php $cs = commissionPlatformSummary($pdo); ?>
        <h2 class="strip-title">Host commission <a href="revenue.php">Details</a></h2>
        <div class="stats-grid" style="margin-bottom:24px">
            <div class="stat-card accent"><div class="stat-label">Total generated</div><div class="stat-value">KES <?= number_format($cs['generated']) ?></div><div class="stat-note"><?= (int)$cs['invoices'] ?> invoice(s)</div></div>
            <div class="stat-card"><div class="stat-label">Outstanding</div><div class="stat-value">KES <?= number_format($cs['outstanding']) ?></div><div class="stat-note">owed by hosts</div></div>
            <div class="stat-card"><div class="stat-label">Awaiting verification</div><div class="stat-value">KES <?= number_format($cs['awaiting']) ?></div><div class="stat-note"><?= (int)$cs['awaiting_n'] ?> to check</div></div>
            <div class="stat-card"><div class="stat-label">Overdue invoices</div><div class="stat-value"><?= (int)$cs['overdue_n'] ?></div><div class="stat-note">KES <?= number_format($cs['overdue_amount']) ?> past due</div></div>
        </div>

        <div class="content-grid">

            <!-- Recent Bookings Table -->
            <div class="section-card">
                <div class="card-head">
                    <h3>Recent activity</h3>
                </div>
                <?php if (empty($recent)): ?>
                    <div style="padding:32px;text-align:center;
                                font-size:13px;
                                color:var(--text-secondary)">
                        No bookings yet
                    </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Property</th>
                            <th>Guest</th>
                            <th>Status</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $b): ?>
                        <tr>
                            <td>
                                <div style="font-weight:500">
                                    <?= h(
                                        $b['property_title']) ?>
                                </div>
                                <div style="font-size:11px;
                                            color:var(--text-secondary)">
                                    <?= date('d M',
                                        strtotime($b['check_in_date'])) ?>
                                    &ndash;
                                    <?= date('d M',
                                        strtotime($b['check_out_date'])) ?>
                                </div>
                            </td>
                            <td>
                                <?= h(
                                    explode(' ',
                                    $b['tourist_name'])[0]) ?>
                                <?= h(
                                    explode(' ',
                                    $b['tourist_name'])[1] ?? '') ?>
                            </td>
                            <td>
                                <?php
                                $cls = [
                                    'confirmed' => 's-confirmed',
                                    'pending'   => 's-pending',
                                    'completed' => 's-completed',
                                    'cancelled' => 's-cancelled'
                                ][$b['status']] ?? ($b['status'] === 'accepted' ? 's-confirmed' : 's-pending');
                                ?>
                                <span class="<?= $cls ?>">
                                    <?= h(bookingStatusLabel($b['status'], 'tourist')) ?>
                                </span>
                            </td>
                            <td style="font-weight:500">
                                KES <?= number_format(
                                    $b['total_amount']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Right Column -->
            <div>

                <!-- Pending Approvals Quick View -->
                <?php if (!empty($pending)): ?>
                <div class="section-card"
                     style="margin-bottom:16px">
                    <div class="card-head">
                        <h3>Pending listings</h3>
                        <a href="listings.php">View all</a>
                    </div>
                    <?php foreach ($pending as $p): ?>
                    <div class="pending-row">
                        <div class="pending-info">
                            <h4>
                                <?= h($p['title']) ?>
                            </h4>
                            <p>
                                <?= h(
                                    $p['location_name']) ?>
                                &middot;
                                <?= h(
                                    $p['property_type']) ?>
                                &middot;
                                KES <?= number_format(
                                    $p['price_per_night']) ?>
                            </p>
                        </div>
                        <a href="listings.php?review=<?= $p['accommodation_id'] ?>"
                           class="review-btn">
                            Review
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Monthly Trend -->
                <div class="section-card">
                    <div class="card-head">
                        <h3>Monthly trend</h3>
                    </div>
                    <div class="chart-wrap">
                        <?php if (!empty($monthly)): ?>
                        <?php
                        $max = max(array_column($monthly,'total'));
                        $max = $max > 0 ? $max : 1;
                        ?>
                        <div class="chart-bars">
                            <?php foreach ($monthly as $m): ?>
                            <div class="bar-col">
                                <div class="bar"
                                     style="height:<?= round(
                                         ($m['total']/$max)*100)?>%"
                                     title="KES <?= number_format(
                                         $m['total'])?>">
                                </div>
                                <div class="bar-label">
                                    <?= $m['month'] ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="font-size:12px;
                                    color:var(--text-secondary)">
                            Platform revenue — last 6 months
                        </div>
                        <?php else: ?>
                        <div style="text-align:center;
                                    padding:32px;font-size:13px;
                                    color:var(--text-secondary)">
                            No revenue data yet
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

    <?php appShellEnd(); ?>

</body>
</html>