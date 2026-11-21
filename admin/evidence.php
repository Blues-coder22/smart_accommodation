<?php
// Printable dispute evidence pack for one booking. Admins only. Opening it is logged in the booking history.
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';
require_once '../includes/payment_audit.php';
require_once '../includes/booking_events.php';
require_once '../includes/email.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');
ensurePaymentAuditTable($pdo); ensureBookingEventsTable($pdo); ensureCommissionTables($pdo);

$bid = bookingIdFromRef($_GET['ref'] ?? '');
$st = $pdo->prepare("SELECT b.*, a.title, a.location_name, t.full_name AS tourist_name, t.email AS tourist_email, t.phone_number AS tourist_phone,
        hu.user_id AS host_id, hu.full_name AS host_name, hu.email AS host_email, hu.phone_number AS host_phone
    FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id JOIN users t ON t.user_id = b.tourist_id JOIN users hu ON hu.user_id = a.host_id
    WHERE b.booking_id = ?");
$st->execute([$bid]); $b = $st->fetch(PDO::FETCH_ASSOC);
if (!$b) { http_response_code(404); exit('Booking not found.'); }
$pay = $pdo->prepare("SELECT * FROM payments WHERE booking_id = ? ORDER BY payment_id"); $pay->execute([$bid]); $pays = $pay->fetchAll(PDO::FETCH_ASSOC);
$aud = $pdo->prepare("SELECT pa.*, u.full_name FROM payment_audit pa LEFT JOIN users u ON u.user_id = pa.actor_id WHERE pa.payment_id IN (SELECT payment_id FROM payments WHERE booking_id = ?) ORDER BY pa.audit_id");
$aud->execute([$bid]); $audit = $aud->fetchAll(PDO::FETCH_ASSOC);
$ev = $pdo->prepare("SELECT e.*, u.full_name FROM booking_events e LEFT JOIN users u ON u.user_id = e.actor_id WHERE e.booking_id = ? ORDER BY e.event_id");
$ev->execute([$bid]); $events = $ev->fetchAll(PDO::FETCH_ASSOC);
$cm = $pdo->prepare("SELECT * FROM platform_payments WHERE booking_id = ?"); $cm->execute([$bid]); $pp = $cm->fetch(PDO::FETCH_ASSOC);
logBookingEvent($pdo, $bid, (int)$_SESSION['user_id'], 'evidence_viewed', 'Evidence pack opened by admin');
$ref = bookingLabel($bid);
?>
<!DOCTYPE html>
<html lang="en" translate="no" class="notranslate">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evidence pack <?= h($ref) ?> — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/finance.css">
    <style>.ev-wrap{max-width:900px;margin:20px auto;padding:0 16px}.ev-wrap h2{margin-top:0}.ev-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:640px){.ev-two{grid-template-columns:1fr}}@media print{.ev-wrap{max-width:none;margin:0;padding:0}}</style>
