<?php
/**
 * Attraction ratings (v9.13).
 * Separate from stay reviews and NOT part of the Smart score: a host is not responsible for a day out.
 * Only a tourist with a COMPLETED booking at an approved stay within ATTRACTION_RATING_RADIUS_KM of the
 * attraction may rate it. One rating per tourist per attraction (they can change it later).
 * Same as database/migration_attraction_reviews.sql; the table is created automatically if missing.
 */
require_once __DIR__ . '/functions.php';

if (!defined('ATTRACTION_RATING_RADIUS_KM')) define('ATTRACTION_RATING_RADIUS_KM', 50);

if (!function_exists('ensureAttractionReviewsTable')) {
    function ensureAttractionReviewsTable($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS attraction_reviews (
                review_id INT AUTO_INCREMENT PRIMARY KEY,
                attraction_id INT NOT NULL,
                tourist_id INT NOT NULL,
                rating TINYINT NOT NULL,
                comment TEXT NULL,
                is_hidden TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_att_tourist (attraction_id, tourist_id),
                KEY idx_att (attraction_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('attractionRatingsMap')) {
    /** [attraction_id => ['avg' => float, 'count' => int]] for visible ratings. Attractions with none are absent. */
    function attractionRatingsMap($pdo, array $ids) {
        ensureAttractionReviewsTable($pdo);
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT attraction_id, AVG(rating) AS avg_r, COUNT(*) AS n FROM attraction_reviews
                                 WHERE is_hidden = 0 AND attraction_id IN ($in) GROUP BY attraction_id");
            $st->execute($ids);
            $map = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int)$r['attraction_id']] = ['avg' => (float)$r['avg_r'], 'count' => (int)$r['n']];
            return $map;
        } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('attractionRatingLabel')) {
    /** "★ 4.6 (12)" or "No ratings yet" (never a fake zero). */
    function attractionRatingLabel($summary) {
        if (!$summary || empty($summary['count'])) return 'No ratings yet';
        return '★ ' . number_format($summary['avg'], 1) . ' (' . (int)$summary['count'] . ')';
    }
}

if (!function_exists('canRateAttraction')) {
    /** True when this tourist has a completed booking at an approved stay close to the attraction. */
    function canRateAttraction($pdo, $touristId, array $attraction) {
        if ($attraction['latitude'] === null || $attraction['longitude'] === null) return false;
        try {
            $st = $pdo->prepare("SELECT " . haversineSql('a.latitude', 'a.longitude', ':lat', ':lng', ':lat2') . " AS d
                FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id
                WHERE b.tourist_id = :tid AND b.status = 'completed' AND a.status = 'approved'
                  AND a.latitude IS NOT NULL AND a.longitude IS NOT NULL
                HAVING d <= :radius LIMIT 1");
            $st->execute([':lat' => (float)$attraction['latitude'], ':lng' => (float)$attraction['longitude'], ':lat2' => (float)$attraction['latitude'],
                          ':tid' => (int)$touristId, ':radius' => ATTRACTION_RATING_RADIUS_KM]);
            return (bool)$st->fetch();
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('saveAttractionRating')) {
    function saveAttractionRating($pdo, $attractionId, $touristId, $rating, $comment) {
        ensureAttractionReviewsTable($pdo);
        $rating = max(1, min(5, (int)$rating));
        $comment = mb_substr(trim((string)$comment), 0, 500);
        $st = $pdo->prepare("INSERT INTO attraction_reviews (attraction_id, tourist_id, rating, comment) VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment)");
        $st->execute([(int)$attractionId, (int)$touristId, $rating, $comment !== '' ? $comment : null]);
    }
}
