<?php
// The signed-in person's notifications (guest, host or admin). Shared page: ?as=tourist|host|admin chooses the signed-in role.
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_once 'includes/db.php';
require_once 'includes/notifications.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
ensureNotificationsTable($pdo);
$uid = (int)$_SESSION['user_id']; $role = (string)($_SESSION['role'] ?? 'tourist');
if ($role === 'tourist') { require_once 'includes/alerts.php'; runTouristAlerts($pdo, $uid, true); }   // v9.31: check-in and saved-stay alerts
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

if (isset($_GET['go'])) {                                   // open one: mark it read, then go to the page it is about
    $q = $pdo->prepare('SELECT url FROM notifications WHERE notification_id = ? AND user_id = ?'); $q->execute([(int)$_GET['go'], $uid]); $u = $q->fetchColumn();
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?')->execute([(int)$_GET['go'], $uid]);
    $dest = $u && !preg_match('#^[a-z]+:|//#i', $u) ? $u : 'notifications.php?as=' . urlencode($role);
    header('Location: ' . $base . '/' . ltrim($dest, '/')); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (($_POST['action'] ?? '') === 'read_all') $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$uid]);
    header('Location: ' . $base . '/notifications.php?as=' . urlencode($role)); exit;
}
$list = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, notification_id DESC LIMIT 100'); $list->execute([$uid]); $list = $list->fetchAll(PDO::FETCH_ASSOC);
$unread = unreadNotificationCount($pdo, $uid);
$home = ['admin' => 'admin/dashboard.php', 'host' => 'host/dashboard.php'][$role] ?? 'tourist/bookings.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — StayExplore Kenya</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/finance.css">
    <style>.nt-wrap{max-width:760px;margin:28px auto;padding:0 16px}.nt-item{display:block;background:#fff;border:1px solid #e4ddcf;border-radius:8px;padding:12px 16px;margin:0 0 10px;text-decoration:none;color:inherit}.nt-item.unread{border-left:4px solid #126b4e;background:#f3f8f5}.nt-item strong{display:block}.nt-time{font-size:12px;color:#565c55}</style>
<link rel="stylesheet" href="assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="assets/js/se-theme.js"></script><script src="assets/js/se-i18n.js"></script></head>
<body>
<main class="nt-wrap" id="main-content">
    <div class="fin-actions" style="margin-bottom:10px"><a class="fin-btn ghost" href="<?= h($base . '/' . $home) ?>">&larr; Back</a>
        <?php if ($unread): ?><form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="read_all"><button class="fin-btn" type="submit">Mark all as read</button></form><?php endif; ?></div>
    <h1>Notifications <?= $unread ? '<span class="small">(' . $unread . ' new)</span>' : '' ?></h1>
    <?php if (!$list): ?><p>Nothing yet. Updates about your bookings, messages, payments, check-in reminders and saved stays will appear here, as well as in your email.</p><?php endif; ?>
    <?php foreach ($list as $n): ?>
        <a class="nt-item <?= $n['is_read'] ? '' : 'unread' ?>" href="<?= h($base . '/notifications.php?go=' . (int)$n['notification_id'] . '&as=' . urlencode($role)) ?>">
            <strong><?= h($n['title']) ?></strong><?= h($n['message']) ?>
            <span class="nt-time"><?= h(date('j M Y, H:i', strtotime($n['created_at']))) ?></span>
        </a>
    <?php endforeach; ?>
</main>
</body>
</html>
