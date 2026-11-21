<?php
require_once __DIR__ . '/session_boot.php';
seBootSession();
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';   // isLoggedIn() and hasRole() are defined here
require_once __DIR__ . '/profile_photo.php';
$is_homepage = basename($_SERVER['PHP_SELF'] ?? '') === 'index.php';
$base = appBasePath();
?>
<header class="<?= $is_homepage ? 'hp-header' : 'public-nav' ?>">
  <a href="<?= $base ?>/index.php" class="<?= $is_homepage ? 'hp-brand' : 'public-brand' ?>" aria-label="StayExplore Kenya home">
    <span class="brand-mark">SE</span><span>StayExplore<small>KENYA</small></span>
  </a>
  <?php
    // Every link comes before the profile; the profile avatar is the last item before Sign out.
    $__navLinks = [[$base . '/tourist/search.php?type=accommodation', 'Discover'],
                   [$base . '/tourist/search.php?type=attraction', 'Attractions'],
                   [$base . '/tourist/choose_destination.php', 'Where to go'],
                   [$base . '/how-it-works.html', 'How it works'],
                   [$base . '/about.html', 'About']];
    $__role = ''; $__profileUrl = ''; $__roleLabel = '';
    if (isLoggedIn()) {
        if (hasRole('tourist')) {
            $__role = 'tourist'; $__roleLabel = 'Traveller'; $__profileUrl = $base . '/tourist/profile.php';
            $__navLinks[] = [$base . '/tourist/bookings.php', 'My bookings'];
            $__navLinks[] = [$base . '/tourist/favourites.php', 'Favourites'];
            $__navLinks[] = [$base . '/messages.php', 'Messages'];
        } elseif (hasRole('host')) {
            $__role = 'host'; $__roleLabel = 'Host'; $__profileUrl = $base . '/host/profile.php';
            $__navLinks = [[$base . '/host/dashboard.php', 'Dashboard'],
                           [$base . '/host/listings.php', 'My listings'],
                           [$base . '/host/bookings.php', 'Bookings'],
                           [$base . '/host/messages.php', 'Messages'],
                           [$base . '/host/payments.php', 'Payments']];
        } elseif (hasRole('admin')) {
            $__role = 'admin'; $__roleLabel = 'Admin'; $__profileUrl = $base . '/admin/profile.php';
            $__navLinks = [[$base . '/admin/dashboard.php', 'Dashboard'],
                           [$base . '/admin/listings.php', 'Approvals'],
                           [$base . '/admin/users.php', 'Users'],
                           [$base . '/admin/reports.php', 'Reports']];
        }
    }
    $__path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
  ?>
  <nav id="siteNav" class="<?= $is_homepage ? 'hp-nav' : 'public-links' ?>" aria-label="Main navigation">
    <?php foreach ($__navLinks as [$__href, $__label]):
        $__hp = parse_url($__href, PHP_URL_PATH);
        $__active = $__hp && $__hp === $__path && (strpos($__href, '?') === false || strpos($_SERVER['REQUEST_URI'] ?? '', substr($__href, strpos($__href, '?'))) !== false); ?>
      <a href="<?= h($__href) ?>"<?= $__active ? ' class="active" aria-current="page"' : '' ?>><?= h($__label) ?></a>
    <?php endforeach; ?>
  </nav>
  <button class="nav-toggle" type="button" aria-label="Open menu" aria-controls="siteNav" aria-expanded="false">☰</button>
  <div class="<?= $is_homepage ? 'hp-actions' : 'public-actions' ?>">
  <?php if ($__role !== ''): ?>
    <span class="role-pill" title="You are signed in as a <?= h(strtolower($__roleLabel)) ?>"><?= h($__roleLabel) ?></span>
    <?php $__av = avatarHtml($_SESSION['user_id'] ?? 0, $_SESSION['full_name'] ?? $__roleLabel, currentUserPhotoName($pdo), 36, 'se-avatar-nav'); ?>
    <a class="nav-avatar" href="<?= h($__profileUrl) ?>" title="My profile" aria-label="My profile"><?= $__av ?></a>
    <?php require_once __DIR__ . '/currency.php'; require_once __DIR__ . '/translate.php'; echo languageWidgetHtml() . currencySelectorHtml($base); ?>
    <?php require_once __DIR__ . '/notifications.php'; if ($__role === 'tourist') { require_once __DIR__ . '/alerts.php'; runTouristAlerts($GLOBALS['pdo'], (int)($_SESSION['user_id'] ?? 0)); } $__nc = unreadNotificationCount($GLOBALS['pdo'], (int)($_SESSION['user_id'] ?? 0)); ?>
    <a class="se-bell" href="<?= $base ?>/notifications.php?as=<?= urlencode($_SESSION['role'] ?? '') ?>" aria-label="Notifications<?= $__nc ? ', ' . $__nc . ' unread' : '' ?>" title="Notifications"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg><?php if ($__nc): ?><span class="se-bell-count"><?= $__nc > 99 ? '99+' : $__nc ?></span><?php endif; ?></a>
    <a href="<?= $base ?>/logout.php?as=<?= urlencode($_SESSION['role'] ?? '') ?>" class="btn btn-outline">Sign out</a>
  <?php else: ?>
    <?php require_once __DIR__ . '/currency.php'; require_once __DIR__ . '/translate.php'; echo languageWidgetHtml() . currencySelectorHtml($base); ?>
    <a href="<?= $base ?>/login.php">Sign in</a>
    <a href="<?= $base ?>/register.php" class="btn">Sign up</a>
  <?php endif; ?>
  </div>
</header>
