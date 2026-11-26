<?php
// Profile of a person.
//  * A host with an approved listing has a PUBLIC profile, so guests can read about the host before booking.
//  * Guest profiles are limited to people who share a conversation or a booking with you (admins can view anyone).
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_photo.php';
require_once __DIR__ . '/includes/host_verification.php';
require_once __DIR__ . '/includes/host_profile.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$base = appBasePath();
$id   = (int)($_GET['id'] ?? 0);
$back = (int)($_GET['thread_id'] ?? 0);

// Is this a host with an approved listing and an active account? Then anyone may read the profile.
$publicHost = false;
if ($id > 0) {
    $ph = $pdo->prepare("SELECT 1 FROM users u
                         WHERE u.user_id = ? AND u.role = 'host' AND u.status = 'active'
                           AND u.account_closed_at IS NULL AND u.account_deleted_at IS NULL
                           AND EXISTS (SELECT 1 FROM accommodations a WHERE a.host_id = u.user_id AND a.status = 'approved')
                         LIMIT 1");
    $ph->execute([$id]);
    $publicHost = (bool)$ph->fetchColumn();
}
if (!$publicHost) requireLogin();

$loggedIn = isLoggedIn();
$me       = $loggedIn ? (int)$_SESSION['user_id'] : 0;
$myRole   = $loggedIn ? ($_SESSION['role'] ?? '') : '';

if ($loggedIn && $id === $me) {
    redirect($base . '/' . ($myRole === 'host' ? 'host' : ($myRole === 'admin' ? 'admin' : 'tourist')) . '/profile.php');
}

$allowed = $publicHost || ($myRole === 'admin');
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
    // 3. reviewers shown publicly on a listing
    $chk = $pdo->prepare("SELECT 1 FROM reviews WHERE tourist_id = ? AND is_published = 1 LIMIT 1");
    $chk->execute([$id]);
    $allowed = (bool)$chk->fetchColumn();
}
$fallback = $base . ($myRole === 'host' ? '/host/messages.php' : '/messages.php');
if (!$allowed) {
    setFlash('error', 'That profile is not available to you.');
    redirect($fallback);
}

$st = $pdo->prepare("SELECT user_id, full_name, role, profile_photo, created_at, account_deleted_at FROM users WHERE user_id = ? LIMIT 1");
$st->execute([$id]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u || !in_array($u['role'], ['tourist', 'host'], true) || (!empty($u['account_deleted_at']) && $myRole !== 'admin')) {
    setFlash('error', 'Profile not found.');
    redirect($fallback);
}

