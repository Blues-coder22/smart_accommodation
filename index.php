<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_photo.php';
require_once __DIR__ . '/includes/attraction_content.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$base = appBasePath();
$isTourist = isLoggedIn() && hasRole('tourist');

// Save / unsave a featured stay (tourists only) — same favourites table as the stay page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fav_stay'])) {
    requireCsrf();
    $stayId = (int)$_POST['fav_stay'];
    if ($isTourist && $stayId > 0) {
        $q = $pdo->prepare("SELECT favourite_id FROM favourites WHERE tourist_id = ? AND item_type = 'accommodation' AND item_id = ?");
        $q->execute([$_SESSION['user_id'], $stayId]);
        if ($q->fetch()) {
            $pdo->prepare("DELETE FROM favourites WHERE tourist_id = ? AND item_type = 'accommodation' AND item_id = ?")->execute([$_SESSION['user_id'], $stayId]);
        } else {
            $pdo->prepare("INSERT INTO favourites (tourist_id, item_type, item_id) VALUES (?, 'accommodation', ?)")->execute([$_SESSION['user_id'], $stayId]);
        }
    }
    header('Location: ' . $base . '/index.php#stays');
    exit;
}

publishDueReviews($pdo);
$featured = [];
$popularAttractions = [];
try {
    // Subqueries avoid duplicate rows and work with MySQL/MariaDB strict GROUP BY settings.
    $stmt = $pdo->query("SELECT a.*,
        COALESCE((SELECT AVG(r.rating) FROM reviews r WHERE r.accommodation_id=a.accommodation_id AND r.is_published=1),0) AS avg_rating,
        (SELECT COUNT(*) FROM bookings b WHERE b.accommodation_id=a.accommodation_id AND b.status='completed') AS total_bookings,
        (SELECT p.photo_path FROM accommodation_photos p WHERE p.accommodation_id=a.accommodation_id ORDER BY p.is_primary DESC, p.photo_id ASC LIMIT 1) AS primary_photo
        FROM accommodations a WHERE a.status='approved'
        ORDER BY avg_rating DESC, a.created_at DESC LIMIT 9");
    $featured = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $featured = []; }
try {
    $stmt = $pdo->query("SELECT attraction_id, name, description, category, latitude, longitude, location_name, entry_fee, photo_path FROM attractions ORDER BY created_at DESC LIMIT 9");
    $popularAttractions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $popularAttractions = []; }
$attExtras = getAttractionExtrasMap($pdo, $popularAttractions);

// How many approved stays are within 50 km of each attraction (shown as "N stays nearby"), then pick the 3 best connected.
$attTotal = count($popularAttractions);
try { $attTotal = (int)$pdo->query("SELECT COUNT(*) FROM attractions")->fetchColumn(); } catch (Throwable $e) {}
foreach ($popularAttractions as $k => $pa) {
    $popularAttractions[$k]['stays_near'] = 0;
    if ($pa['latitude'] !== null && $pa['longitude'] !== null) {
        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM (SELECT " . haversineSql('a.latitude', 'a.longitude', ':la', ':lo', ':la2') . " AS d
                                FROM accommodations a WHERE a.status = 'approved' AND a.latitude IS NOT NULL AND a.longitude IS NOT NULL HAVING d <= 50) x");
            $q->execute([':la' => (float)$pa['latitude'], ':lo' => (float)$pa['longitude'], ':la2' => (float)$pa['latitude']]);
            $popularAttractions[$k]['stays_near'] = (int)$q->fetchColumn();
        } catch (Throwable $e) {}
    }
}
usort($popularAttractions, function ($x, $y) { return $y['stays_near'] <=> $x['stays_near']; });
$homeAttractions = array_slice($popularAttractions, 0, 3);

function homeInsightLabel($category) {
    $c = strtolower((string)$category);
    if (strpos($c, 'wild') !== false || strpos($c, 'safari') !== false) return 'Wildlife insight';
    if (strpos($c, 'park') !== false || strpos($c, 'nature') !== false || strpos($c, 'lake') !== false || strpos($c, 'mountain') !== false) return 'Nature insight';
    if (strpos($c, 'beach') !== false || strpos($c, 'coast') !== false) return 'Coast insight';
    if (strpos($c, 'histor') !== false || strpos($c, 'museum') !== false || strpos($c, 'cultur') !== false) return 'Culture insight';
    return 'Visitor tip';
}

