<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/email.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('admin');

// Handle approve / reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $listing_id = (int)($_POST['listing_id'] ?? 0);
    $action     = sanitize($_POST['action'] ?? '');
    $reason     = sanitize($_POST['reason'] ?? '');

    $stmt = $pdo->prepare("
        SELECT a.*, u.email AS host_email,
               u.full_name AS host_name
        FROM accommodations a
        JOIN users u ON u.user_id = a.host_id
        WHERE a.accommodation_id = ?
    ");
    $stmt->execute([$listing_id]);
    $listing = $stmt->fetch();

    if ($listing) {
        if ($action === 'approve') {
            $pdo->prepare("
                UPDATE accommodations
                SET status = 'approved'
                WHERE accommodation_id = ?
            ")->execute([$listing_id]);

            sendListingApprovedEmail(
                $listing['host_email'],
                $listing['host_name'],
                $listing['title']
            );
            setFlash('success',
                "Listing \"{$listing['title']}\" approved.");

        } elseif ($action === 'reject') {
            $pdo->prepare("
                UPDATE accommodations
                SET status = 'rejected'
                WHERE accommodation_id = ?
            ")->execute([$listing_id]);

            sendListingRejectedEmail(
                $listing['host_email'],
                $listing['host_name'],
                $listing['title'],
                $reason ?: 'Does not meet listing requirements.'
            );
            setFlash('success',
                "Listing \"{$listing['title']}\" rejected.");

        } elseif ($action === 'suspend') {
            $pdo->prepare("
                UPDATE accommodations
                SET status = 'suspended'
                WHERE accommodation_id = ?
            ")->execute([$listing_id]);

            sendEmail(
                $listing['host_email'],
                'Listing Suspended — Smart Accommodation Kenya',
                "
                <h2>Listing suspended</h2>
                <p>Dear {$listing['host_name']},</p>
                <p>Your listing <strong>{$listing['title']}</strong>
                   has been temporarily suspended.</p>
                <p><strong>Reason:</strong> " .
                ($reason ?: 'Violation of platform guidelines.') .
                "</p>
                <p>Please contact support if you have questions.</p>
                "
            );
            setFlash('success',
                "Listing \"{$listing['title']}\" suspended.");

        } elseif ($action === 'restore') {
            $pdo->prepare("
                UPDATE accommodations
                SET status = 'approved'
                WHERE accommodation_id = ?
            ")->execute([$listing_id]);

            sendEmail(
                $listing['host_email'],
                'Listing Restored — Smart Accommodation Kenya',
                "
                <h2>Listing restored</h2>
                <p>Dear {$listing['host_name']},</p>
                <p>Your listing <strong>{$listing['title']}</strong>
                   has been restored and is now live.</p>
                "
            );
            setFlash('success',
                "Listing \"{$listing['title']}\" restored.");
        }
    }
    redirect('listings.php?filter=' .
             sanitize($_GET['filter'] ?? 'pending'));
}

$filter = sanitize($_GET['filter'] ?? 'pending');
$review = (int)($_GET['review'] ?? 0);

$where  = "1=1";
if ($filter === 'pending') {
    $where = "a.status = 'pending'";
} elseif ($filter === 'approved') {
    $where = "a.status = 'approved'";
} elseif ($filter === 'rejected') {
    $where = "a.status = 'rejected'";
} elseif ($filter === 'suspended') {
    $where = "a.status = 'suspended'";
}

$listings = $pdo->query("
    SELECT a.*,
           u.full_name AS host_name,
           u.email AS host_email,
           u.phone_number AS host_phone,
           COUNT(DISTINCT b.booking_id) AS total_bookings,
           COALESCE(AVG(r.rating), 0) AS avg_rating,
           (SELECT COUNT(*) FROM accommodation_photos
            WHERE accommodation_id = a.accommodation_id)
            AS photo_count
    FROM accommodations a
    JOIN users u ON u.user_id = a.host_id
    LEFT JOIN bookings b ON b.accommodation_id = a.accommodation_id
    LEFT JOIN reviews r
        ON r.accommodation_id = a.accommodation_id
        AND r.is_published = 1
    WHERE $where
    GROUP BY a.accommodation_id
    ORDER BY a.created_at ASC
")->fetchAll();

// Counts per status
$counts = $pdo->query("
    SELECT status, COUNT(*) AS cnt
    FROM accommodations
    GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

// If reviewing specific listing open it
$reviewing = null;
if ($review) {
    foreach ($listings as $l) {
        if ($l['accommodation_id'] == $review) {
            $reviewing = $l;
            break;
        }
    }
    if (!$reviewing) {
        $stmt = $pdo->prepare("
            SELECT a.*, u.full_name AS host_name,
                   u.email AS host_email,
                   u.phone_number AS host_phone,
                   (SELECT COUNT(*) FROM accommodation_photos
                    WHERE accommodation_id = a.accommodation_id)
                    AS photo_count
            FROM accommodations a
            JOIN users u ON u.user_id = a.host_id
            WHERE a.accommodation_id = ?
        ");
        $stmt->execute([$review]);
        $reviewing = $stmt->fetch();
    }
}

// Get photos for reviewing listing
$review_photos = [];
if ($reviewing) {
    $p = $pdo->prepare("
        SELECT * FROM accommodation_photos
        WHERE accommodation_id = ?
        ORDER BY is_primary DESC
    ");
    $p->execute([$reviewing['accommodation_id']]);
    $review_photos = $p->fetchAll();
}

// Get amenities for reviewing listing
$review_amenities = [];
if ($reviewing) {
    $a = $pdo->prepare("
        SELECT amenity_name FROM amenities
        WHERE accommodation_id = ?
    ");
    $a->execute([$reviewing['accommodation_id']]);
    $review_amenities = $a->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listing Approvals — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body         { display:flex; flex-direction:column;
                       min-height:100vh }
        .dash-wrap   { display:grid;
                       grid-template-columns:200px 1fr; flex:1 }
        .sidebar     { background:var(--surface-2);
                       border-right:0.5px solid var(--border);
                       padding:24px 0; position:sticky;
                       top:52px; height:calc(100vh - 52px);
                       overflow-y:auto }
        .sidebar-role{ font-size:10px; font-weight:700;
                       letter-spacing:.08em; color:#DC2626;
                       text-transform:uppercase;
                       padding:0 20px; margin-bottom:12px }
        .sidebar-link{ display:flex; align-items:center; gap:10px;
                       padding:9px 20px; font-size:13px;
                       color:var(--text-secondary);
                       text-decoration:none; width:100% }
        .sidebar-link:hover { color:var(--text-primary);
                              background:var(--surface-1) }
        .sidebar-link.active { color:#085041; font-weight:500;
                               background:#E1F5EE;
                               border-left:3px solid #1D9E75 }
        .badge       { font-size:10px; background:#DC2626;
                       color:#fff; border-radius:10px;
                       padding:1px 6px; margin-left:auto }
        .main        { padding:28px }
        .page-tag    { font-size:11px; font-weight:600;
                       letter-spacing:.06em; color:#1D9E75;
                       text-transform:uppercase; margin-bottom:8px }
        .page-title  { font-size:26px; font-weight:400;
                       margin-bottom:20px }
        .filter-tabs { display:flex; gap:6px; margin-bottom:20px;
                       flex-wrap:wrap }
        .filter-tab  { padding:6px 14px; font-size:12px;
                       border-radius:4px; text-decoration:none;
                       color:var(--text-secondary);
                       border:0.5px solid var(--border);
                       display:flex; align-items:center; gap:6px }
        .filter-tab.active { background:#085041; color:#fff;
                             border-color:#085041 }
        .tab-count   { font-size:10px; opacity:.8 }
        .listing-row { background:var(--surface-2);
                       border:0.5px solid var(--border);
                       border-radius:8px; display:grid;
                       grid-template-columns:100px 1fr auto;
                       gap:16px; align-items:start;
                       padding:16px; margin-bottom:10px }
        .listing-row.highlight { border-color:#1D9E75;
                                 background:#F0FAF6 }
        .listing-thumb { width:100px; height:76px; border-radius:6px;
                         background:#E1F5EE; overflow:hidden;
                         display:flex; align-items:center;
                         justify-content:center; font-size:28px }
        .listing-thumb img { width:100%; height:100%;
                             object-fit:cover }
        .listing-title { font-size:14px; font-weight:500;
                         margin-bottom:3px }
        .listing-meta  { font-size:12px;
                         color:var(--text-secondary);
                         margin-bottom:6px }
        .listing-host  { font-size:12px;
                         color:var(--text-secondary) }
        .listing-badges { display:flex; gap:6px;
                          flex-wrap:wrap; margin-top:6px }
        .info-badge    { font-size:11px; padding:2px 8px;
                         border-radius:3px; background:var(--surface-1);
                         border:0.5px solid var(--border);
                         color:var(--text-secondary) }
        .status-pill   { display:inline-block; font-size:11px;
                         font-weight:600; padding:3px 9px;
                         border-radius:3px }
        .s-pending     { background:#FFF8E1; color:#9A6A00;
                         border:0.5px solid #F9D57C }
        .s-approved    { background:#E1F5EE; color:#0F6E56;
                         border:0.5px solid #9FE1CB }
        .s-rejected    { background:#FEF2F2; color:#991B1B;
                         border:0.5px solid #FECACA }
        .s-suspended   { background:#F5F3FF; color:#5B21B6;
                         border:0.5px solid #C4B5FD }
        .listing-actions { display:flex; flex-direction:column;
                           gap:6px; align-items:flex-end;
                           flex-shrink:0 }
        .btn-approve   { padding:7px 14px; background:#085041;
                         color:#fff; border:none; border-radius:4px;
                         font-size:12px; font-weight:500;
                         cursor:pointer; width:100%;
                         text-align:center }
        .btn-reject    { padding:7px 14px; background:none;
                         color:#DC2626; border:0.5px solid #FECACA;
                         border-radius:4px; font-size:12px;
                         font-weight:500; cursor:pointer;
                         width:100%; text-align:center }
        .btn-suspend   { padding:7px 14px; background:none;
                         color:#D97706; border:0.5px solid #F9D57C;
                         border-radius:4px; font-size:12px;
                         font-weight:500; cursor:pointer;
                         width:100%; text-align:center }
        .btn-restore   { padding:7px 14px; background:#085041;
                         color:#fff; border:none; border-radius:4px;
                         font-size:12px; font-weight:500;
                         cursor:pointer; width:100%;
                         text-align:center }
        .btn-view      { padding:7px 14px; background:none;
                         color:var(--text-secondary);
                         border:0.5px solid var(--border-strong);
                         border-radius:4px; font-size:12px;
                         cursor:pointer; width:100%;
                         text-align:center; text-decoration:none;
                         display:block }
        .reason-form   { margin-top:8px; width:100% }
        .reason-form textarea { width:100%; padding:7px 10px;
                                border:0.5px solid var(--border-strong);
                                border-radius:4px; font-size:12px;
                                resize:none; height:60px;
                                font-family:inherit;
                                background:var(--surface-2);
                                color:var(--text-primary) }

        /* Review Panel Modal */
        .modal-overlay { position:fixed; inset:0;
                         background:rgba(0,0,0,.4); z-index:100;
                         display:flex; align-items:flex-start;
                         justify-content:center; padding:20px;
                         overflow-y:auto }
        .modal         { background:var(--surface-2);
                         border-radius:12px; width:100%;
                         max-width:720px; padding:28px;
                         box-shadow:0 8px 40px rgba(0,0,0,.2);
                         position:relative; margin:auto }
        .modal-close   { position:absolute; top:16px; right:16px;
                         background:none; border:none;
                         font-size:18px; cursor:pointer;
                         color:var(--text-secondary);
                         width:28px; height:28px;
                         display:flex; align-items:center;
                         justify-content:center;
                         border-radius:4px }
        .modal-close:hover { background:var(--surface-1) }
        .modal h2      { font-size:18px; font-weight:500;
                         margin-bottom:4px }
        .modal-meta    { font-size:13px; color:var(--text-secondary);
                         margin-bottom:20px }
        .photo-grid    { display:grid;
                         grid-template-columns:repeat(3,1fr);
                         gap:8px; margin-bottom:18px }
        .photo-grid img { width:100%; height:100px;
                          object-fit:cover; border-radius:6px;
                          border:0.5px solid var(--border) }
        .detail-row    { display:flex; justify-content:space-between;
                         padding:8px 0;
                         border-bottom:0.5px solid var(--border);
                         font-size:13px }
        .detail-row:last-child { border-bottom:none }
        .detail-label  { color:var(--text-secondary) }
        .amenity-list  { display:flex; flex-wrap:wrap; gap:6px;
                         margin:10px 0 }
        .amenity-tag   { font-size:11px; padding:3px 9px;
                         background:var(--surface-1);
                         border:0.5px solid var(--border);
                         border-radius:3px;
                         color:var(--text-secondary) }
        .modal-actions { display:flex; gap:10px; margin-top:20px;
                         padding-top:16px;
                         border-top:0.5px solid var(--border) }
        .empty-state   { text-align:center; padding:48px;
                         color:var(--text-secondary); font-size:14px }
        @media(max-width:900px){
            .dash-wrap { grid-template-columns:1fr }
            .sidebar   { display:none }
        }
    </style>
</head>
<body>

<?php include '../includes/header.php'; ?>

<!-- Review Modal -->
<?php if ($reviewing): ?>
<div class="modal-overlay" id="reviewModal">
    <div class="modal">
        <button class="modal-close"
                onclick="document.getElementById(
                    'reviewModal').style.display='none'">
            &times;
        </button>

        <h2><?= htmlspecialchars($reviewing['title']) ?></h2>
        <div class="modal-meta">
            <?= htmlspecialchars($reviewing['location_name']) ?>
            &middot;
            <?= htmlspecialchars($reviewing['property_type']) ?>
            &middot; Submitted by
            <?= htmlspecialchars($reviewing['host_name']) ?>
        </div>

        <!-- Photos -->
        <?php if (!empty($review_photos)): ?>
        <div class="photo-grid">
            <?php foreach (array_slice($review_photos,0,6)
                as $ph): ?>
            <img src="../uploads/accommodations/<?= htmlspecialchars(
                $ph['photo_path']) ?>"
                 alt="Property photo">
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="background:var(--surface-1);border-radius:8px;
                    padding:24px;text-align:center;
                    margin-bottom:18px;font-size:13px;
                    color:var(--text-secondary)">
            No photos uploaded
        </div>
        <?php endif; ?>

        <!-- Details -->
        <div class="detail-row">
            <span class="detail-label">Price per night</span>
            <span>
                KES <?= number_format(
                    $reviewing['price_per_night']) ?>
            </span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Maximum guests</span>
            <span><?= $reviewing['max_guests'] ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Host</span>
            <span>
                <?= htmlspecialchars($reviewing['host_name']) ?>
                &middot;
                <?= htmlspecialchars($reviewing['host_email']) ?>
                <?php if ($reviewing['host_phone']): ?>
                    &middot;
                    <?= htmlspecialchars($reviewing['host_phone']) ?>
                <?php endif; ?>
            </span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Photos uploaded</span>
            <span><?= $reviewing['photo_count'] ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Description</span>
            <span style="max-width:400px;text-align:right">
                <?= htmlspecialchars(
                    substr($reviewing['description'], 0, 200)) ?>
                <?= strlen($reviewing['description']) > 200
                    ? '...' : '' ?>
            </span>
        </div>

        <!-- Amenities -->
        <?php if (!empty($review_amenities)): ?>
        <div class="detail-label" style="margin-top:12px">
            Amenities
        </div>
        <div class="amenity-list">
            <?php foreach ($review_amenities as $am): ?>
            <span class="amenity-tag">
                <?= htmlspecialchars($am) ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <?php if ($reviewing['status'] === 'pending'): ?>
        <div class="modal-actions">
            <form method="POST" style="flex:1">
                <input type="hidden" name="listing_id"
                       value="<?= $reviewing['accommodation_id'] ?>">
                <input type="hidden" name="action" value="approve">
                <button type="submit" class="btn-approve"
                        style="width:100%">
                    Approve listing
                </button>
            </form>
            <form method="POST" style="flex:1">
                <input type="hidden" name="listing_id"
                       value="<?= $reviewing['accommodation_id'] ?>">
                <input type="hidden" name="action" value="reject">
                <textarea name="reason"
                          placeholder="Reason for rejection (sent to host)..."
                          style="width:100%;padding:7px 10px;
                                 border:0.5px solid var(--border-strong);
                                 border-radius:4px;font-size:12px;
                                 resize:none;height:52px;
                                 margin-bottom:6px;
                                 font-family:inherit;
                                 background:var(--surface-2);
                                 color:var(--text-primary)">
                </textarea>
                <button type="submit" class="btn-reject"
                        style="width:100%">
                    Reject listing
                </button>
            </form>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>

<div class="dash-wrap">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-role">Admin</div>
        <a href="dashboard.php" class="sidebar-link">Overview</a>
        <a href="listings.php" class="sidebar-link active">
            Listing approvals
            <?php if (!empty($counts['pending'])): ?>
                <span class="badge">
                    <?= $counts['pending'] ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="users.php" class="sidebar-link">Users</a>
        <a href="reviews.php" class="sidebar-link">
            Review moderation
        </a>
        <a href="reports.php" class="sidebar-link">Reports</a>
        <a href="attractions.php" class="sidebar-link">Attractions</a>
        <a href="profile.php" class="sidebar-link">Profile</a>
    </aside>

    <main class="main">

        <?= showFlash() ?>

        <div class="page-tag">Admin</div>
        <h1 class="page-title">Listing approvals</h1>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <?php foreach ([
                'pending'  => 'Pending',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                'suspended'=> 'Suspended',
                'all'      => 'All'
            ] as $key => $label): ?>
            <a href="?filter=<?= $key ?>"
               class="filter-tab <?= $filter===$key?'active':'' ?>">
                <?= $label ?>
                <?php if (isset($counts[$key])): ?>
                    <span class="tab-count">
                        (<?= $counts[$key] ?>)
                    </span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($listings)): ?>
            <div class="empty-state">
                No <?= $filter ?> listings found.
            </div>
        <?php else: ?>
            <?php foreach ($listings as $l): ?>
            <div class="listing-row <?= ($review == $l['accommodation_id'])
                ? 'highlight' : '' ?>">

                <!-- Thumbnail -->
                <div class="listing-thumb">
                    <?php
                    $thumb = $pdo->prepare("
                        SELECT photo_path FROM accommodation_photos
                        WHERE accommodation_id = ?
                        AND is_primary = 1 LIMIT 1
                    ");
                    $thumb->execute([$l['accommodation_id']]);
                    $thumb = $thumb->fetchColumn();
                    ?>
                    <?php if ($thumb): ?>
                        <img src="../uploads/accommodations/<?= htmlspecialchars($thumb) ?>"
                             alt="<?= htmlspecialchars($l['title']) ?>">
                    <?php else: ?>
                        &#127968;
                    <?php endif; ?>
                </div>

                <!-- Info -->
                <div>
                    <div class="listing-title">
                        <?= htmlspecialchars($l['title']) ?>
                    </div>
                    <div class="listing-meta">
                        <?= htmlspecialchars($l['location_name']) ?>
                        &middot;
                        <?= htmlspecialchars($l['property_type']) ?>
                        &middot;
                        KES <?= number_format($l['price_per_night']) ?>/night
                        &middot;
                        <?= $l['max_guests'] ?> guests max
                    </div>
                    <div class="listing-host">
                        Host: <?= htmlspecialchars($l['host_name']) ?>
                        &middot;
                        <?= htmlspecialchars($l['host_email']) ?>
                    </div>
                    <div class="listing-badges">
                        <span class="info-badge">
                            <?= $l['photo_count'] ?> photos
                        </span>
                        <span class="info-badge">
                            <?= $l['total_bookings'] ?> bookings
                        </span>
                        <?php if ($l['avg_rating'] > 0): ?>
                        <span class="info-badge">
                            &#9733; <?= number_format(
                                $l['avg_rating'], 1) ?>
                        </span>
                        <?php endif; ?>
                        <span class="info-badge">
                            Submitted <?= date('d M Y',
                                strtotime($l['created_at'])) ?>
                        </span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="listing-actions">
                    <?php
                    $pill = [
                        'pending'   => 's-pending',
                        'approved'  => 's-approved',
                        'rejected'  => 's-rejected',
                        'suspended' => 's-suspended'
                    ][$l['status']] ?? 's-pending';
                    $label = [
                        'pending'   => 'Pending',
                        'approved'  => 'Approved',
                        'rejected'  => 'Rejected',
                        'suspended' => 'Suspended'
                    ][$l['status']] ?? ucfirst($l['status']);
                    ?>
                    <span class="status-pill <?= $pill ?>">
                        <?= $label ?>
                    </span>

                    <a href="?filter=<?= $filter ?>
                              &review=<?= $l['accommodation_id'] ?>"
                       class="btn-view">
                        Review details
                    </a>

                    <?php if ($l['status'] === 'pending'): ?>
                    <form method="POST" style="width:100%">
                        <input type="hidden" name="listing_id"
                               value="<?= $l['accommodation_id'] ?>">
                        <input type="hidden" name="action"
                               value="approve">
                        <button type="submit" class="btn-approve">
                            Approve
                        </button>
                    </form>
                    <form method="POST" style="width:100%">
                        <input type="hidden" name="listing_id"
                               value="<?= $l['accommodation_id'] ?>">
                        <input type="hidden" name="action"
                               value="reject">
                        <div class="reason-form">
                            <textarea name="reason"
                                      placeholder="Rejection reason...">
                            </textarea>
                            <button type="submit"
                                    class="btn-reject"
                                    style="width:100%;
                                           margin-top:4px">
                                Reject
                            </button>
                        </div>
                    </form>

                    <?php elseif ($l['status'] === 'approved'): ?>
                    <form method="POST" style="width:100%">
                        <input type="hidden" name="listing_id"
                               value="<?= $l['accommodation_id'] ?>">
                        <input type="hidden" name="action"
                               value="suspend">
                        <div class="reason-form">
                            <textarea name="reason"
                                      placeholder="Suspension reason...">
                            </textarea>
                            <button type="submit"
                                    class="btn-suspend"
                                    style="width:100%;
                                           margin-top:4px">
                                Suspend
                            </button>
                        </div>
                    </form>

                    <?php elseif ($l['status'] === 'suspended'): ?>
                    <form method="POST" style="width:100%">
                        <input type="hidden" name="listing_id"
                               value="<?= $l['accommodation_id'] ?>">
                        <input type="hidden" name="action"
                               value="restore">
                        <button type="submit" class="btn-restore">
                            Restore listing
                        </button>
                    </form>
                    <?php endif; ?>

                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </main>
</div>

<?php include '../includes/footer.php'; ?>

</body>
</html>