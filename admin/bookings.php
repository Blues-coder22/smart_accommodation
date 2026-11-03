<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/review_extras.php';   // person chips (photo + name + profile link)
require_once '../includes/booking_events.php';
ensureBookingEventsTable($pdo);

if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');

$filter = sanitize($_GET['filter'] ?? 'attention');
$search = trim((string)($_GET['q'] ?? ''));
$today  = date('Y-m-d');

// One row per booking with its latest payment (if any) and a plain "what to check" note.
$base = "
    SELECT b.*, a.title,
           t.user_id AS t_id, t.full_name AS t_name, t.profile_photo AS t_photo,
           h.user_id AS h_id, h.full_name AS h_name, h.profile_photo AS h_photo,
           p.payment_id, p.payment_status, p.amount AS paid_amount, p.payment_method, p.payment_reference,
           (SELECT e.note FROM booking_events e WHERE e.booking_id = b.booking_id AND e.event = 'early_completion' ORDER BY e.event_id DESC LIMIT 1) AS early_note,
           CASE
             WHEN b.status = 'completed' AND EXISTS (SELECT 1 FROM booking_events e2 WHERE e2.booking_id = b.booking_id AND e2.event = 'early_completion') THEN 'early_completed'
             WHEN b.status = 'cancelled' AND p.payment_status = 'confirmed' THEN 'cancelled_paid'
             WHEN b.status = 'cancelled' AND p.payment_status = 'pending'   THEN 'cancelled_awaiting'
             WHEN b.status IN ('pending','confirmed') AND p.payment_id IS NULL AND b.check_in_date < CURDATE() THEN 'past_unpaid'
             WHEN b.status IN ('pending','confirmed') AND (p.payment_id IS NULL OR p.payment_status <> 'confirmed') AND b.check_in_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) THEN 'soon_unpaid'
             WHEN b.status IN ('completed','no_show') AND (p.payment_id IS NULL OR p.payment_status <> 'confirmed') THEN 'done_unpaid'
             ELSE ''
           END AS flag
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users t ON t.user_id = b.tourist_id
    JOIN users h ON h.user_id = a.host_id
    LEFT JOIN payments p ON p.payment_id = (SELECT MAX(p2.payment_id) FROM payments p2 WHERE p2.booking_id = b.booking_id)";

