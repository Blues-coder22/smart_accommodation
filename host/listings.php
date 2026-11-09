<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$host_id = $_SESSION['user_id'];

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['delete_listing'])) {
    requireCsrf();
    $lid = (int)$_POST['listing_id'];

    // Verify ownership
    $check = $pdo->prepare("
        SELECT accommodation_id FROM accommodations
        WHERE accommodation_id = ? AND host_id = ?
    ");
    $check->execute([$lid, $host_id]);

    if ($check->fetch()) {
        // Only delete if no confirmed bookings
        $has_bookings = $pdo->prepare("
            SELECT COUNT(*) FROM bookings
            WHERE accommodation_id = ?
            AND status IN ('pending','confirmed')
        ");
        $has_bookings->execute([$lid]);

        if ($has_bookings->fetchColumn() > 0) {
            setFlash('error',
                'Cannot delete a listing with active bookings.');
        } else {
            $pdo->prepare("
                DELETE FROM accommodations
                WHERE accommodation_id = ?
            ")->execute([$lid]);
            setFlash('success', 'Listing deleted successfully.');
        }
    }
    redirect('listings.php');
}

// Get all listings for this host
$listings = $pdo->prepare("
    SELECT a.*,
           COALESCE(AVG(r.rating), 0) AS avg_rating,
           COUNT(DISTINCT r.review_id) AS total_reviews,
           COUNT(DISTINCT CASE WHEN b.status IN ('pending','confirmed')
                 THEN b.booking_id END) AS active_bookings,
           (SELECT photo_path FROM accommodation_photos
            WHERE accommodation_id = a.accommodation_id
            ORDER BY is_primary DESC, photo_id ASC LIMIT 1) AS primary_photo
    FROM accommodations a
    LEFT JOIN reviews r
        ON r.accommodation_id = a.accommodation_id
        AND r.is_published = 1
    LEFT JOIN bookings b
        ON b.accommodation_id = a.accommodation_id
    WHERE a.host_id = ?
    GROUP BY a.accommodation_id
    ORDER BY a.created_at DESC
");
$listings->execute([$host_id]);
$listings = $listings->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Listings — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        
        .dash-wrap  { display:grid;
                      grid-template-columns:200px 1fr; flex:1 }
        .sidebar    { background:var(--surface-2);
                      border-right:1px solid var(--border);
                      padding:24px 0; position:sticky;
                      top:52px; height:calc(100vh - 52px);
                      overflow-y:auto }
        .sidebar-role { font-size:10px; font-weight:700;
                        letter-spacing:.08em; color:#126b4e;
                        text-transform:uppercase;
                        padding:0 20px; margin-bottom:12px }
        .sidebar-link { display:flex; align-items:center; gap:10px;
                        padding:9px 20px; font-size:13px;
                        color:var(--text-secondary);
                        text-decoration:none; width:100% }
        .sidebar-link:hover { color:var(--text-primary);
                              background:var(--surface-1) }
        .sidebar-link.active { color:#085041; font-weight:500;
                               background:#E1F5EE;
                               border-left:3px solid #126b4e }
        .main       { padding:28px }
        .page-head  { display:flex; justify-content:space-between;
                      align-items:flex-start; margin-bottom:24px }
        .page-tag   { font-size:11px; font-weight:600;
                      letter-spacing:.06em; color:#126b4e;
                      text-transform:uppercase; margin-bottom:6px }
        .page-title { font-size:26px; font-weight:400 }
        .btn-add    { padding:9px 18px; background:#085041;
                      color:#fff; border:none; border-radius:6px;
                      font-size:13px; font-weight:500;
                      text-decoration:none; white-space:nowrap }
        .listing-row { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; display:grid;
                       grid-template-columns:96px 1fr auto;
                       gap:16px; align-items:center;
                       padding:16px; margin-bottom:10px;
                       transition:border-color .15s }
        .listing-row:hover { border-color:var(--border-strong) }
        .listing-thumb { width:96px; height:72px; border-radius:6px;
                         background:#E1F5EE; overflow:hidden;
                         display:flex; align-items:center;
                         justify-content:center; font-size:28px;
                         flex-shrink:0 }
        .listing-thumb img { width:100%; height:100%;
                             object-fit:contain }
        .listing-title { font-size:14px; font-weight:500;
                         margin-bottom:3px }
        .listing-meta  { font-size:12px; color:var(--text-secondary);
                         margin-bottom:6px }
        .listing-stats { display:flex; gap:14px; font-size:12px;
                         color:var(--text-secondary) }
        .listing-stats span { display:flex; align-items:center;
                              gap:4px }
        .status-pill { display:inline-block; font-size:11px;
                       font-weight:600; padding:3px 9px;
                       border-radius:3px }
        .s-approved  { background:#E1F5EE; color:#0F6E56;
                       border:1px solid #9FE1CB }
        .s-pending   { background:#FFF8E1; color:#7a5200;
                       border:1px solid #F9D57C }
        .s-rejected  { background:#FEF2F2; color:#991B1B;
                       border:1px solid #FECACA }
        .s-suspended { background:#F5F3FF; color:#5B21B6;
                       border:1px solid #C4B5FD }
        .listing-actions { display:flex; flex-direction:column;
                           gap:6px; align-items:flex-end;
                           flex-shrink:0 }
        .btn-sm     { padding:6px 14px; font-size:12px;
                      border-radius:4px; cursor:pointer;
                      font-weight:500; text-decoration:none;
                      display:inline-block; text-align:center;
                      border:1px solid var(--border-strong);
                      background:none;
                      color:var(--text-secondary) }
        .btn-sm:hover { border-color:#126b4e; color:#126b4e }
        .btn-sm-danger { border-color:#FECACA; color:#b42318 }
        .btn-sm-danger:hover { background:#FEF2F2 }
        .empty-state { text-align:center; padding:64px 20px }
        .empty-state h3 { font-size:16px; font-weight:500;
                          margin-bottom:8px }
        .empty-state p  { font-size:14px;
                          color:var(--text-secondary);
                          margin-bottom:20px }
    </style>
</head>
<body>


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <?= showFlash() ?>

        <div class="page-head">
            <div>
                <div class="page-tag">Host</div>
                <h1 class="page-title">My listings</h1>
            </div>
            <a href="add_listing.php" class="btn-add">
                + Add listing
            </a>
        </div>

        <?php if (empty($listings)): ?>
            <div class="empty-state">
                <h3>No listings yet</h3>
                <p>
                    Add your first property to start
                    receiving bookings.
                </p>
                <a href="add_listing.php" class="btn-add">
                    Add your first listing
                </a>
            </div>

        <?php else: ?>
            <?php foreach ($listings as $l): ?>
            <div class="listing-row">

                <!-- Thumbnail -->
                <div class="listing-thumb">
                    <?php if ($l['primary_photo']): ?>
                        <img src="../uploads/accommodations/<?= h($l['primary_photo']) ?>"
                             alt="<?= h($l['title']) ?>">
                    <?php else: ?>
                        <span style="font-size:12px;color:#8a948e">No photo</span>
                    <?php endif; ?>
                </div>

                <!-- Details -->
                <div>
                    <div class="listing-title">
                        <?= h($l['title']) ?>
                    </div>
                    <div class="listing-meta">
                        <?= h($l['location_name']) ?>
                        &middot;
                        <?= h($l['property_type']) ?>
                        &middot;
                        KES <?= number_format($l['price_per_night']) ?>/night
                    </div>
                    <div class="listing-stats">
                        <span>
                            &#9733;
                            <?= $l['avg_rating'] > 0
                                ? number_format($l['avg_rating'], 1)
                                : 'No reviews' ?>
                            <?php if ($l['total_reviews'] > 0): ?>
                                (<?= $l['total_reviews'] ?>)
                            <?php endif; ?>
                        </span>
                        <span>
                            <?= $l['active_bookings'] ?> active
                            booking<?= $l['active_bookings'] != 1
                                       ? 's' : '' ?>
                        </span>
                        <span>
                            <?= h($l['max_guests']) ?>
                            guests max
                        </span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="listing-actions">
                    <?php
                    $pill = [
                        'approved'  => 's-approved',
                        'pending'   => 's-pending',
                        'rejected'  => 's-rejected',
                        'suspended' => 's-suspended'
                    ][$l['status']] ?? 's-pending';

                    $label = [
                        'approved'  => 'Live',
                        'pending'   => 'Pending approval',
                        'rejected'  => 'Rejected',
                        'suspended' => 'Suspended'
                    ][$l['status']] ?? ucfirst($l['status']);
                    ?>
                    <span class="status-pill <?= $pill ?>">
                        <?= $label ?>
                    </span>

                    <a href="edit_listing.php?id=<?= $l['accommodation_id'] ?>"
                       class="btn-sm">
                        Edit
                    </a>

                    <?php if ($l['status'] === 'approved'): ?>
                    <a href="../tourist/details.php?id=<?= $l['accommodation_id'] ?>"
                       target="_blank"
                       class="btn-sm">
                        View live
                    </a>
                    <?php endif; ?>

                    <?php if ($l['active_bookings'] == 0): ?>
                    <form method="POST" style="margin:0">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="listing_id"
                               value="<?= $l['accommodation_id'] ?>">
                        <input type="hidden" name="delete_listing"
                               value="1">
                        <button type="submit"
                                class="btn-sm btn-sm-danger"
                                onclick="return confirm(
                                    'Delete this listing? This cannot be undone.')">
                            Delete
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php appShellEnd(); ?>

</body>
</html>