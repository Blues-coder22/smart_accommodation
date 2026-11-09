<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/payment_audit.php';
require_once '../includes/review_extras.php';   // person chips (photo + name + profile link)

if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');
ensurePaymentAuditTable($pdo);

$admin_id = (int)$_SESSION['user_id'];

// ---- Admin decision on one payment (always needs a short written reason) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $pid    = (int)($_POST['payment_id'] ?? 0);
    $new    = $_POST['new_status'] ?? '';
    $note   = trim((string)($_POST['note'] ?? ''));
    $q = $pdo->prepare("SELECT p.*, a.title, t.email AS t_email, t.full_name AS t_name, h.email AS h_email, h.full_name AS h_name
        FROM payments p JOIN bookings b ON b.booking_id = p.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id
        JOIN users t ON t.user_id = p.tourist_id JOIN users h ON h.user_id = p.host_id WHERE p.payment_id = ?");
    $q->execute([$pid]);
    $p = $q->fetch(PDO::FETCH_ASSOC);

    if (!$p || !in_array($new, ['confirmed', 'pending', 'failed'], true)) {
        setFlash('error', 'That payment could not be found.');
    } elseif (mb_strlen($note) < 5) {
        setFlash('error', 'Please write a short reason (at least 5 characters) so the history makes sense.');
    } elseif ($p['payment_status'] === $new) {
        setFlash('warning', 'The payment already has that status.');
    } else {
        $pdo->prepare("UPDATE payments SET payment_status = ? WHERE payment_id = ?")->execute([$new, $pid]);
        $action = $new === 'confirmed' ? 'admin_confirmed' : ($new === 'failed' ? 'admin_failed' : 'admin_pending');
        logPaymentChange($pdo, $pid, $admin_id, 'admin', $action, $p['payment_status'], $new, $note);
        // Tell both people so nobody is surprised
        try {
            require_once '../includes/email.php';
            $label = ['confirmed' => 'received (confirmed)', 'pending' => 'awaiting payment', 'failed' => 'failed'][$new];
            $body = '<p>An administrator updated the payment for <strong>' . h($p['title']) . '</strong> (booking #' . (int)$p['booking_id']
                  . ') to <strong>' . h($label) . '</strong>.</p><p>Reason given: ' . h($note) . '</p>';
            sendEmail($p['t_email'], 'Payment status updated — StayExplore Kenya', $body);
            sendEmail($p['h_email'], 'Payment status updated — StayExplore Kenya', $body);
        } catch (Throwable $e) {}
        setFlash('success', 'Payment updated and both people were told.' . ($new === 'confirmed' ? ' The host can no longer set it back to awaiting.' : ''));
    }
    redirect('payments.php?filter=' . urlencode(sanitize($_GET['filter'] ?? 'all')));
}

$filter = sanitize($_GET['filter'] ?? 'all');
$search = trim((string)($_GET['q'] ?? ''));

$where = '1=1'; $params = [];
switch ($filter) {
    case 'pending':   $where .= " AND p.payment_status = 'pending'"; break;
    case 'confirmed': $where .= " AND p.payment_status = 'confirmed'"; break;
    case 'failed':    $where .= " AND p.payment_status = 'failed'"; break;
    case 'reversed':  $where .= " AND p.payment_status = 'pending' AND EXISTS (SELECT 1 FROM payment_audit x WHERE x.payment_id = p.payment_id AND x.action = 'host_unpaid')"; break;
}
if ($search !== '') {
    $where .= " AND (p.payment_reference LIKE :s1 OR t.full_name LIKE :s2 OR h.full_name LIKE :s3 OR a.title LIKE :s4 OR p.booking_id = :sid)";
    $params[':s1'] = $params[':s2'] = $params[':s3'] = $params[':s4'] = "%$search%";
    $params[':sid'] = (int)$search;
}

