<?php
// v9.34: the admin's support inbox. Guests and hosts write from "Contact support"; the admin answers here, live.
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/support.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin(); requireRole('admin');
ensureSupportTables($pdo);
$uid = (int)$_SESSION['user_id']; $cats = supportCategories();
$filter = ($_GET['filter'] ?? 'open') === 'closed' ? 'closed' : (($_GET['filter'] ?? '') === 'all' ? 'all' : 'open');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $tid = (int)($_POST['thread_id'] ?? 0); $act = $_POST['action'] ?? '';
    $c = $pdo->prepare('SELECT * FROM support_threads WHERE thread_id = ?'); $c->execute([$tid]); $t = $c->fetch(PDO::FETCH_ASSOC);
    if ($t && $act === 'reply') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body !== '' && mb_strlen($body) <= 2000) supportAddMessage($pdo, $tid, $uid, true, $body); else setFlash('error', 'Write a reply between 1 and 2,000 characters.');
    } elseif ($t && $act === 'close') {
        $pdo->prepare("UPDATE support_threads SET status = 'closed' WHERE thread_id = ?")->execute([$tid]);
        notify($pdo, (int)$t['user_id'], 'Support request closed', 'Your support request "' . $t['subject'] . '" was marked as solved. Write again if you still need help.', (($pdo->query('SELECT role FROM users WHERE user_id = ' . (int)$t['user_id'])->fetchColumn() === 'host') ? 'host' : 'tourist') . '/support.php?thread_id=' . $tid);
        setFlash('success', 'Marked as solved.');
    } elseif ($t && $act === 'reopen') {
        $pdo->prepare("UPDATE support_threads SET status = 'open' WHERE thread_id = ?")->execute([$tid]);
    }
    redirect('support.php?thread_id=' . $tid . '&filter=' . $filter);
}

