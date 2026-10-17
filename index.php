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

$homeDestinations = [
    [
        'name' => 'Maasai Mara',
        'county' => 'Narok',
        'category' => 'Wildlife',
        'tagline' => 'Iconic savannah landscapes, wildlife and the Great Migration.',
        'image' => 'assets/user-sunset.jpg',
        'latitude' => -1.4900,
        'longitude' => 35.1400
    ],
    [
        'name' => 'Mount Kenya',
        'county' => 'Laikipia',
        'category' => 'Mountain',
        'tagline' => 'Alpine lakes, forest trails and dramatic mountain scenery.',
        'image' => 'assets/user-mount-kenya.jpg',
        'latitude' => -0.1521,
        'longitude' => 37.3084
    ],
    [
        'name' => 'Lamu Old Town',
        'county' => 'Lamu',
        'category' => 'Culture',
        'tagline' => 'A historic Swahili town with dhow trips, beaches and quiet lanes.',
        'image' => 'assets/user-lamu.jpg',
        'latitude' => -2.2696,
        'longitude' => 40.9006
    ]
];
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
</head>
<body class="homepage">

<?php include 'includes/header.php'; ?>

<section class="hp-hero">
    <div class="hp-hero-inner">
        <span class="hp-badge">Smart recommendations for Kenya and beyond</span>
        <h1>Discover Kenya.<br>Stay.Explore.<br>Experience.</h1>
        <p>From cosy rooms in Nairobi to lodges at the foot of Mt. Kenya, find where to stay and discover nearby attractions or start with a place you want to see and find the best stays around it.</p>
        <div class="actions" style="justify-content:center;margin-top:28px">
            <a class="btn btn-light" href="tourist/search.php?type=accommodation">Find a stay</a>
            <a class="btn btn-outline" style="color:#fff;border-color:rgba(255,255,255,.8)" href="tourist/search.php?type=attraction">Explore attractions</a>
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

<section class="hp-section hp-map-section" id="kenyaMap">
    <div class="hp-kicker">Search anywhere in Kenya</div>
    <h2>Find a stay or an attraction on the map</h2>
    <p class="hp-caption">Start from a place you want to visit and see the best stays around it, or start from a stay and see what is nearby.</p>
    <form class="map-search" method="GET" action="tourist/search.php">
        <input type="hidden" name="type" value="accommodation">
        <div class="map-search-box">
            <input id="mapSearch" type="search" name="location" placeholder="Try “Diani”, “Maasai Mara”, “Nanyuki”…" autocomplete="off" aria-label="Search a place, attraction or stay in Kenya">
        </div>
        <button class="btn" id="mapGo" type="submit">Search</button>
        <button class="btn btn-outline" id="mapNear" type="button">◎ Use my location</button>
        <select id="mapRadius" name="radius" aria-label="Search radius">
            <option value="25">Within 25 km</option>
            <option value="50">Within 50 km</option>
            <option value="100" selected>Within 100 km</option>
            <option value="250">Within 250 km</option>
        </select>
        <details class="home-date-options">
            <summary>Choose travel dates</summary>
            <div class="grid grid-2" style="margin-top:10px">
                <div class="field">
                    <label for="home-check-in">CHECK IN</label>
                    <input id="home-check-in" type="date" name="check_in" min="<?= date('Y-m-d') ?>">
                </div>
                <div class="field">
                    <label for="home-check-out">CHECK OUT</label>
                    <input id="home-check-out" type="date" name="check_out" min="<?= date('Y-m-d') ?>">
                </div>
            </div>
        </details>
    </form>
    <div class="map-chips" role="group" aria-label="Show on map">
        <button type="button" class="chip on" data-kind="all" aria-pressed="true">All</button>
        <button type="button" class="chip" data-kind="attractions" aria-pressed="false"><span class="near-dot attraction"></span>Attractions</button>
        <button type="button" class="chip" data-kind="stays" aria-pressed="false"><span class="near-dot stay"></span>Stays</button>
    </div>
    <div class="map-layout">
        <div class="home-map" id="map" aria-label="Map of accommodations and attractions across Kenya"></div>
        <aside class="card pad map-results" id="mapResults" aria-live="polite">
            <h3>Explore around Kenya</h3>
            <p class="small">Featured stays and attractions to start planning your next trip.</p>
            <section class="near-section">
                <div class="near-head"><h4>Featured stays</h4></div>
                <?php foreach (array_slice($featured, 0, 4) as $acc): ?>
                    <a class="near-item" href="tourist/details.php?id=<?= (int)$acc['accommodation_id'] ?>">
                        <span class="near-dot stay"></span>
                        <span><strong><?= htmlspecialchars($acc['title']) ?></strong><span class="small"><?= htmlspecialchars($acc['location_name']) ?> · KES <?= number_format($acc['price_per_night']) ?>/night</span></span>
                    </a>
                <?php endforeach; ?>
            </section>
            <section class="near-section">
                <div class="near-head"><h4>Popular attractions</h4></div>
                <?php foreach ($homeDestinations as $destination): ?>
                    <a class="near-item" href="tourist/search.php?type=accommodation&amp;lat=<?= $destination['latitude'] ?>&amp;lng=<?= $destination['longitude'] ?>&amp;radius=100">
                        <span class="near-dot attraction"></span>
                        <span><strong><?= htmlspecialchars($destination['name']) ?></strong><span class="small"><?= htmlspecialchars($destination['county']) ?> County · <?= htmlspecialchars($destination['category']) ?></span></span>
                    </a>
                <?php endforeach; ?>
            </section>
        </aside>
    </div>
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
        <?php $featuredDestination = $homeDestinations[0]; ?>
        <article class="hp-attraction-feature" style="background-image:linear-gradient(180deg,transparent 42%,rgba(0,0,0,.76)),url('<?= htmlspecialchars($featuredDestination['image'], ENT_QUOTES) ?>');background-size:cover;background-position:center">
            <div class="copy">
                <div class="hp-kicker" style="color:#efb59e"><?= htmlspecialchars($featuredDestination['name']) ?> · <?= htmlspecialchars($featuredDestination['county']) ?> County · <?= htmlspecialchars($featuredDestination['category']) ?></div>
                <h3><?= htmlspecialchars($featuredDestination['tagline']) ?></h3>
                <a class="btn btn-light" href="tourist/search.php?type=accommodation&amp;lat=<?= $featuredDestination['latitude'] ?>&amp;lng=<?= $featuredDestination['longitude'] ?>&amp;radius=100">Find nearby stays</a>
            </div>
        </article>
        <div class="hp-attraction-grid">
            <?php foreach (array_slice($homeDestinations, 1) as $destination): ?>
            <article class="hp-attraction-card" style="background-image:linear-gradient(180deg,transparent 40%,rgba(0,0,0,.72)),url('<?= htmlspecialchars($destination['image'], ENT_QUOTES) ?>')">
                <div class="copy">
                    <div class="hp-kicker" style="color:#efb59e"><?= htmlspecialchars($destination['category']) ?> · <?= htmlspecialchars($destination['county']) ?></div>
                    <h3><?= htmlspecialchars($destination['tagline']) ?></h3>
                    <a class="btn btn-light btn-sm" href="tourist/search.php?type=accommodation&amp;lat=<?= $destination['latitude'] ?>&amp;lng=<?= $destination['longitude'] ?>&amp;radius=100">Find nearby stays</a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
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
const stayMarkers = L.layerGroup().addTo(map);
const attractionMarkers = L.layerGroup().addTo(map);

