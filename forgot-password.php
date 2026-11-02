<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) redirect(roleHome());

const RESET_MAX_PER_HOUR = 3;   // requests per account per hour
const RESET_VALID_MINUTES = 60;

$message = '';
$error   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if (!$email) {
        $error = 'Enter a valid email address.';
    } else {
        // The reply is the same whether or not the address belongs to an account, so this page cannot be used
        // to find out who is registered.
        $message = 'If an account exists for that address, a password reset link has been sent. It works once and expires in '
                 . RESET_VALID_MINUTES . ' minutes.';
        if (!emailIsConfigured()) {
            $message .= ' (Email delivery is not configured on this server yet; the link is written to logs/mail.log instead.)';
        }
        try {
            $stmt = $pdo->prepare("SELECT user_id, full_name, email FROM users
                                   WHERE email = ? AND account_deleted_at IS NULL AND account_closed_at IS NULL LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $recent = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)");
                $recent->execute([$user['user_id']]);
                if ((int)$recent->fetchColumn() < RESET_MAX_PER_HOUR) {
                    $token = bin2hex(random_bytes(32));
                    $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, request_ip)
                                   VALUES (?, ?, NOW() + INTERVAL " . RESET_VALID_MINUTES . " MINUTE, ?)")
                        ->execute([$user['user_id'], hash('sha256', $token), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
                    sendPasswordResetEmail($user['email'], $user['full_name'], $token);
                }
            }
        } catch (Throwable $e) {
            error_log('Password reset request failed: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forgot password — StayExplore Kenya</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body><?php include __DIR__ . '/includes/header.php'; ?><main class="auth-page"><section class="auth-visual"><div class="auth-copy"><div class="eyebrow">Locked out?</div><h1>Let's get you back in.</h1><p>We'll email you a secure link to choose a new password.</p></div></section><section class="auth-form"><div class="auth-box"><h1>Forgot password</h1><p class="small">Enter the email address you registered with.</p>
<?php if ($error): ?><div class="alert alert-error"><p><?= h($error) ?></p></div><?php endif; ?>
<?php if ($message): ?><div class="alert alert-success"><p><?= h($message) ?></p></div><?php endif; ?>
<form method="post" class="grid" style="margin-top:20px">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><div class="field"><label for="email">Email address</label><input id="email" type="email" name="email" autocomplete="email" required value="<?= h($_POST['email'] ?? '') ?>"></div><button class="btn" type="submit">Send reset link</button></form><p class="small" style="margin-top:18px"><a class="home-link" href="login.php">Back to sign in</a></p></div></section></main><?php include __DIR__ . '/includes/footer.php'; ?></body></html>
