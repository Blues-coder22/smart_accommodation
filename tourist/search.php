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
$stay_id        = (int)($_GET['stay_id'] ?? 0);
$distanceMeaningful = false;   // true only when a real place was searched, so "km away" and the distance score mean something
$lat            = (float)($_GET['lat'] ?? -1.2921);
$lng            = (float)($_GET['lng'] ?? 36.8219);
$radius         = (int)($_GET['radius'] ?? 20);
$sort           = sanitize($_GET['sort'] ?? 'smart_score');
$hasResolvedSearchCoords = isset($_GET['search_lat'], $_GET['search_lng']);
// Selected amenities (each must be offered). Stored values are sanitize()d, so compare the same way.
$sel_amenities  = [];
foreach ((array)($_GET['amenities'] ?? []) as $am) {
    if (is_string($am) && trim($am) !== '') $sel_amenities[] = sanitize($am);
}

publishDueReviews($pdo);

$accommodations = [];
$attractions    = [];
$selected_attraction = null;

function searchAccommodationPhotoUrl($storedPath, $kind = 'accommodations') {
    $fallback = '../StayExplore_Kenya_Authenticated_Frontend/assets/' .
        ($kind === 'attractions' ? 'kenya-lake-shore.jpg' : 'user-house.jpg');
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') return $fallback;
    if (preg_match('~^https?://~i', $storedPath)) return $storedPath;
    if (strpos($storedPath, '/') === 0) return $storedPath;
    $storedPath = str_replace('\\', '/', $storedPath);
    if (preg_match('~^uploads/(accommodations|attractions)/~i', $storedPath)) {
        return '../uploads/' . $kind . '/' . rawurlencode(basename($storedPath));
    }
    return '../uploads/' . $kind . '/' . rawurlencode(basename($storedPath));
}


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
        'check_out'     => $check_out,
        'amenities'     => $sel_amenities
    ];
    $hasExplicitCentre = isset($_GET['lat'], $_GET['lng']) || isset($_GET['search_lat'], $_GET['search_lng']) || $attraction_id > 0;
    if ($location !== '' && !$hasResolvedSearchCoords && !$attraction_id) {
        // Before geocoding finishes, match the typed text against real approved listings rather than showing unrelated Nairobi results.
        $textStmt = $pdo->prepare("SELECT a.*,
            COALESCE((SELECT AVG(r.rating) FROM reviews r WHERE r.accommodation_id=a.accommodation_id AND r.is_published=1),0) AS avg_rating,
            (SELECT COUNT(*) FROM bookings b WHERE b.accommodation_id=a.accommodation_id AND b.status='completed') AS total_bookings,
            0 AS distance
            FROM accommodations a WHERE a.status='approved' AND (a.title LIKE ? OR a.location_name LIKE ? OR a.description LIKE ?)
            ORDER BY a.created_at DESC LIMIT 50");
        $like = '%' . $location . '%';
        $textStmt->execute([$like, $like, $like]);
        $accommodations = applyWeightedScoring($textStmt->fetchAll(PDO::FETCH_ASSOC), false);   // no map position yet, so distance is not used
        $distanceMeaningful = false;
    } elseif ($hasExplicitCentre || $location !== '') {
        // Location-based searches use the Haversine distance filter.
        $accommodations = getNearbyAccommodations($lat, $lng, $radius, $filters);
        $distanceMeaningful = true;
    } else {
        // A general search must not silently restrict the visitor to a 20 km circle around Nairobi.
        $allSql = "SELECT a.*,
            CASE WHEN a.latitude IS NULL OR a.longitude IS NULL THEN 99999 ELSE
                " . haversineSql('a.latitude', 'a.longitude', ':lat', ':lng', ':lat2') . " END AS distance,
            COALESCE((SELECT AVG(r.rating) FROM reviews r WHERE r.accommodation_id=a.accommodation_id AND r.is_published=1),0) AS avg_rating,
            (SELECT COUNT(*) FROM bookings b WHERE b.accommodation_id=a.accommodation_id AND b.status='completed') AS total_bookings,
            (SELECT p.photo_path FROM accommodation_photos p WHERE p.accommodation_id=a.accommodation_id ORDER BY p.is_primary DESC, p.photo_id ASC LIMIT 1) AS primary_photo
            FROM accommodations a WHERE a.status='approved'";
        $allParams = [':lat'=>$lat, ':lng'=>$lng, ':lat2'=>$lat];
        if ($min_price > 0) { $allSql .= ' AND a.price_per_night >= :min_price'; $allParams[':min_price'] = $min_price; }
        if ($max_price < 999999) { $allSql .= ' AND a.price_per_night <= :max_price'; $allParams[':max_price'] = $max_price; }
        if ($property_type !== '') { $allSql .= ' AND a.property_type = :property_type'; $allParams[':property_type'] = $property_type; }
        $allSql .= ' ORDER BY a.created_at DESC LIMIT 100';
        $allStmt = $pdo->prepare($allSql);
        $allStmt->execute($allParams);
        $accommodations = applyWeightedScoring($allStmt->fetchAll(PDO::FETCH_ASSOC), false);    // no place chosen, so distance is not used
        $distanceMeaningful = false;
    }

    $verifiedHosts = verifiedHostIds($pdo, array_column($accommodations, 'host_id'));

    // Apply sorting override
    if ($sort === 'price_asc') {
        usort($accommodations, 
            function ($a, $b) { return $a['price_per_night'] <=> $b['price_per_night']; });
    } elseif ($sort === 'price_desc') {
        usort($accommodations, 
            function ($a, $b) { return $b['price_per_night'] <=> $a['price_per_night']; });
    } elseif ($sort === 'rating') {
        usort($accommodations, 
            function ($a, $b) { return $b['avg_rating'] <=> $a['avg_rating']; });
    }
    // Default sort = smart_score (already applied in getNearbyAccommodations)
}

