<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/functions.php';

$message = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $sinceLast = time() - (int)($_SESSION['resend_last'] ?? 0);
    if (!$email) {
        $message = 'Enter a valid email address.';
    } elseif ($sinceLast < 60) {
        $message = 'Please wait ' . (60 - $sinceLast) . ' more seconds before asking for another email.';
    } elseif (emailIsOffline()) {
        $message = emailOfflineMessage();          // same answer for everyone, so no account is revealed
    } else {
        $stmt = $pdo->prepare("SELECT user_id, full_name, email, email_verified FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        // Use a neutral response so this page does not disclose registered accounts.
        $message = 'If an account with that address exists and is not verified yet, we have sent a new verification link. Check your inbox and your spam folder. If you verified before, just sign in.';
        $success = true;
        $_SESSION['resend_last'] = time();
        if ($user && !(int)$user['email_verified']) {
            if (resendVerificationEmailSafely($pdo, $user) !== 'sent') {
                $message = 'We couldn’t send the email just now. Please try again in a little while.';
                $success = false;
                unset($_SESSION['resend_last']);
            }
        }
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resend verification — StayExplore Kenya</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body><?php include __DIR__ . '/includes/header.php'; ?><main class="auth-page"><section class="auth-visual"><div class="auth-copy"><div class="eyebrow">One more step</div><p class="auth-heading">Confirm your email.</p><p>We use email confirmation to protect your account.</p></div></section><section class="auth-form"><div class="auth-box"><h1>Resend verification</h1><p class="small">Enter the email address you used to register.</p><?php if ($message): ?><div class="alert <?= $success ? 'alert-success' : 'alert-error' ?>"><p><?= h($message) ?></p></div><?php endif; ?><form method="post" class="grid" style="margin-top:20px">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><div class="field"><label for="email">Email address</label><input id="email" type="email" name="email" autocomplete="email" required value="<?= h($_POST['email'] ?? '') ?>"></div><button class="btn" type="submit">Send new verification link</button></form><p class="small" style="margin-top:18px"><a class="home-link" href="login.php">Back to sign in</a></p></div></section></main><?php include __DIR__ . '/includes/footer.php'; ?></body></html>