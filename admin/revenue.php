<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/notifications.php';
require_once '../includes/commission.php';
require_once '../includes/finance_audit.php';
require_once '../includes/invoice_reminders.php';
require_once '../includes/email.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');
$admin_id = (int)$_SESSION['user_id'];
ensureCommissionTables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save_rates') {
        $c = (float)str_replace(',', '.', (string)($_POST['commission_rate'] ?? ''));
        $f = (float)str_replace(',', '.', (string)($_POST['service_fee'] ?? ''));
        $ins = mb_substr(trim((string)($_POST['instructions'] ?? '')), 0, 500);
        $payDl = (int)($_POST['payment_deadline_hours'] ?? 24); $reply = (int)($_POST['reply_hours'] ?? 0); $free = (int)($_POST['free_cancel_hours'] ?? -1);
        if (!in_array($payDl, [12, 24, 48], true) || $reply < 1 || $reply > 168 || $free < 0 || $free > 720) { setFlash('error', 'Payment deadline must be 12, 24 or 48 hours; host reply 1 to 168 hours; free cancellation 0 to 720 hours.'); }
        elseif ($c < 0 || $c > 30 || $f < 0 || $f > 30) { setFlash('error', 'Rates must be between 0 and 30 percent.'); }
        else {
            $mode = 'invoice';   // launch model: the host pays the commission, never the guest
            $base = ($_POST['commission_base'] ?? '') === 'stay_only' ? 'stay_only' : 'stay_extras';
            $due = max(0, min(90, (int)($_POST['invoice_due_days'] ?? 7)));
            siteSettingSet('commission_mode', $mode, $admin_id); siteSettingSet('commission_base', $base, $admin_id);
            siteSettingSet('invoice_due_days', $due, $admin_id); siteSettingSet('block_overdue_hosts', isset($_POST['block_overdue_hosts']) ? '1' : '0', $admin_id);
            siteSettingSet('invoice_reminders', isset($_POST['invoice_reminders']) ? '1' : '0', $admin_id);
            siteSettingSet('invoice_reminder_before_days', max(0, min(30, (int)($_POST['invoice_reminder_before_days'] ?? 2))), $admin_id);
            siteSettingSet('invoice_reminder_repeat_days', max(1, min(60, (int)($_POST['invoice_reminder_repeat_days'] ?? 7))), $admin_id);
            siteSettingSet('commission_rate_percent', $c, $admin_id); siteSettingSet('service_fee_percent', $f, $admin_id);
            if ($ins !== '') siteSettingSet('platform_pay_instructions', $ins, $admin_id);
            siteSettingSet('payment_deadline_hours', $payDl, $admin_id); siteSettingSet('host_reply_hours', $reply, $admin_id); siteSettingSet('free_cancel_hours', $free, $admin_id);
            setFlash('success', 'Saved. New bookings will use these rates and time limits. Bookings already made keep their old rates.');
        }
    } elseif (in_array($act, ['hcp_accept', 'hcp_reject'], true)) {
        $id = (int)($_POST['hcp_id'] ?? 0); $note = mb_substr(trim((string)($_POST['review_note'] ?? '')), 0, 300);
        $st = $pdo->prepare("SELECT * FROM host_commission_payments WHERE hcp_id = ? AND status = 'pending'"); $st->execute([$id]); $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $new = $act === 'hcp_accept' ? 'confirmed' : 'rejected';
            // v9.38.1: the decision only applies while the payment is still 'pending' (a double click or a second admin changes nothing), and it is logged.
            $done = false;
            try {
                $pdo->beginTransaction();
                $u = $pdo->prepare("UPDATE host_commission_payments SET status = ?, reviewed_at = NOW(), reviewed_by = ?, review_note = ? WHERE hcp_id = ? AND status = 'pending'");
                $u->execute([$new, $admin_id, $note !== '' ? $note : null, $id]);
                if ($u->rowCount() === 1) { logFinanceEvent($pdo, 'host_commission_payment', $id, (int)$r['host_id'], $admin_id, 'admin', $act === 'hcp_accept' ? 'accepted' : 'rejected', 'pending', $new, $r['amount'], 'ref ' . $r['reference'] . ($note !== '' ? ' | ' . $note : '')); $pdo->commit(); $done = true; }
                else $pdo->rollBack();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('hcp decision: ' . $e->getMessage()); }
            if (!$done) { setFlash('info', 'This payment was already handled or could not be saved, so nothing changed.'); redirect('revenue.php'); }
            notify($pdo, (int)$r['host_id'], $new === 'confirmed' ? 'Commission payment received' : 'Commission payment not accepted',
                $new === 'confirmed' ? 'Your commission payment of KES ' . number_format($r['amount'], 2) . ' (ref ' . $r['reference'] . ') was confirmed.' : 'We could not match your commission payment (ref ' . $r['reference'] . ')' . ($note !== '' ? ': ' . $note : '') . '. Please check it and report it again.', 'host/commission.php');
            syncCommissionInvoiceStatuses($pdo, (int)$r['host_id']);
            setFlash('success', $new === 'confirmed' ? 'Marked as received. The host has been told.' : 'Rejected. The host has been told.');
        }
    } elseif ($act === 'hcp_reverse') {
        // v9.38.1: an admin can reverse a CONFIRMED host commission payment (a bounced deposit, a payment recorded by mistake).
        // It is never deleted: the payment is marked rejected with the reason, the host balance goes back up, the host is told, and the audit trail records it.
        $id = (int)($_POST['hcp_id'] ?? 0); $note = mb_substr(trim((string)($_POST['review_note'] ?? '')), 0, 300);
        $q = $pdo->prepare('SELECT * FROM host_commission_payments WHERE hcp_id = ?'); $q->execute([$id]); $r = $q->fetch(PDO::FETCH_ASSOC);
        if (!$r) { setFlash('error', 'Payment not found.'); }
        elseif (mb_strlen($note) < 3) { setFlash('error', 'Give a short reason for the reversal (at least 3 characters). It is kept in the audit trail.'); }
        else {
            $done = false;
            try {
                $pdo->beginTransaction();
                $u = $pdo->prepare("UPDATE host_commission_payments SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ?, review_note = ? WHERE hcp_id = ? AND status = 'confirmed'");
                $u->execute([$admin_id, 'Reversed by admin: ' . $note, $id]);
                if ($u->rowCount() === 1) { logFinanceEvent($pdo, 'host_commission_payment', $id, (int)$r['host_id'], $admin_id, 'admin', 'reversed', 'confirmed', 'rejected', $r['amount'], 'ref ' . $r['reference'] . ' | ' . $note); $pdo->commit(); $done = true; }
                else $pdo->rollBack();
            } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('hcp reverse: ' . $e->getMessage()); }
            if ($done) {
                notify($pdo, (int)$r['host_id'], 'Commission payment reversed', 'Your commission payment of KES ' . number_format((float)$r['amount'], 2) . ' (ref ' . $r['reference'] . ') was reversed: ' . $note . '. Your balance has been updated.', 'host/commission.php');
                syncCommissionInvoiceStatuses($pdo, (int)$r['host_id']);
                setFlash('success', 'Payment reversed. The host\'s balance has been updated and the host has been told.');
            } else { setFlash('info', 'Only a confirmed payment can be reversed, and this one was already changed or could not be saved.'); }
        }
    } elseif ($act === 'hcp_record') {
        // v9.38.1: the admin records money received from a host that the host did not report themselves (e.g. a bank deposit).
        $hid = (int)($_POST['host_id'] ?? 0); $amt = round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '')), 2);
        $method = (string)($_POST['method'] ?? ''); $ref = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 120); $note = mb_substr(trim((string)($_POST['review_note'] ?? '')), 0, 300);
        $hq = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ? AND role = 'host'"); $hq->execute([$hid]); $hostName = $hq->fetchColumn();
        if (!$hostName) { setFlash('error', 'Choose a host.'); }
        elseif (!in_array($method, ['mpesa', 'bank_transfer'], true) || mb_strlen($ref) < 4) { setFlash('error', 'Choose M-Pesa or bank transfer and enter the reference from your statement (at least 4 characters).'); }
        elseif ($amt <= 0) { setFlash('error', 'Enter the amount received.'); }
        elseif (mb_strlen($note) < 3) { setFlash('error', 'Add a short note saying why you are recording this yourself (at least 3 characters).'); }
        else {
            $dup = $pdo->prepare("SELECT 1 FROM host_commission_payments WHERE reference = ? AND status <> 'rejected' LIMIT 1"); $dup->execute([$ref]);
            if ($dup->fetchColumn()) { setFlash('error', 'That reference is already recorded for a payment.'); }
            else {
                $newId = 0;
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("INSERT INTO host_commission_payments (host_id, amount, method, reference, status, reviewed_at, reviewed_by, review_note) VALUES (?,?,?,?, 'confirmed', NOW(), ?, ?)")->execute([$hid, $amt, $method, $ref, $admin_id, 'Recorded by admin: ' . $note]);
                    $newId = (int)$pdo->lastInsertId();
                    logFinanceEvent($pdo, 'host_commission_payment', $newId, $hid, $admin_id, 'admin', 'recorded', null, 'confirmed', $amt, 'ref ' . $ref . ' | ' . $note);
                    $pdo->commit();
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('hcp record: ' . $e->getMessage()); $newId = 0; }
                if ($newId) {
                    notify($pdo, $hid, 'Commission payment received', 'StayExplore Kenya recorded your commission payment of KES ' . number_format($amt, 2) . ' (ref ' . $ref . ').', 'host/commission.php');
                    syncCommissionInvoiceStatuses($pdo, $hid);
                    setFlash('success', 'Recorded for ' . $hostName . '. It now counts toward their balance and the host has been told.');
                } else { setFlash('error', 'Could not record the payment. Nothing was saved.'); }
            }
        }
    } elseif (in_array($act, ['accept', 'reject', 'refund'], true)) {
        $id = opaqueIdDecode('plat', $_POST['platform_payment_ref'] ?? '');
        $note = mb_substr(trim((string)($_POST['review_note'] ?? '')), 0, 300);
        $st = $pdo->prepare("SELECT pp.*, u.email, u.full_name FROM platform_payments pp JOIN users u ON u.user_id = pp.tourist_id WHERE pp.platform_payment_id = ?");
        $st->execute([$id]); $r = $st->fetch(PDO::FETCH_ASSOC);
        $from = ['accept' => 'pending', 'reject' => 'pending', 'refund' => 'confirmed'][$act];
        if ($r && $r['status'] === $from) {
            if ($act === 'refund' && mb_strlen($note) < 3) { setFlash('error', 'Enter the refund reference or a short note (at least 3 characters).'); }
            else {
                $new = ['accept' => 'confirmed', 'reject' => 'rejected', 'refund' => 'refunded'][$act];
                // v9.38.1: guarded by the status we read (no double decisions), refund amount set in the same step, and logged.
                $done = false;
                try {
                    $pdo->beginTransaction();
                    $u = $pdo->prepare("UPDATE platform_payments SET status = ?, reviewed_at = NOW(), reviewed_by = ?, review_note = ? WHERE platform_payment_id = ? AND status = ?");
                    $u->execute([$new, $admin_id, $note !== '' ? $note : null, $id, $from]);
                    if ($u->rowCount() === 1) {
                        if ($act === 'refund') $pdo->prepare('UPDATE platform_payments SET refunded_amount = COALESCE(refund_due, amount) WHERE platform_payment_id = ?')->execute([$id]);
                        logFinanceEvent($pdo, 'platform_payment', $id, (int)$r['host_id'], $admin_id, 'admin', ['accept' => 'accepted', 'reject' => 'rejected', 'refund' => 'refunded'][$act], $from, $new, $act === 'refund' ? ($r['refund_due'] ?? $r['amount']) : $r['amount'], 'ref ' . $r['reference'] . ($note !== '' ? ' | ' . $note : ''));
                        $pdo->commit(); $done = true;
                    } else $pdo->rollBack();
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('platform decision: ' . $e->getMessage()); }
                if (!$done) { setFlash('info', 'This payment was already handled or could not be saved, so nothing changed.'); redirect('revenue.php'); }
                notify($pdo, (int)$r['tourist_id'], ['accept' => 'Payment received by StayExplore Kenya', 'reject' => 'Payment not accepted', 'refund' => 'Refund sent'][$act],
                    ['accept' => 'Your payment of KES ' . number_format($r['amount'], 2) . ' for ' . bookingLabel($r['booking_id']) . ' was confirmed. Your receipt is ready.', 'reject' => 'We could not match your payment for ' . bookingLabel($r['booking_id']) . ($note !== '' ? ': ' . $note : '') . '. Please check the reference and pay again.', 'refund' => 'A refund of KES ' . number_format((float)($r['refund_due'] ?? $r['amount']), 2) . ' for ' . bookingLabel($r['booking_id']) . ' was sent. Reference: ' . $note][$act], 'tourist/bookings.php');
                if ($act !== 'refund') {
                    try { sendPlatformPaymentDecisionGuestEmail($r['email'], $r['full_name'], $r['booking_id'], $r['amount'], $r['reference'], $act === 'accept', $note,
                          $act === 'accept' ? appAbsoluteUrl('platform_receipt.php?as=tourist&ref=' . opaqueId('plat', $id)) : ''); } catch (Throwable $e) {}
                }
                setFlash('success', ['accept' => 'Marked as received. The guest has been emailed their receipt.', 'reject' => 'Rejected. The guest has been told.', 'refund' => 'Recorded as refunded.'][$act]);
            }
        }
    }
    redirect('revenue.php');
}

