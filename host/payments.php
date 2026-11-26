<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/notifications.php';
require_once '../includes/commission.php';
ensureCommissionTables($pdo);
require_once '../includes/payment_audit.php';
require_once '../includes/booking_events.php';
require_once '../includes/booking_workflow.php';
require_once '../includes/booking_holds.php';
expireStaleBookingRequests($pdo);
ensurePaymentAuditTable($pdo);

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$host_id = (int)$_SESSION['user_id'];

// The host confirms that the guest's money really arrived. This is the ONLY way a booking becomes 'confirmed'.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_payment') {
    requireCsrf();
    $paymentId = opaqueIdDecode('pay', $_POST['payment_ref'] ?? '');
    $r = hostConfirmPaymentReceived($pdo, (int)$paymentId, $host_id);
    setFlash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Payment confirmed. The booking is now confirmed and the guest was notified.' : $r['error']);
    redirect('payments.php?filter=' . urlencode($_GET['filter'] ?? 'all'));
}

// Mark a payment as received (or undo it). Only the host who owns the booking can do this.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    require_once '../includes/email.php';
    $payment_id = opaqueIdDecode('pay', $_POST['payment_ref'] ?? '');
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

    if ($pay && in_array($pay['booking_status'], ['cancelled', 'pending'], true)) {
        setFlash('error', 'This booking is ' . $pay['booking_status'] . ', so its payment cannot be changed.');
    } elseif ($pay && $action === 'mark_paid' && in_array($pay['payment_status'], ['pending', 'failed'], true)) {
        $r = hostConfirmPaymentReceived($pdo, (int)$payment_id, $host_id);
        setFlash($r['ok'] ? 'success' : 'error', $r['ok'] ? 'Payment confirmed. The booking is now confirmed.' : $r['error']);
    } elseif ($pay && $action === 'mark_unpaid' && $pay['payment_status'] === 'confirmed' && paymentLockedByAdmin($pdo, $payment_id)) {
        setFlash('warning', 'An admin has confirmed this payment, so it cannot be set back to awaiting payment. If something is wrong, tell the admin.');
    } elseif ($pay && $action === 'mark_unpaid' && $pay['payment_status'] === 'confirmed') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            setFlash('error', 'Please say why you are setting this payment back to awaiting payment (at least 5 characters). The guest and the admin will see your reason.');
        } elseif (in_array($pay['booking_status'], ['completed', 'no_show'], true) || $pay['check_in_date'] <= date('Y-m-d')) {
            // After check-in a received payment is locked so a host cannot cancel a payment the guest really made.
            setFlash('warning', 'The check-in day has come or passed, so a received payment can no longer be undone here. If something is wrong, tell the admin.');
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE payments SET payment_status = 'pending' WHERE payment_id = ?")->execute([$payment_id]);
            if ($pay['booking_status'] === 'confirmed') transitionBooking($pdo, (int)$pay['booking_id'], 'payment_pending', 'host', $host_id, ['event' => 'payment_reversed_status', 'note' => $reason]);
            $pdo->commit();
            // Recorded so an admin can see that a payment marked as paid was later reversed, and why
            logPaymentChange($pdo, $payment_id, $host_id, 'host', 'host_unpaid', 'confirmed', 'pending', $reason);
            logBookingEvent($pdo, $pay['booking_id'], $host_id, 'payment_reversed', $reason);
            try {   // the guest and every admin are told straight away
                sendPaymentReversedEmail($pay['tourist_email'], $pay['tourist_name'], $pay, $reason);
                notifyUserOfBooking($pdo, $pay['booking_id'], 'tourist', 'Payment set back to unpaid', 'Your host marked ' . bookingLabel($pay['booking_id']) . ' as not received. Reason: ' . $reason, 'tourist/bookings.php');
                notifyAdmins($pdo, 'A host reversed a payment', bookingLabel($pay['booking_id']) . ': ' . $reason, 'admin/payments.php');
                foreach (adminEmailAddresses($pdo) as $adminMail) {
                    sendPaymentReversedAdminEmail($adminMail, $pay, $_SESSION['full_name'] ?? 'Host', $pay['tourist_name'], $reason);
                }
            } catch (Throwable $e) { error_log('Reversal emails: ' . $e->getMessage()); }
            setFlash('pending', 'Payment set back to awaiting payment. Your reason was saved, and the guest and the admin were emailed.');
        }
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
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <style>
        .sidebar     { background:var(--surface-2);
                       border-right:1px solid var(--border);
                       padding:24px 0; position:sticky;
                       top:52px; height:calc(100vh - 52px);
                       overflow-y:auto }
        .main        { padding:28px }
        .stats-row   { display:grid;
                       grid-template-columns:repeat(4,1fr);
                       gap:12px; margin-bottom:24px }
        .stat-card   { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:16px }
        .stat-card.accent { border-left:3px solid var(--accent) }
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
        .filter-tab.active { background:var(--btn-hover); color:#fff;
                             border-color:var(--accent-strong) }
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
        .pay-confirmed { color:var(--accent-strong); font-weight:600;
                         font-size:12px }
        .pay-pending   { color:var(--warn-fg); font-weight:600;
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
        @media(max-width:1024px){
            .sidebar   { display:none }
            .stats-row { grid-template-columns:repeat(2,1fr) }
            .content-grid { grid-template-columns:1fr }
        }
    </style>
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>

<?php if ($flash = getFlash()): ?><div class="alert alert-<?= h($flash['type'], ENT_QUOTES, 'UTF-8') ?>" style="margin:16px 28px 0"><?= h($flash['message'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <div class="page-tag">Host</div>
        <h1 class="page-title">Payments</h1>

        <?= showFlash() ?>
        <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px">
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
                                    <?= h(bookingLabel($p['booking_id'])) ?>
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
                                <?php if ($p['payment_status'] === 'pending' && in_array($p['booking_status'], ['payment_pending','accepted','confirmed'], true)): ?>
                                <form method="post" style="margin:0" onsubmit="return confirm('Have you independently verified that this payment was received?');">
                                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="confirm_payment">
                                    <input type="hidden" name="payment_ref" value="<?= h(opaqueId('pay', $p['payment_id'])) ?>">
                                    <button class="btn btn-outline" type="submit">Mark verified</button>
                                </form>
                                <?php else: ?><span class="small">—</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['payment_reference'])): ?>
                                    <div style="font-size:11px">Ref: <?= h($p['payment_reference']) ?></div>
                                <?php endif; ?>
                                <div style="font-size:11px;margin:3px 0">
                                    <?php if ($p['payment_status'] === 'confirmed'): ?><a href="../receipt.php?as=host&amp;pid=<?= h(opaqueId('pay', $p['payment_id'])) ?>" target="_blank" rel="noopener">Receipt</a><?php endif; ?>
                                    <?php if (!empty($p['payment_proof'])): ?><?= $p['payment_status'] === 'confirmed' ? ' &middot; ' : '' ?><a href="../proof.php?as=host&amp;type=payment&amp;ref=<?= h(opaqueId('pay', $p['payment_id'])) ?>" target="_blank" rel="noopener">Guest's screenshot</a><?php endif; ?>
                                </div>
                                <?php if (in_array($p['booking_status'], ['cancelled', 'pending'], true)): ?>
                                    <span class="small"><?= $p['booking_status'] === 'cancelled' ? 'Booking cancelled: this payment can no longer be marked as paid.' : 'No payment is due until you accept the request.' ?></span>
                                <?php elseif ($p['payment_status'] === 'pending' && !in_array($p['booking_status'], ['accepted', 'payment_pending', 'confirmed'], true)): ?>
                                    <span class="small">Booking is <?= h(str_replace('_', ' ', $p['booking_status'])) ?>: no action possible.</span>
                                <?php else: ?>
                                <form method="POST" style="margin:0">
                                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                                    <input type="hidden" name="payment_ref" value="<?= h(opaqueId('pay', $p['payment_id'])) ?>">
                                    <?php if ($p['payment_status'] === 'pending'): ?>
                                        <button name="action" value="mark_paid" type="submit">Mark as paid</button>
                                    <?php elseif ($p['payment_status'] === 'confirmed' && ($p['booking_status'] === 'completed' || $p['booking_status'] === 'no_show' || $p['check_in_date'] <= date('Y-m-d'))): ?>
                                        <span class="small">Locked after check-in. Contact the admin to change it.</span>
                                    <?php elseif ($p['payment_status'] === 'confirmed'): ?>
                                        <input type="text" name="reason" required minlength="5" maxlength="200" aria-label="Reason for setting this payment back to awaiting" placeholder="Reason (sent to guest and admin)" style="width:100%;margin-bottom:6px">
                                        <button name="action" value="mark_unpaid" type="submit" onclick="return confirm('Set this received payment back to awaiting? The guest and the admin will be emailed your reason.');">Mark unpaid</button>
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