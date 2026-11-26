<?php
/** v9.34: tiny heartbeat for live notifications. Returns the unread count and any notification newer than ?since (id). */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notifications.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (!isLoggedIn()) { http_response_code(401); echo '{"ok":false}'; exit; }
session_write_close();   // do not hold the session lock while polling
$uid = (int)$_SESSION['user_id'];
$since = max(0, (int)($_GET['since'] ?? 0));
ensureNotificationsTable($pdo);
$items = [];
$q = $pdo->prepare('SELECT notification_id, title, message, url FROM notifications WHERE user_id = ? AND is_read = 0 AND notification_id > ? ORDER BY notification_id ASC LIMIT 5');
$q->execute([$uid, $since]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $n) $items[] = ['id' => (int)$n['notification_id'], 'title' => $n['title'], 'message' => $n['message'], 'go' => 'notifications.php?go=' . (int)$n['notification_id']];
$max = $pdo->prepare('SELECT COALESCE(MAX(notification_id),0) FROM notifications WHERE user_id = ?'); $max->execute([$uid]);
$msgs = 0;
try { $c = $pdo->prepare('SELECT COUNT(*) FROM messages m JOIN message_threads t ON t.thread_id = m.thread_id WHERE m.sender_id <> ? AND m.read_at IS NULL AND (t.tourist_id = ? OR t.host_id = ?)'); $c->execute([$uid, $uid, $uid]); $msgs = (int)$c->fetchColumn(); } catch (Throwable $e) {}
echo json_encode(['ok' => true, 'unread' => unreadNotificationCount($pdo, $uid), 'messages' => $msgs, 'latest' => (int)$max->fetchColumn(), 'items' => $items], JSON_UNESCAPED_UNICODE);
