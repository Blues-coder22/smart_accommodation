<?php
/**
 * SMS verification codes (v9.29).
 *
 * People can confirm their account with a 6-digit code sent by SMS instead of the emailed link. The code is the same kind of
 * code the email carries (verify_codes table, 30 minutes, 5 tries), only delivered by text message.
 *
 * Provider: Africa's Talking (https://africastalking.com), which has Kenyan sender IDs and local pricing. The site only calls its
 * web address with PHP's built-in cURL, so there is NOTHING to install and no Composer package is needed.
 * Settings go in includes/config.php (see config.example.php): SMS_PROVIDER, SMS_USERNAME, SMS_API_KEY, SMS_SENDER_ID.
 * Until they are filled in, texts are only written to logs/sms.log (like emails go to logs/mail.log), so you can develop without paying.
 * SMS costs money per message, so sending is limited per phone number and per internet address (see smsSendAllowed()).
 */
if (is_file(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
if (!function_exists('smsSetting')) {
    function smsSetting($name, $default = '') {
        $v = getenv($name);
        if ($v !== false && $v !== '') return $v;
        return defined($name) ? constant($name) : $default;
    }
}
if (!function_exists('smsIsConfigured')) {
    function smsIsConfigured() {
        return strtolower(smsSetting('SMS_PROVIDER')) === 'africastalking' && smsSetting('SMS_USERNAME') !== '' && smsSetting('SMS_API_KEY') !== '';
    }
}

if (!function_exists('normalizePhone')) {
    /**
     * Kenyan numbers in any usual style (0712 345 678, 712345678, +254712345678, 254 712 345 678) become +254712345678.
     * Other countries need the international form (+256..., 00256...). Returns null when it does not look like a phone number.
     */
    function normalizePhone($raw) {
        $s = preg_replace('/[\s\-\.\(\)]/', '', (string)$raw);
        if ($s === '') return null;
        if (strpos($s, '00') === 0) $s = '+' . substr($s, 2);
        if (preg_match('/^\+?254([17]\d{8})$/', $s, $m)) return '+254' . $m[1];
        if (preg_match('/^0([17]\d{8})$/', $s, $m))      return '+254' . $m[1];
        if (preg_match('/^([17]\d{8})$/', $s, $m))        return '+254' . $m[1];
        if (preg_match('/^\+[1-9]\d{7,14}$/', $s) && strpos($s, '+254') !== 0) return $s;
        return null;
    }
}
if (!function_exists('maskPhone')) {
    function maskPhone($p) { $p = (string)$p; return strlen($p) > 7 ? substr($p, 0, 4) . str_repeat('•', max(0, strlen($p) - 7)) . substr($p, -3) : $p; }
}

if (!function_exists('ensureSmsTables')) {
    function ensureSmsTables($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sms_log (
                sms_id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                phone VARCHAR(20) NOT NULL,
                ip VARCHAR(45) NULL,
                status VARCHAR(20) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_phone (phone, created_at), KEY idx_ip (ip, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'phone_verified'")->fetch()) $pdo->exec("ALTER TABLE users ADD COLUMN phone_verified TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Throwable $e) { error_log('ensureSmsTables: ' . $e->getMessage()); }
    }
}

if (!function_exists('smsSendAllowed')) {
    /** Protects your SMS bill: at most 5 texts per phone and 10 per internet address in the last hour. */
    function smsSendAllowed($pdo, $phone) {
        ensureSmsTables($pdo);
        try {
            $a = $pdo->prepare("SELECT COUNT(*) FROM sms_log WHERE phone = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"); $a->execute([$phone]);
            if ((int)$a->fetchColumn() >= 5) return false;
            $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            if ($ip !== '') { $b = $pdo->prepare("SELECT COUNT(*) FROM sms_log WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"); $b->execute([$ip]); if ((int)$b->fetchColumn() >= 10) return false; }
        } catch (Throwable $e) {}
        return true;
    }
}

if (!function_exists('sendSms')) {
    /**
     * @return string 'sent' (the provider accepted it), 'logged' (SMS not set up: written to logs/sms.log only),
     *                'offline' (no internet), or 'failed'.
     */
    function sendSms($to, $message) {
        if (!smsIsConfigured()) {
            $dir = __DIR__ . '/../logs';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($dir . '/sms.log', '==== ' . date('Y-m-d H:i:s') . " ====\nTo: $to\n$message\n\n", FILE_APPEND | LOCK_EX);
            return 'logged';
        }
        if (!function_exists('curl_init')) { error_log('SMS not sent: the PHP cURL extension is not enabled.'); return 'failed'; }
        $user = smsSetting('SMS_USERNAME');
        $host = $user === 'sandbox' ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com';
        $fields = ['username' => $user, 'to' => $to, 'message' => $message];
        if (smsSetting('SMS_SENDER_ID') !== '') $fields['from'] = smsSetting('SMS_SENDER_ID');
        $ch = curl_init($host . '/version1/messaging');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['apiKey: ' . smsSetting('SMS_API_KEY'), 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch); $errno = curl_errno($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($body === false || $errno) { error_log('SMS cURL error ' . $errno); return in_array($errno, [6, 7, 28], true) ? 'offline' : 'failed'; }
        $j = json_decode((string)$body, true);
        $rec = $j['SMSMessageData']['Recipients'][0] ?? null;
        if ($http >= 200 && $http < 300 && $rec && (int)($rec['statusCode'] ?? 0) >= 100 && (int)$rec['statusCode'] <= 102) return 'sent';   // 100 processed, 101 sent, 102 queued
        error_log('SMS rejected by provider: HTTP ' . $http . ' ' . substr((string)$body, 0, 300));
        return 'failed';
    }
}

if (!function_exists('sendVerificationSms')) {
    /**
     * Issues a fresh 6-digit code for this user and texts it to their phone number.
     * @return string 'sent' | 'logged' | 'offline' | 'failed' | 'limited' | 'no_phone'
     */
    function sendVerificationSms($pdo, $userId) {
        require_once __DIR__ . '/verify_wait.php';
        ensureSmsTables($pdo); ensureVerifyCodesTable($pdo);
        $q = $pdo->prepare('SELECT phone_number FROM users WHERE user_id = ? LIMIT 1'); $q->execute([(int)$userId]);
        $phone = normalizePhone($q->fetchColumn());
        if (!$phone) return 'no_phone';
        if (!smsSendAllowed($pdo, $phone)) return 'limited';
        $old = null;
        try { $o = $pdo->prepare('SELECT code_hash, expires_at, attempts FROM verify_codes WHERE user_id = ?'); $o->execute([(int)$userId]); $old = $o->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
        $code = issueVerifyCode($pdo, (int)$userId);
        $res = sendSms($phone, "StayExplore Kenya: your verification code is $code. It expires in 30 minutes. Never share it with anyone.");
        try { $pdo->prepare('INSERT INTO sms_log (user_id, phone, ip, status) VALUES (?,?,?,?)')->execute([(int)$userId, $phone, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), $res]); } catch (Throwable $e) {}
        if ($res === 'offline' || $res === 'failed') {   // nothing reached the phone: put the earlier code back
            try { if ($old) $pdo->prepare('REPLACE INTO verify_codes (user_id, code_hash, expires_at, attempts) VALUES (?,?,?,?)')->execute([(int)$userId, $old['code_hash'], $old['expires_at'], $old['attempts']]);
                  else $pdo->prepare('DELETE FROM verify_codes WHERE user_id = ?')->execute([(int)$userId]); } catch (Throwable $e) {}
        }
        return $res;
    }
}
if (!function_exists('smsResultMessage')) {
    function smsResultMessage($r) {
        return [
            'sent'     => ['success', 'A new code is on its way by SMS. It can take a minute to arrive.'],
            'logged'   => ['warning', 'SMS is not set up on this server yet, so no text was sent. (Developer: the code is in logs/sms.log.)'],
            'offline'  => ['warning', 'We can’t send SMS right now (no internet connection). Please try again, or use email instead.'],
            'failed'   => ['error', 'We couldn’t send the SMS just now. Please try again in a little while, or use email instead.'],
            'limited'  => ['error', 'Too many codes were requested for this number. Please wait an hour, or use email instead.'],
            'no_phone' => ['error', 'There is no valid phone number on this account. Use email instead.'],
        ][$r] ?? ['error', 'We couldn’t send the SMS.'];
    }
}
