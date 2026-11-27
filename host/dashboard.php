<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';
require_once '../includes/booking_workflow.php';
require_once '../includes/report_helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$host_id = $_SESSION['user_id'];

// Overview stats
// v9.38.1: each figure is its own subquery. The old single query joined bookings, payments and reviews together, so a payment was counted once per
// published review (earnings overstated) and the rating was weighted by the number of bookings.
$stats = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE a.host_id = ?) AS total_bookings,
        (SELECT COUNT(*) FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE a.host_id = ? AND b.status = 'pending') AS pending_bookings,
        (SELECT COUNT(*) FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE a.host_id = ? AND b.status = 'confirmed') AS confirmed_bookings,
        (SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN bookings b ON b.booking_id = p.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id
            WHERE a.host_id = ? AND p.payment_status = 'confirmed') AS total_earnings,
        (SELECT COALESCE(AVG(r.rating), 0) FROM reviews r JOIN accommodations a ON a.accommodation_id = r.accommodation_id WHERE a.host_id = ? AND r.is_published = 1) AS avg_rating,
        (SELECT COUNT(*) FROM reviews r JOIN accommodations a ON a.accommodation_id = r.accommodation_id WHERE a.host_id = ? AND r.is_published = 1) AS total_reviews,
        (SELECT COUNT(*) FROM accommodations WHERE host_id = ?) AS total_listings,
        (SELECT COUNT(*) FROM accommodations WHERE host_id = ? AND status = 'pending') AS pending_listings
");
$stats->execute(array_fill(0, 8, $host_id));
$stats = $stats->fetch();
$comm = hostCommissionSummary($pdo, (int)$host_id);
$agreementOk = hostAgreementAccepted($pdo, (int)$host_id);
$awaitingPay = (int)$pdo->query("SELECT COUNT(*) FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE a.host_id = " . (int)$host_id . " AND b.status = 'payment_pending'")->fetchColumn();

// v9.38.1: summary for a chosen period (default last 30 days); the cards above stay all-time.
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
    AND EXISTS (SELECT 1 FROM guest_reviews gr WHERE gr.booking_id = r.booking_id AND gr.host_id = a.host_id)
");
$pending_reviews->execute([$host_id]);
$pending_reviews = $pending_reviews->fetchColumn();

$earnings_by_month = array_column($monthly, 'earnings', 'month_key');
$chart_months = [];
$chart_values = [];
$chart_start = (new DateTimeImmutable('first day of this month'))
    ->modify('-5 months');
