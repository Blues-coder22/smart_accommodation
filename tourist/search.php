<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Get search parameters
$type           = sanitize($_GET['type'] ?? 'accommodation');
$location       = sanitize($_GET['location'] ?? '');
$check_in       = sanitize($_GET['check_in'] ?? '');
$check_out      = sanitize($_GET['check_out'] ?? '');
$guests         = (int)($_GET['guests'] ?? 1);
$min_price      = (float)($_GET['min_price'] ?? 0);
$max_price      = (float)($_GET['max_price'] ?? 999999);
$property_type  = sanitize($_GET['property_type'] ?? '');
$category       = sanitize($_GET['category'] ?? '');
$attraction_id  = (int)($_GET['attraction_id'] ?? 0);
$lat            = (float)($_GET['lat'] ?? -1.2921);
$lng            = (float)($_GET['lng'] ?? 36.8219);
$radius         = (int)($_GET['radius'] ?? 20);
$sort           = sanitize($_GET['sort'] ?? 'smart_score');

$accommodations = [];
$attractions    = [];
$selected_attraction = null;

// If searching near a specific attraction
if ($attraction_id) {
    $stmt = $pdo->prepare("
        SELECT * FROM attractions 
        WHERE attraction_id = ?
    ");
    $stmt->execute([$attraction_id]);
    $selected_attraction = $stmt->fetch();

    if ($selected_attraction) {
        $lat  = $selected_attraction['latitude'];
        $lng  = $selected_attraction['longitude'];
        $type = 'accommodation';
    }
}

// If location string provided — geocode it using Nominatim
// (We store lat/lng in session after JS geocoding)
if (!empty($location) && !$attraction_id) {
    $lat = (float)($_GET['search_lat'] ?? $lat);
    $lng = (float)($_GET['search_lng'] ?? $lng);
}

// Fetch accommodations
if ($type === 'accommodation') {
    $filters = [
        'min_price'     => $min_price,
        'max_price'     => $max_price,
        'property_type' => $property_type,
        'guests'        => $guests,
        'check_in'      => $check_in,
        'check_out'     => $check_out
    ];
    $accommodations = getNearbyAccommodations($lat, $lng, $radius, $filters);

    // Apply sorting override
    if ($sort === 'price_asc') {
        usort($accommodations, 
            fn($a, $b) => $a['price_per_night'] <=> $b['price_per_night']);
    } elseif ($sort === 'price_desc') {
        usort($accommodations, 
            fn($a, $b) => $b['price_per_night'] <=> $a['price_per_night']);
    } elseif ($sort === 'rating') {
        usort($accommodations, 
            fn($a, $b) => $b['avg_rating'] <=> $a['avg_rating']);
    }
    // Default sort = smart_score (already applied in getNearbyAccommodations)
}

// Fetch attractions
if ($type === 'attraction') {
    $sql    = "SELECT * FROM attractions WHERE 1=1";
    $params = [];

    if (!empty($category)) {
        $sql .= " AND category = ?";
        $params[] = $category;
    }

    if (!empty($location)) {
        $sql .= " AND (name LIKE ? OR location_name LIKE ?)";
        $params[] = "%$location%";
        $params[] = "%$location%";
    }

    $sql .= " ORDER BY name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $attractions = $stmt->fetchAll();
}

// Get amenities for filter display
$amenity_list = $pdo->query("
    SELECT DISTINCT amenity_name FROM amenities ORDER BY amenity_name
")->fetchAll(PDO::FETCH_COLUMN);

// Get property types for filter
$property_types = $pdo->query("
    SELECT DISTINCT property_type FROM accommodations 
    WHERE status = 'approved' ORDER BY property_type
")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?= $type === 'accommodation' ? 'Find Accommodation' : 'Explore Attractions' ?>
        — Smart Accommodation Kenya
    </title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .search-layout { display: grid;
                         grid-template-columns: 280px 1fr;
                         gap: 24px; padding: 24px;
                         max-width: 1400px; margin: 0 auto; }
        .filters-panel { background: white; border-radius: 10px;
                         padding: 20px; height: fit-content;
                         box-shadow: 0 1px 6px rgba(0,0,0,0.06);
                         position: sticky; top: 80px; }
        .filters-panel h3 { font-size: 16px; font-weight: 700;
                            color: #085041; margin-bottom: 16px; }
        .filter-group { margin-bottom: 20px; }
        .filter-group label { font-size: 13px; font-weight: 600;
                              color: #444; display: block;
                              margin-bottom: 6px; }
        .filter-group input,
        .filter-group select { width: 100%; padding: 8px 12px;
                               border: 1px solid #ddd; border-radius: 6px;
                               font-size: 13px; }
        .price-range { display: flex; gap: 8px; align-items: center; }
        .price-range input { flex: 1; }
        .price-range span { color: #666; font-size: 13px; }
        .results-header { display: flex; justify-content: space-between;
                          align-items: center; margin-bottom: 16px; }
        .results-count { font-size: 14px; color: #666; }
        .sort-select { padding: 8px 12px; border: 1px solid #ddd;
                       border-radius: 6px; font-size: 13px; }
        #map { height: 320px; border-radius: 10px;
               border: 1px solid #ddd; margin-bottom: 20px; }
        .results-grid { display: grid;
                        grid-template-columns: repeat(
                            auto-fill, minmax(260px,1fr));
                        gap: 16px; }
        .acc-card { background: white; border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 1px 6px rgba(0,0,0,0.06);
                    transition: box-shadow 0.2s; cursor: pointer; }
        .acc-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
        .acc-card img { width: 100%; height: 160px; object-fit: cover; }
        .acc-card-body { padding: 14px; }
        .acc-card-title { font-size: 15px; font-weight: 600;
                          margin-bottom: 4px; color: #085041; }
        .acc-card-location { font-size: 12px; color: #888;
                             margin-bottom: 8px; }
        .acc-card-meta { display: flex; justify-content: space-between;
                         align-items: center; margin-bottom: 8px; }
        .acc-price { font-size: 15px; font-weight: 700; color: #0f6e56; }
        .acc-rating { font-size: 12px; color: #666; }
        .smart-badge { font-size: 11px; background: #e8f8f3;
                       color: #085041; padding: 2px 8px;
                       border-radius: 10px; margin-bottom: 10px;
                       display: inline-block; }
        .no-results { text-align: center; padding: 60px 20px;
                      color: #666; }
        .no-results h3 { font-size: 18px; margin-bottom: 8px; }
        .search-bar-top { background: #085041; padding: 16px 24px; }
        .search-bar-top form { display: flex; gap: 10px;
                               max-width: 1400px; margin: 0 auto; }
        .search-bar-top input,
        .search-bar-top select { flex: 1; padding: 10px 14px;
                                  border: none; border-radius: 6px;
                                  font-size: 14px; }
        .search-bar-top button { background: #1D9E75; color: white;
                                  border: none; padding: 10px 20px;
                                  border-radius: 6px; cursor: pointer;
                                  font-size: 14px; white-space: nowrap; }
        .att-card { background: white; border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 1px 6px rgba(0,0,0,0.06); }
        .att-card img { width: 100%; height: 160px; object-fit: cover; }
        .att-card-body { padding: 14px; }
        .att-card-title { font-size: 15px; font-weight: 600;
                          color: #085041; margin-bottom: 4px; }
        .att-card-location { font-size: 12px; color: #888;
                             margin-bottom: 8px; }
        .att-card-footer { display: flex;
                           justify-content: space-between;
                           align-items: center; }
        .att-price { font-size: 14px; font-weight: 700; color: #0f6e56; }
        .category-badge { font-size: 11px; background: #eeedfe;
                          color: #3c3489; padding: 2px 8px;
                          border-radius: 10px; }
        @media (max-width: 768px) {
            .search-layout { grid-template-columns: 1fr; }
            .filters-panel { position: static; }
        }
    </style>
</head>
<body>

<?php include '../includes/header.php'; ?>

<!-- Search Bar Top -->
<div class="search-bar-top">
    <form method="GET" action="search.php">
        <input type="hidden" name="type" value="<?= $type ?>">
        <?php if ($type === 'accommodation'): ?>
            <input type="text" name="location"
                   value="<?= htmlspecialchars($location) ?>"
                   placeholder="Where are you going?">
            <input type="date" name="check_in"
                   value="<?= htmlspecialchars($check_in) ?>"
                   min="<?= date('Y-m-d') ?>">
            <input type="date" name="check_out"
                   value="<?= htmlspecialchars($check_out) ?>"
                   min="<?= date('Y-m-d') ?>">
            <select name="guests">
                <?php for ($i = 1; $i <= 10; $i++): ?>
                    <option value="<?= $i ?>"
                        <?= $guests == $i ? 'selected' : '' ?>>
                        <?= $i ?> Guest<?= $i > 1 ? 's' : '' ?>
                    </option>
                <?php endfor; ?>
            </select>
        <?php else: ?>
            <input type="text" name="location"
                   value="<?= htmlspecialchars($location) ?>"
                   placeholder="Search attractions...">
            <select name="category">
                <option value="">All Categories</option>
                <?php foreach ([
                    'National Parks','Museums','Beaches',
                    'Historical Sites','Adventure','Culture',
                    'Restaurants','Shopping','Entertainment',
                    'Religious Sites'
                ] as $cat): ?>
                    <option value="<?= $cat ?>"
                        <?= $category === $cat ? 'selected' : '' ?>>
                        <?= $cat ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit">Search</button>
    </form>
</div>

<!-- Selected Attraction Banner -->
<?php if ($selected_attraction): ?>
<div style="background:#eeedfe;padding:12px 24px;
            border-bottom:1px solid #afa9ec">
    <div style="max-width:1400px;margin:0 auto;font-size:14px;
                color:#3c3489">
        📍 Showing accommodations near
        <strong><?= htmlspecialchars($selected_attraction['name']) ?></strong>
        — <?= htmlspecialchars($selected_attraction['location_name']) ?>
        &nbsp;|&nbsp;
        <a href="search.php?type=accommodation" 
           style="color:#3c3489">Clear</a>
    </div>
</div>
<?php endif; ?>

<div class="search-layout">

    <!-- Filters Panel -->
    <div class="filters-panel">
        <h3>Filters</h3>
        <form method="GET" action="search.php" id="filter-form">
            <input type="hidden" name="type" 
                   value="<?= $type ?>">
            <input type="hidden" name="location" 
                   value="<?= htmlspecialchars($location) ?>">
            <input type="hidden" name="check_in"
                   value="<?= htmlspecialchars($check_in) ?>">
            <input type="hidden" name="check_out"
                   value="<?= htmlspecialchars($check_out) ?>">
            <input type="hidden" name="guests"
                   value="<?= $guests ?>">
            <input type="hidden" name="search_lat" 
                   value="<?= $lat ?>">
            <input type="hidden" name="search_lng" 
                   value="<?= $lng ?>">

            <?php if ($type === 'accommodation'): ?>

            <!-- Price Range -->
            <div class="filter-group">
                <label>Price per night (KES)</label>
                <div class="price-range">
                    <input type="number" name="min_price"
                           value="<?= $min_price ?>"
                           placeholder="Min" min="0">
                    <span>to</span>
                    <input type="number" name="max_price"
                           value="<?= $max_price == 999999 
                                       ? '' : $max_price ?>"
                           placeholder="Max" min="0">
                </div>
            </div>

            <!-- Property Type -->
            <div class="filter-group">
                <label>Property Type</label>
                <select name="property_type">
                    <option value="">All Types</option>
                    <?php foreach ($property_types as $pt): ?>
                        <option value="<?= htmlspecialchars($pt) ?>"
                            <?= $property_type === $pt 
                                ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Search Radius -->
            <div class="filter-group">
                <label>Search Radius</label>
                <select name="radius">
                    <option value="5" 
                        <?= $radius == 5 ? 'selected':'' ?>>5 km</option>
                    <option value="10" 
                        <?= $radius == 10 ? 'selected':'' ?>>10 km</option>
                    <option value="20" 
                        <?= $radius == 20 ? 'selected':'' ?>>20 km</option>
                    <option value="50" 
                        <?= $radius == 50 ? 'selected':'' ?>>50 km</option>
                    <option value="100"
                        <?= $radius == 100 ? 'selected':'' ?>>100 km</option>
                </select>
            </div>

            <?php else: ?>

            <!-- Category Filter for Attractions -->
            <div class="filter-group">
                <label>Category</label>
                <select name="category">
                    <option value="">All Categories</option>
                    <?php foreach ([
                        'National Parks','Museums','Beaches',
                        'Historical Sites','Adventure','Culture',
                        'Restaurants','Shopping','Entertainment',
                        'Religious Sites'
                    ] as $cat): ?>
                        <option value="<?= $cat ?>"
                            <?= $category === $cat ? 'selected' : '' ?>>
                            <?= $cat ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-full">
                Apply Filters
            </button>

            <a href="search.php?type=<?= $type ?>"
               style="display:block;text-align:center;margin-top:10px;
                      font-size:13px;color:#666">
                Reset Filters
            </a>
        </form>
    </div>

    <!-- Results Panel -->
    <div>

        <!-- Map -->
        <div id="map"></div>

        <!-- Results Header -->
        <div class="results-header">
            <div class="results-count">
                <?php if ($type === 'accommodation'): ?>
                    <?= count($accommodations) ?> accommodation
                    <?= count($accommodations) == 1 ? '' : 's' ?> found
                    <?= $location ? "near <strong>" . 
                        htmlspecialchars($location) . "</strong>" : '' ?>
                <?php else: ?>
                    <?= count($attractions) ?> attraction
                    <?= count($attractions) == 1 ? '' : 's' ?> found
                <?php endif; ?>
            </div>
            <?php if ($type === 'accommodation'): ?>
            <select class="sort-select" onchange="
                const url = new URL(window.location);
                url.searchParams.set('sort', this.value);
                window.location = url;">
                <option value="smart_score"
                    <?= $sort === 'smart_score' ? 'selected':'' ?>>
                    Sort: Recommended
                </option>
                <option value="price_asc"
                    <?= $sort === 'price_asc' ? 'selected':'' ?>>
                    Sort: Price Low to High
                </option>
                <option value="price_desc"
                    <?= $sort === 'price_desc' ? 'selected':'' ?>>
                    Sort: Price High to Low
                </option>
                <option value="rating"
                    <?= $sort === 'rating' ? 'selected':'' ?>>
                    Sort: Highest Rated
                </option>
            </select>
            <?php endif; ?>
        </div>

        <!-- Accommodation Results -->
        <?php if ($type === 'accommodation'): ?>
            <?php if (empty($accommodations)): ?>
                <div class="no-results">
                    <h3>No accommodations found</h3>
                    <p>Try increasing the search radius or 
                       adjusting your filters</p>
                    <a href="search.php?type=accommodation"
                       class="btn btn-primary" 
                       style="margin-top:16px;display:inline-block">
                        Clear Filters
                    </a>
                </div>
            <?php else: ?>
                <div class="results-grid">
                    <?php foreach ($accommodations as $acc): ?>
                    <div class="acc-card"
                         onclick="window.location='details.php?id=
                             <?= $acc['accommodation_id'] ?>'">
                        <?php
                        // Get primary photo
                        $photo_stmt = $pdo->prepare("
                            SELECT photo_path 
                            FROM accommodation_photos
                            WHERE accommodation_id = ? 
                            AND is_primary = 1 
                            LIMIT 1
                        ");
                        $photo_stmt->execute([$acc['accommodation_id']]);
                        $photo = $photo_stmt->fetchColumn();
                        ?>
                        <?php if ($photo): ?>
                            <img src="../uploads/accommodations/<?= htmlspecialchars($photo) ?>"
                                 alt="<?= htmlspecialchars($acc['title'])?>">
                        <?php else: ?>
                            <div style="height:160px;background:#e8f8f3;
                                        display:flex;align-items:center;
                                        justify-content:center;
                                        font-size:40px">🏠</div>
                        <?php endif; ?>
                        <div class="acc-card-body">
                            <div class="acc-card-title">
                                <?= htmlspecialchars($acc['title']) ?>
                            </div>
                            <div class="acc-card-location">
                                📍 <?= htmlspecialchars($acc['location_name'])?>
                                &nbsp;·&nbsp;
                                <?= number_format($acc['distance'], 1) ?> km away
                            </div>
                            <div class="acc-card-meta">
                                <div class="acc-price">
                                    KES <?= number_format($acc['price_per_night'])?>
                                    <span style="font-size:11px;
                                                 font-weight:400;
                                                 color:#666">/night</span>
                                </div>
                                <div class="acc-rating">
                                    ⭐ <?= number_format($acc['avg_rating'],1)?>
                                    <span style="color:#aaa">
                                        (<?= $acc['total_bookings'] ?>)
                                    </span>
                                </div>
                            </div>
                            <div class="smart-badge">
                                Smart score: <?= $acc['smart_score'] ?>
                            </div>
                            <a href="details.php?id=
                               <?= $acc['accommodation_id'] ?>"
                               class="btn btn-primary btn-full"
                               style="display:block;text-align:center">
                                View Details
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <!-- Attraction Results -->
        <?php else: ?>
            <?php if (empty($attractions)): ?>
                <div class="no-results">
                    <h3>No attractions found</h3>
                    <p>Try a different category or search term</p>
                </div>
            <?php else: ?>
                <div class="results-grid">
                    <?php foreach ($attractions as $att): ?>
                    <div class="att-card">
                        <?php if ($att['photo_path']): ?>
                            <img src="../uploads/attractions/
                                <?= htmlspecialchars($att['photo_path']) ?>"
                                 alt="<?= htmlspecialchars($att['name'])?>">
                        <?php else: ?>
                            <div style="height:160px;background:#eeedfe;
                                        display:flex;align-items:center;
                                        justify-content:center;
                                        font-size:40px">📍</div>
                        <?php endif; ?>
                        <div class="att-card-body">
                            <div class="att-card-title">
                                <?= htmlspecialchars($att['name']) ?>
                            </div>
                            <div class="att-card-location">
                                📍 <?= htmlspecialchars($att['location_name'])?>
                            </div>
                            <p style="font-size:12px;color:#666;
                                      margin-bottom:10px;
                                      line-height:1.5">
                                <?= htmlspecialchars(
                                    substr($att['description'], 0, 80)
                                ) ?>...
                            </p>
                            <div class="att-card-footer">
                                <div class="att-price">
                                    <?= $att['entry_fee'] > 0
                                        ? 'KES ' . 
                                          number_format($att['entry_fee'])
                                        : 'Free entry' ?>
                                </div>
                                <div class="category-badge">
                                    <?= htmlspecialchars($att['category'])?>
                                </div>
                            </div>
                            <a href="search.php?type=accommodation&attraction_id=<?= $att['attraction_id'] ?>"
                               class="btn btn-primary btn-full"
                               style="margin-top:10px;display:block;
                                      text-align:center">
                                Find Nearby Stays
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<?php include '../includes/footer.php'; ?>

<!-- Map Script -->
<script>
const map = L.map('map').setView([<?= $lat ?>, <?= $lng ?>], 11);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

const greenIcon = L.divIcon({
    className: '',
    html: `<div style="background:#1D9E75;color:white;padding:4px 8px;
                       border-radius:10px;font-size:11px;
                       font-weight:600;white-space:nowrap">🏠</div>`,
    iconAnchor: [15, 10]
});

const purpleIcon = L.divIcon({
    className: '',
    html: `<div style="background:#534AB7;color:white;padding:4px 8px;
                       border-radius:10px;font-size:11px;
                       font-weight:600;white-space:nowrap">📍</div>`,
    iconAnchor: [15, 10]
});

<?php if ($type === 'accommodation'): ?>
// Plot accommodation pins
<?php foreach ($accommodations as $acc): ?>
<?php if ($acc['latitude'] && $acc['longitude']): ?>
L.marker(
    [<?= $acc['latitude'] ?>, <?= $acc['longitude'] ?>],
    { icon: greenIcon }
).addTo(map).bindPopup(`
    <b><?= htmlspecialchars($acc['title']) ?></b><br>
    KES <?= number_format($acc['price_per_night']) ?>/night<br>
    ⭐ <?= number_format($acc['avg_rating'], 1) ?><br>
    Score: <?= $acc['smart_score'] ?><br>
    <a href="details.php?id=<?= $acc['accommodation_id'] ?>">
        View Details
    </a>
`);
<?php endif; ?>
<?php endforeach; ?>

<?php if ($selected_attraction): ?>
// Plot selected attraction pin
L.marker(
    [<?= $selected_attraction['latitude'] ?>,
     <?= $selected_attraction['longitude'] ?>],
    { icon: purpleIcon }
).addTo(map).bindPopup(`
    <b><?= htmlspecialchars($selected_attraction['name']) ?></b><br>
    <?= htmlspecialchars($selected_attraction['location_name']) ?>
`).openPopup();
<?php endif; ?>

<?php else: ?>
// Plot attraction pins
<?php foreach ($attractions as $att): ?>
<?php if ($att['latitude'] && $att['longitude']): ?>
L.marker(
    [<?= $att['latitude'] ?>, <?= $att['longitude'] ?>],
    { icon: purpleIcon }
).addTo(map).bindPopup(`
    <b><?= htmlspecialchars($att['name']) ?></b><br>
    <?= htmlspecialchars($att['category']) ?><br>
    <?= $att['entry_fee'] > 0 
        ? 'KES ' . number_format($att['entry_fee']) 
        : 'Free entry' ?><br>
    <a href="search.php?type=accommodation&attraction_id=<?= $att['attraction_id'] ?>">
        Find Nearby Stays
    </a>
`);
<?php endif; ?>
<?php endforeach; ?>
<?php endif; ?>

// Geocoding — convert location text to coordinates using Nominatim
<?php if (!empty($location) && !$attraction_id): ?>
fetch(`https://nominatim.openstreetmap.org/search?format=json&q=<?= urlencode($location) ?>,Kenya&limit=1`)
.then(r => r.json())
.then(data => {
    if (data.length > 0) {
        const lat = parseFloat(data[0].lat);
        const lng = parseFloat(data[0].lon);
        map.setView([lat, lng], 12);

        // Update hidden fields and resubmit if coordinates changed
        const currentLat = <?= $lat ?>;
        const currentLng = <?= $lng ?>;
        if (Math.abs(lat - currentLat) > 0.01 || 
            Math.abs(lng - currentLng) > 0.01) {
            const url = new URL(window.location);
            url.searchParams.set('search_lat', lat);
            url.searchParams.set('search_lng', lng);
            window.location = url;
        }
    }
})
.catch(err => console.error('Geocoding error:', err));
<?php endif; ?>
</script>

</body>
</html>