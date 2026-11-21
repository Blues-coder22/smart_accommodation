<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/notifications.php';
require_once '../includes/commission.php';
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
        $hold = (int)($_POST['hold_hours'] ?? 0); $reply = (int)($_POST['reply_hours'] ?? 0); $free = (int)($_POST['free_cancel_hours'] ?? -1);
        if ($hold < 1 || $hold > 48 || $reply < 1 || $reply > 168 || $free < 0 || $free > 720) { setFlash('error', 'Time limits: payment details 1 to 48 hours, host reply 1 to 168 hours, free cancellation 0 to 720 hours.'); }
        elseif ($c < 0 || $c > 30 || $f < 0 || $f > 30) { setFlash('error', 'Rates must be between 0 and 30 percent.'); }
        else {
            $mode = ($_POST['commission_mode'] ?? '') === 'invoice' ? 'invoice' : 'upfront';
            $base = ($_POST['commission_base'] ?? '') === 'stay_only' ? 'stay_only' : 'stay_extras';
            $due = max(0, min(90, (int)($_POST['invoice_due_days'] ?? 7)));
            siteSettingSet('commission_mode', $mode, $admin_id); siteSettingSet('commission_base', $base, $admin_id);
            siteSettingSet('invoice_due_days', $due, $admin_id); siteSettingSet('block_overdue_hosts', isset($_POST['block_overdue_hosts']) ? '1' : '0', $admin_id);
            siteSettingSet('commission_rate_percent', $c, $admin_id); siteSettingSet('service_fee_percent', $f, $admin_id);
            if ($ins !== '') siteSettingSet('platform_pay_instructions', $ins, $admin_id);
            siteSettingSet('booking_hold_hours', $hold, $admin_id); siteSettingSet('host_reply_hours', $reply, $admin_id); siteSettingSet('free_cancel_hours', $free, $admin_id);
            setFlash('success', 'Saved. New bookings will use these rates and time limits. Bookings already made keep their old rates.');
        }
    } elseif (in_array($act, ['hcp_accept', 'hcp_reject'], true)) {
        $id = (int)($_POST['hcp_id'] ?? 0); $note = mb_substr(trim((string)($_POST['review_note'] ?? '')), 0, 300);
        $st = $pdo->prepare("SELECT * FROM host_commission_payments WHERE hcp_id = ? AND status = 'pending'"); $st->execute([$id]); $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $new = $act === 'hcp_accept' ? 'confirmed' : 'rejected';
            $pdo->prepare('UPDATE host_commission_payments SET status = ?, reviewed_at = NOW(), reviewed_by = ?, review_note = ? WHERE hcp_id = ?')->execute([$new, $admin_id, $note !== '' ? $note : null, $id]);
            notify($pdo, (int)$r['host_id'], $new === 'confirmed' ? 'Commission payment received' : 'Commission payment not accepted',
                $new === 'confirmed' ? 'Your commission payment of KES ' . number_format($r['amount'], 2) . ' (ref ' . $r['reference'] . ') was confirmed.' : 'We could not match your commission payment (ref ' . $r['reference'] . ')' . ($note !== '' ? ': ' . $note : '') . '. Please check it and report it again.', 'host/commission.php');
            setFlash('success', $new === 'confirmed' ? 'Marked as received. The host has been told.' : 'Rejected. The host has been told.');
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
                $pdo->prepare("UPDATE platform_payments SET status = ?, reviewed_at = NOW(), reviewed_by = ?, review_note = ? WHERE platform_payment_id = ?")->execute([$new, $admin_id, $note !== '' ? $note : null, $id]);
                if ($act === 'refund') $pdo->prepare('UPDATE platform_payments SET refunded_amount = COALESCE(refund_due, amount) WHERE platform_payment_id = ?')->execute([$id]);
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
foreach ($pdo->query("SELECT DISTINCT a.host_id, u.full_name FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id JOIN users u ON u.user_id = a.host_id WHERE b.commission_mode = 'invoice' AND b.commission_amount > 0")->fetchAll(PDO::FETCH_ASSOC) as $ih) {
    $l = hostCommissionLedger($pdo, (int)$ih['host_id']); $ih += ['due' => $l['due'], 'paid' => $l['paid'], 'outstanding' => $l['outstanding'], 'overdue' => $l['overdue']]; $invoiceHosts[] = $ih;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue and commission — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/finance.css">
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <?= showFlash() ?>
        <div class="page-tag">Admin</div>
        <h1 class="page-title">Revenue and commission</h1>
        <p class="page-sub"><?= $cMode === 'invoice' ? '<strong>Current mode: invoice after the stay.</strong> New bookings: the guest pays the host in full and the host owes you the commission after check-out. The text below describes the <em>with the booking</em> mode, which still applies to bookings made in that mode. ' : '' ?><strong>How the money moves:</strong> for every booking the guest pays <em>two</em> things, each straight to its owner. The host's share (stay and extras, minus your commission) goes to the <strong>host</strong>. Your share (the guest service fee plus the commission) comes straight to <strong>you</strong>. You never touch the host's money, and hosts owe you nothing afterwards. A payment counts as <strong>received</strong> only after you check it against your M-Pesa or bank statement and accept it below.</p>

        <p><a class="fin-btn ghost" href="revenue_report.php">Print revenue report</a></p>
        <div class="fin-cards">
            <div class="fin-card accent"><div class="fin-label">Received by the platform</div><div class="fin-value"><?= kes($sum['received']) ?></div><div class="fin-note">service fees <?= kes($sum['received_fee']) ?> + commission <?= kes($sum['received_commission']) ?></div></div>
            <div class="fin-card warn"><div class="fin-label">Waiting for your check</div><div class="fin-value"><?= kes($sum['awaiting']) ?></div><div class="fin-note"><?= (int)$sum['awaiting_n'] ?> payment(s) reported by guests</div></div>
            <div class="fin-card"><div class="fin-label">Refunded to guests</div><div class="fin-value"><?= kes($sum['refunded']) ?></div><div class="fin-note">cancelled bookings</div></div>
            <div class="fin-card"><div class="fin-label">Current rates</div><div class="fin-value"><?= h(pct($cRate)) ?>% + <?= h(pct($fRate)) ?>%</div><div class="fin-note">commission + service fee</div></div>
        </div>

        <form method="POST" class="fin-box">
            <h2>Rates, time limits and payment instructions</h2>
            <p class="small">The <strong>commission</strong> is a percentage of the host's price, taken out of what the guest pays the host. The <strong>service fee</strong> is a percentage added to the guest's total. Both go to you. They apply to <em>new</em> bookings only.</p>
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="save_rates">
            <label class="fin-field" for="cmode">How commission is collected</label>
            <select class="fin-input" style="max-width:420px" id="cmode" name="commission_mode">
                <option value="upfront"<?= $cMode === 'upfront' ? ' selected' : '' ?>>With the booking: the guest pays your commission to you when booking</option>
                <option value="invoice"<?= $cMode === 'invoice' ? ' selected' : '' ?>>Invoice after the stay: the guest pays the host in full, the host pays you the commission later</option>
            </select>
            <label class="fin-field" for="cbase">Commission is a percentage of</label>
            <select class="fin-input" style="max-width:420px" id="cbase" name="commission_base">
                <option value="stay_extras"<?= $cBase === 'stay_extras' ? ' selected' : '' ?>>Stay + host extras (the host's whole price)</option>
                <option value="stay_only"<?= $cBase === 'stay_only' ? ' selected' : '' ?>>The accommodation price only (nights × price per night)</option>
            </select>
            <label class="fin-field" for="idd">Invoice mode: days after check-out the host has to pay</label>
            <input class="fin-input" style="max-width:140px" id="idd" name="invoice_due_days" value="<?= (int)invoiceDueDays() ?>" inputmode="numeric">
            <label class="fin-field"><input type="checkbox" name="block_overdue_hosts" value="1"<?= blockOverdueHosts() ? ' checked' : '' ?>> Invoice mode: pause new bookings for hosts with overdue commission</label>
            <label class="fin-field" for="cr">Commission (% of the base chosen above)</label>
            <input class="fin-input" style="max-width:140px" id="cr" name="commission_rate" value="<?= h(pct($cRate)) ?>" inputmode="decimal" required>
            <label class="fin-field" for="sf">Guest service fee (% added to the total)</label>
            <input class="fin-input" style="max-width:140px" id="sf" name="service_fee" value="<?= h(pct($fRate)) ?>" inputmode="decimal" required>
            <h2 style="margin-top:18px">Booking time limits</h2>
            <label class="fin-field" for="hh">Hours a guest has to enter payment details before the dates are released</label>
            <input class="fin-input" style="max-width:140px" id="hh" name="hold_hours" value="<?= (int)bookingHoldHours() ?>" inputmode="numeric" required>
            <label class="fin-field" for="rh">Hours a host has to accept or decline a request</label>
            <input class="fin-input" style="max-width:140px" id="rh" name="reply_hours" value="<?= (int)hostReplyHours() ?>" inputmode="numeric" required>
            <label class="fin-field" for="fc">Free cancellation until this many hours before check-in</label>
            <input class="fin-input" style="max-width:140px" id="fc" name="free_cancel_hours" value="<?= (int)freeCancelHours() ?>" inputmode="numeric" required>
            <label class="fin-field" for="ins">Where guests send your share (shown on the payment page)</label>
            <textarea class="fin-input" id="ins" name="instructions" rows="3" maxlength="500"><?= h(siteSettingGet('platform_pay_instructions', COMMISSION_PAY_INSTRUCTIONS)) ?></textarea>
            <div class="fin-actions"><button class="fin-btn" type="submit">Save</button></div>
        </form>

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
            <h2>Commission owed by hosts (invoice mode)</h2>
            <div class="fin-scroll"><table class="fin-table"><thead><tr><th>Host</th><th class="num">Due after stays</th><th class="num">Paid</th><th class="num">Outstanding</th><th class="num">Overdue</th></tr></thead><tbody>
            <?php foreach ($invoiceHosts as $ih): ?><tr><td><?= h($ih['full_name']) ?></td><td class="num"><?= kes($ih['due']) ?></td><td class="num"><?= kes($ih['paid']) ?></td><td class="num"><strong><?= kes($ih['outstanding']) ?></strong></td><td class="num"><?= $ih['overdue'] > 0 ? '<span class="fin-pill void">' . kes($ih['overdue']) . '</span>' : '–' ?></td></tr><?php endforeach; ?>
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

        <div class="fin-box">
            <h2>Earned by host's bookings (received only)</h2>
            <div class="fin-scroll"><table class="fin-table">
                <thead><tr><th>Host</th><th class="num">Bookings</th><th class="num">Commission</th><th class="num">Service fees</th><th class="num">Total received</th></tr></thead>
                <tbody>
                <?php if (!$perHost): ?><tr><td colspan="5">Nothing received yet.</td></tr><?php endif; ?>
                <?php foreach ($perHost as $h): ?><tr><td><?= h($h['full_name']) ?></td><td class="num"><?= (int)$h['n'] ?></td><td class="num"><?= kes($h['commission']) ?></td><td class="num"><?= kes($h['fee']) ?></td><td class="num"><strong><?= kes($h['total']) ?></strong></td></tr><?php endforeach; ?>
                </tbody>
            </table></div>
        </div>

        <div class="fin-box">
            <h2>Platform payments</h2>
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
<?php appShellEnd(); ?>
</body>
</html>
