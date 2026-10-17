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
            redirect('index.php');
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

<main class="auth-page">
    <section class="auth-visual" style="background-image:url('assets/user-mount-kenya.jpg')">
        <div class="auth-copy">
            <div class="eyebrow" style="color:#efb59e">Continue exploring</div>
            <h1>Stay close to what matters.</h1>
            <p>Sign in to manage bookings, saved places, properties or administration tasks according to your account role.</p>
        </div>
    </section>
    <section class="auth-form">
      <div class="auth-box">
        <div class="eyebrow">Welcome back</div>
        <h1>Continue exploring.</h1>
        <p class="small">Sign in to manage your trips, saved places or properties.</p>
        <p class="notice" style="margin-top:16px">
            Accounts created in the standalone frontend demo are stored only in that browser demo and are not connected to this PHP app. Use an account registered here, and verify its email before signing in.
        </p>

        <?= showFlash() ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <p><?= $error ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="grid" autocomplete="on" style="margin-top:25px">
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       autocomplete="email" placeholder="amina@example.com" required>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       autocomplete="current-password" placeholder="Your password" required>
            </div>

            <button type="submit" class="btn">Sign in</button>
        </form>

        <p class="small" style="margin-top:18px">
            New here? <a class="home-link" href="register.php">Create an account</a>
        </p>
      </div>
    </section>
</main>

<?php include 'includes/footer.php'; ?>

</body>
</html>