<?php
/**
 * Profile photos live in the DATABASE (users.profile_photo_data / profile_photo_mime), so they survive
 * re-extracting a new copy of the project (the uploads/ folder is not part of the zip or git).
 * The old file name in users.profile_photo is still kept and used as a fallback; any old photo found on
 * disk is copied into the database the first time it is requested.
 */
require_once __DIR__ . '/helpers.php';

if (!function_exists('ensureProfilePhotoColumns')) {
    /** Adds the two photo columns if missing (same as database/migration_profile_photo.sql). Never fatal. */
    function ensureProfilePhotoColumns($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $has = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                                AND COLUMN_NAME = 'profile_photo_data'")->fetchColumn();
            if (!(int)$has) {
                $pdo->exec("ALTER TABLE users
                            ADD COLUMN profile_photo_data MEDIUMBLOB NULL DEFAULT NULL,
                            ADD COLUMN profile_photo_mime VARCHAR(50) NULL DEFAULT NULL");
            }
        } catch (Throwable $e) { /* run database/migration_profile_photo.sql by hand */ }
    }
}

if (!function_exists('saveProfilePhotoToDb')) {
    /** Store the image bytes of a saved upload in the database. $filename is the file inside uploads/profiles. */
    function saveProfilePhotoToDb($pdo, $user_id, $filename, $mime) {
        ensureProfilePhotoColumns($pdo);
        $path = dirname(__DIR__) . '/uploads/profiles/' . basename((string)$filename);
        if (!is_file($path)) return false;
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') return false;
        try {
            $st = $pdo->prepare("UPDATE users SET profile_photo_data = ?, profile_photo_mime = ? WHERE user_id = ?");
            $st->bindValue(1, $bytes, PDO::PARAM_LOB);
            $st->bindValue(2, $mime);
            $st->bindValue(3, (int)$user_id, PDO::PARAM_INT);
            $st->execute();
            return true;
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('profilePhotoUrl')) {
    /** URL of a person's photo (served from the database). $version changes when the photo changes (cache busting). */
    function profilePhotoUrl($user_id, $version = '') {
        $base = function_exists('appBasePath') ? appBasePath() : '/smart_accommodation';
        return $base . '/profile_photo.php?id=' . (int)$user_id . ($version !== '' && $version !== null ? '&v=' . rawurlencode(substr(md5((string)$version), 0, 8)) : '');
    }
}

if (!function_exists('avatarHtml')) {
    /**
     * A round avatar: the profile photo when the person has one, otherwise their initial.
     * If the image fails to load the initial is shown instead.
     */
    function avatarHtml($user_id, $name, $photo, $size = 32, $extraClass = '') {
        $size = (int)$size;
        $initial = strtoupper(mb_substr(trim(html_entity_decode((string)$name, ENT_QUOTES, 'UTF-8')) ?: '?', 0, 1));
        $style = "width:{$size}px;height:{$size}px;font-size:" . max(11, (int)round($size * 0.42)) . "px";
        $img = '';
        if (!empty($photo)) {
            $img = '<img src="' . h(profilePhotoUrl($user_id, $photo)) . '" alt="' . h($name) . '" loading="lazy" '
                 . 'onerror="this.remove()">';
        }
        return '<span class="se-avatar ' . h($extraClass) . '" style="' . $style . '" title="' . h($name) . '">'
             . '<span class="se-avatar-initial">' . h($initial) . '</span>' . $img . '</span>';
    }
}

if (!function_exists('currentUserPhotoName')) {
    /** profile_photo file name of the signed-in user (one query per request) or null. */
    function currentUserPhotoName($pdo) {
        static $cache = [];
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if (!$uid) return null;
        if (!array_key_exists($uid, $cache)) {
            try {
                $st = $pdo->prepare("SELECT profile_photo, ((profile_photo IS NOT NULL AND profile_photo <> '')) AS has FROM users WHERE user_id = ?");
                $st->execute([$uid]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                $cache[$uid] = $r ? ($r['profile_photo'] ?: null) : null;
            } catch (Throwable $e) { $cache[$uid] = null; }
        }
        return $cache[$uid];
    }
}
