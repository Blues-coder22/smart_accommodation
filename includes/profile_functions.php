<?php
function updateProfile($pdo, $user_id, $data) {
    $full_name   = sanitize($data['full_name'] ?? '');
    $phone       = sanitize($data['phone'] ?? '');
    $errors      = [];

    if (empty($full_name))
        $errors[] = 'Full name is required.';

    // Handle profile photo upload
    $photo = $data['existing_photo'] ?? null;
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
            $ext      = pathinfo(
                $data['photo_file']['name'], PATHINFO_EXTENSION
            );
            $filename = 'profile_' . $user_id . '_'
                        . uniqid() . '.' . $ext;
            $dest     = '../uploads/profiles/' . $filename;
            if (move_uploaded_file(
                $data['photo_file']['tmp_name'], $dest)) {
                $photo = $filename;
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
?>