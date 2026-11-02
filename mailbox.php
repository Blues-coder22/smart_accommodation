<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Email verification help — StayExplore Kenya</title><link rel="stylesheet" href="assets/css/style.css"></head><body>
<?php include __DIR__ . '/includes/header.php'; ?>
<main class="auth-page"><section class="auth-visual"><div class="auth-copy"><div class="eyebrow">Email confirmation</div><h1>Check your inbox.</h1><p>We’ve sent you an email so we can confirm it’s really you.</p></div></section><section class="auth-form"><div class="auth-box"><h1>Your verification email</h1><p class="small" style="line-height:1.8">Open the email sent to the address you registered with and follow its verification link. If it has not arrived, check your spam folder. Still nothing? Request another link below.</p><div class="actions" style="margin-top:22px"><a class="btn" href="resend-verification.php">Resend verification email</a><a class="btn btn-outline" href="login.php">Back to sign in</a></div></div></section></main>
<?php include __DIR__ . '/includes/footer.php'; ?></body></html>
