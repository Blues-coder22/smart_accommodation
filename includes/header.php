<?php
require_once __DIR__ . '/session_boot.php';
seBootSession();
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';   // isLoggedIn() and hasRole() are defined here
require_once __DIR__ . '/profile_photo.php';
$is_homepage = basename($_SERVER['PHP_SELF'] ?? '') === 'index.php';
$base = appBasePath();
// v9.38: signed-in travellers get the redesigned top bar / drawer (includes/tourist_nav.php). Hosts, admins and visitors keep the header below.
if (isLoggedIn() && hasRole('tourist')) { require_once __DIR__ . '/tourist_nav.php'; seRenderTouristNav(); return; }
?>
<?php
  // ---- v9.34 header: a short row of main links; everything else lives in the account menu ----
  $__navLinks = [[$base . '/tourist/search.php?type=accommodation', 'Discover'],
                 [$base . '/tourist/search.php?type=attraction', 'Attractions'],
                 [$base . '/tourist/choose_destination.php', 'Where to go'],
                 [$base . '/how-it-works.html', 'How it works'],
                 [$base . '/about.html', 'About']];
  $__role = ''; $__profileUrl = ''; $__roleLabel = ''; $__menu = []; $__badgeKey = '';
  if (isLoggedIn()) {
      if (hasRole('tourist')) {
          $__role = 'tourist'; $__roleLabel = 'Traveller'; $__profileUrl = $base . '/tourist/profile.php';
          $__navLinks = [[$base . '/tourist/search.php?type=accommodation', 'Discover'],
                         [$base . '/tourist/search.php?type=attraction', 'Attractions'],
                         [$base . '/tourist/choose_destination.php', 'Where to go'],
                         [$base . '/tourist/bookings.php', 'My bookings'],
                         [$base . '/messages.php', 'Messages']];
          $__badgeKey = 'Messages';
          $__menu = [[$__profileUrl, 'My profile'], [$base . '/tourist/favourites.php', 'Favourites'], [$base . '/tourist/support.php', 'Contact support'],
                     [$base . '/how-it-works.html', 'How it works'], [$base . '/about.html', 'About']];
      } elseif (hasRole('host')) {
          $__role = 'host'; $__roleLabel = 'Host'; $__profileUrl = $base . '/host/profile.php';
          $__navLinks = [[$base . '/host/dashboard.php', 'Dashboard'], [$base . '/host/listings.php', 'My listings'], [$base . '/host/bookings.php', 'Bookings'],
                         [$base . '/host/messages.php', 'Messages'], [$base . '/host/payments.php', 'Payments']];
          $__badgeKey = 'Messages';
          $__menu = [[$__profileUrl, 'My profile'], [$base . '/host/commission.php', 'Commission'], [$base . '/host/support.php', 'Contact support'],
                     [$base . '/tourist/search.php?type=accommodation', 'Browse stays']];
      } elseif (hasRole('admin')) {
          $__role = 'admin'; $__roleLabel = 'Admin'; $__profileUrl = $base . '/admin/profile.php';
          $__navLinks = [[$base . '/admin/dashboard.php', 'Dashboard'], [$base . '/admin/listings.php', 'Approvals'], [$base . '/admin/users.php', 'Users'], [$base . '/admin/reports.php', 'Reports']];
          $__menu = [[$__profileUrl, 'My profile'], [$base . '/admin/support.php', 'Support inbox']];
      }
  }
  $__path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
  $__first = trim(explode(' ', trim((string)($_SESSION['full_name'] ?? '')))[0] ?? '');
  $__unreadMsgs = 0;
  if ($__badgeKey !== '') {
      try { $__uq = $GLOBALS['pdo']->prepare('SELECT COUNT(*) FROM messages m JOIN message_threads t ON t.thread_id = m.thread_id WHERE m.sender_id <> ? AND m.read_at IS NULL AND (t.tourist_id = ? OR t.host_id = ?)');
            $__uid = (int)($_SESSION['user_id'] ?? 0); $__uq->execute([$__uid, $__uid, $__uid]); $__unreadMsgs = (int)$__uq->fetchColumn(); } catch (Throwable $__e) {}
  }
  $__cls = $is_homepage ? ['hp-header', 'hp-brand', 'hp-nav', 'hp-actions'] : ['public-nav', 'public-brand', 'public-links', 'public-actions'];
