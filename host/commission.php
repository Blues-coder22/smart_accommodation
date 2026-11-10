<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('host');
$host_id = (int)$_SESSION['user_id'];
ensureCommissionTables($pdo);
reconcileCommissionLedger($pdo);

// The host reports that they have paid some commission: choose the bookings, give the reference, attach a screenshot.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remit') {
    requireCsrf();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ledger'] ?? [])))));
    $ref = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 120);
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
    $proof = savePrivateProofImage('proof', 'commission');
    $rows = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT ledger_id, commission_amount + service_fee AS owed FROM commission_ledger WHERE host_id = ? AND status = 'accrued' AND ledger_id IN ($in)");
        $q->execute(array_merge([$host_id], $ids));
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$rows || count($rows) !== count($ids)) {
        setFlash('error', 'Please tick the bookings you have paid commission for.');
    } elseif (mb_strlen($ref) < 4) {
        setFlash('error', 'Please enter the M-Pesa or bank reference (at least 4 characters).');
    } elseif ($proof === false) {
        setFlash('error', 'The screenshot could not be used. Send a JPG, PNG or WebP image up to 5 MB.');
    } else {
        $total = array_sum(array_map(fn($r) => (float)$r['owed'], $rows));
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO commission_remittances (host_id, amount, reference, note, proof_file) VALUES (?,?,?,?,?)")
                ->execute([$host_id, $total, $ref, $note !== '' ? $note : null, $proof]);
            $rid = (int)$pdo->lastInsertId();
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE commission_ledger SET status = 'submitted', remittance_id = ? WHERE host_id = ? AND status = 'accrued' AND ledger_id IN ($in)")
                ->execute(array_merge([$rid, $host_id], $ids));
            $pdo->commit();
            try {
                require_once '../includes/email.php'; require_once '../includes/booking_holds.php';
                foreach (adminEmailAddresses($pdo) as $to) sendCommissionRemittanceAdminEmail($to, $_SESSION['full_name'] ?? 'Host', $total, $ref);
            } catch (Throwable $e) { error_log('Remittance email: ' . $e->getMessage()); }
            setFlash('success', 'Thank you. The administrator will check your payment of ' . kes($total) . ' and confirm it.');
        } catch (Throwable $e) { $pdo->rollBack(); error_log('Remittance: ' . $e->getMessage()); setFlash('error', 'Something went wrong. Please try again.'); }
    }
    redirect('commission.php');
}

