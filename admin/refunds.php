<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/notifications.php';
require_once '../includes/refund_requests.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');
$admin_id = (int)$_SESSION['user_id'];
ensureRefundRequestTables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'admin_refund') {
        // The admin records a refund (for example when the host will not, or the guest was paid some other way), with evidence.
        $bid = bookingIdFromRef($_POST['booking_ref'] ?? '');
        $ev = refundSaveEvidence($_FILES['evidence'] ?? null);
        $res = $ev['ok'] ? refundRecordDone($pdo, $bid, 'admin', $admin_id, str_replace(',', '.', (string)($_POST['amount'] ?? '')), (string)($_POST['method'] ?? ''), (string)($_POST['reference'] ?? ''), (string)($_POST['note'] ?? ''), $ev['name']) : $ev;
        setFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Refund recorded. The guest and the host were told.' : $res['error']);
    } elseif ($act === 'admin_close') {
        $res = refundAdminClose($pdo, (int)($_POST['request_id'] ?? 0), $admin_id, (string)($_POST['note'] ?? ''));
        setFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Case closed. Both sides were told your reason.' : $res['error']);
    }
    redirect('refunds.php' . (!empty($_GET['status']) ? '?status=' . urlencode((string)$_GET['status']) : ''));
}

$filter = in_array($_GET['status'] ?? '', ['requested', 'escalated', 'declined', 'refunded', 'closed'], true) ? $_GET['status'] : '';
$where = $filter !== '' ? 'WHERE r.status = ' . $pdo->quote($filter) : '';
$cases = $pdo->query("SELECT r.*, t.full_name AS guest_name, h.full_name AS host_name, a.title
    FROM refund_requests r
    JOIN users t ON t.user_id = r.tourist_id JOIN users h ON h.user_id = r.host_id
    JOIN bookings b ON b.booking_id = r.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    $where ORDER BY FIELD(r.status,'escalated','requested','declined','refunded','closed'), r.updated_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$counts = [];
foreach ($pdo->query("SELECT status, COUNT(*) n FROM refund_requests GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $c) $counts[$c['status']] = (int)$c['n'];

// Cancelled bookings where the guest paid, but nobody has opened a refund case yet.
$open = $pdo->query("SELECT b.booking_id, b.total_amount, a.title, t.full_name AS guest_name, h.full_name AS host_name,
        (SELECT p.amount FROM payments p WHERE p.booking_id = b.booking_id AND p.payment_status IN ('confirmed','pending') ORDER BY (p.payment_status='confirmed') DESC, p.payment_id DESC LIMIT 1) AS paid
    FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users t ON t.user_id = b.tourist_id JOIN users h ON h.user_id = a.host_id
    WHERE b.status = 'cancelled' AND NOT EXISTS (SELECT 1 FROM refund_requests r WHERE r.booking_id = b.booking_id)
      AND EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.booking_id AND p.payment_status IN ('confirmed','pending'))
    ORDER BY b.booking_id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

function adminRefundForm($bookingId, $max, $default) { ?>
    <details style="margin-top:8px"><summary class="fin-btn" style="display:inline-block;cursor:pointer">Record a refund</summary>
        <form method="POST" enctype="multipart/form-data" style="display:grid;gap:8px;max-width:420px;margin-top:8px">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="admin_refund">
            <input type="hidden" name="booking_ref" value="<?= h(bookingRef($bookingId)) ?>">
            <label>Amount (KES), up to <?= number_format($max, 2) ?> <input class="fin-input" name="amount" inputmode="decimal" required value="<?= h(number_format($default, 2, '.', '')) ?>"></label>
            <label>How it was sent <select class="fin-input" name="method" required><option value="mpesa">M-Pesa</option><option value="bank_transfer">Bank transfer</option><option value="cash">Cash</option></select></label>
            <label>Transaction reference (not needed for cash) <input class="fin-input" name="reference" maxlength="120"></label>
            <label>Evidence: screenshot or receipt (not needed for cash) <input type="file" name="evidence" accept="image/jpeg,image/png,image/webp"></label>
            <label>Note (the guest and host can read it) <input class="fin-input" name="note" maxlength="300"></label>
            <button class="fin-btn" type="submit">Save refund record</button>
        </form>
    </details>
<?php }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refunds — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <link rel="stylesheet" href="../assets/css/finance.css">
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <?= showFlash() ?>
        <div class="page-tag">Admin</div>
        <h1 class="page-title">Refunds for cancelled bookings</h1>
        <p class="small">Guests ask for refunds here with evidence of what they paid; hosts record the refund with a screenshot, or decline with a reason. You can step in on any case. StayExplore does not move the host&rsquo;s money: these are records with proof. Refunds of the platform&rsquo;s own share are on the <a href="revenue.php">Revenue</a> page. Every step is in the finance audit trail there.</p>
        <p class="small">Show: <a href="refunds.php">All (<?= array_sum($counts) ?>)</a>
            <?php foreach (['escalated' => 'With you', 'requested' => 'Waiting for host', 'declined' => 'Declined', 'refunded' => 'Refunded', 'closed' => 'Closed'] as $k => $lbl): ?> &middot; <a href="?status=<?= $k ?>"><?= $lbl ?> (<?= (int)($counts[$k] ?? 0) ?>)</a><?php endforeach; ?></p>

        <?php if (!$cases): ?><div class="fin-box"><p>No refund cases<?= $filter ? ' with this status' : ' yet' ?>.</p></div><?php endif; ?>
        <?php foreach ($cases as $c): $tok = opaqueId('rfd', (int)$c['request_id']); ?>
        <div class="fin-box" style="margin-bottom:12px">
            <strong><?= h(bookingLabel($c['booking_id'])) ?></strong> &middot; <?= h($c['title']) ?> &middot; <span class="fin-pill <?= $c['status'] === 'refunded' ? 'settled' : ($c['status'] === 'escalated' ? 'bad' : 'pending') ?>"><?= h(refundStatusLabel($c['status'])) ?></span>
            <p class="small" style="margin:6px 0">Guest <strong><?= h($c['guest_name']) ?></strong> paid KES <?= number_format((float)$c['amount_paid'], 2) ?> to host <strong><?= h($c['host_name']) ?></strong> and asks for KES <?= number_format((float)$c['amount_requested'], 2) ?>.
                Reason: &ldquo;<?= h($c['reason']) ?>&rdquo;
                <?php if ($c['evidence_file']): ?> &middot; <a href="../refund_proof.php?ref=<?= h($tok) ?>&amp;file=request" target="_blank" rel="noopener">Guest&rsquo;s evidence</a><?php else: ?> &middot; no guest evidence<?php endif; ?></p>
            <?php if ($c['host_response']): ?><p class="small" style="margin:4px 0">Host declined: <?= h($c['host_response']) ?></p><?php endif; ?>
            <?php if ($c['escalation_note']): ?><p class="small" style="margin:4px 0"><strong>Guest to you:</strong> <?= h($c['escalation_note']) ?></p><?php endif; ?>
            <?php if ($c['status'] === 'refunded'): ?>
                <p class="small" style="margin:4px 0">&#10003; KES <?= number_format((float)$c['refund_amount'], 2) ?> by <?= h(refundMethodLabel($c['refund_method'])) ?><?= $c['refund_reference'] ? ', ref ' . h($c['refund_reference']) : '' ?>, recorded by the <?= h($c['refunded_by']) ?> on <?= h(date('j M Y, H:i', strtotime($c['refunded_at']))) ?>
                    <?php if ($c['refund_evidence']): ?> &middot; <a href="../refund_proof.php?ref=<?= h($tok) ?>&amp;file=refund" target="_blank" rel="noopener">Refund evidence</a><?php else: ?> &middot; no evidence attached<?php endif; ?>
                    <?php if ($c['refund_note']): ?> &middot; <?= h($c['refund_note']) ?><?php endif; ?></p>
            <?php endif; ?>
            <?php if ($c['admin_note']): ?><p class="small" style="margin:4px 0">Admin note: <?= h($c['admin_note']) ?></p><?php endif; ?>
            <?php if (!in_array($c['status'], ['refunded', 'closed'], true)): ?>
                <?php adminRefundForm((int)$c['booking_id'], (float)$c['amount_paid'], (float)$c['amount_requested']); ?>
                <details style="margin-top:8px"><summary class="fin-btn ghost" style="display:inline-block;cursor:pointer">Close without a refund</summary>
                    <form method="POST" style="display:grid;gap:8px;max-width:420px;margin-top:8px">
                        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="admin_close"><input type="hidden" name="request_id" value="<?= (int)$c['request_id'] ?>">
                        <label>Reason (the guest and host will read it) <input class="fin-input" name="note" maxlength="300" required minlength="5"></label>
                        <button class="fin-btn danger" type="submit">Close case</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if ($open && !$filter): ?>
        <h2 style="margin-top:28px">Cancelled and paid, but no refund asked for yet</h2>
        <p class="small">If a host or guest has told you about one of these outside the app, you can record the refund here with its evidence.</p>
        <?php foreach ($open as $o): ?>
        <div class="fin-box" style="margin-bottom:10px">
            <strong><?= h(bookingLabel($o['booking_id'])) ?></strong> &middot; <?= h($o['title']) ?> &middot; guest <?= h($o['guest_name']) ?>, host <?= h($o['host_name']) ?> &middot; paid KES <?= number_format((float)$o['paid'], 2) ?>
            <?php adminRefundForm((int)$o['booking_id'], (float)$o['paid'], (float)$o['paid']); ?>
        </div>
        <?php endforeach; endif; ?>
<?php appShellEnd(); ?>
</body>
</html>
