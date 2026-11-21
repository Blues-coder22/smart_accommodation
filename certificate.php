<?php
// Printable certificate. Shared page: ?as=admin|host. Admin: kind=host|listing and ref (token). Host: kind=host (own verification) or kind=listing&ref (own listing).
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/db.php';
require_once 'includes/certificates.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
ensureCertificatesTable($pdo);
$uid = (int)$_SESSION['user_id']; $isAdmin = hasRole('admin'); $isHost = hasRole('host');
if (!$isAdmin && !$isHost) { http_response_code(404); exit('Certificate not found.'); }
$kind = ($_GET['kind'] ?? 'host') === 'listing' ? 'listing' : 'host';
$id = opaqueIdDecode($kind === 'host' ? 'hv' : 'lst', $_GET['ref'] ?? '');
$data = null;
if ($kind === 'host') {
    if (!$id && $isHost) { $q = $pdo->prepare("SELECT verification_id FROM host_verifications WHERE host_id = ? AND status = 'verified' ORDER BY decided_at DESC LIMIT 1"); $q->execute([$uid]); $id = (int)$q->fetchColumn(); }
    $q = $pdo->prepare("SELECT v.*, u.full_name, u.role AS user_role FROM host_verifications v JOIN users u ON u.user_id = v.host_id WHERE v.verification_id = ? AND v.status = 'verified'"); $q->execute([$id]); $data = $q->fetch(PDO::FETCH_ASSOC);
    if ($data && !$isAdmin && (int)$data['host_id'] !== $uid) $data = null;
} else {
    $q = $pdo->prepare("SELECT a.*, u.full_name FROM accommodations a JOIN users u ON u.user_id = a.host_id WHERE a.accommodation_id = ? AND a.status = 'approved'"); $q->execute([$id]); $data = $q->fetch(PDO::FETCH_ASSOC);
    if ($data && !$isAdmin && (int)$data['host_id'] !== $uid) $data = null;
}
if (!$data) { http_response_code(404); exit('Certificate not available. It can only be printed for a verified host or an approved listing.'); }
$cert = $kind === 'host'
    ? issueCertificate($pdo, 'host', $data['verification_id'], $data['legal_name'], $data['id_type'] . ' checked', $uid)
    : issueCertificate($pdo, 'listing', $data['accommodation_id'], $data['title'], 'Host: ' . $data['full_name'], $uid);
$verifyUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') . '/verify_certificate.php?no=' . $cert['cert_no'];
$signer = $isAdmin ? ($_SESSION['full_name'] ?? 'Administrator') : 'Administrator';
$back = $isAdmin ? 'admin/' . ($kind === 'host' ? 'certificates.php' : 'certificates.php') : 'host/' . ($kind === 'host' ? 'profile.php' : 'listings.php');
$isGuestCert = $kind === 'host' && ($data['user_role'] ?? 'host') === 'tourist';
?>
<!DOCTYPE html>
<html lang="en" translate="no" class="notranslate">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate <?= h($cert['cert_no']) ?> — StayExplore Kenya</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/finance.css">
    <style>
        body{background:#f6f1e7}
        .ct-bar{max-width:820px;margin:16px auto 0;display:flex;gap:10px;flex-wrap:wrap;padding:0 8px}
        .ct{max-width:820px;margin:16px auto;background:#fff;border:10px double #126b4e;padding:44px 52px;text-align:center}
        .ct .brand{font-size:15px;letter-spacing:.2em;text-transform:uppercase;color:#126b4e;font-weight:700}
        .ct h1{font-family:Georgia,serif;font-size:36px;margin:14px 0 4px}
        .ct .lead{color:#565c55;margin:0 0 22px}
        .ct .name{font-family:Georgia,serif;font-size:32px;border-bottom:1px solid #9aa39d;display:inline-block;padding:0 24px 6px;margin:6px 0 18px}
        .ct p{line-height:1.7;max-width:620px;margin:10px auto}
        .ct .meta{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-top:34px;text-align:left;font-size:14px}
        .ct .sig{border-top:1px solid #333;padding-top:6px;min-width:220px;margin-top:40px;display:inline-block}
        .ct .fine{font-size:12px;color:#565c55;margin-top:26px}
        @media print{body{background:#fff}.ct-bar{display:none}.ct{margin:0;max-width:none;border-width:8px}}
        @media(max-width:600px){.ct{padding:24px 18px}.ct h1{font-size:26px}}
    </style>
</head>
<body>
<div class="ct-bar no-print"><button class="fin-btn" type="button" onclick="window.print()">Print or save as PDF</button><a class="fin-btn ghost" href="<?= h($back) ?>">Back</a></div>
<main class="ct" id="main-content">
    <div class="brand">StayExplore Kenya</div>
    <h1><?= $kind === 'host' ? ($isGuestCert ? 'Certificate of Guest Verification' : 'Certificate of Host Verification') : 'Certificate of Listing Approval' ?></h1>
    <p class="lead">Smart Accommodation and Attraction Recommendation System</p>
    <p>This is to certify that</p>
    <div class="name"><?= h($kind === 'host' ? $data['legal_name'] : $data['title']) ?></div>
    <?php if ($kind === 'host'): ?>
        <p>has been <strong>verified as a <?= $isGuestCert ? 'guest' : 'host' ?></strong> on StayExplore Kenya. The admin checked the identity document (<?= h($data['id_type']) ?>) and approved the verification on <strong><?= h(date('j F Y', strtotime($data['decided_at'] ?: $cert['issued_at']))) ?></strong>.</p>
    <?php else: ?>
        <p>listed at <strong><?= h($data['location_name'] ?: 'Kenya') ?></strong> by host <strong><?= h($data['full_name']) ?></strong>, has been <strong>reviewed and approved</strong> by the admin for publication on StayExplore Kenya.</p>
    <?php endif; ?>
    <div class="meta">
        <div>Certificate no.<br><strong style="font-size:18px;letter-spacing:.06em"><?= h($cert['cert_no']) ?></strong><br>Issued <?= h(date('j F Y', strtotime($cert['issued_at']))) ?></div>
        <div>Check it online:<br><strong><?= h($verifyUrl) ?></strong></div>
    </div>
    <div class="sig"><?= h($signer) ?><br><span class="small">Administrator, StayExplore Kenya</span></div>
    <p class="fine">This certificate confirms a check made by StayExplore Kenya only. It is not a business licence, a safety inspection or a guarantee. It is valid only while the <?= $kind === 'host' ? 'verification stands' : 'listing remains approved' ?>; check the number online to confirm.</p>
</main>
</body>
</html>
