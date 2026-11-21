<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/schema_setup.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
requireRole('admin');
$report = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') { requireCsrf(); $report = runSchemaSetup($pdo); setFlash('success', 'Database checked. Everything the site needs now exists.'); }
else { $have = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)); }
$tables = $report ? $report['tables'] : array_fill_keys(['users','accommodations','accommodation_photos','bookings','payments','reviews','attractions','attraction_photos','attraction_extras','attraction_reviews','payment_audit','booking_events','platform_payments','site_settings','login_attempts','verify_codes','verify_waits','notifications','currencies','certificates'], false);
if (!$report) foreach ($tables as $t => $_) $tables[$t] = in_array($t, $have, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database setup — StayExplore Kenya</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/finance.css">
<link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head>
<body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
        <?= showFlash() ?>
        <div class="page-tag">Admin</div>
        <h1 class="page-title">Database setup</h1>
        <p class="page-sub">Some tables and columns are created by the site the first time a page needs them. This button creates all of them now, so you can export a complete database (phpMyAdmin, Export) without opening every page first. It is safe to press as often as you like. It also makes sure each booking can have only one review.</p>
        <form method="POST" class="fin-box">
            <input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>">
            <div class="fin-actions"><button class="fin-btn" type="submit">Check and create everything</button></div>
        </form>
        <div class="fin-box"><h2>Tables the site needs</h2>
            <div class="fin-scroll"><table class="fin-table"><tbody>
            <?php foreach ($tables as $t => $ok): ?><tr><td><?= h($t) ?></td><td><span class="fin-pill <?= $ok ? 'settled' : 'void' ?>"><?= $ok ? 'Present' : 'Missing (press the button)' ?></span></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
        <?php if ($report): ?><div class="fin-box"><h2>What was done</h2><ul><?php foreach ($report['messages'] as $m): ?><li><?= h($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php appShellEnd(); ?>
</body>
</html>
