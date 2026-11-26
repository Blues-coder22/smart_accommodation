<?php
/**
 * v9.38 tourist top bar (desktop) and navigation drawer (phone).
 *   Desktop: logo | Discover  Attractions  Where to go  My bookings | messages, notifications, favourites, account menu
 *   Phone:   logo + bell + menu button; the menu opens a drawer with the logo on top, then Explore, Your account, Help,
 *            Preferences (language, currency, dark mode) and Sign out.
 * Used by includes/header.php (every /tourist/ page, messages, notifications ...) and by index.php for signed-in travellers.
 * It only changes how the existing links are laid out. Every destination is a page that already existed.
 */
require_once __DIR__ . '/session_boot.php';
seBootSession();
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/profile_photo.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/translate.php';

if (!function_exists('tnIcon')) {
    function tnIcon($name) {
        $p = [
            'chat'   => '<path d="M21 11.5a8.4 8.4 0 0 1-8.9 8.4 9 9 0 0 1-3.6-.8L3 21l1.9-5.1A8.3 8.3 0 0 1 3.5 11.5 8.5 8.5 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/>',
            'bell'   => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
            'heart'  => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21.2l7.8-7.7 1-1.1a5.5 5.5 0 0 0 0-7.8z"/>',
            'user'   => '<circle cx="12" cy="12" r="9.5"/><circle cx="12" cy="10" r="3.2"/><path d="M5.8 18.6c1.3-2.3 3.6-3.4 6.2-3.4s4.9 1.1 6.2 3.4"/>',
            'home'   => '<path d="M3 11l9-7.5 9 7.5"/><path d="M5.5 9.5V20h13V9.5"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="M20.5 20.5L16 16"/>',
            'star'   => '<path d="M12 3.6l2.6 5.3 5.8.9-4.2 4.1 1 5.8L12 16.9l-5.2 2.8 1-5.8-4.2-4.1 5.8-.9z"/>',
            'pin'    => '<path d="M12 21s7-6.1 7-11.5a7 7 0 1 0-14 0C5 14.9 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.4"/>',
            'ticket' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
            'help'   => '<circle cx="12" cy="12" r="9.5"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.6 2.2c-.8.5-1.2 1-1.2 1.9"/><path d="M12 17h.01"/>',
            'info'   => '<circle cx="12" cy="12" r="9.5"/><path d="M12 11v5.5M12 7.6h.01"/>',
            'out'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
            'close'  => '<path d="M6 6l12 12M18 6L6 18"/>',
            'menu'   => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'caret'  => '<path d="M6 9l6 6 6-6"/>',
        ];
        return '<svg class="tn-i" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
    }
}

