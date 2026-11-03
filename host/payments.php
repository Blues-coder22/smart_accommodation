<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/payment_audit.php';
ensurePaymentAuditTable($pdo);

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$host_id = (int)$_SESSION['user_id'];

// Payment verification is a separate action from accepting the booking.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_payment') {
    requireCsrf();
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $owns = $pdo->prepare("SELECT p.payment_id, b.status AS booking_status FROM payments p JOIN bookings b ON b.booking_id=p.booking_id JOIN accommodations a ON a.accommodation_id=b.accommodation_id WHERE p.payment_id=? AND a.host_id=? LIMIT 1");
    $owns->execute([$paymentId, $host_id]);
    $paymentToVerify = $owns->fetch();
    if (!$paymentToVerify) {
        setFlash('error', 'Payment record not found for your listings.');
    } elseif (!in_array($paymentToVerify['booking_status'], ['pending','confirmed'], true)) {
        setFlash('error', 'Payment verification is unavailable for a cancelled or completed booking. Handle any received funds through your normal refund process.');
    } else {
        $pdo->prepare("UPDATE payments SET payment_status='confirmed' WHERE payment_id=? AND payment_status='pending'")->execute([$paymentId]);
        logPaymentChange($pdo, $paymentId, $host_id, 'host', 'host_confirmed', 'pending', 'confirmed');
        setFlash('success', 'Payment marked as verified. This does not change the booking status.');
    }
    redirect('payments.php?filter=' . urlencode($_GET['filter'] ?? 'all'));
}

