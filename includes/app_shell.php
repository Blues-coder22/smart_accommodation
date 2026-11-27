<?php
/**
 * Host / admin application shell, matching StayExplore_Kenya_Authenticated_Frontend:
 * a fixed green sidebar (brand, grouped navigation, account links) and a slim top bar that only shows
 * who is signed in. The public site header is NOT used inside this shell, so navigation is not repeated.
 *
 * Usage on a page:   appShellStart();   ...page content...   appShellEnd();
 * The role comes from the session and the active menu item from the current file name.
 */

require_once __DIR__ . '/profile_photo.php';

if (!function_exists('appShellBadges')) {
    /** Small counters shown next to some menu items. Never fatal: a missing table just means no badge. */
    function appShellBadges($role) {
        global $pdo;
        $b = [];
        try {
            if ($role === 'admin') {
                $b['listings'] = (int)$pdo->query("SELECT COUNT(*) FROM accommodations WHERE status = 'pending'")->fetchColumn();
                $b['reviews']  = (int)$pdo->query("SELECT COUNT(*) FROM reviews WHERE is_flagged = 1 AND is_published = 0")->fetchColumn();
                $b['reports']  = (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'pending'")->fetchColumn();
                $b['users']    = 0;
                try { $b['support'] = (int)$pdo->query("SELECT COUNT(DISTINCT thread_id) FROM support_messages WHERE is_admin = 0 AND read_at IS NULL")->fetchColumn(); } catch (Throwable $e) {}
                try {
                    $b['users'] = (int)$pdo->query("SELECT COUNT(*) FROM host_verifications WHERE status = 'pending'")->fetchColumn();
                } catch (Throwable $e) {}
            } elseif ($role === 'host') {
                $uid = (int)($_SESSION['user_id'] ?? 0);
                $q = $pdo->prepare("SELECT COUNT(*) FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id
                                    WHERE a.host_id = ? AND b.status = 'pending'");
                $q->execute([$uid]);
                $b['bookings'] = (int)$q->fetchColumn();
                $q = $pdo->prepare("SELECT COUNT(*) FROM reviews r JOIN accommodations a ON a.accommodation_id = r.accommodation_id
                                    WHERE a.host_id = ? AND r.is_published = 1 AND r.host_reply IS NULL");
                $q->execute([$uid]);
                $b['reviews'] = (int)$q->fetchColumn();
            }
        } catch (Throwable $e) {}
        return $b;
    }
}

if (!function_exists('appShellStart')) {
    function appShellStart($role = null) {
        $role = strtolower((string)($role ?? ($_SESSION['role'] ?? 'host')));
        if (!in_array($role, ['host', 'admin'], true)) $role = 'host';
        $base   = function_exists('appBasePath') ? appBasePath() : '/smart_accommodation';
        $file   = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $badges = appShellBadges($role);
        if (function_exists('markPageNotificationsRead') || is_file(__DIR__ . '/notifications.php')) { require_once __DIR__ . '/notifications.php'; global $pdo; markPageNotificationsRead($pdo, (int)($_SESSION['user_id'] ?? 0)); }

        // [file name(s), label, link, badge key]
        if ($role === 'host') {
            $items = [
                [['dashboard.php'],                          'Host overview',    'host/dashboard.php',    null],
                [['listings.php', 'edit_listing.php'],       'Manage listings',  'host/listings.php',     null],
                [['add_listing.php'],                        'Add listing',      'host/add_listing.php',  null],
                [['availability.php'],                       'Availability',     'host/availability.php', null],
                [['bookings.php', 'rate_guest.php'],         'Manage bookings',  'host/bookings.php',     'bookings'],
                [['messages.php'],                           'Messages',         'host/messages.php',     null],
                [['payments.php'],                           'Payment tracking', 'host/payments.php',     null],
                [['support.php'],                            'Contact support',  'host/support.php',      null],
                [['statement.php'],                          'Payment statement', 'host/statement.php',   null],
                [['commission.php'],                         'Commission',       'host/commission.php',   null],
                [['agreement.php'],                          'Host agreement',   'host/agreement.php',    null],
                [['reviews.php'],                            'Guest reviews',    'host/reviews.php',      'reviews'],
                [['report.php'],                             'Report incident',  'host/report.php',       null],
                [['profile.php'],                            'Host profile',     'host/profile.php',      null],
            ];
            $section = 'Hosting';
        } else {
            $items = [
                [['dashboard.php'],                          'Admin overview',      'admin/dashboard.php',   null],
                [['listings.php'],                           'Listing approvals',   'admin/listings.php',    'listings'],
                [['users.php'],                              'Manage users',        'admin/users.php',       'users'],
                [['bookings.php'],                           'Booking tracking',    'admin/bookings.php',    null],
                [['payments.php'],                           'Payment tracking',    'admin/payments.php',    null],
                [['revenue.php'],                            'Revenue & commission','admin/revenue.php',     null],
                [['photos.php'],                             'Photo safekeeping',   'admin/photos.php',      null],
                [['certificates.php'],                       'Certificates',        'admin/certificates.php', null],
                [['currencies.php'],                         'Currencies',          'admin/currencies.php',  null],
                [['setup.php'],                              'Database setup',      'admin/setup.php',       null],
                [['reviews.php'],                            'Moderate reviews',    'admin/reviews.php',     'reviews'],
                [['attraction_reviews.php'],                 'Attraction ratings',  'admin/attraction_reviews.php', null],
                [['reports.php'],                            'Incident reports',    'admin/reports.php',     'reports'],
                [['refunds.php'],                            'Refunds',             'admin/refunds.php',     null],
                [['support.php'],                            'Support inbox',       'admin/support.php',     'support'],
                [['attractions.php', 'osm_attractions.php'], 'Manage attractions',  'admin/attractions.php', null],
                [['profile.php'],                            'Admin profile',       'admin/profile.php',     null],
            ];
            $section = 'Administration';
        }

        $name      = (string)($_SESSION['full_name'] ?? ucfirst($role));
        $roleLabel = $role === 'host' ? 'Host' : 'Admin';
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        ?>
<?php if (isLoggedIn()): ?><script>window.SE_LIVE={base:<?= json_encode($base) ?>,role:<?= json_encode((string)($_SESSION['role'] ?? '')) ?>};</script><script src="<?= $e($base) ?>/assets/js/live.js" defer></script><script src="<?= $e($base) ?>/assets/js/emoji.js" defer></script><?php endif; ?>
<div class="app">
  <button type="button" class="side-toggle" aria-expanded="false" aria-controls="appSidebar">Menu</button>
  <aside class="app-sidebar" id="appSidebar">
    <a class="side-brand" href="<?= $e($base) ?>/index.php" aria-label="StayExplore Kenya home"><span class="brand-mark">SE</span><span>StayExplore<small>KENYA</small></span></a>
    <div class="side-section"><?= $e($section) ?></div>
    <nav class="side-nav" aria-label="<?= $e($section) ?>">
<?php foreach ($items as [$files, $label, $href, $badgeKey]):
          $active = in_array($file, $files, true); ?>
      <a href="<?= $e($base . '/' . $href) ?>"<?= $active ? ' class="active" aria-current="page"' : '' ?>><?= $e($label) ?><?php if ($badgeKey && !empty($badges[$badgeKey])): ?> <span class="side-badge"><?= (int)$badges[$badgeKey] ?></span><?php endif; ?></a>
<?php endforeach; ?>
    </nav>
    <div class="side-section">Account</div>
    <nav class="side-nav" aria-label="Account">
      <a href="<?= $e($base) ?>/index.php">View website</a>
      <?php require_once __DIR__ . '/translate.php'; echo languageWidgetHtml(); ?>
      <?php require_once __DIR__ . '/notifications.php'; $__nc = unreadNotificationCount($GLOBALS['pdo'], (int)($_SESSION['user_id'] ?? 0)); ?>
    <a class="se-bell" href="<?= $e($base) ?>/notifications.php?as=<?= urlencode($_SESSION['role'] ?? '') ?>" aria-label="Notifications<?= $__nc ? ', ' . $__nc . ' unread' : '' ?>" title="Notifications"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg><?php if ($__nc): ?><span class="se-bell-count"><?= $__nc > 99 ? '99+' : $__nc ?></span><?php endif; ?></a>
      <a href="<?= $e($base) ?>/logout.php?as=<?= urlencode($_SESSION['role'] ?? '') ?>">Sign out</a>
    </nav>
  </aside>
  <main class="app-main">
    <header class="app-top"><span class="user-chip"><?= $e($name) ?></span><a class="nav-avatar" href="<?= $e($base . '/' . $role . '/profile.php') ?>" title="<?= $e($roleLabel) ?> profile" aria-label="<?= $e($roleLabel) ?> profile"><?= avatarHtml($_SESSION['user_id'] ?? 0, $name, currentUserPhotoName($GLOBALS['pdo'] ?? null), 38, 'se-avatar-nav') ?></a></header>
    <div class="app-content">
<?php
    }
}

if (!function_exists('appShellEnd')) {
    function appShellEnd() {
        echo "    </div>\n  </main>\n</div>\n";
        echo '<script src="' . htmlspecialchars(appBasePath(), ENT_QUOTES, 'UTF-8') . '/assets/js/polish.js" defer></script>' . "\n";
    }
}
