<?php
/**
 * Public booking references (v9.22).
 *
 * The database keeps the numeric booking_id internally, but it is never shown to a user or put in a URL or form. People see
 * and use a random reference such as BK-K7M2QX9TPA instead. The reference is unguessable (31^10 possibilities), so booking
 * numbers cannot be counted, enumerated or used to estimate how many bookings the platform has.
 *
 * This is a second layer: every page still checks that the booking belongs to the signed-in guest, host or admin.
 */
if (!defined('BOOKING_REF_ALPHABET')) define('BOOKING_REF_ALPHABET', 'ABCDEFGHJKMNPQRSTUVWXYZ23456789');   // no 0/O/1/I/L
if (!defined('BOOKING_REF_LENGTH')) define('BOOKING_REF_LENGTH', 10);

if (!function_exists('generateBookingRef')) {
    function generateBookingRef() {
        $a = BOOKING_REF_ALPHABET; $n = strlen($a); $out = '';
        for ($i = 0; $i < BOOKING_REF_LENGTH; $i++) $out .= $a[random_int(0, $n - 1)];
        return $out;
    }
}
if (!function_exists('ensureBookingRefColumn')) {
    /** Adds bookings.booking_ref (unique) the first time it is needed and gives every existing booking a reference. */
    function ensureBookingRefColumn($pdo = null) {
        static $done = false;
        if ($done) return;
        $pdo = $pdo ?: ($GLOBALS['pdo'] ?? null);
        if (!$pdo) return;
        $done = true;
        try {
            if (!$pdo->query("SHOW COLUMNS FROM bookings LIKE 'booking_ref'")->fetch()) {
                $pdo->exec("ALTER TABLE bookings ADD COLUMN booking_ref VARCHAR(12) NULL DEFAULT NULL, ADD UNIQUE KEY uq_booking_ref (booking_ref)");
            }
            $ids = $pdo->query("SELECT booking_id FROM bookings WHERE booking_ref IS NULL")->fetchAll(PDO::FETCH_COLUMN);
            if ($ids) {
                $up = $pdo->prepare("UPDATE bookings SET booking_ref = ? WHERE booking_id = ? AND booking_ref IS NULL");
                foreach ($ids as $id) {
                    for ($try = 0; $try < 5; $try++) {
                        try { $up->execute([generateBookingRef(), (int)$id]); break; } catch (PDOException $e) { if ($try === 4) throw $e; }   // duplicate: draw again
                    }
                }
            }
        } catch (Throwable $e) { error_log('ensureBookingRefColumn: ' . $e->getMessage()); }
    }
}
if (!function_exists('bookingRef')) {
    /** The public reference (10 characters) for a booking id. */
    function bookingRef($bookingId) {
        static $cache = [];
        $id = (int)$bookingId;
        if ($id <= 0) return '';
        if (isset($cache[$id])) return $cache[$id];
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo) return '';
        ensureBookingRefColumn($pdo);
        try {
            $q = $pdo->prepare("SELECT booking_ref FROM bookings WHERE booking_id = ?"); $q->execute([$id]);
            $ref = $q->fetchColumn();
            if ($ref === false) return '';
            if ($ref === null || $ref === '') {   // booking created by something that did not set one
                for ($try = 0; $try < 5; $try++) {
                    $ref = generateBookingRef();
                    try { $pdo->prepare("UPDATE bookings SET booking_ref = ? WHERE booking_id = ? AND booking_ref IS NULL")->execute([$ref, $id]); break; } catch (PDOException $e) { if ($try === 4) throw $e; }
                }
                $q->execute([$id]); $ref = $q->fetchColumn();
            }
            return $cache[$id] = (string)$ref;
        } catch (Throwable $e) { error_log('bookingRef: ' . $e->getMessage()); return ''; }
    }
}
if (!function_exists('bookingLabel')) {
    /** What people see and quote: BK-K7M2QX9TPA */
    function bookingLabel($bookingId) { $r = bookingRef($bookingId); return $r === '' ? 'BK-unknown' : 'BK-' . $r; }
}
if (!function_exists('bookingIdFromRef')) {
    /** Internal booking id for a public reference (with or without the "BK-" prefix), or 0 when it does not exist. Numbers are never accepted. */
    function bookingIdFromRef($ref) {
        $ref = strtoupper(trim((string)$ref));
        if (strpos($ref, 'BK-') === 0) $ref = substr($ref, 3);
        if (!preg_match('/^[' . BOOKING_REF_ALPHABET . ']{' . BOOKING_REF_LENGTH . '}$/', $ref)) return 0;
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo) return 0;
        ensureBookingRefColumn($pdo);
        try { $q = $pdo->prepare("SELECT booking_id FROM bookings WHERE booking_ref = ?"); $q->execute([$ref]); return (int)$q->fetchColumn(); }
        catch (Throwable $e) { error_log('bookingIdFromRef: ' . $e->getMessage()); return 0; }
    }
}