// Which featured stays has this tourist saved?
$savedStays = [];
if ($isTourist) {
    try {
        $q = $pdo->prepare("SELECT item_id FROM favourites WHERE tourist_id = ? AND item_type = 'accommodation'");
        $q->execute([$_SESSION['user_id']]);
        $savedStays = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {}
}

// Same smart score as the Discover page (rating, price and popularity). No place is chosen on the home page,
// so distance is left out and the other weights are scaled up to fill its share.
foreach ($featured as &$f) { $f['distance'] = 99999; }
unset($f);
$featured = applyWeightedScoring($featured, false);
foreach ($featured as &$f) { $f['match'] = (int)round(min(1, max(0, (float)$f['smart_score'])) * 100); }
unset($f);

function homeImagePath($path, $fallback, $kind = 'accommodations') {
    $path = trim((string)$path);
    if ($path === '') return 'assets/' . $fallback;
    if ($kind === 'attractions' && preg_match('~^uploads/accommodations/~i', $path)) {
        return 'uploads/attractions/' . rawurlencode(basename($path));
    }
    if (preg_match('~^(https?://|/|assets/|uploads/|StayExplore_Kenya_Authenticated_Frontend/)~i', $path)) return $path;
    $folder = ($kind === 'attractions') ? 'uploads/attractions/' : 'uploads/accommodations/';
    return $folder . rawurlencode(basename($path));
}
function homePhotoExists($relative) {
    if (preg_match('~^https?://~i', $relative)) return true;
    return is_file(__DIR__ . '/' . rawurldecode(ltrim($relative, '/')));
}
function hxIcon($name) {
    $p = [
        'compass'  => '<circle cx="12" cy="12" r="9"/><path d="M15.6 8.4l-2 5.2-5.2 2 2-5.2z"/>',
        'sparkle'  => '<path d="M11 3l1.7 4.6L17.3 9.3l-4.6 1.7L11 15.6l-1.7-4.6L4.7 9.3l4.6-1.7z"/><path d="M18.5 14.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
        'check'    => '<circle cx="12" cy="12" r="9"/><path d="M8.3 12.4l2.6 2.6 4.8-5.2"/>',
        'shield'   => '<path d="M12 3l7 3v5.2c0 4.4-3 8-7 9.8-4-1.8-7-5.4-7-9.8V6z"/><path d="M9 12l2.2 2.2L15 10.4"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.4 2.9-6 6.5-6s6.5 2.6 6.5 6"/><path d="M16 4.6a3.5 3.5 0 010 6.8M18.2 14.4c2 .7 3.3 2.5 3.3 5.6"/>',
        'pin'      => '<path d="M12 21s7-6.1 7-11.5a7 7 0 10-14 0C5 14.9 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.4"/>',
        'heart'    => '<path d="M12 20s-7.5-4.6-7.5-10.2A4.4 4.4 0 0112 7.3a4.4 4.4 0 017.5 2.5C19.5 15.4 12 20 12 20z"/>',
        'star'     => '<path d="M12 3.6l2.6 5.3 5.8.9-4.2 4.1 1 5.8L12 16.9l-5.2 2.8 1-5.8-4.2-4.1 5.8-.9z"/>',
        'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'building' => '<path d="M5 21V4h9v17M14 9h5v12M8 8h3M8 12h3M8 16h3M3 21h18"/>',
        'left'     => '<path d="M15 6l-6 6 6 6"/>',
        'right'    => '<path d="M9 6l6 6-6 6"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

// ---- hero slides: real attraction photos from the database first, then the built-in Kenya scenes ----
$slides = [];
foreach ($popularAttractions as $a) {
    if (count($slides) >= 3) break;
    $img = homeImagePath($a['photo_path'] ?? '', '', 'attractions');
    if (trim((string)($a['photo_path'] ?? '')) !== '' && homePhotoExists($img)) {
        $slides[] = ['img' => $img, 'label' => (string)$a['name'], 'url' => $base . '/tourist/attraction.php?id=' . (int)$a['attraction_id']];
    }
}
$fallbackSlides = [
    ['assets/user-sunset.jpg',     'Maasai Mara savannah',  'Maasai'],
    ['assets/user-mount-kenya.jpg','Mount Kenya',           'Mount Kenya'],
    ['assets/user-lamu.jpg',       'Lamu Old Town',         'Lamu'],
    ['assets/user-beach.jpg',      'The Kenyan coast',      'Diani'],
];
foreach ($fallbackSlides as $fs) {
    if (count($slides) >= 5) break;
    $slides[] = ['img' => $fs[0], 'label' => $fs[1], 'url' => $base . '/tourist/search.php?type=attraction&location=' . rawurlencode($fs[2])];
}

$destinationFallbacks = [
    ['name'=>'Maasai Mara','where'=>'Narok','category'=>'Wildlife','tagline'=>'Wide-open savannahs, wildlife and unforgettable safari experiences.','image'=>'assets/user-sunset.jpg'],
    ['name'=>'Mount Kenya','where'=>'Laikipia','category'=>'Mountain','tagline'=>'Forest trails, alpine lakes and dramatic mountain scenery.','image'=>'assets/user-mount-kenya.jpg'],
    ['name'=>'Lamu Old Town','where'=>'Lamu','category'=>'Culture','tagline'=>'Swahili heritage, dhow trips, beaches and quiet historic lanes.','image'=>'assets/user-lamu.jpg'],
];

$userName  = $_SESSION['full_name'] ?? '';
$profileUrl = $base . '/' . (hasRole('host') ? 'host' : (hasRole('admin') ? 'admin' : 'tourist')) . '/profile.php';
$dashUrl   = isLoggedIn() ? (hasRole('host') ? $base . '/host/dashboard.php' : (hasRole('admin') ? $base . '/admin/dashboard.php' : $base . '/tourist/bookings.php')) : '';
$dashLabel = hasRole('host') ? 'Host dashboard' : (hasRole('admin') ? 'Admin dashboard' : 'My bookings');
$hostUrl   = isLoggedIn() && hasRole('host') ? $base . '/host/add_listing.php' : $base . '/register.php?role=host';
$stayUrl   = $base . '/tourist/search.php?type=accommodation';
$attrUrl   = $base . '/tourist/search.php?type=attraction';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>StayExplore Kenya — Discover stays and attractions</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Serif&family=Work+Sans:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="assets/css/home.css">
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 32 32%27%3E%3Ccircle cx=%2716%27 cy=%2716%27 r=%2716%27 fill=%27%230d3b2e%27/%3E%3Ctext x=%2716%27 y=%2721%27 font-size=%2713%27 fill=%27white%27 text-anchor=%27middle%27 font-family=%27Georgia%27%3ESE%3C/text%3E%3C/svg%3E">
</head>
<body class="hx">

<header class="hx-header" id="hxHeader">
  <a class="hx-brand" href="<?= h($base) ?>/index.php" aria-label="StayExplore Kenya home">
    <span class="hx-logo">SE</span>
    <span class="hx-brand-name">StayExplore<small>KENYA</small></span>
  </a>
  <button class="hx-burger" type="button" id="hxBurger" aria-label="Toggle navigation" aria-expanded="false">☰</button>
  <nav class="hx-nav" aria-label="Main navigation">
    <a href="<?= h($stayUrl) ?>">Stays</a>
    <a href="<?= h($attrUrl) ?>">Attractions</a>
    <a href="<?= h($base) ?>/how-it-works.html">How it works</a>
    <a href="<?= h($hostUrl) ?>">List your property</a>
  </nav>
  <div class="hx-actions">
    <?php if (isLoggedIn()): ?>
      <a class="link" href="<?= h($dashUrl) ?>"><?= h($dashLabel) ?></a>
      <a class="nav-avatar" href="<?= h($profileUrl) ?>" title="My profile" aria-label="My profile"><?= avatarHtml($_SESSION['user_id'] ?? 0, $userName, currentUserPhotoName($pdo), 40) ?></a>
      <a class="hx-btn" href="<?= h($base) ?>/logout.php">Sign out</a>
    <?php else: ?>
      <a class="link" href="<?= h($base) ?>/login.php">Sign in</a>
      <a class="hx-btn" href="<?= h($base) ?>/register.php">Sign up</a>
    <?php endif; ?>
  </div>
</header>

<?php if (($_GET['account'] ?? '') === 'closed'): ?>
  <div class="hx-flash">Your account has been closed. Your historical booking and payment records have been retained. Contact the administrator if you need help.</div>
<?php endif; ?>
<?php if (($_GET['account'] ?? '') === 'deleted'): ?>
  <div class="hx-flash">Your account has been permanently deleted and your personal details erased.</div>
<?php endif; ?>

<!-- Hero with sliding images -->
<section class="hx-hero" aria-roledescription="carousel" aria-label="Featured places in Kenya">
  <div class="hx-viewport">
    <div class="hx-track" id="hxTrack">
      <?php foreach ($slides as $i => $s): ?>
        <div class="hx-slide<?= $i === 0 ? ' on' : '' ?>" data-label="<?= h($s['label']) ?>" data-url="<?= h($s['url']) ?>"><div class="hx-slide-img" style="background-image:url('<?= h($s['img']) ?>')"></div></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="hx-hero-inner">
    <span class="hx-pill"><?= hxIcon('sparkle') ?> Smart recommendations for Kenya and beyond</span>
    <h1>Discover Kenya.<br>Stay.Explore.<br>Experience.</h1>
    <p class="lead">From cosy rooms in Nairobi to lodges at the foot of Mt. Kenya, find where to stay and discover nearby attractions or start with a place you want to see and find the best stays around it.</p>
    <div class="hx-hero-cta">
      <a class="hx-btn light" href="<?= h($stayUrl) ?>">View stays</a>
      <a class="hx-btn ghost" href="<?= h($attrUrl) ?>">View attractions</a>
    </div>
  </div>
  <div class="hx-ctrl">
    <button class="hx-arrow" type="button" id="hxPrev" aria-label="Previous image"><?= hxIcon('left') ?></button>
    <div class="hx-dots" id="hxDots"></div>
    <button class="hx-arrow" type="button" id="hxNext" aria-label="Next image"><?= hxIcon('right') ?></button>
  </div>
  <div class="hx-caption" id="hxCaption"><span id="hxCapText"></span><a id="hxCapLink" href="#">Explore →</a></div>
</section>

<!-- Benefits -->
<section class="hx-benefits">
  <div class="hx-benefit"><?= hxIcon('sparkle') ?><h3>Smart recommendations</h3><p>Results ranked to help you choose with confidence.</p></div>
  <div class="hx-benefit"><?= hxIcon('check') ?><h3>Verified reviews</h3><p>Only guests with confirmed bookings can leave reviews.</p></div>
  <div class="hx-benefit"><?= hxIcon('shield') ?><h3>Booking information</h3><p>Keep your booking details and payment status organised.</p></div>
  <div class="hx-benefit"><?= hxIcon('users') ?><h3>Local hosts</h3><p>Supporting Kenyan property owners and communities.</p></div>
</section>

<!-- Featured stays -->
<section class="hx-section hx-reveal" id="stays">
  <div class="hx-head">
    <div><div class="hx-kicker">Curated for your journey</div><h2>Featured Stays</h2></div>
    <p>Hand-picked places to unwind, each close to something worth exploring.</p>
    <div class="hx-head-right">
      <a class="hx-viewall" href="<?= h($stayUrl) ?>">View all stays →</a>
      <?php if (count($featured) > 3): ?><div class="hx-arrows"><button type="button" data-slide="#stayRow" data-dir="-1" aria-label="Previous stays"><?= hxIcon('left') ?></button><button type="button" data-slide="#stayRow" data-dir="1" aria-label="Next stays"><?= hxIcon('right') ?></button></div><?php endif; ?>
    </div>
  </div>
  <?php if ($featured): ?>
  <div class="hx-row" id="stayRow">
    <?php foreach ($featured as $acc):
        $photo = homeImagePath($acc['primary_photo'] ?? '', 'user-house.jpg');
        $url = $base . '/tourist/details.php?id=' . (int)$acc['accommodation_id'];
        $saved = in_array((int)$acc['accommodation_id'], $savedStays, true);
    ?>
    <article class="hx-stay">
      <div class="hx-stay-photo">
        <a href="<?= h($url) ?>"><img src="<?= h($photo) ?>" alt="<?= h($acc['title']) ?>" loading="lazy" onerror="this.onerror=null;this.src='assets/user-house.jpg';"></a>
        <span class="hx-match">Smart score <?= (int)$acc['match'] ?></span>
        <?php if ($isTourist): ?>
          <div class="hx-heart<?= $saved ? ' on' : '' ?>"><form method="POST" action="<?= h($base) ?>/index.php#stays" style="display:contents">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="fav_stay" value="<?= (int)$acc['accommodation_id'] ?>">
            <button type="submit" style="all:unset;cursor:pointer;display:grid;place-items:center;width:42px;height:42px" aria-label="<?= $saved ? 'Remove from favourites' : 'Save to favourites' ?>"><?= hxIcon('heart') ?></button></form></div>
        <?php else: ?>
          <a class="hx-heart" href="<?= h($base) ?>/login.php" aria-label="Sign in to save this stay"><?= hxIcon('heart') ?></a>
        <?php endif; ?>
      </div>
      <div class="hx-stay-body">
        <div class="hx-stay-top"><h3><a href="<?= h($url) ?>"><?= h($acc['title']) ?></a></h3>
          <span class="hx-rate"><?= hxIcon('star') ?> <?= (float)$acc['avg_rating'] > 0 ? number_format((float)$acc['avg_rating'], 1) : 'New' ?></span></div>
        <div class="hx-loc"><?= hxIcon('pin') ?> <?= h($acc['location_name'] ?: 'Kenya') ?></div>
        <div class="hx-stay-foot">
          <span class="hx-price">KES <?= number_format((float)$acc['price_per_night']) ?> <small>/ night</small></span>
          <a class="hx-link" href="<?= h($url) ?>">View stay <?= hxIcon('arrow') ?></a>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="hx-stay" style="max-width:640px"><div class="hx-stay-body">
    <div class="hx-kicker">New listings coming soon</div><h3>No approved stays yet</h3>
    <p style="color:var(--hx-muted)">When a host adds a property and an administrator approves it, it will appear here and in search results. You can still explore attractions meanwhile.</p>
    <div style="display:flex;gap:18px;flex-wrap:wrap"><a class="hx-link" href="<?= h($stayUrl) ?>">Browse stays <?= hxIcon('arrow') ?></a><a class="hx-link" href="<?= h($base) ?>/register.php?role=host">Become a host <?= hxIcon('arrow') ?></a></div>
  </div></div>
  <?php endif; ?>
</section>

<!-- Popular attractions -->
<section class="hx-section alt hx-reveal" id="attractions">
  <div class="hx-wrap">
    <div class="hx-head">
      <div><div class="hx-kicker">Explore by destination</div><h2>Popular Attractions</h2></div>
      <p>Discover Kenya's iconic landscapes, wildlife reserves, and coastal escapes.</p>
      <div class="hx-head-right">
        <a class="hx-viewall" href="<?= h($attrUrl) ?>">View all <?= (int)$attTotal ?> attractions →</a>
      </div>
    </div>
    <?php
      // Frontend layout: one large feature card, then two photo cards. Text sits on the photo.
      $cards = [];
      if ($homeAttractions) {
        foreach ($homeAttractions as $a) {
          $ex = $attExtras[(int)$a['attraction_id']] ?? [];
          $teaser = attractionTeaser($a, $ex);
          $cards[] = [
            'img'   => homeImagePath($a['photo_path'] ?? '', 'kenya-lake-shore.jpg', 'attractions'),
            'name'  => $a['name'], 'kicker' => trim(($a['location_name'] ?: 'Kenya') . ' · ' . ($a['category'] ?: 'Attraction')),
            'title' => attractionBlurb($a, $ex),
            'label' => $teaser['label'] === 'Kenya fact' ? 'Kenya fact' : homeInsightLabel($a['category']),
            'text'  => $teaser['text'],
            'open'  => $base . '/tourist/attraction.php?id=' . (int)$a['attraction_id'],
            'stays' => $base . '/tourist/search.php?type=accommodation&attraction_id=' . (int)$a['attraction_id'],
            'near'  => (int)$a['stays_near'],
          ];
        }
      } else {
        foreach (array_slice($destinationFallbacks, 0, 3) as $a) {
          $cards[] = ['img' => $a['image'], 'name' => $a['name'], 'kicker' => $a['where'] . ' · ' . $a['category'], 'title' => $a['tagline'], 'label' => '', 'text' => '',
                      'open' => $attrUrl . '&location=' . rawurlencode($a['name']), 'stays' => '', 'near' => 0];
        }
      }
    ?>
    <div class="hx-att-layout">
      <?php foreach ($cards as $i => $c): ?>
      <article class="<?= $i === 0 ? 'hx-att-feature' : 'hx-att-card' ?>">
        <img src="<?= h($c['img']) ?>" alt="<?= h($c['name']) ?>" loading="lazy" onerror="this.onerror=null;this.src='assets/kenya-lake-shore.jpg';">
        <div class="copy">
          <div class="hx-att-kicker"><?= h($c['kicker']) ?></div>
          <h3><a href="<?= h($c['open']) ?>"><?= h($c['title']) ?></a></h3>
          <?php if ($c['text'] !== ''): ?>
          <p class="hx-insight"><span><?= h($c['label']) ?></span> <?= h($c['text']) ?></p>
          <?php endif; ?>
          <div class="hx-att-links">
            <a href="<?= h($c['open']) ?>">Explore <?= h($c['name']) ?></a>
            <?php if ($c['stays'] !== '' && $c['near'] > 0): ?><span aria-hidden="true">·</span> <a href="<?= h($c['stays']) ?>"><?= (int)$c['near'] ?> stay<?= $c['near'] === 1 ? '' : 's' ?> nearby</a><?php endif; ?>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Host call to action -->
<section class="hx-host hx-reveal">
  <img src="assets/user-house.jpg" alt="A welcoming Kenyan host home" loading="lazy">
  <div>
    <div class="hx-kicker">For Kenyan hosts</div>
    <h2>Your place belongs in someone’s story.</h2>
    <p>List your property, manage bookings and connect with travellers looking for an authentic Kenyan stay.</p>
    <div class="row">
      <a class="hx-btn" href="<?= h($hostUrl) ?>"><?= hxIcon('building') ?> List your property</a>
      <a class="hx-btn line" href="<?= h($base) ?>/how-hosting-works.php">How hosting works</a>
    </div>
  </div>
</section>

<footer class="hx-footer">
  <div class="hx-footer-grid">
    <div><div class="brand"><span class="hx-logo sm">SE</span> StayExplore Kenya</div><p>Thoughtfully matching travellers with Kenyan stays and experiences.</p></div>
    <div><h4>Explore</h4><a href="<?= h($stayUrl) ?>">Stays</a><a href="<?= h($attrUrl) ?>">Attractions</a><a href="<?= h($stayUrl) ?>">Recommendations</a></div>
    <div><h4>Company</h4><a href="<?= h($base) ?>/about.html">About</a><a href="<?= h($base) ?>/contact.html">Contact</a><a href="<?= h($hostUrl) ?>">List your property</a></div>
    <div><h4>Support</h4><a href="<?= h($base) ?>/help.html">Help centre</a><a href="<?= h($base) ?>/privacy.html">Privacy</a><a href="<?= h($base) ?>/terms.html">Terms</a></div>
  </div>
  <div class="hx-copy">© <?= date('Y') ?> StayExplore Kenya. Made for journeys worth remembering.</div>
</footer>

<script src="assets/js/home.js"></script>
</body>
</html>
