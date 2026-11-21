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
require_once '../includes/notifications.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $led = hostCommissionLedger($pdo, $host_id);
    $amt = round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '')), 2);
    $method = (string)($_POST['method'] ?? ''); $ref = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 120);
    $room = $led['outstanding'] - $led['pending'];
    if (!in_array($method, ['mpesa', 'bank_transfer'], true) || mb_strlen($ref) < 4) { setFlash('error', 'Choose M-Pesa or bank transfer and enter the payment reference (at least 4 characters).'); }
    elseif ($amt <= 0 || $amt > max(0.0, $room) + 0.009) { setFlash('error', 'Enter an amount between 1 and the balance not yet reported (KES ' . number_format(max(0.0, $room), 2) . ').'); }
    else {
        $dup = $pdo->prepare("SELECT 1 FROM host_commission_payments WHERE host_id = ? AND reference = ? AND status <> 'rejected'"); $dup->execute([$host_id, $ref]);
        if ($dup->fetchColumn()) { setFlash('error', 'That reference has already been reported.'); }
        else {
            $pdo->prepare('INSERT INTO host_commission_payments (host_id, amount, method, reference) VALUES (?,?,?,?)')->execute([$host_id, $amt, $method, $ref]);
            notifyAdmins($pdo, 'Host commission payment to check', ($_SESSION['full_name'] ?? 'A host') . ' reports KES ' . number_format($amt, 2) . ' commission (ref ' . $ref . '). Compare it with your statement.', 'admin/revenue.php');
            setFlash('success', 'Reported. It will count once StayExplore Kenya has checked it.');
        }
    }
    redirect('commission.php');
}
$ledger = hostCommissionLedger($pdo, $host_id);
$mode = commissionMode();
$hasInvoice = $ledger['due'] > 0 || $ledger['pending'] > 0;
$hcp = $pdo->prepare('SELECT * FROM host_commission_payments WHERE host_id = ? ORDER BY created_at DESC LIMIT 50'); $hcp->execute([$host_id]); $myPayments = $hcp->fetchAll(PDO::FETCH_ASSOC);

