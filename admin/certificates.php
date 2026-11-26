<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/certificates.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');
ensureCertificatesTable($pdo);
$hosts = $pdo->query("SELECT v.verification_id, v.legal_name, v.decided_at, u.role, c.cert_no FROM host_verifications v JOIN users u ON u.user_id = v.host_id
    LEFT JOIN certificates c ON c.kind = 'host' AND c.subject_id = v.verification_id WHERE v.status = 'verified' ORDER BY v.decided_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$lists = $pdo->query("SELECT a.accommodation_id, a.title, a.location_name, u.full_name, c.cert_no FROM accommodations a JOIN users u ON u.user_id = a.host_id
    LEFT JOIN certificates c ON c.kind = 'listing' AND c.subject_id = a.accommodation_id WHERE a.status = 'approved' ORDER BY a.accommodation_id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificates — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
    <link rel="stylesheet" href="../assets/css/finance.css">
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <div class="page-tag">Admin</div>
        <h1 class="page-title">Certificates</h1>
        <p class="page-sub">Print a certificate for a verified host or guest, or for an approved listing. Each has a number anyone can check at <code>verify_certificate.php</code>. A certificate stops being valid if the host is later un-verified or the listing is no longer approved.</p>
        <div class="fin-box"><h2>Verified hosts and guests</h2>
            <div class="fin-scroll"><table class="fin-table"><thead><tr><th>Name</th><th>Role</th><th>Verified on</th><th>Certificate no.</th><th></th></tr></thead><tbody>
            <?php if (!$hosts): ?><tr><td colspan="5">Nobody is verified yet.</td></tr><?php endif; ?>
            <?php foreach ($hosts as $r): ?><tr><td><?= h($r['legal_name']) ?></td><td><?= h($r['role'] === 'tourist' ? 'Guest' : 'Host') ?></td><td><?= h($r['decided_at'] ? date('j M Y', strtotime($r['decided_at'])) : '') ?></td><td><?= h($r['cert_no'] ?: 'not printed yet') ?></td>
                <td><a class="fin-btn ghost" href="../certificate.php?as=admin&amp;kind=host&amp;ref=<?= h(opaqueId('hv', $r['verification_id'])) ?>" target="_blank" rel="noopener">Print certificate</a></td></tr><?php endforeach; ?>
            </tbody></table></div></div>
        <div class="fin-box"><h2>Approved listings</h2>
            <div class="fin-scroll"><table class="fin-table"><thead><tr><th>Listing</th><th>Host</th><th>Certificate no.</th><th></th></tr></thead><tbody>
            <?php if (!$lists): ?><tr><td colspan="4">No approved listings yet.</td></tr><?php endif; ?>
            <?php foreach ($lists as $r): ?><tr><td><?= h($r['title']) ?><br><span class="small"><?= h($r['location_name']) ?></span></td><td><?= h($r['full_name']) ?></td><td><?= h($r['cert_no'] ?: 'not printed yet') ?></td>
                <td><a class="fin-btn ghost" href="../certificate.php?as=admin&amp;kind=listing&amp;ref=<?= h(opaqueId('lst', $r['accommodation_id'])) ?>" target="_blank" rel="noopener">Print certificate</a></td></tr><?php endforeach; ?>
            </tbody></table></div></div>
<?php appShellEnd(); ?>
</body>
</html>