$where = '1=1'; $params = [];
switch ($filter) {
    case 'attention': $where = "flag <> ''"; break;
    case 'unpaid':    $where = "status IN ('pending','confirmed') AND (payment_status IS NULL OR payment_status <> 'confirmed')"; break;
    case 'cancelled': $where = "status = 'cancelled'"; break;
    case 'cancelled_paid': $where = "flag = 'cancelled_paid'"; break;
    case 'upcoming':  $where = "status IN ('pending','confirmed') AND check_out_date >= CURDATE()"; break;
    case 'completed': $where = "status IN ('completed','no_show')"; break;
    case 'all':       break;
    default:          $filter = 'all';
}
if ($search !== '') {
    $where .= " AND (title LIKE :s1 OR t_name LIKE :s2 OR h_name LIKE :s3 OR payment_reference LIKE :s4 OR booking_id = :sid)";
    $params[':s1'] = $params[':s2'] = $params[':s3'] = $params[':s4'] = "%$search%";
    $params[':sid'] = (int)$search;
}
$stmt = $pdo->prepare("SELECT * FROM ($base) x WHERE $where ORDER BY booking_id DESC LIMIT 200");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$c = $pdo->query("SELECT
    SUM(flag <> '') AS attention,
    SUM(flag = 'cancelled_paid') AS cancelled_paid,
    SUM(status IN ('pending','confirmed') AND (payment_status IS NULL OR payment_status <> 'confirmed')) AS unpaid,
    SUM(status = 'cancelled') AS cancelled
    FROM ($base) y")->fetch(PDO::FETCH_ASSOC);

$notes = [
    'cancelled_paid'     => ['#fdecea', '#8a2b22', 'Cancelled, but the payment is marked received. A refund may be owed. Check with the host.'],
    'cancelled_awaiting' => ['#fff6dc', '#7a5a00', 'Cancelled while a payment was awaiting confirmation. Make sure no money is held.'],
    'past_unpaid'        => ['#fdecea', '#8a2b22', 'Check-in date has passed and no payment was ever submitted.'],
    'soon_unpaid'        => ['#fff6dc', '#7a5a00', 'Check-in is within 3 days and the payment is not confirmed yet.'],
    'early_completed'    => ['#f3e8ee', '#6e2a4d', 'The host completed this stay before the check-out date. Read the reason below and ask the guest if unsure.'],
    'done_unpaid'        => ['#fff6dc', '#7a5a00', 'The stay is over but the payment was never confirmed.'],
];
$bookingBadge = ['pending' => ['#fff6dc', '#7a5a00', 'Pending'], 'confirmed' => ['#e7f3ec', '#1f5a3f', 'Confirmed'],
                 'cancelled' => ['#fdecea', '#8a2b22', 'Cancelled'], 'completed' => ['#e8f0fa', '#24476f', 'Completed'], 'no_show' => ['#f3e8ee', '#6e2a4d', 'No show']];
function payLabel($r) {
    if (!$r['payment_id']) return ['#f1efe8', '#6b6f68', 'No payment submitted'];
    return ['confirmed' => ['#e7f3ec', '#1f5a3f', 'Received'], 'pending' => ['#fff6dc', '#7a5a00', 'Awaiting host'], 'failed' => ['#fdecea', '#8a2b22', 'Failed']][$r['payment_status']] ?? ['#f1efe8', '#6b6f68', 'Unknown'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booking tracking — Admin</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.bk-table{width:100%;border-collapse:collapse;font-size:13px}
.bk-table th{text-align:left;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6b6f68;padding:10px 12px;border-bottom:1px solid #e4e1d8;white-space:nowrap}
.bk-table td{padding:12px;border-bottom:1px solid #eee;vertical-align:top}
.pill{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}
.note{border-radius:8px;padding:7px 10px;font-size:12px;margin-top:8px;line-height:1.4}
</style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <div class="page-date"><?= strtoupper(date('l, j F')) ?></div>
        <h1 class="page-title">Booking tracking</h1>
        <p class="page-sub">Every booking with its status and payment side by side, so you can see what is cancelled, paid or still owed.</p>

        <?= showFlash() ?>

        <section class="attention-grid" aria-label="Summary">
            <a class="attention-tile <?= (int)$c['attention'] ? 'urgent' : 'clear' ?>" href="?filter=attention"><span class="attention-label">Needs a look</span><span class="attention-count"><?= (int)$c['attention'] ?></span><span class="attention-note">Unpaid soon, or cancelled with money</span><span class="attention-go">Open &rarr;</span></a>
            <a class="attention-tile <?= (int)$c['cancelled_paid'] ? 'urgent' : 'clear' ?>" href="?filter=cancelled_paid"><span class="attention-label">Cancelled but paid</span><span class="attention-count"><?= (int)$c['cancelled_paid'] ?></span><span class="attention-note">Refund may be owed</span><span class="attention-go">Open &rarr;</span></a>
            <a class="attention-tile clear" href="?filter=unpaid"><span class="attention-label">Active, not paid</span><span class="attention-count" style="color:var(--ink)"><?= (int)$c['unpaid'] ?></span><span class="attention-note">Pending or confirmed bookings</span><span class="attention-go">Open &rarr;</span></a>
            <a class="attention-tile clear" href="?filter=cancelled"><span class="attention-label">Cancelled</span><span class="attention-count" style="color:var(--ink)"><?= (int)$c['cancelled'] ?></span><span class="attention-note">All time</span><span class="attention-go">Open &rarr;</span></a>
        </section>

        <div class="filter-tabs" style="margin-bottom:12px">
            <?php foreach (['attention' => 'Needs a look', 'unpaid' => 'Active, not paid', 'upcoming' => 'Upcoming', 'cancelled' => 'Cancelled', 'cancelled_paid' => 'Cancelled but paid', 'completed' => 'Completed', 'all' => 'All'] as $k => $lbl): ?>
                <a class="filter-tab <?= $filter === $k ? 'active' : '' ?>" href="?filter=<?= $k ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"><?= h($lbl) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap">
            <input type="hidden" name="filter" value="<?= h($filter) ?>">
            <input aria-label="Search a booking number, guest, host, listing or payment reference" type="text" name="q" value="<?= h($search) ?>" placeholder="Search a booking number, guest, host, listing or payment reference" style="flex:1;min-width:220px;padding:9px 12px;border:1px solid #d8d4c8;border-radius:8px">
            <button class="btn" type="submit">Search</button>
        </form>

        <div class="scroll-x">
        <table class="bk-table">
            <thead><tr><th>Booking</th><th>Guest and host</th><th>Booking status</th><th>Payment</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="4" style="text-align:center;color:#888;padding:30px">Nothing here. <?= $filter === 'attention' ? 'No bookings need a look right now.' : '' ?></td></tr><?php endif; ?>
            <?php foreach ($rows as $r): $bb = $bookingBadge[$r['status']] ?? ['#f1efe8', '#6b6f68', ucfirst($r['status'])]; $pl = payLabel($r); ?>
            <tr>
                <td>
                    <strong>#<?= (int)$r['booking_id'] ?></strong> &middot; <a href="../tourist/details.php?id=<?= (int)$r['accommodation_id'] ?>"><?= h($r['title']) ?></a><br>
                    <span style="color:#777"><?= date('d M', strtotime($r['check_in_date'])) ?> &ndash; <?= date('d M Y', strtotime($r['check_out_date'])) ?> &middot; <?= (int)$r['num_guests'] ?> guest<?= $r['num_guests'] == 1 ? '' : 's' ?></span><br>
                    <strong>KES <?= number_format($r['total_amount']) ?></strong>
                    <span style="color:#999;font-size:11px">&middot; booked <?= date('d M Y', strtotime($r['created_at'])) ?></span>
                </td>
                <td>
                    <div style="margin-bottom:8px"><?= personChipHtml($r['t_id'], $r['t_name'], $r['t_photo'], 32, 'Guest') ?></div>
                    <div><?= personChipHtml($r['h_id'], $r['h_name'], $r['h_photo'], 32, 'Host') ?></div>
                </td>
                <td><span class="pill" style="background:<?= $bb[0] ?>;color:<?= $bb[1] ?>"><?= h($bb[2]) ?></span></td>
                <td>
                    <span class="pill" style="background:<?= $pl[0] ?>;color:<?= $pl[1] ?>"><?= h($pl[2]) ?></span>
                    <?php if ($r['payment_id']): ?>
                        <div style="font-size:12px;color:#777;margin-top:6px">KES <?= number_format($r['paid_amount']) ?> &middot; <?= h($r['payment_method'] ?: 'method not given') ?><br>Ref: <?= h($r['payment_reference'] ?: 'none') ?></div>
                        <a href="payments.php?filter=all&q=<?= (int)$r['booking_id'] ?>" style="font-size:12px">Open in Payment tracking &rarr;</a>
                    <?php endif; ?>
                    <?php if ($r['flag'] !== ''): $n = $notes[$r['flag']]; ?>
                        <div class="note" style="background:<?= $n[0] ?>;color:<?= $n[1] ?>"><?= h($n[2]) ?><?= !empty($r['early_note']) && $r['flag'] === 'early_completed' ? '<br><strong>Host\'s reason:</strong> ' . h($r['early_note']) : '' ?></div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
<?php appShellEnd(); ?>
</body>
</html>