$q = $pdo->prepare("SELECT b.booking_id, b.check_in_date, b.check_out_date, b.status, b.total_amount, b.service_fee, b.commission_amount, b.commission_rate,
        a.title, pp.status AS pp_status
    FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    LEFT JOIN platform_payments pp ON pp.booking_id = b.booking_id
    WHERE a.host_id = ? AND b.commission_amount IS NOT NULL AND b.commission_mode = 'upfront' AND b.status <> 'cancelled' ORDER BY b.created_at DESC LIMIT 200");
$q->execute([$host_id]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
$tPrice = $tComm = $tNet = 0.0;
foreach ($rows as &$r) {
    $r['price'] = (float)$r['total_amount'] - (float)$r['service_fee'];           // what the host charged
    $r['net']   = $r['price'] - (float)$r['commission_amount'];                    // what the guest pays the host
    $tPrice += $r['price']; $tComm += (float)$r['commission_amount']; $tNet += $r['net'];
}
unset($r);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commission — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/finance.css">
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <?= showFlash() ?>
        <div class="page-tag">Hosting</div>
        <h1 class="page-title">Commission</h1>
        <p class="page-sub"><?= $mode === 'invoice' ? 'Bookings made while the platform is in <strong>invoice mode</strong> are in the box below; the table at the bottom lists bookings where the guest paid the commission with the booking. ' : '' ?>The platform keeps <strong><?= h(pct(commissionRatePercent())) ?>%</strong> of your price as commission. <strong>You do not pay it and never send it anywhere:</strong> the guest pays you your price minus the commission, and pays the commission (plus a service fee) straight to StayExplore Kenya. You keep everything the guest pays you.</p>
        <?php if ($mode === 'invoice' || $hasInvoice): ?>
        <div class="fin-box">
            <h2>Commission you owe after stays</h2>
            <p class="small">For bookings made in invoice mode the guest pays you the <strong>full price</strong>. After the stay ends, the commission is due to StayExplore Kenya within <strong><?= (int)invoiceDueDays() ?> days</strong> of check-out. <?= $ledger['overdue'] > 0 && blockOverdueHosts() ? '<strong>Your listings are paused for new bookings until the overdue amount is paid.</strong>' : (blockOverdueHosts() ? 'Listings of hosts with overdue commission are paused for new bookings.' : '') ?></p>
            <div class="fin-cards">
                <div class="fin-card"><div class="fin-label">Commission due so far</div><div class="fin-value"><?= kes($ledger['due']) ?></div></div>
                <div class="fin-card accent"><div class="fin-label">Paid and confirmed</div><div class="fin-value"><?= kes($ledger['paid']) ?></div></div>
                <div class="fin-card warn"><div class="fin-label">Outstanding balance</div><div class="fin-value"><?= kes($ledger['outstanding']) ?></div><div class="fin-note"><?= $ledger['overdue'] > 0 ? 'of which overdue ' . kes($ledger['overdue']) : 'nothing overdue' ?><?= $ledger['pending'] > 0 ? ' · ' . kes($ledger['pending']) . ' waiting for confirmation' : '' ?></div></div>
            </div>
            <?php if ($ledger['items']): ?>
            <div class="fin-scroll"><table class="fin-table">
                <thead><tr><th>Booking</th><th>Stay</th><th class="num">Commission</th><th>Due by</th><th class="num">Unpaid</th></tr></thead>
                <tbody><?php foreach ($ledger['items'] as $it): ?>
                    <tr><td><?= h(bookingLabel($it['booking_id'])) ?></td><td><?= h($it['title']) ?><br><span class="small"><?= h($it['check_in_date']) ?> to <?= h($it['check_out_date']) ?></span></td>
                        <td class="num"><?= kes($it['commission_amount']) ?> <span class="small">(<?= h(pct($it['commission_rate'])) ?>%)</span></td>
                        <td><?= h($it['due_date']) ?><?= $it['overdue'] ? ' <span class="fin-pill void">Overdue</span>' : '' ?></td>
                        <td class="num"><strong><?= kes($it['unpaid']) ?></strong></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
            <?php if ($ledger['outstanding'] - $ledger['pending'] > 0.009): ?>
            <form method="POST" style="margin-top:14px">
                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                <p class="small"><?= nl2br(h(siteSettingGet('platform_pay_instructions', COMMISSION_PAY_INSTRUCTIONS))) ?></p>
                <label class="fin-field" for="ca">Amount you paid (KES)</label>
                <input class="fin-input" style="max-width:180px" id="ca" name="amount" value="<?= h(number_format(max(0.0, $ledger['outstanding'] - $ledger['pending']), 2, '.', '')) ?>" inputmode="decimal" required>
                <label class="fin-field" for="cm">How you paid</label>
                <select class="fin-input" style="max-width:220px" id="cm" name="method" required><option value="mpesa">M-Pesa</option><option value="bank_transfer">Bank transfer</option></select>
                <label class="fin-field" for="cref">M-Pesa code or bank reference</label>
                <input class="fin-input" style="max-width:260px" id="cref" name="reference" maxlength="120" required>
                <div class="fin-actions"><button class="fin-btn" type="submit">Report my payment</button></div>
            </form>
            <?php endif; ?>
            <?php if ($myPayments): ?>
            <h2 style="margin-top:18px">Your commission payments</h2>
            <div class="fin-scroll"><table class="fin-table"><thead><tr><th>Date</th><th>Reference</th><th class="num">Amount</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($myPayments as $m): ?><tr><td><?= h(date('j M Y', strtotime($m['created_at']))) ?></td><td><?= h($m['reference']) ?></td><td class="num"><?= kes($m['amount']) ?></td>
                <td><span class="fin-pill <?= $m['status'] === 'confirmed' ? 'settled' : ($m['status'] === 'rejected' ? 'void' : 'pending') ?>"><?= h(['pending' => 'Being checked', 'confirmed' => 'Received', 'rejected' => 'Not accepted'][$m['status']]) ?></span><?= $m['review_note'] ? '<div class="small">' . h($m['review_note']) . '</div>' : '' ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="fin-cards">
            <div class="fin-card"><div class="fin-label">Your prices (active bookings)</div><div class="fin-value"><?= kes($tPrice) ?></div></div>
            <div class="fin-card warn"><div class="fin-label">Commission to the platform</div><div class="fin-value"><?= kes($tComm) ?></div><div class="fin-note">paid by guests, not by you</div></div>
            <div class="fin-card accent"><div class="fin-label">Guests pay you</div><div class="fin-value"><?= kes($tNet) ?></div></div>
        </div>
        <div class="fin-box">
            <h2>Your bookings</h2>
            <div class="fin-scroll"><table class="fin-table">
                <thead><tr><th>Booking</th><th>Stay</th><th class="num">Your price</th><th class="num">Commission</th><th class="num">Guest pays you</th><th>Platform payment</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="6">No bookings with commission yet.</td></tr><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= h(bookingLabel($r['booking_id'])) ?><br><span class="small"><?= h($r['check_in_date']) ?> to <?= h($r['check_out_date']) ?></span></td>
                        <td><?= h($r['title']) ?></td>
                        <td class="num"><?= kes($r['price']) ?></td>
                        <td class="num">– <?= kes($r['commission_amount']) ?> <span class="small">(<?= h(pct($r['commission_rate'])) ?>%)</span></td>
                        <td class="num"><strong><?= kes($r['net']) ?></strong></td>
                        <td><span class="fin-pill <?= $r['pp_status'] === 'confirmed' ? 'settled' : 'pending' ?>"><?= h(['confirmed' => 'Guest paid the platform', 'pending' => 'Being checked', 'rejected' => 'Not accepted', 'refunded' => 'Refunded'][$r['pp_status'] ?? ''] ?? 'Not yet paid') ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
<?php appShellEnd(); ?>
</body>
</html>
