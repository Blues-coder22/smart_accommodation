<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

// Never let the browser keep a copy of a page that depends on who is signed in. Without this, Back after
// signing out can show the old signed-in page, and an old login form carries a security token that no longer
// matches.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/login_throttle.php';

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

    // Too many wrong passwords recently? Ask them to wait instead of letting anyone keep guessing.
    $wait = loginLockedMinutes($pdo, $email);
    if ($wait > 0) {
        return ['success' => false, 'message' => 'Too many failed sign-in attempts. For your security, please try again in ' . $wait . ' minute' . ($wait === 1 ? '' : 's') . ', or reset your password.'];
    }

    $stmt = $pdo->prepare("
        SELECT user_id, full_name, email, password, role, 
               email_verified, status, account_closed_at 
        FROM users 
        WHERE email = ?
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        loginRecordFailure($pdo, $email);
        return ['success' => false, 'message' => 'Invalid email or password'];
    }

    if (!password_verify($password, $user['password'])) {
        loginRecordFailure($pdo, $email);
        return ['success' => false, 'message' => 'Invalid email or password'];
    }
    loginClearFailures($pdo, $email);

    if (!empty($user['account_closed_at'])) {
        return ['success' => false, 'message' => 'This account has been closed. Please contact support if you need assistance.'];
    }

    if ($user['status'] === 'suspended') {
        return ['success' => false, 'message' => 'Your account has been suspended'];
    }

    if ($user['status'] === 'banned') {
        return ['success' => false, 'message' => 'Your account has been banned'];
    }

    if (!$user['email_verified']) {
        return ['success' => false, 'unverified' => true, 'user_id' => (int)$user['user_id'], 'email' => $user['email'],
                'message' => 'Please verify your email before logging in'];
    }

    // Set session
    // Normalize the role before storing it so routing and access checks agree.
    $role = strtolower(trim((string)$user['role']));
    if (!in_array($role, ['tourist', 'host', 'admin'], true)) {
        return ['success' => false, 'message' => 'This account has an invalid role. Please contact the administrator.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['user_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['role']      = $role;

    return ['success' => true, 'role' => $role];
}

// Verify email token
function verifyEmail($token) {
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT user_id, role FROM users 
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

    return ['success' => true, 'user_id' => (int)$user['user_id'], 'role' => strtolower(trim((string)$user['role']))];
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check user role
function hasRole($role) {
    return isset($_SESSION['role']) && strtolower(trim((string)$_SESSION['role'])) === strtolower(trim((string)$role));
}

// Return the correct landing page for each authenticated role.
function roleHome($role = null) {
    $role = strtolower(trim((string)($role ?? ($_SESSION['role'] ?? ''))));
    $base = function_exists('appBasePath') ? appBasePath() : '/smart_accommodation';
    if ($role === 'host') return $base . '/host/dashboard.php';
    if ($role === 'admin') return $base . '/admin/dashboard.php';
    if ($role === 'tourist') return $base . '/tourist/search.php?type=accommodation';
    return $base . '/index.php';
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['return_url'] = $_SERVER['REQUEST_URI'];
        $base = function_exists('appBasePath') ? appBasePath() : '/smart_accommodation';
        header('Location: ' . $base . '/login.php');
        exit;
    }
}

// Redirect if wrong role
function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        // Keep each account inside its own area instead of sending users to a dead-end homepage.
        header('Location: ' . roleHome());
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
    // Use this copy's own address. A fixed '/smart_accommodation/login.php' sent people to a different
    // (older) copy of the site whenever the project folder had another name.
    $base = function_exists('appBasePath') ? appBasePath() : rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    header('Location: ' . $base . '/login.php');
    exit;
}
?>