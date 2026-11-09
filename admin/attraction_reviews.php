<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/attraction_reviews.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');
ensureAttractionReviewsTable($pdo);

// Hide / restore / delete an attraction rating. These never affect a stay's reviews or the Smart score.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $rid = (int)($_POST['review_id'] ?? 0);
    $act = sanitize($_POST['action'] ?? '');
    if ($act === 'hide')      { $pdo->prepare('UPDATE attraction_reviews SET is_hidden = 1 WHERE review_id = ?')->execute([$rid]); setFlash('success', 'Rating hidden from the public.'); }
    elseif ($act === 'show')  { $pdo->prepare('UPDATE attraction_reviews SET is_hidden = 0 WHERE review_id = ?')->execute([$rid]); setFlash('success', 'Rating restored.'); }
    elseif ($act === 'delete'){ $pdo->prepare('DELETE FROM attraction_reviews WHERE review_id = ?')->execute([$rid]); setFlash('success', 'Rating deleted.'); }
    redirect('attraction_reviews.php');
}

$rows = $pdo->query("SELECT r.*, a.name AS attraction_name, u.full_name, u.email
    FROM attraction_reviews r
    JOIN attractions a ON a.attraction_id = r.attraction_id
    JOIN users u ON u.user_id = r.tourist_id
    ORDER BY r.created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attraction ratings — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .rt-card{background:var(--surface-2,#fff);border:1px solid var(--border,#ddd);border-radius:8px;padding:16px 20px;margin-bottom:12px}
        .rt-card.hidden{opacity:.65;border-style:dashed}
        .rt-actions{display:flex;gap:8px;margin-top:10px}
        .rt-actions button{padding:6px 14px;font-size:13px;border-radius:4px;border:1px solid var(--border-strong,#bbb);background:#fff;cursor:pointer}
        .rt-actions .danger{color:#b91c1c;border-color:#b91c1c}
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <?= showFlash() ?>
        <div class="page-tag">Admin</div>
        <h1 class="page-title">Attraction ratings</h1>
        <p class="page-sub">Ratings come from guests with a completed stay within <?= (int)ATTRACTION_RATING_RADIUS_KM ?> km. Hiding one removes it from the public page and the average; it never touches stay reviews or the Smart score.</p>
        <?php if (!$rows): ?>
            <p>No attraction ratings yet.</p>
        <?php else: foreach ($rows as $r): ?>
            <div class="rt-card<?= $r['is_hidden'] ? ' hidden' : '' ?>">
                <strong><?= h($r['attraction_name']) ?></strong> —
                <span aria-label="<?= (int)$r['rating'] ?> out of 5"><?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']) ?></span>
                <span class="small"> by <?= h($r['full_name']) ?> (<?= h($r['email']) ?>) · <?= h(date('j M Y', strtotime($r['created_at']))) ?><?= $r['is_hidden'] ? ' · HIDDEN' : '' ?></span>
                <?php if (!empty($r['comment'])): ?><p style="margin:8px 0 0"><?= nl2br(h($r['comment'])) ?></p><?php endif; ?>
                <form method="POST" class="rt-actions">
                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
                    <input type="hidden" name="review_id" value="<?= (int)$r['review_id'] ?>">
                    <button name="action" value="<?= $r['is_hidden'] ? 'show' : 'hide' ?>" type="submit"><?= $r['is_hidden'] ? 'Restore' : 'Hide' ?></button>
                    <button name="action" value="delete" type="submit" class="danger" onclick="return confirm('Delete this rating permanently?')">Delete</button>
                </form>
            </div>
        <?php endforeach; endif; ?>
<?php appShellEnd(); ?>
</body>
</html>
