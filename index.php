<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Get featured accommodations (top 6 by smart score)
$stmt = $pdo->prepare("
    SELECT a.*, 
    COALESCE(AVG(r.rating), 0) AS avg_rating,
    COUNT(DISTINCT b.booking_id) AS total_bookings,
    (SELECT photo_path FROM accommodation_photos 
     WHERE accommodation_id = a.accommodation_id 
     AND is_primary = 1 LIMIT 1) AS primary_photo
    FROM accommodations a
    LEFT JOIN reviews r 
        ON r.accommodation_id = a.accommodation_id 
        AND r.is_published = 1
    LEFT JOIN bookings b 
        ON b.accommodation_id = a.accommodation_id 
        AND b.status = 'completed'
    WHERE a.status = 'approved'
    GROUP BY a.accommodation_id
    ORDER BY avg_rating DESC
    LIMIT 6
");
$stmt->execute();
$featured = $stmt->fetchAll();

// Get featured attractions (top 6)
$stmt = $pdo->prepare("
    SELECT * FROM attractions 
    ORDER BY created_at DESC 
    LIMIT 6
");
$stmt->execute();
$attractions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Accommodation Kenya — Find Stays and Attractions</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Leaflet.js -->
    <link rel="stylesheet" 
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .hero { background: linear-gradient(135deg, #085041, #1D9E75);
                color: white; padding: 60px 24px; text-align: center; }
        .hero h1 { font-size: 36px; margin-bottom: 10px; }
        .hero p  { font-size: 16px; opacity: 0.9; margin-bottom: 30px; }
        .search-tabs { display: flex; justify-content: center;
                       gap: 10px; margin-bottom: 20px; }
        .tab { padding: 10px 24px; border-radius: 25px; font-size: 14px;
               cursor: pointer; border: 2px solid white;
               background: transparent; color: white; transition: 0.2s; }
        .tab.active { background: white; color: #085041; font-weight: 600; }
        .search-box { background: white; border-radius: 12px;
                      padding: 20px; max-width: 600px;
                      margin: 0 auto; box-shadow: 0 4px 20px rgba(0,0,0,0.2); }
        .search-row { display: flex; gap: 10px; }
        .search-row input,
        .search-row select { flex: 1; padding: 10px 14px;
                              border: 1px solid #ddd; border-radius: 6px;
                              font-size: 14px; }
        .search-row button { background: #1D9E75; color: white;
                              border: none; padding: 10px 20px;
                              border-radius: 6px; cursor: pointer;
                              font-size: 14px; white-space: nowrap; }
        .features { display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px,1fr));
                    gap: 20px; padding: 40px 24px;
                    max-width: 1200px; margin: 0 auto; }
        .feature-item { text-align: center; padding: 20px; }
        .feature-icon { font-size: 32px; margin-bottom: 10px; }
        .feature-item h3 { font-size: 15px; color: #085041;
                           margin-bottom: 6px; }
        .feature-item p { font-size: 13px; color: #666; }
        #map { height: 400px; margin: 0 24px 40px;
               border-radius: 12px; border: 1px solid #ddd; }
        .map-section { max-width: 1200px; margin: 0 auto; padding: 0 24px; }
        .map-section h2 { font-size: 20px; font-weight: 700;
                          color: #085041; margin-bottom: 12px; }
    </style>
</head>
<body>

<?php include 'includes/header.php'; ?>

<!-- Hero Section -->
<div class="hero">
    <h1>Discover Stays. Explore Attractions.</h1>
    <p>Find the best places to stay and amazing attractions — all in one platform.</p>

    <!-- Search Tabs -->
    <div class="search-tabs">
        <button class="tab active" onclick="switchTab('accommodation', this)">
            🏠 Find Accommodation
        </button>
        <button class="tab" onclick="switchTab('attraction', this)">
            📍 Explore Attractions
        </button>
    </div>

    <!-- Accommodation Search -->
    <div class="search-box" id="accommodation-search">
        <form method="GET" action="tourist/search.php">
            <input type="hidden" name="type" value="accommodation">
            <div class="search-row">
                <input type="text" name="location"
                       placeholder="Where are you going?"
                       value="<?= htmlspecialchars($_GET['location'] ?? '') ?>">
                <input type="date" name="check_in"
                       min="<?= date('Y-m-d') ?>">
                <input type="date" name="check_out"
                       min="<?= date('Y-m-d') ?>">
                <button type="submit">Search</button>
            </div>
        </form>
    </div>

    <!-- Attraction Search -->
    <div class="search-box" id="attraction-search" style="display:none">
        <form method="GET" action="tourist/search.php">
            <input type="hidden" name="type" value="attraction">
            <div class="search-row">
                <input type="text" name="attraction_name"
                       placeholder="Search attractions...">
                <select name="category">
                    <option value="">All Categories</option>
                    <option value="National Parks">National Parks</option>
                    <option value="Museums">Museums</option>
                    <option value="Beaches">Beaches</option>
                    <option value="Historical Sites">Historical Sites</option>
                    <option value="Adventure">Adventure</option>
                    <option value="Culture">Culture</option>
                    <option value="Restaurants">Restaurants & Eateries</option>
                    <option value="Shopping">Shopping</option>
                    <option value="Entertainment">Entertainment</option>
                    <option value="Religious sites">Religious sites</option>
                </select>
                <button type="submit">Search</button>
            </div>
        </form>
    </div>
</div>

<!-- Feature Highlights -->
<div class="features">
    <div class="feature-item">
        <div class="feature-icon">🎯</div>
        <h3>Smart Recommendations</h3>
        <p>Personalized suggestions based on location, rating, and price</p>
    </div>
    <div class="feature-item">
        <div class="feature-icon">✅</div>
        <h3>Verified and Trusted</h3>
        <p>Reviews from confirmed guests only — no fake reviews</p>
    </div>
    <div class="feature-item">
        <div class="feature-icon">💳</div>
        <h3>Secure Payments</h3>
        <p>Track payments and book with confidence</p>
    </div>
    <div class="feature-item">
        <div class="feature-icon">🏡</div>
        <h3>Support Local Hosts</h3>
        <p>Empowering local property owners across Kenya</p>
    </div>
</div>

<!-- Map Section -->
<div class="map-section">
    <h2>Explore on the Map</h2>
    <p style="font-size:14px;color:#666;margin-bottom:12px">
        Green pins = Accommodations &nbsp;|&nbsp; Purple pins = Attractions
    </p>
    <div id="map"></div>
</div>

<!-- Featured Accommodations -->
<div class="container section">
    <div style="display:flex;justify-content:space-between;align-items:center;
                margin-bottom:20px">
        <h2 class="section-title" style="margin-bottom:0">
            Featured Accommodations
        </h2>
        <a href="tourist/search.php?type=accommodation" 
           style="font-size:14px;color:#1D9E75">View all →</a>
    </div>
    <div class="grid grid-3">
        <?php foreach ($featured as $acc): ?>
        <div class="card">
            <?php if ($acc['primary_photo']): ?>
                <img class="card-img"
                     src="uploads/accommodations/<?= 
                         htmlspecialchars($acc['primary_photo']) ?>"
                     alt="<?= htmlspecialchars($acc['title']) ?>">
            <?php else: ?>
                <div class="card-img" 
                     style="background:#e8f8f3;display:flex;
                            align-items:center;justify-content:center;
                            font-size:40px">🏠</div>
            <?php endif; ?>
            <div class="card-body">
                <div class="card-title">
                    <?= htmlspecialchars($acc['title']) ?>
                </div>
                <div class="card-location">
                    📍 <?= htmlspecialchars($acc['location_name']) ?>
                </div>
                <div class="card-footer">
                    <div class="card-price">
                        KES <?= number_format($acc['price_per_night']) ?>/night
                    </div>
                    <div class="card-rating">
                        ⭐ <?= number_format($acc['avg_rating'], 1) ?>
                    </div>
                </div>
                <a href="tourist/details.php?id=<?= $acc['accommodation_id'] ?>"
                   class="btn btn-primary btn-full" 
                   style="margin-top:12px;display:block;text-align:center">
                    View Details
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Featured Attractions -->
<div style="background:#f0faf6">
    <div class="container section">
        <div style="display:flex;justify-content:space-between;
                    align-items:center;margin-bottom:20px">
            <h2 class="section-title" style="margin-bottom:0">
                Popular Attractions
            </h2>
            <a href="tourist/search.php?type=attraction"
               style="font-size:14px;color:#1D9E75">View all →</a>
        </div>
        <div class="grid grid-3">
            <?php foreach ($attractions as $att): ?>
            <div class="card">
                <?php if ($att['photo_path']): ?>
                    <img class="card-img"
                         src="uploads/attractions/<?= 
                             htmlspecialchars($att['photo_path']) ?>"
                         alt="<?= htmlspecialchars($att['name']) ?>">
                <?php else: ?>
                    <div class="card-img"
                         style="background:#eeedfe;display:flex;
                                align-items:center;justify-content:center;
                                font-size:40px">📍</div>
                <?php endif; ?>
                <div class="card-body">
                    <div class="card-title">
                        <?= htmlspecialchars($att['name']) ?>
                    </div>
                    <div class="card-location">
                        📍 <?= htmlspecialchars($att['location_name']) ?>
                        &nbsp;|&nbsp; 
                        🏷️ <?= htmlspecialchars($att['category']) ?>
                    </div>
                    <div class="card-footer">
                        <div class="card-price">
                            <?= $att['entry_fee'] > 0 
                                ? 'KES ' . number_format($att['entry_fee']) 
                                : 'Free entry' ?>
                        </div>
                    </div>
                    <a href="tourist/search.php?type=accommodation
                             &attraction_id=<?= $att['attraction_id'] ?>"
                       class="btn btn-primary btn-full"
                       style="margin-top:12px;display:block;text-align:center">
                        Find Nearby Stays
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<!-- Map Script -->
<script>
// Initialize map centered on Kenya
const map = L.map('map').setView([-1.2921, 36.8219], 7);

// OpenStreetMap tiles 
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Custom icons
const greenIcon = L.divIcon({
    className: '',
    html: '<div style="background:#1D9E75;color:white;padding:4px 8px;
                       border-radius:10px;font-size:11px;
                       font-weight:600;white-space:nowrap">🏠 Stay</div>',
    iconAnchor: [20, 10]
});

const purpleIcon = L.divIcon({
    className: '',
    html: '<div style="background:#534AB7;color:white;padding:4px 8px;
                       border-radius:10px;font-size:11px;
                       font-weight:600;white-space:nowrap">📍 Visit</div>',
    iconAnchor: [20, 10]
});

// Plot accommodations
<?php foreach ($featured as $acc): ?>
<?php if ($acc['latitude'] && $acc['longitude']): ?>
L.marker(
    [<?= $acc['latitude'] ?>, <?= $acc['longitude'] ?>],
    { icon: greenIcon }
).addTo(map).bindPopup(`
    <b><?= htmlspecialchars($acc['title']) ?></b><br>
    <?= htmlspecialchars($acc['location_name']) ?><br>
    KES <?= number_format($acc['price_per_night']) ?>/night<br>
    ⭐ <?= number_format($acc['avg_rating'], 1) ?><br>
    <a href="tourist/details.php?id=<?= $acc['accommodation_id'] ?>">
        View Details
    </a>
`);
<?php endif; ?>
<?php endforeach; ?>

// Plot attractions
<?php foreach ($attractions as $att): ?>
<?php if ($att['latitude'] && $att['longitude']): ?>
L.marker(
    [<?= $att['latitude'] ?>, <?= $att['longitude'] ?>],
    { icon: purpleIcon }
).addTo(map).bindPopup(`
    <b><?= htmlspecialchars($att['name']) ?></b><br>
    <?= htmlspecialchars($att['location_name']) ?><br>
    Category: <?= htmlspecialchars($att['category']) ?><br>
    <?= $att['entry_fee'] > 0 
        ? 'Entry: KES ' . number_format($att['entry_fee']) 
        : 'Free entry' ?><br>
    <a href="tourist/search.php?type=accommodation
             &attraction_id=<?= $att['attraction_id'] ?>">
        Find Nearby Stays
    </a>
`);
<?php endif; ?>
<?php endforeach; ?>

// Detect current location button
document.addEventListener('DOMContentLoaded', function() {
    const locBtn = L.control({ position: 'topleft' });
    locBtn.onAdd = function() {
        const btn = L.DomUtil.create('button', '');
        btn.innerHTML = '📍 Use my location';
        btn.style.cssText = 'background:white;border:1px solid #ddd;
                             padding:8px 12px;border-radius:6px;
                             cursor:pointer;font-size:13px;margin:10px';
        btn.onclick = function() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(function(pos) {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    map.setView([lat, lng], 12);
                    L.marker([lat, lng]).addTo(map)
                     .bindPopup('You are here').openPopup();
                    // Redirect to search with current location
                    window.location.href = 
                        `tourist/search.php?type=accommodation
                         &lat=${lat}&lng=${lng}`;
                });
            } else {
                alert('Geolocation is not supported by your browser');
            }
        };
        return btn;
    };
    locBtn.addTo(map);
});
</script>

<!-- Tab switching script -->
<script>
function switchTab(type, btn) {
    // Update tab styles
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');

    // Show correct search box
    document.getElementById('accommodation-search').style.display =
        type === 'accommodation' ? 'block' : 'none';
    document.getElementById('attraction-search').style.display =
        type === 'attraction' ? 'block' : 'none';
}
</script>

</body>
</html>