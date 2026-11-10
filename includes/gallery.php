<?php
/**
 * Shared photo helpers: several photos per attraction, plus the slideshow markup used on
 * search cards, stay pages and attraction pages (assets/js/slideshow.js + .se-slider CSS).
 * Same as database/migration_attraction_photos.sql; the table is created automatically if missing.
 */
require_once __DIR__ . '/helpers.php';

if (!function_exists('ensureAttractionPhotosTable')) {
    function ensureAttractionPhotosTable($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS attraction_photos (
                photo_id INT AUTO_INCREMENT PRIMARY KEY,
                attraction_id INT NOT NULL,
                photo_path VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_att (attraction_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('getAttractionPhotoNames')) {
    /** Cover photo first, then the extra photos. Returns file names (not URLs). */
    function getAttractionPhotoNames($pdo, $attraction_id, $cover = null) {
        ensureAttractionPhotosTable($pdo);
        $names = [];
        if ($cover === null) {
            $c = $pdo->prepare("SELECT photo_path FROM attractions WHERE attraction_id = ?");
            $c->execute([(int)$attraction_id]);
            $cover = $c->fetchColumn();
        }
        if ($cover) $names[] = $cover;
        try {
            $q = $pdo->prepare("SELECT photo_path FROM attraction_photos WHERE attraction_id = ? ORDER BY sort_order, photo_id");
            $q->execute([(int)$attraction_id]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $n) if (!in_array($n, $names, true)) $names[] = $n;
        } catch (Throwable $e) {}
        return $names;
    }
}

if (!function_exists('saveAttractionPhotos')) {
    /** Handles photos[] uploads and remove_photos[] for one attraction. Returns an array of error strings. */
    function saveAttractionPhotos($pdo, $aid, array $files, array $remove, $uploadDir) {
        ensureAttractionPhotosTable($pdo);
        $errors = [];
        $aid = (int)$aid;

        // Removals
        foreach ($remove as $name) {
            $name = basename((string)$name);
            if ($name === '') continue;
            $pdo->prepare("DELETE FROM attraction_photos WHERE attraction_id = ? AND photo_path = ?")->execute([$aid, $name]);
            $pdo->prepare("UPDATE attractions SET photo_path = NULL WHERE attraction_id = ? AND photo_path = ?")->execute([$aid, $name]);
            if (!$pdo->query("SELECT COUNT(*) FROM attractions WHERE photo_path = " . $pdo->quote($name))->fetchColumn()
                && !$pdo->query("SELECT COUNT(*) FROM attraction_photos WHERE photo_path = " . $pdo->quote($name))->fetchColumn()) {
                @unlink(rtrim($uploadDir, '/') . '/' . $name);
                try { $pdo->prepare("DELETE FROM attraction_photo_credits WHERE photo_path = ?")->execute([$name]); } catch (Throwable $e) {}
            }
        }

        // New uploads (several at once)
        $saved = [];
        if (!empty($files['name']) && is_array($files['name'])) {
            $n = min(count($files['name']), 12);
            for ($i = 0; $i < $n; $i++) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                if ($files['error'][$i] !== UPLOAD_ERR_OK) { $errors[] = 'One photo failed to upload.'; continue; }
                $tmp = $files['tmp_name'][$i];
                $type = mime_content_type($tmp);
                if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) { $errors[] = h($files['name'][$i]) . ' must be JPG, PNG or WebP.'; continue; }
                if ($files['size'][$i] > 5 * 1024 * 1024) { $errors[] = h($files['name'][$i]) . ' is over 5MB.'; continue; }
                $ext = $type === 'image/png' ? 'png' : ($type === 'image/webp' ? 'webp' : 'jpg');
                $fn = 'att_' . uniqid() . $i . '.' . $ext;
                if (move_uploaded_file($tmp, rtrim($uploadDir, '/') . '/' . $fn)) $saved[] = $fn;
            }
        }
        foreach ($saved as $k => $fn) {
            $pdo->prepare("INSERT INTO attraction_photos (attraction_id, photo_path, sort_order) VALUES (?, ?, ?)")->execute([$aid, $fn, $k]);
        }

        // Make sure there is always a cover when any photo exists
        $cover = $pdo->prepare("SELECT photo_path FROM attractions WHERE attraction_id = ?");
        $cover->execute([$aid]);
        if (!$cover->fetchColumn()) {
            $first = $pdo->prepare("SELECT photo_path FROM attraction_photos WHERE attraction_id = ? ORDER BY sort_order, photo_id LIMIT 1");
            $first->execute([$aid]);
            if ($f = $first->fetchColumn()) {
                $pdo->prepare("UPDATE attractions SET photo_path = ? WHERE attraction_id = ?")->execute([$f, $aid]);
                $pdo->prepare("DELETE FROM attraction_photos WHERE attraction_id = ? AND photo_path = ?")->execute([$aid, $f]);
            }
        }
        return $errors;
    }
}

if (!function_exists('slideshowHtml')) {
    /**
     * @param string[] $urls  image URLs (first one is shown first)
     * @param string   $alt
     * @param string   $variant 'card' (small, arrows on hover) or 'hero' (large, thumbnails + autoplay)
     * @param string   $overlay extra HTML placed on top of the picture (badges)
     */
    function slideshowHtml(array $urls, $alt, $variant = 'card', $overlay = '') {
        $urls = array_values(array_filter(array_unique($urls)));
        if (!$urls) return '';
        $many = count($urls) > 1;
        $out  = '<div class="se-slider se-' . h($variant) . '" data-slider' . ($variant === 'hero' ? ' data-autoplay="6000"' : '') . '>';
        $out .= '<div class="se-track">';
        foreach ($urls as $i => $u) {
            $out .= '<img class="se-slide' . ($i === 0 ? ' is-active' : '') . '" src="' . h($u) . '" alt="' . h($alt) . ($many ? ' — photo ' . ($i + 1) : '')
                  . '" loading="' . ($i === 0 ? 'eager' : 'lazy') . '" onerror="this.onerror=null;this.style.visibility=\'hidden\'">';
        }
        $out .= '</div>';
        if ($many) {
            $out .= '<button type="button" class="se-nav se-prev" aria-label="Previous photo">&#8249;</button>'
                  . '<button type="button" class="se-nav se-next" aria-label="Next photo">&#8250;</button>'
                  . '<span class="se-count">1 / ' . count($urls) . '</span><div class="se-dots">';
            foreach ($urls as $i => $u) $out .= '<button type="button" class="se-dot' . ($i === 0 ? ' is-active' : '') . '" aria-label="Photo ' . ($i + 1) . '"></button>';
            $out .= '</div>';
        }
        $out .= $overlay . '</div>';
        if ($many && $variant === 'hero') {
            $out .= '<div class="se-thumbs" data-thumbs>';
            foreach ($urls as $i => $u) $out .= '<button type="button" class="se-thumb' . ($i === 0 ? ' is-active' : '') . '" aria-label="Show photo ' . ($i + 1) . '"><img src="' . h($u) . '" alt="" loading="lazy"></button>';
            $out .= '</div>';
        }
        return $out;
    }
}
