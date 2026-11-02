<?php
/**
 * Host identity verification.
 * A host submits legal name, ID details and a photo of their ID. An administrator checks them and verifies or
 * rejects the host. As soon as a decision is made the ID number and ID photo are erased: only the decision, legal
 * name and reason are kept. Photos live in private_uploads/ (never served directly) and are only shown to admins
 * through admin/verification_file.php.
 */

function hostVerificationPrivateDir() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private_uploads' . DIRECTORY_SEPARATOR . 'id_documents';
}

/** The host's verification row, or null if they never submitted one (or the table is not installed). */
function getHostVerification($pdo, $host_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM host_verifications WHERE host_id = ? LIMIT 1");
        $stmt->execute([(int)$host_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/** Returns [host_id => true] for the verified hosts among $ids. */
function verifiedHostIds($pdo, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT host_id FROM host_verifications WHERE status = 'verified' AND host_id IN ($in)");
        $stmt->execute($ids);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    } catch (Throwable $e) { return []; }
}

function guestVerifiedBadge() {
    return '<span title="An administrator checked this guest\'s identity" style="display:inline-block;margin-left:6px;padding:2px 8px;'
         . 'border-radius:999px;background:#E1F5EE;color:#0F6E56;font-size:11px;font-weight:600;white-space:nowrap">&#10003; Verified guest</span>';
}
function userVerifiedBadge($role) {
    return $role === 'host' ? hostVerifiedBadge() : guestVerifiedBadge();
}

function hostVerifiedBadge() {
    return '<span title="An administrator checked this host\'s identity" style="display:inline-block;margin-left:6px;padding:2px 8px;'
         . 'border-radius:999px;background:#E1F5EE;color:#0F6E56;font-size:11px;font-weight:600;white-space:nowrap">&#10003; Verified host</span>';
}

/** Store an uploaded ID photo privately. Type comes from the file content. Returns filename or false. */
function saveVerificationPhoto($tmpPath) {
    if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) return false;
    if (filesize($tmpPath) > 4 * 1024 * 1024) return false;
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][mime_content_type($tmpPath)] ?? null;
    if (!$ext) return false;
    $dir = hostVerificationPrivateDir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) return false;
    $name = 'id_' . bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($tmpPath, $dir . DIRECTORY_SEPARATOR . $name)) {
        // Allow plain copies too (command-line tests); real uploads always take the branch above.
        if (is_uploaded_file($tmpPath) || !@copy($tmpPath, $dir . DIRECTORY_SEPARATOR . $name)) return false;
    }
    @chmod($dir . DIRECTORY_SEPARATOR . $name, 0640);
    return $name;
}

function deleteVerificationPhoto($name) {
    if (!$name) return;
    $file = hostVerificationPrivateDir() . DIRECTORY_SEPARATOR . basename((string)$name);
    if (is_file($file)) @unlink($file);
}

/** Erase the sensitive parts of a verification record (used after a decision and when an account is deleted). */
function purgeVerificationSecrets($pdo, $host_id) {
    $row = getHostVerification($pdo, $host_id);
    if (!$row) return;
    $pdo->prepare("UPDATE host_verifications SET id_number = NULL, id_photo = NULL WHERE host_id = ?")->execute([(int)$host_id]);
    deleteVerificationPhoto($row['id_photo']);
}

/** Validate and store a host's submission. Returns ['success'=>bool,'errors'=>[...]]. */
function submitHostVerification($pdo, $host_id, array $post, $file, $role = 'host') {
    $errors = [];
    $legal  = trim((string)($post['legal_name'] ?? ''));
    $type   = ($post['id_type'] ?? '') === 'Passport' ? 'Passport' : 'National ID';
    $number = strtoupper(trim((string)($post['id_number'] ?? '')));
    $biz    = trim((string)($post['business_ref'] ?? ''));
    if (mb_strlen($legal, 'UTF-8') < 3 || mb_strlen($legal, 'UTF-8') > 120) $errors[] = 'Enter your full legal name as it appears on your ID.';
    if (!preg_match('/^[A-Z0-9]{6,14}$/', $number)) $errors[] = 'Enter your ID or passport number (6 to 14 letters or digits, no spaces).';
    if (mb_strlen($biz, 'UTF-8') > 60) $errors[] = 'The property proof reference must be under 60 characters.';
    if (empty($post['consent'])) $errors[] = 'Please confirm that the details are yours and correct.';
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $errors[] = 'Upload a clear photo of your ID (JPG, PNG or WebP, up to 4MB).';

    $current = getHostVerification($pdo, $host_id);
    if ($current && $current['status'] === 'pending')  $errors[] = 'Your verification request is already waiting for review.';
    if ($current && $current['status'] === 'verified') $errors[] = $role === 'tourist' ? 'You are already a verified guest.' : 'You are already a verified host.';
    if ($errors) return ['success' => false, 'errors' => $errors];

    $photo = saveVerificationPhoto($file['tmp_name']);
    if (!$photo) return ['success' => false, 'errors' => ['The ID photo must be a JPG, PNG or WebP image under 4MB.']];

    try {
        $pdo->prepare("
            INSERT INTO host_verifications (host_id, legal_name, id_type, id_number, id_photo, business_ref, status, reason, submitted_at, decided_at, decided_by)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NULL, NOW(), NULL, NULL)
            ON DUPLICATE KEY UPDATE legal_name = VALUES(legal_name), id_type = VALUES(id_type), id_number = VALUES(id_number),
                id_photo = VALUES(id_photo), business_ref = VALUES(business_ref), status = 'pending', reason = NULL,
                submitted_at = NOW(), decided_at = NULL, decided_by = NULL
        ")->execute([(int)$host_id, sanitize($legal), $type, $number, $photo, $biz !== '' ? sanitize($biz) : null]);
    } catch (Throwable $e) {
        deleteVerificationPhoto($photo);
        return ['success' => false, 'errors' => ['Your request could not be saved right now. Please try again.']];
    }
    return ['success' => true, 'errors' => []];
}
