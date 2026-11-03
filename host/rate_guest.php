<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

requireLogin();
requireRole('host');

$host_id    = (int)$_SESSION['user_id'];
$booking_id = (int)($_GET['booking_id'] ?? $_POST['booking_id'] ?? 0);

// The booking must be a completed stay at one of this host's own listings.
$stmt = $pdo->prepare("
    SELECT b.booking_id, b.tourist_id, b.check_in_date, b.check_out_date, a.title, u.full_name AS tourist_name
    FROM bookings b
    JOIN accommodations a ON a.accommodation_id = b.accommodation_id
    JOIN users u ON u.user_id = b.tourist_id
    WHERE b.booking_id = ? AND a.host_id = ? AND b.status = 'completed'
");
$stmt->execute([$booking_id, $host_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) {
    setFlash('error', 'You can only rate guests after a completed stay at one of your listings.');
    redirect('bookings.php');
}

$existing = $pdo->prepare("SELECT rating, review_text FROM guest_reviews WHERE booking_id = ?");
$existing->execute([$booking_id]);
$done = $existing->fetch(PDO::FETCH_ASSOC);

$errors = [];
if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $rating = (int)($_POST['rating'] ?? 0);
    $text   = trim((string)($_POST['review_text'] ?? ''));
    if ($rating < 1 || $rating > 5) $errors[] = 'Choose a rating from 1 to 5.';
    if (mb_strlen($text, 'UTF-8') > 1000) $errors[] = 'Keep the comment under 1,000 characters.';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO guest_reviews (booking_id, host_id, tourist_id, rating, review_text) VALUES (?, ?, ?, ?, ?)")
                ->execute([$booking_id, $host_id, $booking['tourist_id'], $rating, $text !== '' ? sanitize($text) : null]);
            // The host's side is now in, so the guest's own (sealed) review for this stay can be revealed.
            $pdo->prepare("UPDATE reviews SET host_submitted = 1 WHERE booking_id = ?")->execute([$booking_id]);
            $pdo->prepare("UPDATE reviews SET is_published = 1 WHERE booking_id = ? AND tourist_submitted = 1 AND is_flagged = 0")->execute([$booking_id]);
            $pdo->commit();
            setFlash('success', 'Thank you. Your guest review has been saved. It becomes visible once the guest has reviewed the stay, or 14 days after check-out.');
            redirect('bookings.php?tab=completed');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'This stay has already been reviewed or could not be saved.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate guest — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .rg-wrap { max-width:640px; margin:0 auto; padding:32px 24px }
        .rg-card { background:var(--surface-2,#fff); border:1px solid var(--border,#ddd); border-radius:8px; padding:28px }
        .rg-stars { display:flex; gap:10px; margin:8px 0 18px; flex-wrap:wrap }
        .rg-stars label { display:flex; align-items:center; gap:6px; cursor:pointer; font-size:14px }
        .rg-card textarea { width:100%; min-height:110px }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
<div class="rg-wrap">
    <p><a class="home-link" href="bookings.php?tab=completed">&larr; Back to bookings</a></p>
    <h1 style="font-size:26px;font-weight:400;margin:12px 0 6px">Rate your guest</h1>
    <p class="small" style="margin-bottom:20px">
        <?= h($booking['tourist_name']) ?> &middot; <?= h($booking['title']) ?> &middot;
        <?= date('d M', strtotime($booking['check_in_date'])) ?> &ndash; <?= date('d M Y', strtotime($booking['check_out_date'])) ?>
    </p>
    <div class="rg-card">
    <?php if ($done): ?>
        <p><strong>You rated this guest <?= (int)$done['rating'] ?>/5.</strong></p>
        <?php if (!empty($done['review_text'])): ?><p style="margin-top:8px"><?= h($done['review_text']) ?></p><?php endif; ?>
        <p class="small" style="margin-top:12px">Guest reviews cannot be edited after they are sent.</p>
    <?php else: ?>
        <?php if ($errors): ?><div class="alert alert-error" style="margin-bottom:14px"><?php foreach ($errors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
            <input type="hidden" name="booking_id" value="<?= (int)$booking_id ?>">
            <label><strong>How was this guest?</strong></label>
            <div class="rg-stars">
                <?php foreach ([1 => 'Poor', 2 => 'Fair', 3 => 'Good', 4 => 'Very good', 5 => 'Excellent'] as $n => $label): ?>
                    <label><input type="radio" name="rating" value="<?= $n ?>" required> <?= $n ?> &middot; <?= $label ?></label>
                <?php endforeach; ?>
            </div>
            <label for="review_text"><strong>Comment (optional)</strong></label>
            <textarea id="review_text" name="review_text" maxlength="1000" placeholder="Were they respectful, tidy and on time? Keep it factual and fair."></textarea>
            <p class="small" style="margin:8px 0 18px">Other hosts and the guest will see this once both sides have reviewed, or 14 days after check-out.</p>
            <button type="submit" class="btn">Submit guest review</button>
        </form>
    <?php endif; ?>
    </div>
</div>
<?php appShellEnd(); ?>
</body>
</html>
