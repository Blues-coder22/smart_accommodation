<?php
// "What hosts say about you" card for the tourist profile. Needs $pdo and $user_id.
// Only ratings that are revealed (guest reviewed the stay, or 14 days after check-out) are shown.
$gr_rep = getGuestReputation($pdo, $user_id);
require_once __DIR__ . '/review_extras.php';
$gr_rows = [];
try {
    $gr = $pdo->prepare("
        SELECT g.rating, g.review_text, g.created_at, a.title, h.full_name AS host_name, h.user_id AS host_uid, h.profile_photo AS host_photo
        FROM guest_reviews g
        JOIN bookings b ON b.booking_id = g.booking_id
        JOIN accommodations a ON a.accommodation_id = b.accommodation_id
        JOIN users h ON h.user_id = g.host_id
        LEFT JOIN reviews r ON r.booking_id = g.booking_id
        WHERE g.tourist_id = ? AND g.is_hidden = 0 AND (r.tourist_submitted = 1 OR b.check_out_date <= DATE_SUB(CURDATE(), INTERVAL 14 DAY))
        ORDER BY g.created_at DESC LIMIT 10");
    $gr->execute([(int)$user_id]);
    $gr_rows = $gr->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $gr_rows = []; }
?>
    <div class="form-card">
        <h3>What hosts say about you</h3>
        <?php if (!$gr_rows): ?>
            <p style="font-size:13px;color:var(--text-secondary)">No host reviews yet. After a completed stay, hosts can rate how you were as a guest. Their rating appears here once you have reviewed the stay, or 14 days after check-out.</p>
        <?php else: ?>
            <p style="font-size:14px;margin-bottom:12px"><strong>&#9733; <?= h($gr_rep['avg']) ?></strong> from <?= (int)$gr_rep['count'] ?> host review<?= $gr_rep['count'] === 1 ? '' : 's' ?></p>
            <?php foreach ($gr_rows as $g): ?>
                <div style="border-top:1px solid var(--border,#eee);padding:10px 0;font-size:13px">
                    <strong>&#9733; <?= (int)$g['rating'] ?>/5</strong> &middot; <?= personChipHtml($g['host_uid'], $g['host_name'], $g['host_photo'], 24) ?> &middot; <?= h($g['title']) ?> &middot; <?= date('d M Y', strtotime($g['created_at'])) ?>
                    <?php if (!empty($g['review_text'])): ?><div style="margin-top:4px"><?= h($g['review_text']) ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
