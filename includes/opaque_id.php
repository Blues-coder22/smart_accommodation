<?php
/**
 * Opaque public tokens for internal ids (v9.23).
 *
 * Payment, commission-ledger and remittance ids are sequential database numbers. They are never put in a URL, a form or on
 * screen; people get a token instead. A token is the id encrypted and authenticated (AES-256-GCM) with a secret key that is
 * generated once and stored in site_settings ('id_token_key'). It cannot be guessed, edited or reused for another kind of
 * record (the kind is bound into the token), and no table or column is needed. Every page still checks ownership as before.
 */
if (!function_exists('opaqueIdKey')) {
    function opaqueIdKey() {
        static $key = null;
        if ($key !== null) return $key;
        $pdo = $GLOBALS['pdo'] ?? null;
        $hex = '';
        if ($pdo) {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
                    setting_key VARCHAR(60) NOT NULL PRIMARY KEY, setting_value VARCHAR(255) NOT NULL,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, updated_by INT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $pdo->prepare("INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES ('id_token_key', ?)")->execute([bin2hex(random_bytes(32))]);
                $q = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'id_token_key'"); $q->execute();
                $hex = (string)$q->fetchColumn();
            } catch (Throwable $e) { error_log('opaqueIdKey: ' . $e->getMessage()); }
        }
        if (strlen($hex) !== 64) $hex = hash('sha256', 'fallback|' . (defined('RECEIPT_SECRET') ? RECEIPT_SECRET : 'x'));   // only if the database is unreachable
        return $key = hex2bin($hex);
    }
}
if (!function_exists('opaqueId')) {
    /** Public token for $id of kind $type ('pay' = host payment, 'plat' = platform payment). The same id always gives the same token. */
    function opaqueId($type, $id) {
        $id = (int)$id;
        if ($id <= 0) return '';
        $key = opaqueIdKey();
        $iv = substr(hash_hmac('sha256', $type . '|' . $id, $key, true), 0, 12);   // fixed per id and kind, so links stay stable
        $tag = '';
        $ct = openssl_encrypt(pack('N', $id), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $type, 8);
        return rtrim(strtr(base64_encode($iv . $tag . $ct), '+/', '-_'), '=');
    }
}
if (!function_exists('opaqueIdDecode')) {
    /** The internal id behind a token, or 0 when the token is missing, altered or of another kind. Plain numbers are rejected. */
    function opaqueIdDecode($type, $token) {
        $raw = base64_decode(strtr((string)$token, '-_', '+/'), true);
        if ($raw === false || strlen($raw) !== 12 + 8 + 4) return 0;
        $pt = openssl_decrypt(substr($raw, 20), 'aes-256-gcm', opaqueIdKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 8), $type);
        return ($pt === false || strlen($pt) !== 4) ? 0 : (int)unpack('N', $pt)[1];
    }
}
