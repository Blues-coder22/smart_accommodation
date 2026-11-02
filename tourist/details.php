<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/onsite_experience.php';
require_once '../includes/review_extras.php';
require_once '../includes/gallery.php';
require_once '../includes/booking_calendar.php';
ensureOnsiteExperienceColumns($pdo);
ensureReviewExtraColumns($pdo);

if (session_status() === PHP_SESSION_NONE) session_start();

publishDueReviews($pdo);
$accommodation_id = (int)($_GET['id'] ?? 0);

if (!$accommodation_id) {
    redirect('../tourist/search.php?type=accommodation');
}

// Get accommodation details
$stmt = $pdo->prepare("
    SELECT a.*, u.full_name AS host_name, 
           u.phone_number AS host_phone,
           u.profile_photo AS host_photo,
           COALESCE(AVG(r.rating), 0) AS avg_rating,
           COUNT(DISTINCT r.review_id) AS total_reviews
    FROM accommodations a
    JOIN users u ON u.user_id = a.host_id
    LEFT JOIN reviews r 
        ON r.accommodation_id = a.accommodation_id 
        AND r.is_published = 1
    WHERE a.accommodation_id = ? 
    AND a.status = 'approved'
    GROUP BY a.accommodation_id
");
$stmt->execute([$accommodation_id]);
$acc = $stmt->fetch();

if (!$acc) {
    redirect('../tourist/search.php?type=accommodation');
}

// Get photos
$photos = $pdo->prepare("
    SELECT * FROM accommodation_photos 
    WHERE accommodation_id = ? 
    ORDER BY is_primary DESC
");
$photos->execute([$accommodation_id]);
$photos = $photos->fetchAll();

// Photo paths may be stored as a filename or as an uploads/accommodations path.
function detailAccommodationPhotoUrl($storedPath) {
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') return '../StayExplore_Kenya_Authenticated_Frontend/assets/user-house.jpg';
    if (preg_match('~^https?://~i', $storedPath)) return $storedPath;
    if (strpos($storedPath, '/') === 0) return $storedPath;
    return '../uploads/accommodations/' . rawurlencode(basename(str_replace('\\', '/', $storedPath)));
}


// Get amenities
$amenities = $pdo->prepare("
    SELECT amenity_name FROM amenities 
    WHERE accommodation_id = ?
");
$amenities->execute([$accommodation_id]);
$amenities = $amenities->fetchAll(PDO::FETCH_COLUMN);

// Get house rules
$rules = $pdo->prepare("
    SELECT rule_description FROM house_rules 
    WHERE accommodation_id = ?
");
$rules->execute([$accommodation_id]);
$rules = $rules->fetchAll(PDO::FETCH_COLUMN);

// Get published reviews
$reviews = $pdo->prepare("
    SELECT r.*, u.full_name AS reviewer_name, u.profile_photo,
           (SELECT COUNT(*) FROM reviews rc WHERE rc.tourist_id = r.tourist_id AND rc.is_published = 1) AS reviewer_reviews
    FROM reviews r
    JOIN users u ON u.user_id = r.tourist_id
    WHERE r.accommodation_id = ? 
    AND r.is_published = 1
    ORDER BY r.created_at DESC
");
$reviews->execute([$accommodation_id]);
$reviews = $reviews->fetchAll();

// Get booked dates for availability calendar
$booked = $pdo->prepare("
    SELECT check_in_date, check_out_date 
    FROM bookings
    WHERE accommodation_id = ? 
    AND status IN ('pending','confirmed')
");
$booked->execute([$accommodation_id]);
$booked_dates = $booked->fetchAll();

// Nights the host has blocked count as unavailable too (each blocked night is a 1-night range).
$blocked = $pdo->prepare("
    SELECT `date` FROM availability
    WHERE accommodation_id = ? AND is_available = 0 AND `date` >= CURDATE()
");
$blocked->execute([$accommodation_id]);
foreach ($blocked->fetchAll(PDO::FETCH_COLUMN) as $bd) {
    $booked_dates[] = [
        'check_in_date'  => $bd,
        'check_out_date' => date('Y-m-d', strtotime($bd . ' +1 day')),
    ];
}

// Get nearby attractions
$nearby = getNearbyAttractions(
    $acc['latitude'], 
    $acc['longitude'], 
    10
);

// Check if tourist has saved this to favourites
$is_favourite = false;
if (isLoggedIn() && hasRole('tourist')) {
    $fav = $pdo->prepare("
        SELECT favourite_id FROM favourites
        WHERE tourist_id = ? 
        AND item_type = 'accommodation' 
        AND item_id = ?
    ");
    $fav->execute([$_SESSION['user_id'], $accommodation_id]);
    $is_favourite = (bool)$fav->fetch();
}

// Handle save/unsave favourite
if ($_SERVER['REQUEST_METHOD'] === 'POST' 
    && isset($_POST['toggle_favourite'])) {
    requireCsrf();
    requireLogin();
    if ($is_favourite) {
        $pdo->prepare("
            DELETE FROM favourites 
            WHERE tourist_id = ? 
            AND item_type = 'accommodation' 
            AND item_id = ?
        ")->execute([$_SESSION['user_id'], $accommodation_id]);
        $is_favourite = false;
    } else {
        $pdo->prepare("
            INSERT INTO favourites 
            (tourist_id, item_type, item_id) 
            VALUES (?, 'accommodation', ?)
        ")->execute([$_SESSION['user_id'], $accommodation_id]);
        $is_favourite = true;
    }
}

// Two-letter initials for amenities (same idea as the frontend design, no emoji ticks).
function amenityInitials($name) {
    $map = ['wi-fi'=>'Wi','wifi'=>'Wi','breakfast'=>'Bk','free parking'=>'Pk','parking'=>'Pk','air conditioning'=>'AC',
            'smart tv'=>'TV','tv'=>'TV','24/7 support'=>'24','garden'=>'Gd','fireplace'=>'Fp','sea view'=>'Sv',
            'guided game drives'=>'GD','pool'=>'Pl','swimming pool'=>'Pl','kitchen'=>'Kt','security'=>'Sc'];
    $key = strtolower(trim((string)$name));
    if (isset($map[$key])) return $map[$key];
    $clean = preg_replace('/[^A-Za-z0-9]/', '', (string)$name);
    return $clean !== '' ? ucfirst(substr($clean, 0, 2)) : '•';
}
function starString($r) {
    $full = (int)round($r);
    return str_repeat('★', max(0, min(5, $full))) . str_repeat('☆', 5 - max(0, min(5, $full)));
}
$avg = (float)$acc['avg_rating'];
// Real category averages from guest reviews (cleanliness, location, comfort, value)
$catAvg = [];
try {
    $cq = $pdo->prepare("SELECT AVG(cleanliness_rating) AS cleanliness_rating, AVG(location_rating) AS location_rating,
                                AVG(comfort_rating) AS comfort_rating, AVG(value_rating) AS value_rating
                         FROM reviews WHERE accommodation_id = ? AND is_published = 1");
    $cq->execute([$accommodation_id]);
    $catAvg = $cq->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $catAvg = []; }
// Use the real category averages; if older reviews have none, fall back to the overall score.
$rating_bars = [];
foreach (reviewCategoryLabels() as $col => $n) {
    $real = isset($catAvg[$col]) && $catAvg[$col] !== null ? (float)$catAvg[$col] : null;
    $rating_bars[] = [$n, $real !== null ? min(5, max(1, $real)) : min(5, max(1, $avg))];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($acc['title']) ?> — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .details-layout { max-width: 1100px; margin: 0 auto;
                          padding: 24px; }
        .details-gallery { margin-bottom: 24px; }
        .photo-gallery { display: grid;
                         grid-template-columns: minmax(0, 1.65fr) repeat(2, minmax(0, 1fr));
                         grid-template-rows: repeat(2, minmax(0, 1fr));
                         gap: 10px; border-radius: 16px;
                         overflow: hidden; margin-bottom: 30px;
                         height: clamp(300px, 38vw, 470px);
                         background: #f0eee7; }
        .photo-gallery img { display:block; width: 100%; height: 100%;
                             min-width:0; min-height:0; object-fit: contain;
                             background:#f0eee7; cursor: zoom-in;
                             transition: transform .35s ease, filter .35s ease; }
        .photo-gallery img:hover { transform: scale(1.025); filter: saturate(1.06); }
        .photo-gallery .main-photo { grid-row: span 2; }
        .details-grid { display: grid;
                        grid-template-columns: 1fr 340px;
                        gap: 32px; }
        .details-main h1 { font-size: 26px; font-weight: 700;
                           color: #085041; margin-bottom: 6px; }
        .details-meta { display: flex; gap: 16px; align-items: center;
                        font-size: 14px; color: #666;
                        margin-bottom: 16px; }
        .rating-big { font-size: 18px; font-weight: 700;
                      color: #085041; }
        .host-info { display: flex; align-items: center; gap: 12px;
                     padding: 16px; background: #f9f9f9;
                     border-radius: 10px; margin-bottom: 20px; }
        .host-avatar { width: 48px; height: 48px; border-radius: 50%;
                       background: #1D9E75; display: flex;
                       align-items: center; justify-content: center;
                       color: white; font-size: 18px; font-weight: 700;
                       overflow: hidden; }
        .host-avatar img { width: 100%; height: 100%;
                           object-fit: cover; }
        .section-title { font-size: 18px; font-weight: 700;
                         color: #085041; margin: 24px 0 12px; }
        .amenities-grid { display: grid;
                          grid-template-columns: repeat(
                              auto-fill, minmax(140px,1fr));
                          gap: 8px; }
        .amenity-item { display: flex; align-items: center; gap: 8px;
                        font-size: 13px; color: #444;
                        padding: 8px 12px; background: #f5f5f5;
                        border-radius: 6px; }
        .rules-list { list-style: none; }
        .rules-list li { padding: 8px 0; border-bottom: 1px solid #eee;
                         font-size: 14px; color: #555;
                         display: flex; gap: 8px; }
        .rules-list li::before { content: "•"; color: #1D9E75; }
        #map { height: 280px; border-radius: 10px;
               border: 1px solid #ddd; margin-bottom: 20px; }
        .booking-card { background: white; border-radius: 12px;
                        padding: 24px;
                        box-shadow: 0 2px 16px rgba(0,0,0,0.1);
                        position: sticky; top: 80px; }
        .booking-price { font-size: 24px; font-weight: 700;
                         color: #085041; margin-bottom: 16px; }
        .booking-price span { font-size: 14px; font-weight: 400;
                              color: #666; }
        .booking-form .form-group { margin-bottom: 12px; }
        .price-breakdown { border-top: 1px solid #eee;
                           padding-top: 12px; margin-top: 12px; }
        .price-row { display: flex; justify-content: space-between;
                     font-size: 14px; margin-bottom: 6px; }
        .price-total { font-weight: 700; font-size: 15px;
                       border-top: 1px solid #eee; padding-top: 8px;
                       margin-top: 8px; }
        .review-card { padding: 16px; background: #f9f9f9;
                       border-radius: 10px; margin-bottom: 12px; }
        .review-header { display: flex; justify-content: space-between;
                         align-items: center; margin-bottom: 8px; }
        .review-author { font-size: 14px; font-weight: 600;
                         color: #085041; }
        .review-date { font-size: 12px; color: #aaa; }
        .review-stars { color: #f39c12; margin-bottom: 6px; }
        .review-text { font-size: 14px; color: #555; line-height: 1.5; }
        .host-reply { background: #e8f8f3; border-left: 3px solid #1D9E75;
                      padding: 10px 12px; margin-top: 10px;
                      border-radius: 0 6px 6px 0; font-size: 13px;
                      color: #085041; }
        .no-photos { height: 360px; background: #e8f8f3; display: flex;
                     align-items: center; justify-content: center;
                     font-size: 60px; border-radius: 12px;
                     margin-bottom: 24px; }
        .nearby-attraction { display: flex; align-items: center;
                             gap: 12px; padding: 10px 0;
                             border-bottom: 1px solid #eee;
                             font-size: 14px; }
        .nearby-icon { font-size: 20px; }
        .nearby-info h4 { font-size: 14px; font-weight: 600;
                          color: #085041; margin-bottom: 2px; }
        .nearby-info p { font-size: 12px; color: #888; }
        .details-meta .stars-under { color:#c0623a; letter-spacing:2px; font-size:17px; }
        .rating-summary h2 { font-size:34px; margin:0; color:#0d3b2e; }
        .rating-bars { display:grid; gap:11px; margin-top:16px; }
        .rating-row { display:grid; grid-template-columns:92px minmax(0,1fr) 28px; align-items:center; gap:10px; font-size:12px; }
        .rating-row > span { color:#6b6f68; }
        .rating-row > strong { text-align:right; font-size:12px; }
        .rating-track { height:7px; background:#e8e5dc; border-radius:99px; overflow:hidden; }
        .rating-track span { display:block; height:100%; background:#c0623a; border-radius:99px; }
        .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-top:22px; align-items:stretch; }
        .info-card { background:#fff; border:1px solid #e4e1d8; border-radius:12px; padding:20px; }
        .info-card .eyebrow { font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#c0623a; font-weight:700; }
        .info-card h2 { font-size:22px; margin:2px 0 8px; color:#0d3b2e; }
        .amenities-grid2 { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px 16px; margin-top:12px; }
        .amenity-item2 { display:flex; align-items:center; gap:10px; min-width:0; padding:8px 0; border-bottom:1px solid #eee; }
        .amenity-icon { width:34px; height:34px; flex:0 0 34px; display:grid; place-items:center; border:1px solid #cfd8d1; border-radius:6px; color:#0d3b2e; font-size:11px; font-weight:700; }
        .amenity-item2 strong { display:block; font-size:13px; }
        .amenity-item2 small { display:block; color:#888; font-size:11px; }
        .res-price { font-size:24px; font-weight:700; color:#0d3b2e; }
        .res-price small { font-size:13px; font-weight:400; color:#666; }
        .res-dates { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:16px; }
        .res-card label { display:block; font-size:10px; letter-spacing:.1em; font-weight:700; color:#6b6f68; margin-bottom:5px; }
        .res-card input, .res-card select { width:100%; }
        .fav-link { display:block; width:100%; text-align:center; margin-top:13px; background:none; border:0; cursor:pointer; font:inherit; font-size:13px; font-weight:700; color:#0d3b2e; }
        .fav-link:hover { color:#c0623a; }
        @media (max-width: 900px) { .info-grid { grid-template-columns:1fr; } }
        @media (max-width: 768px) {
            .details-grid { grid-template-columns: 1fr; }
            .photo-gallery { grid-template-columns: 1fr; grid-template-rows: 280px; height:auto; }
            .photo-gallery img { height:280px; }
            .photo-gallery img:not(.main-photo) { display: none; }
            .booking-card { position: static; }
        }
    </style>
</head>
<body class="tourist-details">

<?php include '../includes/header.php'; ?>

<div style="max-width:1180px;margin:16px auto 0;padding:0 24px"><?= showFlash() ?></div>

<div class="details-layout">

    <!-- Back link -->
    <a href="javascript:history.back()"
       style="font-size:14px;color:#666;
              display:inline-block;margin-bottom:16px">
        ← Back to results
    </a>

    <!-- Photo slideshow (arrows, dots, swipe, thumbnails) -->
    <?php
        $galleryUrls = [];
        foreach ($photos as $photo) $galleryUrls[] = detailAccommodationPhotoUrl($photo['photo_path'] ?? '');
        if (!$galleryUrls) $galleryUrls[] = '../StayExplore_Kenya_Authenticated_Frontend/assets/user-house.jpg';
    ?>
    <div class="details-gallery">
        <?= slideshowHtml($galleryUrls, $acc['title'], 'hero') ?>
    </div>

    <div class="details-grid">

        <!-- Left Column — Details -->
        <div class="details-main">

            <!-- Title and Meta -->
            <div>
                <h1><?= h($acc['title']) ?></h1>
                <div class="details-meta" style="margin-bottom:6px">
                    <?php if ((int)$acc['total_reviews'] > 0): ?>
                        <span class="stars-under" aria-label="<?= number_format($avg,1) ?> out of 5 stars"><?= starString($avg) ?></span>
                        <span class="rating-big"><?= number_format($avg,1) ?></span>
                        <span><?= (int)$acc['total_reviews'] ?> review<?= $acc['total_reviews'] != 1 ? 's' : '' ?></span>
                    <?php else: ?>
                        <span class="rating-big" style="font-size:14px">New listing</span>
                    <?php endif; ?>
                </div>
                <div class="details-meta">
                    <span><?= h($acc['location_name']) ?></span>
                    <span>Up to <?= (int)$acc['max_guests'] ?> guests</span>
                    <span><?= h($acc['property_type']) ?></span>
                </div>
            </div>

            <!-- Host Info -->
            <div class="host-info">
                <div class="host-avatar">
                    <?php if ($acc['host_photo']): ?>
                        <img src="<?= h(profilePhotoUrl($acc['host_id'], $acc['host_photo'])) ?>"
                             alt="Host">
                    <?php else: ?>
                        <?= h(strtoupper(substr($acc['host_name'], 0, 1))) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-weight:600;font-size:14px">
                        Hosted by <?= h($acc['host_name']) ?>
                        <?php if (!empty(verifiedHostIds($pdo, [$acc['host_id']]))) echo hostVerifiedBadge(); ?>
                    </div>
                    <div style="font-size:12px;color:#888">
                        <?= h($acc['host_phone'] ?? '') ?>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <h2 class="section-title">About this place</h2>
            <p style="font-size:14px;color:#555;line-height:1.7">
                <?= nl2br(h($acc['description'])) ?>
            </p>

            <!-- Guest rating bars + Amenities (frontend layout) -->
            <section class="info-grid" aria-label="Stay ratings and amenities">
                <div class="info-card rating-summary">
                    <?php if ((int)$acc['total_reviews'] > 0): ?>
                        <div class="eyebrow">Guest rating</div>
                        <h2><?= number_format($avg,1) ?></h2>
                        <p style="font-size:12px;color:#666;margin:0"><?= $avg >= 4.7 ? 'Excellent' : 'Very good' ?> · <?= (int)$acc['total_reviews'] ?> verified review<?= $acc['total_reviews'] != 1 ? 's' : '' ?></p>
                        <div class="rating-bars">
                            <?php foreach ($rating_bars as [$n, $v]): ?>
                            <div class="rating-row"><span><?= h($n) ?></span><div class="rating-track"><span style="width:<?= round($v / 5 * 100) ?>%"></span></div><strong><?= number_format($v,1) ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="eyebrow">Guest rating</div>
                        <h2>New</h2>
                        <p style="font-size:13px;color:#666">This is a new listing. Verified guest reviews will appear after the first stays.</p>
                    <?php endif; ?>
                </div>
                <div class="info-card">
                    <div class="eyebrow">What this stay offers</div>
                    <h2>Amenities</h2>
                    <?php if (!empty($amenities)): ?>
                    <div class="amenities-grid2">
                        <?php foreach ($amenities as $amenity): ?>
                        <div class="amenity-item2"><span class="amenity-icon"><?= h(amenityInitials($amenity)) ?></span><div><strong><?= h($amenity) ?></strong><small>Included</small></div></div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                        <p style="font-size:13px;color:#666">The host has not listed amenities yet.</p>
                    <?php endif; ?>
                </div>
            </section>

            <?php $onsiteName = trim((string)($acc['onsite_experience_name'] ?? '')); $onsiteFee = (float)($acc['onsite_experience_fee'] ?? 0); $hasOnsite = ($onsiteName !== '' && $onsiteFee > 0); ?>
            <?php if ($hasOnsite): ?>
            <div class="info-card" style="margin-top:20px">
                <div class="eyebrow">On the property</div>
                <h2><?= h($onsiteName) ?></h2>
                <p style="font-size:14px;color:#555;line-height:1.6;margin:0">This is offered by your host, right here at the stay. It costs KES <?= number_format($onsiteFee) ?> per guest and is added to your total when you book.</p>
            </div>
            <?php endif; ?>

            <!-- Availability calendar -->
            <?php $calStates = calendarNightStates($pdo, $accommodation_id, date('Y-m-d'), date('Y-m-d', strtotime('+6 months'))); ?>
            <div class="info-card" style="margin-top:20px">
                <div class="eyebrow">Availability</div>
                <h2>When this stay is free</h2>
                <?= bookingCalendarHtml($calStates, 6, false) ?>
            </div>

            <!-- House Rules -->
            <?php if (!empty($rules)): ?>
            <h2 class="section-title">House Rules</h2>
            <ul class="rules-list">
                <?php foreach ($rules as $rule): ?>
                <li><?= h($rule) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <!-- Map -->
            <h2 class="section-title">Location</h2>
            <div id="map"></div>
            <div class="osm-nearby-controls">
                <button type="button" id="osmDetailsPlaces">Show nearby places</button>
                <span class="osm-nearby-status" id="osmDetailsStatus" role="status" aria-live="polite">Green pin: this stay. Terracotta pins: attractions nearby.</span>
            </div>

            <!-- Nearby Attractions -->
            <?php if (!empty($nearby)): ?>
            <h2 class="section-title">Nearby Attractions</h2>
            <p style="font-size:12px;color:#68776f;line-height:1.5;margin:0 0 10px">Entry fees are set by the park or site (often the government) and paid at the gate. They are only a guide to what you may pay on the day, and they are never added to your booking.</p>
            <?php foreach (array_slice($nearby, 0, 5) as $att): ?>
            <div class="nearby-attraction">
                <div class="nearby-icon" style="width:8px;height:8px;border-radius:50%;background:#c0623a"></div>
                <div class="nearby-info">
                    <h4><?= h($att['name']) ?></h4>
                    <p>
                        <?= h($att['category']) ?>
                        &nbsp;·&nbsp;
                        <?= number_format($att['distance'], 1) ?> km away
                    </p>
                    <a href="attraction.php?id=<?= (int)$att['attraction_id'] ?>">View attraction</a>
                    &nbsp;&middot;&nbsp;
                    <a href="search.php?type=accommodation&attraction_id=<?= (int)$att['attraction_id'] ?>">Other stays nearby</a>
                </div>
            </div>
            <?php endforeach; ?>
            <p style="margin-top:12px"><a class="btn btn-outline" href="search.php?type=attraction&stay_id=<?= (int)$accommodation_id ?>">View all <?= count($nearby) ?> attraction<?= count($nearby) === 1 ? '' : 's' ?> near this stay &rarr;</a>
               &nbsp; <a href="search.php?type=attraction" style="font-size:13px">Show all attractions</a></p>
            <?php else: ?>
            <h2 class="section-title">Nearby Attractions</h2>
            <p style="font-size:13px;color:#666">No listed attractions close by yet. <a href="search.php?type=attraction&stay_id=<?= (int)$accommodation_id ?>&radius=500">See the closest ones</a>.</p>
            <?php endif; ?>

            <!-- Reviews -->
            <h2 class="section-title">
                Guest Reviews
                <?php if ($acc['total_reviews'] > 0): ?>
                <span style="font-size:14px;font-weight:400;color:#666">
                    (<?= $acc['total_reviews'] ?>)
                </span>
                <?php endif; ?>
            </h2>

            <?php if (empty($reviews)): ?>
                <p style="color:#888;font-size:14px">
                    No reviews yet — be the first to review after your stay.
                </p>
            <?php else: ?>
                <?php foreach ($reviews as $review): ?>
                <div class="review-card">
                    <div class="review-header">
                        <div class="review-author">
                            <?= personChipHtml($review['tourist_id'], $review['reviewer_name'], $review['profile_photo'], 40, 'Verified stay') ?>
                        </div>
                        <div class="review-date">
                            <?= date('M Y', 
                                strtotime($review['created_at'])) ?>
                        </div>
                    </div>
                    <div class="review-stars">
                        <?= str_repeat('★', $review['rating']) ?>
                    </div>
                    <?= categoryBarsHtml($review) ?>
                    <div class="review-text">
                        <?= nl2br(h($review['review_text'])) ?>
                    </div>
                    <?php if ($review['host_reply'] && empty($review['host_reply_hidden'])): ?>
                    <div class="host-reply">
                        <strong>Host response:</strong><br>
                        <?= nl2br(h($review['host_reply'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Report Listing -->
            <?php if (isLoggedIn() && hasRole('tourist')): ?>
            <div style="margin-top:24px;padding-top:16px;
                        border-top:1px solid #eee">
                <a href="../contact.html"
                   style="font-size:13px;color:#e74c3c">
                    Report this listing
                </a>
            </div>
            <?php endif; ?>

        </div>

        <!-- Right Column — Booking Card -->
        <div>
            <div class="booking-card res-card">
                <div class="res-price">KES <?= number_format($acc['price_per_night']) ?> <small>/ night</small></div>

                <?php if (isLoggedIn() && hasRole('tourist')): ?>
                <form class="booking-form" method="GET" action="book.php">
                    <input type="hidden" name="accommodation_id" value="<?= $accommodation_id ?>">

                    <div class="res-dates">
                        <div>
                            <label for="check_in">CHECK IN</label>
                            <input type="date" name="check_in" min="<?= date('Y-m-d') ?>" id="check_in" required>
                        </div>
                        <div>
                            <label for="check_out">CHECK OUT</label>
                            <input type="date" name="check_out" min="<?= date('Y-m-d') ?>" id="check_out" required>
                        </div>
                    </div>
                    <div style="margin-top:14px">
                        <label for="guests">GUESTS</label>
                        <select name="guests" id="guests">
                            <?php for ($i=1; $i<=$acc['max_guests']; $i++): ?>
                            <option value="<?= $i ?>"><?= $i ?> guest<?= $i>1?'s':'' ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="price-breakdown" id="price-breakdown" style="display:none">
                        <div class="price-row">
                            <span id="nights-label">KES <?= number_format($acc['price_per_night'])?> × <span id="nights-count">0</span> nights</span>
                            <span id="subtotal">KES 0</span>
                        </div>
                        <?php if ($hasOnsite): ?>
                        <div class="price-row"><span><?= h($onsiteName) ?> (KES <?= number_format($onsiteFee) ?> per guest)</span><span id="onsite-amount">KES 0</span></div>
                        <?php endif; ?>
                        <div class="price-row price-total"><span>Total</span><span id="total-amount">KES 0</span></div>
                    </div>

                    <p id="bookedWarn" style="display:none;margin:12px 0 0;padding:8px 10px;background:#fdecea;color:#8a2b22;border-radius:8px;font-size:12px">Some of these nights are already booked. Please pick other dates (the shaded days in the calendar are taken).</p>
                    <button type="submit" class="btn btn-primary btn-full" style="margin-top:16px">Reserve</button>
                </form>

                <a class="btn btn-outline btn-full" style="display:block;text-align:center;margin-top:10px" href="../messages.php?accommodation_id=<?= (int)$accommodation_id ?>">Message host</a>

                <form method="POST" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="toggle_favourite" value="1">
                    <button type="submit" class="fav-link"><?= $is_favourite ? '♥ Saved to favourites (remove)' : '♡ Save to favourites' ?></button>
                </form>

                <?php elseif (isLoggedIn() && hasRole('host')): ?>
                <div class="alert alert-warning">
                    <p>Hosts cannot make bookings. 
                       Please use a tourist account.</p>
                </div>

                <?php else: ?>
                <a href="../login.php"
                   class="btn btn-primary btn-full"
                   style="display:block;text-align:center">
                    Sign in to Book
                </a>
                <p style="text-align:center;font-size:12px;
                           color:#888;margin-top:8px">
                    Don't have an account? 
                    <a href="../register.php">Sign up</a>
                </p>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<?php include '../includes/footer.php'; ?>

<!-- Map -->
<script src="../assets/js/osm-nearby.js"></script>
<script src="../assets/js/map-pins.js"></script>
<script src="../assets/js/directions.js"></script>
<script>
const map = L.map('map').setView(
    [<?= $acc['latitude'] ?>, <?= $acc['longitude'] ?>], 14
);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);
window.enableDirections(map, { destination: { lat: <?= (float)$acc['latitude'] ?>, lng: <?= (float)$acc['longitude'] ?>, name: <?= json_encode(html_entity_decode((string)$acc['title'], ENT_QUOTES, 'UTF-8'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?> } });
window.enableOsmNearbyPlaces(map, { buttonId: 'osmDetailsPlaces', statusId: 'osmDetailsStatus', radius: 2000, latitude: <?= (float)$acc['latitude'] ?>, longitude: <?= (float)$acc['longitude'] ?> });

// Property location pin
SEPins.legend(map, ['stay', 'attraction', 'place']);
L.marker([<?= $acc['latitude'] ?>, <?= $acc['longitude'] ?>], { icon: SEPins.stay() })
 .addTo(map)
 .bindPopup('<b><?= h($acc['title']) ?></b>')
 .openPopup();

// Nearby attraction pins
<?php foreach ($nearby as $att): ?>
<?php if ($att['latitude'] && $att['longitude']): ?>
L.marker(
    [<?= $att['latitude'] ?>, <?= $att['longitude'] ?>],
    { icon: SEPins.attraction() }
).addTo(map).bindPopup(`
    <b><?= h($att['name']) ?></b><br>
    <?= number_format($att['distance'], 1) ?> km away
`);
<?php endif; ?>
<?php endforeach; ?>

</script>

<!-- Price Calculator -->
<script>
const pricePerNight = <?= (float)$acc['price_per_night'] ?>;
const bookedNights = <?= json_encode(array_keys($calStates)) ?>;
const onsiteFee = <?= $hasOnsite ? $onsiteFee : 0 ?>;

document.getElementById('check_in').addEventListener('change', calcPrice);
document.getElementById('check_out').addEventListener('change', calcPrice);
const guestsSel = document.querySelector('select[name="guests"]');
if (guestsSel) guestsSel.addEventListener('change', calcPrice);

function rangeHasBookedNight(ci, co) {
    const set = new Set(bookedNights);
    for (let d = new Date(ci); d < new Date(co); d.setDate(d.getDate() + 1)) {
        if (set.has(d.toISOString().slice(0, 10))) return true;
    }
    return false;
}

function calcPrice() {
    const checkIn  = new Date(document.getElementById('check_in').value);
    const checkOut = new Date(document.getElementById('check_out').value);

    if (checkIn && checkOut && checkOut > checkIn) {
        const nights = Math.round(
            (checkOut - checkIn) / (1000 * 60 * 60 * 24)
        );
        const subtotal = nights * pricePerNight;

        document.getElementById('nights-count').textContent = nights;
        document.getElementById('subtotal').textContent =
            'KES ' + subtotal.toLocaleString();
        const guestsEl = document.querySelector('select[name="guests"]');
        const guestCount = guestsEl ? parseInt(guestsEl.value, 10) || 1 : 1;
        const onsiteTotal = onsiteFee * guestCount;
        const onsiteEl = document.getElementById('onsite-amount');
        if (onsiteEl) onsiteEl.textContent = 'KES ' + onsiteTotal.toLocaleString();
        document.getElementById('total-amount').textContent =
            'KES ' + (subtotal + onsiteTotal).toLocaleString();
        document.getElementById('price-breakdown').style.display = 'block';
        const clash = rangeHasBookedNight(checkIn, checkOut);
        const warn = document.getElementById('bookedWarn');
        if (warn) warn.style.display = clash ? 'block' : 'none';
        const reserveBtn = document.querySelector('.booking-form button[type="submit"]');
        if (reserveBtn) reserveBtn.disabled = clash;

        // Set check_out minimum to day after check_in
        document.getElementById('check_out').min =
            document.getElementById('check_in').value;
    }
}
</script>

<!-- Highlight booked dates -->
<script>
// Booked date ranges from database
const bookedRanges = <?= json_encode(array_map(function($b) {
    return [
        'start' => $b['check_in_date'],
        'end'   => $b['check_out_date']
    ];
}, $booked_dates)) ?>;

// Prevent selecting booked check-in dates
document.getElementById('check_in').addEventListener('change', function() {
    const selected = this.value;
    const isBooked = bookedRanges.some(range =>
        selected >= range.start && selected < range.end
    );
    if (isBooked) {
        alert('This date is already booked. Please select another date.');
        this.value = '';
    }
});
</script>

<script src="../assets/js/slideshow.js"></script>
<script src="../assets/js/booking-calendar.js"></script>
</body>
</html>