<?php
// "Continue on this device" button shown after opening the email link on a different device from the one that registered.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/verify_wait.php';

$base = appBasePath();
requireCsrf();
$h = $_SESSION['verified_here'] ?? null;
unset($_SESSION['verified_here']);

if (is_array($h) && !empty($h['user_id']) && ($h['expires'] ?? 0) >= time()) {
    $q = $pdo->prepare("SELECT user_id, full_name, email, role, status, email_verified, account_closed_at FROM users WHERE user_id = ? LIMIT 1");
    $q->execute([(int)$h['user_id']]);
    $u = $q->fetch(PDO::FETCH_ASSOC);
    $role = $u ? strtolower(trim((string)$u['role'])) : '';
    if ($u && (int)$u['email_verified'] && empty($u['account_closed_at']) && !in_array($u['status'], ['suspended', 'banned'], true)
        && in_array($role, ['tourist', 'host', 'admin'], true)) {
        startUserSession($u);
        setFlash('success', 'You are signed in. Welcome to StayExplore Kenya.');
        redirect($role === 'host' ? $base . '/host/profile.php' : roleHome($role));
    }
}
setFlash('info', 'That option has expired. Please sign in with your email and password.');
redirect($base . '/login.php');