?>
<header class="<?= $__cls[0] ?> se-hdr">
  <a href="<?= $base ?>/index.php" class="<?= $__cls[1] ?>" aria-label="StayExplore Kenya home">
    <span class="brand-mark">SE</span><span>StayExplore<small>KENYA</small></span>
  </a>
  <nav id="siteNav" class="<?= $__cls[2] ?>" aria-label="Main navigation">
    <div class="nav-panel-top" aria-hidden="false">
      <a href="<?= $base ?>/index.php" class="nav-panel-brand"><span class="brand-mark">SE</span><span>StayExplore<small>KENYA</small></span></a>
      <?php if ($__role !== ''): ?><span class="nav-panel-hello">Hello<?= $__first !== '' ? ', ' . h($__first) : '' ?> &middot; <?= h($__roleLabel) ?></span><?php endif; ?>
    </div>
    <?php foreach ($__navLinks as [$__href, $__label]):
        $__hp = parse_url($__href, PHP_URL_PATH);
        $__active = $__hp && $__hp === $__path && (strpos($__href, '?') === false || strpos($_SERVER['REQUEST_URI'] ?? '', substr($__href, strpos($__href, '?'))) !== false); ?>
      <a href="<?= h($__href) ?>"<?= $__active ? ' class="active" aria-current="page"' : '' ?>><?= h($__label) ?><?php if ($__label === 'Messages' && $__badgeKey !== ''): ?><span class="nav-badge" data-live-messages<?= $__unreadMsgs ? '' : ' hidden' ?>><?= $__unreadMsgs ?: '' ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
  <button class="nav-toggle" type="button" aria-label="Open menu" aria-controls="siteNav" aria-expanded="false">☰</button>
  <div class="<?= $__cls[3] ?> se-actions">
  <?php if ($__role !== ''): ?>
    <?php require_once __DIR__ . '/notifications.php'; if ($__role === 'tourist') { require_once __DIR__ . '/alerts.php'; runTouristAlerts($GLOBALS['pdo'], (int)($_SESSION['user_id'] ?? 0)); } markPageNotificationsRead($GLOBALS['pdo'], (int)($_SESSION['user_id'] ?? 0)); $__nc = unreadNotificationCount($GLOBALS['pdo'], (int)($_SESSION['user_id'] ?? 0)); ?>
    <a class="se-bell" href="<?= $base ?>/notifications.php?as=<?= urlencode($_SESSION['role'] ?? '') ?>" aria-label="Notifications<?= $__nc ? ', ' . $__nc . ' unread' : '' ?>" title="Notifications"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg><?php if ($__nc): ?><span class="se-bell-count"><?= $__nc > 99 ? '99+' : $__nc ?></span><?php endif; ?></a>
    <?php $__av = avatarHtml($_SESSION['user_id'] ?? 0, $_SESSION['full_name'] ?? $__roleLabel, currentUserPhotoName($pdo), 34, 'se-avatar-nav'); ?>
    <div class="acct" data-acct>
      <button class="acct-btn" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="acctMenu" title="Account menu"><?= $__av ?><span class="acct-name"><?= h($__first !== '' ? $__first : $__roleLabel) ?></span><span class="acct-caret" aria-hidden="true">▾</span></button>
      <div class="acct-menu" id="acctMenu">
        <div class="acct-head"><strong><?= h($_SESSION['full_name'] ?? $__roleLabel) ?></strong><span><?= h($__roleLabel) ?></span></div>
        <?php foreach ($__menu as [$__mh, $__ml]): ?><a href="<?= h($__mh) ?>"><?= h($__ml) ?></a><?php endforeach; ?>
        <div class="acct-prefs"><?php require_once __DIR__ . '/currency.php'; require_once __DIR__ . '/translate.php'; echo languageWidgetHtml() . currencySelectorHtml($base); ?></div>
        <a class="acct-out" href="<?= $base ?>/logout.php?as=<?= urlencode($_SESSION['role'] ?? '') ?>">Sign out</a>
      </div>
    </div>
  <?php else: ?>
    <div class="acct acct-guest">
      <div class="acct-prefs"><?php require_once __DIR__ . '/currency.php'; require_once __DIR__ . '/translate.php'; echo languageWidgetHtml() . currencySelectorHtml($base); ?></div>
      <a href="<?= $base ?>/login.php" class="acct-signin">Sign in</a>
      <a href="<?= $base ?>/register.php" class="btn">Sign up</a>
    </div>
  <?php endif; ?>
  </div>
</header>
<?php if (function_exists('isLoggedIn') && isLoggedIn()): ?><script>window.SE_LIVE={base:<?= json_encode(appBasePath()) ?>,role:<?= json_encode((string)($_SESSION['role'] ?? '')) ?>};</script><script src="<?= appBasePath() ?>/assets/js/live.js" defer></script><script src="<?= appBasePath() ?>/assets/js/emoji.js" defer></script><?php endif; ?>
<script src="<?= appBasePath() ?>/assets/js/nav.js" defer></script>
