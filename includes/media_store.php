<?php
/**
 * Photo safekeeping: listing and attraction photos survive a new version of the project, with no duplicates.
 *
 * WHY THIS EXISTS
 *   Uploaded photos are files in uploads/accommodations and uploads/attractions, and the database only stores
 *   their file NAMES. A new version of the project ships an empty uploads/ folder (photos are not part of the
 *   zip or git), so after replacing the folder every name in the database pointed at a missing file and the
 *   photos had to be added again, creating duplicates.
 *
 * WHAT IT DOES (automatic, no cron job)
 *   1. BACKUP   every photo that the database refers to is copied into the database (tables media_files and
 *               media_chunks, in small pieces so MySQL's max_allowed_packet is never a problem).
 *   2. RESTORE  when a photo the database refers to is missing from uploads/ (for example right after switching
 *               to a new version), it is written back from the database. Pages are unchanged: the same
 *               ../uploads/... addresses simply work again.
 *   3. DEDUPE   two photos of the same listing/attraction with identical content (SHA-1) are reduced to one.
 *   4. CLEAN    backups of photos that nothing refers to any more are removed, so deleted photos stay deleted.
 *
 * It runs at most once a minute (and immediately on the first page opened after a version change).
 * Profile photos already live in the database (includes/profile_photo.php) and are not handled here.
 */
require_once __DIR__ . '/helpers.php';

if (!defined('MEDIA_CHUNK_BYTES'))    define('MEDIA_CHUNK_BYTES', 200 * 1024);        // well under any max_allowed_packet
if (!defined('MEDIA_MAX_FILE_BYTES')) define('MEDIA_MAX_FILE_BYTES', 8 * 1024 * 1024);
if (!defined('MEDIA_RESTORE_LIMIT'))  define('MEDIA_RESTORE_LIMIT', 400);             // files restored per run
if (!defined('MEDIA_BACKUP_LIMIT'))   define('MEDIA_BACKUP_LIMIT', 40);               // files backed up per run

if (!function_exists('ensureMediaTables')) {
    function ensureMediaTables($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS media_files (
                path VARCHAR(190) NOT NULL PRIMARY KEY,
                mime VARCHAR(50) NOT NULL,
                size INT NOT NULL,
                sha1 CHAR(40) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sha1 (sha1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS media_chunks (
                path VARCHAR(190) NOT NULL,
                seq INT NOT NULL,
                data MEDIUMBLOB NOT NULL,
                PRIMARY KEY (path, seq)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { error_log('ensureMediaTables: ' . $e->getMessage()); }
    }
}

if (!function_exists('mediaUploadsRoot')) {
    function mediaUploadsRoot() { return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads'; }
}

/** 'accommodations/acc_5_ab12.jpg' -> true when it is a plain image path we are willing to read/write. */
if (!function_exists('mediaPathOk')) {
    function mediaPathOk($rel) {
        return (bool)preg_match('~^(accommodations|attractions)/[A-Za-z0-9_.-]{1,150}\.(jpe?g|png|webp)$~i', (string)$rel)
            && strpos((string)$rel, '..') === false;
    }
}

if (!function_exists('mediaDiskPath')) {
    function mediaDiskPath($rel) { return mediaUploadsRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel); }
}

if (!function_exists('mediaCollectRefs')) {
    /**
     * Every photo the database refers to.
     * @return array [members, complete]  members: list of ['table','id','group','rel','primary','cover'];
     *               complete is false when a table could not be read (then nothing may be cleaned up).
     */
    function mediaCollectRefs($pdo) {
        $members = [];
        $complete = true;
        $norm = function ($dir, $stored) {
            $b = basename(str_replace('\\', '/', (string)$stored));
            return $b === '' ? '' : $dir . '/' . $b;
        };
        try {
            $q = $pdo->query("SELECT photo_id, accommodation_id, photo_path, is_primary FROM accommodation_photos ORDER BY accommodation_id, is_primary DESC, photo_id");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rel = $norm('accommodations', $r['photo_path']);
                if (mediaPathOk($rel)) $members[] = ['table' => 'accommodation_photos', 'id' => (int)$r['photo_id'], 'group' => 'acc:' . (int)$r['accommodation_id'],
                                                     'rel' => $rel, 'primary' => (int)$r['is_primary'] === 1, 'cover' => false, 'owner' => (int)$r['accommodation_id']];
            }
        } catch (Throwable $e) { $complete = false; }
        try {
            $q = $pdo->query("SELECT attraction_id, photo_path FROM attractions WHERE photo_path IS NOT NULL AND photo_path <> ''");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rel = $norm('attractions', $r['photo_path']);
                if (mediaPathOk($rel)) $members[] = ['table' => 'attractions', 'id' => (int)$r['attraction_id'], 'group' => 'att:' . (int)$r['attraction_id'],
                                                     'rel' => $rel, 'primary' => false, 'cover' => true, 'owner' => (int)$r['attraction_id']];
            }
        } catch (Throwable $e) { $complete = false; }
        try {
            $q = $pdo->query("SELECT photo_id, attraction_id, photo_path FROM attraction_photos ORDER BY attraction_id, sort_order, photo_id");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rel = $norm('attractions', $r['photo_path']);
                if (mediaPathOk($rel)) $members[] = ['table' => 'attraction_photos', 'id' => (int)$r['photo_id'], 'group' => 'att:' . (int)$r['attraction_id'],
                                                     'rel' => $rel, 'primary' => false, 'cover' => false, 'owner' => (int)$r['attraction_id']];
            }
        } catch (Throwable $e) { /* table is created on first use of the attraction pages */ }
        return [$members, $complete];
    }
}