// Custom icons
const greenIcon = L.divIcon({
    className: '',
    html: `<div style="background:#1D9E75;color:white;padding:4px 8px;
                       border-radius:10px;font-size:11px;
                       font-weight:600;white-space:nowrap">🏠 Stay</div>`,
    iconAnchor: [20, 10]
});

const purpleIcon = L.divIcon({
    className: '',
    html: `<div style="background:#bd5d3c;color:white;padding:4px 8px;
                       border-radius:10px;font-size:11px;
                       font-weight:600;white-space:nowrap">📍 Visit</div>`,
    iconAnchor: [20, 10]
});

// Plot accommodations
<?php foreach ($featured as $acc): ?>
<?php if ($acc['latitude'] && $acc['longitude']): ?>
L.marker(
    [<?= $acc['latitude'] ?>, <?= $acc['longitude'] ?>],
    { icon: greenIcon }
).addTo(stayMarkers).bindPopup(`
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

// Show the same featured destinations as the standalone frontend.
const homeDestinations = <?= json_encode($homeDestinations, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
homeDestinations.forEach(destination => {
    const nearbyUrl = `tourist/search.php?type=accommodation&lat=${destination.latitude}&lng=${destination.longitude}&radius=100`;
    L.marker([destination.latitude, destination.longitude], { icon: purpleIcon })
        .addTo(attractionMarkers)
        .bindPopup(`<b>${destination.name}</b><br>${destination.county} County · ${destination.category}<br>${destination.tagline}<br><a href="${nearbyUrl}">Find Nearby Stays</a>`);
});

// Detect current location button
document.querySelectorAll('.map-chips [data-kind]').forEach(chip => {
    chip.addEventListener('click', () => {
        const kind = chip.dataset.kind;
        document.querySelectorAll('.map-chips [data-kind]').forEach(option => {
            const active = option === chip;
            option.classList.toggle('on', active);
            option.setAttribute('aria-pressed', String(active));
        });
        if (kind === 'all' || kind === 'stays') {
            if (!map.hasLayer(stayMarkers)) stayMarkers.addTo(map);
        } else {
            map.removeLayer(stayMarkers);
        }
        if (kind === 'all' || kind === 'attractions') {
            if (!map.hasLayer(attractionMarkers)) attractionMarkers.addTo(map);
        } else {
            map.removeLayer(attractionMarkers);
        }
    });
});

document.getElementById('mapNear').addEventListener('click', function() {
    if (!navigator.geolocation) {
        alert('Geolocation is not supported by your browser.');
        return;
    }
    this.disabled = true;
    navigator.geolocation.getCurrentPosition(function(pos) {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        map.setView([lat, lng], 12);
        L.marker([lat, lng]).addTo(map).bindPopup('You are here').openPopup();
        const radius = encodeURIComponent(document.getElementById('mapRadius').value);
        window.location.href = `tourist/search.php?type=accommodation&lat=${lat}&lng=${lng}&radius=${radius}`;
    }, function() {
        alert('Your location could not be determined. Check your location permissions and try again.');
        document.getElementById('mapNear').disabled = false;
    }, { enableHighAccuracy: true, timeout: 10000 });
});
</script>

</body>
</html>