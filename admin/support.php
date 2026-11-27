<?php
// v9.34: the admin's support inbox. Guests and hosts write from "Contact support"; the admin answers here, live.
// v9.38.1: ticket management: statuses (open, in progress, waiting for the customer, solved), priority, category changes, assignment,
// filters and search, and private notes with an automatic record of every change. Customers never see notes or the record.
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/db.php';
require_once '../includes/support.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin(); requireRole('admin');
ensureSupportTables($pdo);
$uid = (int)$_SESSION['user_id']; $me = (string)($_SESSION['full_name'] ?? 'An admin');
$cats = supportCategories(); $pris = supportPriorities(); $statuses = supportStatuses();
$filter = in_array($_GET['filter'] ?? 'open', ['open', 'waiting', 'closed', 'all'], true) ? $_GET['filter'] : 'open';
$fCat  = array_key_exists($_GET['cat'] ?? '', $cats) ? $_GET['cat'] : '';
$fPri  = array_key_exists($_GET['pri'] ?? '', $pris) ? $_GET['pri'] : '';
$fRole = in_array($_GET['role'] ?? '', ['tourist', 'host'], true) ? $_GET['role'] : '';
$fWho  = in_array($_GET['who'] ?? '', ['mine', 'unassigned'], true) ? $_GET['who'] : '';
$fQ    = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$keep  = array_filter(['filter' => $filter, 'cat' => $fCat, 'pri' => $fPri, 'role' => $fRole, 'who' => $fWho, 'q' => $fQ], fn($v) => $v !== '');
$link  = function (array $over = []) use ($keep) { return '?' . http_build_query(array_filter(array_merge($keep, $over), fn($v) => $v !== '' && $v !== null)); };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $tid = (int)($_POST['thread_id'] ?? 0); $act = $_POST['action'] ?? '';
    $c = $pdo->prepare('SELECT * FROM support_threads WHERE thread_id = ?'); $c->execute([$tid]); $t = $c->fetch(PDO::FETCH_ASSOC);
    if ($t && $act === 'reply') {
        $body = trim((string)($_POST['body'] ?? ''));
        if ($body !== '' && mb_strlen($body) <= 2000) supportAddMessage($pdo, $tid, $uid, true, $body); else setFlash('error', 'Write a reply between 1 and 2,000 characters.');
    } elseif ($t && $act === 'close') {
        $pdo->prepare("UPDATE support_threads SET status = 'closed' WHERE thread_id = ?")->execute([$tid]);
        supportLogNote($pdo, $tid, $uid, $me . ' marked it as solved.', 'event');
        notify($pdo, (int)$t['user_id'], 'Support request closed', 'Your support request "' . $t['subject'] . '" was marked as solved. Write again if you still need help.', supportPortalFor($pdo, (int)$t['user_id']) . '/support.php?thread_id=' . $tid);
        setFlash('success', 'Marked as solved.');
    } elseif ($t && $act === 'reopen') {
        $pdo->prepare("UPDATE support_threads SET status = 'open' WHERE thread_id = ?")->execute([$tid]);
        supportLogNote($pdo, $tid, $uid, $me . ' reopened it.', 'event');
    } elseif ($t && $act === 'update') {
        // Status, priority and category from one form; each change that really happened is recorded.
        $ns = (string)($_POST['status'] ?? $t['status']); $np = (string)($_POST['priority'] ?? $t['priority']); $nc = (string)($_POST['category'] ?? $t['category']);
        if (!isset($statuses[$ns])) $ns = $t['status'];
        if (!isset($pris[$np])) $np = $t['priority'];
        if (!isset($cats[$nc])) $nc = $t['category'];
        if ($t['status'] === 'closed') $ns = 'closed';   // a solved ticket is changed only with Reopen
        $pdo->prepare('UPDATE support_threads SET status = ?, priority = ?, category = ? WHERE thread_id = ?')->execute([$ns, $np, $nc, $tid]);
        if ($ns !== $t['status']) {
            supportLogNote($pdo, $tid, $uid, $me . ' changed the status from ' . supportStatusLabel($t['status']) . ' to ' . supportStatusLabel($ns) . '.', 'event');
            if ($ns === 'in_progress') notify($pdo, (int)$t['user_id'], 'Support is working on your request', 'StayExplore support has started on: ' . $t['subject'], supportPortalFor($pdo, (int)$t['user_id']) . '/support.php?thread_id=' . $tid);
        }
        if ($np !== $t['priority']) supportLogNote($pdo, $tid, $uid, $me . ' changed the priority from ' . $pris[$t['priority']] . ' to ' . $pris[$np] . '.', 'event');
        if ($nc !== $t['category']) supportLogNote($pdo, $tid, $uid, $me . ' changed the category from ' . ($cats[$t['category']] ?? 'Other') . ' to ' . $cats[$nc] . '.', 'event');
        setFlash('success', 'Saved.');
    } elseif ($t && $act === 'assign') {
        $toMe = ($_POST['to'] ?? '') === 'me';
        $pdo->prepare('UPDATE support_threads SET assigned_to = ? WHERE thread_id = ?')->execute([$toMe ? $uid : null, $tid]);
        supportLogNote($pdo, $tid, $uid, $toMe ? $me . ' took this ticket.' : $me . ' removed the assignment.', 'event');
    } elseif ($t && $act === 'add_note') {
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note !== '' && mb_strlen($note) <= 2000) { supportLogNote($pdo, $tid, $uid, $note, 'note'); setFlash('success', 'Private note saved. Only admins can see it.'); }
        else setFlash('error', 'Write a note between 1 and 2,000 characters.');
    }
    redirect('support.php?' . http_build_query(array_merge($keep, ['thread_id' => $tid])));
}

