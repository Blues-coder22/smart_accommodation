<?php
/**
 * Slows down password guessing: after 5 wrong passwords for the same email from the same device (IP)
 * within 15 minutes, further attempts are refused until the 15 minutes are over.
 * The table is created automatically if missing.
 */
const LOGIN_MAX_FAILS_PER_EMAIL = 5;
const LOGIN_MAX_FAILS_PER_IP    = 20;
const LOGIN_WINDOW_MINUTES      = 15;

if (!function_exists('ensureLoginAttemptsTable')) {
    function ensureLoginAttemptsTable($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                attempt_id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(190) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_ip (email, ip, attempted_at),
                INDEX idx_ip (ip, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {}
    }
}
if (!function_exists('loginClientIp')) {
    function loginClientIp() { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45); }
}
if (!function_exists('loginLockedMinutes')) {
    /** Minutes the person must still wait, or 0 when they may try. */
    function loginLockedMinutes($pdo, $email) {
        ensureLoginAttemptsTable($pdo);
        $email = strtolower(trim((string)$email)); $ip = loginClientIp();
        try {
            $q = $pdo->prepare("SELECT COUNT(*) AS n, MIN(attempted_at) AS first_at FROM login_attempts
                                WHERE email = ? AND ip = ? AND attempted_at > (NOW() - INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE)");
            $q->execute([$email, $ip]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            $byIp = $pdo->prepare("SELECT COUNT(*) AS n, MIN(attempted_at) AS first_at FROM login_attempts
                                   WHERE ip = ? AND attempted_at > (NOW() - INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE)");
            $byIp->execute([$ip]);
            $i = $byIp->fetch(PDO::FETCH_ASSOC);
            $first = null;
            if ((int)$r['n'] >= LOGIN_MAX_FAILS_PER_EMAIL) $first = $r['first_at'];
            elseif ((int)$i['n'] >= LOGIN_MAX_FAILS_PER_IP) $first = $i['first_at'];
            if ($first === null) return 0;
            $left = LOGIN_WINDOW_MINUTES * 60 - (time() - strtotime($first));
            return $left > 0 ? (int)ceil($left / 60) : 0;
        } catch (Throwable $e) { return 0; }
    }
}
if (!function_exists('loginRecordFailure')) {
    function loginRecordFailure($pdo, $email) {
        ensureLoginAttemptsTable($pdo);
        try {
            $pdo->prepare("INSERT INTO login_attempts (email, ip) VALUES (?, ?)")->execute([strtolower(trim((string)$email)), loginClientIp()]);
            if (mt_rand(1, 50) === 1) $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
        } catch (Throwable $e) {}
    }
}
if (!function_exists('loginClearFailures')) {
    function loginClearFailures($pdo, $email) {
        try { $pdo->prepare("DELETE FROM login_attempts WHERE email = ? AND ip = ?")->execute([strtolower(trim((string)$email)), loginClientIp()]); } catch (Throwable $e) {}
    }
}
