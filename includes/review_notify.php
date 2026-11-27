<?php
/**
 * v9.38.1: a host is told when a guest review about one of their listings becomes visible (reviews stay hidden until revealed or approved,
 * so the moment that matters is publication). Each review is announced once. The first run only marks reviews that are already
 * published, so nobody gets a flood about old ones. Safe to run repeatedly; never throws.
 */
if (!function_exists('notifyHostsOfNewReviews')) {
    function notifyHostsOfNewReviews($pdo) {
        $sent = 0;
        try {
            if (!$pdo->query("SHOW COLUMNS FROM reviews LIKE 'host_notified_at'")->fetch()) {
                $pdo->exec("ALTER TABLE reviews ADD COLUMN host_notified_at DATETIME NULL DEFAULT NULL");
                $pdo->exec("UPDATE reviews SET host_notified_at = NOW() WHERE is_published = 1");
                return 0;
            }
            require_once __DIR__ . '/notifications.php';
            $rows = $pdo->query("SELECT r.review_id, r.rating, a.host_id, a.title FROM reviews r JOIN accommodations a ON a.accommodation_id = r.accommodation_id
                WHERE r.is_published = 1 AND r.host_notified_at IS NULL ORDER BY r.review_id LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                notify($pdo, (int)$r['host_id'], 'New review: ' . (int)$r['rating'] . ' star' . ((int)$r['rating'] === 1 ? '' : 's'),
                    'A guest reviewed "' . $r['title'] . '". Open Reviews to read it and reply.', 'host/reviews.php');
                $pdo->prepare('UPDATE reviews SET host_notified_at = NOW() WHERE review_id = ?')->execute([(int)$r['review_id']]);
                $sent++;
            }
        } catch (Throwable $e) { error_log('notifyHostsOfNewReviews: ' . $e->getMessage()); }
        return $sent;
    }
}