if (!function_exists('seRenderTouristNav')) {
/** Prints the tourist header. Everything lives inside this function so no variable leaks into the page that includes it. */
function seRenderTouristNav($tnSkipMarkRead = false) {
$tnBase  = appBasePath();
$tnUid   = (int)($_SESSION['user_id'] ?? 0);
$tnName  = trim((string)($_SESSION['full_name'] ?? ''));
$tnFirst = $tnName !== '' ? explode(' ', $tnName)[0] : 'Traveller';
$tnPath  = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$tnQuery = (string)($_SERVER['QUERY_STRING'] ?? '');
$tnIsActive = function ($href) use ($tnPath) {
    $hp = parse_url($href, PHP_URL_PATH);
    if (!$hp || $hp !== $tnPath) return false;
    $q = parse_url($href, PHP_URL_QUERY);
    if (!$q) return true;
    parse_str($q, $want);
    foreach ($want as $k => $v) { if ((string)($_GET[$k] ?? '') !== (string)$v) return false; }
    return true;
};

// Run the tourist alerts and clear this page's notifications exactly as the previous header did.
try {
    runTouristAlerts($GLOBALS['pdo'], $tnUid);
    if (!$tnSkipMarkRead) markPageNotificationsRead($GLOBALS['pdo'], $tnUid);
} catch (Throwable $e) { error_log('tourist_nav alerts: ' . $e->getMessage()); }
$tnNotif = 0; $tnMsgs = 0;
try { $tnNotif = (int)unreadNotificationCount($GLOBALS['pdo'], $tnUid); } catch (Throwable $e) {}
try {
    $q = $GLOBALS['pdo']->prepare('SELECT COUNT(*) FROM messages m JOIN message_threads t ON t.thread_id = m.thread_id WHERE m.sender_id <> ? AND m.read_at IS NULL AND (t.tourist_id = ? OR t.host_id = ?)');
    $q->execute([$tnUid, $tnUid, $tnUid]);
    $tnMsgs = (int)$q->fetchColumn();
} catch (Throwable $e) {}

$tnHome     = $tnBase . '/index.php';
$tnProfile  = $tnBase . '/tourist/profile.php';
$tnFavs     = $tnBase . '/tourist/favourites.php';
$tnMsgUrl   = $tnBase . '/messages.php';
$tnNotifUrl = $tnBase . '/notifications.php?as=' . urlencode($_SESSION['role'] ?? 'tourist');
$tnSupport  = $tnBase . '/tourist/support.php';
$tnLogout   = $tnBase . '/logout.php?as=' . urlencode($_SESSION['role'] ?? 'tourist');
$tnMain = [
    [$tnBase . '/tourist/search.php?type=accommodation', 'Discover',    'search'],
    [$tnBase . '/tourist/search.php?type=attraction',    'Attractions', 'star'],
    [$tnBase . '/tourist/choose_destination.php',        'Where to go', 'pin'],
    [$tnBase . '/tourist/bookings.php',                  'My bookings', 'ticket'],
];
$tnPhoto = '';
try { $tnPhoto = (string)currentUserPhotoName($GLOBALS['pdo']); } catch (Throwable $e) {}
$tnAvatar = $tnPhoto !== '' ? avatarHtml($tnUid, $tnName ?: 'Traveller', $tnPhoto, 34, 'tn-avatar') : tnIcon('user');
$tnBadge = function ($n, $live = false) { return '<span class="tn-badge" ' . ($live ? 'data-live-messages ' : '') . ($n > 0 ? '' : 'hidden') . '>' . ($n > 99 ? '99+' : ($n > 0 ? $n : '')) . '</span>'; };
?>
<link rel="stylesheet" href="<?= h($tnBase) ?>/assets/css/tourist-nav.css">
<header class="tn" id="tnHeader" data-tn>
  <div class="tn-bar">
    <a class="tn-brand" href="<?= h($tnHome) ?>" aria-label="StayExplore Kenya home"><span class="tn-mark">SE</span><span class="tn-name">StayExplore</span></a>

    <!-- Main navigation: a row on desktop, the drawer on phones -->
    <div class="tn-panel" id="tnPanel" role="dialog" aria-modal="false" aria-label="Menu">
      <div class="tn-panel-head">
        <a class="tn-brand" href="<?= h($tnHome) ?>"><span class="tn-mark">SE</span><span class="tn-name">StayExplore</span></a>
        <button class="tn-close" type="button" data-tn-close aria-label="Close menu"><?= tnIcon('close') ?></button>
      </div>
      <div class="tn-hello"><?= $tnAvatar ?><span><strong><?= h($tnFirst) ?></strong><small>Traveller account</small></span></div>

      <nav class="tn-links" aria-label="Main navigation">
        <span class="tn-label">Explore</span>
        <a class="tn-link tn-only-drawer<?= $tnIsActive($tnHome) ? ' active' : '' ?>" href="<?= h($tnHome) ?>"<?= $tnIsActive($tnHome) ? ' aria-current="page"' : '' ?>><?= tnIcon('home') ?><span>Home</span></a>
        <?php foreach ($tnMain as [$href, $label, $ico]): $act = $tnIsActive($href); ?>
          <a class="tn-link<?= $act ? ' active' : '' ?>" href="<?= h($href) ?>"<?= $act ? ' aria-current="page"' : '' ?>><?= tnIcon($ico) ?><span><?= h($label) ?></span></a>
        <?php endforeach; ?>
      </nav>

      <!-- Phone drawer only: account options kept apart from the main links -->
      <div class="tn-drawer-sec">
        <span class="tn-label">Your account</span>
        <a class="tn-link" href="<?= h($tnMsgUrl) ?>"><?= tnIcon('chat') ?><span>Messages</span><?= $tnBadge($tnMsgs, true) ?></a>
        <a class="tn-link" href="<?= h($tnNotifUrl) ?>"><?= tnIcon('bell') ?><span>Notifications</span><?= $tnNotif > 0 ? '<span class="tn-badge">' . ($tnNotif > 99 ? '99+' : $tnNotif) . '</span>' : '' ?></a>
        <a class="tn-link" href="<?= h($tnFavs) ?>"><?= tnIcon('heart') ?><span>Favourites</span></a>
        <a class="tn-link" href="<?= h($tnProfile) ?>"><?= tnIcon('user') ?><span>My profile</span></a>
      </div>
      <div class="tn-drawer-sec">
        <span class="tn-label">Help</span>
        <a class="tn-link" href="<?= h($tnSupport) ?>"><?= tnIcon('help') ?><span>Contact support</span></a>
        <a class="tn-link" href="<?= h($tnBase) ?>/how-it-works.html"><?= tnIcon('info') ?><span>How it works</span></a>
        <a class="tn-link" href="<?= h($tnBase) ?>/about.html"><?= tnIcon('info') ?><span>About</span></a>
      </div>
      <div class="tn-drawer-sec tn-drawer-prefs" data-tn-prefs-drawer>
        <span class="tn-label">Preferences</span>
      </div>
      <a class="tn-signout tn-only-drawer" href="<?= h($tnLogout) ?>"><?= tnIcon('out') ?><span>Sign out</span></a>
    </div>

    <!-- Icons: messages, notifications, favourites, account -->
    <div class="tn-actions">
      <a class="tn-icon tn-hide-sm" href="<?= h($tnMsgUrl) ?>" aria-label="Messages" title="Messages"><?= tnIcon('chat') ?><?= $tnBadge($tnMsgs, true) ?></a>
      <a class="tn-icon se-bell" href="<?= h($tnNotifUrl) ?>" aria-label="Notifications<?= $tnNotif ? ', ' . $tnNotif . ' unread' : '' ?>" title="Notifications"><?= tnIcon('bell') ?><?php if ($tnNotif): ?><span class="se-bell-count"><?= $tnNotif > 99 ? '99+' : $tnNotif ?></span><?php endif; ?></a>
      <a class="tn-icon tn-hide-sm" href="<?= h($tnFavs) ?>" aria-label="Favourites" title="Favourites"><?= tnIcon('heart') ?></a>

      <div class="tn-acct tn-hide-sm" data-tn-acct>
        <button class="tn-icon tn-acct-btn" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="tnMenu" aria-label="Account menu" title="Account"><?= $tnAvatar ?></button>
        <div class="tn-menu" id="tnMenu">
          <div class="tn-menu-head"><?= $tnAvatar ?><span><strong><?= h($tnName ?: 'Traveller') ?></strong><small>Traveller</small></span></div>
          <div class="tn-menu-group">
            <a href="<?= h($tnProfile) ?>">My profile</a>
            <a href="<?= h($tnFavs) ?>">Favourites</a>
            <a href="<?= h($tnMsgUrl) ?>">Messages</a>
          </div>
          <div class="tn-menu-group">
            <span class="tn-label">Help</span>
            <a href="<?= h($tnSupport) ?>">Contact support</a>
            <a href="<?= h($tnBase) ?>/how-it-works.html">How it works</a>
            <a href="<?= h($tnBase) ?>/about.html">About</a>
          </div>
          <div class="tn-menu-group tn-prefs" data-tn-prefs>
            <span class="tn-label">Preferences</span>
            <?= languageWidgetHtml() . currencySelectorHtml($tnBase) ?>
          </div>
          <a class="tn-menu-out" href="<?= h($tnLogout) ?>">Sign out</a>
        </div>
      </div>

      <button class="tn-burger" type="button" aria-label="Open menu" aria-controls="tnPanel" aria-expanded="false"><?= tnIcon('menu') ?></button>
    </div>
  </div>
  <div class="tn-scrim" data-tn-close hidden></div>
</header>
<script>window.SE_LIVE={base:<?= json_encode($tnBase) ?>,role:<?= json_encode((string)($_SESSION['role'] ?? 'tourist')) ?>};</script>
<script src="<?= h($tnBase) ?>/assets/js/live.js" defer></script>
<script src="<?= h($tnBase) ?>/assets/js/emoji.js" defer></script>
<script src="<?= h($tnBase) ?>/assets/js/tourist-nav.js" defer></script>
<?php
}
}
