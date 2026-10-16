<?php
require_once 'includes/auth.php';
require_once 'includes/email.php';
require_once 'includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect('index.php');
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = sanitize($_POST['full_name'] ?? '');
    $email     = sanitize($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $role      = sanitize($_POST['role'] ?? '');
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
            sendVerificationEmail($email, $full_name, $result['token']);
            $success = 'Registration successful! Please check your 
                        email to verify your account.';
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
    <title>Register — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php include 'includes/header.php'; ?>

<main class="auth-page">
    <section class="auth-visual">
        <div class="auth-copy">
            <div class="eyebrow" style="color:#efb59e">Join the journey</div>
            <h1>Create your account</h1>
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
                    <p><?= $error ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <p><?= $success ?></p>
            </div>
        <?php else: ?>

        <form method="POST" action="register.php" class="grid grid-2" autocomplete="on" style="margin-top:25px">
            <div class="field full">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name"
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                       autocomplete="name" placeholder="Amina Wanjiku" required>
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       autocomplete="email" placeholder="amina@example.com" required>
            </div>

            <div class="field">
                <label for="phone">Phone number</label>
                <input type="tel" id="phone" name="phone"
                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                       autocomplete="tel" placeholder="+254 7XX XXX XXX">
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
                       minlength="8" autocomplete="new-password" placeholder="At least 8 characters" required>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password"
                       name="confirm_password"
                       minlength="8" autocomplete="new-password" placeholder="Repeat your password" required>
            </div>

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