$stmt = $pdo->prepare("
    SELECT p.*, a.title, b.check_in_date, b.check_out_date, b.status AS booking_status,
           t.user_id AS t_id, t.full_name AS t_name, t.profile_photo AS t_photo,
           h.user_id AS h_id, h.full_name AS h_name, h.profile_photo AS h_photo,
           (SELECT COUNT(*) FROM payment_audit x WHERE x.payment_id = p.payment_id AND x.action = 'host_unpaid') AS reversals
    FROM payments p
    JOIN bookings b ON b.booking_id = p.booking_id
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users t ON t.user_id = p.tourist_id
    JOIN users h ON h.user_id = p.host_id
    WHERE $where ORDER BY p.payment_date DESC LIMIT 200");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$history = [];
if ($rows) {
    $ids = implode(',', array_map('intval', array_column($rows, 'payment_id')));
    foreach ($pdo->query("SELECT au.*, u.full_name FROM payment_audit au LEFT JOIN users u ON u.user_id = au.actor_id WHERE au.payment_id IN ($ids) ORDER BY au.audit_id DESC")->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $history[(int)$a['payment_id']][] = $a;
    }
}

$sum = $pdo->query("SELECT
    COALESCE(SUM(CASE WHEN payment_status='confirmed' THEN amount END),0) AS confirmed_total,
    COALESCE(SUM(CASE WHEN payment_status='pending' THEN amount END),0) AS pending_total,
    COUNT(CASE WHEN payment_status='failed' THEN 1 END) AS failed_count
    FROM payments")->fetch(PDO::FETCH_ASSOC);
$reversedCount = (int)$pdo->query("SELECT COUNT(DISTINCT p.payment_id) FROM payments p JOIN payment_audit x ON x.payment_id = p.payment_id
    WHERE p.payment_status = 'pending' AND x.action = 'host_unpaid'")->fetchColumn();

$actionText = ['host_confirmed' => 'Host marked as received', 'host_unpaid' => 'Host set back to awaiting', 'admin_confirmed' => 'Administrator confirmed',
               'admin_pending' => 'Administrator set to awaiting', 'admin_failed' => 'Administrator marked failed'];
$badge = ['confirmed' => ['#e7f3ec', '#1f5a3f', 'Received'], 'pending' => ['#fff6dc', '#7a5a00', 'Awaiting'], 'failed' => ['#fdecea', '#8a2b22', 'Failed']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payment tracking — Admin</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.pay-table{width:100%;border-collapse:collapse;font-size:13px}
.pay-table th{text-align:left;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#565c55;padding:10px 12px;border-bottom:1px solid #e4e1d8;white-space:nowrap}
.pay-table td{padding:12px;border-bottom:1px solid #eee;vertical-align:top}
.pill{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}
.flag{background:#fff4ef;border:1px solid #e2a58f;color:#8a3b1c;border-radius:8px;padding:6px 10px;font-size:12px;margin-top:6px}
.hist{margin-top:8px;font-size:12px;color:#4a5249}
.hist li{margin:3px 0}
.decide{display:grid;gap:6px;min-width:210px}
.decide input[type=text]{padding:7px 9px;border:1px solid #d8d4c8;border-radius:6px;font-size:12px}
.decide .row{display:flex;gap:6px;flex-wrap:wrap}
.decide button{padding:6px 10px;border-radius:6px;border:1px solid #0d3b2e;background:#0d3b2e;color:#fff;font-size:12px;cursor:pointer}
.decide button.alt{background:#fff;color:#0d3b2e}
.decide button.bad{background:#fff;color:#8a2b22;border-color:#e0a39b}
.help{background:#fff;border:1px solid #e4e1d8;border-radius:10px;padding:12px 16px;font-size:13px;color:#4a5249;line-height:1.6;margin-bottom:16px}
</style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <div class="page-date"><?= strtoupper(date('l, j F')) ?></div>
        <h1 class="page-title">Payment tracking</h1>
        <p class="page-sub">Every payment on the platform, who changed its status, and when.</p>

        <?= showFlash() ?>

        <div class="stats-grid">
            <div class="stat-card accent"><div class="stat-label">Received</div><div class="stat-value">KES <?= number_format($sum['confirmed_total']) ?></div><div class="stat-note">Marked as paid</div></div>
            <div class="stat-card"><div class="stat-label">Awaiting</div><div class="stat-value">KES <?= number_format($sum['pending_total']) ?></div><div class="stat-note">Not yet marked as paid</div></div>
            <div class="stat-card <?= $reversedCount ? 'warn' : '' ?>"><div class="stat-label">Reversed by host</div><div class="stat-value"><?= $reversedCount ?></div><div class="stat-note <?= $reversedCount ? 'warn' : '' ?>"><?= $reversedCount ? 'Worth checking' : 'None' ?></div></div>
            <div class="stat-card"><div class="stat-label">Failed</div><div class="stat-value"><?= (int)$sum['failed_count'] ?></div><div class="stat-note">Payments</div></div>
        </div>

        <div class="help">
            <strong>When a host says &ldquo;unpaid&rdquo; but the guest says they paid.</strong>
            Open the payment below: you can see the method and reference the guest gave, and the full history of who changed the status. If you are satisfied the guest paid, choose <em>Confirm as received</em> and write a reason. Once an administrator confirms a payment the host can no longer set it back to awaiting. Both people are emailed about every change you make.
        </div>

        <div class="filter-tabs" style="margin-bottom:12px">
            <?php foreach (['all' => 'All', 'pending' => 'Awaiting', 'reversed' => 'Reversed by host', 'confirmed' => 'Received', 'failed' => 'Failed'] as $k => $lbl): ?>
                <a class="filter-tab <?= $filter === $k ? 'active' : '' ?>" href="?filter=<?= $k ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"><?= h($lbl) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap">
            <input type="hidden" name="filter" value="<?= h($filter) ?>">
            <input aria-label="Search a reference, name, listing or booking number" type="text" name="q" value="<?= h($search) ?>" placeholder="Search a reference, name, listing or booking number" style="flex:1;min-width:220px;padding:9px 12px;border:1px solid #d8d4c8;border-radius:8px">
            <button class="btn" type="submit">Search</button>
        </form>

        <div class="scroll-x">
        <table class="pay-table">
            <thead><tr><th>Payment</th><th>Guest and host</th><th>Amount</th><th>Status and history</th><th>Admin decision</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="5" style="text-align:center;color:#565c55;padding:30px">No payments found.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): $b = $badge[$r['payment_status']] ?? $badge['pending']; $hist = $history[(int)$r['payment_id']] ?? []; ?>
            <tr>
                <td>
                    <strong>Booking #<?= (int)$r['booking_id'] ?></strong><br>
                    <?= h($r['title']) ?><br>
                    <span style="color:#565c55"><?= date('d M', strtotime($r['check_in_date'])) ?> &ndash; <?= date('d M Y', strtotime($r['check_out_date'])) ?></span><br>
                    <span style="color:#565c55"><?= h($r['payment_method'] ?: 'Method not given') ?> &middot; Ref: <strong><?= h($r['payment_reference'] ?: 'none') ?></strong></span><br>
                    <span style="color:#565c55;font-size:11px">Submitted <?= date('d M Y, H:i', strtotime($r['payment_date'])) ?></span>
                </td>
                <td>
                    <div style="margin-bottom:8px"><?= personChipHtml($r['t_id'], $r['t_name'], $r['t_photo'], 32, 'Guest') ?></div>
                    <div><?= personChipHtml($r['h_id'], $r['h_name'], $r['h_photo'], 32, 'Host') ?></div>
                </td>
                <td style="white-space:nowrap"><strong>KES <?= number_format($r['amount']) ?></strong></td>
                <td>
                    <span class="pill" style="background:<?= $b[0] ?>;color:<?= $b[1] ?>"><?= $b[2] ?></span>
                    <?php if ($r['reversals'] > 0 && $r['payment_status'] === 'pending'): ?>
                        <div class="flag">The host marked this as received, then set it back to awaiting<?= $r['reversals'] > 1 ? ' (' . (int)$r['reversals'] . ' times)' : '' ?>. Check with both people.</div>
                    <?php endif; ?>
                    <?php if ($hist): ?>
                    <details class="hist"><summary style="cursor:pointer">History (<?= count($hist) ?>)</summary>
                        <ul style="padding-left:16px;margin:6px 0 0">
                        <?php foreach ($hist as $e): ?>
                            <li><strong><?= h($actionText[$e['action']] ?? $e['action']) ?></strong> by <?= h($e['full_name'] ?: $e['actor_role']) ?>, <?= date('d M Y H:i', strtotime($e['created_at'])) ?><?= $e['note'] ? ' &mdash; &ldquo;' . h($e['note']) . '&rdquo;' : '' ?></li>
                        <?php endforeach; ?>
                        </ul>
                    </details>
                    <?php else: ?><div class="hist" style="color:#565c55">No changes recorded yet.</div><?php endif; ?>
                </td>
                <td>
                    <form method="POST" class="decide" onsubmit="return this.note.value.trim().length >= 5 || (alert('Please write a short reason first.'), false);">
                        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="payment_id" value="<?= (int)$r['payment_id'] ?>">
                        <input aria-label="Reason (required)" type="text" name="note" maxlength="500" placeholder="Reason (required)">
                        <div class="row">
                            <?php if ($r['payment_status'] !== 'confirmed'): ?><button type="submit" name="new_status" value="confirmed">Confirm as received</button><?php endif; ?>
                            <?php if ($r['payment_status'] !== 'pending'): ?><button type="submit" name="new_status" value="pending" class="alt">Set to awaiting</button><?php endif; ?>
                            <?php if ($r['payment_status'] !== 'failed'): ?><button type="submit" name="new_status" value="failed" class="bad">Mark failed</button><?php endif; ?>
                        </div>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
<?php appShellEnd(); ?>
</body>
</html>
