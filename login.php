<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Do not automatically redirect an existing session away from this page.
// This lets a user switch from a tourist session to a host/admin account by signing in again.

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $errors[] = 'Email and password are required';
    } else {
        $result = loginUser($email, $password);

        if ($result['success']) {
            // Return to a protected page only when it belongs to this app and this role.
            $returnUrl = $_SESSION['return_url'] ?? '';
            unset($_SESSION['return_url']);
            $role = $result['role'];
            $base = appBasePath();
            $allowedPrefix = $role === 'host' ? $base . '/host/' : ($role === 'admin' ? $base . '/admin/' : $base . '/tourist/');
            if (is_string($returnUrl) && strpos($returnUrl, $allowedPrefix) === 0 && strpos($returnUrl, '://') === false) {
                header('Location: ' . $returnUrl);
                exit;
            }
            redirect(roleHome($role));
        } elseif (!empty($result['unverified'])) {
            // Right password, email not confirmed yet: wait for the link to be opened (on any device) and sign in automatically.
            require_once 'includes/verify_wait.php';
            startVerifyWait($pdo, (int)$result['user_id'], $result['email']);
            setFlash('info', 'Please confirm your email first. Open the link we sent, on any device, and this page will sign you in.');
            redirect(appBasePath() . '/verify_wait.php');
        } else {
            $errors[] = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — StayExplore Kenya</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php include 'includes/header.php'; ?>

<main class="auth-page">
    <section class="auth-visual" style="background-image:url('assets/user-mount-kenya.jpg')">
        <div class="auth-copy">
            <div class="eyebrow" style="color:#efb59e">Continue exploring</div>
            <p class="auth-heading">Stay close to what matters.</p>
            <p>Sign in to manage bookings, saved places, properties or administration tasks according to your account role.</p>
        </div>
    </section>
    <section class="auth-form">
      <div class="auth-box">
        <div class="eyebrow">Welcome back</div>
        <h1>Sign in to your account.</h1>
        <p class="small">Sign in to manage your trips, saved places or properties.</p>
        <?= showFlash() ?>

        <?php if (isLoggedIn()): ?>
            <div class="alert alert-info">
                You are currently signed in as <?= h($_SESSION['role'] ?? 'a user', ENT_QUOTES, 'UTF-8') ?>.
                Sign in below with another account to switch dashboards.
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <p><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="grid" autocomplete="on" style="margin-top:25px">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="<?= h($_POST['email'] ?? '') ?>"
                       autocomplete="email" required>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn">Sign in</button>
        </form>

        <p class="small" style="margin-top:18px">
            New here? <a class="home-link" href="register.php">Create an account</a><br><a class="home-link" href="forgot-password.php">Forgot your password?</a><br><a class="home-link" href="resend-verification.php">Resend email verification</a>
        </p>
      </div>
    </section>
</main>

<?php include 'includes/footer.php'; ?>

</body>
</html>