if (!function_exists('mediaStoreFile')) {
    /** Copy one photo from uploads/ into the database. Returns the SHA-1, or null. */
    function mediaStoreFile($pdo, $rel) {
        if (!mediaPathOk($rel)) return null;
        $file = mediaDiskPath($rel);
        if (!is_file($file)) return null;
        $size = (int)filesize($file);
        if ($size < 1 || $size > MEDIA_MAX_FILE_BYTES) return null;
        $mime = function_exists('mime_content_type') ? (string)mime_content_type($file) : 'image/jpeg';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
        $sha = sha1_file($file);
        $fh = fopen($file, 'rb');
        if (!$fh) return null;
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM media_chunks WHERE path = ?")->execute([$rel]);
            $ins = $pdo->prepare("INSERT INTO media_chunks (path, seq, data) VALUES (?, ?, ?)");
            $seq = 0;
            while (!feof($fh)) {
                $buf = fread($fh, MEDIA_CHUNK_BYTES);
                if ($buf === false || $buf === '') break;
                $ins->bindValue(1, $rel);
                $ins->bindValue(2, $seq++, PDO::PARAM_INT);
                $ins->bindValue(3, $buf, PDO::PARAM_LOB);
                $ins->execute();
            }
            $pdo->prepare("REPLACE INTO media_files (path, mime, size, sha1) VALUES (?, ?, ?, ?)")->execute([$rel, $mime, $size, $sha]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('mediaStoreFile ' . $rel . ': ' . $e->getMessage());
            fclose($fh);
            return null;
        }
        fclose($fh);
        return $sha;
    }
}

