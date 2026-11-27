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
require_once '../includes/finance_audit.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $led = hostCommissionLedger($pdo, $host_id);
    $amt = round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '')), 2);
    $method = (string)($_POST['method'] ?? ''); $ref = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 120);
    $room = $led['outstanding'] - $led['pending'];
    if (!in_array($method, ['mpesa', 'bank_transfer'], true) || mb_strlen($ref) < 4) { setFlash('error', 'Choose M-Pesa or bank transfer and enter the payment reference (at least 4 characters).'); }
    elseif ($amt <= 0 || $amt > max(0.0, $room) + 0.009) { setFlash('error', 'Enter an amount between 1 and the balance not yet reported (KES ' . number_format(max(0.0, $room), 2) . ').'); }
    else {
        $dup = $pdo->prepare("SELECT 1 FROM host_commission_payments WHERE reference = ? AND status <> 'rejected' LIMIT 1"); $dup->execute([$ref]);   // a reference can back only one payment, whichever host reported it
        if ($dup->fetchColumn()) { setFlash('error', 'That reference has already been reported.'); }
        else {
            $pdo->prepare('INSERT INTO host_commission_payments (host_id, amount, method, reference) VALUES (?,?,?,?)')->execute([$host_id, $amt, $method, $ref]);
            logFinanceEvent($pdo, 'host_commission_payment', (int)$pdo->lastInsertId(), $host_id, $host_id, 'host', 'reported', null, 'pending', $amt, 'ref ' . $ref);
            notifyAdmins($pdo, 'Host commission payment to check', ($_SESSION['full_name'] ?? 'A host') . ' reports KES ' . number_format($amt, 2) . ' commission (ref ' . $ref . '). Compare it with your statement.', 'admin/revenue.php');
            setFlash('success', 'Reported. It will count once StayExplore Kenya has checked it.');
        }
    }
    redirect('commission.php');
}
$summary = hostCommissionSummary($pdo, $host_id);
$ledger  = $summary['ledger'];
$hcp = $pdo->prepare('SELECT * FROM host_commission_payments WHERE host_id = ? ORDER BY created_at DESC LIMIT 50'); $hcp->execute([$host_id]); $myPayments = $hcp->fetchAll(PDO::FETCH_ASSOC);
$invoices = array_reverse($ledger['items']);   // newest first for display
$room = max(0.0, $ledger['outstanding'] - $ledger['pending']);

