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
<body class="homepage">

<?php include 'includes/header.php'; ?>

<section class="hp-hero">
    <div class="hp-hero-inner">
        <span class="hp-badge">Smart recommendations for Kenya and beyond</span>
        <h1>Discover Kenya.<br>Stay. Explore.<br>Experience.</h1>
        <p>From cosy rooms in Nairobi to lodges at the foot of Mt. Kenya, find where to stay and discover nearby attractions.</p>
        <div class="actions" style="justify-content:center;margin-top:28px">
            <a class="btn btn-light" href="tourist/search.php?type=accommodation">Find a stay</a>
            <a class="btn btn-outline" style="color:#fff;border-color:rgba(255,255,255,.8)" href="tourist/search.php?type=attraction">Explore attractions</a>
        </div>
        <div class="search-tabs" style="justify-content:center;margin-top:28px">
            <button class="tab active" type="button" onclick="switchTab('accommodation', this)">Find accommodation</button>
            <button class="tab" type="button" onclick="switchTab('attraction', this)">Explore attractions</button>
        </div>
        <div class="hp-search" id="accommodation-search">
            <form method="GET" action="tourist/search.php" class="field" style="grid-column:1/-1">
                <input type="hidden" name="type" value="accommodation">
                <div class="field">
                    <label for="home-location">DESTINATION</label>
                    <input id="home-location" type="search" name="location" placeholder="Where are you going?" value="<?= htmlspecialchars($_GET['location'] ?? '') ?>">
                </div>
                <div class="grid grid-2">
                    <div class="field">
                        <label for="home-check-in">CHECK IN</label>
                        <input id="home-check-in" type="date" name="check_in" min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="field">
                        <label for="home-check-out">CHECK OUT</label>
                        <input id="home-check-out" type="date" name="check_out" min="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <button class="btn" type="submit" style="margin-top:10px">Search stays</button>
            </form>
        </div>
        <div class="hp-search" id="attraction-search" style="display:none">
            <form method="GET" action="tourist/search.php" class="field" style="grid-column:1/-1">
                <input type="hidden" name="type" value="attraction">
                <div class="field">
                    <label for="home-attraction">ATTRACTION OR DESTINATION</label>
                    <input id="home-attraction" type="search" name="location" placeholder="Search attractions...">
                </div>
                <div class="field">
                    <label for="home-category">CATEGORY</label>
                    <select id="home-category" name="category">
                        <option value="">All categories</option>
                        <option value="National Parks">National Parks</option>
                        <option value="Museums">Museums</option>
                        <option value="Beaches">Beaches</option>
                        <option value="Historical Sites">Historical Sites</option>
                        <option value="Adventure">Adventure</option>
                        <option value="Culture">Culture</option>
                        <option value="Restaurants">Restaurants &amp; Eateries</option>
                        <option value="Shopping">Shopping</option>
                        <option value="Entertainment">Entertainment</option>
                        <option value="Religious Sites">Religious sites</option>
                    </select>
                </div>
                <button class="btn" type="submit" style="margin-top:10px">Search attractions</button>
            </form>
        </div>
    </div>
</section>

<section class="hp-intro">
    <div class="hp-benefits">
        <div class="hp-benefit"><div class="icon">✧</div><h3>Smart recommendations</h3><p>Results ranked to help you choose with confidence.</p></div>
        <div class="hp-benefit"><div class="icon">◉</div><h3>Verified reviews</h3><p>Only guests with confirmed bookings can leave reviews.</p></div>
        <div class="hp-benefit"><div class="icon">◉</div><h3>Secure payments</h3><p>Track payments and book with confidence.</p></div>
        <div class="hp-benefit"><div class="icon">⌁</div><h3>Local hosts</h3><p>Supporting Kenyan property owners and communities.</p></div>
    </div>
</section>

<section class="hp-section hp-map-section">
    <div class="hp-kicker">Search anywhere in Kenya</div>
    <h2>Find a stay or an attraction on the map</h2>
    <p class="hp-caption">Green pins show stays; purple pins show attractions.</p>
    <div id="map" aria-label="Map of accommodations and attractions across Kenya"></div>
</section>