// Fetch attractions
$near_stay = null;
if ($type === 'attraction') {
    $attRadius = max(5, min(500, (int)($_GET['radius'] ?? 100)));
    $sql    = "SELECT a.*, NULL AS distance_km FROM attractions a WHERE 1=1";
    $params = [];

    if ($stay_id > 0) {
        $ns = $pdo->prepare("SELECT accommodation_id, title, location_name, latitude, longitude FROM accommodations WHERE accommodation_id = ? AND status = 'approved'");
        $ns->execute([$stay_id]);
        $near_stay = $ns->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($near_stay && $near_stay['latitude'] !== null && $near_stay['longitude'] !== null) {
        // Straight-line (haversine) distance from the stay to each attraction, nearest first
        $sql = "SELECT a.*, " . haversineSql('a.latitude', 'a.longitude', ':nlat', ':nlng', ':nlat2') . " AS distance_km
                FROM attractions a WHERE a.latitude IS NOT NULL AND a.longitude IS NOT NULL";
        $params[':nlat'] = (float)$near_stay['latitude'];
        $params[':nlng'] = (float)$near_stay['longitude'];
        $params[':nlat2'] = (float)$near_stay['latitude'];
        $lat = (float)$near_stay['latitude']; $lng = (float)$near_stay['longitude'];
    } else {
        $near_stay = null;
    }

    if (!empty($category)) {
        $sql .= " AND a.category = :cat";
        $params[':cat'] = $category;
    }
    if (!empty($location)) {
        $sql .= " AND (a.name LIKE :q1 OR a.location_name LIKE :q2)";
        $params[':q1'] = "%$location%";
        $params[':q2'] = "%$location%";
    }
    if ($near_stay) {
        $sql .= " HAVING distance_km <= :rad ORDER BY distance_km ASC";
        $params[':rad'] = $attRadius;
    } else {
        $sql .= " ORDER BY a.name ASC";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $attractions = $stmt->fetchAll();
}
require_once __DIR__ . '/../includes/attraction_content.php';
require_once __DIR__ . '/../includes/gallery.php';
ensureAttractionPhotosTable($pdo);
$attExtras = ($type === 'attraction') ? getAttractionExtrasMap($pdo, $attractions) : [];

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
        — StayExplore Kenya
    </title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/attractions.css">
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
        .filter-group input:not([type=checkbox]):not([type=radio]),
        .filter-group select { width: 100%; padding: 8px 12px;
                               border: 1px solid #ddd; border-radius: 6px;
                               font-size: 13px; }
        /* Amenity check-boxes: the rule above used to stretch every checkbox to 100% width with a border,
           which drew the broken line. They now match the frontend's .check rows. */
        .filter-group .amenity-options { display: grid; gap: 2px; }
        .filter-group label.amenity-check { display: flex; align-items: center; gap: 9px;
                               margin: 0; padding: 6px 0; font-size: 12px; font-weight: 400;
                               color: #4a5249; cursor: pointer;
                               border-bottom: 1px solid #eef0ea; }
        .filter-group label.amenity-check:last-child { border-bottom: 0; }
        .filter-group label.amenity-check input[type=checkbox] { width: 16px; height: 16px; flex: 0 0 16px;
                               margin: 0; padding: 0; border: 0; accent-color: #0d3b2e; }
        .price-range { display: flex; gap: 8px; align-items: center; }
        .price-range input { flex: 1; }
        .price-range span { color: #666; font-size: 13px; }
        .results-header { display: flex; justify-content: space-between;
                          align-items: center; margin-bottom: 16px; }
        .results-count { font-size: 14px; color: #666; }
        .sort-select { padding: 8px 12px; border: 1px solid #ddd;
                       border-radius: 6px; font-size: 13px; }
        #map { height: 320px; border-radius: 10px;
               border: 1px solid #ddd; margin-bottom: 20px;
               position: relative; z-index: 0; isolation: isolate; }
        .results-grid { display: grid;
                        grid-template-columns: repeat(
                            auto-fill, minmax(260px,1fr));
                        gap: 16px; }
        .acc-card { background: white; border-radius: 14px;
                    overflow: hidden; border:1px solid #e7e9e3;
                    box-shadow: 0 3px 12px rgba(0,0,0,0.045);
                    transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease; cursor: pointer; }
        .acc-card:hover { transform:translateY(-5px); border-color:#c8d7cc; box-shadow: 0 16px 32px rgba(13,59,46,0.13); }
        .acc-card img { display:block; width: 100%; height: 205px; object-fit: contain; background:#f0eee7; }
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
        .no-results { text-align: center; padding: 60px 20px; color: #666; }
        .osm-live-results { background:#fff;border:1px solid #e4e9e6;border-radius:10px;padding:16px;margin:0 0 20px; }
        .osm-live-results h3 { margin:0 0 12px;color:#085041;font-size:17px; }
        .osm-live-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px; }
        .osm-live-card { border:1px solid #e2e7e4;border-radius:8px;padding:12px;min-width:0; }
        .osm-live-card strong { display:block;color:#164e3d;overflow-wrap:anywhere; }
        .osm-live-card p { margin:5px 0;color:#68736d;font-size:13px;text-transform:capitalize; }
        .osm-live-card small { color:#9a5b17;font-size:11px;display:block;margin:5px 0 8px; }
        .osm-live-card button { border:0;background:#eaf5ef;color:#155b43;border-radius:6px;padding:7px 9px;cursor:pointer; }
        .osm-attribution { color:#6d7772;font-size:11px;margin:12px 0 0; }
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
        .att-card { background: white; border-radius: 14px;
                    overflow: hidden; border:1px solid #e7e9e3;
                    box-shadow: 0 3px 12px rgba(0,0,0,0.045);
                    transition: transform .25s ease, box-shadow .25s ease; }
        .att-card:hover { transform:translateY(-5px); box-shadow:0 16px 32px rgba(13,59,46,.13); }
        .att-card img { display:block; width: 100%; height: 205px; object-fit: contain; background:#f0eee7; }
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
            .disc-head { max-width:1400px; margin:0 auto; padding:28px 24px 0; }
        .disc-head-row { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; }
        .disc-eyebrow { font-size:11px; letter-spacing:.14em; text-transform:uppercase; font-weight:700; color:#c0623a; }
        .disc-head h1 { margin:4px 0 6px; font-size:42px; line-height:1.05; }
        .disc-sub { margin:0; color:#6b6f68; font-size:14px; }
        .disc-tabs { display:flex; gap:6px; margin-top:22px; border-bottom:1px solid #e4e1d8; }
        .disc-tab { padding:10px 20px; font-size:14px; font-weight:600; color:#6b6f68; border-bottom:3px solid transparent; margin-bottom:-1px; text-decoration:none; }
        .disc-tab.active { color:#0d3b2e; border-bottom-color:#c0623a; }
        #mapBox[hidden] { display:none; }
        #mapBox { margin-bottom:18px; }
        .explainer { background:#fff; border:1px solid #e4e1d8; border-radius:12px; padding:0; margin-bottom:16px; }
        .explainer summary { cursor:pointer; padding:12px 16px; font-size:13px; font-weight:600; color:#0d3b2e; list-style:none; }
        .explainer summary::-webkit-details-marker { display:none; }
        .explainer summary::after { content:'+'; float:right; font-size:18px; line-height:1; }
        .explainer[open] summary::after { content:'\2212'; }
        .explainer .ex-body { padding:0 16px 14px; font-size:13px; color:#4a5249; line-height:1.6; }
        .explainer .ex-body p { margin:0 0 8px; }
        .near-banner { background:#eef4f1; border:1px solid #cfdcd5; border-radius:10px; padding:12px 16px; font-size:14px; color:#0d3b2e; margin-bottom:16px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:space-between; }
        .acc-card-actions { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:10px; }
        .acc-card-actions .btn { text-align:center; padding:9px 8px; font-size:13px; }
        .att-media { position:relative; }
        .att-media > .att-card-emoji, .att-media > .att-card-fee { z-index:4; }
        .att-media .att-teaser { pointer-events:none; z-index:2; }
        .near-km-tag { color:#0d3b2e; font-weight:700; font-size:12px; margin-bottom:6px; }
    </style>
</head>
<body class="tourist-search">

<?php include '../includes/header.php'; ?>

<!-- Page heading, tabs and map toggle (filters live only in the side panel) -->
<div class="disc-head">
    <div class="disc-head-row">
        <div>
            <div class="disc-eyebrow">Discover</div>
            <h1><?= $type === 'accommodation' ? 'Stays that feel like Kenya.' : 'Places worth the journey.' ?></h1>
            <p class="disc-sub"><?= $type === 'accommodation' ? 'Recommended stays around Kenya, with the attractions close to each one.' : 'Attractions across Kenya, with the stays close to each one.' ?></p>
        </div>
        <button type="button" class="btn btn-outline" id="toggleMap" aria-expanded="false">View map</button>
    </div>
    <div class="disc-tabs" role="tablist">
        <a class="disc-tab <?= $type === 'accommodation' ? 'active' : '' ?>" href="search.php?type=accommodation" role="tab">Accommodation</a>
        <a class="disc-tab <?= $type === 'attraction' ? 'active' : '' ?>" href="search.php?type=attraction" role="tab">Attractions</a>
    </div>
</div>

<!-- Context banners (flow between stays and attractions) -->
<?php if ($selected_attraction): ?>
<div class="disc-head" style="padding-top:18px"><div class="near-banner">
    <span>Showing stays near <strong><?= h($selected_attraction['name']) ?></strong> (<?= h($selected_attraction['location_name']) ?>), nearest first.</span>
    <span><a href="attraction.php?id=<?= (int)$selected_attraction['attraction_id'] ?>">View this attraction</a> &nbsp;|&nbsp; <a href="search.php?type=accommodation">Show all stays</a></span>
</div></div>
<?php elseif ($near_stay): ?>
<div class="disc-head" style="padding-top:18px"><div class="near-banner">
    <span>Showing attractions within <strong><?= (int)$attRadius ?> km</strong> of <strong><?= h($near_stay['title']) ?></strong>, nearest first.</span>
    <span><a href="details.php?id=<?= (int)$near_stay['accommodation_id'] ?>">View this stay</a> &nbsp;|&nbsp; <a href="search.php?type=attraction">Show all attractions</a></span>
</div></div>
<?php endif; ?>

<div class="search-layout">

    <!-- Filters Panel -->
    <div class="filters-panel">
        <h3>Filters</h3>
        <form method="GET" action="search.php" id="filter-form">
            <input type="hidden" name="type" value="<?= h($type) ?>">
            <?php if ($attraction_id): ?><input type="hidden" name="attraction_id" value="<?= (int)$attraction_id ?>" id="keepAttractionId"><?php endif; ?>
            <?php if ($stay_id): ?><input type="hidden" name="stay_id" value="<?= (int)$stay_id ?>" id="keepStayId"><?php endif; ?>
            <?php if ($check_in !== ''): ?><input type="hidden" name="check_in" value="<?= h($check_in) ?>"><?php endif; ?>
            <?php if ($check_out !== ''): ?><input type="hidden" name="check_out" value="<?= h($check_out) ?>"><?php endif; ?>
            <?php if ((int)$guests > 1): ?><input type="hidden" name="guests" value="<?= (int)$guests ?>"><?php endif; ?>

            <!-- Where -->
            <div class="filter-group">
                <label for="whereInput">Where</label>
                <input type="text" id="whereInput" name="location" value="<?= h($location) ?>"
                       placeholder="<?= $type === 'accommodation' ? 'Search a town, area or stay' : 'Search an attraction or place' ?>">
            </div>

            <?php if ($type === 'accommodation'): ?>

            <!-- Price Range -->
            <div class="filter-group">
                <label>Price per night (KES)</label>
                <div class="price-range">
                    <input aria-label="Minimum price per night (KES)" type="number" name="min_price"
                           value="<?= $min_price ?>"
                           placeholder="Min" min="0">
                    <span>to</span>
                    <input aria-label="Maximum price per night (KES)" type="number" name="max_price"
                           value="<?= $max_price == 999999 
                                       ? '' : $max_price ?>"
                           placeholder="Max" min="0">
                </div>
            </div>

            <!-- Property Type -->
            <div class="filter-group">
                <label>Property Type</label>
                <select aria-label="Property Type" name="property_type">
                    <option value="">All Types</option>
                    <?php foreach ($property_types as $pt): ?>
                        <option value="<?= h($pt) ?>"
                            <?= $property_type === $pt 
                                ? 'selected' : '' ?>>
                            <?= h($pt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Amenities -->
            <?php if (!empty($amenity_list)): ?>
            <div class="filter-group">
                <label>Amenities</label>
                <div class="amenity-options">
                <?php foreach ($amenity_list as $am): ?>
                    <label class="amenity-check">
                        <input type="checkbox" name="amenities[]" value="<?= h($am) ?>"
                               <?= in_array($am, $sel_amenities, true) ? 'checked' : '' ?>>
                        <span><?= h($am) ?></span>
                    </label>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Search Radius -->
            <div class="filter-group">
                <label>Search Radius</label>
                <select aria-label="Search Radius" name="radius">
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

            <?php if ($near_stay): ?>
            <div class="filter-group">
                <label>Distance from the stay</label>
                <select aria-label="Distance from the stay" name="radius">
                    <?php foreach ([10, 25, 50, 100, 200, 500] as $rk): ?>
                        <option value="<?= $rk ?>" <?= (int)$attRadius === $rk ? 'selected' : '' ?>><?= $rk ?> km</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <!-- Category Filter for Attractions -->
            <div class="filter-group">
                <label>Category</label>
                <select aria-label="Category" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ([
                        'National Parks','Museums','Beaches',
                        'Historical Sites','Adventure','Culture',
                        'Restaurants','Shopping','Entertainment',
                        'Religious Sites','Wildlife','Nature'
                    ] as $cat): ?>
                        <option value="<?= h($cat) ?>"
                            <?= $category === $cat ? 'selected' : '' ?>>
                            <?= h($cat) ?>
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

        <!-- Map (hidden until "View map" is pressed, like the frontend) -->
        <div id="mapBox" hidden>
        <div id="map"></div>
        <div class="osm-nearby-controls">
            <button type="button" id="osmSearchPlaces">Show nearby places</button>
            <span class="osm-nearby-status" id="osmSearchStatus" role="status" aria-live="polite">Green pins are stays, terracotta pins are attractions.</span>
        </div>
        <div class="osm-live-results" id="osmLiveResults" aria-live="polite"><p>Cafés, shops and other places around the map will appear here when you tap “Show nearby places”.</p></div>
        </div>

        <!-- Results Header -->
        <div class="results-header">
            <div class="results-count">
                <?php if ($type === 'accommodation'): ?>
                    <?= count($accommodations) ?> accommodation
                    <?= count($accommodations) == 1 ? '' : 's' ?> found
                    <?= $location ? "near <strong>" . 
                        h($location) . "</strong>" : '' ?>
                <?php else: ?>
                    <?= count($attractions) ?> attraction
                    <?= count($attractions) == 1 ? '' : 's' ?> found
                <?php endif; ?>
            </div>
            <?php if ($type === 'accommodation'): ?>
            <select aria-label="Sort results" class="sort-select" onchange="
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

        <?php if ($type === 'accommodation'): ?>
        <details class="explainer" id="how-scores">
            <summary>How are the smart score and distance worked out?</summary>
            <div class="ex-body">
                <p><strong>Smart score (out of 100).</strong> A quick way to see which stays suit you best. It mixes four things: guest ratings (the biggest part), how close the stay is to the place you searched, how good the price is compared with the other results, and how often guests have stayed there. A higher score means a better overall match. It is only a guide, so you always choose.</p>
                <p><strong>Distance.</strong> &ldquo;3.2 km away&rdquo; is the straight-line distance on the map, as a bird would fly it. We work it out from the two map positions with the haversine method, which allows for the curve of the Earth. Roads twist, so the drive is usually a bit longer than this number.</p>
            </div>
        </details>
        <?php endif; ?>

        <!-- Accommodation Results -->
        <?php if ($type === 'accommodation'): ?>
            <?php if (empty($accommodations)): ?>
                <div class="no-results">
                    <h3>No stays found</h3>
                    <p>Try a different place, a wider distance, or fewer filters.</p>
                    <a href="search.php?type=accommodation"
                       class="btn btn-primary" 
                       style="margin-top:16px;display:inline-block">
                        Clear Filters
                    </a>
                </div>
            <?php else: ?>
                <div class="results-grid">
                    <?php foreach ($accommodations as $acc):
                        $ps = $pdo->prepare("SELECT photo_path FROM accommodation_photos WHERE accommodation_id = ? ORDER BY is_primary DESC, photo_id ASC LIMIT 6");
                        $ps->execute([$acc['accommodation_id']]);
                        $urls = [];
                        foreach ($ps->fetchAll(PDO::FETCH_COLUMN) as $pp) $urls[] = searchAccommodationPhotoUrl($pp);
                        if (!$urls) $urls[] = searchAccommodationPhotoUrl('');
                        $score100 = (int)round(min(1, max(0, (float)$acc['smart_score'])) * 100);
                        $detailsUrl = 'details.php?id=' . (int)$acc['accommodation_id'];
                    ?>
                    <div class="acc-card" onclick="if(!event.target.closest('a,button'))window.location='<?= h($detailsUrl) ?>'">
                        <?= slideshowHtml($urls, $acc['title'], 'card') ?>
                        <div class="acc-card-body">
                            <div class="smart-badge" style="margin:0 0 4px">Smart score <?= $score100 ?> <a href="#how-scores" title="How is this worked out?" style="font-weight:400">(how?)</a></div>
                            <div class="acc-card-title">
                                <a href="<?= h($detailsUrl) ?>" style="color:inherit;text-decoration:none"><?= h($acc['title']) ?></a>
                                <?php if (!empty($verifiedHosts[(int)($acc['host_id'] ?? 0)])) echo hostVerifiedBadge(); ?>
                            </div>
                            <?php if ((int)$acc['total_bookings'] >= 0 && (float)$acc['avg_rating'] > 0): ?>
                            <div class="acc-rating" style="margin:2px 0 4px"><?= str_repeat('&#9733;', (int)round($acc['avg_rating'])) ?><span style="color:#d6d3c8"><?= str_repeat('&#9733;', 5 - (int)round($acc['avg_rating'])) ?></span> <span style="font-size:12px;color:#6b6f68"><?= number_format($acc['avg_rating'], 1) ?></span></div>
                            <?php else: ?>
                            <div class="acc-rating" style="margin:2px 0 4px;font-size:12px;color:#6b6f68">New listing, no reviews yet</div>
                            <?php endif; ?>
                            <div class="acc-card-location">
                                <?= h($acc['location_name']) ?>
                                <?php if ($distanceMeaningful && (float)$acc['distance'] < 99999): ?>
                                &nbsp;&middot;&nbsp; <?= number_format((float)$acc['distance'], 1) ?> km away
                                <?php endif; ?>
                            </div>
                            <div class="acc-card-meta">
                                <div class="acc-price">KES <?= number_format($acc['price_per_night']) ?> <span style="font-size:11px;font-weight:400;color:#666">/night</span></div>
                            </div>
                            <div class="acc-card-actions">
                                <a href="<?= h($detailsUrl) ?>" class="btn btn-primary">View stay</a>
                                <a href="search.php?type=attraction&stay_id=<?= (int)$acc['accommodation_id'] ?>" class="btn btn-outline">View attractions</a>
                            </div>
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
                    <p>Try a different category or search word.</p>
                </div>
            <?php else: ?>
                <div class="results-grid">
                    <?php foreach ($attractions as $i => $att):
                        $ex     = $attExtras[(int)$att['attraction_id']] ?? [];
                        $teaser = attractionTeaser($att, $ex);
                        $blurb  = attractionBlurb($att, $ex);
                    ?>
                    <div class="att-card att-fun" style="--i:<?= min($i, 12) ?>">
                        <?php $aUrls = array_map('attractionPhotoUrl', getAttractionPhotoNames($pdo, (int)$att['attraction_id'], $att['photo_path'])); if (!$aUrls) $aUrls[] = attractionPhotoUrl(''); ?>
                        <div class="att-media">
                            <?= slideshowHtml($aUrls, $att['name'], 'card') ?>
                            <span class="att-card-emoji" aria-hidden="true"><?= attractionEmoji($att['category']) ?></span>
                            <div class="att-teaser"><b><?= h($teaser['label']) ?></b><?= h($teaser['text']) ?></div>
                        </div>
                        <div class="att-card-body">
                            <div class="att-card-title"><?= h($att['name']) ?></div>
                            <div class="att-card-location"><?= h($att['location_name']) ?></div>
                            <?php if ($att['distance_km'] !== null): ?><div class="near-km-tag"><?= number_format((float)$att['distance_km'], 1) ?> km from <?= h($near_stay['title']) ?></div><?php endif; ?>
                            <p class="att-card-tag"><?= h($blurb) ?></p>
                            <div class="att-card-footer">
                                <span class="att-hint">Hover for a fun fact</span>
                                <div class="category-badge"><?= h($att['category']) ?></div>
                            </div>
                            <a href="attraction.php?id=<?= (int)$att['attraction_id'] ?>"
                               class="btn btn-outline btn-full"
                               style="margin-top:10px;display:block;text-align:center">
                                Explore attraction
                            </a>
                            <a href="search.php?type=accommodation&attraction_id=<?= (int)$att['attraction_id'] ?>"
                               class="btn btn-primary btn-full"
                               style="margin-top:8px;display:block;text-align:center">
                                View stays near here
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
<script src="../assets/js/slideshow.js"></script>
<script src="../assets/js/osm-nearby.js"></script>
<script src="../assets/js/map-pins.js"></script>
<script src="../assets/js/directions.js"></script>
<script>
const map = L.map('map').setView([<?= $lat ?>, <?= $lng ?>], <?= ($location !== '' || isset($_GET['lat'], $_GET['lng']) || isset($_GET['search_lat'], $_GET['search_lng']) || $attraction_id) ? 12 : 10 ?>);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);
window.enableDirections(map);
(function () {
    var btn = document.getElementById('toggleMap'), box = document.getElementById('mapBox');
    btn.addEventListener('click', function () {
        box.hidden = !box.hidden;
        btn.textContent = box.hidden ? 'View map' : 'Hide map';
        btn.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
        if (!box.hidden) setTimeout(function () { map.invalidateSize(); }, 60);
    });
    var where = document.getElementById('whereInput');
    if (where) where.addEventListener('input', function () {
        var k = document.getElementById('keepAttractionId'); if (k) k.remove();
    });
})();
window.enableOsmNearbyPlaces(map, { buttonId: 'osmSearchPlaces', statusId: 'osmSearchStatus', resultsContainerId: 'osmLiveResults', radius: Math.min(10000, Math.max(2000, <?= (int)$radius ?> * 1000)), autoFetch: <?= (!$location || $hasResolvedSearchCoords) ? 'true' : 'false' ?> });

const greenIcon = SEPins.stay();
const purpleIcon = SEPins.attraction();
SEPins.legend(map, ['stay', 'attraction', 'place']);

<?php if ($type === 'accommodation'): ?>
// Plot accommodation pins
<?php foreach ($accommodations as $acc): ?>
<?php if ($acc['latitude'] && $acc['longitude']): ?>
L.marker(
    [<?= $acc['latitude'] ?>, <?= $acc['longitude'] ?>],
    { icon: greenIcon }
).addTo(map).bindPopup(`
    <b><?= h($acc['title']) ?></b><br>
    KES <?= number_format($acc['price_per_night']) ?>/night<br>
    ★ <?= number_format($acc['avg_rating'], 1) ?><br>
    <a href="details.php?id=<?= $acc['accommodation_id'] ?>">
        View stay
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
    <b><?= h($selected_attraction['name']) ?></b><br>
    <?= h($selected_attraction['location_name']) ?>
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
    <b><?= h($att['name']) ?></b><br>
    <?= h($att['category']) ?><br>
    <a href="search.php?type=accommodation&attraction_id=<?= $att['attraction_id'] ?>">
        Find nearby stays
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
        } else {
            const liveButton = document.getElementById('osmSearchPlaces');
            if (liveButton) liveButton.click();
        }
    } else {
        const liveButton = document.getElementById('osmSearchPlaces');
        if (liveButton) liveButton.click();
    }
})
.catch(err => { console.error('Geocoding error:', err); const liveButton = document.getElementById('osmSearchPlaces'); if (liveButton) liveButton.click(); });
<?php endif; ?>
</script>

</body>
</html>