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

<div class="auth-container">
    <div class="auth-card">
        <h1>Create Account</h1>
        <p class="auth-subtitle">Join Smart Accommodation Kenya</p>

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

        <form method="POST" action="register.php">
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name"
                       value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                       placeholder="Enter your full name" required>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       placeholder="Enter your email" required>
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone"
                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                       placeholder="+254 7XX XXX XXX">
            </div>

            <div class="form-group">
                <label for="role">I am a</label>
                <select id="role" name="role" required>
                    <option value="">Select role</option>
                    <option value="tourist" 
                        <?= (($_POST['role'] ?? '') === 'tourist') 
                            ? 'selected' : '' ?>>
                        Tourist — I want to find accommodation
                    </option>
                    <option value="host"
                        <?= (($_POST['role'] ?? '') === 'host') 
                            ? 'selected' : '' ?>>
                        Host — I want to list my property
                    </option>
                </select>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       placeholder="Minimum 8 characters" required>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password"
                       name="confirm_password"
                       placeholder="Repeat your password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-full">
                Create Account
            </button>
        </form>

        <?php endif; ?>

        <p class="auth-link">
            Already have an account? 
            <a href="login.php">Sign in</a>
        </p>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

</body>
</html>