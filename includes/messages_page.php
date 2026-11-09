<?php
// Shared server-backed messaging UI for tourists and hosts.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile_photo.php';
requireLogin();
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['tourist','host'], true)) { redirect(appBasePath() . '/admin/dashboard.php'); }
$userId = (int)$_SESSION['user_id'];
$isHost = $role === 'host';
$base = appBasePath();
$errors = [];

// Conversation tables are installed by database/migration_messaging.sql.
// A tourist can start a conversation about an approved listing; a host can start one only with a guest who booked that listing.
if (isset($_GET['accommodation_id']) && (int)$_GET['accommodation_id'] > 0 && !isset($_GET['thread_id'])) {
    $accId = (int)$_GET['accommodation_id'];
    $touristId = $userId;
    if ($isHost) {
        $touristId = (int)($_GET['tourist_id'] ?? 0);
        $valid = $pdo->prepare("SELECT a.accommodation_id, a.host_id FROM accommodations a WHERE a.accommodation_id = ? AND a.host_id = ? LIMIT 1");
        $valid->execute([$accId, $userId]);
        $acc = $valid->fetch();
        $booked = $pdo->prepare("SELECT 1 FROM bookings WHERE accommodation_id = ? AND tourist_id = ? LIMIT 1");
        $booked->execute([$accId, $touristId]);
        if (!$acc || !$touristId || !$booked->fetchColumn()) {
            setFlash('error', 'Hosts can message guests who have booked one of their listings.');
            redirect($base . '/host/bookings.php');
        }
        $hostId = $userId;
    } else {
        $valid = $pdo->prepare("SELECT accommodation_id, host_id FROM accommodations WHERE accommodation_id = ? AND status = 'approved' LIMIT 1");
        $valid->execute([$accId]);
        $acc = $valid->fetch();
        if (!$acc || (int)$acc['host_id'] === $userId) {
            setFlash('error', 'That listing is not available for messaging.');
            redirect($base . '/tourist/search.php?type=accommodation');
        }
        $hostId = (int)$acc['host_id'];
    }
    $find = $pdo->prepare("SELECT thread_id FROM message_threads WHERE accommodation_id = ? AND tourist_id = ? LIMIT 1");
    $find->execute([$accId, $touristId]);
    $threadId = (int)$find->fetchColumn();
    if (!$threadId) {
        $create = $pdo->prepare("INSERT INTO message_threads (accommodation_id, tourist_id, host_id) VALUES (?, ?, ?)");
        $create->execute([$accId, $touristId, $hostId]);
        $threadId = (int)$pdo->lastInsertId();
    }
    redirect(($isHost ? $base . '/host/messages.php' : $base . '/messages.php') . '?thread_id=' . $threadId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $threadId = (int)($_POST['thread_id'] ?? 0);
    $body = trim((string)($_POST['body'] ?? ''));
    $check = $pdo->prepare("SELECT thread_id FROM message_threads WHERE thread_id = ? AND " . ($isHost ? 'host_id' : 'tourist_id') . " = ? LIMIT 1");
    $check->execute([$threadId, $userId]);
    if (!$check->fetchColumn()) {
        setFlash('error', 'Conversation not found or you do not have access to it.');
    } elseif ($body === '' || (function_exists('mb_strlen') ? mb_strlen($body, 'UTF-8') : strlen($body)) > 1000) {
        setFlash('error', 'Write a message between 1 and 1,000 characters.');
    } else {
        $insert = $pdo->prepare("INSERT INTO messages (thread_id, sender_id, body) VALUES (?, ?, ?)");
        $insert->execute([$threadId, $userId, $body]);
        $pdo->prepare("UPDATE message_threads SET updated_at = CURRENT_TIMESTAMP WHERE thread_id = ?")->execute([$threadId]);
        redirect(($isHost ? $base . '/host/messages.php' : $base . '/messages.php') . '?thread_id=' . $threadId);
    }
}

// Load conversation list, including the last message and unread count.
$listSql = "SELECT t.thread_id, t.accommodation_id, a.title, " . ($isHost ? 'u.user_id AS other_id, u.full_name AS other_name, u.profile_photo AS other_photo' : 'h.user_id AS other_id, h.full_name AS other_name, h.profile_photo AS other_photo') . ", (SELECT m.body FROM messages m WHERE m.thread_id=t.thread_id ORDER BY m.created_at DESC, m.message_id DESC LIMIT 1) AS last_body, (SELECT m.created_at FROM messages m WHERE m.thread_id=t.thread_id ORDER BY m.created_at DESC, m.message_id DESC LIMIT 1) AS last_at, (SELECT COUNT(*) FROM messages unread WHERE unread.thread_id=t.thread_id AND unread.sender_id <> ? AND unread.read_at IS NULL) AS unread_count FROM message_threads t JOIN accommodations a ON a.accommodation_id=t.accommodation_id JOIN users u ON u.user_id=t.tourist_id JOIN users h ON h.user_id=t.host_id WHERE t." . ($isHost ? 'host_id' : 'tourist_id') . " = ? ORDER BY COALESCE(t.updated_at,t.created_at) DESC";
$listStmt = $pdo->prepare($listSql); $listStmt->execute([$userId, $userId]); $threads = $listStmt->fetchAll();
$activeId = (int)($_GET['thread_id'] ?? 0);
$active = null; $messages = [];
if ($activeId) {
    $activeStmt = $pdo->prepare("SELECT t.*, a.title, a.accommodation_id, " . ($isHost ? 'u.full_name AS other_name, u.user_id AS other_id, u.profile_photo AS other_photo' : 'h.full_name AS other_name, h.user_id AS other_id, h.profile_photo AS other_photo') . " FROM message_threads t JOIN accommodations a ON a.accommodation_id=t.accommodation_id JOIN users u ON u.user_id=t.tourist_id JOIN users h ON h.user_id=t.host_id WHERE t.thread_id=? AND t." . ($isHost ? 'host_id' : 'tourist_id') . "=? LIMIT 1");
    $activeStmt->execute([$activeId, $userId]); $active = $activeStmt->fetch();
    if ($active) {
        $pdo->prepare("UPDATE messages SET read_at = CURRENT_TIMESTAMP WHERE thread_id = ? AND sender_id <> ? AND read_at IS NULL")->execute([$activeId, $userId]);
        $msgStmt = $pdo->prepare("SELECT m.*, u.full_name AS sender_name FROM messages m JOIN users u ON u.user_id=m.sender_id WHERE m.thread_id=? ORDER BY m.created_at ASC, m.message_id ASC");
        $msgStmt->execute([$activeId]); $messages = $msgStmt->fetchAll();
    }
}
$pageTitle = $isHost ? 'Host messages' : 'Messages';
$flash = getFlash();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= h($pageTitle, ENT_QUOTES, 'UTF-8') ?> — StayExplore Kenya</title><link rel="stylesheet" href="<?= h($base, ENT_QUOTES, 'UTF-8') ?>/assets/css/style.css"><style>
.msg-page{max-width:1180px;margin:0 auto;padding:28px 20px 60px}.msg-layout{display:grid;grid-template-columns:300px minmax(0,1fr);min-height:540px;border:1px solid #dfe7e3;border-radius:12px;overflow:hidden;background:#fff}.msg-sidebar{border-right:1px solid #e4e9e6;background:#f8faf9}.msg-sidebar h2{font-size:17px;padding:18px;margin:0;border-bottom:1px solid #e4e9e6}.msg-thread{display:block;padding:14px 16px;text-decoration:none;color:inherit;border-bottom:1px solid #e8ecea}.msg-thread.active{background:#e6f5ef;border-left:3px solid #16866b}.msg-thread strong,.msg-thread span{display:block}.msg-thread span{font-size:12px;color:#64736c;margin-top:4px}.msg-thread .unread{display:inline-block;background:#16866b;color:white;padding:1px 6px;border-radius:10px;margin-left:6px}.msg-content{display:flex;flex-direction:column;min-width:0}.msg-heading{padding:18px;border-bottom:1px solid #e4e9e6}.msg-heading h2{margin:0 0 4px;font-size:18px}.msg-stream{flex:1;max-height:430px;overflow:auto;padding:20px;display:flex;flex-direction:column;gap:12px;background:#fbfcfb}.msg-bubble{max-width:78%;border-radius:12px;padding:10px 13px;background:#eef2f0;align-self:flex-start;overflow-wrap:anywhere}.msg-bubble.mine{background:#dff4ea;align-self:flex-end}.msg-bubble small{display:block;color:#4f5f57;font-size:11px;margin-top:5px}.msg-compose{padding:14px;border-top:1px solid #e4e9e6}.msg-compose textarea{width:100%;min-height:76px;resize:vertical;padding:10px;border:1px solid #ccd7d1;border-radius:8px;font:inherit}.msg-compose button{margin-top:8px}.msg-empty{padding:35px 20px;color:#63726b}.msg-safe{font-size:12px;color:#69776f;padding:10px 18px;background:#fff;border-top:1px solid #e4e9e6}@media(max-width:760px){.msg-layout{grid-template-columns:1fr}.msg-sidebar{border-right:0;border-bottom:1px solid #e4e9e6;max-height:230px;overflow:auto}.msg-stream{max-height:50vh}}
.msg-thread{display:flex!important;gap:12px;align-items:center}.msg-thread .t-body{min-width:0;flex:1}.msg-thread .t-top{display:flex;justify-content:space-between;gap:8px;align-items:baseline}.msg-thread .t-top strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.msg-thread .t-time{font-size:11px;color:#7a8780;margin:0;white-space:nowrap}.msg-thread .t-time.has-unread{color:#16866b;font-weight:700}.msg-thread .t-bottom{display:flex;justify-content:space-between;gap:8px;align-items:center}.msg-thread .t-bottom .t-prev{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin:0}.msg-thread .t-bottom .unread{margin:0;flex:none;min-width:20px;text-align:center;font-size:11px;padding:2px 6px}.msg-thread:hover{background:#eef4f1}.msg-heading{display:flex;align-items:center;gap:12px;background:#f0f4f2}.msg-heading .who{flex:1;min-width:0;text-decoration:none;color:inherit;display:flex;align-items:center;gap:12px}.msg-heading .who:hover h2{text-decoration:underline}.msg-heading .who .sub{display:block;font-size:12px;color:#64736c}.msg-heading .view-prof{font-size:12px;white-space:nowrap}.msg-stream{background:#efeae2}.msg-bubble{background:#fff;box-shadow:0 1px 1px rgba(0,0,0,.1);border-radius:10px 10px 10px 2px}.msg-bubble.mine{background:#d9fdd3;border-radius:10px 10px 2px 10px}.msg-bubble small{text-align:right;margin-top:3px}.msg-sidebar .se-avatar{border-width:0}.msg-heading .se-avatar{border-width:0}</style></head><body><?php if ($isHost) { require_once __DIR__ . '/app_shell.php'; appShellStart('host'); } else { include __DIR__ . '/header.php'; } ?><<?= $isHost ? 'div' : 'main' ?> class="msg-page"><div class="eyebrow"><?= $isHost ? 'Hosting' : 'Inbox' ?></div><h1><?= $isHost ? 'Messages' : 'Messages.' ?></h1><p class="small" style="margin-bottom:20px">Discuss dates, the area and stay details. Keep payments and passwords out of chat.</p>
<?php if ($flash): ?><div class="alert alert-<?= h($flash['type'], ENT_QUOTES, 'UTF-8') ?>"><?= h($flash['message'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<div class="msg-layout"><aside class="msg-sidebar"><h2>Conversations (<?= count($threads) ?>)</h2><?php if (!$threads): ?><div class="msg-empty">No conversations yet. <?= $isHost ? 'Guests can message you from an approved listing, or you can open a conversation from a booking.' : 'Open an accommodation listing and choose “Message host”.' ?></div><?php endif; ?><?php foreach ($threads as $t): $hasUnread = (int)$t['unread_count']>0; ?><a class="msg-thread <?= (int)$t['thread_id']===$activeId?'active':'' ?>" href="<?= h(($isHost ? $base.'/host/messages.php' : $base.'/messages.php').'?thread_id='.(int)$t['thread_id'], ENT_QUOTES, 'UTF-8') ?>"><?= avatarHtml((int)$t['other_id'], $t['other_name'], $t['other_photo'] ?? null, 46) ?><div class="t-body"><div class="t-top"><strong><?= h($t['other_name'], ENT_QUOTES, 'UTF-8') ?></strong><span class="t-time <?= $hasUnread?'has-unread':'' ?>"><?php if (!empty($t['last_at'])) { $ts=strtotime($t['last_at']); echo h(date('Y-m-d',$ts)===date('Y-m-d') ? date('H:i',$ts) : (date('Y-m-d',$ts)===date('Y-m-d',strtotime('-1 day')) ? 'Yesterday' : date('d/m/Y',$ts))); } ?></span></div><span style="margin-top:2px"><?= h($t['title'], ENT_QUOTES, 'UTF-8') ?></span><div class="t-bottom"><span class="t-prev"><?= h((function_exists('mb_substr') ? mb_substr((string)($t['last_body'] ?? 'No messages yet'),0,45,'UTF-8') : substr((string)($t['last_body'] ?? 'No messages yet'),0,45)), ENT_QUOTES, 'UTF-8') ?></span><?php if ($hasUnread): ?><span class="unread"><?= (int)$t['unread_count'] ?></span><?php endif; ?></div></div></a><?php endforeach; ?></aside>
<section class="msg-content"><?php if ($active): ?><header class="msg-heading"><a class="who" href="<?= h($base.'/view_profile.php?id='.(int)$active['other_id'].'&thread_id='.(int)$active['thread_id'], ENT_QUOTES, 'UTF-8') ?>" title="View profile"><?= avatarHtml((int)$active['other_id'], $active['other_name'], $active['other_photo'] ?? null, 44) ?><span><h2 style="margin:0;font-size:17px"><?= h($active['other_name'], ENT_QUOTES, 'UTF-8') ?></h2><span class="sub">About <?= h($active['title'], ENT_QUOTES, 'UTF-8') ?> · tap to view profile</span></span></a><a class="view-prof" href="<?= h($base.'/tourist/details.php?id='.(int)$active['accommodation_id'], ENT_QUOTES, 'UTF-8') ?>">View listing</a></header><div class="msg-stream" id="msgStream" aria-live="polite"><?php if (!$messages): ?><p class="msg-empty">Say hello. Ask about dates, the area or house details.</p><?php endif; ?><?php foreach ($messages as $m): ?><div class="msg-bubble <?= (int)$m['sender_id']===$userId?'mine':'' ?>"><div><?= nl2br(h($m['body'], ENT_QUOTES, 'UTF-8')) ?></div><small><?= h($m['sender_name'], ENT_QUOTES, 'UTF-8') ?> · <?= date('d M Y, H:i', strtotime($m['created_at'])) ?></small></div><?php endforeach; ?></div><div class="msg-safe">Messages are stored in your website database and visible only to the conversation participants. Never share passwords or payment credentials.</div><form class="msg-compose" method="post"><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="thread_id" value="<?= (int)$active['thread_id'] ?>"><label for="messageBody">Write a message</label><textarea id="messageBody" name="body" maxlength="1000" required placeholder="Write a message…"></textarea><button class="btn btn-primary" type="submit">Send message</button></form><?php else: ?><div class="msg-empty">Select a conversation to read and reply. Messages are saved in the database, so both the tourist and host can see the same conversation.</div><?php endif; ?></section></div></<?= $isHost ? 'div' : 'main' ?>><script>const stream=document.getElementById('msgStream');if(stream)stream.scrollTop=stream.scrollHeight;</script><?php if ($isHost) { appShellEnd(); } else { include __DIR__ . '/footer.php'; } ?></body></html>
