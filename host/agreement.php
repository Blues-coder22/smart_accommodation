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

$accepted = hostAgreementAccepted($pdo, $host_id);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$accepted) {
    requireCsrf();
    if (empty($_POST['agree'])) {
        setFlash('error', 'Please tick the box to confirm you have read and accept the agreement.');
    } else {
        // Store the exact wording the host saw, so the record stays true even if the terms change later.
        $pdo->prepare('INSERT INTO host_agreements (host_id, version, terms_snapshot, accepted_ip) VALUES (?,?,?,?)')
            ->execute([$host_id, HOST_AGREEMENT_VERSION, hostAgreementText(), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        setFlash('success', 'Thank you. Your acceptance has been recorded. You can now add listings.');
        redirect('add_listing.php');
    }
    redirect('agreement.php');
}
$rec = hostAgreementRecord($pdo, $host_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host agreement — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <link rel="stylesheet" href="../assets/css/finance.css">
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <?= showFlash() ?>
        <div class="page-tag">Hosting</div>
        <h1 class="page-title">Host agreement</h1>
        <?php if ($accepted && $rec): ?>
            <p class="page-sub">You accepted this agreement on <strong><?= h(date('j M Y, H:i', strtotime($rec['accepted_at']))) ?></strong> (version <?= h($rec['version']) ?>). This is the wording you accepted.</p>
            <div class="fin-box"><div style="white-space:pre-line;line-height:1.6"><?= h($rec['terms_snapshot']) ?></div></div>
            <?php if (trim($rec['terms_snapshot']) !== trim(hostAgreementText())): ?>
                <p class="small">The platform's current terms differ from this record (for example a new rate). Changes apply to new bookings only, and you will be told before they take effect.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="page-sub">Please read this before you list a property. You must accept it once before a listing can be added or approved.</p>
            <div class="fin-box"><div style="white-space:pre-line;line-height:1.6"><?= h(hostAgreementText()) ?></div></div>
            <form method="POST" class="fin-box">
                <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                <label class="fin-field"><input type="checkbox" name="agree" value="1" required> I have read this agreement and I accept it on behalf of myself and my properties.</label>
                <div class="fin-actions"><button class="fin-btn" type="submit">Accept and continue</button></div>
            </form>
        <?php endif; ?>
<?php appShellEnd(); ?>
</body>
</html>