$isHostProfile = $u['role'] === 'host';
$verified = !empty(verifiedHostIds($pdo, [$id]));
$listings = []; $guestRep = null; $intro = ''; $resp = null; $revs = null;
if ($isHostProfile) {
    $ls = $pdo->prepare("SELECT a.accommodation_id, a.title, a.location_name, a.price_per_night,
            COALESCE(AVG(r.rating),0) AS avg_rating, COUNT(DISTINCT r.review_id) AS total_reviews
        FROM accommodations a LEFT JOIN reviews r ON r.accommodation_id = a.accommodation_id AND r.is_published = 1
        WHERE a.host_id = ? AND a.status = 'approved' GROUP BY a.accommodation_id ORDER BY a.accommodation_id DESC");
    $ls->execute([$id]);
    $listings = $ls->fetchAll(PDO::FETCH_ASSOC);
    $intro = getHostIntro($pdo, $id);
    $resp  = hostResponseStats($pdo, $id);
    $revs  = hostReviewSummary($pdo, $id, 10);
} elseif (function_exists('getGuestReputation')) {
    try { $guestRep = getGuestReputation($pdo, $id); } catch (Throwable $e) { $guestRep = null; }
}
if ($back) {
    $backUrl = $base . ($myRole === 'host' ? '/host/messages.php' : '/messages.php') . '?thread_id=' . $back;
} else {
    $backUrl = 'javascript:history.back()';
}
$firstName = strtok(html_entity_decode((string)$u['full_name'], ENT_QUOTES, 'UTF-8'), ' ');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($u['full_name']) ?> — StayExplore Kenya</title>
<link rel="stylesheet" href="<?= h($base) ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= h($base) ?>/assets/css/ui.css"><script src="<?= h($base) ?>/assets/js/ui.js" defer></script>
<style>
.vp{max-width:720px;margin:0 auto;padding:28px 20px 60px}
.vp-head{display:flex;gap:20px;align-items:center;background:var(--surface-2);border:1px solid #dfe7e3;border-radius:14px;padding:24px}
.vp-head h1{margin:0 0 6px;font-size:24px}
.vp-pill{display:inline-block;background:var(--forest);color:#fff;border-radius:20px;padding:3px 12px;font-size:11px;font-weight:700}
.vp-card{background:var(--surface-2);border:1px solid #dfe7e3;border-radius:14px;padding:20px;margin-top:16px}
.vp-card h2{font-size:17px;margin:0 0 10px}
.vp-row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid var(--border);font-size:14px}
.vp-row:first-of-type{border-top:0}
.vp-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-top:16px}
.vp-tile{background:var(--surface-2);border:1px solid #dfe7e3;border-radius:12px;padding:14px;text-align:center}
.vp-tile b{display:block;font-size:20px;margin-bottom:2px}
.vp-tile small{display:block;color:#4f5f57;font-size:12px;line-height:1.4}
.vp-rev{padding:14px 0;border-top:1px solid var(--border);font-size:14px}
.vp-rev:first-of-type{border-top:0}
.vp-stars{color:#b7791f;letter-spacing:1px}
.vp-reply{background:#e8f8f3;border-left:3px solid #126b4e;border-radius:6px;padding:8px 12px;margin-top:8px;font-size:13px}
.vp-muted{color:#566159;font-size:13px}
@media (max-width:520px){.vp-head{flex-direction:column;text-align:center}}
html[data-theme="dark"] .vp-tile{background:#171f1b;border-color:#2c3d34}
html[data-theme="dark"] .vp-tile small,html[data-theme="dark"] .vp-muted{color:#a9b8b0}
html[data-theme="dark"] .vp-rev{border-color:#2c3d34}
html[data-theme="dark"] .vp-reply{background:#16231d}
html[data-theme="dark"] .vp-stars{color:#f0c46a}
</style><link rel="stylesheet" href="assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="assets/js/se-theme.js"></script><script src="assets/js/se-i18n.js"></script></head><body>
<?php include __DIR__ . '/includes/header.php'; ?>
<main class="vp">
  <a href="<?= h($backUrl) ?>" style="font-size:14px;color:var(--text-secondary)">← Back</a>
  <div class="vp-head" style="margin-top:14px">
    <?= avatarHtml((int)$u['user_id'], $u['full_name'], $u['profile_photo'], 96) ?>
    <div>
      <h1><?= h($u['full_name']) ?><?= $verified ? userVerifiedBadge($u['role']) : '' ?></h1>
      <span class="vp-pill"><?= $isHostProfile ? 'Host' : 'Traveller' ?></span>
      <?php if (!empty($u['created_at'])): ?><div style="font-size:13px;color:var(--text-secondary);margin-top:8px">Member since <?= h(date('F Y', strtotime($u['created_at']))) ?></div><?php endif; ?>
    </div>
  </div>

  <?php if ($isHostProfile): ?>
    <div class="vp-tiles">
      <div class="vp-tile">
        <?php if ($verified): ?><b>✓ Verified host</b><small>ID checked by an admin</small>
        <?php else: ?><b>Not verified yet</b><small>ID check not completed</small><?php endif; ?>
      </div>
      <div class="vp-tile">
        <?php if ($resp && $resp['rate'] !== null): ?>
          <b><?= (int)$resp['rate'] ?>%</b><small>of enquiries answered</small><small><span><?= (int)$resp['enquiries'] ?></span> enquiries counted</small>
        <?php else: ?><b>New</b><small>Not enough enquiries yet to show a response rate</small><?php endif; ?>
      </div>
      <div class="vp-tile"><b><?= count($listings) ?></b><small><?= count($listings) === 1 ? 'property' : 'properties' ?></small></div>
      <div class="vp-tile">
        <?php if ($revs && $revs['count'] > 0): ?>
          <b>★ <?= number_format($revs['avg'], 1) ?></b><small><span><?= (int)$revs['count'] ?></span> <?= $revs['count'] === 1 ? 'review' : 'reviews' ?></small>
        <?php else: ?><b>—</b><small>No reviews yet</small><?php endif; ?>
      </div>
    </div>

    <div class="vp-card"><h2>About this host</h2>
      <?php if ($intro !== ''): ?>
        <p style="font-size:14px;line-height:1.6;margin:0"><?= nl2br(h($intro)) ?></p>
      <?php else: ?>
        <p class="vp-muted" style="margin:0">This host has not written an introduction yet.</p>
      <?php endif; ?>
    </div>

    <div class="vp-card"><h2>Properties</h2>
      <?php if (!$listings): ?><p style="font-size:14px;color:var(--text-secondary);margin:0">No approved listings yet.</p><?php endif; ?>
      <?php foreach ($listings as $l): ?>
        <div class="vp-row"><span><a href="<?= h($base) ?>/tourist/details.php?id=<?= (int)$l['accommodation_id'] ?>"><?= h($l['title']) ?></a><br><small style="color:var(--text-secondary)"><?= h($l['location_name']) ?></small></span>
        <span style="text-align:right">KES <?= number_format($l['price_per_night']) ?><br><small style="color:var(--text-secondary)"><?= (int)$l['total_reviews'] > 0 ? '★ ' . number_format($l['avg_rating'], 1) . ' (' . (int)$l['total_reviews'] . ')' : 'New listing' ?></small></span></div>
      <?php endforeach; ?>
    </div>

    <div class="vp-card"><h2>Guest reviews</h2>
      <?php if (!$revs || empty($revs['items'])): ?>
        <p class="vp-muted" style="margin:0">No reviews yet. Reviews appear here after guests complete their stays.</p>
      <?php else: foreach ($revs['items'] as $rv): $stars = max(1, min(5, (int)$rv['rating'])); ?>
        <div class="vp-rev">
          <div><span class="vp-stars" role="img" aria-label="<?= $stars ?> out of 5"><?= str_repeat('★', $stars) . str_repeat('☆', 5 - $stars) ?></span>
            <strong><?= h(reviewerFirstName($rv['full_name'])) ?></strong>
            <span class="vp-muted">· <?= h(date('M Y', strtotime($rv['created_at']))) ?></span></div>
          <div class="vp-muted"><a href="<?= h($base) ?>/tourist/details.php?id=<?= (int)$rv['accommodation_id'] ?>"><?= h($rv['title']) ?></a></div>
          <?php if (trim((string)$rv['review_text']) !== ''): ?><p style="margin:6px 0 0;line-height:1.55"><?= nl2br(h($rv['review_text'])) ?></p><?php endif; ?>
          <?php if (!empty($rv['host_reply']) && empty($rv['host_reply_hidden'])): ?>
            <div class="vp-reply"><strong>Host reply:</strong><br><?= nl2br(h($rv['host_reply'])) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
  <?php else: ?>
    <div class="vp-card"><h2>Guest reputation</h2>
      <?php if ($guestRep && (int)($guestRep['count'] ?? 0) > 0): ?>
        <p style="font-size:14px;margin:0"><strong>★ <?= h($guestRep['avg']) ?></strong> from <?= (int)$guestRep['count'] ?> host review<?= (int)$guestRep['count'] === 1 ? '' : 's' ?></p>
      <?php else: ?><p style="font-size:14px;color:var(--text-secondary);margin:0">No host reviews yet.</p><?php endif; ?>
    </div>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body></html>
