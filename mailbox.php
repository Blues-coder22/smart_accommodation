<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Email verification help — StayExplore Kenya</title><link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="assets/js/se-theme.js"></script><script src="assets/js/se-i18n.js"></script></head><body>
<?php include __DIR__ . '/includes/header.php'; ?>
<main class="auth-page"><section class="auth-visual"><div class="auth-copy"><div class="eyebrow">Email confirmation</div><p class="auth-heading">Check your inbox.</p><p>We confirm every new account by email, so we know it’s really you.</p></div></section><section class="auth-form"><div class="auth-box"><h1>Your verification email</h1><p class="small" style="line-height:1.8">When you sign up, we email a verification link and a 6-digit code to your address. Open the email and follow the link. If it has not arrived, check your spam folder. Still nothing? Ask for a new email below.</p><div class="actions" style="margin-top:22px"><a class="btn" href="resend-verification.php">Resend verification email</a><a class="btn btn-outline" href="login.php">Back to sign in</a></div></div></section></main>
<?php include __DIR__ . '/includes/footer.php'; ?></body></html>
