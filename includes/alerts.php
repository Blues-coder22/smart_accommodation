<?php
/**
 * Tourist alerts (v9.31). There is no scheduled job on a XAMPP/shared host, so these checks run when a signed-in
 * tourist opens a page (at most once every 15 minutes per session) and write ordinary in-site notifications.
 *   1. Check-in reminder: a confirmed booking that starts today, tomorrow or within 3 days (once per booking).
 *   2. Saved stay available again: a favourite stay that had no free night in the next 14 days now has one.
 * Nothing here can break a page: every check is wrapped and failures are only written to the error log.
 */
require_once __DIR__ . '/notifications.php';

if (!function_exists('ensureAlertTables')) {
    function ensureAlertTables($pdo) {
        if (function_exists('schemaIsUpgraded') && schemaIsUpgraded()) return;   // v9.33: tables are created once by the upgrade, not on every request
        static $done = false; if ($done) return; $done = true;
        try {
            // One row per alert already sent, so the same alert is never sent twice.
            $pdo->exec("CREATE TABLE IF NOT EXISTS alert_log (
                user_id INT NOT NULL,
                kind VARCHAR(30) NOT NULL,
                ref VARCHAR(60) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, kind, ref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // What each tourist last saw for each saved stay: 1 = has a free night in the next 14 days, 0 = none.
            $pdo->exec("CREATE TABLE IF NOT EXISTS saved_stay_state (
                tourist_id INT NOT NULL,
                accommodation_id INT NOT NULL,
                is_free TINYINT(1) NOT NULL,
                checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (tourist_id, accommodation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { error_log('ensureAlertTables: ' . $e->getMessage()); }
    }
}

if (!function_exists('alertOnce')) {
    /** True the first time this (user, kind, ref) is seen, false afterwards. */
    function alertOnce($pdo, $userId, $kind, $ref) {
        $st = $pdo->prepare('INSERT IGNORE INTO alert_log (user_id, kind, ref) VALUES (?, ?, ?)');
        $st->execute([(int)$userId, (string)$kind, (string)$ref]);
        return $st->rowCount() === 1;
    }
}

if (!function_exists('sendCheckinReminders')) {
    function sendCheckinReminders($pdo, $userId) {
        $q = $pdo->prepare("SELECT b.booking_id, b.check_in_date, DATEDIFF(b.check_in_date, CURDATE()) AS days_left, a.title
                            FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id
                            WHERE b.tourist_id = ? AND b.status = 'confirmed'
                              AND b.check_in_date >= CURDATE() AND b.check_in_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
        $q->execute([(int)$userId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $b) {
            if (!alertOnce($pdo, $userId, 'checkin', (string)(int)$b['booking_id'])) continue;
            $d = (int)$b['days_left'];
            $when = $d <= 0 ? 'today' : ($d === 1 ? 'tomorrow' : 'in ' . $d . ' days');
            notify($pdo, $userId, 'Check-in ' . $when,
                'Your stay at "' . $b['title'] . '" starts ' . $when . ' (' . date('j M Y', strtotime($b['check_in_date'])) . '). Check the booking details and message your host if you need directions.',
                'tourist/bookings.php');
        }
    }
}

if (!function_exists('sendSavedStayAlerts')) {
    /** Window checked: today and the 13 nights after it (14 nights). */
    function sendSavedStayAlerts($pdo, $userId, $windowNights = 14) {
        $q = $pdo->prepare("SELECT a.accommodation_id, a.title FROM favourites f
                            JOIN accommodations a ON a.accommodation_id = f.item_id
                            WHERE f.tourist_id = ? AND f.item_type = 'accommodation' AND a.status = 'approved'");
        $q->execute([(int)$userId]);
        $saved = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$saved) return;

        $start = date('Y-m-d');
        $end   = date('Y-m-d', strtotime("+$windowNights days"));          // exclusive
        $ids   = array_map(function ($s) { return (int)$s['accommodation_id']; }, $saved);
        $in    = implode(',', array_fill(0, count($ids), '?'));

        // Nights taken by a pending or confirmed booking
        $taken = array_fill_keys($ids, []);
        $b = $pdo->prepare("SELECT accommodation_id, check_in_date, check_out_date FROM bookings
                            WHERE accommodation_id IN ($in) AND status IN ('pending','accepted','payment_pending','confirmed')
                              AND check_out_date > ? AND check_in_date < ?");
        $b->execute(array_merge($ids, [$start, $end]));
        foreach ($b->fetchAll(PDO::FETCH_ASSOC) as $r) {
            for ($t = strtotime($r['check_in_date']); $t < strtotime($r['check_out_date']); $t += 86400) {
                $taken[(int)$r['accommodation_id']][date('Y-m-d', $t)] = true;
            }
        }
        // Nights the host blocked
        $v = $pdo->prepare("SELECT accommodation_id, `date` FROM availability
                            WHERE accommodation_id IN ($in) AND is_available = 0 AND `date` >= ? AND `date` < ?");
        $v->execute(array_merge($ids, [$start, $end]));
        foreach ($v->fetchAll(PDO::FETCH_ASSOC) as $r) $taken[(int)$r['accommodation_id']][$r['date']] = true;

        $prev = [];
        $p = $pdo->prepare('SELECT accommodation_id, is_free FROM saved_stay_state WHERE tourist_id = ?');
        $p->execute([(int)$userId]);
        foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $r) $prev[(int)$r['accommodation_id']] = (int)$r['is_free'];

        $save = $pdo->prepare('INSERT INTO saved_stay_state (tourist_id, accommodation_id, is_free) VALUES (?, ?, ?)
                               ON DUPLICATE KEY UPDATE is_free = VALUES(is_free)');
        foreach ($saved as $s) {
            $id = (int)$s['accommodation_id'];
            $firstFree = null;
            for ($t = strtotime($start); $t < strtotime($end); $t += 86400) {
                if (empty($taken[$id][date('Y-m-d', $t)])) { $firstFree = date('Y-m-d', $t); break; }
            }
            $free = $firstFree !== null ? 1 : 0;
            // Tell the tourist only when it changes from "nothing free" to "free"; the first visit just records the state.
            if ($free === 1 && isset($prev[$id]) && $prev[$id] === 0 && alertOnce($pdo, $userId, 'saved_free', $id . ':' . date('Y-m-d'))) {
                notify($pdo, $userId, 'A stay you saved is available',
                    '"' . $s['title'] . '" has free nights again, from ' . date('j M Y', strtotime($firstFree)) . '. Dates can be taken quickly, so have a look.',
                    'tourist/details.php?id=' . $id);
            }
            $save->execute([(int)$userId, $id, $free]);
        }
    }
}

if (!function_exists('runTouristAlerts')) {
    /** Call for a signed-in tourist. $force skips the 15-minute pause (used on the Notifications page). */
    function runTouristAlerts($pdo, $userId, $force = false) {
        if (!$userId) return;
        if (session_status() === PHP_SESSION_NONE) @session_start();
        if (!$force && isset($_SESSION['alerts_checked_at']) && time() - (int)$_SESSION['alerts_checked_at'] < 900) return;
        $_SESSION['alerts_checked_at'] = time();
        try {
            ensureNotificationsTable($pdo);
            ensureAlertTables($pdo);
            sendCheckinReminders($pdo, (int)$userId);
            sendSavedStayAlerts($pdo, (int)$userId);
        } catch (Throwable $e) { error_log('runTouristAlerts: ' . $e->getMessage()); }
    }
}