</head>
<body>
<main class="ev-wrap" id="main-content">
    <div class="no-print fin-actions" style="margin-bottom:12px"><button class="fin-btn" type="button" onclick="window.print()">Print or save as PDF</button><a class="fin-btn ghost" href="revenue.php">Back to revenue</a><a class="fin-btn ghost" href="payments.php">Payments</a></div>
    <h1>Dispute evidence pack — <?= h($ref) ?></h1>
    <p class="small">Generated <?= h(date('j M Y, H:i')) ?> by <?= h($_SESSION['full_name'] ?? 'Administrator') ?> from the StayExplore Kenya system records. Opening this pack is recorded in the booking history.</p>

    <div class="fin-box"><h2>Booking</h2>
        <p><strong><?= h($b['title']) ?></strong>, <?= h($b['location_name']) ?><br>
        <?= h($b['check_in_date']) ?> to <?= h($b['check_out_date']) ?> · <?= (int)$b['num_guests'] ?> guest(s) · <?= kes($b['total_amount']) ?> · booking status: <strong><?= h($b['status']) ?></strong><br>
        Requested: <?= h($b['created_at']) ?></p>
        <div class="ev-two">
            <div><strong>Guest</strong><br><?= h($b['tourist_name']) ?><br><?= h($b['tourist_email']) ?><br><?= h($b['tourist_phone'] ?: '') ?></div>
            <div><strong>Host</strong><br><?= h($b['host_name']) ?><br><?= h($b['host_email']) ?><br><?= h($b['host_phone'] ?: '') ?></div>
        </div>
    </div>

    <div class="fin-box"><h2>Payment records</h2>
        <?php if (!$pays): ?><p>No payment was recorded for this booking.</p><?php endif; ?>
        <?php foreach ($pays as $p): ?>
            <p><strong><?= h(receiptNumber($p['payment_id'])) ?></strong> · <?= kes($p['amount']) ?> · <?= h(paymentMethodLabel($p['payment_method'])) ?> · reference <strong><?= h($p['payment_reference'] ?: 'none') ?></strong> · status <strong><?= h($p['payment_status']) ?></strong> · entered <?= h($p['payment_date']) ?><br>
            <?php if ($p['payment_status'] === 'confirmed'): ?>Receipt verification code: <span style="font-family:monospace"><?= h(receiptVerificationCode($p)) ?></span> · <a href="../receipt.php?as=admin&amp;pid=<?= h(opaqueId('pay', $p['payment_id'])) ?>" target="_blank" rel="noopener">Open receipt</a><?php endif; ?>
            <?php if (!empty($p['payment_proof'])): ?> · <a href="../proof.php?as=admin&amp;type=payment&amp;ref=<?= h(opaqueId('pay', $p['payment_id'])) ?>" target="_blank" rel="noopener">View the guest's payment screenshot</a> <span class="small">(a screenshot alone is not proof of payment; check the host's statement)</span><?php endif; ?></p>
        <?php endforeach; ?>
    </div>

    <div class="fin-box"><h2>Payment history (who changed what)</h2>
        <div class="fin-scroll"><table class="fin-table"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Change</th><th>Reason / note</th></tr></thead><tbody>
        <?php if (!$audit): ?><tr><td colspan="5">No changes recorded.</td></tr><?php endif; ?>
        <?php foreach ($audit as $r): ?><tr><td><?= h($r['created_at']) ?></td><td><?= h($r['full_name'] ?: 'System') ?> (<?= h($r['actor_role']) ?>)</td><td><?= h($r['action']) ?></td><td><?= h(($r['old_status'] ?: '—') . ' → ' . ($r['new_status'] ?: '—')) ?></td><td><?= h($r['note'] ?: '') ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>

    <div class="fin-box"><h2>Booking history</h2>
        <div class="fin-scroll"><table class="fin-table"><thead><tr><th>When</th><th>Who</th><th>Event</th><th>Note</th></tr></thead><tbody>
        <?php if (!$events): ?><tr><td colspan="4">No events recorded.</td></tr><?php endif; ?>
        <?php foreach ($events as $r): ?><tr><td><?= h($r['created_at']) ?></td><td><?= h($r['full_name'] ?: 'System') ?></td><td><?= h($r['event']) ?></td><td><?= h($r['note'] ?: '') ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>

    <div class="fin-box"><h2>Payment to the platform (service fee + commission)</h2>
        <?php if (!$pp): ?><p>None recorded (older booking, or the guest has not entered it yet).</p>
        <?php else: ?><p><strong><?= kes($pp['amount']) ?></strong> = service fee <?= kes($pp['service_fee']) ?> + commission <?= kes($pp['commission_amount']) ?> · <?= h($pp['method'] === 'mpesa' ? 'M-Pesa' : 'Bank transfer') ?> · reference <strong><?= h($pp['reference']) ?></strong> · status <strong><?= h($pp['status']) ?></strong>
            <?= $pp['reviewed_at'] ? ' · checked ' . h($pp['reviewed_at']) : '' ?><?= $pp['review_note'] ? ' · note: ' . h($pp['review_note']) : '' ?><br>
            <?php if ($pp['status'] === 'confirmed'): ?>Verification code: <span style="font-family:monospace"><?= h(platformReceiptVerificationCode($pp)) ?></span> · <a href="../platform_receipt.php?as=admin&amp;ref=<?= h(opaqueId('plat', $pp['platform_payment_id'])) ?>" target="_blank" rel="noopener">Open receipt</a><?php endif; ?>
            <?php if ($pp['proof_file']): ?> · <a href="../proof.php?as=admin&amp;type=platform&amp;ref=<?= h(opaqueId('plat', $pp['platform_payment_id'])) ?>" target="_blank" rel="noopener">View the guest's screenshot</a><?php endif; ?></p><?php endif; ?>
    </div>
</main>
</body>
</html>
