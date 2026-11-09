<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/attraction_content.php';
require_once __DIR__ . '/../includes/gallery.php';
require_once __DIR__ . '/../includes/commons_photos.php';
require_once __DIR__ . '/../includes/attraction_reviews.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$stmt = $pdo->prepare('SELECT * FROM attractions WHERE attraction_id = ?');
$stmt->execute([$id]);
$attraction = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$attraction) {
    http_response_code(404);
}

$hasCoords = $attraction && $attraction['latitude'] !== null && $attraction['longitude'] !== null;

// Save / unsave this attraction (tourists only)
$is_favourite = false;
$canSave = $attraction && isLoggedIn() && hasRole('tourist');
if ($canSave) {
    $fav = $pdo->prepare("SELECT favourite_id FROM favourites WHERE tourist_id = ? AND item_type = 'attraction' AND item_id = ?");
    $fav->execute([$_SESSION['user_id'], $id]);
    $is_favourite = (bool)$fav->fetch();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_favourite'])) {
        requireCsrf();
        if ($is_favourite) {
            $pdo->prepare("DELETE FROM favourites WHERE tourist_id = ? AND item_type = 'attraction' AND item_id = ?")
                ->execute([$_SESSION['user_id'], $id]);
        } else {
            $pdo->prepare("INSERT INTO favourites (tourist_id, item_type, item_id) VALUES (?, 'attraction', ?)")
                ->execute([$_SESSION['user_id'], $id]);
        }
        header('Location: attraction.php?id=' . $id);
        exit;
    }
}

