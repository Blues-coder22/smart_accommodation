<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/verify_wait.php';

$base = appBasePath();
$w = $_SESSION['verify_wait'] ?? null;
if (!is_array($w) || empty($w['user_id'])) {
    redirect($base . '/login.php');         // nothing to wait for: go and sign in
}

// Typed the 6-digit code from the email (works without the phone ever opening the link)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code'])) {
    requireCsrf();
    $c = verifyWaitCheck($pdo);
    if ($c['state'] === 'verified') redirect(verifyWaitComplete($pdo, $c['user']));
    if ($c['state'] !== 'waiting') {
        setFlash('error', 'This page has timed out. Press "Send the email again", or sign in.');
    } else {
        $r = verifyCodeSubmit($pdo, (int)$w['user_id'], $_POST['verify_code']);
        if ($r['ok']) {
            $c = verifyWaitCheck($pdo);
            if ($c['state'] === 'verified') redirect(verifyWaitComplete($pdo, $c['user']));
        }
        setFlash('error', $r['message']);
    }
    redirect($base . '/verify_wait.php');
}

// Resend the email (at most once a minute)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $wait = 60 - (time() - (int)($w['last_sent'] ?? 0));
    if ($wait > 0) {
        setFlash('warning', 'Please wait ' . $wait . ' more second' . ($wait === 1 ? '' : 's') . ' before asking for another email.');
    } else {
        $q = $pdo->prepare("SELECT user_id, full_name, email, email_verified FROM users WHERE user_id = ? LIMIT 1");
        $q->execute([(int)$w['user_id']]);
        $u = $q->fetch(PDO::FETCH_ASSOC);
        if ($u && !(int)$u['email_verified']) {
            $r = resendVerificationEmailSafely($pdo, $u);
            if ($r === 'sent') {
                $_SESSION['verify_wait']['last_sent'] = time();      // the one-minute wait only starts after a real send
                unset($_SESSION['verify_wait']['unsent']);
                setFlash('success', 'A new email is on its way. Older links no longer work.');
            } elseif ($r === 'offline') {
                setFlash('warning', emailOfflineMessage());
            } else {
                setFlash('error', 'We couldn’t send the email just now. Please try again in a little while.');
            }
        }
    }
    redirect($base . '/verify_wait.php');
}

$c = verifyWaitCheck($pdo);
if ($c['state'] === 'verified') redirect(verifyWaitComplete($pdo, $c['user']));   // verified while this page was closed
$email = (string)($w['email'] ?? '');
$unsent = !empty($w['unsent']);
$cooldown = max(0, 60 - (time() - (int)($w['last_sent'] ?? 0)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Confirm your email — StayExplore Kenya</title>
<link rel="stylesheet" href="assets/css/style.css">
<style>
.vw-box{display:flex;align-items:center;gap:14px;background:#f4f8f5;border:1px solid #cfdcd5;border-radius:12px;padding:16px 18px;margin:20px 0}
.vw-spin{width:22px;height:22px;border-radius:50%;border:3px solid #cfdcd5;border-top-color:#0d3b2e;animation:vwspin 1s linear infinite;flex:none}
@keyframes vwspin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.vw-spin{animation:none}}
.vw-box.ok{background:#e7f3ec;border-color:#9fcfb4}.vw-box.ok .vw-spin{animation:none;border-color:#2f7658;background:#2f7658}
.vw-box.bad{background:#fff6dc;border-color:#efd27a}.vw-box.bad .vw-spin{animation:none;border-color:#c58f00}
.vw-steps{font-size:13px;color:#4a5249;line-height:1.7;padding-left:18px;margin:0 0 6px}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/header.php'; ?>
<main class="auth-page">
  <section class="auth-visual"><div class="auth-copy"><div class="eyebrow" style="color:#efb59e">One more step</div><p class="auth-heading">Check your inbox.</p><p>Open the link we emailed you on any device. This page notices and signs you in by itself.</p></div></section>
  <section class="auth-form"><div class="auth-box">
    <div class="eyebrow">Confirm your email</div>
    <h1>Waiting for you to confirm.</h1>
    <?= showFlash() ?>
    <?php if ($unsent): ?>
    <p class="small" style="line-height:1.6">We haven&rsquo;t been able to email <strong><?= h($email) ?></strong> yet. When you&rsquo;re back online, press <strong>Send the email again</strong> below.</p>
    <?php else: ?>
    <p class="small" style="line-height:1.6">We sent a link to <strong><?= h($email) ?></strong>. You can open it on your phone or any other device, you do not have to come back to this one first.</p>
    <?php endif; ?>

    <div class="vw-box" id="vwBox" role="status" aria-live="polite"><span class="vw-spin" id="vwSpin"></span><span id="vwText">Waiting for you to open the link&hellip;</span></div>

    <ol class="vw-steps"><li>Open the email from StayExplore Kenya (check spam too).</li><li>Tap <strong>Verify Email</strong>, on any device.</li><li>This page signs you in automatically.</li></ol>

    <div style="margin-top:22px;padding-top:18px;border-top:1px solid #e2e8e4">
      <p class="small" style="line-height:1.6;margin:0 0 10px"><strong>Phone can&rsquo;t open the link?</strong> No problem: type the 6-digit code from the same email here.</p>
      <form method="POST" autocomplete="off" style="display:flex;gap:10px">
        <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="text" name="verify_code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" placeholder="123456" required
               aria-label="6-digit code" style="flex:1;min-width:0;font-size:22px;letter-spacing:6px;text-align:center;padding:10px;border:1px solid #cfdcd5;border-radius:10px">
        <button type="submit" class="btn">Verify</button>
      </form>
    </div>

    <form method="POST" style="margin-top:18px">
      <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit" class="btn btn-outline" id="vwResend" <?= $cooldown > 0 ? 'disabled' : '' ?> style="width:100%">
        <span id="vwResendLabel"><?= $cooldown > 0 ? 'Send the email again (' . $cooldown . 's)' : 'Send the email again' ?></span>
      </button>
    </form>
    <p class="small" style="margin-top:16px"><a class="home-link" href="register.php">Wrong email? Start again</a> &nbsp;|&nbsp; <a class="home-link" href="login.php">Sign in instead</a></p>
  </div></section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
<script>
(function () {
  var box = document.getElementById('vwBox'), text = document.getElementById('vwText'), done = false, cd = <?= (int)$cooldown ?>;
  var btn = document.getElementById('vwResend'), lbl = document.getElementById('vwResendLabel');
  if (cd > 0) { var t = setInterval(function () { cd--; if (cd <= 0) { clearInterval(t); btn.disabled = false; lbl.textContent = 'Send the email again'; } else lbl.textContent = 'Send the email again (' + cd + 's)'; }, 1000); }
  function setState(cls, msg) { box.className = 'vw-box ' + cls; text.textContent = msg; }
  function check() {
    if (done) return;
    fetch('verify_status.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.status === 'verified') { done = true; setState('ok', 'Verified. Signing you in…'); setTimeout(function () { window.location.href = d.redirect; }, 700); }
        else if (d.status === 'expired') { done = true; setState('bad', 'This waiting page has timed out. Press “Send the email again”, or sign in.'); }
        else if (d.status === 'blocked') { done = true; setState('bad', 'Your email is verified, but this account cannot be signed in. Please contact support.'); }
        else if (d.status === 'none') { done = true; setState('bad', 'If you already verified, please sign in with your email and password.'); }
      })
      .catch(function () { /* offline for a moment: try again on the next tick */ });
  }
  setInterval(check, 3000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
  check();
})();
</script>
</body>
</html>
