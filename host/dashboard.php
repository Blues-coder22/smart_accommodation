<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$host_id = $_SESSION['user_id'];

// Overview stats
$stats = $pdo->prepare("
    SELECT
        COUNT(DISTINCT b.booking_id) AS total_bookings,
        COUNT(DISTINCT CASE WHEN b.status = 'pending'
              THEN b.booking_id END) AS pending_bookings,
        COUNT(DISTINCT CASE WHEN b.status = 'confirmed'
              THEN b.booking_id END) AS confirmed_bookings,
        COALESCE(SUM(CASE WHEN p.payment_status = 'confirmed'
              THEN p.amount END), 0) AS total_earnings,
        COALESCE(AVG(r.rating), 0) AS avg_rating,
        COUNT(DISTINCT r.review_id) AS total_reviews,
        COUNT(DISTINCT a.accommodation_id) AS total_listings,
        COUNT(DISTINCT CASE WHEN a.status = 'pending'
              THEN a.accommodation_id END) AS pending_listings
    FROM accommodations a
    LEFT JOIN bookings b ON b.accommodation_id = a.accommodation_id
    LEFT JOIN payments p ON p.booking_id = b.booking_id
    LEFT JOIN reviews r
        ON r.accommodation_id = a.accommodation_id
        AND r.is_published = 1
    WHERE a.host_id = ?
");
$stats->execute([$host_id]);
$stats = $stats->fetch();

// Recent bookings
$recent = $pdo->prepare("
    SELECT b.*,
           a.title,
           u.full_name AS tourist_name,
           p.payment_status
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users u ON u.user_id = b.tourist_id
    LEFT JOIN payments p ON p.booking_id = b.booking_id
    WHERE a.host_id = ?
    ORDER BY b.created_at DESC
    LIMIT 6
");
$recent->execute([$host_id]);
$recent = $recent->fetchAll();

// Monthly earnings for trend chart
$monthly = $pdo->prepare("
    SELECT
        DATE_FORMAT(p.payment_date, '%b') AS month,
        DATE_FORMAT(p.payment_date, '%Y-%m') AS month_key,
        COALESCE(SUM(p.amount), 0) AS earnings
    FROM payments p
    JOIN bookings b ON b.booking_id = p.booking_id
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    WHERE a.host_id = ?
    AND p.payment_status = 'confirmed'
    AND p.payment_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_key, month
    ORDER BY month_key ASC
");
$monthly->execute([$host_id]);
$monthly = $monthly->fetchAll();

// Pending reviews count
$pending_reviews = $pdo->prepare("
    SELECT COUNT(*) FROM reviews r
    JOIN accommodations a ON a.accommodation_id = r.accommodation_id
    WHERE a.host_id = ?
    AND r.is_published = 1
    AND r.host_reply IS NULL
");
$pending_reviews->execute([$host_id]);
$pending_reviews = $pending_reviews->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host Dashboard — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body        { display:flex; flex-direction:column;
                      min-height:100vh }
        .dash-wrap  { display:grid;
                      grid-template-columns:200px 1fr;
                      flex:1 }
        .sidebar    { background:var(--surface-2);
                      border-right:0.5px solid var(--border);
                      padding:24px 0; position:sticky;
                      top:52px; height:calc(100vh - 52px);
                      overflow-y:auto }
        .sidebar-role { font-size:10px; font-weight:700;
                        letter-spacing:.08em; color:#1D9E75;
                        text-transform:uppercase;
                        padding:0 20px; margin-bottom:12px }
        .sidebar-link { display:flex; align-items:center; gap:10px;
                        padding:9px 20px; font-size:13px;
                        color:var(--text-secondary); cursor:pointer;
                        text-decoration:none; border:none;
                        background:none; width:100%;
                        text-align:left; position:relative }
        .sidebar-link:hover { color:var(--text-primary);
                              background:var(--surface-1) }
        .sidebar-link.active { color:#085041; font-weight:500;
                               background:#E1F5EE;
                               border-left:3px solid #1D9E75 }
        .badge      { font-size:10px; background:#DC2626;
                      color:#fff; border-radius:10px;
                      padding:1px 6px; margin-left:auto }
        .main       { padding:28px; overflow-y:auto }
        .page-date  { font-size:11px; font-weight:600;
                      letter-spacing:.06em; color:#1D9E75;
                      text-transform:uppercase; margin-bottom:6px }
        .page-title { font-size:26px; font-weight:400;
                      margin-bottom:4px }
        .page-sub   { font-size:13px; color:var(--text-secondary);
                      margin-bottom:24px }
        .stats-grid { display:grid;
                      grid-template-columns:repeat(4,1fr);
                      gap:12px; margin-bottom:24px }
        .stat-card  { background:var(--surface-2);
                      border:0.5px solid var(--border);
                      border-radius:8px; padding:16px;
                      border-left:3px solid transparent }
        .stat-card.accent { border-left-color:#1D9E75 }
        .stat-label { font-size:10px; font-weight:700;
                      letter-spacing:.06em; color:var(--text-secondary);
                      text-transform:uppercase; margin-bottom:8px }
        .stat-value { font-size:26px; font-weight:500;
                      color:var(--text-primary); margin-bottom:4px }
        .stat-note  { font-size:12px; color:#1D9E75 }
        .stat-note.warn { color:#D97706 }
        .content-grid { display:grid;
                        grid-template-columns:1fr 300px;
                        gap:20px }
        .section-card { background:var(--surface-2);
                        border:0.5px solid var(--border);
                        border-radius:8px; overflow:hidden }
        .card-header { padding:14px 18px;
                       border-bottom:0.5px solid var(--border);
                       display:flex; justify-content:space-between;
                       align-items:center }
        .card-header h3 { font-size:13px; font-weight:500 }
        .card-header a  { font-size:12px; color:#1D9E75;
                          text-decoration:none }
        table       { width:100%; border-collapse:collapse;
                      font-size:13px }
        th          { font-size:10px; font-weight:700;
                      letter-spacing:.06em; text-transform:uppercase;
                      color:var(--text-secondary); padding:10px 18px;
                      text-align:left;
                      background:var(--surface-1);
                      border-bottom:0.5px solid var(--border) }
        td          { padding:12px 18px;
                      border-bottom:0.5px solid var(--border);
                      color:var(--text-primary) }
        tr:last-child td { border-bottom:none }
        tr:hover td { background:var(--surface-1) }
        .s-confirmed { color:#0F6E56; font-weight:500 }
        .s-pending   { color:#D97706; font-weight:500 }
        .s-completed { color:#1A4DB3; font-weight:500 }
        .s-cancelled { color:#DC2626 }
        .chart-wrap  { padding:18px }
        .chart-bars  { display:flex; align-items:flex-end;
                       gap:8px; height:120px;
                       border-bottom:0.5px solid var(--border);
                       padding-bottom:8px; margin-bottom:8px }
        .bar-col    { flex:1; display:flex; flex-direction:column;
                      align-items:center; gap:4px; height:100% }
        .bar        { width:100%; background:#1D9E75;
                      border-radius:3px 3px 0 0;
                      min-height:2px; transition:.3s }
        .bar-label  { font-size:10px; color:var(--text-secondary) }
        .empty-row  { text-align:center; padding:32px;
                      color:var(--text-secondary); font-size:13px }
        @media(max-width:900px){
            .dash-wrap { grid-template-columns:1fr }
            .sidebar   { display:none }
            .stats-grid { grid-template-columns:repeat(2,1fr) }
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
        <a href="dashboard.php"
           class="sidebar-link active">Overview</a>
        <a href="listings.php"
           class="sidebar-link">
            Listings
            <?php if ($stats['pending_listings'] > 0): ?>
                <span class="badge">
                    <?= $stats['pending_listings'] ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="availability.php"
           class="sidebar-link">Availability</a>
        <a href="bookings.php"
           class="sidebar-link">
            Bookings
            <?php if ($stats['pending_bookings'] > 0): ?>
                <span class="badge">
                    <?= $stats['pending_bookings'] ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="payments.php"
           class="sidebar-link">Payments</a>
        <a href="reviews.php"
           class="sidebar-link">
            Reviews
            <?php if ($pending_reviews > 0): ?>
                <span class="badge">
                    <?= $pending_reviews ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="report.php"
           class="sidebar-link">Reports</a>
        <a href="profile.php"
           class="sidebar-link">Profile</a>
    </aside>

    <!-- Main Content -->
    <main class="main">
        <div class="page-date">
            <?= strtoupper(date('l, j F')) ?>
        </div>
        <h1 class="page-title">
            Host overview
        </h1>
        <p class="page-sub">
            A clear view of what needs attention today.
        </p>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card accent">
                <div class="stat-label">Total bookings</div>
                <div class="stat-value">
                    <?= $stats['total_bookings'] ?>
                </div>
                <div class="stat-note">
                    <?= $stats['confirmed_bookings'] ?> confirmed
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending</div>
                <div class="stat-value">
                    <?= $stats['pending_bookings'] ?>
                </div>
                <div class="stat-note <?= $stats['pending_bookings'] > 0
                                          ? 'warn' : '' ?>">
                    <?= $stats['pending_bookings'] > 0
                        ? 'Needs review' : 'All clear' ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Earnings</div>
                <div class="stat-value">
                    KES <?= number_format($stats['total_earnings']) ?>
                </div>
                <div class="stat-note">Confirmed payments</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Rating</div>
                <div class="stat-value">
                    <?= $stats['avg_rating'] > 0
                        ? number_format($stats['avg_rating'], 1)
                        : '—' ?>
                </div>
                <div class="stat-note">
                    <?= $stats['total_reviews'] ?> reviews
                </div>
            </div>
        </div>

        <div class="content-grid">

            <!-- Recent Activity Table -->
            <div class="section-card">
                <div class="card-header">
                    <h3>Recent activity</h3>
                    <a href="bookings.php">View all</a>
                </div>
                <?php if (empty($recent)): ?>
                    <div class="empty-row">
                        No bookings yet
                    </div>
                <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Property</th>
                            <th>Guest</th>
                            <th>Status</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['title']) ?></td>
                            <td>
                                <?= htmlspecialchars(
                                    explode(' ', $b['tourist_name'])[0]
                                ) ?>
                                <?= htmlspecialchars(
                                    explode(' ', $b['tourist_name'])[1]
                                    ?? '') ?>
                            </td>
                            <td>
                                <?php
                                $cls = [
                                    'confirmed' => 's-confirmed',
                                    'pending'   => 's-pending',
                                    'completed' => 's-completed',
                                    'cancelled' => 's-cancelled'
                                ][$b['status']] ?? '';
                                ?>
                                <span class="<?= $cls ?>">
                                    <?= ucfirst($b['status']) ?>
                                </span>
                            </td>
                            <td>
                                KES <?= number_format($b['total_amount']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Monthly Trend Chart -->
            <div class="section-card">
                <div class="card-header">
                    <h3>Monthly trend</h3>
                </div>
                <div class="chart-wrap">
                    <?php if (!empty($monthly)): ?>
                    <?php
                    $max_earn = max(array_column($monthly, 'earnings'));
                    $max_earn = $max_earn > 0 ? $max_earn : 1;
                    ?>
                    <div class="chart-bars">
                        <?php foreach ($monthly as $m): ?>
                        <div class="bar-col">
                            <div class="bar" style="height:<?= round(
                                ($m['earnings'] / $max_earn) * 100
                            ) ?>%"
                                 title="KES <?= number_format(
                                     $m['earnings']) ?>">
                            </div>
                            <div class="bar-label">
                                <?= $m['month'] ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="font-size:12px;color:var(--text-secondary)">
                        Earnings last 6 months
                    </div>
                    <?php else: ?>
                    <div style="text-align:center;padding:40px 0;
                                font-size:13px;
                                color:var(--text-secondary)">
                        No earnings data yet
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>

</div>

<?php include '../includes/footer.php'; ?>

</body>
</html>