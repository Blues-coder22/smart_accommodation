<?php
require_once __DIR__ . '/upload_helpers.php';
require_once __DIR__ . '/profile_photo.php';
function updateProfile($pdo, $user_id, $data) {
    $full_name   = sanitize($data['full_name'] ?? '');
    $phone       = sanitize($data['phone'] ?? '');
    $errors      = [];

    if (empty($full_name))
        $errors[] = 'Full name is required.';

    // Handle profile photo upload
    $photo = $data['existing_photo'] ?? null;
    $newPhotoMime = null;
    if (isset($data['photo_file'])
        && $data['photo_file']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg','image/png','image/webp'];
        $ftype   = mime_content_type(
            $data['photo_file']['tmp_name']);
        if (!in_array($ftype, $allowed)) {
            $errors[] = 'Photo must be JPG, PNG or WebP.';
        } elseif ($data['photo_file']['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Photo must be under 2MB.';
        } else {
            $filename = saveUploadedImage(
                $data['photo_file']['tmp_name'], $ftype,
                'profiles', 'profile_' . (int)$user_id,
                2 * 1024 * 1024
            );
            if ($filename !== false) {
                $photo = $filename;
                $newPhotoMime = $ftype;
            } else {
                $errors[] = 'The profile photo could not be saved. Please try again.';
            }
        }
    }

    if (!empty($errors)) return ['success'=>false,'errors'=>$errors];

    $pdo->prepare("
        UPDATE users
        SET full_name = ?, phone_number = ?,
            profile_photo = ?
        WHERE user_id = ?
    ")->execute([$full_name, $phone, $photo, $user_id]);

    // Keep the picture itself in the database too, so it survives replacing the project folder.
    if ($newPhotoMime !== null) {
        saveProfilePhotoToDb($pdo, $user_id, $photo, $newPhotoMime);
    }

    // Update session name
    $_SESSION['full_name'] = $full_name;

    return ['success'=>true];
}

function updatePassword($pdo, $user_id, $current, $new, $confirm) {
    $errors = [];

    if (empty($current) || empty($new) || empty($confirm))
        $errors[] = 'All password fields are required.';
    if ($new !== $confirm)
        $errors[] = 'New passwords do not match.';
    if (strlen($new) < 8)
        $errors[] = 'New password must be at least 8 characters.';

    if (!empty($errors)) return ['success'=>false,'errors'=>$errors];

    // Verify current password
    $stmt = $pdo->prepare("
        SELECT password FROM users WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!password_verify($current, $user['password'])) {
        return ['success'=>false,
                'errors'=>['Current password is incorrect.']];
    }

    $hashed = password_hash($new, PASSWORD_BCRYPT);
    $pdo->prepare("
        UPDATE users SET password = ? WHERE user_id = ?
    ")->execute([$hashed, $user_id]);

    return ['success'=>true];
}


/**
 * Close an account without deleting linked booking/payment/review history.
 * Requires database/migration_account_closure.sql to be applied first.
 */
function closeUserAccount($pdo, $user_id, $password) {
    $stmt = $pdo->prepare("SELECT password, role, account_closed_at FROM users WHERE user_id = ? LIMIT 1");
    $stmt->execute([(int)$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify((string)$password, (string)$user['password'])) {
        return ['success' => false, 'errors' => ['Your current password is incorrect.']];
    }
    if (!empty($user['account_closed_at'])) {
        return ['success' => false, 'errors' => ['This account is already closed.']];
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET account_closed_at = NOW() WHERE user_id = ? AND account_closed_at IS NULL")
            ->execute([(int)$user_id]);
        // A closed host must not continue receiving new bookings through approved listings.
        if ($user['role'] === 'host') {
            $pdo->prepare("UPDATE accommodations SET status = 'suspended' WHERE host_id = ? AND status = 'approved'")
                ->execute([(int)$user_id]);
        }
        $pdo->commit();
        return ['success' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'errors' => ['The account could not be closed right now. Please try again or contact the administrator.']];
    }
}


/**
 * Permanently delete an account.
 *
 * The user row is kept but ALL personal data is erased and cannot be recovered: name, email, phone, photo, password,
 * sign-in tokens, favourites and the texts of the person's own chat messages. Bookings, payments, reviews and reports
 * keep pointing at the (now anonymous "Deleted user") row, so no other table is disturbed and hosts/admins keep their
 * financial and safety records. Refused while the person still has upcoming bookings.
 */
function deleteUserAccount($pdo, $user_id, $password, $typed) {
    $user_id = (int)$user_id;
    $stmt = $pdo->prepare("SELECT password, role, profile_photo, account_deleted_at FROM users WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || !empty($user['account_deleted_at'])) {
        return ['success' => false, 'errors' => ['This account cannot be deleted.']];
    }
    if ($user['role'] === 'admin') {
        return ['success' => false, 'errors' => ['Administrator accounts cannot be deleted here.']];
    }
    if (!password_verify((string)$password, (string)$user['password'])) {
        return ['success' => false, 'errors' => ['Your current password is incorrect.']];
    }
    if (trim((string)$typed) !== 'DELETE') {
        return ['success' => false, 'errors' => ['Type DELETE (in capitals) to confirm.']];
    }

    // Upcoming or in-progress stays must be finished or cancelled first.
    if ($user['role'] === 'host') {
        $open = $pdo->prepare("SELECT COUNT(*) FROM bookings b JOIN accommodations a ON a.accommodation_id = b.accommodation_id
                               WHERE a.host_id = ? AND b.status IN ('pending','confirmed') AND b.check_out_date >= CURDATE()");
    } else {
        $open = $pdo->prepare("SELECT COUNT(*) FROM bookings b WHERE b.tourist_id = ? AND b.status IN ('pending','confirmed')
                               AND b.check_out_date >= CURDATE()");
    }
    $open->execute([$user_id]);
    if ((int)$open->fetchColumn() > 0) {
        return ['success' => false, 'errors' => [$user['role'] === 'host'
            ? 'You still have upcoming or pending bookings on your listings. Complete or cancel them first, then delete your account.'
            : 'You still have an upcoming or pending booking. Cancel it (or wait until the stay is over), then delete your account.']];
    }

    $verRow = ($user['role'] === 'host') ? getHostVerification($pdo, $user_id) : null;
    $verPhoto = $verRow['id_photo'] ?? null;
    $anonEmail = 'deleted-' . $user_id . '-' . bin2hex(random_bytes(4)) . '@deleted.invalid';
    $randomPw  = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET full_name = 'Deleted user', email = ?, password = ?, phone_number = NULL,
                       profile_photo = NULL, verification_token = NULL, email_verified = 0, status = 'banned',
                       account_closed_at = COALESCE(account_closed_at, NOW()), account_deleted_at = NOW()
                       WHERE user_id = ?")->execute([$anonEmail, $randomPw, $user_id]);
        try { $pdo->prepare("UPDATE users SET profile_photo_data = NULL, profile_photo_mime = NULL WHERE user_id = ?")->execute([$user_id]); } catch (Throwable $e) { /* column not installed */ }
        $pdo->prepare("DELETE FROM favourites WHERE tourist_id = ?")->execute([$user_id]);
        try { $pdo->prepare("DELETE FROM host_verifications WHERE host_id = ?")->execute([$user_id]); } catch (Throwable $e) { /* table not installed */ }
        try {
            $pdo->prepare("UPDATE messages SET body = '[Message deleted]' WHERE sender_id = ?")->execute([$user_id]);
        } catch (Throwable $e) { /* messaging tables not installed: nothing to scrub */ }
        if ($user['role'] === 'host') {
            // Listings stay (their bookings and reviews reference them) but are taken off the site for good.
            $pdo->prepare("UPDATE accommodations SET status = 'suspended' WHERE host_id = ? AND status IN ('approved','pending')")->execute([$user_id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['success' => false, 'errors' => ['The account could not be deleted right now. Please try again or contact the administrator.']];
    }

    if ($user['role'] === 'host' && !empty($verPhoto)) deleteVerificationPhoto($verPhoto);
    // Remove the profile picture file (after the database change succeeded).
    if (!empty($user['profile_photo'])) {
        $file = realpath(__DIR__ . '/../uploads/profiles') . DIRECTORY_SEPARATOR . basename((string)$user['profile_photo']);
        if (is_file($file)) @unlink($file);
    }
    return ['success' => true];
}

?>