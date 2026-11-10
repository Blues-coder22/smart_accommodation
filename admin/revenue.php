<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');
$admin_id = (int)$_SESSION['user_id'];
ensureCommissionTables($pdo);
reconcileCommissionLedger($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = (string)($_POST['action'] ?? '');
    $rid = (int)($_POST['remittance_id'] ?? 0);
    if ($act === 'accept' || $act === 'reject') {
        $st = $pdo->prepare("SELECT r.*, u.email, u.full_name FROM commission_remittances r JOIN users u ON u.user_id = r.host_id WHERE r.remittance_id = ? AND r.status = 'pending'");
        $st->execute([$rid]); $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $note = mb_substr(trim((string)($_POST['review_note'] ?? '')), 0, 300);
            $pdo->beginTransaction();
            try {
                if ($act === 'accept') {
                    $pdo->prepare("UPDATE commission_ledger SET status='settled', settled_at=NOW(), settled_by=?, settle_ref=? WHERE remittance_id=? AND status='submitted'")->execute([$admin_id, $r['reference'], $rid]);
                } else {
                    $pdo->prepare("UPDATE commission_ledger SET status='accrued', remittance_id=NULL WHERE remittance_id=? AND status='submitted'")->execute([$rid]);
                }
                $pdo->prepare("UPDATE commission_remittances SET status=?, reviewed_at=NOW(), reviewed_by=?, review_note=? WHERE remittance_id=?")
                    ->execute([$act === 'accept' ? 'accepted' : 'rejected', $admin_id, $note !== '' ? $note : null, $rid]);
                $pdo->commit();
                try { require_once '../includes/email.php'; sendCommissionDecisionHostEmail($r['email'], $r['full_name'], $r['amount'], $r['reference'], $act === 'accept', $note); } catch (Throwable $e) {}
                setFlash('success', $act === 'accept' ? 'Commission marked as received.' : 'Commission payment rejected. The host has been told.');
            } catch (Throwable $e) { $pdo->rollBack(); error_log('Remittance review: ' . $e->getMessage()); setFlash('error', 'Could not save the decision.'); }
        }
    } elseif ($act === 'mark_received') {   // paid outside the site (e.g. cash): the admin records it directly
        $lid = (int)($_POST['ledger_id'] ?? 0); $ref = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 120);
        if (mb_strlen($ref) < 3) { setFlash('error', 'Enter a reference for this payment (at least 3 characters).'); }
        else {
            $u = $pdo->prepare("UPDATE commission_ledger SET status='settled', settled_at=NOW(), settled_by=?, settle_ref=? WHERE ledger_id=? AND status='accrued'");
            $u->execute([$admin_id, $ref, $lid]);
            setFlash($u->rowCount() ? 'success' : 'error', $u->rowCount() ? 'Marked as received.' : 'That commission is not outstanding.');
        }
    }
    redirect('revenue.php');
}

