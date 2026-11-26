<?php
/**
 * Certificates (v9.26): a printable "Host verification" certificate and "Listing approval" certificate, each with a number
 * that anyone can check on verify_certificate.php. A certificate is valid only while the host is still verified / the listing
 * is still approved. ID numbers are never shown (they are deleted after a decision).
 */
require_once __DIR__ . '/commission.php';   // RECEIPT_SECRET

if (!function_exists('ensureCertificatesTable')) {
    function ensureCertificatesTable($pdo) {
        if (function_exists('schemaIsUpgraded') && schemaIsUpgraded()) return;   // v9.33: tables are created once by the upgrade, not on every request
        static $done = false; if ($done) return; $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS certificates (
                cert_no VARCHAR(20) NOT NULL PRIMARY KEY,
                kind ENUM('host','listing') NOT NULL,
                subject_id INT NOT NULL,
                issued_to VARCHAR(160) NOT NULL,
                detail VARCHAR(255) NULL,
                issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                issued_by INT NULL,
                UNIQUE KEY uq_subject (kind, subject_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) { error_log('ensureCertificatesTable: ' . $e->getMessage()); }
    }
}
if (!function_exists('certificateNumber')) {
    function certificateNumber($kind, $subjectId) {
        $h = hash_hmac('sha256', 'cert|' . $kind . '|' . (int)$subjectId, RECEIPT_SECRET, true); $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; $o = '';
        for ($i = 0; $i < 10; $i++) $o .= $alpha[ord($h[$i]) % strlen($alpha)];
        return ($kind === 'host' ? 'HC-' : 'LC-') . $o;
    }
}
if (!function_exists('issueCertificate')) {
    /** First print records it; later prints show the same number and date. */
    function issueCertificate($pdo, $kind, $subjectId, $issuedTo, $detail, $issuedBy) {
        ensureCertificatesTable($pdo); $no = certificateNumber($kind, $subjectId);
        $pdo->prepare('INSERT IGNORE INTO certificates (cert_no, kind, subject_id, issued_to, detail, issued_by) VALUES (?,?,?,?,?,?)')->execute([$no, $kind, (int)$subjectId, mb_substr($issuedTo, 0, 160), mb_substr((string)$detail, 0, 255), $issuedBy]);
        $q = $pdo->prepare('SELECT * FROM certificates WHERE cert_no = ?'); $q->execute([$no]); return $q->fetch(PDO::FETCH_ASSOC);
    }
}
if (!function_exists('certificateIsValid')) {
    /** [valid(bool), reason] right now. */
    function certificateIsValid($pdo, array $c) {
        if ($c['kind'] === 'host') {
            $q = $pdo->prepare("SELECT status FROM host_verifications WHERE verification_id = ?"); $q->execute([$c['subject_id']]); $s = $q->fetchColumn();
            return $s === 'verified' ? [true, 'The host is currently verified.'] : [false, 'The host is no longer verified.'];
        }
        $q = $pdo->prepare("SELECT status FROM accommodations WHERE accommodation_id = ?"); $q->execute([$c['subject_id']]); $s = $q->fetchColumn();
        return $s === 'approved' ? [true, 'The listing is currently approved.'] : [false, 'The listing is no longer approved.'];
    }
}