$sum = platformSummary($pdo);
$cs = commissionPlatformSummary($pdo);
$perHost = $pdo->query("SELECT u.full_name, COUNT(*) AS n, SUM(pp.commission_amount) AS commission, SUM(pp.service_fee) AS fee, SUM(pp.amount) AS total
    FROM platform_payments pp JOIN users u ON u.user_id = pp.host_id WHERE pp.status = 'confirmed' GROUP BY u.user_id, u.full_name ORDER BY total DESC")->fetchAll(PDO::FETCH_ASSOC);
$pending = $pdo->query("SELECT pp.*, u.full_name AS tourist, a.title FROM platform_payments pp JOIN users u ON u.user_id = pp.tourist_id
    JOIN bookings b ON b.booking_id = pp.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id WHERE pp.status = 'pending' ORDER BY pp.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
$filter = in_array($_GET['status'] ?? '', ['pending', 'confirmed', 'rejected', 'refunded'], true) ? $_GET['status'] : '';
$lq = $pdo->prepare("SELECT pp.*, t.full_name AS tourist, h.full_name AS host_name, a.title, b.status AS booking_status FROM platform_payments pp
    JOIN users t ON t.user_id = pp.tourist_id JOIN users h ON h.user_id = pp.host_id
    JOIN bookings b ON b.booking_id = pp.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    " . ($filter ? "WHERE pp.status = ?" : "") . " ORDER BY pp.created_at DESC LIMIT 150");
$lq->execute($filter ? [$filter] : []); $ledger = $lq->fetchAll(PDO::FETCH_ASSOC);
$cRate = commissionRatePercent(); $fRate = serviceFeePercent(); $cMode = commissionMode(); $cBase = commissionBase();
$hcpPending = $pdo->query("SELECT c.*, u.full_name FROM host_commission_payments c JOIN users u ON u.user_id = c.host_id WHERE c.status = 'pending' ORDER BY c.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
$invoiceHosts = [];
foreach ($pdo->query("SELECT DISTINCT ci.host_id, u.full_name FROM commission_invoices ci JOIN users u ON u.user_id = ci.host_id WHERE ci.status <> 'void'")->fetchAll(PDO::FETCH_ASSOC) as $ih) {
    $l = hostCommissionLedger($pdo, (int)$ih['host_id']);
    $ih += ['due' => $l['due'], 'paid' => $l['paid'], 'pending' => $l['pending'], 'outstanding' => $l['outstanding'], 'overdue' => $l['overdue'], 'n' => count($l['items'])];
    $invoiceHosts[] = $ih;
}
usort($invoiceHosts, function ($a, $b) { return $b['outstanding'] <=> $a['outstanding']; });
$overdueInv = $pdo->query("SELECT ci.*, u.full_name, a.title FROM commission_invoices ci JOIN users u ON u.user_id = ci.host_id JOIN bookings b ON b.booking_id = ci.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    WHERE ci.status NOT IN ('paid','void') AND ci.due_date < CURDATE() ORDER BY ci.due_date ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$recordHosts = $pdo->query("SELECT user_id, full_name FROM users WHERE role = 'host' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$auditRows = financeAuditRecent($pdo, 40);
$hcpHistory = $pdo->query("SELECT c.*, u.full_name FROM host_commission_payments c JOIN users u ON u.user_id = c.host_id ORDER BY c.created_at DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue and commission — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <link rel="stylesheet" href="../assets/css/finance.css">
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <?= showFlash() ?>
        <div class="page-tag">Admin</div>
        <h1 class="page-title">Revenue and commission</h1>
        <p class="page-sub">Guests pay hosts directly. Each host pays StayExplore Kenya its commission by invoice, calculated when the host confirms the guest&rsquo;s payment and due after check-out. Guests never see or pay commission.</p>

        <p class="no-print"><a class="fin-btn ghost" href="revenue_report.php">Print revenue report</a></p>
        <div class="fin-cards">
            <div class="fin-card accent"><div class="fin-label">Total commission generated</div><div class="fin-value"><?= kes($cs['generated']) ?></div><div class="fin-note"><?= (int)$cs['invoices'] ?> invoice(s)</div></div>
            <div class="fin-card warn"><div class="fin-label">Outstanding host commission</div><div class="fin-value"><?= kes($cs['outstanding']) ?></div><div class="fin-note">invoiced, not yet paid</div></div>
            <div class="fin-card"><div class="fin-label">Awaiting verification</div><div class="fin-value"><?= kes($cs['awaiting']) ?></div><div class="fin-note"><?= (int)$cs['awaiting_n'] ?> payment(s) to check</div></div>
            <div class="fin-card accent"><div class="fin-label">Confirmed payments</div><div class="fin-value"><?= kes($cs['confirmed']) ?></div><div class="fin-note">verified by you</div></div>
            <div class="fin-card warn"><div class="fin-label">Overdue invoices</div><div class="fin-value"><?= (int)$cs['overdue_n'] ?></div><div class="fin-note"><?= kes($cs['overdue_amount']) ?> past due</div></div>
        </div>
        <?php if (!empty($cs['credit']) && $cs['credit'] > 0.004): ?><p class="small" role="status"><strong>Reconciliation note:</strong> <?= kes($cs['credit']) ?> of confirmed host payments is not matched to any live invoice (an overpayment, or the invoice was voided after payment). Check these hosts before refunding or carrying the credit forward.</p><?php endif; ?>

        <form method="POST" class="fin-box">
            <h2>Rates, time limits and payment instructions</h2>
            <p class="small">The <strong>commission</strong> is a percentage of the host's price. The guest pays the host in full; the host pays you the commission separately, by invoice. The optional <strong>guest service fee</strong> should stay at 0 for the launch. Changes apply to <em>new</em> bookings only.</p>
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="save_rates">
            <label class="fin-field" for="cbase">Commission is a percentage of</label>
            <select class="fin-input" style="max-width:420px" id="cbase" name="commission_base">
                <option value="stay_extras"<?= $cBase === 'stay_extras' ? ' selected' : '' ?>>Stay + host extras (the host's whole price)</option>
                <option value="stay_only"<?= $cBase === 'stay_only' ? ' selected' : '' ?>>The accommodation price only (nights × price per night)</option>
            </select>
            <label class="fin-field" for="idd">Days after check-out the host has to pay each invoice</label>
            <input class="fin-input" style="max-width:140px" id="idd" name="invoice_due_days" value="<?= (int)invoiceDueDays() ?>" inputmode="numeric">
            <label class="fin-field"><input type="checkbox" name="block_overdue_hosts" value="1"<?= blockOverdueHosts() ? ' checked' : '' ?>> Pause new bookings for hosts with overdue commission</label>
            <label class="fin-field"><input type="checkbox" name="invoice_reminders" value="1"<?= invoiceRemindersOn() ? ' checked' : '' ?>> Email hosts reminders about unpaid commission invoices</label>
            <label class="fin-field" for="irb">Send the first reminder this many days before the due date (another on the due date)</label>
            <input class="fin-input" style="max-width:140px" id="irb" name="invoice_reminder_before_days" value="<?= (int)invoiceReminderBefore() ?>" inputmode="numeric">
            <label class="fin-field" for="irr">While overdue, repeat the reminder every this many days</label>
            <input class="fin-input" style="max-width:140px" id="irr" name="invoice_reminder_repeat_days" value="<?= (int)invoiceReminderRepeat() ?>" inputmode="numeric">
            <label class="fin-field" for="cr">Commission (% of the base chosen above)</label>
            <input class="fin-input" style="max-width:140px" id="cr" name="commission_rate" value="<?= h(pct($cRate)) ?>" inputmode="decimal" required>
            <label class="fin-field" for="sf">Guest service fee (% added to the total)</label>
            <input class="fin-input" style="max-width:140px" id="sf" name="service_fee" value="<?= h(pct($fRate)) ?>" inputmode="decimal" required>
            <h2 style="margin-top:18px">Booking time limits</h2>
            <label class="fin-field" for="pdl">Hours a guest has to pay the host after acceptance (then the dates are released)</label>
            <select class="fin-input" style="max-width:140px" id="pdl" name="payment_deadline_hours"><?php foreach ([12, 24, 48] as $hrs): ?><option value="<?= $hrs ?>"<?= paymentDeadlineHours() === $hrs ? ' selected' : '' ?>><?= $hrs ?> hours</option><?php endforeach; ?></select>
            <label class="fin-field" for="rh">Hours a host has to accept or decline a request</label>
            <input class="fin-input" style="max-width:140px" id="rh" name="reply_hours" value="<?= (int)hostReplyHours() ?>" inputmode="numeric" required>
            <label class="fin-field" for="fc">Free cancellation until this many hours before check-in</label>
            <input class="fin-input" style="max-width:140px" id="fc" name="free_cancel_hours" value="<?= (int)freeCancelHours() ?>" inputmode="numeric" required>
            <label class="fin-field" for="ins">How hosts pay you (shown to hosts on their Commission page)</label>
            <textarea class="fin-input" id="ins" name="instructions" rows="3" maxlength="500"><?= h(commissionPayInstructions()) ?></textarea>
            <div class="fin-actions"><button class="fin-btn" type="submit">Save</button></div>
        </form>

        <div class="fin-box">
            <h2>Record a commission payment received</h2>
            <p class="small">Use this when a host paid you but did not report it (for example a bank deposit you found on your statement). It counts immediately toward that host&rsquo;s balance and is written to the audit trail with your name and note.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="hcp_record">
                <label class="fin-field" for="rcHost">Host</label>
                <select class="fin-input" id="rcHost" name="host_id" required><option value="">Choose a host</option><?php foreach ($recordHosts as $rh): ?><option value="<?= (int)$rh['user_id'] ?>"><?= h($rh['full_name']) ?></option><?php endforeach; ?></select>
                <label class="fin-field" for="rcAmt">Amount received (KES)</label><input class="fin-input" id="rcAmt" name="amount" inputmode="decimal" required>
                <label class="fin-field" for="rcMethod">Method</label>
                <select class="fin-input" id="rcMethod" name="method" required><option value="mpesa">M-Pesa</option><option value="bank_transfer">Bank transfer</option></select>
                <label class="fin-field" for="rcRef">Reference on your statement</label><input class="fin-input" id="rcRef" name="reference" maxlength="120" required>
                <label class="fin-field" for="rcNote">Why you are recording it (kept in the audit trail)</label><input class="fin-input" id="rcNote" name="review_note" maxlength="300" required>
                <div class="fin-actions"><button class="fin-btn" type="submit">Record payment</button></div>
            </form>
        </div>

        <?php if ($hcpPending): ?>
        <div class="fin-box">
            <h2>Host commission payments to check</h2>
            <p class="small">Hosts report these after stays in invoice mode. Compare the reference and amount with your M-Pesa or bank statement before you accept.</p>
            <?php foreach ($hcpPending as $r): ?>
                <form method="POST" class="fin-box" style="margin-bottom:10px">
                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="hcp_id" value="<?= (int)$r['hcp_id'] ?>">
                    <strong><?= h($r['full_name']) ?></strong> reports <strong><?= kes($r['amount']) ?></strong> · <?= h($r['method'] === 'mpesa' ? 'M-Pesa' : 'Bank transfer') ?> · reference <strong><?= h($r['reference']) ?></strong> · <?= h(date('j M Y, H:i', strtotime($r['created_at']))) ?>
                    <label class="fin-field" for="hn<?= (int)$r['hcp_id'] ?>">Note to the host (optional)</label>
                    <input class="fin-input" id="hn<?= (int)$r['hcp_id'] ?>" name="review_note" maxlength="300">
                    <div class="fin-actions"><button class="fin-btn" name="action" value="hcp_accept" type="submit">Accept, money received</button> <button class="fin-btn danger" name="action" value="hcp_reject" type="submit">Reject</button></div>
                </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($invoiceHosts): ?>
        <div class="fin-box">
            <h2>Host commission report</h2>
            <div class="fin-scroll"><table class="fin-table stack-sm"><thead><tr><th>Host</th><th class="num">Invoiced</th><th class="num">Confirmed paid</th><th class="num">Awaiting check</th><th class="num">Outstanding</th><th class="num">Overdue</th></tr></thead><tbody>
            <?php foreach ($invoiceHosts as $ih): ?><tr><td data-label="Host"><?= h($ih['full_name']) ?></td><td data-label="Invoiced" class="num"><?= kes($ih['due']) ?></td><td data-label="Confirmed paid" class="num"><?= kes($ih['paid']) ?></td><td data-label="Awaiting check" class="num"><?= kes($ih['pending']) ?></td><td data-label="Outstanding" class="num"><strong><?= kes($ih['outstanding']) ?></strong></td><td data-label="Overdue" class="num"><?= $ih['overdue'] > 0 ? '<span class="fin-pill void">' . kes($ih['overdue']) . '</span>' : '&mdash;' ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>

        <?php if ($overdueInv): ?>
        <div class="fin-box">
            <h2>Overdue invoices</h2>
            <div class="fin-scroll"><table class="fin-table stack-sm"><thead><tr><th>Invoice</th><th>Host</th><th>Stay</th><th>Due</th><th class="num">Amount</th></tr></thead><tbody>
            <?php foreach ($overdueInv as $o): ?><tr><td data-label="Invoice"><?= h($o['invoice_no']) ?></td><td data-label="Host"><?= h($o['full_name']) ?></td><td data-label="Stay"><?= h($o['title']) ?></td><td data-label="Due"><?= h(date('d M Y', strtotime($o['due_date']))) ?></td><td data-label="Amount" class="num"><?= kes($o['amount']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>

        <?php if ($hcpHistory): ?>
        <div class="fin-box">
            <h2>Commission transaction history</h2>
            <div class="fin-scroll"><table class="fin-table stack-sm"><thead><tr><th>Date</th><th>Host</th><th>Reference</th><th>Method</th><th class="num">Amount</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($hcpHistory as $m): ?><tr><td data-label="Date"><?= h(date('d M Y', strtotime($m['created_at']))) ?></td><td data-label="Host"><?= h($m['full_name']) ?></td><td data-label="Reference"><?= h($m['reference']) ?></td><td data-label="Method"><?= h(str_replace('_', ' ', (string)$m['method'])) ?></td><td data-label="Amount" class="num"><?= kes($m['amount']) ?></td><td data-label="Status"><span class="fin-pill <?= $m['status'] === 'confirmed' ? 'settled' : ($m['status'] === 'rejected' ? 'rejected' : 'pending') ?>"><?= h(($m['status'] === 'rejected' && strpos((string)$m['review_note'], 'Reversed by admin') === 0) ? 'Reversed' : (['confirmed' => 'Confirmed', 'rejected' => 'Rejected', 'pending' => 'To check'][$m['status']] ?? $m['status'])) ?></span></td>
                <td data-label="Action"><?php if ($m['status'] === 'confirmed'): ?>
                    <form method="POST" onsubmit="return confirm('Reverse this confirmed payment? The host balance will go back up.');">
                        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="hcp_reverse"><input type="hidden" name="hcp_id" value="<?= (int)$m['hcp_id'] ?>">
                        <label class="sr-only" for="rv<?= (int)$m['hcp_id'] ?>">Reason for reversal</label>
                        <input class="fin-input" id="rv<?= (int)$m['hcp_id'] ?>" name="review_note" maxlength="300" placeholder="Reason (required)" required>
                        <button class="fin-btn" type="submit">Reverse</button>
                    </form>
                <?php elseif ($m['review_note']): ?><span class="small"><?= h($m['review_note']) ?></span><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>

        <?php if ($pending): ?>
        <div class="fin-box">
            <h2>Guest payments to check</h2>
            <p class="small">Compare the reference and amount with your M-Pesa or bank statement before you accept.</p>
            <?php foreach ($pending as $r): ?>
                <form method="POST" class="fin-box" style="margin-bottom:10px">
                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="platform_payment_ref" value="<?= h(opaqueId('plat', $r['platform_payment_id'])) ?>">
                    <strong><?= h($r['tourist']) ?></strong> reports <strong><?= kes($r['amount']) ?></strong> for <?= h(bookingLabel($r['booking_id'])) ?> (<?= h($r['title']) ?>) · <?= h($r['method'] === 'mpesa' ? 'M-Pesa' : 'Bank transfer') ?> · reference <strong><?= h($r['reference']) ?></strong> · <?= h(date('j M Y, H:i', strtotime($r['created_at']))) ?>
                    <div class="small">Service fee <?= kes($r['service_fee']) ?> + commission <?= kes($r['commission_amount']) ?><?php if ($r['proof_file']): ?> · <a href="../proof.php?as=admin&amp;type=platform&amp;ref=<?= h(opaqueId('plat', $r['platform_payment_id'])) ?>" target="_blank" rel="noopener">View screenshot</a><?php endif; ?></div>
                    <label class="fin-field" for="rn<?= substr(md5((string)$r['platform_payment_id']), 0, 8) ?>">Note to the guest (optional, sent by email)</label>
                    <input class="fin-input" id="rn<?= substr(md5((string)$r['platform_payment_id']), 0, 8) ?>" name="review_note" maxlength="300">
                    <div class="fin-actions">
                        <button class="fin-btn" name="action" value="accept" type="submit">Accept, money received</button>
                        <button class="fin-btn danger" name="action" value="reject" type="submit">Reject</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($perHost || $ledger || $pending): ?>
        <div class="fin-box">
            <h2>Earlier guest-paid platform shares (bookings made before the host-paid arrangement)</h2>
            <div class="fin-scroll"><table class="fin-table">
                <thead><tr><th>Host</th><th class="num">Bookings</th><th class="num">Commission</th><th class="num">Service fees</th><th class="num">Total received</th></tr></thead>
                <tbody>
                <?php if (!$perHost): ?><tr><td colspan="5">Nothing received yet.</td></tr><?php endif; ?>
                <?php foreach ($perHost as $h): ?><tr><td><?= h($h['full_name']) ?></td><td class="num"><?= (int)$h['n'] ?></td><td class="num"><?= kes($h['commission']) ?></td><td class="num"><?= kes($h['fee']) ?></td><td class="num"><strong><?= kes($h['total']) ?></strong></td></tr><?php endforeach; ?>
                </tbody>
            </table></div>
        </div>

        <div class="fin-box">
            <h2>Earlier platform payments</h2>
            <p class="small">Show: <a href="revenue.php">All</a> · <a href="?status=pending">Waiting</a> · <a href="?status=confirmed">Received</a> · <a href="?status=rejected">Rejected</a> · <a href="?status=refunded">Refunded</a></p>
            <div class="fin-scroll"><table class="fin-table">
                <thead><tr><th>Booking</th><th>Guest</th><th>Host</th><th class="num">Service fee</th><th class="num">Commission</th><th class="num">Total</th><th>Status</th><th>Evidence</th></tr></thead>
                <tbody>
                <?php if (!$ledger): ?><tr><td colspan="8">Nothing to show.</td></tr><?php endif; ?>
                <?php foreach ($ledger as $r): ?>
                    <tr>
                        <td><?= h(bookingLabel($r['booking_id'])) ?><br><span class="small"><?= h($r['title']) ?></span></td>
                        <td><?= h($r['tourist']) ?></td><td><?= h($r['host_name']) ?></td>
                        <td class="num"><?= kes($r['service_fee']) ?></td><td class="num"><?= kes($r['commission_amount']) ?></td><td class="num"><strong><?= kes($r['amount']) ?></strong></td>
                        <td><span class="fin-pill <?= h($r['status'] === 'confirmed' ? 'settled' : ($r['status'] === 'rejected' ? 'void' : ($r['status'] === 'refunded' ? 'submitted' : 'pending'))) ?>"><?= h(['pending' => 'Waiting', 'confirmed' => 'Received', 'rejected' => 'Rejected', 'refunded' => 'Refunded'][$r['status']]) ?></span>
                            <div class="small">Ref: <?= h($r['reference']) ?></div>
                            <?= $r['review_note'] ? '<div class="small">' . h($r['review_note']) . '</div>' : '' ?>
                            <?php if ($r['status'] === 'refunded' && $r['refunded_amount'] !== null): ?><div class="small">Refunded <?= kes($r['refunded_amount']) ?></div><?php endif; ?>
                            <?php if ($r['status'] === 'confirmed' && $r['booking_status'] === 'cancelled'): ?>
                                <div class="fin-warn">This booking was cancelled. <?= $r['refund_due'] !== null ? ((float)$r['refund_due'] > 0 ? '<strong>Refund due to the guest: ' . kes($r['refund_due']) . '.</strong> ' : '<strong>No automatic refund.</strong> ') . h((string)$r['refund_note']) : 'Consider refunding the guest.' ?></div>
                                <form method="POST" class="fin-actions no-print" onsubmit="var n=prompt('Refund reference or note (e.g. M-Pesa code):'); if(!n||n.trim().length<3){return false;} this.review_note.value=n; return true;">
                                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="refund"><input type="hidden" name="platform_payment_ref" value="<?= h(opaqueId('plat', $r['platform_payment_id'])) ?>"><input type="hidden" name="review_note" value="">
                                    <button class="fin-btn ghost" type="submit">Record refund</button>
                                </form>
                            <?php endif; ?></td>
                        <td><a href="evidence.php?ref=<?= h(bookingRef($r['booking_id'])) ?>">Evidence pack</a><?php if ($r['proof_file']): ?><br><a href="../proof.php?as=admin&amp;type=platform&amp;ref=<?= h(opaqueId('plat', $r['platform_payment_id'])) ?>" target="_blank" rel="noopener">Screenshot</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        <?php endif; ?>
        <?php if ($auditRows): ?>
        <div class="fin-box">
            <h2>Recent finance activity (audit trail)</h2>
            <p class="small">Every accept, reject, refund and manual recording, with who did it. Entries cannot be edited.</p>
            <div class="fin-scroll"><table class="fin-table stack-sm"><thead><tr><th>When</th><th>Who</th><th>Host</th><th>What</th><th class="num">Amount</th><th>Note</th></tr></thead><tbody>
            <?php foreach ($auditRows as $a): ?><tr>
                <td data-label="When"><?= h(date('d M Y, H:i', strtotime($a['created_at']))) ?></td>
                <td data-label="Who"><?= h($a['actor_name'] ?: ucfirst($a['actor_role'])) ?></td>
                <td data-label="Host"><?= h($a['host_name'] ?: '—') ?></td>
                <td data-label="What"><?= h(str_replace('_', ' ', $a['entity'])) ?> #<?= (int)$a['entity_id'] ?>: <?= h($a['action']) ?><?= $a['old_status'] ? ' (' . h($a['old_status']) . ' &rarr; ' . h($a['new_status']) . ')' : '' ?></td>
                <td class="num" data-label="Amount"><?= $a['amount'] !== null ? kes($a['amount']) : '—' ?></td>
                <td data-label="Note"><?= h((string)$a['note']) ?></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>
<?php appShellEnd(); ?>
</body>
</html>