$sum = commissionSummary($pdo);
$perHost = $pdo->query("SELECT u.user_id, u.full_name, COUNT(*) AS bookings, SUM(l.booking_amount) AS value, SUM(l.commission_amount + l.service_fee) AS earned,
        SUM(CASE WHEN l.status='settled' THEN l.commission_amount + l.service_fee ELSE 0 END) AS received,
        SUM(CASE WHEN l.status='submitted' THEN l.commission_amount + l.service_fee ELSE 0 END) AS awaiting,
        SUM(CASE WHEN l.status='accrued' THEN l.commission_amount + l.service_fee ELSE 0 END) AS outstanding
    FROM commission_ledger l JOIN users u ON u.user_id = l.host_id WHERE l.status <> 'void' GROUP BY u.user_id, u.full_name ORDER BY outstanding DESC, earned DESC")->fetchAll(PDO::FETCH_ASSOC);
$pending = $pdo->query("SELECT r.*, u.full_name FROM commission_remittances r JOIN users u ON u.user_id = r.host_id WHERE r.status = 'pending' ORDER BY r.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
$filter = in_array($_GET['status'] ?? '', ['accrued', 'submitted', 'settled', 'void'], true) ? $_GET['status'] : '';
$lq = $pdo->prepare("SELECT l.*, u.full_name AS host_name, a.title FROM commission_ledger l JOIN users u ON u.user_id = l.host_id
    JOIN bookings b ON b.booking_id = l.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    " . ($filter ? "WHERE l.status = ?" : "") . " ORDER BY l.accrued_at DESC LIMIT 150");
$lq->execute($filter ? [$filter] : []); $ledger = $lq->fetchAll(PDO::FETCH_ASSOC);
$rate = rtrim(rtrim(number_format((float)COMMISSION_RATE_PERCENT, 2), '0'), '.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue and commission — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/finance.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <?= showFlash() ?>
        <div class="page-tag">Admin</div>
        <h1 class="page-title">Revenue and commission</h1>
        <p class="page-sub">Guests pay hosts directly. The platform earns <?= h($rate) ?>% commission on the host's price, plus the guest service fee (<?= h(rtrim(rtrim(number_format((float)SERVICE_FEE_PERCENT, 2), '0'), '.')) ?>%), on every booking whose payment the host has confirmed. <strong>Earned</strong> is what the platform is owed; <strong>received</strong> is only what a host has actually paid and you have confirmed. Change the rates in <code>includes/config.php</code>; past bookings keep the rates they were created with.</p>

        <div class="fin-cards">
            <div class="fin-card"><div class="fin-label">Paid bookings</div><div class="fin-value"><?= (int)$sum['bookings'] ?></div><div class="fin-note">value <?= kes($sum['booking_value']) ?></div></div>
            <div class="fin-card"><div class="fin-label">Platform earned</div><div class="fin-value"><?= kes($sum['earned']) ?></div><div class="fin-note">commission <?= kes($sum['earned_commission']) ?> + fees <?= kes($sum['earned_fee']) ?></div></div>
            <div class="fin-card accent"><div class="fin-label">Received</div><div class="fin-value"><?= kes($sum['received']) ?></div><div class="fin-note">money actually in</div></div>
            <div class="fin-card warn"><div class="fin-label">Outstanding</div><div class="fin-value"><?= kes($sum['outstanding']) ?></div><div class="fin-note">hosts have not reported it</div></div>
            <div class="fin-card"><div class="fin-label">Awaiting your check</div><div class="fin-value"><?= kes($sum['awaiting']) ?></div><div class="fin-note"><?= count($pending) ?> report(s) from hosts</div></div>
        </div>

        <?php if ($pending): ?>
        <div class="fin-box">
            <h2>Host commission payments to check</h2>
            <p class="small">Compare the reference and amount with your M-Pesa or bank statement before you accept.</p>
            <?php foreach ($pending as $r): ?>
                <form method="POST" class="fin-box" style="margin-bottom:10px">
                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                    <input type="hidden" name="remittance_id" value="<?= (int)$r['remittance_id'] ?>">
                    <strong><?= h($r['full_name']) ?></strong> reports <strong><?= kes($r['amount']) ?></strong> · reference <strong><?= h($r['reference']) ?></strong> · <?= h(date('j M Y, H:i', strtotime($r['created_at']))) ?>
                    <?php if ($r['proof_file']): ?> · <a href="../proof.php?type=commission&amp;id=<?= (int)$r['remittance_id'] ?>" target="_blank" rel="noopener">View screenshot</a><?php endif; ?>
                    <?php if ($r['note']): ?><div class="small">Host note: <?= h($r['note']) ?></div><?php endif; ?>
                    <label class="fin-field" for="rn<?= (int)$r['remittance_id'] ?>">Note to the host (optional, sent by email)</label>
                    <input class="fin-input" id="rn<?= (int)$r['remittance_id'] ?>" name="review_note" maxlength="300">
                    <div class="fin-actions">
                        <button class="fin-btn" name="action" value="accept" type="submit">Accept, money received</button>
                        <button class="fin-btn danger" name="action" value="reject" type="submit">Reject</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="fin-box">
            <h2>By host</h2>
            <div class="fin-scroll"><table class="fin-table">
                <thead><tr><th>Host</th><th class="num">Paid bookings</th><th class="num">Booking value</th><th class="num">Earned</th><th class="num">Received</th><th class="num">Awaiting</th><th class="num">Outstanding</th></tr></thead>
                <tbody>
                <?php if (!$perHost): ?><tr><td colspan="7">No confirmed payments yet.</td></tr><?php endif; ?>
                <?php foreach ($perHost as $h): ?>
                    <tr><td><?= h($h['full_name']) ?></td><td class="num"><?= (int)$h['bookings'] ?></td><td class="num"><?= kes($h['value']) ?></td><td class="num"><?= kes($h['earned']) ?></td><td class="num"><?= kes($h['received']) ?></td><td class="num"><?= kes($h['awaiting']) ?></td><td class="num"><strong><?= kes($h['outstanding']) ?></strong></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>

        <div class="fin-box">
            <h2>Commission ledger</h2>
            <p class="small">Show:
                <a href="revenue.php">All</a> ·
                <a href="?status=accrued">Outstanding</a> ·
                <a href="?status=submitted">Awaiting check</a> ·
                <a href="?status=settled">Received</a> ·
                <a href="?status=void">Void (payment reversed or booking cancelled)</a></p>
            <div class="fin-scroll"><table class="fin-table">
                <thead><tr><th>Booking</th><th>Host</th><th>Stay</th><th class="num">Guest paid</th><th class="num">Commission</th><th class="num">Service fee</th><th>Status</th><th>Evidence</th></tr></thead>
                <tbody>
                <?php if (!$ledger): ?><tr><td colspan="8">Nothing to show.</td></tr><?php endif; ?>
                <?php foreach ($ledger as $r): ?>
                    <tr>
                        <td>#BK<?= str_pad($r['booking_id'], 5, '0', STR_PAD_LEFT) ?></td>
                        <td><?= h($r['host_name']) ?></td>
                        <td><?= h($r['title']) ?></td>
                        <td class="num"><?= kes($r['booking_amount']) ?></td>
                        <td class="num"><?= kes($r['commission_amount']) ?> <span class="small">(<?= rtrim(rtrim(number_format($r['rate_percent'], 2), '0'), '.') ?>%)</span></td>
                        <td class="num"><?= kes($r['service_fee']) ?></td>
                        <td><span class="fin-pill <?= h($r['status']) ?>"><?= h(['accrued' => 'Outstanding', 'submitted' => 'Awaiting check', 'settled' => 'Received', 'void' => 'Void'][$r['status']]) ?></span>
                            <?= $r['settle_ref'] ? '<div class="small">Ref: ' . h($r['settle_ref']) . '</div>' : '' ?>
                            <?= $r['flag_note'] ? '<div class="fin-warn">' . h($r['flag_note']) . '</div>' : '' ?>
                            <?php if ($r['status'] === 'accrued'): ?>
                            <form method="POST" class="fin-actions no-print" onsubmit="var r=prompt('Reference for this payment (e.g. M-Pesa code, or CASH):'); if(!r||r.trim().length<3){return false;} this.reference.value=r; return true;">
                                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="mark_received"><input type="hidden" name="ledger_id" value="<?= (int)$r['ledger_id'] ?>"><input type="hidden" name="reference" value="">
                                <button class="fin-btn ghost" type="submit">Mark received</button>
                            </form>
                            <?php endif; ?></td>
                        <td><a href="evidence.php?booking_id=<?= (int)$r['booking_id'] ?>">Evidence pack</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
<?php appShellEnd(); ?>
</body>
</html>
