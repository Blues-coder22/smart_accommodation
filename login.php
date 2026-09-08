<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    $return_url = $_SESSION['return_url'] ?? 'index.php';
    unset($_SESSION['return_url']);
    redirect($return_url);;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $errors[] = 'Email and password are required';
    } else {
        $result = loginUser($email, $password);

        if ($result['success']) {
            // Redirect based on role
            switch ($result['role']) {
                case 'admin':
                    redirect('admin/dashboard.php');
                    break;
                case 'host':
                    redirect('host/dashboard.php');
                    break;
                default:
                    redirect('index.php');
            }
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
    <title>Login — Smart Accommodation Kenya</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php include 'includes/header.php'; ?>

<div class="auth-container">
    <div class="auth-card">
        <h1>Welcome Back</h1>
        <p class="auth-subtitle">Sign in to your account</p>

        <?= showFlash() ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <p><?= $error ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       placeholder="Enter your email" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       placeholder="Enter your password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-full">
                Sign In
            </button>
        </form>

        <p class="auth-link">
            Don't have an account? 
            <a href="register.php">Create one</a>
        </p>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

</body>
</html>