<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Always redirect inside THIS copy of the site (never to a hard-coded or relative address).
$base  = appBasePath();
$token = sanitize($_GET['token'] ?? '');

if ($token === '') {
    redirect($base . '/login.php');
}

$result = verifyEmail($token);

if ($result['success']) {
    require_once __DIR__ . '/includes/verify_wait.php';
    $uStmt = $pdo->prepare("SELECT user_id, full_name, email, role, status, account_closed_at FROM users WHERE user_id = ? LIMIT 1");
    $uStmt->execute([(int)$result['user_id']]);
    $u = $uStmt->fetch();
    $okRole  = $u && in_array(strtolower(trim((string)$u['role'])), ['tourist', 'host', 'admin'], true);
    $blocked = !$u || !empty($u['account_closed_at']) || in_array($u['status'] ?? '', ['suspended', 'banned'], true);

    if ($okRole && !$blocked) {
        // Same browser that registered? Sign in right here.
        $w = $_SESSION['verify_wait'] ?? null;
        if (is_array($w) && (int)($w['user_id'] ?? 0) === (int)$u['user_id']) {
            $c = verifyWaitCheck($pdo);
            if ($c['state'] === 'verified') redirect(verifyWaitComplete($pdo, $c['user']));
        }
        // Different device (for example a phone): the browser that is waiting will sign itself in within a few seconds.
        // This device only signs in if the person chooses to, with one tap.
        $_SESSION['verified_here'] = ['user_id' => (int)$u['user_id'], 'expires' => time() + 600];
        $firstName = trim((string)strtok((string)$u['full_name'], ' '));
        ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email verified — StayExplore Kenya</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>
<main class="auth-page">
  <section class="auth-visual"><div class="auth-copy"><div class="eyebrow" style="color:#efb59e">All set</div><h1>You&rsquo;re verified.</h1><p>Thanks for confirming your email.</p></div></section>
  <section class="auth-form"><div class="auth-box" style="text-align:center">
    <div style="width:64px;height:64px;border-radius:50%;background:#e7f3ec;color:#1f5a3f;display:grid;place-items:center;margin:0 auto 14px;font-size:30px">&#10003;</div>
    <h1 style="margin-bottom:8px">Email verified<?= $firstName !== '' ? ', ' . h($firstName) : '' ?>.</h1>
    <p class="small" style="line-height:1.6">If you were signing up on another device, go back to it. It will sign you in automatically within a few seconds, no password needed.</p>
    <form method="POST" action="verify_continue.php" style="margin-top:22px">
      <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit" class="btn" style="width:100%">Continue on this device</button>
    </form>
    <p class="small" style="margin-top:14px"><a class="home-link" href="login.php">Sign in with my password instead</a></p>
  </div></section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
</body></html>
<?php
        exit;
    }
    setFlash('success', 'Email verified successfully. You can now sign in.');
} else {
    setFlash('error', $result['message'] . '. If you already verified, just sign in; otherwise request a new link.');
}

redirect($base . '/login.php');
