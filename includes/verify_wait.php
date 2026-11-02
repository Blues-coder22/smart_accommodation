<?php
/**
 * "Confirm on any device" sign-up, like Google's: the browser where someone registers keeps a waiting page open,
 * the person opens the email link on ANY device (a phone, say), and the waiting browser signs itself in.
 *
 * How it stays safe: the waiting browser holds a secret code in its own session (only a hash is stored in the
 * database). Only that browser can use it, only once, only within 30 minutes, and only after the email is verified.
 * The table is created automatically if missing.
 */
if (!function_exists('ensureVerifyWaitsTable')) {
    function ensureVerifyWaitsTable($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS verify_waits (
                wait_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_hash (token_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('startVerifyWait')) {
    /** Call in the browser that is waiting (right after registering, or after signing in with an unverified email). */
    function startVerifyWait($pdo, $user_id, $email) {
        ensureVerifyWaitsTable($pdo);
        if (session_status() === PHP_SESSION_NONE) session_start();
        $raw = bin2hex(random_bytes(32));
        $pdo->prepare("UPDATE verify_waits SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([(int)$user_id]);
        $pdo->prepare("INSERT INTO verify_waits (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))")
            ->execute([(int)$user_id, hash('sha256', $raw)]);
        $_SESSION['verify_wait'] = ['user_id' => (int)$user_id, 'token' => $raw, 'email' => (string)$email, 'last_sent' => time()];
    }
}

if (!function_exists('startUserSession')) {
    function startUserSession(array $u) {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $u['user_id'];
        $_SESSION['full_name'] = $u['full_name'];
        $_SESSION['email']     = $u['email'];
        $_SESSION['role']      = strtolower(trim((string)$u['role']));
    }
}

if (!function_exists('verifyWaitCheck')) {
    /** @return array ['state' => none|waiting|expired|blocked|verified, 'user' => row|null] */
    function verifyWaitCheck($pdo) {
        ensureVerifyWaitsTable($pdo);
        $w = $_SESSION['verify_wait'] ?? null;
        if (!is_array($w) || empty($w['user_id']) || empty($w['token'])) return ['state' => 'none', 'user' => null];
        $q = $pdo->prepare("SELECT wait_id, used_at, (expires_at > NOW()) AS alive FROM verify_waits WHERE user_id = ? AND token_hash = ? LIMIT 1");
        $q->execute([(int)$w['user_id'], hash('sha256', (string)$w['token'])]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['used_at'] !== null) return ['state' => 'none', 'user' => null];
        if (!(int)$row['alive']) return ['state' => 'expired', 'user' => null];
        $u = $pdo->prepare("SELECT user_id, full_name, email, role, status, email_verified, account_closed_at FROM users WHERE user_id = ? LIMIT 1");
        $u->execute([(int)$w['user_id']]);
        $user = $u->fetch(PDO::FETCH_ASSOC);
        if (!$user) return ['state' => 'none', 'user' => null];
        if (!(int)$user['email_verified']) return ['state' => 'waiting', 'user' => $user];
        $role = strtolower(trim((string)$user['role']));
        if (!empty($user['account_closed_at']) || in_array($user['status'], ['suspended', 'banned'], true) || !in_array($role, ['tourist', 'host', 'admin'], true)) {
            return ['state' => 'blocked', 'user' => $user];
        }
        return ['state' => 'verified', 'user' => $user];
    }
}

if (!function_exists('verifyWaitComplete')) {
    /** Signs this browser in (once) and returns where to send it. Only call when verifyWaitCheck() said 'verified'. */
    function verifyWaitComplete($pdo, array $user) {
        $w = $_SESSION['verify_wait'];
        $pdo->prepare("UPDATE verify_waits SET used_at = NOW() WHERE user_id = ? AND token_hash = ? AND used_at IS NULL")
            ->execute([(int)$w['user_id'], hash('sha256', (string)$w['token'])]);
        unset($_SESSION['verify_wait']);
        startUserSession($user);
        $base = function_exists('appBasePath') ? appBasePath() : '/smart_accommodation';
        setFlash('success', 'Email verified. Welcome to StayExplore Kenya, you are now signed in.'
            . ($user['role'] === 'host' ? ' Open your Host profile to submit your ID for identity verification.' : ''));
        return $user['role'] === 'host' ? $base . '/host/profile.php' : roleHome($user['role']);
    }
}
