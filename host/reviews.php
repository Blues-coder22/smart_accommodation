<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/review_extras.php';
ensureReviewExtraColumns($pdo);

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

publishDueReviews($pdo);

$host_id = $_SESSION['user_id'];

// Handle host reply submission
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['submit_reply'])) {
    requireCsrf();
    $review_id = (int)$_POST['review_id'];
    $reply     = sanitize($_POST['host_reply'] ?? '');

    if (!empty($reply)) {
        // Verify review belongs to host's property
        $check = $pdo->prepare("
            SELECT r.review_id FROM reviews r
            JOIN accommodations a
                ON a.accommodation_id = r.accommodation_id
            WHERE r.review_id = ? AND a.host_id = ?
        ");
        $check->execute([$review_id, $host_id]);

        if ($check->fetch()) {
            $pdo->prepare("
                UPDATE reviews SET host_reply = ?
                WHERE review_id = ?
            ")->execute([$reply, $review_id]);
            setFlash('success', 'Reply posted successfully.');
        }
    }
    redirect('reviews.php');
}

// Filter
$filter = sanitize($_GET['filter'] ?? 'all');

$where  = "a.host_id = ? AND r.is_published = 1";
$params = [$host_id];

if ($filter === 'unanswered') {
    $where .= " AND r.host_reply IS NULL";
} elseif ($filter === 'answered') {
    $where .= " AND r.host_reply IS NOT NULL";
}

// Get reviews
$reviews = $pdo->prepare("
    SELECT r.*,
           a.title AS property_title,
           u.full_name AS reviewer_name, u.profile_photo AS reviewer_photo,
           b.check_in_date, b.check_out_date
    FROM reviews r
    JOIN accommodations a
        ON a.accommodation_id = r.accommodation_id
    JOIN users u ON u.user_id = r.tourist_id
    JOIN bookings b ON b.booking_id = r.booking_id
    WHERE $where
    ORDER BY r.created_at DESC
");
$reviews->execute($params);
$reviews = $reviews->fetchAll();

// Summary stats
$stats = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        COALESCE(AVG(rating), 0) AS avg_rating,
        SUM(CASE WHEN host_reply IS NULL THEN 1 ELSE 0 END)
            AS unanswered,
        SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) AS five_star,
        SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) AS low_rating
    FROM reviews r
    JOIN accommodations a
        ON a.accommodation_id = r.accommodation_id
    WHERE a.host_id = ? AND r.is_published = 1
");
$stats->execute([$host_id]);
$stats = $stats->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        
        .dash-wrap   { display:grid;
                       grid-template-columns:200px 1fr; flex:1 }
        .sidebar     { background:var(--surface-2);
                       border-right:1px solid var(--border);
                       padding:24px 0; position:sticky;
                       top:52px; height:calc(100vh - 52px);
                       overflow-y:auto }
        .sidebar-role{ font-size:10px; font-weight:700;
                       letter-spacing:.08em; color:#1D9E75;
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
        .stats-row   { display:grid;
                       grid-template-columns:repeat(4,1fr);
                       gap:12px; margin-bottom:20px }
        .stat-card   { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:14px }
        .stat-card.accent { border-left:3px solid #1D9E75 }
        .stat-label  { font-size:10px; font-weight:700;
                       letter-spacing:.06em;
                       color:var(--text-secondary);
                       text-transform:uppercase; margin-bottom:6px }
        .stat-value  { font-size:22px; font-weight:500;
                       color:var(--text-primary) }
        .stat-note   { font-size:12px; color:var(--text-secondary);
                       margin-top:3px }
        .filter-tabs { display:flex; gap:6px; margin-bottom:16px }
        .filter-tab  { padding:6px 14px; font-size:12px;
                       border-radius:4px; cursor:pointer;
                       text-decoration:none;
                       color:var(--text-secondary);
                       border:1px solid var(--border) }
        .filter-tab.active { background:#085041; color:#fff;
                             border-color:#085041 }
        .review-card { background:var(--surface-2);
                       border:1px solid var(--border);
                       border-radius:8px; padding:18px;
                       margin-bottom:10px }
        .review-top  { display:flex; justify-content:space-between;
                       align-items:flex-start; margin-bottom:10px }
        .reviewer    { font-size:14px; font-weight:500 }
        .review-prop { font-size:12px; color:var(--text-secondary);
                       margin-top:2px }
        .review-date { font-size:12px; color:var(--text-secondary) }
        .stars       { display:flex; gap:2px; margin-bottom:8px }
        .star        { font-size:14px; color:#E5E7EB }
        .star.filled { color:#BA7517 }
        .review-text { font-size:14px; color:var(--text-primary);
                       line-height:1.6; margin-bottom:12px }
        .host-reply  { background:var(--surface-1);
                       border-left:3px solid #1D9E75;
                       padding:12px 14px; border-radius:0 6px 6px 0;
                       font-size:13px; color:var(--text-secondary);
                       line-height:1.5 }
        .host-reply strong { color:var(--text-primary);
                             display:block; margin-bottom:4px;
                             font-size:12px }
        .reply-form  { margin-top:12px;
                       padding-top:12px;
                       border-top:1px solid var(--border) }
        .reply-form textarea { width:100%; padding:10px 12px;
                               border:1px solid var(--border-strong);
                               border-radius:6px; font-size:13px;
                               color:var(--text-primary);
                               resize:vertical; min-height:80px;
                               font-family:inherit;
                               background:var(--surface-2) }
        .reply-form textarea:focus { outline:none;
                                     border-color:#1D9E75 }
        .reply-actions { display:flex; gap:8px; margin-top:8px }
        .btn-reply   { padding:7px 16px; background:#085041;
                       color:#fff; border:none; border-radius:4px;
                       font-size:12px; font-weight:500;
                       cursor:pointer }
        .btn-cancel  { padding:7px 14px; background:none;
                       color:var(--text-secondary);
                       border:1px solid var(--border-strong);
                       border-radius:4px; font-size:12px;
                       cursor:pointer }
        .toggle-reply{ padding:6px 14px; font-size:12px;
                       border-radius:4px; cursor:pointer;
                       color:var(--text-secondary);
                       border:1px solid var(--border-strong);
                       background:none }
        .toggle-reply:hover { border-color:#1D9E75; color:#1D9E75 }
        .empty-state { text-align:center; padding:48px 20px;
                       color:var(--text-secondary); font-size:14px }
        @media(max-width:900px){
            .dash-wrap { grid-template-columns:1fr }
            .sidebar   { display:none }
            .stats-row { grid-template-columns:repeat(2,1fr) }
        }
    </style>
</head>
<body>


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <?= showFlash() ?>

        <div class="page-tag">Host</div>
        <h1 class="page-title">Reviews</h1>

        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card accent">
                <div class="stat-label">Average rating</div>
                <div class="stat-value">
                    <?= $stats['avg_rating'] > 0
                        ? number_format($stats['avg_rating'], 1)
                        : '—' ?>
                </div>
                <div class="stat-note">
                    <?= $stats['total'] ?> total review
                    <?= $stats['total'] != 1 ? 's' : '' ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Unanswered</div>
                <div class="stat-value">
                    <?= $stats['unanswered'] ?>
                </div>
                <div class="stat-note">
                    <?= $stats['unanswered'] > 0
                        ? 'Needs response' : 'All responded' ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">5-star reviews</div>
                <div class="stat-value">
                    <?= $stats['five_star'] ?>
                </div>
                <div class="stat-note">Excellent ratings</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Low ratings</div>
                <div class="stat-value">
                    <?= $stats['low_rating'] ?>
                </div>
                <div class="stat-note">2 stars or below</div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <a href="?filter=all"
               class="filter-tab <?= $filter==='all'
                   ?'active':'' ?>">
                All (<?= $stats['total'] ?>)
            </a>
            <a href="?filter=unanswered"
               class="filter-tab <?= $filter==='unanswered'
                   ?'active':'' ?>">
                Unanswered (<?= $stats['unanswered'] ?>)
            </a>
            <a href="?filter=answered"
               class="filter-tab <?= $filter==='answered'
                   ?'active':'' ?>">
                Answered
            </a>
        </div>

        <?php if (empty($reviews)): ?>
            <div class="empty-state">
                <?php if ($filter === 'unanswered'): ?>
                    All reviews have been answered.
                <?php elseif ($filter === 'answered'): ?>
                    No answered reviews yet.
                <?php else: ?>
                    No reviews yet. Reviews appear here after
                    guests complete their stays.
                <?php endif; ?>
            </div>

        <?php else: ?>
            <?php foreach ($reviews as $r): ?>
            <div class="review-card">

                <!-- Top -->
                <div class="review-top">
                    <div>
                        <div class="reviewer">
                            <?= personChipHtml($r['tourist_id'], $r['reviewer_name'], $r['reviewer_photo'], 44, 'Guest · open profile') ?>
                        </div>
                        <div class="review-prop">
                            <?= h($r['property_title']) ?>
                            &middot;
                            <?= date('d M',
                                strtotime($r['check_in_date'])) ?>
                            &ndash;
                            <?= date('d M Y',
                                strtotime($r['check_out_date'])) ?>
                        </div>
                    </div>
                    <div class="review-date">
                        <?= date('d M Y',
                            strtotime($r['created_at'])) ?>
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
                        <?= $r['rating'] ?>/5
                    </span>
                </div>

                <?= categoryBarsHtml($r) ?>
                <!-- Review text -->
                <div class="review-text">
                    <?= nl2br(h($r['review_text'])) ?>
                </div>

                <!-- Host reply if exists -->
                <?php if ($r['host_reply']): ?>
                <div class="host-reply">
                    <strong>Your response</strong>
                    <?php if (!empty($r['host_reply_hidden'])): ?><em style="display:block;color:#a12b2b;font-size:12px">Hidden from the public by an administrator.</em><?php endif; ?>
                    <?= nl2br(h($r['host_reply'])) ?>
                </div>
                <?php endif; ?>

                <!-- Reply form -->
                <?php if (!$r['host_reply']): ?>
                <div class="reply-form"
                     id="reply-form-<?= $r['review_id'] ?>"
                     style="display:none">
                    <form method="POST">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="review_id"
                               value="<?= $r['review_id'] ?>">
                        <input type="hidden" name="submit_reply"
                               value="1">
                        <textarea name="host_reply"
                                  placeholder="Write a public response to this review..."
                                  required></textarea>
                        <div class="reply-actions">
                            <button type="submit" class="btn-reply">
                                Post response
                            </button>
                            <button type="button" class="btn-cancel"
                                    onclick="toggleReply(
                                        <?= $r['review_id'] ?>)">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>

                <div style="margin-top:10px"
                     id="reply-btn-<?= $r['review_id'] ?>">
                    <button class="toggle-reply"
                            onclick="toggleReply(
                                <?= $r['review_id'] ?>)">
                        Respond to review
                    </button>
                </div>
                <?php else: ?>
                <!-- Edit reply -->
                <div style="margin-top:10px">
                    <button class="toggle-reply"
                            onclick="toggleReply(
                                <?= $r['review_id'] ?>)">
                        Edit response
                    </button>
                </div>
                <div class="reply-form"
                     id="reply-form-<?= $r['review_id'] ?>"
                     style="display:none">
                    <form method="POST">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="review_id"
                               value="<?= $r['review_id'] ?>">
                        <input type="hidden" name="submit_reply"
                               value="1">
                        <textarea name="host_reply"
                                  required><?= h(
                            $r['host_reply']) ?></textarea>
                        <div class="reply-actions">
                            <button type="submit" class="btn-reply">
                                Update response
                            </button>
                            <button type="button" class="btn-cancel"
                                    onclick="toggleReply(
                                        <?= $r['review_id'] ?>)">
                                Cancel
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php appShellEnd(); ?>

<script>
function toggleReply(id) {
    const form = document.getElementById('reply-form-' + id);
    const btn  = document.getElementById('reply-btn-' + id);
    const show = form.style.display === 'none';
    form.style.display = show ? 'block' : 'none';
    if (btn) btn.style.display = show ? 'none' : 'block';
}
</script>

</body>
</html>