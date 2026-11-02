<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/functions.php';

$message = '';
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        $message = 'Enter a valid email address.';
    } else {
        $stmt = $pdo->prepare("SELECT user_id, full_name, email_verified FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        // Use a neutral response so this page does not disclose registered accounts.
        $message = 'If an account with that address exists and is not verified yet, we have sent a new verification link. Check your inbox and your spam folder. If you verified before, just sign in.';
        if (!emailIsConfigured()) {
            $message .= ' (Email sending is not set up on this server yet: the link was written to logs/mail.log instead. See SETUP_LOGIN_AND_EMAIL.md.)';
        }
        $success = true;
        if ($user && !(int)$user['email_verified']) {
            $token = bin2hex(random_bytes(32));
            $update = $pdo->prepare('UPDATE users SET verification_token = ? WHERE user_id = ?');
            $update->execute([$token, $user['user_id']]);
            if (!sendVerificationEmail($user['email'], $user['full_name'], $token)) {
                $message = 'Email delivery is not configured or failed. Ask the developer to configure SMTP_USERNAME, SMTP_PASSWORD and SMTP_FROM_EMAIL, then try again.';
                $success = false;
            }
        }
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Resend verification — StayExplore Kenya</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body><?php include __DIR__ . '/includes/header.php'; ?><main class="auth-page"><section class="auth-visual"><div class="auth-copy"><div class="eyebrow">One more step</div><h1>Confirm your email.</h1><p>We use email confirmation to protect your account.</p></div></section><section class="auth-form"><div class="auth-box"><h1>Resend verification</h1><p class="small">Enter the email address you used to register.</p><?php if ($message): ?><div class="alert <?= $success ? 'alert-success' : 'alert-error' ?>"><p><?= h($message) ?></p></div><?php endif; ?><form method="post" class="grid" style="margin-top:20px">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><div class="field"><label for="email">Email address</label><input id="email" type="email" name="email" autocomplete="email" required value="<?= h($_POST['email'] ?? '') ?>"></div><button class="btn" type="submit">Send new verification link</button></form><p class="small" style="margin-top:18px"><a class="home-link" href="login.php">Back to sign in</a></p></div></section></main><?php include __DIR__ . '/includes/footer.php'; ?></body></html>