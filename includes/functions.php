<?php
require_once 'db.php';

// Haversine formula — get accommodations within radius
function getNearbyAccommodations($lat, $lng, $radius = 10, $filters = []) {
    global $pdo;

    $sql = "
        SELECT a.*, 
        (6371 * acos(
            cos(radians(:lat)) * cos(radians(a.latitude)) * 
            cos(radians(a.longitude) - radians(:lng)) + 
            sin(radians(:lat2)) * sin(radians(a.latitude))
        )) AS distance,
        COALESCE(AVG(r.rating), 0) AS avg_rating,
        COUNT(DISTINCT b.booking_id) AS total_bookings
        FROM accommodations a
        LEFT JOIN reviews r 
            ON r.accommodation_id = a.accommodation_id 
            AND r.is_published = 1
        LEFT JOIN bookings b 
            ON b.accommodation_id = a.accommodation_id 
            AND b.status = 'completed'
        WHERE a.status = 'approved'
    ";

    $params = [
        ':lat'  => $lat,
        ':lng'  => $lng,
        ':lat2' => $lat
    ];

    // Apply filters
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

    $sql .= " GROUP BY a.accommodation_id HAVING distance < :radius";
    $params[':radius'] = $radius;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    // Apply weighted scoring
    return applyWeightedScoring($results);
}

// Weighted scoring algorithm
function applyWeightedScoring($results) {
    if (empty($results)) return [];

    $max_distance   = max(array_column($results, 'distance')) ?: 1;
    $max_price      = max(array_column($results, 'price_per_night')) ?: 1;
    $max_bookings   = max(array_column($results, 'total_bookings')) ?: 1;

    foreach ($results as &$item) {
        $rating_score   = ($item['avg_rating'] / 5);
        $distance_score = 1 - ($item['distance'] / $max_distance);
        $price_score    = 1 - ($item['price_per_night'] / $max_price);
        $popularity     = ($item['total_bookings'] / $max_bookings);

        $item['smart_score'] = round(
            ($rating_score   * 0.4) +
            ($distance_score * 0.3) +
            ($price_score    * 0.2) +
            ($popularity     * 0.1),
            3
        );
    }

    // Sort by smart score descending
    usort($results, fn($a, $b) => $b['smart_score'] <=> $a['smart_score']);

    return $results;
}

// Get nearby attractions for a given coordinate
function getNearbyAttractions($lat, $lng, $radius = 10) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT *, 
        (6371 * acos(
            cos(radians(:lat)) * cos(radians(latitude)) * 
            cos(radians(longitude) - radians(:lng)) + 
            sin(radians(:lat2)) * sin(radians(latitude))
        )) AS distance
        FROM attractions
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
    $hours_since_checkout = 
        (time() - strtotime($booking['check_out_date'])) / 3600;
    if ($hours_since_checkout < 1) $score += 2;

    // Check review length
    if (str_word_count($review_text) < 10) $score += 1;

    return $score;
}

// Sanitize input
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
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
    $colors = [
        'success' => '#1D9E75',
        'error'   => '#e74c3c',
        'warning' => '#f39c12',
        'info'    => '#3498db'
    ];
    $color = $colors[$flash['type']] ?? '#333';
    return "<div style='background:$color;color:white;padding:12px 16px;
                        border-radius:6px;margin-bottom:16px'>
                {$flash['message']}
            </div>";
}
?>