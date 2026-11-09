<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/email.php';
require_once '../includes/review_extras.php';
ensureReviewExtraColumns($pdo);

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('tourist');

$booking_id = (int)($_GET['booking_id'] ?? 0);

if (!$booking_id) {
    redirect('bookings.php');
}

// Get booking — must be completed and belong to this tourist
$stmt = $pdo->prepare("
    SELECT b.*,
           a.title, a.location_name, a.accommodation_id,
           a.host_id,
           (SELECT photo_path FROM accommodation_photos
            WHERE accommodation_id = a.accommodation_id
            ORDER BY is_primary DESC, photo_id ASC LIMIT 1) AS primary_photo
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    WHERE b.booking_id = ?
    AND b.tourist_id = ?
    AND b.status = 'completed'
");
$stmt->execute([$booking_id, $_SESSION['user_id']]);
$booking = $stmt->fetch();

if (!$booking) {
    setFlash('error', 'You can only review completed bookings.');
    redirect('bookings.php');
}

// Check if review already submitted
$existing = $pdo->prepare("
    SELECT review_id, tourist_submitted
    FROM reviews
    WHERE booking_id = ? AND tourist_id = ?
");
$existing->execute([$booking_id, $_SESSION['user_id']]);
$existing = $existing->fetch();

if ($existing && $existing['tourist_submitted']) {
    setFlash('error', 'You have already submitted a review for this booking.');
    redirect('bookings.php');
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $rating      = (int)($_POST['rating'] ?? 0);
    $review_text = sanitize($_POST['review_text'] ?? '');

    if ($rating < 1 || $rating > 5) {
        $errors[] = 'Please select a rating between 1 and 5.';
    }
    $cats = [];
    foreach (reviewCategoryLabels() as $col => $label) {
        $v = (int)($_POST[$col] ?? 0);
        if ($v < 1 || $v > 5) { $errors[] = 'Please rate the ' . strtolower($label) . ' from 1 to 5.'; }
        $cats[$col] = $v;
    }
    if (strlen(trim($review_text)) < 10) {
        $errors[] = 'Please write at least 10 characters in your review.';
    }

    if (empty($errors)) {
        // Calculate suspicion score
        $suspicion = calculateSuspicionScore(
            $_SESSION['user_id'],
            $booking_id,
            $rating,
            $review_text,
            $pdo
        );

        $is_flagged = ($suspicion >= 5) ? 1 : 0;

        // Simultaneous reveal logic
        // Check if host has already submitted their review
        $host_submitted = $existing
            ? (int)$existing['host_submitted'] : 0;

        // Publish immediately only if host already submitted
        // Otherwise wait for host or 14 days
        $checkout      = new DateTime($booking['check_out_date']);
        $now           = new DateTime();
        $days_since    = $now->diff($checkout)->days;
        $auto_publish  = ($host_submitted || $days_since >= 14) ? 1 : 0;

        // Final publish decision — flagged reviews never auto publish
        $is_published  = ($is_flagged) ? 0 : $auto_publish;

        if ($existing) {
            // Update existing record
            $pdo->prepare("
                UPDATE reviews
                SET rating = ?, review_text = ?,
                    suspicion_score = ?, is_flagged = ?,
                    tourist_submitted = 1, is_published = ?
                WHERE booking_id = ? AND tourist_id = ?
            ")->execute([
                $rating, $review_text,
                $suspicion, $is_flagged,
                $is_published,
                $booking_id, $_SESSION['user_id']
            ]);
        } else {
            // Insert new review
            $pdo->prepare("
                INSERT INTO reviews
                (booking_id, tourist_id, accommodation_id,
                 rating, review_text, suspicion_score,
                 is_flagged, tourist_submitted,
                 host_submitted, is_published)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, ?)
            ")->execute([
                $booking_id,
                $_SESSION['user_id'],
                $booking['accommodation_id'],
                $rating, $review_text,
                $suspicion, $is_flagged,
                $is_published
            ]);
        }

        // Save the four category scores
        $pdo->prepare("UPDATE reviews SET cleanliness_rating = ?, location_rating = ?, comfort_rating = ?, value_rating = ? WHERE booking_id = ? AND tourist_id = ?")
            ->execute([$cats['cleanliness_rating'], $cats['location_rating'], $cats['comfort_rating'], $cats['value_rating'], $booking_id, $_SESSION['user_id']]);

        // If flagged notify admin
        if ($is_flagged) {
            $admin = adminNotificationRecipient($pdo);

            if ($admin) {
                sendEmail(
                    $admin['email'],
                    'Flagged Review — Review Required',
                    "A review has been automatically flagged for
                     moderation. Suspicion score: $suspicion.
                     Booking ID: $booking_id.
                     Please log in to the admin panel to review it."
                );
            }
        }

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave a Review — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .page-wrap  { max-width:680px; margin:0 auto; padding:32px 24px }
        .page-tag   { font-size:11px; font-weight:600; letter-spacing:.06em;
                      color:#126b4e; text-transform:uppercase;
                      margin-bottom:8px }
        .page-title { font-size:28px; font-weight:400; margin-bottom:4px }
        .page-sub   { font-size:14px; color:var(--text-secondary);
                      margin-bottom:28px }
        .review-card{ background:var(--surface-2);
                      border:1px solid var(--border);
                      border-radius:8px; padding:28px }
        .prop-row   { display:flex; gap:14px; align-items:center;
                      padding-bottom:20px;
                      border-bottom:1px solid var(--border);
                      margin-bottom:24px }
        .prop-thumb { width:64px; height:52px; border-radius:6px;
                      background:#E1F5EE; overflow:hidden;
                      display:flex; align-items:center;
                      justify-content:center; font-size:22px;
                      flex-shrink:0 }
        .prop-thumb img { width:100%; height:100%; object-fit:cover }
        .prop-title { font-size:15px; font-weight:500; margin-bottom:2px }
        .prop-dates { font-size:12px; color:var(--text-secondary) }
        .rating-label { font-size:13px; font-weight:500;
                        margin-bottom:10px; display:block }
        .star-row   { display:flex; gap:6px; margin-bottom:20px }
        .star-btn   { background:none; border:none; cursor:pointer;
                      font-size:28px; color:#E5E7EB;
                      transition:color .1s; padding:0;
                      line-height:1 }
        .cat-star{background:none;border:0;cursor:pointer;font-size:24px;color:#d6d3c8;padding:0 2px;line-height:1}
        .cat-star.on{color:#BA7517}
        .star-btn.active,
        .star-btn:hover,
        .star-btn.hovered { color:#BA7517 }
        .rating-desc{ font-size:12px; color:var(--text-secondary);
                      margin-top:4px; min-height:18px }
        .form-group { margin-bottom:18px }
        .form-group label { font-size:13px; font-weight:500;
                            display:block; margin-bottom:6px;
                            color:var(--text-primary) }
        .form-group textarea { width:100%; padding:12px 14px;
                               border:1px solid var(--border-strong);
                               border-radius:6px; font-size:14px;
                               color:var(--text-primary);
                               resize:vertical; min-height:120px;
                               font-family:inherit;
                               background:var(--surface-2) }
        .form-group textarea:focus { outline:none;
                                     border-color:#126b4e }
        .char-count { font-size:11px; color:var(--text-secondary);
                      text-align:right; margin-top:4px }
        .honesty-note { background:#F0FAF6;
                        border:1px solid #9FE1CB;
                        border-radius:6px; padding:12px 14px;
                        font-size:13px; color:#0F6E56;
                        margin-bottom:20px; line-height:1.5 }
        .reveal-note { background:var(--surface-1);
                       border:1px solid var(--border);
                       border-radius:6px; padding:12px 14px;
                       font-size:12px; color:var(--text-secondary);
                       margin-bottom:20px; line-height:1.5 }
        .submit-btn { width:100%; padding:12px; background:#085041;
                      color:#fff; border:none; border-radius:6px;
                      font-size:14px; font-weight:500; cursor:pointer }
        .submit-btn:hover { background:#0a6350 }
        .success-state { text-align:center; padding:40px 20px }
        .success-icon  { width:52px; height:52px; background:#E1F5EE;
                         border-radius:50%; display:flex;
                         align-items:center; justify-content:center;
                         margin:0 auto 16px;
                         border:1px solid #9FE1CB }
        .success-icon svg { width:22px; height:22px; stroke:#126b4e }
        .success-state h2 { font-size:20px; font-weight:500;
                            margin-bottom:8px }
        .success-state p  { font-size:14px; color:var(--text-secondary);
                            margin-bottom:20px; line-height:1.6 }
    </style>
</head>
<body class="tourist-review">

<?php include '../includes/header.php'; ?>

<div class="page-wrap">

    <div class="page-tag">Share what mattered</div>
    <h1 class="page-title">Leave a review</h1>
    <p class="page-sub">
        Your honest experience helps future guests
        make better decisions.
    </p>

    <?php if ($success): ?>

        <!-- Success State -->
        <div class="review-card">
            <div class="success-state">
                <div class="success-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M4.5 12.75l6 6 9-13.5"/>
                    </svg>
                </div>
                <h2>Review submitted</h2>
                <p>
                    Thank you for your honest feedback.<br>
                    Your review will be published once both you and
                    your host have submitted reviews, or after
                    14 days — whichever comes first.
                </p>
                <a href="bookings.php"
                   style="display:inline-block;padding:10px 24px;
                          background:#085041;color:#fff;
                          border-radius:6px;font-size:14px;
                          font-weight:500;text-decoration:none">
                    Back to my bookings
                </a>
            </div>
        </div>

    <?php else: ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error" style="margin-bottom:20px">
            <?php foreach ($errors as $e): ?>
                <p><?= h($e) ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="review-card">

            <!-- Property Info -->
            <div class="prop-row">
                <div class="prop-thumb">
                    <?php if ($booking['primary_photo']): ?>
                        <img src="../uploads/accommodations/<?= h($booking['primary_photo']) ?>"
                             alt="<?= h($booking['title']) ?>">
                    <?php else: ?>
                        <span style="font-size:12px;color:#8a948e">No photo</span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="prop-title">
                        <?= h($booking['title']) ?>
                    </div>
                    <div class="prop-dates">
                        <?= h($booking['location_name']) ?>
                        &middot;
                        Your stay ended
                        <?= date('d M Y',
                            strtotime($booking['check_out_date'])) ?>
                    </div>
                </div>
            </div>

            <!-- Honesty Prompt -->
            <div class="honesty-note">
                Honest reviews — whether positive or negative —
                help future guests make informed choices and
                encourage hosts to maintain high standards.
                Your name and profile photo are shown next to your review.
            </div>

            <form method="POST">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

                <!-- Star Rating -->
                <div class="form-group">
                    <span class="rating-label">
                        How was your overall stay?
                    </span>
                    <div class="star-row" id="starRow">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <button type="button" class="star-btn"
                                data-value="<?= $i ?>"
                                onclick="setRating(<?= $i ?>)"
                                onmouseover="hoverRating(<?= $i ?>)"
                                onmouseout="resetHover()">
                            &#9733;
                        </button>
                        <?php endfor; ?>
                    </div>
                    <div class="rating-desc" id="ratingDesc">
                        Select a rating
                    </div>
                    <input type="hidden" name="rating"
                           id="ratingInput" value="0">
                </div>


                <!-- Category ratings -->
                <div class="form-group">
                    <span class="rating-label">Rate the details</span>
                    <?php foreach (reviewCategoryLabels() as $col => $label): ?>
                    <div class="cat-row" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #eee">
                        <span style="font-size:14px"><?= h($label) ?></span>
                        <span class="cat-stars" data-name="<?= h($col) ?>">
                            <?php for ($i = 1; $i <= 5; $i++): ?><button type="button" class="cat-star" data-value="<?= $i ?>" aria-label="<?= $i ?> out of 5 for <?= h(strtolower($label)) ?>">&#9733;</button><?php endfor; ?>
                        </span>
                        <input type="hidden" name="<?= h($col) ?>" id="in_<?= h($col) ?>" value="<?= (int)($_POST[$col] ?? 0) ?>">
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Review Text -->
                <div class="form-group">
                    <label for="review_text">
                        Tell future guests what stood out
                    </label>
                    <textarea id="review_text" name="review_text"
                              maxlength="1000"
                              placeholder="The apartment felt calm and well considered..."
                              oninput="updateCount(this)"><?= h($_POST['review_text'] ?? '') ?></textarea>
                    <div class="char-count">
                        <span id="charCount">0</span> / 1000
                    </div>
                </div>

                <!-- Simultaneous Reveal Note -->
                <div class="reveal-note">
                    Your review will not be visible until your host
                    has also submitted their review, or until
                    14 days have passed — whichever comes first.
                    This ensures neither party influences the other.
                </div>

                <button type="submit" class="submit-btn"
                        id="submitBtn" disabled>
                    Publish review
                </button>

            </form>
        </div>

    <?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>

<script>
const descriptions = {
    1: 'Poor — significant issues with the stay',
    2: 'Below average — some disappointments',
    3: 'Okay — met basic expectations',
    4: 'Good — enjoyable stay',
    5: 'Excellent — exceeded expectations'
};

let currentRating = 0;

function setRating(val) {
    currentRating = val;
    document.getElementById('ratingInput').value = val;
    document.getElementById('ratingDesc').textContent =
        descriptions[val] || '';
    updateStars(val);
    checkSubmit();
}

function hoverRating(val) {
    updateStars(val);
    document.getElementById('ratingDesc').textContent =
        descriptions[val] || '';
}

function resetHover() {
    updateStars(currentRating);
    document.getElementById('ratingDesc').textContent =
        currentRating ? descriptions[currentRating] : 'Select a rating';
}

function updateStars(val) {
    document.querySelectorAll('.star-btn').forEach((btn, i) => {
        btn.classList.toggle('active', i < val);
    });
}

function updateCount(el) {
    document.getElementById('charCount').textContent =
        el.value.length;
    checkSubmit();
}

function checkSubmit() {
    const text = document.getElementById('review_text').value;
    const btn  = document.getElementById('submitBtn');
    const catsDone = Array.from(document.querySelectorAll('.cat-stars')).every(function (g) {
        return parseInt(document.getElementById('in_' + g.dataset.name).value, 10) > 0;
    });
    btn.disabled = !(currentRating > 0 && catsDone && text.trim().length >= 10);
}

// Category star pickers
document.querySelectorAll('.cat-stars').forEach(function (g) {
    const input = document.getElementById('in_' + g.dataset.name);
    const paint = function (v) { g.querySelectorAll('.cat-star').forEach(function (b, i) { b.classList.toggle('on', i < v); }); };
    paint(parseInt(input.value, 10) || 0);
    g.querySelectorAll('.cat-star').forEach(function (b) {
        b.addEventListener('click', function () { input.value = b.dataset.value; paint(+b.dataset.value); checkSubmit(); });
        b.addEventListener('mouseover', function () { paint(+b.dataset.value); });
        b.addEventListener('mouseout', function () { paint(parseInt(input.value, 10) || 0); });
    });
});

// Init char count
const ta = document.getElementById('review_text');
if (ta) {
    document.getElementById('charCount').textContent = ta.value.length;
}
</script>

</body>
</html>