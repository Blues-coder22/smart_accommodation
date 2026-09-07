<?php
require_once 'includes/auth.php';

$token = sanitize($_GET['token'] ?? '');

if (empty($token)) {
    redirect('login.php');
}

$result = verifyEmail($token);

if ($result['success']) {
    setFlash('success', 'Email verified successfully. You can now log in.');
} else {
    setFlash('error', $result['message']);
}

redirect('login.php');
?>