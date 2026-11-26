<?php
/**
 * Public host profile helpers (v9.33): the host's own introduction, response rate and guest reviews.
 * The introduction lives in users.host_intro (added automatically, or run database/migration_host_intro.sql).
 */
if (!function_exists('ensureHostIntroColumn')) {
    /** Adds users.host_intro if it is missing. Never fatal. */
    function ensureHostIntroColumn($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $has = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                                AND COLUMN_NAME = 'host_intro'")->fetchColumn();
            if (!(int)$has) {
                $pdo->exec("ALTER TABLE users ADD COLUMN host_intro TEXT NULL DEFAULT NULL");
            }
        } catch (Throwable $e) { /* run database/migration_host_intro.sql by hand */ }
    }
}

if (!function_exists('getHostIntro')) {
    /** The host's introduction text ('' when none). */
    function getHostIntro($pdo, $hostId) {
        ensureHostIntroColumn($pdo);
        try {
            $st = $pdo->prepare("SELECT host_intro FROM users WHERE user_id = ? LIMIT 1");
            $st->execute([(int)$hostId]);
            return trim((string)$st->fetchColumn());
        } catch (Throwable $e) { return ''; }
    }
}

if (!function_exists('hostResponseStats')) {
    /**
     * Response rate = enquiries the host answered / enquiries received.
     * An enquiry is a chat thread on one of the host's listings in which the guest wrote at least one message;
     * it counts as answered when the host wrote at least one message in it.
     * The rate is only shown once there are 3 or more enquiries (null before that), so one missed message does not read as 0%.
     * @return array ['enquiries' => int, 'answered' => int, 'rate' => int|null]
     */
    function hostResponseStats($pdo, $hostId) {
        $out = ['enquiries' => 0, 'answered' => 0, 'rate' => null];
        try {
            $st = $pdo->prepare("SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN EXISTS (SELECT 1 FROM messages m2 WHERE m2.thread_id = t.thread_id AND m2.sender_id = t.host_id) THEN 1 ELSE 0 END), 0) AS answered
                FROM message_threads t
                WHERE t.host_id = ?
                  AND EXISTS (SELECT 1 FROM messages m WHERE m.thread_id = t.thread_id AND m.sender_id = t.tourist_id)");
            $st->execute([(int)$hostId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $out['enquiries'] = (int)($row['total'] ?? 0);
            $out['answered']  = (int)($row['answered'] ?? 0);
            if ($out['enquiries'] >= 3) $out['rate'] = (int)round(100 * $out['answered'] / $out['enquiries']);
        } catch (Throwable $e) { /* messaging tables not installed */ }
        return $out;
    }
}

if (!function_exists('hostReviewSummary')) {
    /**
     * Published guest reviews across the host's approved listings.
     * @return array ['count' => int, 'avg' => float, 'items' => array]
     */
    function hostReviewSummary($pdo, $hostId, $limit = 10) {
        $out = ['count' => 0, 'avg' => 0.0, 'items' => []];
        $limit = max(1, min(50, (int)$limit));
        try {
            $st = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(AVG(r.rating), 0) AS a
                FROM reviews r JOIN accommodations a ON a.accommodation_id = r.accommodation_id
                WHERE a.host_id = ? AND a.status = 'approved' AND r.is_published = 1");
            $st->execute([(int)$hostId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $out['count'] = (int)($row['c'] ?? 0);
            $out['avg']   = (float)($row['a'] ?? 0);
            if ($out['count'] > 0) {
                $st = $pdo->prepare("SELECT r.rating, r.review_text, r.host_reply, r.host_reply_hidden, r.created_at,
                        a.accommodation_id, a.title, u.full_name
                    FROM reviews r
                    JOIN accommodations a ON a.accommodation_id = r.accommodation_id
                    JOIN users u ON u.user_id = r.tourist_id
                    WHERE a.host_id = ? AND a.status = 'approved' AND r.is_published = 1
                    ORDER BY r.created_at DESC LIMIT $limit");
                $st->execute([(int)$hostId]);
                $out['items'] = $st->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) { /* reviews table not installed */ }
        return $out;
    }
}

if (!function_exists('reviewerFirstName')) {
    /** First name only, for privacy. */
    function reviewerFirstName($fullName) {
        $name = trim(html_entity_decode((string)$fullName, ENT_QUOTES, 'UTF-8'));
        if ($name === '' || $name === 'Deleted user') return 'A guest';
        $parts = preg_split('/\s+/', $name);
        return $parts[0];
    }
}
