<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$host_id = $_SESSION['user_id'];

// Summary stats
$summary = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN p.payment_status = 'confirmed'
            THEN p.amount END), 0) AS total_confirmed,
        COALESCE(SUM(CASE WHEN p.payment_status = 'pending'
            THEN p.amount END), 0) AS total_pending,
        COUNT(CASE WHEN p.payment_status = 'confirmed'
            THEN 1 END) AS confirmed_count,
        COUNT(CASE WHEN p.payment_status = 'pending'
            THEN 1 END) AS pending_count,
        COALESCE(SUM(CASE WHEN p.payment_status = 'confirmed'
            AND MONTH(p.payment_date) = MONTH(NOW())
            AND YEAR(p.payment_date) = YEAR(NOW())
            THEN p.amount END), 0) AS this_month
    FROM payments p
    JOIN bookings b ON b.booking_id = p.booking_id
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    WHERE a.host_id = ?
");
$summary->execute([$host_id]);
$summary = $summary->fetch();

// Filter
$filter = sanitize($_GET['filter'] ?? 'all');
$where  = "a.host_id = ?";
$params = [$host_id];

if ($filter === 'confirmed') {
    $where  .= " AND p.payment_status = 'confirmed'";
} elseif ($filter === 'pending') {
    $where  .= " AND p.payment_status = 'pending'";
}

