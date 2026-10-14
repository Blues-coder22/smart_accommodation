<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

// Register new user
function registerUser($full_name, $email, $password, $role, $phone) {
    global $pdo;

    // Check if email already exists
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Email already registered'];
    }

    // Hash password
    $hashed = password_hash($password, PASSWORD_BCRYPT);

    // Generate email verification token
    $token = bin2hex(random_bytes(32));

    // Insert user
    $stmt = $pdo->prepare("
        INSERT INTO users 
        (full_name, email, password, role, phone_number, 
         email_verified, status, verification_token)
        VALUES (?, ?, ?, ?, ?, 0, 'active', ?)
    ");
    $stmt->execute([$full_name, $email, $hashed, $role, $phone, $token]);

    return [
        'success' => true,
        'user_id' => $pdo->lastInsertId(),
        'token'   => $token
    ];
}

// Login user
function loginUser($email, $password) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT user_id, full_name, email, password, role, 
               email_verified, status 
        FROM users 
        WHERE email = ?
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid email or password'];
    }

    if (!password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid email or password'];
    }

    if ($user['status'] === 'suspended') {
        return ['success' => false, 'message' => 'Your account has been suspended'];
    }

    if ($user['status'] === 'banned') {
        return ['success' => false, 'message' => 'Your account has been banned'];
    }

    if (!$user['email_verified']) {
        return ['success' => false, 'message' => 'Please verify your email before logging in'];
    }

    // Set session
    $_SESSION['user_id']   = $user['user_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['role']      = $user['role'];

    return ['success' => true, 'role' => $user['role']];
}

// Verify email token
function verifyEmail($token) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT user_id FROM users 
        WHERE verification_token = ? AND email_verified = 0
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid or expired verification link'];
    }

    $stmt = $pdo->prepare("
        UPDATE users 
        SET email_verified = 1, verification_token = NULL 
        WHERE user_id = ?
    ");
    $stmt->execute([$user['user_id']]);

    return ['success' => true];
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check user role
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['return_url'] = $_SERVER['REQUEST_URI'];
        header('Location: /smart_accommodation/login.php');
        exit;
    }
}

// Redirect if wrong role
function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        header('Location: /smart_accommodation/index.php');
        exit;
    }
}

// Get current user details
function getCurrentUser() {
    global $pdo;
    if (!isLoggedIn()) return null;

    $stmt = $pdo->prepare("
        SELECT user_id, full_name, email, role, 
               phone_number, profile_photo, status 
        FROM users 
        WHERE user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

// Logout
function logoutUser() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    session_unset();
    session_destroy();
    header('Location: /smart_accommodation/login.php');
    exit;
}
?>