// Mark a payment as received (or undo it). Only the host who owns the booking can do this.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    require_once '../includes/email.php';
    $payment_id = (int)($_POST['payment_id'] ?? 0);
    $action     = $_POST['action'] ?? '';

    $q = $pdo->prepare("
        SELECT p.payment_id, p.payment_status, p.amount, p.booking_id, b.check_in_date, b.check_out_date,
               b.status AS booking_status, a.title,
               t.email AS tourist_email, t.full_name AS tourist_name
        FROM payments p
        JOIN bookings b ON b.booking_id = p.booking_id
        JOIN accommodations a ON a.accommodation_id = b.accommodation_id
        JOIN users t ON t.user_id = p.tourist_id
        WHERE p.payment_id = ? AND a.host_id = ?
    ");
    $q->execute([$payment_id, $host_id]);
    $pay = $q->fetch();

    if ($pay && $pay['booking_status'] === 'cancelled') {
        setFlash('error', 'This booking was cancelled, so its payment cannot be changed.');
    } elseif ($pay && $action === 'mark_paid' && $pay['payment_status'] === 'pending') {
        $pdo->prepare("UPDATE payments SET payment_status = 'confirmed' WHERE payment_id = ?")
            ->execute([$payment_id]);
        logPaymentChange($pdo, $payment_id, $host_id, 'host', 'host_confirmed', 'pending', 'confirmed');
        sendPaymentVerifiedEmail($pay['tourist_email'], $pay['tourist_name'], [
            'booking_id'     => $pay['booking_id'],
            'title'          => $pay['title'],
            'check_in_date'  => $pay['check_in_date'],
            'check_out_date' => $pay['check_out_date'],
            'total_amount'   => $pay['amount'],
        ]);
        setFlash('success', 'Payment marked as received.');
    } elseif ($pay && $action === 'mark_unpaid' && $pay['payment_status'] === 'confirmed' && paymentLockedByAdmin($pdo, $payment_id)) {
        setFlash('warning', 'An administrator has confirmed this payment, so it cannot be set back to awaiting. If something is wrong, report it to the administrator.');
    } elseif ($pay && $action === 'mark_unpaid' && $pay['payment_status'] === 'confirmed') {
        $pdo->prepare("UPDATE payments SET payment_status = 'pending' WHERE payment_id = ?")
            ->execute([$payment_id]);
        // Recorded so an administrator can see that a payment marked as paid was later reversed
        logPaymentChange($pdo, $payment_id, $host_id, 'host', 'host_unpaid', 'confirmed', 'pending', 'Host set a received payment back to awaiting');
        setFlash('pending', 'Payment set back to awaiting. This change is recorded and visible to the administrator.');
    }
    redirect('payments.php?filter=' . urlencode(sanitize($_GET['filter'] ?? 'all')));
}

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
    <title>Payments — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        
        .dash-wrap   { display:grid;
                       grid-template-columns:200px 1fr; flex:1 }
        .sidebar     { background:var(--surface-2);
                       border-right:1px solid var(--border);
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
                       border:1px solid var(--border);
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
                       border:1px solid var(--border);
                       border-radius:8px; overflow:hidden }
        .card-head   { padding:14px 18px;
                       border-bottom:1px solid var(--border);
                       display:flex; justify-content:space-between;
                       align-items:center }
        .card-head h3{ font-size:13px; font-weight:500 }
        .filter-tabs { display:flex; gap:6px }
        .filter-tab  { padding:5px 12px; font-size:12px;
                       border-radius:4px; cursor:pointer;
                       text-decoration:none;
                       color:var(--text-secondary);
                       border:1px solid var(--border) }
        .filter-tab.active { background:#085041; color:#fff;
                             border-color:#085041 }
        table        { width:100%; border-collapse:collapse;
                       font-size:13px }
        th           { font-size:10px; font-weight:700;
                       letter-spacing:.06em; text-transform:uppercase;
                       color:var(--text-secondary); padding:10px 18px;
                       text-align:left; background:var(--surface-1);
                       border-bottom:1px solid var(--border) }
        td           { padding:12px 18px;
                       border-bottom:1px solid var(--border);
                       color:var(--text-primary) }
        tr:last-child td { border-bottom:none }
        tr:hover td  { background:var(--surface-1) }
        .pay-confirmed { color:#0F6E56; font-weight:600;
                         font-size:12px }
        .pay-pending   { color:#D97706; font-weight:600;
                         font-size:12px }
        .method-label  { font-size:11px; color:var(--text-secondary);
                         background:var(--surface-1);
                         border:1px solid var(--border);
                         border-radius:3px; padding:2px 7px }
        .monthly-table { padding:16px }
        .month-row   { display:flex; justify-content:space-between;
                       align-items:center; padding:10px 0;
                       border-bottom:1px solid var(--border);
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

<?php if ($flash = getFlash()): ?><div class="alert alert-<?= h($flash['type'], ENT_QUOTES, 'UTF-8') ?>" style="margin:16px 28px 0"><?= h($flash['message'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <div class="page-tag">Host</div>
        <h1 class="page-title">Payments</h1>

        <?= showFlash() ?>
        <div style="background:var(--surface,#f5f7f6);border:1px solid var(--border,#dde);border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px">
            Guests pay you directly (cash, bank transfer or M-Pesa). When you have received a payment, mark it as paid so the guest sees the status update.
        </div>

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
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td>
                                <div style="font-weight:500">
                                    <?= h(
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
                                <?= h(
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
                                <?php if (!empty($p['payment_reference'])): ?><div style="font-size:11px;color:var(--text-secondary);margin-top:4px">Ref: <?= h($p['payment_reference'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
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
                                <?= date('d M Y', strtotime($p['payment_date'])) ?>
                            </td>
                            <td>
                                <?php if ($p['payment_status'] === 'pending' && in_array($p['booking_status'], ['pending','confirmed'], true)): ?>
                                <form method="post" style="margin:0" onsubmit="return confirm('Have you independently verified that this payment was received?');">
                                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="confirm_payment">
                                    <input type="hidden" name="payment_id" value="<?= (int)$p['payment_id'] ?>">
                                    <button class="btn btn-outline" type="submit">Mark verified</button>
                                </form>
                                <?php else: ?><span class="small">—</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['payment_reference'])): ?>
                                    <div style="font-size:11px">Ref: <?= h($p['payment_reference']) ?></div>
                                <?php endif; ?>
                                <?php if ($p['booking_status'] !== 'cancelled'): ?>
                                <form method="POST" style="margin:0">
                                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                    <input type="hidden" name="payment_id" value="<?= (int)$p['payment_id'] ?>">
                                    <?php if ($p['payment_status'] === 'pending'): ?>
                                        <button name="action" value="mark_paid" type="submit">Mark as paid</button>
                                    <?php elseif ($p['payment_status'] === 'confirmed'): ?>
                                        <button name="action" value="mark_unpaid" type="submit">Mark unpaid</button>
                                    <?php endif; ?>
                                </form>
                                <?php endif; ?>
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
    <?php appShellEnd(); ?>

</body>
</html>