$where = []; $params = [];
if ($filter === 'open') $where[] = "t.status IN ('open','in_progress')";
elseif ($filter === 'waiting') $where[] = "t.status = 'waiting'";
elseif ($filter === 'closed') $where[] = "t.status = 'closed'";
if ($fCat !== '')  { $where[] = 't.category = ?'; $params[] = $fCat; }
if ($fPri !== '')  { $where[] = 't.priority = ?'; $params[] = $fPri; }
if ($fRole !== '') { $where[] = 'u.role = ?'; $params[] = $fRole; }
if ($fWho === 'mine') { $where[] = 't.assigned_to = ?'; $params[] = $uid; }
elseif ($fWho === 'unassigned') $where[] = "t.assigned_to IS NULL AND t.status <> 'closed'";
if ($fQ !== '') { $like = '%' . addcslashes($fQ, '%_\\') . '%'; $where[] = '(t.subject LIKE ? OR u.full_name LIKE ? OR u.email LIKE ? OR t.booking_ref LIKE ?)'; array_push($params, $like, $like, $like, $like); }
$ts = $pdo->prepare("SELECT t.*, u.full_name, u.role, u.email, a.full_name AS assignee_name,
    (SELECT COUNT(*) FROM support_messages m WHERE m.thread_id = t.thread_id AND m.is_admin = 0 AND m.read_at IS NULL) AS unread
    FROM support_threads t JOIN users u ON u.user_id = t.user_id LEFT JOIN users a ON a.user_id = t.assigned_to
    " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
    ORDER BY (t.status = 'closed'), FIELD(t.priority, 'urgent', 'high', 'normal', 'low'), unread DESC, t.updated_at DESC LIMIT 100");
$ts->execute($params); $threads = $ts->fetchAll(PDO::FETCH_ASSOC);
$tc = $pdo->query("SELECT SUM(status IN ('open','in_progress')) AS o, SUM(status = 'waiting') AS w, SUM(status = 'closed') AS c, COUNT(*) AS a FROM support_threads")->fetch(PDO::FETCH_ASSOC);
$tabCounts = ['open' => (int)$tc['o'], 'waiting' => (int)$tc['w'], 'closed' => (int)$tc['c'], 'all' => (int)$tc['a']];
$activeId = (int)($_GET['thread_id'] ?? 0); $active = null; $messages = []; $notes = [];
if ($activeId) {
    $a = $pdo->prepare('SELECT t.*, u.full_name, u.role, u.email, u.phone_number, ag.full_name AS assignee_name FROM support_threads t JOIN users u ON u.user_id = t.user_id LEFT JOIN users ag ON ag.user_id = t.assigned_to WHERE t.thread_id = ?'); $a->execute([$activeId]); $active = $a->fetch(PDO::FETCH_ASSOC);
    if ($active) {
        $pdo->prepare('UPDATE support_messages SET read_at = CURRENT_TIMESTAMP WHERE thread_id = ? AND is_admin = 0 AND read_at IS NULL')->execute([$activeId]);
        $mq = $pdo->prepare('SELECT m.*, u.full_name AS sender_name FROM support_messages m JOIN users u ON u.user_id = m.sender_id WHERE m.thread_id = ? ORDER BY m.message_id'); $mq->execute([$activeId]); $messages = $mq->fetchAll(PDO::FETCH_ASSOC);
        $nq = $pdo->prepare('SELECT n.*, u.full_name AS admin_name FROM support_notes n LEFT JOIN users u ON u.user_id = n.admin_id WHERE n.thread_id = ? ORDER BY n.note_id'); $nq->execute([$activeId]); $notes = $nq->fetchAll(PDO::FETCH_ASSOC);
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
.sp-filters{display:grid;grid-template-columns:1fr 1fr;gap:6px;padding:10px 12px;border-bottom:1px solid var(--border)}.sp-filters input,.sp-filters select{width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:8px;font:inherit;font-size:13px;background:var(--surface-1,#fff);color:inherit}.sp-filters .wide{grid-column:1/-1}.sp-filters .row{grid-column:1/-1;display:flex;gap:8px;align-items:center}
.sp-chip{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:700;margin-right:4px;border:1px solid currentColor}.sp-chip.urgent{color:#a3462a}.sp-chip.high{color:#a76a00}.sp-chip.st{opacity:.85}
.sp-controls{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:10px 0}.sp-controls label{display:block;font-size:12px}.sp-controls select{padding:7px 8px;border:1px solid var(--border);border-radius:8px;background:var(--surface-1,#fff);color:inherit}
.sp-notes{margin-top:16px;padding:12px;border:1px dashed var(--border);border-radius:10px}.sp-notes h3{margin:0 0 4px;font-size:14px}.sp-notes ol{list-style:none;margin:8px 0;padding:0;display:grid;gap:6px}.sp-notes li{font-size:13px}.sp-notes li.event{opacity:.75;font-style:italic}.sp-notes textarea{width:100%;min-height:56px;padding:8px;border:1px solid var(--border);border-radius:8px;font:inherit;background:var(--surface-1,#fff);color:inherit}
@media(max-width:900px){.sp-layout{grid-template-columns:1fr}.sp-side{max-height:260px;border-right:0;border-bottom:1px solid var(--border)}}
</style><link rel="stylesheet" href="../assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="../assets/js/se-theme.js"></script><script src="../assets/js/se-i18n.js"></script></head><body>
<?php require_once __DIR__ . '/../includes/app_shell.php'; appShellStart(); ?>
<div class="eyebrow">Administration</div><h1>Support inbox</h1>
<p class="small" style="margin-bottom:14px">Messages from guests and hosts. Replies appear for them instantly and in their notifications.</p>
<?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<div class="sp-layout">
 <aside class="sp-side"><div class="sp-tabs"><?php foreach (['open' => 'Open', 'waiting' => 'Waiting', 'closed' => 'Solved', 'all' => 'All'] as $k => $l): ?><a class="<?= $filter === $k ? 'on' : '' ?>" href="<?= h($link(['filter' => $k, 'thread_id' => null])) ?>"><?= $l ?> (<?= (int)$tabCounts[$k] ?>)</a><?php endforeach; ?></div>
  <form method="get" class="sp-filters" role="search" aria-label="Filter messages"><input type="hidden" name="filter" value="<?= h($filter) ?>">
   <input class="wide" type="search" name="q" value="<?= h($fQ) ?>" placeholder="Search title, name, email or booking" aria-label="Search">
   <select name="cat" aria-label="Category"><option value="">All categories</option><?php foreach ($cats as $k => $v): ?><option value="<?= h($k) ?>" <?= $fCat === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
   <select name="pri" aria-label="Priority"><option value="">All priorities</option><?php foreach ($pris as $k => $v): ?><option value="<?= h($k) ?>" <?= $fPri === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
   <select name="role" aria-label="From"><option value="">Guests and hosts</option><option value="tourist" <?= $fRole === 'tourist' ? 'selected' : '' ?>>Guests</option><option value="host" <?= $fRole === 'host' ? 'selected' : '' ?>>Hosts</option></select>
   <select name="who" aria-label="Assigned"><option value="">Anyone</option><option value="mine" <?= $fWho === 'mine' ? 'selected' : '' ?>>Assigned to me</option><option value="unassigned" <?= $fWho === 'unassigned' ? 'selected' : '' ?>>Unassigned</option></select>
   <div class="row"><button class="btn" type="submit">Filter</button><a class="small" href="?filter=<?= h($filter) ?>">Clear</a></div></form>
  <?php if (!$threads): ?><p class="small" style="padding:16px">No messages here.</p><?php endif; ?>
  <?php foreach ($threads as $t): ?><a class="sp-item <?= (int)$t['thread_id'] === $activeId ? 'active' : '' ?>" href="<?= h($link(['thread_id' => (int)$t['thread_id']])) ?>"><strong><?php if (in_array($t['priority'], ['urgent', 'high'], true)): ?><span class="sp-chip <?= h($t['priority']) ?>"><?= h($pris[$t['priority']]) ?></span><?php endif; ?><?= h($t['subject']) ?><?php if ((int)$t['unread']): ?><span class="sp-badge"><?= (int)$t['unread'] ?></span><?php endif; ?></strong><span><?= h($t['full_name']) ?> (<?= h($t['role']) ?>) · <?= h($cats[$t['category']] ?? 'Other') ?> · <?= h(supportStatusLabel($t['status'])) ?><?= $t['assignee_name'] ? ' · ' . h($t['assignee_name']) : '' ?> · <?= h(date('j M, H:i', strtotime($t['updated_at']))) ?></span></a><?php endforeach; ?>
 </aside>
 <section class="sp-main">
 <?php if ($active): ?>
  <h2><?= h($active['subject']) ?></h2>
  <p class="small"><?= h($active['full_name']) ?> (<?= h($active['role']) ?>) · <a href="mailto:<?= h($active['email']) ?>"><?= h($active['email']) ?></a><?= $active['phone_number'] ? ' · ' . h($active['phone_number']) : '' ?> · <?= h($cats[$active['category']] ?? 'Other') ?><?= $active['booking_ref'] ? ' · booking BK-' . h($active['booking_ref']) : '' ?></p>
   <form method="post" class="sp-controls"><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="thread_id" value="<?= (int)$active['thread_id'] ?>"><input type="hidden" name="action" value="update">
    <div><label for="spSt">Status</label><?php if ($active['status'] === 'closed'): ?><select id="spSt" disabled><option>Solved</option></select><?php else: ?><select id="spSt" name="status"><?php foreach ($statuses as $k => $v): ?><option value="<?= h($k) ?>" <?= $active['status'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select><?php endif; ?></div>
    <div><label for="spPr">Priority</label><select id="spPr" name="priority"><?php foreach ($pris as $k => $v): ?><option value="<?= h($k) ?>" <?= $active['priority'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
    <div><label for="spCt">Category</label><select id="spCt" name="category"><?php foreach ($cats as $k => $v): ?><option value="<?= h($k) ?>" <?= $active['category'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-outline" type="submit">Save</button>
    <span class="small">Assigned to: <strong><?= $active['assignee_name'] ? h($active['assignee_name']) : 'nobody' ?></strong></span></form>
  <div class="chat-stream" id="chatStream" data-chat-kind="support" data-chat-id="<?= (int)$active['thread_id'] ?>" data-chat-last="<?= $lastId ?>" aria-live="polite">
   <?php foreach ($messages as $m): $mine = (int)$m['is_admin'] === 1; ?><div class="chat-bubble <?= $mine ? 'mine' : '' ?>" data-mid="<?= (int)$m['message_id'] ?>"><?= h($m['body']) ?><small><?= $mine ? 'Support' : h($m['sender_name']) ?> · <?= h(date('d M Y, H:i', strtotime($m['created_at']))) ?></small></div><?php endforeach; ?>
  </div>
  <form class="chat-compose" method="post" data-live-chat><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="reply"><input type="hidden" name="thread_id" value="<?= (int)$active['thread_id'] ?>">
   <label for="spBody" class="small">Reply</label><textarea id="spBody" name="body" maxlength="2000" required placeholder="Write your reply…"></textarea>
   <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap"><button class="btn" type="submit">Send reply</button></div></form>
  <form method="post" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap"><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="thread_id" value="<?= (int)$active['thread_id'] ?>">
    <?php if ($active['status'] !== 'closed'): ?><button class="btn btn-outline" name="action" value="close" type="submit">Mark as solved</button><?php else: ?><button class="btn btn-outline" name="action" value="reopen" type="submit">Reopen</button><?php endif; ?>
    <?php if ((int)$active['assigned_to'] !== $uid): ?><button class="btn btn-outline" name="action" value="assign" type="submit" onclick="this.form.querySelector('[name=to]').value='me'">Take this ticket</button><?php elseif ($active['assigned_to']): ?><button class="btn btn-outline" name="action" value="assign" type="submit" onclick="this.form.querySelector('[name=to]').value='none'">Release it</button><?php endif; ?>
    <input type="hidden" name="to" value=""></form>
   <section class="sp-notes" aria-label="Private notes">
    <h3>Private notes</h3><p class="small" style="margin:0">Only admins see these. They are never sent to the customer. Changes to status, priority, category and assignment are recorded here automatically.</p>
    <?php if ($notes): ?><ol><?php foreach ($notes as $n): ?><li class="<?= $n['kind'] === 'event' ? 'event' : '' ?>"><span class="small"><?= h(date('j M Y, H:i', strtotime($n['created_at']))) ?><?= $n['kind'] === 'note' ? ' · ' . h($n['admin_name'] ?: 'Admin') : '' ?></span><br><?= nl2br(h($n['body'])) ?></li><?php endforeach; ?></ol><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="thread_id" value="<?= (int)$active['thread_id'] ?>"><input type="hidden" name="action" value="add_note">
     <label for="spNote" class="small">Add a private note</label><textarea id="spNote" name="note" maxlength="2000" required placeholder="What you checked, who you spoke to, what is left to do"></textarea>
     <button class="btn btn-outline" type="submit" style="margin-top:6px">Save note</button></form>
   </section>
 <?php else: ?><p class="small">Choose a message on the left.</p><?php endif; ?>
 </section>
</div>
<?php appShellEnd(); ?>
</body></html>