// Bookings made before v9.33 under the old "guest pays commission with the booking" arrangement keep their original terms.
$q = $pdo->prepare("SELECT b.booking_id, b.check_in_date, b.status, b.total_amount, b.service_fee, b.commission_amount, a.title, pp.status AS pp_status
    FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id LEFT JOIN platform_payments pp ON pp.booking_id = b.booking_id
    WHERE a.host_id = ? AND b.commission_amount IS NOT NULL AND b.commission_mode = 'upfront' AND b.status <> 'cancelled' ORDER BY b.created_at DESC LIMIT 100");
$q->execute([$host_id]); $legacyRows = $q->fetchAll(PDO::FETCH_ASSOC);
$rate = commissionRatePercent();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commission — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <link rel="stylesheet" href="../assets/css/finance.css">
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <?= showFlash() ?>
        <div class="page-tag">Hosting</div>
        <h1 class="page-title">Commission</h1>
        <p class="page-sub">Guests pay you directly for their accommodation. Your agreed platform commission is worked out automatically the moment you confirm a guest&rsquo;s payment, and is payable separately to StayExplore Kenya by the due date shown on each invoice.</p>

        <div class="fin-box how-box">
            <h2>How it works</h2>
            <ol class="how-list">
                <li><strong>Guests pay you.</strong> After you accept a request the guest pays you the full price, and you confirm when it arrives. StayExplore Kenya never holds that money.</li>
                <li><strong>Commission is <?= h(pct($rate)) ?>%.</strong> It is <?= h(pct($rate)) ?>% of <?= commissionBase() === 'stay_only' ? 'the accommodation price' : 'your price for the stay plus any extras you charge' ?>, using the rate saved on each booking when it was made.</li>
                <li><strong>It is calculated</strong> as soon as you mark the guest&rsquo;s payment as received, so you can see what is coming. <strong>It is due</strong> <?= (int)invoiceDueDays() ?> day(s) after check-out. If the booking is cancelled, or you set the payment back to unpaid, the commission is removed. Declined, unpaid, cancelled and no-show bookings carry no commission.</li>
                <li><strong>You pay by invoice.</strong> Each invoice is due <?= (int)invoiceDueDays() ?> days after check-out. Pay by M-Pesa or bank transfer, then report the reference below. StayExplore Kenya verifies it and your balance updates.</li>
            </ol>
            <p class="small">Your commission is between you and StayExplore Kenya. Guests never see it and must never be asked to pay it.</p>
        </div>

        <div class="fin-cards">
            <div class="fin-card"><div class="fin-label">Total commission earned</div><div class="fin-value"><?= kes($summary['earned']) ?></div><div class="fin-note">on stays whose payment you confirmed</div></div>
            <div class="fin-card warn"><div class="fin-label">Outstanding</div><div class="fin-value"><?= kes($summary['outstanding']) ?></div><div class="fin-note"><?= $summary['overdue'] > 0 ? 'of which overdue ' . kes($summary['overdue']) : 'nothing overdue' ?></div></div>
            <div class="fin-card"><div class="fin-label">Pending verification</div><div class="fin-value"><?= kes($summary['pending_payments']) ?></div><div class="fin-note">reported, awaiting check</div></div>
            <div class="fin-card accent"><div class="fin-label">Confirmed payments</div><div class="fin-value"><?= kes($summary['confirmed_payments']) ?></div><div class="fin-note"><?= (int)$summary['confirmed_count'] ?> payment<?= $summary['confirmed_count'] == 1 ? '' : 's' ?></div></div>
        </div>

        <div class="fin-box">
            <h2>Commission invoices</h2>
            <?php if (!$invoices): ?>
                <p class="small">No invoices yet. An invoice appears here automatically as soon as you confirm a guest&rsquo;s payment.</p>
            <?php else: ?>
            <div class="fin-scroll"><table class="fin-table stack-sm">
                <thead><tr><th>Invoice</th><th>Stay</th><th>Due</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($invoices as $i): ?>
                    <tr>
                        <td data-label="Invoice"><?= h($i['invoice_no']) ?></td>
                        <td data-label="Stay"><?= h($i['title']) ?><div class="small"><?= h(date('d M', strtotime($i['check_in_date']))) ?> &ndash; <?= h(date('d M Y', strtotime($i['check_out_date']))) ?></div></td>
                        <td data-label="Due"><?= h(date('d M Y', strtotime($i['due_date']))) ?></td>
                        <td data-label="Amount" class="num"><?= kes($i['amount']) ?></td>
                        <td data-label="Status"><?php if ($i['state'] === 'paid'): ?><span class="fin-pill ok">Paid</span><?php elseif ($i['overdue']): ?><span class="fin-pill bad">Overdue</span><?php elseif ($i['state'] === 'partial'): ?><span class="fin-pill warn">Part-paid</span><?php else: ?><span class="fin-pill warn">Unpaid</span><?php endif; ?></td>
                        <td data-label=""><a class="btn-sm" href="commission_invoice.php?no=<?= h(urlencode($i['invoice_no'])) ?>" target="_blank" rel="noopener">View / print</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>

        <?php if ($room > 0.004): ?>
        <div class="fin-box">
            <h2>Pay StayExplore Kenya</h2>
            <p class="small"><?= nl2br(h(commissionPayInstructions())) ?></p>
            <form method="POST" class="fin-form">
                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-row">
                    <div class="form-group"><label for="c_amount">Amount paid (KES)</label><input id="c_amount" type="number" name="amount" step="0.01" min="1" max="<?= h(number_format($room, 2, '.', '')) ?>" value="<?= h(number_format($room, 2, '.', '')) ?>" required></div>
                    <div class="form-group"><label for="c_method">Method</label><select id="c_method" name="method" required><option value="mpesa">M-Pesa</option><option value="bank_transfer">Bank transfer</option></select></div>
                    <div class="form-group"><label for="c_ref">M-Pesa code or bank reference</label><input id="c_ref" type="text" name="reference" maxlength="120" minlength="4" required placeholder="e.g. QGH7XXXXXX"></div>
                </div>
                <button type="submit" class="btn-save">Report payment</button>
                <div class="small">It reduces your balance once StayExplore Kenya has checked it. Only enter a payment you really made.</div>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($myPayments): ?>
        <div class="fin-box">
            <h2>Payment history</h2>
            <div class="fin-scroll"><table class="fin-table stack-sm">
                <thead><tr><th>Date</th><th>Reference</th><th>Method</th><th class="num">Amount</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($myPayments as $m): ?>
                    <tr>
                        <td data-label="Date"><?= h(date('d M Y', strtotime($m['created_at']))) ?></td>
                        <td data-label="Reference"><?= h($m['reference']) ?></td>
                        <td data-label="Method"><?= h(str_replace('_', ' ', (string)$m['method'])) ?></td>
                        <td data-label="Amount" class="num"><?= kes($m['amount']) ?></td>
                        <td data-label="Status"><?php if ($m['status'] === 'confirmed'): ?><span class="fin-pill ok">Confirmed</span><?php elseif ($m['status'] === 'rejected'): ?><span class="fin-pill bad">Not accepted</span><?php if (!empty($m['review_note'])): ?><div class="small"><?= h($m['review_note']) ?></div><?php endif; ?><?php else: ?><span class="fin-pill warn">Being checked</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endif; ?>

        <?php if ($legacyRows): ?>
        <div class="fin-box">
            <h2>Earlier bookings (before this arrangement)</h2>
            <p class="small">These bookings were made when guests paid the platform share together with the booking. They keep the terms they were made under.</p>
            <div class="fin-scroll"><table class="fin-table stack-sm">
                <thead><tr><th>Stay</th><th>Check-in</th><th class="num">Commission</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($legacyRows as $r): ?>
                    <tr><td data-label="Stay"><?= h($r['title']) ?></td><td data-label="Check-in"><?= h(date('d M Y', strtotime($r['check_in_date']))) ?></td><td data-label="Commission" class="num"><?= kes($r['commission_amount']) ?></td><td data-label="Status"><?= h(['confirmed' => 'Received', 'pending' => 'Being checked', 'rejected' => 'Not accepted', 'refunded' => 'Refunded'][$r['pp_status']] ?? 'Awaiting guest') ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endif; ?>
<?php appShellEnd(); ?>
</body>
</html>
