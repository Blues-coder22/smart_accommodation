<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/functions.php';

// The reset token is in the address, so never leak it to other sites through the Referer header.
header('Referrer-Policy: no-referrer');

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$valid = false; $reset = null; $errors = [];

if (preg_match('/^[0-9a-f]{64}$/', $token)) {
    $stmt = $pdo->prepare("SELECT pr.reset_id, pr.user_id, u.full_name, u.email
                           FROM password_resets pr JOIN users u ON u.user_id = pr.user_id
                           WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
                             AND u.account_deleted_at IS NULL AND u.account_closed_at IS NULL LIMIT 1");
    $stmt->execute([hash('sha256', $token)]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);
    $valid = (bool)$reset;
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if (strlen($new) < 8) $errors[] = 'Your new password must be at least 8 characters.';
    if ($new !== $confirm) $errors[] = 'The two passwords do not match.';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            // Consume the token first; if another request already used it, rowCount() is 0 and nothing changes.
            $use = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE reset_id = ? AND used_at IS NULL AND expires_at > NOW()");
            $use->execute([$reset['reset_id']]);
            if ($use->rowCount() !== 1) {
                $pdo->rollBack();
                $valid = false;
            } else {
                $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?")
                    ->execute([password_hash($new, PASSWORD_BCRYPT), $reset['user_id']]);
                // Any other outstanding reset links for this account stop working.
                $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$reset['user_id']]);
                $pdo->commit();
                sendPasswordChangedEmail($reset['email'], $reset['full_name']);
                setFlash('success', 'Your password has been changed. Sign in with your new password.');
                redirect('login.php');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Password reset failed: ' . $e->getMessage());
            $errors[] = 'Your password could not be changed right now. Please try again.';
        }
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reset password — StayExplore Kenya</title><link rel="stylesheet" href="assets/css/style.css"><meta name="robots" content="noindex"></head>
<body><?php include __DIR__ . '/includes/header.php'; ?><main class="auth-page"><section class="auth-visual"><div class="auth-copy"><div class="eyebrow">Almost there</div><h1>Choose a new password.</h1><p>Use at least 8 characters.</p></div></section><section class="auth-form"><div class="auth-box"><h1>Reset password</h1>
<?php if (!$valid): ?>
<div class="alert alert-error"><p>This reset link is invalid, has expired, or has already been used.</p></div>
<p class="small" style="margin-top:18px"><a class="home-link" href="forgot-password.php">Request a new link</a> &middot; <a class="home-link" href="login.php">Back to sign in</a></p>
<?php else: ?>
<?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $e): ?><p><?= h($e) ?></p><?php endforeach; ?></div><?php endif; ?>
<form method="post" class="grid" style="margin-top:20px" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="token" value="<?= h($token) ?>">
<div class="field"><label for="new_password">New password</label><input id="new_password" type="password" name="new_password" autocomplete="new-password" minlength="8" required></div>
<div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" type="password" name="confirm_password" autocomplete="new-password" minlength="8" required></div>
<button class="btn" type="submit">Change password</button></form>
<?php endif; ?>
</div></section></main><?php include __DIR__ . '/includes/footer.php'; ?></body></html>
