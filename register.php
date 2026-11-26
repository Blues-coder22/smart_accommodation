<?php
require_once 'includes/auth.php';
require_once 'includes/email.php';
require_once 'includes/functions.php';
require_once 'includes/phone.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect(roleHome());
}

header('Cache-Control: no-store');   // the form can be sent back with what the person typed, so it must not be cached
$errors = [];
$fe = [];          // errors by field: only the wrong field is marked and cleared; everything else stays filled in
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
    $phoneNorm = normalizePhone($phone);

    // Validation
    if (empty($full_name)) $fe['full_name'] = 'Enter your full name.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
        $fe['email'] = 'Enter a valid email address, for example name@example.com.';
    if (strlen($password) < 8)
        $fe['password'] = 'Use at least 8 characters.';
    elseif ($password !== $confirm)
        $fe['confirm_password'] = 'The two passwords do not match. Type the second one again.';
    if (!in_array($role, ['tourist', 'host']))
        $fe['role'] = 'Choose how you are signing up.';

    if (empty($_POST['agree'])) $fe['agree'] = 'Tick the box to agree to the terms and privacy policy.';
    if ($phone !== '' && !$phoneNorm) $fe['phone'] = 'Enter a valid phone number, for example 0712 345 678 (other countries: start with + and the country code).';

    if (empty($fe)) {
        $result = registerUser($full_name, $email, $password, $role, $phoneNorm ?: $phone);

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
            if (stripos((string)$result['message'], 'email') !== false) $fe['email'] = $result['message'] . '. Use a different email, or sign in instead.';
            else $errors[] = $result['message'];
        }
    }
}
$fv = function ($k) use ($fe) { return isset($fe[$k]) ? ' aria-invalid="true" aria-describedby="err_' . $k . '"' : ''; };
$fm = function ($k) use ($fe) { return isset($fe[$k]) ? '<div class="field-error" id="err_' . $k . '" role="alert">' . h($fe[$k]) . '</div>' : ''; };
// Keep what was typed, except the field that was wrong.
$keep = function ($k) use ($fe) { return isset($fe[$k]) ? '' : h($_POST[$k] ?? ''); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — StayExplore Kenya</title>
    <link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/ui.css"><script src="assets/js/ui.js" defer></script>
<link rel="stylesheet" href="assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="assets/js/se-theme.js"></script><script src="assets/js/se-i18n.js"></script></head>
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
        <p class="small">It only takes a minute. We will email you a link to confirm your account.</p>

        <?php if (!empty($errors) || !empty($fe)): ?>
            <div class="alert alert-error" role="alert">
                <?php foreach ($errors as $error): ?><p><?= h($error) ?></p><?php endforeach; ?>
                <?php if (!empty($fe)): ?><p>Please fix <?= count($fe) === 1 ? 'the highlighted field' : 'the highlighted fields' ?> below. Everything else you typed has been kept.</p><?php endif; ?>
            </div>
        <?php endif; ?>
        <style>.field-error{color:var(--bad-fg,#b3261e);font-size:13px;margin-top:5px}.field input[aria-invalid=true],.field select[aria-invalid=true]{border-color:var(--bad-fg,#b3261e)!important;box-shadow:0 0 0 2px rgba(179,38,30,.15)}</style>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <p><?= h($success) ?></p>
            </div>
        <?php else: ?>

        <form method="POST" action="register.php" id="regForm" novalidate class="grid grid-2" autocomplete="on" style="margin-top:25px">
<input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name"
                       value="<?= $keep('full_name') ?>"
                       autocomplete="name" required<?= $fv('full_name') ?>>
                <?= $fm('full_name') ?>
            </div>

            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email"
                       value="<?= $keep('email') ?>"
                       autocomplete="email" required<?= $fv('email') ?>>
                <?= $fm('email') ?>
            </div>

            <div class="field">
                <label for="phone">Phone number (optional)</label>
                <input type="tel" id="phone" name="phone"
                       value="<?= $keep('phone') ?>"
                       autocomplete="tel" placeholder="0712 345 678"<?= $fv('phone') ?>>
                <?= $fm('phone') ?>
            </div>

            <div class="field full">
                <label for="role">I am signing up as</label>
                <select id="role" name="role" required<?= $fv('role') ?>>
                    <option value="">Choose one</option>
                    <option value="tourist" 
                        <?= (($_POST['role'] ?? $_GET['role'] ?? '') === 'tourist')
                            ? 'selected' : '' ?>>
                        Traveller — find stays, explore places and make bookings
                    </option>
                    <option value="host"
                        <?= (($_POST['role'] ?? $_GET['role'] ?? '') === 'host')
                            ? 'selected' : '' ?>>
                        Host — list your property and manage bookings
                    </option>
                </select>
                <?= $fm('role') ?>
            </div>

            <?php /* Passwords are sent back only when they were fine, so a mistake elsewhere does not make anyone retype them. */
                  $pwKeep = !isset($fe['password']) && !isset($fe['confirm_password']); ?>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       value="<?= $pwKeep ? h($_POST['password'] ?? '') : '' ?>"
                       minlength="8" autocomplete="new-password" required<?= $fv('password') ?>>
                <?= $fm('password') ?>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password"
                       name="confirm_password"
                       value="<?= (!isset($fe['confirm_password']) && !isset($fe['password'])) ? h($_POST['confirm_password'] ?? '') : '' ?>"
                       minlength="8" autocomplete="new-password" required<?= $fv('confirm_password') ?>>
                <?= $fm('confirm_password') ?>
            </div>

            <label class="check full">
                <input type="checkbox" name="agree" value="1" required <?= ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($fe['agree'])) ? 'checked' : '' ?><?= $fv('agree') ?>>
                <span>By continuing, you agree to our <a class="home-link" href="terms.html">terms</a> and <a class="home-link" href="privacy.html">privacy policy</a>.</span>
                <?= $fm('agree') ?>
            </label>

            <button type="submit" class="btn full">
                Create account
            </button>
        </form>

        <script>
        (function () {   // check each field as the person leaves it, and mark only the one that is wrong
            var f = document.getElementById('regForm'); if (!f) return;
            var rules = {
                full_name: function (v) { return v.trim() ? '' : 'Enter your full name.'; },
                email: function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) ? '' : 'Enter a valid email address, for example name@example.com.'; },
                phone: function (v) { return !v.trim() || /^\+?[0-9 ()-]{9,17}$/.test(v) ? '' : 'Enter a valid phone number, for example 0712 345 678.'; },
                password: function (v) { return v.length >= 8 ? '' : 'Use at least 8 characters.'; },
                confirm_password: function (v) { return v === f.password.value ? '' : 'The two passwords do not match.'; }
            };
            function show(el, msg) {
                var id = 'err_' + el.name, old = document.getElementById(id);
                if (old) old.remove();
                el.removeAttribute('aria-invalid'); el.removeAttribute('aria-describedby');
                if (!msg) return;
                var d = document.createElement('div'); d.className = 'field-error'; d.id = id; d.setAttribute('role', 'alert'); d.textContent = msg;
                el.setAttribute('aria-invalid', 'true'); el.setAttribute('aria-describedby', id);
                el.parentNode.appendChild(d);
            }
            Object.keys(rules).forEach(function (n) {
                var el = f.elements[n]; if (!el) return;
                el.addEventListener('blur', function () { if (el.value !== '' || n === 'full_name') show(el, rules[n](el.value)); });
                el.addEventListener('input', function () { if (el.getAttribute('aria-invalid')) show(el, rules[n](el.value)); });
            });
            f.addEventListener('submit', function (e) {
                var first = null;
                Object.keys(rules).forEach(function (n) { var el = f.elements[n]; if (!el) return; var m = rules[n](el.value); show(el, m); if (m && !first) first = el; });
                if (f.elements.agree && !f.elements.agree.checked) { show(f.elements.agree, 'Tick the box to agree to the terms and privacy policy.'); first = first || f.elements.agree; }
                if (f.elements.role && !f.elements.role.value) { show(f.elements.role, 'Choose how you are signing up.'); first = first || f.elements.role; }
                if (first) { e.preventDefault(); first.focus(); }   // nothing is cleared, nothing is lost
            });
        })();
        </script>

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