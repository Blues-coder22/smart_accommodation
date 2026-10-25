<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('admin');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $review_id = (int)($_POST['review_id'] ?? 0);
    $action    = sanitize($_POST['action'] ?? '');

    $stmt = $pdo->prepare("
        SELECT r.*, a.title AS property_title,
               u.email AS tourist_email,
               u.full_name AS tourist_name
        FROM reviews r
        JOIN accommodations a
            ON a.accommodation_id = r.accommodation_id
        JOIN users u ON u.user_id = r.tourist_id
        WHERE r.review_id = ?
    ");
    $stmt->execute([$review_id]);
    $review = $stmt->fetch();

    if ($review) {
        switch ($action) {
            case 'publish':
                $pdo->prepare("
                    UPDATE reviews
                    SET is_published = 1, is_flagged = 0
                    WHERE review_id = ?
                ")->execute([$review_id]);
                setFlash('success', 'Review published.');
                break;

            case 'remove':
                $pdo->prepare("
                    UPDATE reviews
                    SET is_published = 0, is_flagged = 1
                    WHERE review_id = ?
                ")->execute([$review_id]);
                setFlash('success', 'Review removed from public view.');
                break;

            case 'delete':
                $pdo->prepare("
                    DELETE FROM reviews WHERE review_id = ?
                ")->execute([$review_id]);
                setFlash('success', 'Review permanently deleted.');
                break;
        }
    }
    redirect('reviews.php?filter=' .
             sanitize($_GET['filter'] ?? 'flagged'));
}

$filter = sanitize($_GET['filter'] ?? 'flagged');

// Build query
$where = "1=1";
if ($filter === 'flagged') {
    $where = "r.is_flagged = 1 AND r.is_published = 0";
} elseif ($filter === 'published') {
    $where = "r.is_published = 1";
} elseif ($filter === 'removed') {
    $where = "r.is_published = 0 AND r.is_flagged = 0
              AND r.tourist_submitted = 1";
}

$reviews = $pdo->query("
    SELECT r.*,
           a.title AS property_title,
           a.accommodation_id,
           SUBSTRING(u.full_name, 1,
               LOCATE(' ', CONCAT(u.full_name,' '))-1
           ) AS first_name,
           u.full_name AS full_tourist_name,
           u.created_at AS account_created,
           b.check_out_date,
           (SELECT COUNT(*) FROM reviews r2
            WHERE r2.tourist_id = r.tourist_id
            AND r2.review_id != r.review_id) AS other_reviews,
           (SELECT COUNT(*) FROM reviews r3
            WHERE r3.accommodation_id = r.accommodation_id
            AND r3.is_published = 1
            AND r3.review_id != r.review_id) AS other_prop_reviews,
           (SELECT COALESCE(AVG(r4.rating),0)
            FROM reviews r4
            WHERE r4.accommodation_id = r.accommodation_id
            AND r4.is_published = 1
            AND r4.review_id != r.review_id) AS prop_avg_rating
    FROM reviews r
    JOIN accommodations a
        ON a.accommodation_id = r.accommodation_id
    JOIN users u ON u.user_id = r.tourist_id
    JOIN bookings b ON b.booking_id = r.booking_id
    WHERE $where
    ORDER BY r.created_at DESC
")->fetchAll();

// Counts
$counts = $pdo->query("
    SELECT
        SUM(is_flagged=1 AND is_published=0) AS flagged,
        SUM(is_published=1) AS published,
        SUM(is_published=0 AND is_flagged=0
            AND tourist_submitted=1) AS removed
    FROM reviews
")->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Moderation — Smart Accommodation Kenya</title>
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
                       margin-bottom:4px }
        .page-sub    { font-size:13px; color:var(--text-secondary);
                       margin-bottom:20px }
        .filter-tabs { display:flex; gap:6px; margin-bottom:20px }
        .filter-tab  { padding:6px 14px; font-size:12px;
                       border-radius:4px; text-decoration:none;
                       color:var(--text-secondary);
                       border:0.5px solid var(--border);
                       display:flex; align-items:center; gap:6px }
        .filter-tab.active { background:#085041; color:#fff;
                             border-color:#085041 }
        .review-card { background:var(--surface-2);
                       border:0.5px solid var(--border);
                       border-radius:8px; padding:20px;
                       margin-bottom:12px }
        .review-card.flagged { border-color:#F9D57C }
        .review-top  { display:flex; justify-content:space-between;
                       align-items:flex-start; margin-bottom:12px }
        .reviewer    { font-size:14px; font-weight:500;
                       margin-bottom:2px }
        .review-prop { font-size:12px; color:var(--text-secondary) }
        .review-date { font-size:12px; color:var(--text-secondary) }
        .stars       { display:flex; gap:2px; margin-bottom:8px }
        .star        { font-size:14px; color:#E5E7EB }
        .star.filled { color:#BA7517 }
        .review-text { font-size:14px; color:var(--text-primary);
                       line-height:1.6; margin-bottom:14px;
                       padding:12px; background:var(--surface-1);
                       border-radius:6px; border:0.5px solid var(--border) }
        .suspicion-box { background:#FFF8E1;
                         border:0.5px solid #F9D57C;
                         border-radius:6px; padding:12px 14px;
                         margin-bottom:14px }
        .suspicion-box h4 { font-size:12px; font-weight:600;
                            color:#9A6A00; margin-bottom:8px;
                            text-transform:uppercase;
                            letter-spacing:.04em }
        .score-row   { display:flex; justify-content:space-between;
                       align-items:center; margin-bottom:4px }
        .score-label { font-size:12px; color:#9A6A00 }
        .score-val   { font-size:14px; font-weight:700; color:#9A6A00 }
        .score-bar   { height:4px; background:#F9D57C;
                       border-radius:2px; margin-top:6px }
        .score-fill  { height:100%; background:#D97706;
                       border-radius:2px }
        .consistency { background:var(--surface-1);
                       border:0.5px solid var(--border);
                       border-radius:6px; padding:12px 14px;
                       margin-bottom:14px }
        .consistency h4 { font-size:12px; font-weight:600;
                          color:var(--text-secondary);
                          margin-bottom:8px;
                          text-transform:uppercase;
                          letter-spacing:.04em }
        .consist-stat { display:flex; justify-content:space-between;
                        font-size:12px; padding:4px 0;
                        border-bottom:0.5px solid var(--border) }
        .consist-stat:last-child { border-bottom:none }
        .consist-label { color:var(--text-secondary) }
        .consist-val   { font-weight:500 }
        .review-actions { display:flex; gap:8px; flex-wrap:wrap }
        .btn-publish { padding:7px 16px; background:#085041;
                       color:#fff; border:none; border-radius:4px;
                       font-size:12px; font-weight:500; cursor:pointer }
        .btn-remove  { padding:7px 16px; background:none;
                       color:#D97706; border:0.5px solid #F9D57C;
                       border-radius:4px; font-size:12px;
                       font-weight:500; cursor:pointer }
        .btn-delete  { padding:7px 16px; background:none;
                       color:#DC2626; border:0.5px solid #FECACA;
                       border-radius:4px; font-size:12px;
                       font-weight:500; cursor:pointer }
        .pub-badge   { display:inline-block; font-size:11px;
                       font-weight:600; padding:3px 9px;
                       border-radius:3px }
        .b-flagged   { background:#FFF8E1; color:#9A6A00;
                       border:0.5px solid #F9D57C }
        .b-published { background:#E1F5EE; color:#0F6E56;
                       border:0.5px solid #9FE1CB }
        .b-removed   { background:#F5F3FF; color:#5B21B6;
                       border:0.5px solid #C4B5FD }
        .empty-state { text-align:center; padding:48px;
                       font-size:14px;
                       color:var(--text-secondary) }
        @media(max-width:900px){
            .dash-wrap { grid-template-columns:1fr }
            .sidebar   { display:none }
        }
    </style>
</head>
<body>

<?php include '../includes/header.php'; ?>

<div class="dash-wrap">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-role">Admin</div>
        <a href="dashboard.php" class="sidebar-link">Overview</a>
        <a href="listings.php" class="sidebar-link">
            Listing approvals
        </a>
        <a href="users.php" class="sidebar-link">Users</a>
        <a href="reviews.php" class="sidebar-link active">
            Review moderation
            <?php if ($counts['flagged'] > 0): ?>
                <span class="badge"><?= $counts['flagged'] ?></span>
            <?php endif; ?>
        </a>
        <a href="reports.php" class="sidebar-link">Reports</a>
        <a href="attractions.php" class="sidebar-link">Attractions</a>
        <a href="profile.php" class="sidebar-link">Profile</a>
    </aside>

    <main class="main">

        <?= showFlash() ?>

        <div class="page-tag">Admin</div>
        <h1 class="page-title">Review moderation</h1>
        <p class="page-sub">
            Focused tools for fair and traceable decisions.
        </p>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <a href="?filter=flagged"
               class="filter-tab <?= $filter==='flagged'
                   ?'active':'' ?>">
                Flagged
                <span style="font-size:10px;opacity:.8">
                    (<?= $counts['flagged'] ?>)
                </span>
            </a>
            <a href="?filter=published"
               class="filter-tab <?= $filter==='published'
                   ?'active':'' ?>">
                Published
                <span style="font-size:10px;opacity:.8">
                    (<?= $counts['published'] ?>)
                </span>
            </a>
            <a href="?filter=removed"
               class="filter-tab <?= $filter==='removed'
                   ?'active':'' ?>">
                Removed
                <span style="font-size:10px;opacity:.8">
                    (<?= $counts['removed'] ?>)
                </span>
            </a>
        </div>

        <?php if (empty($reviews)): ?>
            <div class="empty-state">
                <?php if ($filter === 'flagged'): ?>
                    No flagged reviews. The anomaly detection
                    system has not flagged any reviews.
                <?php elseif ($filter === 'published'): ?>
                    No published reviews yet.
                <?php else: ?>
                    No removed reviews.
                <?php endif; ?>
            </div>

        <?php else: ?>
            <?php foreach ($reviews as $r): ?>
            <div class="review-card <?= $r['is_flagged']
                && !$r['is_published'] ? 'flagged' : '' ?>">

                <!-- Top Row -->
                <div class="review-top">
                    <div>
                        <div class="reviewer">
                            <?= htmlspecialchars($r['first_name']) ?>
                            <span style="font-size:12px;
                                         font-weight:400;
                                         color:var(--text-secondary)">
                                &mdash;
                                <?= htmlspecialchars(
                                    $r['full_tourist_name']) ?>
                            </span>
                        </div>
                        <div class="review-prop">
                            <?= htmlspecialchars(
                                $r['property_title']) ?>
                            &middot; Stay ended
                            <?= date('d M Y',
                                strtotime($r['check_out_date'])) ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;
                                align-items:center">
                        <?php
                        if ($r['is_flagged'] && !$r['is_published']) {
                            echo '<span class="pub-badge b-flagged">
                                Flagged</span>';
                        } elseif ($r['is_published']) {
                            echo '<span class="pub-badge b-published">
                                Published</span>';
                        } else {
                            echo '<span class="pub-badge b-removed">
                                Removed</span>';
                        }
                        ?>
                        <div class="review-date">
                            <?= date('d M Y',
                                strtotime($r['created_at'])) ?>
                        </div>
                    </div>
                </div>

                <!-- Stars -->
                <div class="stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="star <?= $i <= $r['rating']
                        ? 'filled' : '' ?>">&#9733;</span>
                    <?php endfor; ?>
                    <span style="font-size:12px;
                                 color:var(--text-secondary);
                                 margin-left:6px">
                        <?= $r['rating'] ?> / 5
                    </span>
                </div>

                <!-- Review Text -->
                <div class="review-text">
                    <?= nl2br(htmlspecialchars($r['review_text'])) ?>
                </div>

                <!-- Suspicion Score — shown for flagged reviews -->
                <?php if ($r['suspicion_score'] > 0): ?>
                <div class="suspicion-box">
                    <h4>Anomaly detection score</h4>
                    <div class="score-row">
                        <span class="score-label">
                            Suspicion score
                        </span>
                        <span class="score-val">
                            <?= $r['suspicion_score'] ?> / 8
                        </span>
                    </div>
                    <div class="score-bar">
                        <div class="score-fill"
                             style="width:<?= min(100,
                                 ($r['suspicion_score']/8)*100) ?>%">
                        </div>
                    </div>
                    <div style="font-size:12px;color:#9A6A00;
                                margin-top:8px">
                        <?php
                        $account_age = floor(
                            (time() - strtotime($r['account_created']))
                            / 86400
                        );
                        $hints = [];
                        if ($account_age < 7)
                            $hints[] = 'Account is '
                                . $account_age . ' days old';
                        if ($r['other_reviews'] == 0)
                            $hints[] = 'First ever review by this user';
                        if ($r['rating'] == 1 || $r['rating'] == 5)
                            $hints[] = 'Extreme rating ('
                                . $r['rating'] . ' stars)';
                        if (str_word_count($r['review_text']) < 10)
                            $hints[] = 'Very short review ('
                                . str_word_count($r['review_text'])
                                . ' words)';
                        echo implode(' &middot; ', $hints);
                        ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Consistency Check -->
                <div class="consistency">
                    <h4>Review consistency</h4>
                    <div class="consist-stat">
                        <span class="consist-label">
                            Other reviews for this property
                        </span>
                        <span class="consist-val">
                            <?= $r['other_prop_reviews'] ?>
                        </span>
                    </div>
                    <div class="consist-stat">
                        <span class="consist-label">
                            Property average rating
                            (excluding this)
                        </span>
                        <span class="consist-val">
                            <?= $r['other_prop_reviews'] > 0
                                ? number_format($r['prop_avg_rating'], 1)
                                . ' / 5' : 'No other reviews' ?>
                        </span>
                    </div>
                    <div class="consist-stat">
                        <span class="consist-label">
                            This review rating
                        </span>
                        <span class="consist-val"
                              style="<?= abs($r['rating']
                                  - $r['prop_avg_rating']) >= 2
                                  && $r['other_prop_reviews'] > 0
                                  ? 'color:#DC2626' : '' ?>">
                            <?= $r['rating'] ?> / 5
                            <?php if (
                                abs($r['rating']
                                    - $r['prop_avg_rating']) >= 2
                                && $r['other_prop_reviews'] > 0): ?>
                                <span style="font-size:11px;
                                             color:#DC2626">
                                    (<?= $r['rating']
                                        > $r['prop_avg_rating']
                                        ? 'significantly higher'
                                        : 'significantly lower' ?>
                                    than average)
                                </span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="consist-stat">
                        <span class="consist-label">
                            Other reviews by this tourist
                        </span>
                        <span class="consist-val">
                            <?= $r['other_reviews'] ?>
                        </span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="review-actions">
                    <?php if (!$r['is_published']): ?>
                    <form method="POST" style="margin:0">
                        <input type="hidden" name="review_id"
                               value="<?= $r['review_id'] ?>">
                        <input type="hidden" name="action"
                               value="publish">
                        <button type="submit" class="btn-publish">
                            Publish review
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($r['is_published']): ?>
                    <form method="POST" style="margin:0">
                        <input type="hidden" name="review_id"
                               value="<?= $r['review_id'] ?>">
                        <input type="hidden" name="action"
                               value="remove">
                        <button type="submit" class="btn-remove"
                                onclick="return confirm(
                                    'Remove this review from public view?')">
                            Remove from public
                        </button>
                    </form>
                    <?php endif; ?>

                    <form method="POST" style="margin:0">
                        <input type="hidden" name="review_id"
                               value="<?= $r['review_id'] ?>">
                        <input type="hidden" name="action"
                               value="delete">
                        <button type="submit" class="btn-delete"
                                onclick="return confirm(
                                    'Permanently delete this review? This cannot be undone.')">
                            Delete permanently
                        </button>
                    </form>

                    <a href="../tourist/details.php?id=<?= $r['accommodation_id'] ?>"
                       target="_blank"
                       style="padding:7px 14px;font-size:12px;
                              color:var(--text-secondary);
                              border:0.5px solid var(--border-strong);
                              border-radius:4px;text-decoration:none">
                        View listing
                    </a>
                </div>

            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </main>
</div>

<?php include '../includes/footer.php'; ?>

</body>
</html>