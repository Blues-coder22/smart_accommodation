<?php
/**
 * v9.33 database migration. Safe to run any number of times; it only adds, never removes or rewrites history.
 *
 *   php database/migrate_v9_33.php
 *
 * What it does (all checked first, so a second run changes nothing):
 *   1. bookings.status gains 'accepted' and 'payment_pending'. Old bookings that already had payment details
 *      while still 'pending' become 'payment_pending'; every other booking keeps its status.
 *   2. bookings gains accepted_at, payment_due_at, payment_reminder_sent.
 *   3. New table commission_invoices. New column users.payment_instructions.
 *   4. Launch settings (applied once): commission_mode = invoice, service_fee_percent = 0, payment_deadline_hours = 24.
 *      Existing bookings keep the commission_mode and amounts they were created with.
 *   5. Creates the supporting tables that used to be created during page requests, and adds the performance indexes.
 * The application also runs this automatically on its first request after the update; running it here just lets you
 * do it deliberately, and see the result.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
chdir(dirname(__DIR__));
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/commission.php';

$marker = dirname(__DIR__) . '/logs/.schema_v933';
if (is_file($marker)) { unlink($marker); }          // force a fresh pass
$before = $pdo->query("SELECT status, COUNT(*) c FROM bookings GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
ensureCommissionTables($pdo);
$after  = $pdo->query("SELECT status, COUNT(*) c FROM bookings GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

echo "StayExplore Kenya v9.33 migration complete.\n";
echo "Bookings by status before: " . json_encode($before) . "\n";
echo "Bookings by status after:  " . json_encode($after) . "\n";
echo "commission_mode (new bookings): " . commissionMode() . " | guest service fee: " . serviceFeePercent() . "% | payment deadline: " . paymentDeadlineHours() . " hours\n";
echo is_file($marker) ? "Marker written: logs/.schema_v933 (page loads now skip schema checks).\n" : "WARNING: marker not written; check that logs/ is writable.\n";
