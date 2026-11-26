<?php
// Public page: anyone can check a StayExplore Kenya certificate number. Shows only the name, kind and date, never private data.
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/certificates.php';
ensureCertificatesTable($pdo);
$no = strtoupper(trim((string)($_GET['no'] ?? '')));
$c = null; $state = null;
if ($no !== '') {
    $q = $pdo->prepare('SELECT * FROM certificates WHERE cert_no = ?'); $q->execute([$no]); $c = $q->fetch(PDO::FETCH_ASSOC);
    if ($c) $state = certificateIsValid($pdo, $c);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check a certificate — StayExplore Kenya</title>
    <link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/ui.css"><script src="assets/js/ui.js" defer></script>
    <style>.vc{max-width:560px;margin:40px auto;padding:0 16px}.vc form{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}.vc input{flex:1;min-width:200px;padding:10px;border:1px solid #9aa39d;border-radius:6px;font:inherit;text-transform:uppercase}.vc button{padding:10px 18px;border:0;border-radius:6px;background:var(--btn);color:#fff;font:inherit;font-weight:600;cursor:pointer}.vc .ok{background:#e4f4ec;border:1px solid var(--accent)}.vc .no{background:#fbeaea;border:1px solid var(--bad-fg)}.vc .box{padding:14px 18px;border-radius:8px;margin-top:12px}</style>
<link rel="stylesheet" href="assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="assets/js/se-theme.js"></script><script src="assets/js/se-i18n.js"></script></head>
<body>
<main class="vc" id="main-content">
    <h1>Check a certificate</h1>
    <p>Enter the certificate number printed on a StayExplore Kenya certificate (for example HC-XXXXXXXXXX).</p>
    <form method="GET"><label class="sr-only" for="no">Certificate number</label><input id="no" name="no" value="<?= h($no) ?>" maxlength="20" required placeholder="HC-XXXXXXXXXX"><button type="submit">Check</button></form>
    <?php if ($no !== '' && !$c): ?><div class="box no"><strong>Not found.</strong> No certificate with that number was issued by StayExplore Kenya.</div>
    <?php elseif ($c): [$valid, $why] = $state; ?>
        <div class="box <?= $valid ? 'ok' : 'no' ?>">
            <strong><?= $valid ? 'Valid certificate' : 'This certificate is no longer valid' ?></strong><br>
            <?= h($c['kind'] === 'host' ? 'Host verification' : 'Listing approval') ?> · <?= h($c['cert_no']) ?><br>
            Issued to: <strong><?= h($c['issued_to']) ?></strong><?= $c['detail'] ? ' · ' . h($c['detail']) : '' ?><br>
            Issued on <?= h(date('j F Y', strtotime($c['issued_at']))) ?><br>
            <span class="small"><?= h($why) ?></span>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