// Get payments
$payments = $pdo->prepare("
    SELECT p.*,
           b.check_in_date, b.check_out_date,
           b.num_guests, b.status AS booking_status,
           a.title AS property_title,
           u.full_name AS tourist_name
    FROM payments p
    JOIN bookings b ON b.booking_id = p.booking_id
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users u ON u.user_id = b.tourist_id
    WHERE $where
    ORDER BY p.payment_date DESC
");
$payments->execute($params);
$payments = $payments->fetchAll();

// Monthly breakdown for last 6 months
$monthly = $pdo->prepare("
    SELECT
        DATE_FORMAT(p.payment_date, '%b %Y') AS month_label,
        DATE_FORMAT(p.payment_date, '%Y-%m') AS month_key,
        COALESCE(SUM(p.amount), 0) AS total,
        COUNT(*) AS count
    FROM payments p
    JOIN bookings b ON b.booking_id = p.booking_id
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    WHERE a.host_id = ?
    AND p.payment_status = 'confirmed'
    AND p.payment_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
");
$monthly->execute([$host_id]);
$monthly = $monthly->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body         { display:flex; flex-direction:column;
                       min-height:100vh }
        .dash-wrap   { display:grid;
                       grid-template-columns:200px 1fr; flex:1 }
        .sidebar     { background:var(--surface-2);
                       border-right:0.5px solid var(--border);
                       padding:24px 0; position:sticky;
                       top:52px; height:calc(100vh - 52px);
                       overflow-y:auto }
        .sidebar-role{ font-size:10px; font-weight:700;
                       letter-spacing:.08em; color:#1D9E75;
                       text-transform:uppercase;
                       padding:0 20px; margin-bottom:12px }
        .sidebar-link{ display:flex; align-items:center; gap:10px;
                       padding:9px 20px; font-size:13px;
                       color:var(--text-secondary);
                       text-decoration:none; width:100% }
        .sidebar-link:hover { color:var(--text-primary);
                              background:var(--surface-1) }
        .sidebar-link.active { color:#085041; font-weight:500;
                               background:#E1F5EE;
                               border-left:3px solid #1D9E75 }
        .main        { padding:28px }
        .page-tag    { font-size:11px; font-weight:600;
                       letter-spacing:.06em; color:#1D9E75;
                       text-transform:uppercase; margin-bottom:8px }
        .page-title  { font-size:26px; font-weight:400;
                       margin-bottom:24px }
        .stats-row   { display:grid;
                       grid-template-columns:repeat(4,1fr);
                       gap:12px; margin-bottom:24px }
        .stat-card   { background:var(--surface-2);
                       border:0.5px solid var(--border);
                       border-radius:8px; padding:16px }
        .stat-card.accent { border-left:3px solid #1D9E75 }
        .stat-label  { font-size:10px; font-weight:700;
                       letter-spacing:.06em; color:var(--text-secondary);
                       text-transform:uppercase; margin-bottom:8px }
        .stat-value  { font-size:22px; font-weight:500;
                       color:var(--text-primary); margin-bottom:3px }
        .stat-note   { font-size:12px; color:var(--text-secondary) }
        .content-grid{ display:grid;
                       grid-template-columns:1fr 260px; gap:20px }
        .section-card{ background:var(--surface-2);
                       border:0.5px solid var(--border);
                       border-radius:8px; overflow:hidden }
        .card-head   { padding:14px 18px;
                       border-bottom:0.5px solid var(--border);
                       display:flex; justify-content:space-between;
                       align-items:center }
        .card-head h3{ font-size:13px; font-weight:500 }
        .filter-tabs { display:flex; gap:6px }
        .filter-tab  { padding:5px 12px; font-size:12px;
                       border-radius:4px; cursor:pointer;
                       text-decoration:none;
                       color:var(--text-secondary);
                       border:0.5px solid var(--border) }
        .filter-tab.active { background:#085041; color:#fff;
                             border-color:#085041 }
        table        { width:100%; border-collapse:collapse;
                       font-size:13px }
        th           { font-size:10px; font-weight:700;
                       letter-spacing:.06em; text-transform:uppercase;
                       color:var(--text-secondary); padding:10px 18px;
                       text-align:left; background:var(--surface-1);
                       border-bottom:0.5px solid var(--border) }
        td           { padding:12px 18px;
                       border-bottom:0.5px solid var(--border);
                       color:var(--text-primary) }
        tr:last-child td { border-bottom:none }
        tr:hover td  { background:var(--surface-1) }
        .pay-confirmed { color:#0F6E56; font-weight:600;
                         font-size:12px }
        .pay-pending   { color:#D97706; font-weight:600;
                         font-size:12px }
        .method-label  { font-size:11px; color:var(--text-secondary);
                         background:var(--surface-1);
                         border:0.5px solid var(--border);
                         border-radius:3px; padding:2px 7px }
        .monthly-table { padding:16px }
        .month-row   { display:flex; justify-content:space-between;
                       align-items:center; padding:10px 0;
                       border-bottom:0.5px solid var(--border);
                       font-size:13px }
        .month-row:last-child { border-bottom:none }
        .month-label { color:var(--text-secondary) }
        .month-amount{ font-weight:500 }
        .month-count { font-size:11px; color:var(--text-secondary) }
        .empty-row   { text-align:center; padding:32px;
                       color:var(--text-secondary); font-size:13px }
        @media(max-width:1000px){
            .dash-wrap { grid-template-columns:1fr }
            .sidebar   { display:none }
            .stats-row { grid-template-columns:repeat(2,1fr) }
            .content-grid { grid-template-columns:1fr }
        }
    </style>
</head>
<body>

<?php include '../includes/header.php'; ?>

<div class="dash-wrap">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-role">Host</div>
        <a href="dashboard.php" class="sidebar-link">Overview</a>
        <a href="listings.php" class="sidebar-link">Listings</a>
        <a href="availability.php" class="sidebar-link">Availability</a>
        <a href="bookings.php" class="sidebar-link">Bookings</a>
        <a href="payments.php"
           class="sidebar-link active">Payments</a>
        <a href="reviews.php" class="sidebar-link">Reviews</a>
        <a href="report.php" class="sidebar-link">Reports</a>
        <a href="profile.php" class="sidebar-link">Profile</a>
    </aside>

    <main class="main">

        <div class="page-tag">Host</div>
        <h1 class="page-title">Payments</h1>

        <!-- Summary Stats -->
        <div class="stats-row">
            <div class="stat-card accent">
                <div class="stat-label">Total earnings</div>
                <div class="stat-value">
                    KES <?= number_format($summary['total_confirmed']) ?>
                </div>
                <div class="stat-note">
                    <?= $summary['confirmed_count'] ?> confirmed
                    payment<?= $summary['confirmed_count'] != 1
                                ? 's' : '' ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">This month</div>
                <div class="stat-value">
                    KES <?= number_format($summary['this_month']) ?>
                </div>
                <div class="stat-note">
                    <?= date('F Y') ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Awaiting payment</div>
                <div class="stat-value">
                    KES <?= number_format($summary['total_pending']) ?>
                </div>
                <div class="stat-note">
                    <?= $summary['pending_count'] ?> booking
                    <?= $summary['pending_count'] != 1 ? 's' : '' ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Confirmed payments</div>
                <div class="stat-value">
                    <?= $summary['confirmed_count'] ?>
                </div>
                <div class="stat-note">All time</div>
            </div>
        </div>

        <div class="content-grid">

            <!-- Payments Table -->
            <div class="section-card">
                <div class="card-head">
                    <h3>Payment records</h3>
                    <div class="filter-tabs">
                        <a href="?filter=all"
                           class="filter-tab <?= $filter==='all'
                               ?'active':'' ?>">All</a>
                        <a href="?filter=confirmed"
                           class="filter-tab <?= $filter==='confirmed'
                               ?'active':'' ?>">Confirmed</a>
                        <a href="?filter=pending"
                           class="filter-tab <?= $filter==='pending'
                               ?'active':'' ?>">Pending</a>
                    </div>
                </div>

                <?php if (empty($payments)): ?>
                    <div class="empty-row">No payments found</div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Guest</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td>
                                <div style="font-weight:500">
                                    <?= htmlspecialchars(
                                        $p['property_title']) ?>
                                </div>
                                <div style="font-size:11px;
                                            color:var(--text-secondary)">
                                    #BK<?= str_pad($p['booking_id'],
                                        5, '0', STR_PAD_LEFT) ?>
                                    &middot;
                                    <?= date('d M',
                                        strtotime($p['check_in_date'])) ?>
                                    &ndash;
                                    <?= date('d M',
                                        strtotime($p['check_out_date'])) ?>
                                </div>
                            </td>
                            <td>
                                <?= htmlspecialchars(
                                    $p['tourist_name']) ?>
                            </td>
                            <td>
                                <span class="method-label">
                                    <?php
                                    $methods = [
                                        'cash'          => 'Cash',
                                        'bank_transfer' => 'Bank transfer',
                                        'mpesa'         => 'M-Pesa'
                                    ];
                                    echo $methods[$p['payment_method']]
                                         ?? ucfirst($p['payment_method']);
                                    ?>
                                </span>
                            </td>
                            <td style="font-weight:500">
                                KES <?= number_format($p['amount']) ?>
                            </td>
                            <td>
                                <span class="<?= $p['payment_status'] === 'confirmed'
                                    ? 'pay-confirmed' : 'pay-pending' ?>">
                                    <?= ucfirst($p['payment_status']) ?>
                                </span>
                            </td>
                            <td style="color:var(--text-secondary)">
                                <?= date('d M Y',
                                    strtotime($p['payment_date'])) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Monthly Breakdown -->
            <div class="section-card">
                <div class="card-head">
                    <h3>Monthly breakdown</h3>
                </div>
                <div class="monthly-table">
                    <?php if (empty($monthly)): ?>
                        <div style="text-align:center;padding:24px;
                                    font-size:13px;
                                    color:var(--text-secondary)">
                            No earnings data yet
                        </div>
                    <?php else: ?>
                        <?php foreach (array_reverse($monthly) as $m): ?>
                        <div class="month-row">
                            <div>
                                <div class="month-label">
                                    <?= $m['month_label'] ?>
                                </div>
                                <div class="month-count">
                                    <?= $m['count'] ?>
                                    payment<?= $m['count'] != 1
                                               ? 's' : '' ?>
                                </div>
                            </div>
                            <div class="month-amount">
                                KES <?= number_format($m['total']) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>

</body>
</html>