$nearby = [];
if ($hasCoords) {
    $nearStmt = $pdo->prepare("SELECT a.*,
        (SELECT p.photo_path FROM accommodation_photos p WHERE p.accommodation_id = a.accommodation_id ORDER BY p.is_primary DESC, p.photo_id ASC LIMIT 1) AS primary_photo,
        " . haversineSql('a.latitude', 'a.longitude', ':lat', ':lng', ':lat2') . " AS distance_km
        FROM accommodations a
        WHERE a.status = 'approved' AND a.latitude IS NOT NULL AND a.longitude IS NOT NULL
        HAVING distance_km <= :radius
        ORDER BY distance_km ASC LIMIT 6");
    // Only stays within 50 km count as 'nearby'; with few listings the 6 closest could be hundreds of km away.
    $nearStmt->execute([':lat' => $attraction['latitude'], ':lng' => $attraction['longitude'], ':lat2' => $attraction['latitude'], ':radius' => 50]);
    $nearby = $nearStmt->fetchAll(PDO::FETCH_ASSOC);
}


// ---- Ratings: only tourists with a completed stay within 50 km can rate; this never touches the Smart score ----
$ratingNotice = '';
$myRating = null; $canRate = false; $ratings = []; $ratingSummary = null;
if ($attraction) {
    ensureAttractionReviewsTable($pdo);
    $isTouristUser = isLoggedIn() && hasRole('tourist');
    if ($isTouristUser) {
        $canRate = canRateAttraction($pdo, (int)$_SESSION['user_id'], $attraction);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rate_attraction'])) {
            requireCsrf();
            $stars = (int)($_POST['rating'] ?? 0);
            if (!$canRate) {
                $ratingNotice = 'Only guests who completed a stay within ' . ATTRACTION_RATING_RADIUS_KM . ' km of this place can rate it.';
            } elseif ($stars < 1 || $stars > 5) {
                $ratingNotice = 'Please choose 1 to 5 stars.';
            } else {
                saveAttractionRating($pdo, $id, (int)$_SESSION['user_id'], $stars, $_POST['comment'] ?? '');
                header('Location: attraction.php?id=' . $id . '&rated=1#ratings');
                exit;
            }
        }
        $q = $pdo->prepare('SELECT rating, comment FROM attraction_reviews WHERE attraction_id = ? AND tourist_id = ?');
        $q->execute([$id, $_SESSION['user_id']]);
        $myRating = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (isset($_GET['rated'])) $ratingNotice = 'Thank you, your rating is saved.';
    $ratingSummary = attractionRatingsMap($pdo, [$id])[$id] ?? null;
    $rq = $pdo->prepare("SELECT r.rating, r.comment, r.created_at, u.full_name FROM attraction_reviews r
                         JOIN users u ON u.user_id = r.tourist_id WHERE r.attraction_id = ? AND r.is_hidden = 0
                         ORDER BY r.created_at DESC LIMIT 10");
    $rq->execute([$id]);
    $ratings = $rq->fetchAll(PDO::FETCH_ASSOC);
}

$story = $attraction ? buildAttractionStory($pdo, $attraction, $nearby) : null;
$title = $attraction['name'] ?? 'Attraction not found';

function stayPhotoUrl($stored) {
    $stored = trim((string)$stored);
    if ($stored === '') return '../StayExplore_Kenya_Authenticated_Frontend/assets/user-house.jpg';
    if (preg_match('~^https?://~i', $stored) || strpos($stored, '/') === 0) return $stored;
    return '../uploads/accommodations/' . rawurlencode(basename(str_replace('\\', '/', $stored)));
}
$monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($title) ?> — StayExplore Kenya</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="../assets/css/attractions.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<main class="att-page">
<?php if (!$attraction): ?>
  <section class="card pad"><div class="eyebrow">Destination unavailable</div><h1>We couldn't find that attraction.</h1><p class="small">It may have been removed or the link may be incorrect.</p><a class="btn" href="search.php?type=attraction">Explore attractions</a></section>
<?php else: ?>
  <a class="att-back" href="search.php?type=attraction">← Back to attractions</a>

  <!-- Hero -->
  <header class="att-hero">
    <?php
        $heroUrls = array_map('attractionPhotoUrl', getAttractionPhotoNames($pdo, (int)$attraction['attraction_id'], $attraction['photo_path']));
        if (!$heroUrls) $heroUrls[] = attractionPhotoUrl('');
        echo str_replace('data-slider', 'data-slider data-autoplay="6000"', slideshowHtml($heroUrls, $attraction['name'], 'card'));
    ?>
    <div class="att-emoji" aria-hidden="true"><?= attractionEmoji($attraction['category']) ?></div>
    <div class="att-hero-body">
      <div class="eyebrow" style="color:#ffd9a8"><?= h($attraction['category'] ?: 'Attraction') ?> · <?= h($attraction['location_name'] ?: 'Kenya') ?></div>
      <h1><?= h($attraction['name']) ?></h1>
      <?php if ($story['tagline'] !== ''): ?><p class="att-tagline"><?= h($story['tagline']) ?></p><?php endif; ?>
      <div class="att-badges">
        <?php if ($story['in_season'] === true): ?><span class="att-badge season">In season now</span><?php endif; ?>
        <?php if ($story['best_time'] !== ''): ?><span class="att-badge"><?= h($story['best_time']) ?></span><?php endif; ?>
        <a class="att-badge" href="#ratings" style="text-decoration:none"><?= h(attractionRatingLabel($ratingSummary)) ?></a>
      </div>
      <div class="att-actions">
        <?php if ($hasCoords): ?><a class="att-btn" href="search.php?type=accommodation&amp;attraction_id=<?= (int)$attraction['attraction_id'] ?>">Find stays nearby</a><?php endif; ?>
        <?php if ($hasCoords): ?><a class="att-btn ghost" href="#getThere">Get directions</a><?php endif; ?>
        <button class="att-btn ghost" type="button" data-share>↗ Share</button>
        <?php if ($canSave): ?>
          <form method="POST" style="display:contents">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
            <input type="hidden" name="toggle_favourite" value="1">
            <button class="att-btn heart<?= $is_favourite ? ' on' : ' ghost' ?>" type="submit"><?= $is_favourite ? '♥ Saved' : '♡ Save' ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <!-- Stats -->
  <section class="att-stats att-reveal" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">
    <div class="att-stat"><strong data-count="<?= count($nearby) ?>"><?= count($nearby) ?></strong><span>Stays nearby</span></div>
    <div class="att-stat">
      <?php if ($nearby): ?><strong data-count="<?= round((float)$nearby[0]['distance_km'], 1) ?>" data-decimals="1" data-suffix=" km"><?= number_format((float)$nearby[0]['distance_km'], 1) ?> km</strong>
      <?php else: ?><strong>—</strong><?php endif; ?>
      <span>Closest stay</span>
    </div>
    <div class="att-stat">
      <?php if ($story['months']): ?><strong data-count="<?= count($story['months']) ?>"><?= count($story['months']) ?></strong><span>Best months</span>
      <?php else: ?><strong><?= attractionEmoji($attraction['category']) ?></strong><span><?= h($attraction['category'] ?: 'Attraction') ?></span><?php endif; ?>
    </div>
  </section>
  <?= attractionCreditsHtml($pdo, getAttractionPhotoNames($pdo, (int)$attraction['attraction_id'], $attraction['photo_path'])) ?>

  <!-- Story -->
  <section class="att-section att-reveal">
    <h2>The story <small>About</small></h2>
    <div class="att-story">
      <p><?= nl2br(h($attraction['description'] ?: 'Explore this destination in Kenya.')) ?></p>
      <div class="att-facts">
        <div class="att-fact"><b>Location</b><span><?= h($attraction['location_name'] ?: 'Kenya') ?></span></div>
        <?php if ($story['terrain'] !== ''): ?><div class="att-fact"><b>Landscape</b><span><?= h($story['terrain']) ?></span></div><?php endif; ?>
        <?php if ($story['climate'] !== ''): ?><div class="att-fact"><b>Climate</b><span><?= h($story['climate']) ?></span></div><?php endif; ?>
        <?php if ($story['best_for'] !== ''): ?><div class="att-fact"><b>Best for</b><span><?= h($story['best_for']) ?></span></div><?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Best time -->
  <?php if ($story['months']): ?>
  <section class="att-section att-reveal">
    <h2>Best time to go <small><?= $story['in_season'] ? 'Now is great' : 'Plan ahead' ?></small></h2>
    <div class="att-months">
      <?php foreach ($monthNames as $i => $mn): $m = $i + 1; ?>
        <div class="att-month<?= in_array($m, $story['months'], true) ? ' best' : '' ?><?= $m === (int)date('n') ? ' now' : '' ?>" style="--i:<?= $i ?>"><?= $mn ?></div>
      <?php endforeach; ?>
    </div>
    <p class="att-legend">Green months are the best time to visit<?= $story['best_time'] !== '' ? ' — ' . h($story['best_time']) : '' ?>.</p>
  </section>
  <?php endif; ?>

  <!-- Did you know -->
  <section class="att-section att-reveal">
    <h2>Did you know? <small>Fun facts</small></h2>
    <div class="att-dyk" data-dyk>
      <?php foreach ($story['facts'] as $f): ?>
        <div class="att-dyk-slide">
          <span class="att-dyk-kind"><?= h($f['kind']) ?></span>
          <p class="att-dyk-text"><?= h($f['text']) ?></p>
        </div>
      <?php endforeach; ?>
      <div class="att-dyk-nav">
        <button type="button" data-prev>← Back</button>
        <button type="button" data-next>Another fact →</button>
        <div class="att-dots" aria-hidden="true"></div>
      </div>
      <div class="att-dyk-bar"></div>
    </div>
  </section>

  <!-- What's new -->
  <section class="att-section att-reveal">
    <h2>What’s new <small>Latest</small></h2>
    <ul class="att-news">
      <?php foreach ($story['news'] as $i => $n): ?>
        <li class="<?= $n['fresh'] ? 'fresh' : '' ?>" style="--i:<?= $i ?>">
          <span class="ico"><?= $n['icon'] ?></span>
          <div>
            <div><?php if ($n['fresh']): ?><span class="att-new-tag">NEW</span><?php endif; ?><?= h($n['text']) ?>
              <?php if (!empty($n['link'])): ?> <a href="<?= h($n['link']) ?>">View stay</a><?php endif; ?></div>
            <span class="when"><?= h($n['when']) ?></span>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- Pack list -->
  <section class="att-section att-reveal">
    <h2>Pack for the trip <small>Checklist</small></h2>
    <div class="att-pack">
      <?php foreach ($story['packing'] as $i => $p): ?><span style="--i:<?= $i ?>"><?= h($p) ?></span><?php endforeach; ?>
    </div>
  </section>

  <!-- Ratings -->
  <section class="att-section att-reveal" id="ratings">
    <h2>Visitor ratings <small><?= h(attractionRatingLabel($ratingSummary)) ?></small></h2>
    <?php if ($ratingNotice !== ''): ?><p role="status" style="background:#e6f3ec;border-radius:8px;padding:10px 14px"><?= h($ratingNotice) ?></p><?php endif; ?>
    <?php if ($canRate): ?>
      <form method="POST" action="#ratings" class="card pad" style="margin:12px 0">
        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
        <input type="hidden" name="rate_attraction" value="1">
        <fieldset style="border:0;padding:0;margin:0 0 10px"><legend><strong><?= $myRating ? 'Your rating (you can change it)' : 'How was your visit?' ?></strong></legend>
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <label style="margin-right:12px;white-space:nowrap"><input type="radio" name="rating" value="<?= $i ?>" <?= ($myRating && (int)$myRating['rating'] === $i) ? 'checked' : '' ?> required> <?= $i ?> ★</label>
          <?php endfor; ?>
        </fieldset>
        <label for="attComment">Comment (optional, up to 500 characters)</label>
        <textarea id="attComment" name="comment" maxlength="500" rows="3" style="width:100%"><?= h($myRating['comment'] ?? '') ?></textarea>
        <button class="btn" type="submit" style="margin-top:10px"><?= $myRating ? 'Update rating' : 'Submit rating' ?></button>
      </form>
    <?php elseif (isLoggedIn() && hasRole('tourist')): ?>
      <p class="small">Ratings come from guests who completed a stay within <?= (int)ATTRACTION_RATING_RADIUS_KM ?> km of this place, so they come from people who were really in the area.</p>
    <?php elseif (!isLoggedIn()): ?>
      <p class="small"><a href="../login.php">Sign in</a> to rate this place after a completed stay nearby.</p>
    <?php endif; ?>
    <?php if (!$ratings): ?>
      <p class="small">No ratings yet.</p>
    <?php else: foreach ($ratings as $r): ?>
      <div class="card pad" style="margin:10px 0">
        <strong><?= h($r['full_name']) ?></strong> <span aria-label="<?= (int)$r['rating'] ?> out of 5 stars"><?= str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']) ?></span>
        <span class="small"> · <?= h(date('j M Y', strtotime($r['created_at']))) ?></span>
        <?php if (!empty($r['comment'])): ?><p style="margin:6px 0 0"><?= nl2br(h($r['comment'])) ?></p><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>
  </section>

  <!-- Map -->
  <?php if ($hasCoords): ?>
  <section class="att-section att-reveal" id="getThere">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><h2 style="margin:0">Getting there <small>Map &amp; directions</small></h2><button type="button" class="btn btn-outline btn-sm" id="toggleAttMap" aria-expanded="true">Hide map</button></div>
    <div id="attractionMap"></div>
  </section>
  <?php endif; ?>

  <!-- Stays -->
  <section class="att-section att-reveal">
    <div class="detail-card-heading"><h2 style="margin:0;color:var(--forest)">Stay nearby</h2><a class="home-link" href="search.php?type=accommodation&amp;attraction_id=<?= (int)$attraction['attraction_id'] ?>">View all stays →</a></div>
    <?php if (!$nearby): ?>
      <div class="card pad" style="margin-top:14px"><p class="small">There are no stays listed near this attraction yet.</p><a class="home-link" href="search.php?type=accommodation&amp;attraction_id=<?= (int)$attraction['attraction_id'] ?>">Search stays around this destination</a></div>
    <?php else: ?>
      <div class="att-stays">
      <?php foreach (array_slice($nearby, 0, 6) as $stay): ?>
        <article class="att-stay">
          <div class="att-stay-photo">
            <img src="<?= h(stayPhotoUrl($stay['primary_photo'] ?? '')) ?>" alt="<?= h($stay['title']) ?>" loading="lazy">
            <span class="att-stay-km"><?= number_format((float)$stay['distance_km'], 1) ?> km away</span>
          </div>
          <div class="att-stay-body">
            <h3><?= h($stay['title']) ?></h3>
            <p class="small" style="margin:0"><?= h($stay['location_name'] ?: 'Kenya') ?></p>
            <p style="margin:6px 0 10px"><strong>KSh <?= number_format((float)$stay['price_per_night']) ?></strong> <span class="small">/ night</span></p>
            <a class="btn btn-sm" style="margin-top:auto" href="details.php?id=<?= (int)$stay['accommodation_id'] ?>">View stay</a>
          </div>
        </article>
      <?php endforeach; ?>
      </div>
      <p style="margin-top:14px"><a class="btn btn-outline" href="search.php?type=accommodation&amp;attraction_id=<?= (int)$attraction['attraction_id'] ?>">View all stays near <?= h($attraction['name']) ?> &rarr;</a>
         &nbsp; <a href="search.php?type=accommodation" style="font-size:13px">Show all stays</a></p>
    <?php endif; ?>
  </section>
<?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<?php if ($attraction): ?>
<script src="../assets/js/slideshow.js"></script>
<script src="../assets/js/attractions.js"></script>
<?php if ($hasCoords): ?>
<script src="../assets/js/map-pins.js"></script>
<script src="../assets/js/directions.js"></script>
<script>
(function () {
  var lat = <?= (float)$attraction['latitude'] ?>, lng = <?= (float)$attraction['longitude'] ?>;
  var name = <?= json_encode(html_entity_decode((string)$attraction['name'], ENT_QUOTES, 'UTF-8'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  var map = L.map('attractionMap').setView([lat, lng], 12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors' }).addTo(map);
  window.enableDirections(map, { destination: { lat: lat, lng: lng, name: name } });
  var b = document.createElement('b'); b.textContent = name;
  SEPins.legend(map, ['stay', 'attraction']);
  L.marker([lat, lng], { icon: SEPins.attraction() }).addTo(map).bindPopup(b).openPopup();
  <?php foreach ($nearby as $s): ?>
  L.marker([<?= (float)$s['latitude'] ?>, <?= (float)$s['longitude'] ?>], { icon: SEPins.stay() })
    .addTo(map).bindPopup(<?= json_encode('<b>' . h($s['title']) . '</b><br>' . number_format((float)$s['distance_km'], 1) . ' km away<br><a href="details.php?id=' . (int)$s['accommodation_id'] . '">View stay</a>', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
  <?php endforeach; ?>
  var tm = document.getElementById('toggleAttMap'), mp = document.getElementById('attractionMap');
  if (tm && mp) tm.addEventListener('click', function () {
    var hide = mp.style.display !== 'none';
    mp.style.display = hide ? 'none' : '';
    tm.textContent = hide ? 'View map' : 'Hide map';
    tm.setAttribute('aria-expanded', hide ? 'false' : 'true');
    if (!hide) setTimeout(function () { map.invalidateSize(); }, 60);
  });
  // The hero "Get directions" button jumps to the map and opens the directions panel.
  var link = document.querySelector('a[href="#getThere"]');
  if (link) link.addEventListener('click', function () { setTimeout(function () { if (map._seDirections) { map._seDirections.setDestination({ lat: lat, lng: lng }, name); } }, 400); });
})();
</script>
<?php endif; ?>
<?php endif; ?>
</body></html>
