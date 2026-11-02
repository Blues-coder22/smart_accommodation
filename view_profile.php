<?php
// Public-style profile of a person you are chatting with (host <-> tourist).
// Access is limited to people who share a conversation (admins can view anyone).
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_photo.php';
require_once __DIR__ . '/includes/host_verification.php';
requireLogin();

$base   = appBasePath();
$me     = (int)$_SESSION['user_id'];
$myRole = $_SESSION['role'] ?? '';
$id     = (int)($_GET['id'] ?? 0);
$back   = (int)($_GET['thread_id'] ?? 0);

if ($id === $me) {
    redirect($base . '/' . ($myRole === 'host' ? 'host' : ($myRole === 'admin' ? 'admin' : 'tourist')) . '/profile.php');
}

$allowed = ($myRole === 'admin');
if (!$allowed && $id > 0) {
    // 1. people you have a conversation with
    $chk = $pdo->prepare("SELECT 1 FROM message_threads WHERE (tourist_id = ? AND host_id = ?) OR (host_id = ? AND tourist_id = ?) LIMIT 1");
    $chk->execute([$me, $id, $me, $id]);
    $allowed = (bool)$chk->fetchColumn();
}
if (!$allowed && $id > 0) {
    // 2. host <-> guest of a booking
    $chk = $pdo->prepare("SELECT 1 FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id
                          WHERE (b.tourist_id = ? AND a.host_id = ?) OR (a.host_id = ? AND b.tourist_id = ?) LIMIT 1");
    $chk->execute([$me, $id, $me, $id]);
    $allowed = (bool)$chk->fetchColumn();
}
if (!$allowed && $id > 0) {
    // 3. reviewers shown publicly on a listing, and hosts with an approved listing
    $chk = $pdo->prepare("SELECT 1 FROM reviews WHERE tourist_id = ? AND is_published = 1
                          UNION SELECT 1 FROM accommodations WHERE host_id = ? AND status = 'approved' LIMIT 1");
    $chk->execute([$id, $id]);
    $allowed = (bool)$chk->fetchColumn();
}
if (!$allowed) {
    setFlash('error', 'That profile is not available to you.');
    redirect($base . ($myRole === 'host' ? '/host/messages.php' : '/messages.php'));
}

$st = $pdo->prepare("SELECT user_id, full_name, role, profile_photo, created_at FROM users WHERE user_id = ? LIMIT 1");
$st->execute([$id]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u || !in_array($u['role'], ['tourist', 'host'], true)) {
    setFlash('error', 'Profile not found.');
    redirect($base . ($myRole === 'host' ? '/host/messages.php' : '/messages.php'));
}

$isHostProfile = $u['role'] === 'host';
$listings = []; $guestRep = null; $verified = !empty(verifiedHostIds($pdo, [$id]));
if ($isHostProfile) {
    $ls = $pdo->prepare("SELECT a.accommodation_id, a.title, a.location_name, a.price_per_night,
            COALESCE(AVG(r.rating),0) AS avg_rating, COUNT(DISTINCT r.review_id) AS total_reviews
        FROM accommodations a LEFT JOIN reviews r ON r.accommodation_id = a.accommodation_id AND r.is_published = 1
        WHERE a.host_id = ? AND a.status = 'approved' GROUP BY a.accommodation_id ORDER BY a.accommodation_id DESC");
    $ls->execute([$id]);
    $listings = $ls->fetchAll(PDO::FETCH_ASSOC);
} elseif (function_exists('getGuestReputation')) {
    try { $guestRep = getGuestReputation($pdo, $id); } catch (Throwable $e) { $guestRep = null; }
}
$backUrl = $back ? $base . ($myRole === 'host' ? '/host/messages.php' : '/messages.php') . '?thread_id=' . $back : 'javascript:history.back()';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($u['full_name']) ?> — StayExplore Kenya</title>
<link rel="stylesheet" href="<?= h($base) ?>/assets/css/style.css">
<style>
.vp{max-width:720px;margin:0 auto;padding:28px 20px 60px}
.vp-head{display:flex;gap:20px;align-items:center;background:#fff;border:1px solid #dfe7e3;border-radius:14px;padding:24px}
.vp-head h1{margin:0 0 6px;font-size:24px}
.vp-pill{display:inline-block;background:#0d3b2e;color:#fff;border-radius:20px;padding:3px 12px;font-size:11px;font-weight:700}
.vp-card{background:#fff;border:1px solid #dfe7e3;border-radius:14px;padding:20px;margin-top:16px}
.vp-card h2{font-size:17px;margin:0 0 10px}
.vp-row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid #eee;font-size:14px}
.vp-row:first-of-type{border-top:0}
</style></head><body>
<?php include __DIR__ . '/includes/header.php'; ?>
<main class="vp">
  <a href="<?= h($backUrl) ?>" style="font-size:14px;color:#666">← Back</a>
  <div class="vp-head" style="margin-top:14px">
    <?= avatarHtml((int)$u['user_id'], $u['full_name'], $u['profile_photo'], 96) ?>
    <div>
      <h1><?= h($u['full_name']) ?><?= $verified ? userVerifiedBadge($u['role']) : '' ?></h1>
      <span class="vp-pill"><?= $isHostProfile ? 'Host' : 'Tourist' ?></span>
      <?php if (!empty($u['created_at'])): ?><div style="font-size:13px;color:#68776f;margin-top:8px">Member since <?= h(date('F Y', strtotime($u['created_at']))) ?></div><?php endif; ?>
    </div>
  </div>

  <?php if ($isHostProfile): ?>
    <div class="vp-card"><h2>Listings by <?= h(strtok($u['full_name'], ' ')) ?></h2>
      <?php if (!$listings): ?><p style="font-size:14px;color:#666;margin:0">No approved listings yet.</p><?php endif; ?>
      <?php foreach ($listings as $l): ?>
        <div class="vp-row"><span><a href="<?= h($base) ?>/tourist/details.php?id=<?= (int)$l['accommodation_id'] ?>"><?= h($l['title']) ?></a><br><small style="color:#777"><?= h($l['location_name']) ?></small></span>
        <span style="text-align:right">KES <?= number_format($l['price_per_night']) ?><br><small style="color:#777"><?= (int)$l['total_reviews'] > 0 ? '★ ' . number_format($l['avg_rating'], 1) . ' (' . (int)$l['total_reviews'] . ')' : 'New' ?></small></span></div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="vp-card"><h2>Guest reputation</h2>
      <?php if ($guestRep && (int)($guestRep['count'] ?? 0) > 0): ?>
        <p style="font-size:14px;margin:0"><strong>★ <?= h($guestRep['avg']) ?></strong> from <?= (int)$guestRep['count'] ?> host review<?= (int)$guestRep['count'] === 1 ? '' : 's' ?></p>
      <?php else: ?><p style="font-size:14px;color:#666;margin:0">No host reviews yet.</p><?php endif; ?>
    </div>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body></html>
