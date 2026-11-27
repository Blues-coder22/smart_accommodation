<?php
// v9.38.1: CSV exports for a host: only their own bookings and the payments for them.
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/report_helpers.php';
requireLogin();
requireRole('host');
$hostId = (int)$_SESSION['user_id'];
$p = reportPeriod('last_30'); $type = (string)($_GET['type'] ?? '');
$range = [$hostId, $p['from'], $p['to']]; $name = 'my_' . $type . '_' . $p['from'] . '_to_' . $p['to'] . '.csv';
switch ($type) {
    case 'bookings':
        $q = $pdo->prepare("SELECT b.booking_id, b.created_at, u.full_name AS guest, a.title, b.check_in_date, b.check_out_date, b.status, b.total_amount
            FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id JOIN users u ON u.user_id = b.tourist_id
            WHERE a.host_id = ? AND DATE(b.created_at) BETWEEN ? AND ? ORDER BY b.created_at LIMIT 20000"); $q->execute($range);
        $rows = array_map(fn($r) => [bookingRef($r['booking_id']), $r['created_at'], $r['guest'], $r['title'], $r['check_in_date'], $r['check_out_date'], $r['status'], $r['total_amount']], $q->fetchAll(PDO::FETCH_ASSOC));
        csvDownload($name, ['Booking', 'Created', 'Guest', 'Listing', 'Check-in', 'Check-out', 'Status', 'Total (KES)'], $rows);
    case 'payments':
        $q = $pdo->prepare("SELECT p.*, u.full_name AS guest FROM payments p JOIN users u ON u.user_id = p.tourist_id
            WHERE p.host_id = ? AND DATE(p.payment_date) BETWEEN ? AND ? ORDER BY p.payment_date LIMIT 20000"); $q->execute($range);
        $rows = array_map(fn($r) => [$r['payment_id'], bookingRef($r['booking_id']), $r['payment_date'], $r['guest'], $r['payment_method'], $r['payment_reference'], $r['payment_status'], $r['amount']], $q->fetchAll(PDO::FETCH_ASSOC));
        csvDownload($name, ['Payment', 'Booking', 'Date', 'Guest', 'Method', 'Reference', 'Status', 'Amount (KES)'], $rows);
    default:
        http_response_code(400); echo 'Unknown export type.';
}