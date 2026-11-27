<?php
// v9.38.1: CSV exports for the admin (bookings, guest payments, platform payments, commission payments) for a date range.
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/commission.php';
require_once '../includes/report_helpers.php';
requireLogin();
requireRole('admin');
ensureCommissionTables($pdo);
$p = reportPeriod('last_30'); $type = (string)($_GET['type'] ?? '');
$range = [$p['from'], $p['to']]; $name = 'stayexplore_' . $type . '_' . $p['from'] . '_to_' . $p['to'] . '.csv';
$LIMIT = 20000;
switch ($type) {
    case 'bookings':
        $q = $pdo->prepare("SELECT b.booking_id, b.created_at, u.full_name AS guest, h.full_name AS host, a.title, b.check_in_date, b.check_out_date, b.status, b.total_amount
            FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id JOIN users u ON u.user_id = b.tourist_id JOIN users h ON h.user_id = a.host_id
            WHERE DATE(b.created_at) BETWEEN ? AND ? ORDER BY b.created_at LIMIT $LIMIT"); $q->execute($range);
        $rows = array_map(fn($r) => [bookingRef($r['booking_id']), $r['created_at'], $r['guest'], $r['host'], $r['title'], $r['check_in_date'], $r['check_out_date'], $r['status'], $r['total_amount']], $q->fetchAll(PDO::FETCH_ASSOC));
        csvDownload($name, ['Booking', 'Created', 'Guest', 'Host', 'Listing', 'Check-in', 'Check-out', 'Status', 'Total (KES)'], $rows);
    case 'payments':
        $q = $pdo->prepare("SELECT p.*, u.full_name AS guest, h.full_name AS host FROM payments p JOIN users u ON u.user_id = p.tourist_id JOIN users h ON h.user_id = p.host_id
            WHERE DATE(p.payment_date) BETWEEN ? AND ? ORDER BY p.payment_date LIMIT $LIMIT"); $q->execute($range);
        $rows = array_map(fn($r) => [$r['payment_id'], bookingRef($r['booking_id']), $r['payment_date'], $r['guest'], $r['host'], $r['payment_method'], $r['payment_reference'], $r['payment_status'], $r['amount']], $q->fetchAll(PDO::FETCH_ASSOC));
        csvDownload($name, ['Payment', 'Booking', 'Date', 'Guest', 'Host', 'Method', 'Reference', 'Status', 'Amount (KES)'], $rows);
    case 'platform_payments':
        $q = $pdo->prepare("SELECT pp.*, u.full_name AS guest, h.full_name AS host FROM platform_payments pp JOIN users u ON u.user_id = pp.tourist_id JOIN users h ON h.user_id = pp.host_id
            WHERE DATE(COALESCE(pp.reviewed_at, pp.created_at)) BETWEEN ? AND ? ORDER BY COALESCE(pp.reviewed_at, pp.created_at) LIMIT $LIMIT"); $q->execute($range);
        $rows = array_map(fn($r) => [$r['platform_payment_id'], bookingRef($r['booking_id']), $r['reviewed_at'] ?: $r['created_at'], $r['guest'], $r['host'], $r['method'], $r['reference'], $r['status'], $r['service_fee'], $r['commission_amount'], $r['amount'], $r['refunded_amount'] ?? ''], $q->fetchAll(PDO::FETCH_ASSOC));
        csvDownload($name, ['Payment', 'Booking', 'Date', 'Guest', 'Host', 'Method', 'Reference', 'Status', 'Service fee', 'Commission', 'Total', 'Refunded'], $rows);
    case 'commission':
        $q = $pdo->prepare("SELECT c.*, u.full_name AS host FROM host_commission_payments c JOIN users u ON u.user_id = c.host_id
            WHERE DATE(c.created_at) BETWEEN ? AND ? ORDER BY c.created_at LIMIT $LIMIT"); $q->execute($range);
        $rows = array_map(fn($r) => [$r['hcp_id'], $r['created_at'], $r['host'], $r['method'], $r['reference'], $r['status'], $r['amount'], $r['reviewed_at'], $r['review_note']], $q->fetchAll(PDO::FETCH_ASSOC));
        csvDownload($name, ['Payment', 'Reported', 'Host', 'Method', 'Reference', 'Status', 'Amount (KES)', 'Reviewed', 'Admin note'], $rows);
    default:
        http_response_code(400); echo 'Unknown export type.';
}