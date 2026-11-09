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
                [['reviews.php'],                            'Moderate reviews',    'admin/reviews.php',     'reviews'],
                [['attraction_reviews.php'],                 'Attraction ratings',  'admin/attraction_reviews.php', null],
                [['reports.php'],                            'Incident reports',    'admin/reports.php',     'reports'],
                [['attractions.php', 'osm_attractions.php'], 'Manage attractions',  'admin/attractions.php', null],
                [['profile.php'],                            'Admin profile',       'admin/profile.php',     null],
            ];
            $section = 'Administration';
        }

        $name      = (string)($_SESSION['full_name'] ?? ucfirst($role));
        $roleLabel = $role === 'host' ? 'Host' : 'Admin';
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        ?>
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
      <a href="<?= $e($base) ?>/logout.php">Sign out</a>
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