$where = $filter === 'all' ? '1=1' : "t.status = '" . $filter . "'";
$threads = $pdo->query("SELECT t.*, u.full_name, u.role, u.email,
    (SELECT COUNT(*) FROM support_messages m WHERE m.thread_id = t.thread_id AND m.is_admin = 0 AND m.read_at IS NULL) AS unread
    FROM support_threads t JOIN users u ON u.user_id = t.user_id WHERE $where ORDER BY unread DESC, t.updated_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$activeId = (int)($_GET['thread_id'] ?? 0); $active = null; $messages = [];
if ($activeId) {
    $a = $pdo->prepare('SELECT t.*, u.full_name, u.role, u.email, u.phone_number FROM support_threads t JOIN users u ON u.user_id = t.user_id WHERE t.thread_id = ?'); $a->execute([$activeId]); $active = $a->fetch(PDO::FETCH_ASSOC);
    if ($active) {
        $pdo->prepare('UPDATE support_messages SET read_at = CURRENT_TIMESTAMP WHERE thread_id = ? AND is_admin = 0 AND read_at IS NULL')->execute([$activeId]);
        $mq = $pdo->prepare('SELECT m.*, u.full_name AS sender_name FROM support_messages m JOIN users u ON u.user_id = m.sender_id WHERE m.thread_id = ? ORDER BY m.message_id'); $mq->execute([$activeId]); $messages = $mq->fetchAll(PDO::FETCH_ASSOC);
    }
}
$lastId = $messages ? (int)end($messages)['message_id'] : 0;
$flash = getFlash(); $base = appBasePath();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Support inbox — StayExplore Kenya</title>
<link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="../assets/css/ui.css"><script src="../assets/js/ui.js" defer></script>
<style>
.sp-layout{display:grid;grid-template-columns:320px minmax(0,1fr);border:1px solid var(--border);border-radius:12px;overflow:hidden;background:var(--surface-2)}
.sp-side{border-right:1px solid var(--border);max-height:78vh;overflow:auto}.sp-tabs{display:flex;gap:6px;padding:10px 12px;border-bottom:1px solid var(--border)}.sp-tabs a{padding:5px 12px;border-radius:14px;text-decoration:none;font-size:13px;border:1px solid var(--border);color:inherit}.sp-tabs a.on{background:var(--forest,#0d3b2e);color:#fff;border-color:transparent}
.sp-item{display:block;padding:12px 16px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit}.sp-item.active,.sp-item:hover{background:rgba(22,134,107,.08)}.sp-item strong{display:block;font-size:14px}.sp-item span{font-size:12px;color:var(--text-secondary)}
.sp-badge{display:inline-block;min-width:18px;padding:0 6px;border-radius:9px;background:#a3462a;color:#fff;font-size:11px;font-weight:700;text-align:center;margin-left:6px}
.sp-main{padding:20px;min-width:0}.sp-main h2{margin:0 0 4px}
.chat-stream{background:var(--surface-1,#efeae2);border:1px solid var(--border);border-radius:10px;padding:14px;height:380px;overflow-y:auto;display:flex;flex-direction:column;gap:8px}
.chat-bubble{max-width:82%;padding:9px 12px;border-radius:12px 12px 12px 3px;background:var(--surface-2);box-shadow:0 1px 1px rgba(0,0,0,.12);white-space:pre-wrap;overflow-wrap:anywhere}.chat-bubble.mine{align-self:flex-end;background:#d9fdd3;color:#1a2a22;border-radius:12px 12px 3px 12px}.chat-bubble small{display:block;margin-top:4px;font-size:11px;opacity:.7;text-align:right}
.chat-compose{margin-top:12px}.chat-compose textarea{width:100%;min-height:70px;padding:10px;border:1px solid var(--border);border-radius:8px;font:inherit;background:var(--surface-1,#fff);color:inherit}
@media(max-width:900px){.sp-layout{grid-template-columns:1fr}.sp-side{max-height:260px;border-right:0;border-bottom:1px solid var(--border)}}
</style><link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head><body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
<div class="eyebrow">Administration</div><h1>Support inbox</h1>
<p class="small" style="margin-bottom:14px">Messages from guests and hosts. Replies appear for them instantly and in their notifications.</p>
<?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<div class="sp-layout">
 <aside class="sp-side"><div class="sp-tabs"><?php foreach (['open' => 'Open', 'closed' => 'Solved', 'all' => 'All'] as $k => $l): ?><a class="<?= $filter === $k ? 'on' : '' ?>" href="?filter=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
  <?php if (!$threads): ?><p class="small" style="padding:16px">No messages here.</p><?php endif; ?>
  <?php foreach ($threads as $t): ?><a class="sp-item <?= (int)$t['thread_id'] === $activeId ? 'active' : '' ?>" href="?thread_id=<?= (int)$t['thread_id'] ?>&amp;filter=<?= h($filter) ?>"><strong><?= h($t['subject']) ?><?php if ((int)$t['unread']): ?><span class="sp-badge"><?= (int)$t['unread'] ?></span><?php endif; ?></strong><span><?= h($t['full_name']) ?> (<?= h($t['role']) ?>) · <?= h($cats[$t['category']] ?? 'Other') ?> · <?= h(date('j M, H:i', strtotime($t['updated_at']))) ?></span></a><?php endforeach; ?>
 </aside>
 <section class="sp-main">
 <?php if ($active): ?>
  <h2><?= h($active['subject']) ?></h2>
  <p class="small"><?= h($active['full_name']) ?> (<?= h($active['role']) ?>) · <a href="mailto:<?= h($active['email']) ?>"><?= h($active['email']) ?></a><?= $active['phone_number'] ? ' · ' . h($active['phone_number']) : '' ?> · <?= h($cats[$active['category']] ?? 'Other') ?><?= $active['booking_ref'] ? ' · booking BK-' . h($active['booking_ref']) : '' ?></p>
  <div class="chat-stream" id="chatStream" data-chat-kind="support" data-chat-id="<?= (int)$active['thread_id'] ?>" data-chat-last="<?= $lastId ?>" aria-live="polite">
   <?php foreach ($messages as $m): $mine = (int)$m['is_admin'] === 1; ?><div class="chat-bubble <?= $mine ? 'mine' : '' ?>" data-mid="<?= (int)$m['message_id'] ?>"><?= h($m['body']) ?><small><?= $mine ? 'Support' : h($m['sender_name']) ?> · <?= h(date('d M Y, H:i', strtotime($m['created_at']))) ?></small></div><?php endforeach; ?>
  </div>
  <form class="chat-compose" method="post" data-live-chat><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="reply"><input type="hidden" name="thread_id" value="<?= (int)$active['thread_id'] ?>">
   <label for="spBody" class="small">Reply</label><textarea id="spBody" name="body" maxlength="2000" required placeholder="Write your reply…"></textarea>
   <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap"><button class="btn" type="submit">Send reply</button></div></form>
  <form method="post" style="margin-top:10px"><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="thread_id" value="<?= (int)$active['thread_id'] ?>">
   <?php if ($active['status'] === 'open'): ?><button class="btn btn-outline" name="action" value="close" type="submit">Mark as solved</button><?php else: ?><button class="btn btn-outline" name="action" value="reopen" type="submit">Reopen</button><?php endif; ?></form>
 <?php else: ?><p class="small">Choose a message on the left.</p><?php endif; ?>
 </section>
</div>
<?php appShellEnd(); ?>
</body></html>
