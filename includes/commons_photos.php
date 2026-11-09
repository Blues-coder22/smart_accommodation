<?php
/**
 * Wikimedia Commons photo finder for attractions.
 *
 * - commonsSearch()        suggests free-licensed photos by name and by location
 * - commonsInfoForTitles() re-reads the details (credit, licence) of chosen files straight from Commons
 * - importCommonsPhotos()  downloads chosen photos into uploads/attractions and stores the credit
 * - getAttractionPhotoCredits()/attractionCreditsHtml() show the credit on the attraction page
 *
 * The credit lives in its own table keyed by the saved file name, so it follows the photo even when
 * it becomes the cover (the cover is stored in attractions.photo_path, not in attraction_photos).
 * Same as database/migration_attraction_photo_credits.sql; the table is created automatically if missing.
 */
require_once __DIR__ . '/gallery.php';

const COMMONS_API = 'https://commons.wikimedia.org/w/api.php';
const COMMONS_UA  = 'StayExploreKenya/1.0 (student project; admin photo finder)';

if (!function_exists('ensureAttractionPhotoCreditsTable')) {
    function ensureAttractionPhotoCreditsTable($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS attraction_photo_credits (
                photo_path  VARCHAR(255) NOT NULL PRIMARY KEY,
                credit      VARCHAR(255) NOT NULL,
                license     VARCHAR(120) NULL,
                license_url VARCHAR(500) NULL,
                source_url  VARCHAR(500) NULL,
                source_name VARCHAR(60)  NOT NULL DEFAULT 'Wikimedia Commons',
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}

/** GET a URL with a proper User-Agent (Wikimedia requires one). Returns [body|null, errorMessage]. */
function commonsHttpGet($url, $timeout = 20) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => COMMONS_UA, CURLOPT_HTTPHEADER => ['Accept: */*'],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 300) return [$body, ''];
        if ($errno === 60 || $errno === 77) {
            return [null, 'This computer could not verify the Wikimedia certificate (PHP has no certificate list). '
                . 'In XAMPP, download cacert.pem from curl.se/docs/caextract.html, then set curl.cainfo and openssl.cafile '
                . 'in php.ini to its full path and restart Apache.'];
        }
        if ($body === false) return [null, 'Could not reach Wikimedia Commons (' . ($err ?: 'network error') . ').'];
        return [null, 'Wikimedia Commons answered with error ' . $code . '.'];
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => 'User-Agent: ' . COMMONS_UA . "\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? [null, 'Could not reach Wikimedia Commons.'] : [$body, ''];
}

function commonsQuery(array $params) {
    $params += ['action' => 'query', 'format' => 'json', 'formatversion' => '2'];
    [$body, $err] = commonsHttpGet(COMMONS_API . '?' . http_build_query($params));
    if ($body === null) return [null, $err];
    $json = json_decode($body, true);
    if (!is_array($json)) return [null, 'Wikimedia Commons sent an unreadable answer.'];
    return [$json, ''];
}

function commonsCleanText($html, $max = 160) {
    $t = html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    return function_exists('mb_substr') ? mb_substr($t, 0, $max, 'UTF-8') : substr($t, 0, $max);
}