for ($i = 0; $i < 6; $i++) {
    $chart_month = $chart_start->modify("+$i months");
    $chart_months[] = $chart_month;
    $chart_values[] = (float)($earnings_by_month[
        $chart_month->format('Y-m')
    ] ?? 0);
}
$chart_max = max($chart_values) ?: 1;
$chart_x_start = 42;
$chart_x_end = 490;
$chart_y_top = 24;
$chart_y_bottom = 154;
$chart_x_at = static function ($index) use ($chart_x_start, $chart_x_end) {
    return $chart_x_start
        + ($chart_x_end - $chart_x_start) * $index / 5;
};
$chart_y_at = static function ($value) use (
    $chart_max,
    $chart_y_top,
    $chart_y_bottom
) {
    return $chart_y_bottom
        - ($value / $chart_max) * ($chart_y_bottom - $chart_y_top);
};
$chart_points = [];
foreach ($chart_values as $i => $value) {
    $chart_points[] = $chart_x_at($i) . ',' . $chart_y_at($value);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host Dashboard — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <style>
        .sidebar    { background:var(--surface-2);
                      border-right:1px solid var(--border);
                      padding:24px 0; position:sticky;
                      top:52px; height:calc(100vh - 52px);
                      overflow-y:auto }
        .badge      { font-size:10px; background:#DC2626;
                      color:#fff; border-radius:10px;
                      padding:1px 6px; margin-left:auto }
        .main       { padding:28px; overflow-y:auto }
        .page-date  { font-size:11px; font-weight:600;
                      letter-spacing:.06em; color:var(--accent);
                      text-transform:uppercase; margin-bottom:6px }
        .strip-title { font-size:15px; font-weight:600; margin:8px 0 10px; display:flex; justify-content:space-between; align-items:baseline }
        .strip-title a { font-size:13px; font-weight:400 }
        .stats-grid { display:grid;
                      grid-template-columns:repeat(4,1fr);
                      gap:12px; margin-bottom:24px }
        .stat-card  { background:var(--surface-2);
                      border:1px solid var(--border);
                      border-radius:8px; padding:16px;
                      border-left:3px solid transparent }
        .stat-card.accent { border-left-color:var(--accent) }
        .stat-label { font-size:10px; font-weight:700;
                      letter-spacing:.06em; color:var(--text-secondary);
                      text-transform:uppercase; margin-bottom:8px }
        .stat-value { font-size:26px; font-weight:500;
                      color:var(--text-primary); margin-bottom:4px }
        .stat-note  { font-size:12px; color:var(--accent) }
        .stat-note.warn { color:var(--warn-fg) }
        .content-grid { display:grid;
                        grid-template-columns:1fr 300px;
                        gap:20px }
        .section-card { background:var(--surface-2);
                        border:1px solid var(--border);
                        border-radius:8px; overflow:hidden }
        .card-header { padding:14px 18px;
                       border-bottom:1px solid var(--border);
                       display:flex; justify-content:space-between;
                       align-items:center }
        .card-header h3 { font-size:13px; font-weight:500 }
        .card-header a  { font-size:12px; color:var(--accent);
                          text-decoration:none }
        table       { width:100%; border-collapse:collapse;
                      font-size:13px }
        th          { font-size:10px; font-weight:700;
                      letter-spacing:.06em; text-transform:uppercase;
                      color:var(--text-secondary); padding:10px 18px;
                      text-align:left;
                      background:var(--surface-1);
                      border-bottom:1px solid var(--border) }
        td          { padding:12px 18px;
                      border-bottom:1px solid var(--border);
                      color:var(--text-primary) }
        tr:last-child td { border-bottom:none }
        tr:hover td { background:var(--surface-1) }
        .s-confirmed { color:var(--accent-strong); font-weight:500 }
        .s-pending   { color:var(--warn-fg); font-weight:500 }
        .s-completed { color:#1A4DB3; font-weight:500 }
        .s-cancelled { color:var(--bad-fg) }
        .chart-heading { padding:18px 18px 0 }
        .chart-eyebrow { font-size:10px; letter-spacing:.13em;
                         text-transform:uppercase; color:var(--terracotta);
                         font-weight:700; margin-bottom:8px }
        .chart-heading h2 { font-family:Georgia,'Times New Roman',serif;
                            font-size:29px; font-weight:500;
                            letter-spacing:-.02em; margin:0 }
        .chart-wrap  { padding:12px 18px 18px }
        .line-chart  { height:200px; position:relative;
                       background:linear-gradient(180deg,transparent 0,
                       transparent 49%,#eee 50%,transparent 51%) }
        .line-chart svg { width:100%; height:100% }
        .chart-note { font-size:12px; color:var(--text-secondary) }
        .empty-row  { text-align:center; padding:32px;
                      color:var(--text-secondary); font-size:13px }
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
            <h1 class="page-title" style="margin-bottom:0">Host overview</h1>
        </div>
        <?php $hvState = getHostVerification($pdo, $host_id); $hvStatus = $hvState['status'] ?? 'unverified'; ?>
        <?php if ($hvStatus !== 'verified'): ?>
        <div style="border:1px solid var(--warn-line);background:var(--warn-bg);border-radius:8px;padding:12px 16px;margin-bottom:18px;font-size:13px">
            <?php if ($hvStatus === 'pending'): ?>
                <strong>Identity verification pending.</strong> Your email is confirmed; an admin is reviewing your ID.
            <?php elseif ($hvStatus === 'rejected'): ?>
                <strong>Your identity verification was not approved.</strong> <?= h($hvState['reason'] ?? '') ?> <a href="profile.php#verification">Resubmit</a>
            <?php else: ?>
                <strong>Your email is confirmed, but your host identity (ID) is not verified yet.</strong> Verified hosts get a &ldquo;Verified host&rdquo; badge and guests trust them more. <a href="profile.php#verification">Verify my ID</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <p class="page-sub">
            A clear view of what needs attention today.
        </p>
        <?php if (!$agreementOk): ?>
        <div class="alert alert-warning" style="margin-bottom:16px">The host agreement was updated to reflect how commission works. <a href="agreement.php">Read and accept it</a> to add or approve listings.</div>
        <?php endif; ?>
        <?php if ($awaitingPay > 0): ?>
        <div class="alert alert-info" style="margin-bottom:16px"><a href="bookings.php?tab=awaiting"><strong><?= $awaitingPay ?></strong> guest payment<?= $awaitingPay == 1 ? '' : 's' ?> waiting for you to confirm</a>.</div>
        <?php endif; ?>

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
                <div class="stat-label">Awaiting your reply</div>
                <div class="stat-value">
                    <?= $stats['pending_bookings'] ?>
                </div>
                <div class="stat-note <?= $stats['pending_bookings'] > 0
                                          ? 'warn' : '' ?>">
                    <?= $stats['pending_bookings'] > 0
                        ? 'Needs review' : 'All clear' ?>
                </div>
            </div>
            <div class="stat-card hero">
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

        <h2 class="strip-title">Commission <a href="commission.php">Details</a></h2>
        <section class="section-card" style="margin-bottom:24px" aria-label="Summary for a period">
            <h2 style="margin:0 0 6px">Summary for a period</h2>
            <p style="margin:0 0 12px;opacity:.8"><?= h($per['label']) ?>: <?= h(date('j M Y', strtotime($per['from']))) ?> to <?= h(date('j M Y', strtotime($per['to']))) ?>. Bookings are counted by the day they were made; payments by the day they were reported.</p>
            <?= reportFilterHtml($per) ?>
            <div class="stats-grid" style="margin-bottom:12px">
                <div class="stat-card accent"><div class="stat-label">Bookings made</div><div class="stat-value"><?= (int)$pBook['all'] ?></div><div class="stat-note">KES <?= number_format($pBook['value'], 2) ?> booked</div></div>
                <div class="stat-card"><div class="stat-label">Confirmed or completed</div><div class="stat-value"><?= (int)$pBook['won'] ?></div><div class="stat-note"><?= (int)$pBook['lost'] ?> cancelled, declined or expired</div></div>
                <div class="stat-card"><div class="stat-label">Earnings confirmed</div><div class="stat-value">KES <?= number_format((float)$pEarn['got'], 2) ?></div><div class="stat-note">KES <?= number_format((float)$pEarn['waiting'], 2) ?> still to confirm</div></div>
            </div>
            <div class="no-print" style="display:flex;gap:10px;flex-wrap:wrap">
                <?php $ex = http_build_query(['from' => $per['from'], 'to' => $per['to']]); ?>
                <strong style="align-self:center">Download CSV:</strong>
                <a href="export.php?type=bookings&amp;<?= h($ex) ?>">My bookings</a> &middot;
                <a href="export.php?type=payments&amp;<?= h($ex) ?>">My payments</a>
            </div>
        </section>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-label">Commission earned</div><div class="stat-value">KES <?= number_format($comm['earned']) ?></div><div class="stat-note">on confirmed payments</div></div>
            <div class="stat-card"><div class="stat-label">Outstanding</div><div class="stat-value">KES <?= number_format($comm['outstanding']) ?></div><div class="stat-note <?= $comm['overdue'] > 0 ? 'warn' : '' ?>"><?= $comm['overdue'] > 0 ? 'KES ' . number_format($comm['overdue']) . ' overdue' : 'nothing overdue' ?></div></div>
            <div class="stat-card"><div class="stat-label">Pending verification</div><div class="stat-value">KES <?= number_format($comm['pending_payments']) ?></div><div class="stat-note">reported by you</div></div>
            <div class="stat-card accent"><div class="stat-label">Confirmed payments</div><div class="stat-value">KES <?= number_format($comm['confirmed_payments']) ?></div><div class="stat-note"><?= (int)$comm['confirmed_count'] ?> payment<?= $comm['confirmed_count'] == 1 ? '' : 's' ?></div></div>
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
                            <td><?= h($b['title']) ?></td>
                            <td>
                                <?= h(
                                    explode(' ', $b['tourist_name'])[0]
                                ) ?>
                                <?= h(
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
                                ][$b['status']] ?? ($b['status'] === 'accepted' ? 's-confirmed' : 's-pending');
                                ?>
                                <span class="<?= $cls ?>">
                                    <?= h(bookingStatusLabel($b['status'], 'host')) ?>
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
                <div class="chart-heading">
                    <div class="chart-eyebrow">Income</div>
                    <h2>Monthly trend</h2>
                </div>
                <div class="chart-wrap">
                    <?php
                    $chart_description =
                        'Host income from confirmed payments over the last six months';
                    ?>
                    <div class="line-chart">
                        <svg viewBox="0 0 500 200" role="img"
                             aria-label="<?= h(
                                 $chart_description,
                                 ENT_QUOTES,
                                 'UTF-8'
                             ) ?>"
                             preserveAspectRatio="none">
                            <title><?= h(
                                $chart_description,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></title>
                            <?php foreach ([24, 89, 154] as $grid_y): ?>
                            <line x1="<?= $chart_x_start ?>"
                                  y1="<?= $grid_y ?>"
                                  x2="<?= $chart_x_end ?>"
                                  y2="<?= $grid_y ?>"
                                  stroke="#d9d5ca" stroke-width="1"/>
                            <?php endforeach; ?>
                            <polyline
                                points="<?= implode(' ', $chart_points) ?>"
                                fill="none" stroke="#0d3b2e" stroke-width="3"
                                stroke-linejoin="round"
                                stroke-linecap="round"/>
                            <?php foreach ($chart_values as $i => $value): ?>
                            <circle cx="<?= $chart_x_at($i) ?>"
                                    cy="<?= $chart_y_at($value) ?>"
                                    r="4" fill="#0d3b2e">
                                <title><?= h(
                                    $chart_months[$i]->format('F Y') . ': KES '
                                        . number_format($value),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?></title>
                            </circle>
                            <?php endforeach; ?>
                            <?php foreach ($chart_months as $i => $month): ?>
                            <text x="<?= $chart_x_at($i) ?>" y="183"
                                  text-anchor="middle" fill="#565c55"
                                  font-size="11"><?= $month->format('M y') ?></text>
                            <?php endforeach; ?>
                        </svg>
                    </div>
                    <div class="chart-note">
                        <?= !empty($monthly)
                            ? 'Monthly income from confirmed payments over the last six months.'
                            : 'No confirmed payments yet; the trend is zero for the last six months.' ?>
                    </div>
                </div>
            </div>

        </div>
    <?php appShellEnd(); ?>

</body>
</html>