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


/*
 * Verification CODE (works even when the phone cannot open the link, e.g. the site runs on a PC the phone cannot reach).
 * The email carries a 6-digit code; typing it into the waiting browser verifies the email and signs that browser in.
 * Only a keyed hash is stored, the code expires after 30 minutes and is locked after 5 wrong tries.
 */
if (!function_exists('ensureVerifyCodesTable')) {
    function ensureVerifyCodesTable($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS verify_codes (
                user_id INT NOT NULL PRIMARY KEY,
                code_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                attempts TINYINT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}

if (!function_exists('verifyCodeHash')) {
    function verifyCodeHash($user_id, $code) {
        return hash('sha256', 'verify-code|' . (int)$user_id . '|' . preg_replace('/\D/', '', (string)$code));
    }
}

if (!function_exists('issueVerifyCode')) {
    /** Creates (replacing any older) 6-digit code for this user and returns it for the email. */
    function issueVerifyCode($pdo, $user_id) {
        ensureVerifyCodesTable($pdo);
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pdo->prepare("REPLACE INTO verify_codes (user_id, code_hash, expires_at, attempts) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), 0)")
            ->execute([(int)$user_id, verifyCodeHash($user_id, $code)]);
        return $code;
    }
}

if (!function_exists('verifyCodeSubmit')) {
    /** @return array ['ok' => bool, 'message' => string]. On success the user's email is marked verified. */
    function verifyCodeSubmit($pdo, $user_id, $code) {
        ensureVerifyCodesTable($pdo);
        $code = preg_replace('/\D/', '', (string)$code);
        if (strlen($code) !== 6) return ['ok' => false, 'message' => 'Enter the 6-digit code from the email.'];
        $q = $pdo->prepare("SELECT code_hash, attempts, (expires_at > NOW()) AS alive FROM verify_codes WHERE user_id = ? LIMIT 1");
        $q->execute([(int)$user_id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || !(int)$row['alive']) return ['ok' => false, 'message' => 'That code has expired. Press "Send the email again" to get a new one.'];
        if ((int)$row['attempts'] >= 5) return ['ok' => false, 'message' => 'Too many wrong tries. Press "Send the email again" to get a new code.'];
        if (!hash_equals($row['code_hash'], verifyCodeHash($user_id, $code))) {
            $pdo->prepare("UPDATE verify_codes SET attempts = attempts + 1 WHERE user_id = ?")->execute([(int)$user_id]);
            $left = max(0, 4 - (int)$row['attempts']);
            return ['ok' => false, 'message' => 'That code is not right.' . ($left > 0 ? ' ' . $left . ' more ' . ($left === 1 ? 'try' : 'tries') . ' left.' : ' Request a new code.')];
        }
        $pdo->prepare("DELETE FROM verify_codes WHERE user_id = ?")->execute([(int)$user_id]);
        $pdo->prepare("UPDATE users SET email_verified = 1, verification_token = NULL WHERE user_id = ?")->execute([(int)$user_id]);
        return ['ok' => true, 'message' => 'Verified.'];
    }
}
