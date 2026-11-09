<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('admin');
require_once '../includes/review_risk.php';
require_once '../includes/review_extras.php';
ensureReviewExtraColumns($pdo);

publishDueReviews($pdo);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $review_id = (int)($_POST['review_id'] ?? 0);
    $action    = sanitize($_POST['action'] ?? '');

    // Host reviews of guests (guest_reviews): hide, restore or delete
    if (in_array($action, ['guest_hide', 'guest_show', 'guest_delete'], true)) {
        $gid = (int)($_POST['guest_review_id'] ?? 0);
        if ($action === 'guest_hide') {
            $pdo->prepare("UPDATE guest_reviews SET is_hidden = 1, hidden_at = NOW() WHERE guest_review_id = ?")->execute([$gid]);
            setFlash('success', 'Host review hidden. It no longer counts towards the guest\'s reputation.');
        } elseif ($action === 'guest_show') {
            $pdo->prepare("UPDATE guest_reviews SET is_hidden = 0, hidden_at = NULL WHERE guest_review_id = ?")->execute([$gid]);
            setFlash('success', 'Host review restored.');
        } else {
            $pdo->prepare("DELETE FROM guest_reviews WHERE guest_review_id = ?")->execute([$gid]);
            setFlash('success', 'Host review permanently deleted.');
        }
        redirect('reviews.php?type=host&filter=' . urlencode(sanitize($_GET['filter'] ?? 'visible')));
    }

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

            case 'hide_reply':
                $pdo->prepare("UPDATE reviews SET host_reply_hidden = 1 WHERE review_id = ?")->execute([$review_id]);
                setFlash('success', 'Host reply hidden from the public.');
                break;

            case 'show_reply':
                $pdo->prepare("UPDATE reviews SET host_reply_hidden = 0 WHERE review_id = ?")->execute([$review_id]);
                setFlash('success', 'Host reply is public again.');
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

$type   = (($_GET['type'] ?? 'guest') === 'host') ? 'host' : 'guest';
$filter = sanitize($_GET['filter'] ?? ($type === 'host' ? 'visible' : 'flagged'));

