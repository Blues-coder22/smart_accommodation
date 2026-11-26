<?php
/**
 * Creates every table and column the site adds on top of the original database, in one go (v9.21),
 * so you do not have to open each page once before exporting the database. Safe to run any number of times.
 * Run it from Admin > Database setup, or from a terminal:  php database/run_setup.php
 */
require_once __DIR__ . '/functions.php';
foreach (['gallery', 'attraction_reviews', 'profile_photo', 'attraction_content', 'commons_photos', 'review_extras', 'login_throttle',
          'payment_audit', 'media_store', 'booking_events', 'commission', 'onsite_experience', 'verify_wait', 'notifications', 'currency', 'certificates', 'host_profile', 'support', 'emoji_support'] as $f) {
    if (is_file(__DIR__ . "/$f.php")) require_once __DIR__ . "/$f.php";
}

if (!function_exists('ensureReviewsUniqueKey')) {
    /** One review per booking. Removes duplicate reviews (keeps the oldest) and adds the unique key if it is missing. */
    function ensureReviewsUniqueKey($pdo) {
        if ($pdo->query("SHOW INDEX FROM reviews WHERE Column_name = 'booking_id' AND Non_unique = 0")->fetch()) return 'reviews.booking_id is already unique';
        $dups = $pdo->exec("DELETE r1 FROM reviews r1 JOIN reviews r2 ON r1.booking_id = r2.booking_id AND r1.review_id > r2.review_id");
        $pdo->exec("ALTER TABLE reviews ADD UNIQUE KEY uq_review_booking (booking_id)");
        return 'added a unique key on reviews.booking_id' . ($dups ? " (removed $dups duplicate review(s), keeping the oldest)" : '');
    }
}

if (!function_exists('runSchemaSetup')) {
    /** @return array ['messages' => [...], 'tables' => [table => bool]] */
    function runSchemaSetup($pdo) {
        $msgs = []; $calls = ['ensureAttractionPhotosTable', 'ensureAttractionReviewsTable', 'ensureProfilePhotoColumns', 'ensureAttractionExtras',
            'ensureAttractionPhotoCreditsTable', 'ensureReviewExtraColumns', 'ensureLoginAttemptsTable', 'ensurePaymentAuditTable',
            'ensureMediaTables', 'ensureBookingEventsTable', 'ensureCommissionTables', 'ensureOnsiteExperienceColumns',
            'ensureVerifyWaitsTable', 'ensureVerifyCodesTable', 'ensureBookingRefColumn', 'ensureNotificationsTable', 'ensureCurrenciesTable', 'ensureCertificatesTable', 'ensureHostIntroColumn', 'ensureSupportTables', 'ensureEmojiSupport'];
        foreach ($calls as $fn) {
            if (!function_exists($fn)) { $msgs[] = "$fn: not found"; continue; }
            try { $fn($pdo); $msgs[] = "$fn: ok"; } catch (Throwable $e) { $msgs[] = "$fn: FAILED (" . $e->getMessage() . ")"; }
        }
        try { $msgs[] = ensureReviewsUniqueKey($pdo); } catch (Throwable $e) { $msgs[] = 'reviews unique key: FAILED (' . $e->getMessage() . ')'; }
        $expected = ['users', 'accommodations', 'accommodation_photos', 'bookings', 'payments', 'reviews', 'attractions', 'attraction_photos', 'attraction_extras',
                     'attraction_reviews', 'payment_audit', 'booking_events', 'platform_payments', 'site_settings', 'login_attempts', 'verify_codes', 'verify_waits', 'notifications', 'currencies', 'certificates'];
        $have = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $tables = []; foreach ($expected as $t) $tables[$t] = in_array($t, $have, true);
        return ['messages' => $msgs, 'tables' => $tables];
    }
}
