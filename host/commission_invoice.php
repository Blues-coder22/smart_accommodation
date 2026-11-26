<?php
/** Printable commission invoice (host -> StayExplore Kenya). A host can open only their own invoices; admins can open any. */
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
ensureCommissionTables($pdo);
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['host', 'admin'], true)) { http_response_code(403); exit('Not allowed.'); }
$no = (string)($_GET['no'] ?? '');
$q = $pdo->prepare("SELECT ci.*, b.check_in_date, b.check_out_date, b.booking_id, a.title, u.full_name AS host_name, u.email AS host_email
    FROM commission_invoices ci JOIN bookings b ON b.booking_id = ci.booking_id JOIN accommodations a ON a.accommodation_id = b.accommodation_id JOIN users u ON u.user_id = ci.host_id
    WHERE ci.invoice_no = ? AND ci.status <> 'void'" . ($role === 'host' ? ' AND ci.host_id = ?' : ''));
$q->execute($role === 'host' ? [$no, (int)$_SESSION['user_id']] : [$no]);
$i = $q->fetch(PDO::FETCH_ASSOC);
if (!$i) { http_response_code(404); exit('Invoice not found.'); }
$led = hostCommissionLedger($pdo, (int)$i['host_id']);
$state = 'unpaid';
foreach ($led['items'] as $it) if ((int)$it['invoice_id'] === (int)$i['invoice_id']) $state = $it['state'];
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Invoice <?= h($i['invoice_no']) ?> — StayExplore Kenya</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script><link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script>
<style>.inv{max-width:720px;margin:24px auto;padding:28px;background:var(--surface-2);border:1px solid var(--border);border-radius:8px}.inv h1{font-size:24px;margin:0 0 4px}.inv dl{display:grid;grid-template-columns:160px 1fr;gap:8px 16px;margin:18px 0}.inv dt{color:var(--text-secondary);font-size:13px}.inv dd{margin:0}.inv .tot{font-size:22px;font-weight:600}@media(max-width:480px){.inv{margin:0;border-radius:0;padding:18px}.inv dl{grid-template-columns:1fr}}@media print{.no-print{display:none!important}.inv{border:0}}</style></head>
<body><div class="inv">
<p class="no-print"><a href="<?= $role === 'admin' ? '../admin/revenue.php' : 'commission.php' ?>">&larr; Back</a> &middot; <a href="#" onclick="window.print();return false">Print</a></p>
<h1>Commission invoice</h1>
<div class="small">StayExplore Kenya &middot; <?= h($i['invoice_no']) ?> &middot; issued <?= h(date('j M Y', strtotime($i['issued_at']))) ?></div>
<dl>
<dt>Billed to</dt><dd><?= h($i['host_name']) ?> (<?= h($i['host_email']) ?>)</dd>
<dt>Stay</dt><dd><?= h($i['title']) ?>, <?= h($i['check_in_date']) ?> to <?= h($i['check_out_date']) ?> &middot; booking <?= h(bookingLabel($i['booking_id'])) ?></dd>
<?php if ((float)$i['base_amount'] > 0): ?><dt>Commissionable amount</dt><dd><?= kes($i['base_amount']) ?></dd><?php endif; ?>
<?php if ($i['rate'] !== null): ?><dt>Commission rate</dt><dd><?= h(pct($i['rate'])) ?>%</dd><?php endif; ?>
<dt>Amount due</dt><dd class="tot"><?= kes($i['amount']) ?></dd>
<dt>Due date</dt><dd><?= h(date('j M Y', strtotime($i['due_date']))) ?></dd>
<dt>Status</dt><dd><?= h(['paid' => 'Paid', 'partial' => 'Part-paid', 'unpaid' => 'Unpaid'][$state] ?? $state) ?></dd>
</dl>
<p class="small">How to pay: <?= nl2br(h(commissionPayInstructions())) ?> Then report the reference on your Commission page.</p>
<p class="small">Guests pay you directly for their accommodation. This is your agreed platform commission, payable separately to StayExplore Kenya.</p>
</div></body></html>
