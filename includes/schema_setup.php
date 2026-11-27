<?php
/**
 * Creates every table and column the site adds on top of the original database, in one go (v9.21),
 * so you do not have to open each page once before exporting the database. Safe to run any number of times.
 * Run it from Admin > Database setup, or from a terminal:  php database/run_setup.php
 */
require_once __DIR__ . '/functions.php';
foreach (['gallery', 'attraction_reviews', 'profile_photo', 'attraction_content', 'commons_photos', 'review_extras', 'login_throttle',
          'payment_audit', 'media_store', 'booking_events', 'commission', 'onsite_experience', 'verify_wait', 'notifications', 'currency', 'certificates', 'host_profile', 'support', 'emoji_support', 'finance_audit'] as $f) {
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

if (!function_exists('ensurePaymentReferenceUnique')) {
    /**
     * v9.38.1: one payment reference (per method) can back only one payment, enforced by the database so two simultaneous
     * submissions cannot both pass the check in tourist/payment.php. Payment records are financial data and are NEVER deleted here:
     * if duplicates already exist the key is skipped and they are listed so an admin can review them.
     */
    function ensurePaymentReferenceUnique($pdo) {
        if ($pdo->query("SHOW INDEX FROM payments WHERE Key_name = 'uq_payment_method_ref'")->fetch()) return 'payments.payment_reference is already unique per method';
        $dups = $pdo->query("SELECT payment_method, payment_reference, COUNT(*) c, GROUP_CONCAT(payment_id) ids FROM payments WHERE payment_reference IS NOT NULL AND payment_reference <> '' GROUP BY payment_method, payment_reference HAVING c > 1 LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        if ($dups) {
            $list = array_map(fn($d) => ($d['payment_method'] ?: '-') . ' ' . $d['payment_reference'] . ' (payment ids ' . $d['ids'] . ')', $dups);
            return 'unique key on payments NOT added, duplicate references exist, review them first: ' . implode('; ', $list);
        }
        $pdo->exec("ALTER TABLE payments ADD UNIQUE KEY uq_payment_method_ref (payment_method, payment_reference)");
        return 'added a unique key on payments (payment_method, payment_reference)';
    }
}

if (!function_exists('runSchemaSetup')) {
    /** @return array ['messages' => [...], 'tables' => [table => bool]] */
    function runSchemaSetup($pdo) {
        $msgs = []; $calls = ['ensureAttractionPhotosTable', 'ensureAttractionReviewsTable', 'ensureProfilePhotoColumns', 'ensureAttractionExtras',
            'ensureAttractionPhotoCreditsTable', 'ensureReviewExtraColumns', 'ensureLoginAttemptsTable', 'ensurePaymentAuditTable',
            'ensureMediaTables', 'ensureBookingEventsTable', 'ensureCommissionTables', 'ensureOnsiteExperienceColumns',
            'ensureVerifyWaitsTable', 'ensureVerifyCodesTable', 'ensureBookingRefColumn', 'ensureNotificationsTable', 'ensureCurrenciesTable', 'ensureCertificatesTable', 'ensureHostIntroColumn', 'ensureSupportTables', 'ensureEmojiSupport', 'ensureFinanceAuditTable'];
        foreach ($calls as $fn) {
            if (!function_exists($fn)) { $msgs[] = "$fn: not found"; continue; }
            try { $fn($pdo); $msgs[] = "$fn: ok"; } catch (Throwable $e) { $msgs[] = "$fn: FAILED (" . $e->getMessage() . ")"; }
        }
        try { $msgs[] = ensureReviewsUniqueKey($pdo); } catch (Throwable $e) { $msgs[] = 'reviews unique key: FAILED (' . $e->getMessage() . ')'; }
        try { $msgs[] = ensurePaymentReferenceUnique($pdo); } catch (Throwable $e) { $msgs[] = 'payments unique key: FAILED (' . $e->getMessage() . ')'; }
        $expected = ['users', 'accommodations', 'accommodation_photos', 'bookings', 'payments', 'reviews', 'attractions', 'attraction_photos', 'attraction_extras',
                     'attraction_reviews', 'payment_audit', 'booking_events', 'platform_payments', 'site_settings', 'login_attempts', 'verify_codes', 'verify_waits', 'notifications', 'currencies', 'certificates'];
        $have = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $tables = []; foreach ($expected as $t) $tables[$t] = in_array($t, $have, true);
        return ['messages' => $msgs, 'tables' => $tables];
    }
}