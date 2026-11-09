<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('tourist');

// Handle remove favourite
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['remove_favourite'])) {
    requireCsrf();
    $fav_id = (int)$_POST['favourite_id'];
    $pdo->prepare("
        DELETE FROM favourites
        WHERE favourite_id = ? AND tourist_id = ?
    ")->execute([$fav_id, $_SESSION['user_id']]);
    redirect('favourites.php');
}

// Get saved accommodations
$acc_favs = $pdo->prepare("
    SELECT f.favourite_id, f.saved_at,
           a.accommodation_id, a.title,
           a.location_name, a.price_per_night,
           a.property_type, a.status,
           COALESCE(AVG(r.rating), 0) AS avg_rating,
           COUNT(DISTINCT r.review_id) AS total_reviews,
           (SELECT photo_path FROM accommodation_photos
            WHERE accommodation_id = a.accommodation_id
            ORDER BY is_primary DESC, photo_id ASC LIMIT 1) AS primary_photo
    FROM favourites f
    JOIN accommodations a ON a.accommodation_id = f.item_id
    LEFT JOIN reviews r
        ON r.accommodation_id = a.accommodation_id
        AND r.is_published = 1
    WHERE f.tourist_id = ?
    AND f.item_type = 'accommodation'
    GROUP BY f.favourite_id, a.accommodation_id
    ORDER BY f.saved_at DESC
");
$acc_favs->execute([$_SESSION['user_id']]);
$acc_favs = $acc_favs->fetchAll();

// Get saved attractions
$att_favs = $pdo->prepare("
    SELECT f.favourite_id, f.saved_at,
           a.attraction_id, a.name,
           a.location_name, a.category,
           a.entry_fee, a.photo_path
    FROM favourites f
    JOIN attractions a ON a.attraction_id = f.item_id
    WHERE f.tourist_id = ?
    AND f.item_type = 'attraction'
    ORDER BY f.saved_at DESC
");
$att_favs->execute([$_SESSION['user_id']]);
$att_favs = $att_favs->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Favourites — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .page-wrap  { max-width:1100px; margin:0 auto; padding:32px 24px }
        .page-tag   { font-size:11px; font-weight:600; letter-spacing:.06em;
                      color:#126b4e; text-transform:uppercase;
                      margin-bottom:8px }
        .page-title { font-size:28px; font-weight:400; margin-bottom:4px }
        .page-sub   { font-size:14px; color:var(--text-secondary);
                      margin-bottom:28px }
        .section-title { font-size:15px; font-weight:500;
                         margin-bottom:14px; color:var(--text-primary) }
        .fav-grid   { display:grid;
                      grid-template-columns:repeat(
                          auto-fill, minmax(240px,1fr));
                      gap:14px; margin-bottom:32px }
        .fav-card   { background:var(--surface-2);
                      border:1px solid var(--border);
                      border-radius:8px; overflow:hidden;
                      position:relative }
        .fav-img    { height:150px; background:#E1F5EE;
                      display:flex; align-items:center;
                      justify-content:center; font-size:32px;
                      position:relative; overflow:hidden }
        .fav-img img{ width:100%; height:100%; object-fit:cover }
        .fav-img.att{ background:#EEEDFE }
        .remove-btn { position:absolute; top:8px; right:8px;
                      background:var(--surface-2);
                      border:1px solid var(--border);
                      border-radius:4px; width:28px; height:28px;
                      display:flex; align-items:center;
                      justify-content:center; cursor:pointer;
                      font-size:13px; color:var(--text-secondary);
                      z-index:2 }
        .remove-btn:hover { border-color:#DC2626; color:#b42318 }
        .fav-body   { padding:14px }
        .fav-title  { font-size:13px; font-weight:500; margin-bottom:3px }
        .fav-loc    { font-size:12px; color:var(--text-secondary);
                      margin-bottom:8px }
        .fav-meta   { display:flex; justify-content:space-between;
                      align-items:center; margin-bottom:10px }
        .fav-price  { font-size:13px; font-weight:500; color:#0F6E56 }
        .fav-rating { font-size:12px; color:var(--text-secondary) }
        .fav-cat    { font-size:11px; color:#534AB7; background:#EEEDFE;
                      padding:2px 7px; border-radius:3px;
                      border:1px solid #AFA9EC }
        .fav-fee    { font-size:13px; font-weight:500; color:#0F6E56 }
        .fav-saved  { font-size:11px; color:var(--text-secondary);
                      margin-bottom:10px }
        .btn-view   { display:block; text-align:center; padding:8px;
                      background:none; border:1px solid var(--border-strong);
                      border-radius:4px; font-size:12px; font-weight:500;
                      color:var(--text-secondary); cursor:pointer;
                      text-decoration:none; transition:.15s }
        .btn-view:hover { border-color:#126b4e; color:#126b4e }
        .btn-view-green { background:#085041; color:#fff;
                          border-color:#085041 }
        .btn-view-green:hover { background:#0a6350; color:#fff }
        .empty-state { text-align:center; padding:48px 20px;
                       border:0.5px dashed var(--border);
                       border-radius:8px; margin-bottom:32px }
        .empty-state h3 { font-size:15px; font-weight:500;
                          margin-bottom:6px }
        .empty-state p  { font-size:13px; color:var(--text-secondary);
                          margin-bottom:16px }
        .divider { height:0.5px; background:var(--border);
                   margin-bottom:28px }
    </style>
</head>
<body>

<?php include '../includes/header.php'; ?>

<div class="page-wrap">

    <div class="page-tag">Saved</div>
    <h1 class="page-title">Favourites</h1>
    <p class="page-sub">
        Accommodations and attractions you have saved for later.
    </p>

    <!-- Saved Accommodations -->
    <div class="section-title">
        Saved stays
        <span style="font-size:12px;font-weight:400;
                     color:var(--text-secondary)">
            (<?= count($acc_favs) ?>)
        </span>
    </div>

    <?php if (empty($acc_favs)): ?>
        <div class="empty-state">
            <h3>No saved stays yet</h3>
            <p>
                When you find accommodation you like,
                tap the heart icon to save it here.
            </p>
            <a href="../index.php"
               style="display:inline-block;padding:8px 20px;
                      background:#085041;color:#fff;
                      border-radius:6px;font-size:13px;
                      font-weight:500;text-decoration:none">
                Find accommodation
            </a>
        </div>
    <?php else: ?>
        <div class="fav-grid">
            <?php foreach ($acc_favs as $f): ?>
            <div class="fav-card">
                <div class="fav-img">
                    <?php if ($f['primary_photo']): ?>
                        <img src="../uploads/accommodations/<?= h($f['primary_photo']) ?>"
                             alt="<?= h($f['title']) ?>">
                    <?php else: ?>
                        <span style="font-size:12px;color:#8a948e">No photo</span>
                    <?php endif; ?>

                    <!-- Remove button -->
                    <form method="POST" style="position:absolute;
                                               top:8px;right:8px">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="favourite_id"
                               value="<?= $f['favourite_id'] ?>">
                        <input type="hidden" name="remove_favourite"
                               value="1">
                        <button type="submit" class="remove-btn"
                                title="Remove from favourites"
                                onclick="return confirm(
                                    'Remove from favourites?')">
                            &#10005;
                        </button>
                    </form>

                    <?php if ($f['status'] !== 'approved'): ?>
                        <div style="position:absolute;bottom:0;left:0;
                                    right:0;background:rgba(0,0,0,.5);
                                    color:#fff;font-size:11px;
                                    padding:4px 8px;text-align:center">
                            No longer available
                        </div>
                    <?php endif; ?>
                </div>

                <div class="fav-body">
                    <div class="fav-title">
                        <?= h($f['title']) ?>
                    </div>
                    <div class="fav-loc">
                        <?= h($f['location_name']) ?>
                    </div>
                    <div class="fav-meta">
                        <span class="fav-price">
                            KES <?= number_format($f['price_per_night']) ?>
                            <span style="font-weight:400;font-size:11px;
                                         color:var(--text-secondary)">
                                /night
                            </span>
                        </span>
                        <span class="fav-rating">
                            <?php if ($f['avg_rating'] > 0): ?>
                                &#9733;
                                <?= number_format($f['avg_rating'], 1) ?>
                                <span style="color:var(--text-secondary)">
                                    (<?= $f['total_reviews'] ?>)
                                </span>
                            <?php else: ?>
                                New
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="fav-saved">
                        Saved <?= date('d M Y',
                            strtotime($f['saved_at'])) ?>
                    </div>
                    <?php if ($f['status'] === 'approved'): ?>
                        <a href="details.php?id=<?= $f['accommodation_id'] ?>"
                           class="btn-view btn-view-green">
                            View stay
                        </a>
                    <?php else: ?>
                        <span class="btn-view" style="cursor:default;
                              color:var(--text-secondary)">
                            Unavailable
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="divider"></div>

    <!-- Saved Attractions -->
    <div class="section-title">
        Saved attractions
        <span style="font-size:12px;font-weight:400;
                     color:var(--text-secondary)">
            (<?= count($att_favs) ?>)
        </span>
    </div>

    <?php if (empty($att_favs)): ?>
        <div class="empty-state">
            <h3>No saved attractions yet</h3>
            <p>
                When you find an attraction you want to visit,
                save it here for easy access.
            </p>
            <a href="../tourist/search.php?type=attraction"
               style="display:inline-block;padding:8px 20px;
                      background:#085041;color:#fff;
                      border-radius:6px;font-size:13px;
                      font-weight:500;text-decoration:none">
                Explore attractions
            </a>
        </div>
    <?php else: ?>
        <div class="fav-grid">
            <?php foreach ($att_favs as $f): ?>
            <div class="fav-card">
                <div class="fav-img att">
                    <?php if ($f['photo_path']): ?>
                        <img src="../uploads/attractions/<?= h($f['photo_path']) ?>"
                             alt="<?= h($f['name']) ?>">
                    <?php else: ?>
                        <span style="font-size:12px;color:#8a948e">No photo</span>
                    <?php endif; ?>

                    <!-- Remove button -->
                    <form method="POST" style="position:absolute;
                                               top:8px;right:8px">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="favourite_id"
                               value="<?= $f['favourite_id'] ?>">
                        <input type="hidden" name="remove_favourite"
                               value="1">
                        <button type="submit" class="remove-btn"
                                title="Remove from favourites"
                                onclick="return confirm(
                                    'Remove from favourites?')">
                            &#10005;
                        </button>
                    </form>
                </div>

                <div class="fav-body">
                    <div class="fav-title">
                        <?= h($f['name']) ?>
                    </div>
                    <div class="fav-loc">
                        <?= h($f['location_name']) ?>
                    </div>
                    <div class="fav-meta">
                        <span class="fav-cat">
                            <?= h($f['category']) ?>
                        </span>
                    </div>
                    <div class="fav-saved">
                        Saved <?= date('d M Y',
                            strtotime($f['saved_at'])) ?>
                    </div>
                    <a href="search.php?type=accommodation&attraction_id=<?= $f['attraction_id'] ?>"
                       class="btn-view btn-view-green">
                        Find nearby stays
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>

</body>
</html>