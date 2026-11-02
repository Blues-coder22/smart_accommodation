<?php
// Polled by the "Confirm your email" waiting page. Signs the waiting browser in once the email is verified.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/verify_wait.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$c = verifyWaitCheck($pdo);
if ($c['state'] === 'verified') {
    echo json_encode(['status' => 'verified', 'redirect' => verifyWaitComplete($pdo, $c['user'])]);
} else {
    echo json_encode(['status' => $c['state']]);
}
