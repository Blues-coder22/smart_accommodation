<?php
require_once 'includes/auth.php';
require_once 'includes/email.php';
require_once 'includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect(roleHome());
}

$errors = [];
$success = '';
$selectedRole = in_array(($_POST['role'] ?? $_GET['role'] ?? ''), ['tourist', 'host'], true) ? ($_POST['role'] ?? $_GET['role']) : 'tourist';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $full_name = sanitize($_POST['full_name'] ?? '');
    $email     = sanitize($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $role      = sanitize($_POST['role'] ?? $selectedRole);
    $phone     = sanitize($_POST['phone'] ?? '');

    // Validation
    if (empty($full_name)) $errors[] = 'Full name is required';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Valid email is required';
    if (strlen($password) < 8)
        $errors[] = 'Password must be at least 8 characters';
    if ($password !== $confirm)
        $errors[] = 'Passwords do not match';
    if (!in_array($role, ['tourist', 'host']))
        $errors[] = 'Please select a valid role';

    if (empty($errors)) {
        $result = registerUser($full_name, $email, $password, $role, $phone);

        if ($result['success']) {
            // Send verification email
            // No internet: do not even try, so nothing half-sends. The person can press "Send the email again" later.
            $emailSent = emailIsOffline() ? false : sendVerificationEmail($email, $full_name, $result['token']);
            // Keep this browser waiting: when the link is opened on ANY device, this browser signs itself in.
            require_once __DIR__ . '/includes/verify_wait.php';
            startVerifyWait($pdo, (int)$result['user_id'], $email);
            if (!$emailSent) { $_SESSION['verify_wait']['unsent'] = true; $_SESSION['verify_wait']['last_sent'] = 0; }
            setFlash($emailSent ? 'success' : 'warning', $emailSent
                ? 'Account created. We sent a verification link to ' . $email . '.'
                : 'Your account is ready, but we couldn’t send your confirmation email just now. Please check your internet connection, then press "Send the email again" when you’re back online.');
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
    <title>Register — StayExplore Kenya</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php include 'includes/header.php'; ?>

<main class="auth-page">
    <section class="auth-visual">
        <div class="auth-copy">
            <div class="eyebrow" style="color:#efb59e">Join the journey</div>
            <p class="auth-heading">Create your account</p>
            <p>Choose how you use StayExplore. Your account role controls which part of the website you can access.</p>
        </div>
    </section>
    <section class="auth-form">
      <div class="auth-box">
        <div class="eyebrow">Join the journey</div>
        <h1>Create your account.</h1>
        <p class="small">It only takes a minute. Verify your email to get started.</p>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <p><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <p><?= h($success) ?></p>
            </div>
        <?php else: ?>

        <form method="POST" action="register.php" class="grid grid-2" autocomplete="on" style="margin-top:25px">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name"
                       value="<?= h($_POST['full_name'] ?? '') ?>"
                       autocomplete="name" required>
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="<?= h($_POST['email'] ?? '') ?>"
                       autocomplete="email" required>
            </div>

            <div class="field">
                <label for="phone">Phone number</label>
                <input type="tel" id="phone" name="phone"
                       value="<?= h($_POST['phone'] ?? '') ?>"
                       autocomplete="tel">
            </div>

            <div class="field full">
                <label for="role">Account type</label>
                <select id="role" name="role" required>
                    <option value="">Select role</option>
                    <option value="tourist" 
                        <?= (($_POST['role'] ?? $_GET['role'] ?? '') === 'tourist')
                            ? 'selected' : '' ?>>
                        Tourist — search, book and review stays
                    </option>
                    <option value="host"
                        <?= (($_POST['role'] ?? $_GET['role'] ?? '') === 'host')
                            ? 'selected' : '' ?>>
                        Host — list and manage properties
                    </option>
                </select>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       minlength="8" autocomplete="new-password" required>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password"
                       name="confirm_password"
                       minlength="8" autocomplete="new-password" required>
            </div>

            <label class="check full">
                <input type="checkbox" required>
                <span>By continuing, you agree to our <a class="home-link" href="terms.html">terms</a> and <a class="home-link" href="privacy.html">privacy policy</a>.</span>
            </label>

            <button type="submit" class="btn full">
                Create account
            </button>
        </form>

        <?php endif; ?>

        <p class="small" style="margin-top:18px">
            Already have an account? <a class="home-link" href="login.php">Sign in</a>
        </p>
      </div>
    </section>
</main>

<?php include 'includes/footer.php'; ?>

</body>
</html>