<section class="hp-section">
    <div class="hp-section-head">
        <div><div class="hp-kicker">Curated for your journey</div><h2>Featured stays</h2><p class="hp-caption">Hand-picked places to unwind, each close to something worth exploring.</p></div>
        <a class="home-link hp-viewall" href="tourist/search.php?type=accommodation">View all stays &rarr;</a>
    </div>
    <div class="hp-stay-grid">
        <?php foreach ($featured as $acc): ?>
        <article class="hp-stay-card">
            <a href="tourist/details.php?id=<?= $acc['accommodation_id'] ?>">
                <img src="<?= $acc['primary_photo'] ? 'uploads/accommodations/' . rawurlencode($acc['primary_photo']) : 'assets/user-house.jpg' ?>" alt="<?= htmlspecialchars($acc['title']) ?>">
            </a>
            <div class="copy">
                <h3><?= htmlspecialchars($acc['title']) ?></h3>
                <p><?= htmlspecialchars($acc['location_name']) ?></p>
                <div class="actions" style="justify-content:space-between">
                    <strong>KES <?= number_format($acc['price_per_night']) ?><small> / night</small></strong>
                    <span class="rating">★ <?= number_format($acc['avg_rating'], 1) ?></span>
                </div>
                <a class="home-link" href="tourist/details.php?id=<?= $acc['accommodation_id'] ?>">View stay &rarr;</a>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="hp-section" id="attractions">
    <div class="hp-section-head">
        <div><div class="hp-kicker">Explore by destination</div><h2>Popular attractions</h2><p class="hp-caption">Discover Kenya's iconic landscapes, wildlife reserves, and coastal escapes.</p></div>
        <a class="home-link hp-viewall" href="tourist/search.php?type=attraction">View all attractions &rarr;</a>
    </div>
    <div class="hp-attraction-layout">
        <?php if (!empty($attractions)): $featured_attraction = $attractions[0]; ?>
        <article class="hp-attraction-feature">
            <?php if ($featured_attraction['photo_path']): ?>
                <img src="uploads/attractions/<?= rawurlencode($featured_attraction['photo_path']) ?>" alt="<?= htmlspecialchars($featured_attraction['name']) ?>" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:9px">
            <?php endif; ?>
            <div class="copy">
                <div class="hp-kicker" style="color:#efb59e">Featured destination</div>
                <h3><?= htmlspecialchars($featured_attraction['name']) ?></h3>
                <p><?= htmlspecialchars($featured_attraction['location_name']) ?> &middot; <?= htmlspecialchars($featured_attraction['category']) ?></p>
                <a class="btn btn-light" href="tourist/search.php?type=accommodation&amp;attraction_id=<?= $featured_attraction['attraction_id'] ?>">Find nearby stays</a>
            </div>
        </article>
        <div class="hp-attraction-grid">
            <?php foreach (array_slice($attractions, 1, 4) as $att): ?>
            <article class="hp-attraction-card">
                <?php if ($att['photo_path']): ?>
                    <img src="uploads/attractions/<?= rawurlencode($att['photo_path']) ?>" alt="<?= htmlspecialchars($att['name']) ?>" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">
                <?php endif; ?>
                <div class="copy">
                    <h3><?= htmlspecialchars($att['name']) ?></h3>
                    <p><?= htmlspecialchars($att['location_name']) ?> &middot; <?= htmlspecialchars($att['category']) ?></p>
                    <a class="btn btn-light btn-sm" href="tourist/search.php?type=accommodation&amp;attraction_id=<?= $att['attraction_id'] ?>">Find nearby stays</a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<section class="hp-host">
    <img src="assets/user-house.jpg" alt="A welcoming Kenyan host home">
    <div class="hp-host-copy">
        <div class="hp-kicker">For Kenyan hosts</div>
        <h2>Your place belongs in someone's story.</h2>
        <p class="sub">List your property, manage bookings and connect with travellers looking for an authentic Kenyan stay.</p>
        <div class="actions">
            <a class="btn" href="register.php?role=host">List your property</a>
            <a class="btn btn-outline" href="how-it-works.php">How hosting works</a>
        </div>
    </div>
</section>

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