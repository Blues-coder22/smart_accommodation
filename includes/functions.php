<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/host_verification.php';
require_once __DIR__ . '/db.php';

/** Return this project's URL path relative to the web document root. */
if (!function_exists('appBasePath')) {
    function appBasePath() {
        $projectRoot = realpath(dirname(__DIR__));
        $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($projectRoot && $documentRoot) {
            $project = str_replace('\\', '/', $projectRoot);
            $document = rtrim(str_replace('\\', '/', $documentRoot), '/');
            if (stripos($project, $document . '/') === 0) {
                return '/' . trim(substr($project, strlen($document)), '/');
            }
            if (strcasecmp($project, $document) === 0) return '';
        }
        // Fallback for common XAMPP installs.
        return '/smart_accommodation';
    }
}

// Great-circle distance in km between two coordinates, as a SQL expression (Haversine formula):
//   a = sin^2(dLat/2) + cos(lat1) * cos(lat2) * sin^2(dLng/2)
//   d = 2 * R * asin(sqrt(a)),  R = 6371 km (mean Earth radius)
// $latParam and $lat2Param must both be bound to the search latitude: PDO named placeholders cannot
// be repeated in one statement, and the latitude is needed twice (for dLat and for cos(lat1)).
// LEAST(1, ...) keeps rounding from pushing the value passed to asin() just past 1.
function haversineSql($latCol, $lngCol, $latParam, $lngParam, $lat2Param) {
    return "(2 * 6371 * ASIN(LEAST(1, SQRT(
            POW(SIN(RADIANS($latCol - $latParam) / 2), 2) +
            COS(RADIANS($lat2Param)) * COS(RADIANS($latCol)) *
            POW(SIN(RADIANS($lngCol - $lngParam) / 2), 2)
        ))))";
}

