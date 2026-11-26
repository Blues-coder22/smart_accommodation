<?php
/**
 * Scheduled maintenance (v9.33). Run it from cron every 5-10 minutes:   php cron/maintenance.php
 * It was previously run lazily on page requests (search, details, bookings, payments...). Pages now only call
 * runMaintenanceIfDue(), which does nothing while the cron heartbeat is fresh, and acts as a safety net if cron is not set up.
 *
 * Jobs: expire unanswered requests, expire accepted bookings that were not paid in time (dates are released), remind guests
 * before the payment deadline, complete finished stays, issue commission invoices, email hosts reminders about commission invoices (due soon, due today, overdue),
 * publish due reviews. Every job is idempotent and every status change goes through transitionBooking().
 */
require_once __DIR__ . '/booking_workflow.php';
require_once __DIR__ . '/booking_holds.php';

if (!function_exists('maintenanceStamp')) { function maintenanceStamp($name) { return dirname(__DIR__) . '/logs/.' . $name; } }

if (!function_exists('runMaintenance')) {
    function runMaintenance($pdo) {
        ensureCommissionTables($pdo);
        $out = ['expired_unanswered' => 0, 'expired_unpaid' => 0, 'reminders' => 0, 'completed' => 0, 'invoices' => 0, 'overdue_notices' => 0, 'invoice_reminders' => 0, 'reviews' => 0];
        $lock = false;
        try { $lock = (bool)$pdo->query("SELECT GET_LOCK('se_maintenance', 0)")->fetchColumn(); } catch (Throwable $e) {}
        if (!$lock) return $out + ['skipped' => true];
        try {
            require_once __DIR__ . '/email.php';
            // 1. Requests the host never answered
            $q = $pdo->query("SELECT booking_id FROM bookings WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL " . (int)hostReplyHours() . " HOUR) LIMIT 200");
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $r = transitionBooking($pdo, (int)$id, 'cancelled', 'system', null, ['event' => 'auto_expired', 'expect' => 'pending', 'note' => 'Host did not respond within ' . hostReplyHours() . ' hours; dates released']);
                if (!$r['ok']) continue; $out['expired_unanswered']++;
                $f = bookingStayFacts($pdo, (int)$id); $label = bookingLabel((int)$id);
                notify($pdo, (int)$f['t_id'], 'Booking request expired', "$label expired because the host did not reply in time. You have not been charged.", 'tourist/bookings.php');
                notify($pdo, (int)$f['h_id'], 'Booking request expired', "$label expired because it was not answered in time.", 'host/bookings.php');
                try { sendBookingExpiredEmail($f['t_email'], $f['t_name'], $f, 'no_reply', false); sendBookingExpiredEmail($f['h_email'], $f['h_name'], $f, 'no_reply', true); } catch (Throwable $e) {}
            }
            // 2. Accepted bookings the guest did not pay for in time: release the dates
            $q = $pdo->query("SELECT booking_id FROM bookings WHERE status = 'accepted' AND payment_due_at IS NOT NULL AND payment_due_at < NOW() LIMIT 200");
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $r = transitionBooking($pdo, (int)$id, 'cancelled', 'system', null, ['event' => 'auto_expired', 'expect' => 'accepted', 'note' => 'No payment within ' . paymentDeadlineHours() . ' hours of acceptance; dates released']);
                if (!$r['ok']) continue; $out['expired_unpaid']++;
                $f = bookingStayFacts($pdo, (int)$id); $label = bookingLabel((int)$id);
                notify($pdo, (int)$f['t_id'], 'Booking released', "$label was cancelled because payment was not made within " . paymentDeadlineHours() . ' hours of acceptance. The dates were released.', 'tourist/bookings.php');
                notify($pdo, (int)$f['h_id'], 'Dates released', "$label was cancelled: the guest did not pay in time. The dates are open again.", 'host/bookings.php');
                try { sendBookingExpiredEmail($f['t_email'], $f['t_name'], $f, 'no_payment', false); } catch (Throwable $e) {}
            }
            // 3. Reminder before the payment deadline (last 3 hours, or the last quarter of a short window), once per booking
            $q = $pdo->query("SELECT booking_id, TIMESTAMPDIFF(MINUTE, NOW(), payment_due_at) AS left_min FROM bookings
                WHERE status = 'accepted' AND payment_reminder_sent = 0 AND payment_due_at > NOW() AND payment_due_at <= DATE_ADD(NOW(), INTERVAL 3 HOUR) LIMIT 200");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $upd = $pdo->prepare("UPDATE bookings SET payment_reminder_sent = 1 WHERE booking_id = ? AND payment_reminder_sent = 0 AND status = 'accepted'");
                $upd->execute([(int)$r['booking_id']]); if (!$upd->rowCount()) continue;
                $f = bookingStayFacts($pdo, (int)$r['booking_id']); $left = max(1, (int)$r['left_min']); $label = bookingLabel((int)$r['booking_id']);
                logBookingEvent($pdo, (int)$r['booking_id'], null, 'payment_reminder', 'Reminder sent before the payment deadline');
                notify($pdo, (int)$f['t_id'], 'Payment deadline soon', "About $left minutes left to pay your host for $label, or the dates are released.", 'tourist/bookings.php');
                try { sendPaymentDeadlineReminderEmail($f['t_email'], $f['t_name'], $f, $left); } catch (Throwable $e) {}
                $out['reminders']++;
            }
            // 4. Finished stays whose payment the host confirmed become 'completed' (the day after check-out)
            $q = $pdo->query("SELECT b.booking_id FROM bookings b WHERE b.status = 'confirmed' AND b.check_out_date < CURDATE()
                AND EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.booking_id AND p.payment_status = 'confirmed') LIMIT 300");
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
                if (transitionBooking($pdo, (int)$id, 'completed', 'system', null, ['event' => 'auto_completed', 'expect' => 'confirmed', 'note' => 'Stay ended'])['ok']) $out['completed']++;
            }
            // 5. Commission invoices and overdue notices (host-facing only)
            $out['invoices'] = issueCommissionInvoices($pdo);
            // v9.37: reminder emails (before the due date, on it, and while overdue) plus the matching notifications.
            require_once __DIR__ . '/invoice_reminders.php';
            $rem = sendInvoiceReminders($pdo);
            $out['overdue_notices'] = (int)$rem['overdue']; $out['invoice_reminders'] = (int)$rem['invoices'];
            // 6. Reviews
            try { $out['reviews'] = (int)publishDueReviews($pdo, true); } catch (Throwable $e) {}
        } catch (Throwable $e) { error_log('runMaintenance: ' . $e->getMessage()); }
        try { $pdo->query("SELECT RELEASE_LOCK('se_maintenance')"); } catch (Throwable $e) {}
        @touch(maintenanceStamp('maintenance_heartbeat')); @touch(maintenanceStamp('hold_check'));
        return $out;
    }
}
if (!function_exists('runMaintenanceIfDue')) {
    /** Page-load safety net: does nothing while cron is keeping the heartbeat fresh; otherwise runs at most every 5 minutes. */
    function runMaintenanceIfDue($pdo) {
        $hb = maintenanceStamp('maintenance_heartbeat');
        if (is_file($hb) && (time() - (int)@filemtime($hb)) < 900) return null;
        $st = maintenanceStamp('hold_check');
        if (is_file($st) && (time() - (int)@filemtime($st)) < 300) return null;
        if (!is_dir(dirname($st))) @mkdir(dirname($st), 0775, true);
        @touch($st);
        return runMaintenance($pdo);
    }
}