// Host reviews of guests
$hostReviews = []; $hostCounts = ['visible' => 0, 'hidden' => 0];
if ($type === 'host') {
    $hw = $filter === 'hidden' ? 'g.is_hidden = 1' : ($filter === 'all' ? '1=1' : 'g.is_hidden = 0');
    $hostReviews = $pdo->query("
        SELECT g.*, a.title AS property_title, a.accommodation_id,
               hu.user_id AS host_uid, hu.full_name AS host_name, hu.profile_photo AS host_photo,
               tu.user_id AS tourist_uid, tu.full_name AS tourist_name, tu.profile_photo AS tourist_photo,
               b.check_in_date, b.check_out_date
        FROM guest_reviews g
        JOIN bookings b ON b.booking_id = g.booking_id
        JOIN accommodations a ON a.accommodation_id = b.accommodation_id
        JOIN users hu ON hu.user_id = g.host_id
        JOIN users tu ON tu.user_id = g.tourist_id
        WHERE $hw ORDER BY g.created_at DESC")->fetchAll();
}
try {
    $hc = $pdo->query("SELECT SUM(is_hidden = 0) AS v, SUM(is_hidden = 1) AS h FROM guest_reviews")->fetch();
    $hostCounts = ['visible' => (int)($hc['v'] ?? 0), 'hidden' => (int)($hc['h'] ?? 0)];
} catch (Throwable $e) {}

// Build query
$where = "1=1";
if ($filter === 'flagged') {
    $where = "r.is_flagged = 1 AND r.is_published = 0";
} elseif ($filter === 'published') {
    $where = "r.is_published = 1";
} elseif ($filter === 'risk') {
    $where = "r.tourist_submitted = 1";
} elseif ($filter === 'removed') {
    $where = "r.is_published = 0 AND r.is_flagged = 0
              AND r.tourist_submitted = 1";
}

if ($type === 'host') { $where = "0=1"; }
$reviews = $pdo->query("
    SELECT r.*,
           a.title AS property_title,
           a.accommodation_id,
           u.profile_photo AS tourist_photo,
           a.host_id, hu.full_name AS host_name, hu.profile_photo AS host_photo,
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
    JOIN users hu ON hu.user_id = a.host_id
    JOIN bookings b ON b.booking_id = r.booking_id
    WHERE $where
    ORDER BY r.created_at DESC
")->fetchAll();

// Risk score for every review shown; the "High risk" tab keeps only the reviews scoring 60 or more.
foreach ($reviews as &$rv) { $rv['risk'] = reviewRisk($pdo, $rv); }
unset($rv);
if ($filter === 'risk') {
    $reviews = array_values(array_filter($reviews, function ($x) { return $x['risk']['score'] >= 60; }));
    usort($reviews, function ($a, $b) { return $b['risk']['score'] <=> $a['risk']['score']; });
}

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
    <title>Review Moderation — StayExplore Kenya</title>
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
                       letter-spacing:.08em; color:#b42318;
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
                               border-left:3px solid #126b4e }
        .badge       { font-size:10px; background:#DC2626;
                       color:#fff; border-radius:10px;
                       padding:1px 6px; margin-left:auto }
        .main        { padding:28px }
        .page-tag    { font-size:11px; font-weight:600;
                       letter-spacing:.06em; color:#126b4e;
                       text-transform:uppercase; margin-bottom:8px }
        .page-title  { font-size:26px; font-weight:400;
                       margin-bottom:4px }
        .page-sub    { font-size:13px; color:var(--text-secondary);
                       margin-bottom:20px }
        .filter-tabs { display:flex; gap:6px; margin-bottom:20px }
        .filter-tab  { padding:6px 14px; font-size:12px;
                       border-radius:4px; text-decoration:none;
                       color:var(--text-secondary);
                       border:1px solid var(--border);
                       display:flex; align-items:center; gap:6px }
        .filter-tab.active { background:#085041; color:#fff;
                             border-color:#085041 }
        .review-card { background:var(--surface-2);
                       border:1px solid var(--border);
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
                       border-radius:6px; border:1px solid var(--border) }
        .suspicion-box { background:#FFF8E1;
                         border:1px solid #F9D57C;
                         border-radius:6px; padding:12px 14px;
                         margin-bottom:14px }
        .suspicion-box h4 { font-size:12px; font-weight:600;
                            color:#7a5200; margin-bottom:8px;
                            text-transform:uppercase;
                            letter-spacing:.04em }
        .score-row   { display:flex; justify-content:space-between;
                       align-items:center; margin-bottom:4px }
        .score-label { font-size:12px; color:#7a5200 }
        .score-val   { font-size:14px; font-weight:700; color:#7a5200 }
        .score-bar   { height:4px; background:#F9D57C;
                       border-radius:2px; margin-top:6px }
        .score-fill  { height:100%; background:#8f4b00;
                       border-radius:2px }
        .consistency { background:var(--surface-1);
                       border:1px solid var(--border);
                       border-radius:6px; padding:12px 14px;
                       margin-bottom:14px }
        .consistency h4 { font-size:12px; font-weight:600;
                          color:var(--text-secondary);
                          margin-bottom:8px;
                          text-transform:uppercase;
                          letter-spacing:.04em }
        .consist-stat { display:flex; justify-content:space-between;
                        font-size:12px; padding:4px 0;
                        border-bottom:1px solid var(--border) }
        .consist-stat:last-child { border-bottom:none }
        .consist-label { color:var(--text-secondary) }
        .consist-val   { font-weight:500 }
        .review-actions { display:flex; gap:8px; flex-wrap:wrap }
        .btn-publish { padding:7px 16px; background:#085041;
                       color:#fff; border:none; border-radius:4px;
                       font-size:12px; font-weight:500; cursor:pointer }
        .btn-remove  { padding:7px 16px; background:none;
                       color:#8f4b00; border:1px solid #F9D57C;
                       border-radius:4px; font-size:12px;
                       font-weight:500; cursor:pointer }
        .btn-delete  { padding:7px 16px; background:none;
                       color:#b42318; border:1px solid #FECACA;
                       border-radius:4px; font-size:12px;
                       font-weight:500; cursor:pointer }
        .pub-badge   { display:inline-block; font-size:11px;
                       font-weight:600; padding:3px 9px;
                       border-radius:3px }
        .b-flagged   { background:#FFF8E1; color:#7a5200;
                       border:1px solid #F9D57C }
        .b-published { background:#E1F5EE; color:#0F6E56;
                       border:1px solid #9FE1CB }
        .b-removed   { background:#F5F3FF; color:#5B21B6;
                       border:1px solid #C4B5FD }
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


<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>

        <?= showFlash() ?>

        <div class="page-tag">Admin</div>
        <h1 class="page-title">Review moderation</h1>
        <p class="page-sub">
            Focused tools for fair and traceable decisions.
        </p>

        <!-- Which reviews: guests about stays, or hosts about guests -->
        <div class="filter-tabs" style="margin-bottom:12px">
            <a href="?type=guest" class="filter-tab <?= $type==='guest' ? 'active' : '' ?>">Guest reviews of stays</a>
            <a href="?type=host" class="filter-tab <?= $type==='host' ? 'active' : '' ?>">Host reviews of guests</a>
        </div>

        <?php if ($type === 'guest'): ?>
        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <a href="?filter=flagged"
               class="filter-tab <?= $filter==='flagged'
                   ?'active':'' ?>">
                Flagged
                <span style="font-size:11px">
                    (<?= $counts['flagged'] ?>)
                </span>
            </a>
            <a href="?filter=published"
               class="filter-tab <?= $filter==='published'
                   ?'active':'' ?>">
                Published
                <span style="font-size:11px">
                    (<?= $counts['published'] ?>)
                </span>
            </a>
            <a href="?filter=risk"
               class="filter-tab <?= $filter==='risk' ? 'active' : '' ?>">High risk</a>
            <a href="?filter=removed"
               class="filter-tab <?= $filter==='removed'
                   ?'active':'' ?>">
                Removed
                <span style="font-size:11px">
                    (<?= $counts['removed'] ?>)
                </span>
            </a>
        </div>

        <details class="explainer" style="margin-bottom:16px">
            <summary>How does review checking work? (plain explanation)</summary>
            <div class="ex-body">
                <p>Every new review is looked at by a simple checklist, a bit like a shop assistant glancing at a banknote. Nothing is decided by a computer on its own. The checklist adds points when something looks unusual, and a higher total means &ldquo;take a closer look first&rdquo;.</p>
                <p><strong>What adds points:</strong> a host reported the review or its author; it contains a link, email address or phone number (often advertising); the same words appear in another review; the same person posted several reviews within minutes; it is very short or all in capital letters; the rating is very different from what other guests gave the same place.</p>
                <p><strong>What the number means:</strong> under 30 is low (looks normal), 30 to 59 is medium (worth a glance), 60 and above is high (check before it appears). A high number is a hint, not proof. A real guest can trigger it, so read the review and use your judgement. Reviews are only hidden or removed when an administrator decides.</p>
            </div>
        </details>
        <p style="font-size:12px;color:var(--text-secondary);margin:-6px 0 16px"><strong>Risk score (0&ndash;100).</strong> A rule-based guide to what you should check first: host reports, links or phone numbers, identical text, several reviews in minutes, very short or all-capitals text, and a rating far from the listing's average. It is a signal, not proof, and nothing is removed automatically.</p>
        <?php endif; ?>

        <?php if ($type === 'host'): ?>
            <div class="filter-tabs">
                <a href="?type=host&filter=visible" class="filter-tab <?= $filter==='visible' ? 'active' : '' ?>">Visible <span style="font-size:11px">(<?= $hostCounts['visible'] ?>)</span></a>
                <a href="?type=host&filter=hidden" class="filter-tab <?= $filter==='hidden' ? 'active' : '' ?>">Hidden <span style="font-size:11px">(<?= $hostCounts['hidden'] ?>)</span></a>
                <a href="?type=host&filter=all" class="filter-tab <?= $filter==='all' ? 'active' : '' ?>">All</a>
            </div>
            <p style="font-size:12px;color:var(--text-secondary);margin:-6px 0 16px">These are ratings hosts gave to guests after a stay. Hiding one removes it from the guest&rsquo;s reputation. The guest and host keep their accounts.</p>
            <?php if (!$hostReviews): ?>
                <div class="empty-state">No host reviews here yet.</div>
            <?php endif; ?>
            <?php foreach ($hostReviews as $g): ?>
            <div class="review-card <?= !empty($g['is_hidden']) ? 'flagged' : '' ?>">
                <div class="review-top">
                    <div>
                        <div style="display:flex;gap:28px;flex-wrap:wrap">
                            <div><div style="font-size:11px;color:#4f5f57;margin-bottom:3px">Host (wrote this)</div><?= personChipHtml($g['host_uid'], $g['host_name'], $g['host_photo'], 40, 'Host') ?></div>
                            <div><div style="font-size:11px;color:#4f5f57;margin-bottom:3px">About the guest</div><?= personChipHtml($g['tourist_uid'], $g['tourist_name'], $g['tourist_photo'], 40, 'Tourist') ?></div>
                        </div>
                        <div class="review-prop" style="margin-top:8px"><?= h($g['property_title']) ?> &middot; <?= date('d M', strtotime($g['check_in_date'])) ?> &ndash; <?= date('d M Y', strtotime($g['check_out_date'])) ?></div>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center">
                        <span class="pub-badge <?= !empty($g['is_hidden']) ? 'b-removed' : 'b-published' ?>"><?= !empty($g['is_hidden']) ? 'Hidden' : 'Visible' ?></span>
                        <div class="review-date"><?= date('d M Y', strtotime($g['created_at'])) ?></div>
                    </div>
                </div>
                <div class="stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?><span class="star <?= $i <= $g['rating'] ? 'filled' : '' ?>">&#9733;</span><?php endfor; ?>
                    <span style="font-size:12px;color:var(--text-secondary);margin-left:6px"><?= (int)$g['rating'] ?> / 5</span>
                </div>
                <div class="review-text"><?= $g['review_text'] !== null && $g['review_text'] !== '' ? nl2br(h($g['review_text'])) : '<em style="color:#565c55">No written comment.</em>' ?></div>
                <div class="review-actions">
                    <form method="POST" style="margin:0">
                        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="guest_review_id" value="<?= (int)$g['guest_review_id'] ?>">
                        <input type="hidden" name="action" value="<?= !empty($g['is_hidden']) ? 'guest_show' : 'guest_hide' ?>">
                        <button type="submit" class="<?= !empty($g['is_hidden']) ? 'btn-publish' : 'btn-remove' ?>"><?= !empty($g['is_hidden']) ? 'Restore review' : 'Hide review' ?></button>
                    </form>
                    <form method="POST" style="margin:0" onsubmit="return confirm('Permanently delete this host review? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="guest_review_id" value="<?= (int)$g['guest_review_id'] ?>">
                        <input type="hidden" name="action" value="guest_delete">
                        <button type="submit" class="btn-delete">Delete permanently</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
        <?php if (empty($reviews)): ?>
            <div class="empty-state">
                <?php if ($filter === 'flagged'): ?>
                    No flagged reviews. The anomaly detection
                    system has not flagged any reviews.
                <?php elseif ($filter === 'published'): ?>
                    No published reviews yet.
                <?php elseif ($filter === 'risk'): ?>
                    No high-risk reviews (risk score 60 or more).
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
                        <div style="display:flex;gap:28px;flex-wrap:wrap">
                            <div><div style="font-size:11px;color:#4f5f57;margin-bottom:3px">Guest (wrote this)</div><?= personChipHtml($r['tourist_id'], $r['full_tourist_name'], $r['tourist_photo'], 40, 'Tourist') ?></div>
                            <div><div style="font-size:11px;color:#4f5f57;margin-bottom:3px">Host of the stay</div><?= personChipHtml($r['host_id'], $r['host_name'], $r['host_photo'], 40, 'Host') ?></div>
                        </div>
                        <div class="review-prop">
                            <?= h(
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

                <!-- Risk score (0-100): a guide for what to check first -->
                <?php $risk = $r['risk']; $rc = ['low' => ['#E1F5EE', '#0F6E56'], 'medium' => ['#FFF3D6', '#7a5200'], 'high' => ['#FDECEC', '#A12B2B']][$risk['level']]; ?>
                <div style="margin:8px 0 4px">
                    <span title="<?= h($risk['reasons'] ? implode("\n", $risk['reasons']) : 'No risk signals found') ?>"
                          style="display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;background:<?= $rc[0] ?>;color:<?= $rc[1] ?>">
                        Risk <?= (int)$risk['score'] ?>/100 &middot; <?= ucfirst($risk['level']) ?>
                    </span>
                    <?php if ($risk['reasons']): ?>
                    <ul style="margin:6px 0 0 18px;font-size:12px;color:var(--text-secondary)">
                        <?php foreach ($risk['reasons'] as $why): ?><li><?= h($why) ?></li><?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>

                <?= categoryBarsHtml($r) ?>

                <!-- Review Text -->
                <div class="review-text">
                    <?= nl2br(h($r['review_text'])) ?>
                </div>

                <?php if (!empty($r['host_reply'])): ?>
                <div style="margin-top:10px;padding:10px 12px;background:#e8f8f3;border-left:3px solid #126b4e;border-radius:0 6px 6px 0;font-size:13px">
                    <strong>Host reply</strong> <?= !empty($r['host_reply_hidden']) ? '<span style="color:#a12b2b;font-size:12px">(hidden from public)</span>' : '' ?><br>
                    <?= nl2br(h($r['host_reply'])) ?>
                    <form method="POST" style="margin:8px 0 0">
                        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="review_id" value="<?= (int)$r['review_id'] ?>">
                        <input type="hidden" name="action" value="<?= !empty($r['host_reply_hidden']) ? 'show_reply' : 'hide_reply' ?>">
                        <button type="submit" class="btn-remove"><?= !empty($r['host_reply_hidden']) ? 'Make reply public' : 'Hide host reply' ?></button>
                    </form>
                </div>
                <?php endif; ?>

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
                    <div style="font-size:12px;color:#7a5200;
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
                                  ? 'color:#b42318' : '' ?>">
                            <?= $r['rating'] ?> / 5
                            <?php if (
                                abs($r['rating']
                                    - $r['prop_avg_rating']) >= 2
                                && $r['other_prop_reviews'] > 0): ?>
                                <span style="font-size:11px;
                                             color:#b42318">
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
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
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
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
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
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
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
                              border:1px solid var(--border-strong);
                              border-radius:4px;text-decoration:none">
                        View listing
                    </a>
                </div>

            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php endif; ?>

    <?php appShellEnd(); ?>

</body>
</html>