/** Turns one Commons "page" entry into a small array, or null if it is not a usable free photo. */
function commonsPageToPhoto(array $page, $thumbWidthKey = 'thumburl') {
    $ii = $page['imageinfo'][0] ?? null;
    if (!$ii) return null;
    if (!in_array($ii['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
    $m = $ii['extmetadata'] ?? [];
    $val = fn($k) => (string)($m[$k]['value'] ?? '');
    if (strtolower($val('NonFree')) === 'true' || trim($val('Restrictions')) !== '') return null;
    $license = commonsCleanText($val('LicenseShortName'), 80);
    if ($license === '') return null;          // no clear licence = not offered
    $artist = commonsCleanText($val('Artist'), 150);
    if ($artist === '') $artist = commonsCleanText($val('Credit'), 150);
    if ($artist === '') $artist = 'Unknown author';
    $licUrl = $val('LicenseUrl');
    if (!preg_match('~^https://~i', $licUrl)) $licUrl = '';
    return [
        'title'       => (string)$page['title'],
        'thumb'       => (string)($ii['thumburl'] ?? $ii['url'] ?? ''),
        'artist'      => $artist,
        'license'     => $license,
        'license_url' => $licUrl,
        'page_url'    => (string)($ii['descriptionurl'] ?? ''),
        'width'       => (int)($ii['width'] ?? 0),
        'height'      => (int)($ii['height'] ?? 0),
        'size'        => (int)($ii['size'] ?? 0),
        'description' => commonsCleanText($val('ImageDescription'), 140),
    ];
}

function commonsImageProps($thumbWidth) {
    return [
        'prop' => 'imageinfo',
        'iiprop' => 'url|mime|size|extmetadata',
        'iiurlwidth' => (string)$thumbWidth,
        'iiextmetadatafilter' => 'Artist|Credit|LicenseShortName|LicenseUrl|ImageDescription|NonFree|Restrictions',
    ];
}

/**
 * Suggest photos: first those that match the place name, then free photos taken nearby.
 * Returns ['photos' => [...], 'error' => string].
 */
function commonsSearch($name, $lat = null, $lng = null, $limit = 18) {
    $out = []; $error = '';
    $name = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$name)));

    if ($name !== '') {
        $q = $name . (stripos($name, 'kenya') === false ? ' Kenya' : '') . ' filetype:bitmap';
        [$json, $err] = commonsQuery(commonsImageProps(360) + [
            'generator' => 'search', 'gsrsearch' => $q, 'gsrnamespace' => '6', 'gsrlimit' => (string)$limit,
        ]);
        if ($json === null) $error = $err;
        else {
            $pages = $json['query']['pages'] ?? [];
            usort($pages, fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
            foreach ($pages as $p) {
                $ph = commonsPageToPhoto($p);
                if ($ph) { $ph['source'] = 'name'; $out[$ph['title']] = $ph; }
            }
        }
    }
    if ($lat !== null && $lng !== null && is_finite($lat) && is_finite($lng)) {
        [$json, $err] = commonsQuery(commonsImageProps(360) + [
            'generator' => 'geosearch', 'ggscoord' => $lat . '|' . $lng, 'ggsradius' => '1000',
            'ggsnamespace' => '6', 'ggslimit' => (string)$limit,
        ]);
        if ($json === null) { if ($error === '') $error = $err; }
        else {
            $pages = $json['query']['pages'] ?? [];
            usort($pages, fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
            foreach ($pages as $p) {
                $ph = commonsPageToPhoto($p);
                if ($ph && !isset($out[$ph['title']])) { $ph['source'] = 'nearby'; $out[$ph['title']] = $ph; }
            }
        }
    }
    return ['photos' => array_values($out), 'error' => $out ? '' : $error];
}

/** Only real Commons file titles are accepted ("File:Something.jpg"). */
function commonsCleanTitles(array $titles, $max = 12) {
    $ok = [];
    foreach ($titles as $t) {
        $t = trim((string)$t);
        if (!preg_match('~^File:[^|<>\[\]{}#]{1,240}\.(jpe?g|png|webp)$~iu', $t)) continue;
        $ok[$t] = $t;
        if (count($ok) >= $max) break;
    }
    return array_values($ok);
}

/** Credit and download link for chosen files, read again from Commons (the browser is not trusted for this). */
function commonsInfoForTitles(array $titles, $thumbWidth = 1280) {
    $titles = commonsCleanTitles($titles);
    if (!$titles) return [[], ''];
    [$json, $err] = commonsQuery(commonsImageProps($thumbWidth) + ['titles' => implode('|', $titles)]);
    if ($json === null) return [[], $err];
    $byTitle = [];
    foreach (($json['query']['pages'] ?? []) as $p) {
        $ph = commonsPageToPhoto($p);
        if ($ph) $byTitle[$ph['title']] = $ph;
    }
    // The API may normalise titles (underscores to spaces), so match loosely and keep the admin's order.
    $norm = fn($s) => strtolower(str_replace('_', ' ', $s));
    $map = [];
    foreach ($byTitle as $k => $v) $map[$norm($k)] = $v;
    $result = [];
    foreach ($titles as $t) if (isset($map[$norm($t)])) $result[] = $map[$norm($t)];
    return [$result, ''];
}

/**
 * Download the chosen photos for one attraction and save them with their credit.
 * Returns ['saved' => int, 'errors' => string[]].
 */
function importCommonsPhotos($pdo, $attractionId, array $titles, $uploadDir) {
    ensureAttractionPhotosTable($pdo);
    ensureAttractionPhotoCreditsTable($pdo);
    $attractionId = (int)$attractionId;
    $errors = []; $saved = 0;
    [$infos, $err] = commonsInfoForTitles($titles);
    if ($err !== '') return ['saved' => 0, 'errors' => [$err]];
    if (!$infos) return ['saved' => 0, 'errors' => $titles ? ['Those photos could not be read from Commons (they may have been removed).'] : []];
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

    $order = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM attraction_photos WHERE attraction_id = " . $attractionId)->fetchColumn();
    foreach ($infos as $ph) {
        if ($ph['thumb'] === '' || !preg_match('~^https://upload\.wikimedia\.org/~i', $ph['thumb'])) { $errors[] = 'A photo had no safe download link and was skipped.'; continue; }
        [$bytes, $e] = commonsHttpGet($ph['thumb'], 40);
        if ($bytes === null) { $errors[] = $e; break; }
        if (strlen($bytes) > 5 * 1024 * 1024) { $errors[] = 'One photo was over 5MB and was skipped.'; continue; }
        $tmp = tempnam(sys_get_temp_dir(), 'cm');
        file_put_contents($tmp, $bytes);
        $type = function_exists('mime_content_type') ? mime_content_type($tmp) : '';
        @unlink($tmp);
        if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) { $errors[] = 'One download was not a JPG, PNG or WebP image and was skipped.'; continue; }
        $ext = $type === 'image/png' ? 'png' : ($type === 'image/webp' ? 'webp' : 'jpg');
        $fn = 'att_cm' . uniqid() . '.' . $ext;
        if (file_put_contents(rtrim($uploadDir, '/') . '/' . $fn, $bytes) === false) { $errors[] = 'A photo could not be saved to the uploads folder.'; continue; }
        $pdo->prepare("INSERT INTO attraction_photos (attraction_id, photo_path, sort_order) VALUES (?, ?, ?)")->execute([$attractionId, $fn, $order++]);
        $pdo->prepare("INSERT INTO attraction_photo_credits (photo_path, credit, license, license_url, source_url) VALUES (?, ?, ?, ?, ?)")
            ->execute([$fn, $ph['artist'], $ph['license'], $ph['license_url'] ?: null, $ph['page_url'] ?: null]);
        $saved++;
    }
    // Make sure there is a cover photo (same rule as manual uploads)
    saveAttractionPhotos($pdo, $attractionId, [], [], $uploadDir);
    return ['saved' => $saved, 'errors' => $errors];
}

/** [photo_path => ['credit'=>, 'license'=>, 'license_url'=>, 'source_url'=>]] for the given file names. */
function getAttractionPhotoCredits($pdo, array $names) {
    $names = array_values(array_filter(array_unique(array_map('strval', $names))));
    if (!$names) return [];
    ensureAttractionPhotoCreditsTable($pdo);
    try {
        $in = implode(',', array_fill(0, count($names), '?'));
        $q = $pdo->prepare("SELECT * FROM attraction_photo_credits WHERE photo_path IN ($in)");
        $q->execute($names);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['photo_path']] = $r;
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Small "Photo credits" block for the attraction page. Empty string when no photo needs a credit. */
function attractionCreditsHtml($pdo, array $names) {
    $credits = getAttractionPhotoCredits($pdo, $names);
    if (!$credits) return '';
    $i = 0; $items = '';
    foreach ($names as $n) {
        if (!isset($credits[$n])) continue;
        $c = $credits[$n]; $i++;
        $pos = array_search($n, $names, true) + 1;
        $lic = h($c['license'] ?? '');
        if (!empty($c['license_url'])) $lic = '<a href="' . h($c['license_url']) . '" target="_blank" rel="noopener">' . $lic . '</a>';
        $src = !empty($c['source_url'])
            ? '<a href="' . h($c['source_url']) . '" target="_blank" rel="noopener">' . h($c['source_name'] ?? 'Wikimedia Commons') . '</a>'
            : h($c['source_name'] ?? 'Wikimedia Commons');
        $items .= '<li>Photo ' . (int)$pos . ': ' . h($c['credit']) . ($lic !== '' ? ' &middot; ' . $lic : '') . ' &middot; ' . $src . '</li>';
    }
    return $items === '' ? '' : '<div class="att-credits" style="font-size:12px;color:#4f5f57;line-height:1.6;margin:10px 0 0">'
        . '<strong style="font-size:11px;letter-spacing:.08em;text-transform:uppercase">Photo credits</strong>'
        . '<ul style="margin:4px 0 0;padding-left:18px">' . $items . '</ul></div>';
}

/**
 * Save photos that the admin's BROWSER downloaded from Commons and uploaded here (no outbound connection from PHP needed).
 * $files is $_FILES['files']; $metaList is the matching list of ['credit','license','license_url','source_url'].
 */
function saveCommonsUploadedFiles($pdo, $attractionId, array $files, array $metaList, $uploadDir) {
    ensureAttractionPhotosTable($pdo);
    ensureAttractionPhotoCreditsTable($pdo);
    $attractionId = (int)$attractionId; $errors = []; $saved = 0;
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
    $order = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM attraction_photos WHERE attraction_id = " . $attractionId)->fetchColumn();
    $cut = function ($s, $n) { $s = trim(strip_tags((string)$s)); return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n); };
    $url = fn($u) => preg_match('~^https://[^\s]{4,480}$~i', (string)$u) ? (string)$u : null;
    $n = min(count($files['name'] ?? []), 8);
    for ($i = 0; $i < $n; $i++) {
        if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) { $errors[] = 'One photo failed to upload.'; continue; }
        if ($files['size'][$i] > 5 * 1024 * 1024) { $errors[] = 'One photo was over 5MB and was skipped.'; continue; }
        $type = mime_content_type($files['tmp_name'][$i]);
        if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) { $errors[] = 'One file was not a JPG, PNG or WebP image.'; continue; }
        $m = is_array($metaList[$i] ?? null) ? $metaList[$i] : [];
        $credit = $cut($m['credit'] ?? '', 250);
        $license = $cut($m['license'] ?? '', 110);
        if ($credit === '' || $license === '') { $errors[] = 'A photo without a credit and licence was skipped.'; continue; }
        $ext = $type === 'image/png' ? 'png' : ($type === 'image/webp' ? 'webp' : 'jpg');
        $fn = 'att_cm' . uniqid() . $i . '.' . $ext;
        if (!move_uploaded_file($files['tmp_name'][$i], rtrim($uploadDir, '/') . '/' . $fn)) { $errors[] = 'A photo could not be saved.'; continue; }
        $pdo->prepare("INSERT INTO attraction_photos (attraction_id, photo_path, sort_order) VALUES (?, ?, ?)")->execute([$attractionId, $fn, $order++]);
        $pdo->prepare("INSERT INTO attraction_photo_credits (photo_path, credit, license, license_url, source_url) VALUES (?, ?, ?, ?, ?)")
            ->execute([$fn, $credit, $license, $url($m['license_url'] ?? ''), $url($m['source_url'] ?? '')]);
        $saved++;
    }
    saveAttractionPhotos($pdo, $attractionId, [], [], $uploadDir);
    return ['saved' => $saved, 'errors' => $errors];
}
