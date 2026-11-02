<?php
/**
 * Extra review features (same as database/migration_review_extras.sql; columns are added automatically if missing):
 *  - reviews: four category scores a guest gives (cleanliness, location, comfort, value)
 *  - reviews.host_reply_hidden: admin can hide a host's public reply
 *  - guest_reviews.is_hidden: admin can hide a host's review of a guest
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/profile_photo.php';

if (!function_exists('ensureReviewExtraColumns')) {
    function ensureReviewExtraColumns($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        $need = [
            ['reviews', 'cleanliness_rating', 'TINYINT NULL DEFAULT NULL'],
            ['reviews', 'location_rating',    'TINYINT NULL DEFAULT NULL'],
            ['reviews', 'comfort_rating',     'TINYINT NULL DEFAULT NULL'],
            ['reviews', 'value_rating',       'TINYINT NULL DEFAULT NULL'],
            ['reviews', 'host_reply_hidden',  'TINYINT(1) NOT NULL DEFAULT 0'],
            ['guest_reviews', 'is_hidden',    'TINYINT(1) NOT NULL DEFAULT 0'],
            ['guest_reviews', 'hidden_at',    'DATETIME NULL DEFAULT NULL'],
        ];
        foreach ($need as [$t, $c, $def]) {
            try {
                $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
                $q->execute([$t, $c]);
                if (!(int)$q->fetchColumn()) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
            } catch (Throwable $e) { /* run database/migration_review_extras.sql by hand */ }
        }
    }
}

if (!function_exists('reviewCategoryLabels')) {
    function reviewCategoryLabels() {
        return ['cleanliness_rating' => 'Cleanliness', 'location_rating' => 'Location',
                'comfort_rating' => 'Comfort', 'value_rating' => 'Value'];
    }
}

if (!function_exists('personChipHtml')) {
    /** Round photo (or initial) + full name that links to that person's profile. */
    function personChipHtml($user_id, $full_name, $photo, $size = 40, $sub = '') {
        $base = function_exists('appBasePath') ? appBasePath() : '/smart_accommodation';
        $url  = $base . '/view_profile.php?id=' . (int)$user_id;
        return '<a href="' . h($url) . '" class="person-chip" title="View profile" style="display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:inherit">'
             . avatarHtml((int)$user_id, $full_name, $photo, (int)$size)
             . '<span><strong style="display:block;font-size:14px">' . h($full_name) . '</strong>'
             . ($sub !== '' ? '<span style="display:block;font-size:12px;color:#68776f">' . h($sub) . '</span>' : '')
             . '</span></a>';
    }
}

if (!function_exists('categoryBarsHtml')) {
    /** Small bars for a single review's category scores; empty string when the review has none. */
    function categoryBarsHtml(array $r) {
        $out = '';
        foreach (reviewCategoryLabels() as $col => $label) {
            if (empty($r[$col])) continue;
            $v = (int)$r[$col];
            $out .= '<div style="display:grid;grid-template-columns:90px minmax(0,1fr) 22px;gap:8px;align-items:center;font-size:12px;margin:3px 0">'
                  . '<span style="color:#6b6f68">' . h($label) . '</span>'
                  . '<div style="height:6px;background:#e8e5dc;border-radius:99px;overflow:hidden"><span style="display:block;height:100%;width:' . ($v * 20) . '%;background:#c0623a"></span></div>'
                  . '<strong style="text-align:right">' . $v . '</strong></div>';
        }
        return $out === '' ? '' : '<div style="max-width:320px;margin:8px 0">' . $out . '</div>';
    }
}