// True if the stay cannot be booked for these nights: another booking overlaps, or the host blocked a night.
// Dates are 'Y-m-d' strings; check-out day itself is not a night.
function isStayUnavailable($pdo, $accommodation_id, $check_in, $check_out) {
    $stmt = $pdo->prepare("
        SELECT
          (SELECT COUNT(*) FROM bookings
            WHERE accommodation_id = ? AND status IN ('pending','confirmed')
              AND NOT (check_out_date <= ? OR check_in_date >= ?))
        +
          (SELECT COUNT(*) FROM availability
            WHERE accommodation_id = ? AND is_available = 0
              AND `date` >= ? AND `date` < ?)
    ");
    $stmt->execute([$accommodation_id, $check_in, $check_out,
                    $accommodation_id, $check_in, $check_out]);
    return (int)$stmt->fetchColumn() > 0;
}

// Valid 'Y-m-d' date string or null.
function validDateOrNull($value) {
    $d = DateTime::createFromFormat('Y-m-d', (string)$value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

// Haversine formula — get accommodations within radius
// $filters: min_price, max_price, property_type, guests, check_in, check_out, amenities[]
function getNearbyAccommodations($lat, $lng, $radius = 10, $filters = []) {
    global $pdo;

    $distance = haversineSql('a.latitude', 'a.longitude', ':lat', ':lng', ':lat2');

    // Rating and booking counts come from subqueries so the joins cannot multiply each other's rows
    // (a join of reviews and bookings made every review count once per completed booking).
    $sql = "
        SELECT a.*,
        $distance AS distance,
        COALESCE((SELECT AVG(r.rating) FROM reviews r
                  WHERE r.accommodation_id = a.accommodation_id
                  AND r.is_published = 1), 0) AS avg_rating,
        (SELECT COUNT(*) FROM bookings b
         WHERE b.accommodation_id = a.accommodation_id
         AND b.status = 'completed') AS total_bookings
        FROM accommodations a
        WHERE a.status = 'approved'
        AND a.latitude IS NOT NULL AND a.longitude IS NOT NULL
    ";

    $params = [
        ':lat'  => $lat,
        ':lng'  => $lng,
        ':lat2' => $lat
    ];

    if (!empty($filters['min_price'])) {
        $sql .= " AND a.price_per_night >= :min_price";
        $params[':min_price'] = $filters['min_price'];
    }
    if (!empty($filters['max_price'])) {
        $sql .= " AND a.price_per_night <= :max_price";
        $params[':max_price'] = $filters['max_price'];
    }
    if (!empty($filters['property_type'])) {
        $sql .= " AND a.property_type = :property_type";
        $params[':property_type'] = $filters['property_type'];
    }

    // Enough room for the party
    if (!empty($filters['guests']) && (int)$filters['guests'] > 1) {
        $sql .= " AND a.max_guests >= :guests";
        $params[':guests'] = (int)$filters['guests'];
    }

    // Free for the whole stay: no overlapping booking and no night blocked by the host
    $ci = validDateOrNull($filters['check_in']  ?? '');
    $co = validDateOrNull($filters['check_out'] ?? '');
    if ($ci && $co && $co > $ci) {
        $sql .= " AND NOT EXISTS (
                    SELECT 1 FROM bookings bk
                    WHERE bk.accommodation_id = a.accommodation_id
                    AND bk.status IN ('pending','confirmed')
                    AND NOT (bk.check_out_date <= :ci1 OR bk.check_in_date >= :co1))
                  AND NOT EXISTS (
                    SELECT 1 FROM availability av
                    WHERE av.accommodation_id = a.accommodation_id
                    AND av.is_available = 0
                    AND av.`date` >= :ci2 AND av.`date` < :co2)";
        $params[':ci1'] = $ci; $params[':co1'] = $co;
        $params[':ci2'] = $ci; $params[':co2'] = $co;
    }

    // Must offer every selected amenity
    $amenities = array_values(array_filter((array)($filters['amenities'] ?? []), 'is_string'));
    foreach ($amenities as $i => $amenity) {
        $sql .= " AND EXISTS (SELECT 1 FROM amenities am$i
                              WHERE am$i.accommodation_id = a.accommodation_id
                              AND am$i.amenity_name = :amenity$i)";
        $params[":amenity$i"] = $amenity;
    }

    $sql .= " HAVING distance < :radius";
    $params[':radius'] = $radius;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // Apply weighted scoring
    return applyWeightedScoring($results);
}

// Weighted scoring algorithm
function applyWeightedScoring($results, $useDistance = null) {
    if (empty($results)) return [];

    // Distance only counts when it means something: a real place was searched and results are at different distances.
    // Otherwise it is left out and the other weights are scaled up, so every score stays comparable.
    $real = array_filter(array_column($results, 'distance'), function ($d) { return $d !== null && (float)$d < 99999; });
    if ($useDistance === null) $useDistance = count($real) > 1 && max($real) > min($real);
    $max_distance   = $real ? max($real) : 1;
    if ($max_distance <= 0) $max_distance = 1;
    $max_price      = max(array_column($results, 'price_per_night')) ?: 1;
    $max_bookings   = max(array_column($results, 'total_bookings')) ?: 1;

    foreach ($results as &$item) {
        $rating_score   = ($item['avg_rating'] / 5);
        $price_score    = 1 - ($item['price_per_night'] / $max_price);
        $popularity     = ($item['total_bookings'] / $max_bookings);
        if ($useDistance) {
            $d = (float)$item['distance'];
            $distance_score = $d >= 99999 ? 0 : 1 - ($d / $max_distance);
            $score = ($rating_score * 0.4) + ($distance_score * 0.3) + ($price_score * 0.2) + ($popularity * 0.1);
        } else {
            $score = (($rating_score * 0.4) + ($price_score * 0.2) + ($popularity * 0.1)) / 0.7;
        }
        $item['smart_score'] = round($score, 3);
    }
    unset($item);

    // Sort by smart score descending
    usort($results, function ($a, $b) { return $b['smart_score'] <=> $a['smart_score']; });

    return $results;
}

// Get nearby attractions for a given coordinate
function getNearbyAttractions($lat, $lng, $radius = 10) {
    global $pdo;

    $distance = haversineSql('latitude', 'longitude', ':lat', ':lng', ':lat2');

    $stmt = $pdo->prepare("
        SELECT *,
        $distance AS distance
        FROM attractions
        WHERE latitude IS NOT NULL AND longitude IS NOT NULL
        HAVING distance < :radius
        ORDER BY distance ASC
    ");
    $stmt->execute([
        ':lat'    => $lat,
        ':lng'    => $lng,
        ':lat2'   => $lat,
        ':radius' => $radius
    ]);
    return $stmt->fetchAll();
}

// Publish reviews whose reveal condition is met. A guest's review stays sealed until the host has
// also reviewed OR 14 days have passed since check-out, whichever comes first. Nothing else ever
// published a sealed review later, so without this a review written early would stay hidden forever.
// Flagged reviews are never published here: those are decided by an admin.
// Runs at most once every 5 minutes (a timestamp file avoids hitting the database on every page).
function publishDueReviews($pdo) {
    $stamp = __DIR__ . '/../logs/.review_sweep';
    if (is_file($stamp) && (time() - filemtime($stamp)) < 300) return 0;
    $dir = dirname($stamp);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @touch($stamp);

    // A review is revealed once the host has reviewed the guest for the same stay (host_submitted, or a row in
    // guest_reviews), or 14 days after check-out. Flagged reviews always wait for an administrator.
    $base = "
        UPDATE reviews r
        JOIN bookings b ON b.booking_id = r.booking_id
        SET r.is_published = 1
        WHERE r.tourist_submitted = 1
          AND r.is_published = 0
          AND r.is_flagged = 0
          AND (r.host_submitted = 1
               %s
               OR b.check_out_date <= DATE_SUB(CURDATE(), INTERVAL 14 DAY))
    ";
    try {
        $stmt = $pdo->prepare(sprintf($base, "OR EXISTS (SELECT 1 FROM guest_reviews g WHERE g.booking_id = r.booking_id)"));
        $stmt->execute();
    } catch (Throwable $e) {
        // guest_reviews not installed yet (migration_002_features.sql): fall back to the original rule.
        $stmt = $pdo->prepare(sprintf($base, ""));
        $stmt->execute();
    }
    return $stmt->rowCount();
}

/**
 * Reputation of a guest as seen by hosts: average and count of the ratings hosts gave them.
 * Only revealed ratings count (the guest has reviewed that stay, or 14 days have passed since check-out),
 * which keeps host and guest reviews sealed and fair. Returns ['avg' => float|null, 'count' => int].
 */
function getGuestReputation($pdo, $tourist_id) {
    try {
        require_once __DIR__ . '/review_extras.php';
        ensureReviewExtraColumns($pdo);
        $stmt = $pdo->prepare("
            SELECT AVG(g.rating) AS avg_rating, COUNT(*) AS n
            FROM guest_reviews g
            JOIN bookings b ON b.booking_id = g.booking_id
            LEFT JOIN reviews r ON r.booking_id = g.booking_id
            WHERE g.tourist_id = ? AND g.is_hidden = 0
              AND (r.tourist_submitted = 1 OR b.check_out_date <= DATE_SUB(CURDATE(), INTERVAL 14 DAY))
        ");
        $stmt->execute([(int)$tourist_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['avg' => $row && $row['n'] ? round((float)$row['avg_rating'], 1) : null, 'count' => (int)($row['n'] ?? 0)];
    } catch (Throwable $e) {
        return ['avg' => null, 'count' => 0];
    }
}

// Anomaly detection scoring
function calculateSuspicionScore($tourist_id, $booking_id, 
                                  $rating, $review_text, $pdo) {
    $score = 0;

    // Check account age
    $stmt = $pdo->prepare(
        "SELECT created_at FROM users WHERE user_id = ?"
    );
    $stmt->execute([$tourist_id]);
    $user = $stmt->fetch();
    $account_age_days = (time() - strtotime($user['created_at'])) / 86400;
    if ($account_age_days < 7) $score += 2;

    // Check review history
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM reviews WHERE tourist_id = ?"
    );
    $stmt->execute([$tourist_id]);
    if ($stmt->fetchColumn() == 0) $score += 2;

    // Check extreme rating
    if ($rating == 1 || $rating == 5) $score += 1;

    // Check submission timing vs checkout
    $stmt = $pdo->prepare(
        "SELECT check_out_date FROM bookings WHERE booking_id = ?"
    );
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch();
    // check_out_date is a date with no time, so measuring from it means "midnight at the start of checkout
    // day" and the gap is always many hours. Measure from the standard checkout time (12:00) instead.
    // A review sent before checkout, or within 3 hours after it, is too quick to be a real stay review.
    if ($booking && !empty($booking['check_out_date'])) {
        $checkout_at = strtotime($booking['check_out_date'] . ' 12:00:00');
        $hours_since_checkout = (time() - $checkout_at) / 3600;
        if ($hours_since_checkout < 3) $score += 2;
    }

    // Check review length
    if (str_word_count($review_text) < 10) $score += 1;

    return $score;
}

// Safe file extension for an uploaded image, decided from its real content (never from the user's file name).
function imageExtFor($tmp_path) {
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = @mime_content_type($tmp_path);
    return $map[$mime] ?? null;
}

// Sanitize input
function sanitize($input) {
    $text = trim((string)$input);
    // Make it safe to save the same text again after editing: decode earlier encoding first, so "&#039;" never becomes "&amp;#039;".
    for ($i = 0; $i < 3; $i++) {
        $decoded = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        if ($decoded === $text) break;
        $text = $decoded;
    }
    return htmlspecialchars(strip_tags($text));
}

// Format currency
function formatKES($amount) {
    return 'KES ' . number_format($amount, 2);
}

// Redirect
function redirect($url) {
    header("Location: $url");
    exit;
}

// Flash messages
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Display flash message as HTML
function showFlash() {
    $flash = getFlash();
    if (!$flash) return '';
    // Five clearly different looks: success, error, rejected, pending (waiting) and warning/info.
    $type  = in_array($flash['type'], ['success', 'error', 'rejected', 'pending', 'warning', 'info'], true) ? $flash['type'] : 'info';
    $icons = ['success' => '&#10003;', 'error' => '!', 'rejected' => '&#10005;', 'pending' => '&hellip;', 'warning' => '!', 'info' => 'i'];
    $role  = ($type === 'error' || $type === 'rejected') ? 'alert' : 'status';
    return "<div class='flash flash-$type' role='$role'><span class='flash-icon' aria-hidden='true'>{$icons[$type]}</span>"
         . "<div class='flash-text'>{$flash['message']}</div></div>";
}


// CSRF protection for state-changing form submissions.
function generateCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && is_string($token)
        && $token !== ''
        && hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrf() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('This page has expired. Please go back, refresh it and try again.');
    }
}
?>