$sum = commissionSummary($pdo, $host_id);
$ledger = $pdo->prepare("SELECT l.*, a.title, b.check_in_date, b.check_out_date FROM commission_ledger l
    JOIN bookings b ON b.booking_id = l.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    WHERE l.host_id = ? AND l.status <> 'void' ORDER BY l.accrued_at DESC LIMIT 200");
$ledger->execute([$host_id]); $ledger = $ledger->fetchAll(PDO::FETCH_ASSOC);
$rem = $pdo->prepare("SELECT * FROM commission_remittances WHERE host_id = ? ORDER BY created_at DESC LIMIT 30");
$rem->execute([$host_id]); $rem = $rem->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commission — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/finance.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <?= showFlash() ?>
        <div class="page-tag">Hosting</div>
        <h1 class="page-title">Commission</h1>
        <p class="page-sub">Guests pay you directly. When you confirm a payment, the platform earns <?= rtrim(rtrim(number_format((float)COMMISSION_RATE_PERCENT, 2), '0'), '.') ?>% commission on your price, plus the service fee the guest paid with the booking. You received both from the guest, so you then send them to StayExplore Kenya.</p>

        <div class="fin-cards">
            <div class="fin-card"><div class="fin-label">Owed to the platform</div><div class="fin-value"><?= kes($sum['earned']) ?></div><div class="fin-note"><?= (int)$sum['bookings'] ?> paid booking(s)</div></div>
            <div class="fin-card warn"><div class="fin-label">You still owe</div><div class="fin-value"><?= kes($sum['outstanding']) ?></div><div class="fin-note">not yet reported</div></div>
            <div class="fin-card"><div class="fin-label">Awaiting admin check</div><div class="fin-value"><?= kes($sum['awaiting']) ?></div><div class="fin-note">you reported it</div></div>
            <div class="fin-card accent"><div class="fin-label">Paid and received</div><div class="fin-value"><?= kes($sum['received']) ?></div><div class="fin-note">confirmed by admin</div></div>
        </div>

        <form method="POST" enctype="multipart/form-data" class="fin-box">
            <h2>Report a commission payment</h2>
            <p class="small"><?= h(COMMISSION_PAY_INSTRUCTIONS) ?></p>
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="remit">
            <div class="fin-scroll"><table class="fin-table">
                <thead><tr><th><span class="sr-only">Select</span></th><th>Booking</th><th>Stay</th><th class="num">Guest paid</th><th class="num">Commission</th><th class="num">Service fee</th><th class="num">You owe</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (!$ledger): ?><tr><td colspan="8">No commission yet. It appears here after you confirm a guest's payment.</td></tr><?php endif; ?>
                <?php foreach ($ledger as $r): ?>
                    <tr>
                        <td><?php if ($r['status'] === 'accrued'): ?><input type="checkbox" name="ledger[]" value="<?= (int)$r['ledger_id'] ?>" aria-label="Pay commission for booking #BK<?= str_pad($r['booking_id'], 5, '0', STR_PAD_LEFT) ?>"><?php endif; ?></td>
                        <td>#BK<?= str_pad($r['booking_id'], 5, '0', STR_PAD_LEFT) ?><br><span class="small"><?= h($r['check_in_date']) ?> to <?= h($r['check_out_date']) ?></span></td>
                        <td><?= h($r['title']) ?></td>
                        <td class="num"><?= kes($r['booking_amount']) ?></td>
                        <td class="num"><?= kes($r['commission_amount']) ?> <span class="small">(<?= rtrim(rtrim(number_format($r['rate_percent'], 2), '0'), '.') ?>%)</span></td>
                        <td class="num"><?= kes($r['service_fee']) ?></td>
                        <td class="num"><strong><?= kes($r['commission_amount'] + $r['service_fee']) ?></strong></td>
                        <td><span class="fin-pill <?= h($r['status']) ?>"><?= h(['accrued' => 'You owe this', 'submitted' => 'Awaiting admin', 'settled' => 'Received'][$r['status']] ?? $r['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <label class="fin-field" for="reference">M-Pesa or bank reference</label>
            <input class="fin-input" id="reference" name="reference" maxlength="120" required>
            <label class="fin-field" for="proof">Screenshot or receipt (optional, JPG, PNG or WebP, up to 5 MB)</label>
            <input id="proof" type="file" name="proof" accept="image/jpeg,image/png,image/webp">
            <label class="fin-field" for="note">Note for the administrator (optional)</label>
            <textarea class="fin-input" id="note" name="note" rows="2" maxlength="500"></textarea>
            <div class="fin-actions"><button class="fin-btn" type="submit">Send to administrator</button></div>
        </form>

        <div class="fin-box">
            <h2>Your commission payments</h2>
            <div class="fin-scroll"><table class="fin-table">
                <thead><tr><th>Date</th><th>Reference</th><th class="num">Amount</th><th>Status</th><th>Proof</th></tr></thead>
                <tbody>
                <?php if (!$rem): ?><tr><td colspan="5">You have not reported any commission payment yet.</td></tr><?php endif; ?>
                <?php foreach ($rem as $r): ?>
                    <tr>
                        <td><?= h(date('j M Y', strtotime($r['created_at']))) ?></td>
                        <td><?= h($r['reference']) ?></td>
                        <td class="num"><?= kes($r['amount']) ?></td>
                        <td><span class="fin-pill <?= h($r['status']) ?>"><?= h(ucfirst($r['status'])) ?></span><?= $r['review_note'] ? '<div class="small">' . h($r['review_note']) . '</div>' : '' ?></td>
                        <td><?php if ($r['proof_file']): ?><a href="../proof.php?type=commission&amp;id=<?= (int)$r['remittance_id'] ?>" target="_blank" rel="noopener">View</a><?php else: ?>—<?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
<?php appShellEnd(); ?>
</body>
</html>