if (!function_exists('mediaRestoreFile')) {
    /** Write one photo back into uploads/ from the database. Returns true on success. */
    function mediaRestoreFile($pdo, $rel) {
        if (!mediaPathOk($rel)) return false;
        try {
            $m = $pdo->prepare("SELECT sha1, size FROM media_files WHERE path = ?");
            $m->execute([$rel]);
            $meta = $m->fetch(PDO::FETCH_ASSOC);
            if (!$meta) return false;
            $dest = mediaDiskPath($rel);
            $dir = dirname($dest);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
            $tmp = $dest . '.restoring' . bin2hex(random_bytes(4));
            $out = fopen($tmp, 'wb');
            if (!$out) return false;
            $q = $pdo->prepare("SELECT data FROM media_chunks WHERE path = ? ORDER BY seq");
            $q->execute([$rel]);
            while (($chunk = $q->fetchColumn()) !== false) fwrite($out, is_resource($chunk) ? stream_get_contents($chunk) : $chunk);
            fclose($out);
            if (sha1_file($tmp) !== $meta['sha1']) { @unlink($tmp); return false; }   // never write a damaged copy
            return @rename($tmp, $dest);
        } catch (Throwable $e) {
            error_log('mediaRestoreFile ' . $rel . ': ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mediaSelfHeal')) {
    /**
     * Backup + restore + dedupe + clean. Throttled to once a minute unless $force.
     * @return array|null  counts: restored, backed_up, duplicates_removed, cleaned, broken (list of rel paths); null when skipped.
     */
    function mediaSelfHeal($pdo, $force = false) {
        if (PHP_SAPI === 'cli' && !$force) return null;
        $stamp = dirname(__DIR__) . '/logs/.media_check';
        $lock = null;
        if (!$force && is_file($stamp) && (time() - (int)@filemtime($stamp)) < 60) return null;
        if (!is_dir(dirname($stamp))) @mkdir(dirname($stamp), 0775, true);
        $lock = @fopen($stamp, 'c');
        if ($lock && !@flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return null; }   // another request is already doing it
        @touch($stamp);
        if (function_exists('set_time_limit')) @set_time_limit(120);

        $res = ['restored' => 0, 'backed_up' => 0, 'duplicates_removed' => 0, 'cleaned' => 0, 'broken' => []];
        try {
            ensureMediaTables($pdo);
            if (function_exists('ensureAttractionPhotosTable')) ensureAttractionPhotosTable($pdo);
            [$members, $complete] = mediaCollectRefs($pdo);

            $known = [];   // rel => sha1 for what the database already holds
            $oldEnough = []; // rel => true when the backup is over a day old (only those may be cleaned)
            foreach ($pdo->query("SELECT path, sha1, (created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)) AS old FROM media_files")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $known[$r['path']] = $r['sha1'];
                if ((int)$r['old']) $oldEnough[$r['path']] = true;
            }

            // 1+2. restore what is missing, back up what is not saved yet
            $backupBudget = $force ? 400 : MEDIA_BACKUP_LIMIT;
            $restoreBudget = MEDIA_RESTORE_LIMIT;
            $seen = [];
            $sha = [];     // rel => sha1 for files that exist (or were restored)
            foreach ($members as $m) {
                $rel = $m['rel'];
                if (isset($seen[$rel])) continue;
                $seen[$rel] = true;
                $disk = mediaDiskPath($rel);
                if (!is_file($disk)) {
                    if (isset($known[$rel])) {
                        if ($restoreBudget > 0 && mediaRestoreFile($pdo, $rel)) { $res['restored']++; $restoreBudget--; $sha[$rel] = $known[$rel]; }
                        else $res['broken'][] = $rel;
                    } else {
                        $res['broken'][] = $rel;    // never saved and the file is gone: only a re-upload can fix it
                    }
                    continue;
                }
                if (isset($known[$rel])) { $sha[$rel] = $known[$rel]; continue; }
                if ($backupBudget > 0) {
                    $s = mediaStoreFile($pdo, $rel);
                    if ($s) { $known[$rel] = $s; $sha[$rel] = $s; $res['backed_up']++; $backupBudget--; }
                }
            }

            // 3. identical photos inside one listing / attraction: keep the first, remove the others
            $groups = [];
            foreach ($members as $i => $m) $groups[$m['group']][] = $i;
            $removed = [];
            foreach ($groups as $idxs) {
                if (count($idxs) < 2) continue;
                $keep = [];      // sha1 => member index kept
                // keepers first: the cover / main photo, then the oldest
                usort($idxs, function ($a, $b) use ($members) {
                    $ra = ($members[$a]['cover'] ? 0 : ($members[$a]['primary'] ? 1 : 2));
                    $rb = ($members[$b]['cover'] ? 0 : ($members[$b]['primary'] ? 1 : 2));
                    return $ra <=> $rb ?: $a <=> $b;
                });
                foreach ($idxs as $i) {
                    $m = $members[$i];
                    $h = $sha[$m['rel']] ?? null;
                    if ($h === null) continue;
                    if (!isset($keep[$h])) { $keep[$h] = $i; continue; }
                    if ($m['cover']) continue;                       // never delete a cover row
                    $kept = $members[$keep[$h]];
                    if ($m['table'] === 'accommodation_photos') {
                        if ($m['primary'] && $kept['table'] === 'accommodation_photos') {
                            $pdo->prepare("UPDATE accommodation_photos SET is_primary = 1 WHERE photo_id = ?")->execute([$kept['id']]);
                        }
                        $pdo->prepare("DELETE FROM accommodation_photos WHERE photo_id = ?")->execute([$m['id']]);
                    } elseif ($m['table'] === 'attraction_photos') {
                        $pdo->prepare("DELETE FROM attraction_photos WHERE photo_id = ?")->execute([$m['id']]);
                    } else continue;
                    $removed[$i] = true;
                    $res['duplicates_removed']++;
                }
            }
            if ($removed) {
                $members = array_values(array_filter($members, function ($k) use ($removed) { return !isset($removed[$k]); }, ARRAY_FILTER_USE_KEY));
            }

            // 4. clean: backups (and files) nothing refers to any more
            if ($complete) {
                $live = [];
                foreach ($members as $m) $live[$m['rel']] = true;
                foreach ($known as $rel => $_) {
                    if (isset($live[$rel]) || !isset($oldEnough[$rel])) continue;   // a day's grace before a backup is dropped
                    $pdo->prepare("DELETE FROM media_chunks WHERE path = ?")->execute([$rel]);
                    $pdo->prepare("DELETE FROM media_files WHERE path = ?")->execute([$rel]);
                    $res['cleaned']++;
                }
                foreach ($seen as $rel => $_) {
                    if (!isset($live[$rel]) && is_file(mediaDiskPath($rel))) @unlink(mediaDiskPath($rel));
                }
            }
        } catch (Throwable $e) {
            error_log('mediaSelfHeal: ' . $e->getMessage());
        }
        if ($lock) { @flock($lock, LOCK_UN); fclose($lock); }
        return $res;
    }
}

if (!function_exists('mediaStatus')) {
    /** Read-only overview for the admin page. */
    function mediaStatus($pdo) {
        ensureMediaTables($pdo);
        [$members] = mediaCollectRefs($pdo);
        $known = [];
        try { foreach ($pdo->query("SELECT path FROM media_files")->fetchAll(PDO::FETCH_COLUMN) as $p) $known[$p] = true; } catch (Throwable $e) {}
        $st = ['photos' => 0, 'on_disk' => 0, 'in_db' => 0, 'restorable' => [], 'broken' => [], 'db_bytes' => 0];
        $seen = [];
        foreach ($members as $m) {
            if (isset($seen[$m['rel']])) continue;
            $seen[$m['rel']] = true;
            $st['photos']++;
            $disk = is_file(mediaDiskPath($m['rel']));
            $db = isset($known[$m['rel']]);
            if ($disk) $st['on_disk']++;
            if ($db) $st['in_db']++;
            if (!$disk && $db) $st['restorable'][] = $m;
            if (!$disk && !$db) $st['broken'][] = $m;
        }
        try { $st['db_bytes'] = (int)$pdo->query("SELECT COALESCE(SUM(size),0) FROM media_files")->fetchColumn(); } catch (Throwable $e) {}
        return $st;
    }
}

if (!function_exists('mediaRemoveBroken')) {
    /** Delete database records whose photo file is gone AND was never saved in the database. Returns how many rows. */
    function mediaRemoveBroken($pdo) {
        $n = 0;
        $st = mediaStatus($pdo);
        foreach ($st['broken'] as $m) {
            if ($m['table'] === 'accommodation_photos') {
                $pdo->prepare("DELETE FROM accommodation_photos WHERE photo_id = ?")->execute([$m['id']]);
                // keep one main photo per listing
                $has = $pdo->prepare("SELECT COUNT(*) FROM accommodation_photos WHERE accommodation_id = ? AND is_primary = 1");
                $has->execute([$m['owner']]);
                if ((int)$has->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE accommodation_photos SET is_primary = 1 WHERE accommodation_id = ? ORDER BY photo_id ASC LIMIT 1")->execute([$m['owner']]);
                }
            } elseif ($m['table'] === 'attraction_photos') {
                $pdo->prepare("DELETE FROM attraction_photos WHERE photo_id = ?")->execute([$m['id']]);
            } else {
                $pdo->prepare("UPDATE attractions SET photo_path = NULL WHERE attraction_id = ?")->execute([$m['id']]);
            }
            $n++;
        }
        return $n;
    }
}
