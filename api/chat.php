<?php
/**
 * v9.34: live chat endpoint (polling). Used by the Messages pages and the Support pages.
 *   GET  ?kind=thread|support&id=N&after=LAST_MESSAGE_ID   -> new messages (and marks the other person's messages read)
 *   POST kind, id, body, csrf_token                         -> saves a message and returns it
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/../includes/message_guard.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function chatOut($a, $code = 200) { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
if (!isLoggedIn()) chatOut(['ok' => false, 'error' => 'Please sign in again.'], 401);
$uid = (int)$_SESSION['user_id']; $role = (string)($_SESSION['role'] ?? '');
$kind = ($_REQUEST['kind'] ?? '') === 'support' ? 'support' : 'thread';
$id = (int)($_REQUEST['id'] ?? 0);

// Who may use this conversation?
$thread = null;
if ($kind === 'thread') {
    $q = $pdo->prepare('SELECT t.*, a.title FROM message_threads t JOIN accommodations a ON a.accommodation_id = t.accommodation_id WHERE t.thread_id = ? AND (t.tourist_id = ? OR t.host_id = ?)');
    $q->execute([$id, $uid, $uid]); $thread = $q->fetch(PDO::FETCH_ASSOC);
} else {
    ensureSupportTables($pdo);
    $q = $pdo->prepare('SELECT * FROM support_threads WHERE thread_id = ?'); $q->execute([$id]); $thread = $q->fetch(PDO::FETCH_ASSOC);
    if ($thread && $role !== 'admin' && (int)$thread['user_id'] !== $uid) $thread = null;
}
if (!$thread) chatOut(['ok' => false, 'error' => 'Conversation not found.'], 404);

$fmt = function (array $m) use ($uid) {
    return ['id' => (int)$m['message_id'], 'mine' => (int)$m['sender_id'] === $uid, 'name' => (string)($m['sender_name'] ?? ''), 'body' => (string)$m['body'],
            'time' => date('d M Y, H:i', strtotime($m['created_at']))];
};
$msgTable = $kind === 'support' ? 'support_messages' : 'messages';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) chatOut(['ok' => false, 'error' => 'This page has expired. Refresh it and try again.'], 403);
    $body = trim((string)($_POST['body'] ?? ''));
    $max = $kind === 'support' ? 2000 : 1000;
    $len = function_exists('mb_strlen') ? mb_strlen($body, 'UTF-8') : strlen($body);
    if ($body === '' || $len > $max) chatOut(['ok' => false, 'error' => 'Write a message between 1 and ' . number_format($max) . ' characters.'], 422);
    if ($kind === 'thread') {
        $toId = (int)(((int)$thread['host_id'] === $uid) ? $thread['tourist_id'] : $thread['host_id']);
        $prob = messageAccountProblem($pdo, $uid, $toId);
        if ($prob !== '') chatOut(['ok' => false, 'error' => $prob], 403);
    }
    if (messageRateLimited($pdo, $msgTable, $uid)) chatOut(['ok' => false, 'error' => 'You are sending messages very quickly. Please wait a moment and try again.'], 429);
    $dupId = messageRecentDuplicateId($pdo, $msgTable, $id, $uid, $body);   // same text just sent: return it, do not save it twice
    if ($dupId) {
        $newId = $dupId;
    } elseif ($kind === 'support') {
        $newId = supportAddMessage($pdo, $id, $uid, $role === 'admin', $body);
    } else {
        $pdo->prepare('INSERT INTO messages (thread_id, sender_id, body) VALUES (?,?,?)')->execute([$id, $uid, $body]);
        $newId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE message_threads SET updated_at = CURRENT_TIMESTAMP WHERE thread_id = ?')->execute([$id]);
        try {
            $isHost = (int)$thread['host_id'] === $uid;
            $toId = (int)($isHost ? $thread['tourist_id'] : $thread['host_id']);
            $nUrl = ($isHost ? 'messages.php' : 'host/messages.php') . '?thread_id=' . $id;
            $dup = $pdo->prepare("SELECT 1 FROM notifications WHERE user_id = ? AND url = ? AND title = 'New message' AND is_read = 0 LIMIT 1");
            $dup->execute([$toId, $nUrl]);
            if (!$dup->fetchColumn()) notify($pdo, $toId, 'New message', (trim((string)($_SESSION['full_name'] ?? '')) ?: ($isHost ? 'Your host' : 'A guest')) . ' sent you a message about "' . $thread['title'] . '".', $nUrl);
        } catch (Throwable $e) { error_log('chat notify: ' . $e->getMessage()); }
    }
    $m = $pdo->prepare("SELECT m.*, u.full_name AS sender_name FROM $msgTable m JOIN users u ON u.user_id = m.sender_id WHERE m.message_id = ?");
    $m->execute([$newId]);
    chatOut(['ok' => true, 'messages' => [$fmt($m->fetch(PDO::FETCH_ASSOC))]]);
}

$after = max(0, (int)($_GET['after'] ?? 0));
$m = $pdo->prepare("SELECT m.*, u.full_name AS sender_name FROM $msgTable m JOIN users u ON u.user_id = m.sender_id WHERE m.thread_id = ? AND m.message_id > ? ORDER BY m.message_id ASC LIMIT 100");
$m->execute([$id, $after]);
$rows = $m->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {   // the person is looking at the conversation, so what the other side wrote is now read
    if ($kind === 'support') $pdo->prepare('UPDATE support_messages SET read_at = CURRENT_TIMESTAMP WHERE thread_id = ? AND sender_id <> ? AND read_at IS NULL')->execute([$id, $uid]);
    else $pdo->prepare('UPDATE messages SET read_at = CURRENT_TIMESTAMP WHERE thread_id = ? AND sender_id <> ? AND read_at IS NULL')->execute([$id, $uid]);
    $page = $kind === 'support' ? ($role === 'admin' ? 'admin' : ($role === 'host' ? 'host' : 'tourist')) . '/support.php' : ((int)$thread['host_id'] === $uid ? 'host/messages.php' : 'messages.php');
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0 AND url = ?')->execute([$uid, $page . '?thread_id=' . $id]);
}
chatOut(['ok' => true, 'messages' => array_map($fmt, $rows)]);
