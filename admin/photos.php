<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/media_store.php';

if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'repair') {
        $r = mediaSelfHeal($pdo, true) ?: [];
        setFlash('success', 'Checked. Restored ' . (int)($r['restored'] ?? 0) . ' missing photo(s), saved ' . (int)($r['backed_up'] ?? 0)
            . ' new photo(s) in the database, removed ' . (int)($r['duplicates_removed'] ?? 0) . ' duplicate(s).');
    } elseif ($action === 'remove_broken') {
        $n = mediaRemoveBroken($pdo);
        setFlash('success', $n . ' broken photo record(s) removed. Their listings and attractions can get new photos.');
    }
    redirect('photos.php');
}

$st = mediaStatus($pdo);
$mb = round($st['db_bytes'] / 1048576, 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Photo safekeeping — Admin</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.help{background:#fff;border:1px solid #e4e1d8;border-radius:10px;padding:14px 18px;font-size:13px;color:#4a5249;line-height:1.7;margin-bottom:16px}
.ph-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff}
.ph-table th{text-align:left;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#565c55;padding:10px 12px;border-bottom:1px solid #e4e1d8}
.ph-table td{padding:10px 12px;border-bottom:1px solid #eee}
.ph-actions{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}
.ph-actions button{padding:9px 16px;border-radius:8px;border:1px solid #0d3b2e;background:#0d3b2e;color:#fff;font-size:13px;cursor:pointer}
.ph-actions button.alt{background:#fff;color:#8a2b22;border-color:#e0a39b}
</style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <div class="page-date"><?= strtoupper(date('l, j F')) ?></div>
        <h1 class="page-title">Photo safekeeping</h1>
        <p class="page-sub">Listing and attraction photos are copied into the database, so a new version of the website no longer loses them.</p>

        <?= showFlash() ?>

        <div class="stats-grid">
            <div class="stat-card accent"><div class="stat-label">Photos in use</div><div class="stat-value"><?= (int)$st['photos'] ?></div><div class="stat-note">Listings and attractions</div></div>
            <div class="stat-card"><div class="stat-label">Saved in database</div><div class="stat-value"><?= (int)$st['in_db'] ?></div><div class="stat-note"><?= $mb ?> MB</div></div>
            <div class="stat-card <?= $st['restorable'] ? 'warn' : '' ?>"><div class="stat-label">Missing, can be restored</div><div class="stat-value"><?= count($st['restorable']) ?></div><div class="stat-note">Restored automatically</div></div>
            <div class="stat-card <?= $st['broken'] ? 'warn' : '' ?>"><div class="stat-label">Lost for good</div><div class="stat-value"><?= count($st['broken']) ?></div><div class="stat-note">Need a new upload</div></div>
        </div>

        <div class="help">
            <strong>How it works.</strong> Every photo is copied into the database the first time the site sees it. If a photo file is missing from the
            <code>uploads</code> folder (for example after switching to a new version), it is written back from the database on the next page load.
            Photos that are identical inside the same listing or attraction are reduced to one, and photos you delete stay deleted.<br>
            <strong>Photos already lost before this feature existed</strong> cannot be recovered from the database. If you still have the old
            <code>uploads</code> folder, copy its <code>accommodations</code> and <code>attractions</code> files into the new <code>uploads</code> folder, then press
            <em>Check and repair now</em>: they are saved in the database for good. Otherwise upload them once more, or remove the broken records below.
        </div>

        <form method="post" class="ph-actions">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" name="action" value="repair">Check and repair now</button>
            <?php if ($st['broken']): ?>
            <button type="submit" name="action" value="remove_broken" class="alt" onclick="return confirm('Remove the database records of the <?= count($st['broken']) ?> lost photo(s)? This cannot be undone.');">Remove broken records</button>
            <?php endif; ?>
        </form>

        <?php if ($st['broken']): ?>
        <h2 style="font-size:16px;margin:24px 0 8px">Lost photos (no file and no copy in the database)</h2>
        <div style="overflow-x:auto"><table class="ph-table">
            <thead><tr><th>Belongs to</th><th>File name</th></tr></thead>
            <tbody>
            <?php foreach ($st['broken'] as $m): ?>
                <tr>
                    <td><?php if ($m['table'] === 'accommodation_photos'): ?><a href="listings.php">Listing #<?= (int)$m['owner'] ?></a>
                        <?php else: ?><a href="attractions.php?edit=<?= (int)$m['owner'] ?>">Attraction #<?= (int)$m['owner'] ?></a><?php endif; ?></td>
                    <td><?= h(basename($m['rel'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
<?php appShellEnd(); ?>
</body>
</html>
