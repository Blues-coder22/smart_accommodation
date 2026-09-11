<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

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
    SELECT r.*, 
           SUBSTRING(u.full_name, 1, 
               LOCATE(' ', u.full_name) - 1) AS first_name,
           u.profile_photo
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($acc['title']) ?> — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .details-layout { max-width: 1100px; margin: 0 auto;
                          padding: 24px; }
        .photo-gallery { display: grid;
                         grid-template-columns: 2fr 1fr 1fr;
                         gap: 8px; border-radius: 12px;
                         overflow: hidden; margin-bottom: 24px;
                         height: 360px; }
        .photo-gallery img { width: 100%; height: 100%;
                             object-fit: cover; cursor: pointer; }
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
        @media (max-width: 768px) {
            .details-grid { grid-template-columns: 1fr; }
            .photo-gallery { grid-template-columns: 1fr; height: 240px; }
            .photo-gallery img:not(.main-photo) { display: none; }
            .booking-card { position: static; }
        }
    </style>
</head>
<body>

<?php include '../includes/header.php'; ?>

<div class="details-layout">

    <!-- Back link -->
    <a href="javascript:history.back()"
       style="font-size:14px;color:#666;
              display:inline-block;margin-bottom:16px">
        ← Back to results
    </a>

    <!-- Photo Gallery -->
    <?php if (!empty($photos)): ?>
    <div class="photo-gallery">
        <?php foreach (array_slice($photos, 0, 5) as $i => $photo): ?>
        <img src="../uploads/accommodations/
             <?= htmlspecialchars($photo['photo_path']) ?>"
             alt="<?= htmlspecialchars($acc['title']) ?>"
             class="<?= $i === 0 ? 'main-photo' : '' ?>">
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="no-photos">🏠</div>
    <?php endif; ?>

    <div class="details-grid">

        <!-- Left Column — Details -->
        <div class="details-main">

            <!-- Title and Meta -->
            <div style="display:flex;justify-content:space-between;
                        align-items:flex-start">
                <div>
                    <h1><?= htmlspecialchars($acc['title']) ?></h1>
                    <div class="details-meta">
                        <span class="rating-big">
                            ⭐ <?= number_format($acc['avg_rating'],1) ?>
                        </span>
                        <span>
                            <?= $acc['total_reviews'] ?> review
                            <?= $acc['total_reviews'] != 1 ? 's' : '' ?>
                        </span>
                        <span>📍 <?= htmlspecialchars($acc['location_name']) ?></span>
                        <span>👥 Up to <?= $acc['max_guests'] ?> guests</span>
                        <span>🏠 <?= htmlspecialchars($acc['property_type']) ?></span>
                    </div>
                </div>

                <!-- Favourite Button -->
                <?php if (isLoggedIn() && hasRole('tourist')): ?>
                <form method="POST">
                    <input type="hidden" name="toggle_favourite" value="1">
                    <button type="submit"
                            style="background:none;border:none;
                                   cursor:pointer;font-size:24px"
                            title="<?= $is_favourite 
                                       ? 'Remove from favourites' 
                                       : 'Save to favourites' ?>">
                        <?= $is_favourite ? '❤️' : '🤍' ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Host Info -->
            <div class="host-info">
                <div class="host-avatar">
                    <?php if ($acc['host_photo']): ?>
                        <img src="../uploads/profiles/
                             <?= htmlspecialchars($acc['host_photo']) ?>"
                             alt="Host">
                    <?php else: ?>
                        <?= strtoupper(substr($acc['host_name'], 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-weight:600;font-size:14px">
                        Hosted by <?= htmlspecialchars($acc['host_name']) ?>
                    </div>
                    <div style="font-size:12px;color:#888">
                        <?= htmlspecialchars($acc['host_phone'] ?? '') ?>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <h2 class="section-title">About this place</h2>
            <p style="font-size:14px;color:#555;line-height:1.7">
                <?= nl2br(htmlspecialchars($acc['description'])) ?>
            </p>

            <!-- Amenities -->
            <?php if (!empty($amenities)): ?>
            <h2 class="section-title">What this place offers</h2>
            <div class="amenities-grid">
                <?php foreach ($amenities as $amenity): ?>
                <div class="amenity-item">
                    ✅ <?= htmlspecialchars($amenity) ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- House Rules -->
            <?php if (!empty($rules)): ?>
            <h2 class="section-title">House Rules</h2>
            <ul class="rules-list">
                <?php foreach ($rules as $rule): ?>
                <li><?= htmlspecialchars($rule) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <!-- Map -->
            <h2 class="section-title">Location</h2>
            <div id="map"></div>

            <!-- Nearby Attractions -->
            <?php if (!empty($nearby)): ?>
            <h2 class="section-title">Nearby Attractions</h2>
            <?php foreach (array_slice($nearby, 0, 5) as $att): ?>
            <div class="nearby-attraction">
                <div class="nearby-icon">📍</div>
                <div class="nearby-info">
                    <h4><?= htmlspecialchars($att['name']) ?></h4>
                    <p>
                        <?= htmlspecialchars($att['category']) ?>
                        &nbsp;·&nbsp;
                        <?= number_format($att['distance'], 1) ?> km away
                        &nbsp;·&nbsp;
                        <?= $att['entry_fee'] > 0
                            ? 'KES ' . number_format($att['entry_fee'])
                            : 'Free entry' ?>
                    </p>
                </div>
            </div>
            <?php endforeach; ?>
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
                            <?= htmlspecialchars($review['first_name']) ?>
                        </div>
                        <div class="review-date">
                            <?= date('M Y', 
                                strtotime($review['created_at'])) ?>
                        </div>
                    </div>
                    <div class="review-stars">
                        <?= str_repeat('⭐', $review['rating']) ?>
                    </div>
                    <div class="review-text">
                        <?= nl2br(htmlspecialchars($review['review_text'])) ?>
                    </div>
                    <?php if ($review['host_reply']): ?>
                    <div class="host-reply">
                        <strong>Host response:</strong><br>
                        <?= nl2br(htmlspecialchars($review['host_reply'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Report Listing -->
            <?php if (isLoggedIn() && hasRole('tourist')): ?>
            <div style="margin-top:24px;padding-top:16px;
                        border-top:1px solid #eee">
                <a href="report.php?type=listing
                         &target_id=<?= $accommodation_id ?>"
                   style="font-size:13px;color:#e74c3c">
                    🚩 Report this listing
                </a>
            </div>
            <?php endif; ?>

        </div>

        <!-- Right Column — Booking Card -->
        <div>
            <div class="booking-card">
                <div class="booking-price">
                    KES <?= number_format($acc['price_per_night']) ?>
                    <span>/night</span>
                </div>

                <div style="display:flex;gap:8px;align-items:center;
                            margin-bottom:16px">
                    <span>⭐ <?= number_format($acc['avg_rating'],1) ?></span>
                    <span style="color:#aaa">·</span>
                    <span style="color:#666;font-size:13px">
                        <?= $acc['total_reviews'] ?> reviews
                    </span>
                </div>

                <?php if (isLoggedIn() && hasRole('tourist')): ?>
                <form class="booking-form" method="GET" 
                      action="book.php">
                    <input type="hidden" name="accommodation_id"
                           value="<?= $accommodation_id ?>">

                    <div class="form-group">
                        <label>Check-in</label>
                        <input type="date" name="check_in"
                               min="<?= date('Y-m-d') ?>"
                               id="check_in" required>
                    </div>
                    <div class="form-group">
                        <label>Check-out</label>
                        <input type="date" name="check_out"
                               min="<?= date('Y-m-d') ?>"
                               id="check_out" required>
                    </div>
                    <div class="form-group">
                        <label>Guests</label>
                        <select name="guests">
                            <?php for ($i=1; $i<=$acc['max_guests']; $i++): ?>
                            <option value="<?= $i ?>">
                                <?= $i ?> Guest<?= $i>1?'s':'' ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <!-- Price breakdown -->
                    <div class="price-breakdown" id="price-breakdown"
                         style="display:none">
                        <div class="price-row">
                            <span id="nights-label">
                                KES <?= number_format($acc['price_per_night'])?>
                                × <span id="nights-count">0</span> nights
                            </span>
                            <span id="subtotal">KES 0</span>
                        </div>
                        <div class="price-row price-total">
                            <span>Total</span>
                            <span id="total-amount">KES 0</span>
                        </div>
                    </div>

                    <button type="submit"
                            class="btn btn-primary btn-full"
                            style="margin-top:16px">
                        Reserve Now
                    </button>
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
<script>
const map = L.map('map').setView(
    [<?= $acc['latitude'] ?>, <?= $acc['longitude'] ?>], 14
);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Property location pin
L.marker([<?= $acc['latitude'] ?>, <?= $acc['longitude'] ?>])
 .addTo(map)
 .bindPopup('<b><?= htmlspecialchars($acc['title']) ?></b>')
 .openPopup();

// Nearby attraction pins
<?php foreach ($nearby as $att): ?>
<?php if ($att['latitude'] && $att['longitude']): ?>
L.marker(
    [<?= $att['latitude'] ?>, <?= $att['longitude'] ?>],
    { icon: L.divIcon({
        className: '',
        html: `<div style="background:#534AB7;color:white;
                           padding:4px 8px;border-radius:10px;
                           font-size:11px;font-weight:600">📍</div>`,
        iconAnchor: [15, 10]
    })}
).addTo(map).bindPopup(`
    <b><?= htmlspecialchars($att['name']) ?></b><br>
    <?= number_format($att['distance'], 1) ?> km away
`);
<?php endif; ?>
<?php endforeach; ?>
</script>

<!-- Price Calculator -->
<script>
const pricePerNight = <?= $acc['price_per_night'] ?>;

document.getElementById('check_in').addEventListener('change', calcPrice);
document.getElementById('check_out').addEventListener('change', calcPrice);

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
        document.getElementById('total-amount').textContent =
            'KES ' + subtotal.toLocaleString();
        document.getElementById('price-breakdown').style.display = 'block';

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

</body>
</html>