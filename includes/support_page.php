<?php
// v9.34: "Contact support" for guests (tourist/support.php) and hosts (host/support.php). Wrapped by those two small files.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/support.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
$role = (string)($_SESSION['role'] ?? ''); $isHost = $role === 'host';
if (!in_array($role, ['tourist', 'host'], true)) { redirect(appBasePath() . '/admin/support.php'); }
$uid = (int)$_SESSION['user_id']; $base = appBasePath(); $self = $base . '/' . ($isHost ? 'host' : 'tourist') . '/support.php';
ensureSupportTables($pdo);
$cats = supportCategories(); $errors = [];
$f = ['subject' => '', 'category' => 'other', 'body' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'new') {
        $f['subject'] = trim((string)($_POST['subject'] ?? '')); $f['category'] = array_key_exists($_POST['category'] ?? '', $cats) ? $_POST['category'] : 'other'; $f['body'] = trim((string)($_POST['body'] ?? ''));
        $len = function ($s) { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); };
        if ($len($f['subject']) < 4 || $len($f['subject']) > 160) $errors['subject'] = 'Give your message a short title (4 to 160 characters).';
        if ($len($f['body']) < 10 || $len($f['body']) > 2000) $errors['body'] = 'Tell us what happened (10 to 2,000 characters).';
        if (!$errors) {
            $ref = strtoupper(preg_replace('/^BK-/i', '', trim((string)($_POST['booking_ref'] ?? ''))));
            $pdo->prepare('INSERT INTO support_threads (user_id, subject, category, booking_ref, priority) VALUES (?,?,?,?,?)')->execute([$uid, $f['subject'], $f['category'], $ref !== '' ? mb_substr($ref, 0, 20) : null, supportDefaultPriority($f['category'])]);
            $tid = (int)$pdo->lastInsertId();
            supportAddMessage($pdo, $tid, $uid, false, $f['body']);
            setFlash('success', 'Your message was sent to StayExplore support. You will see the reply here and in your notifications.');
            redirect($self . '?thread_id=' . $tid);
        }
    } elseif ($act === 'reply') {
        $tid = (int)($_POST['thread_id'] ?? 0); $body = trim((string)($_POST['body'] ?? ''));
        $c = $pdo->prepare('SELECT 1 FROM support_threads WHERE thread_id = ? AND user_id = ?'); $c->execute([$tid, $uid]);
        if ($c->fetchColumn() && $body !== '' && mb_strlen($body) <= 2000) supportAddMessage($pdo, $tid, $uid, false, $body);
        else setFlash('error', 'Write a message between 1 and 2,000 characters.');
        redirect($self . '?thread_id=' . $tid);
    }
}

$list = $pdo->prepare("SELECT t.*, (SELECT COUNT(*) FROM support_messages m WHERE m.thread_id = t.thread_id AND m.is_admin = 1 AND m.read_at IS NULL) AS unread,
        (SELECT m.body FROM support_messages m WHERE m.thread_id = t.thread_id ORDER BY m.message_id DESC LIMIT 1) AS last_body
        FROM support_threads t WHERE t.user_id = ? ORDER BY t.updated_at DESC LIMIT 50");
$list->execute([$uid]); $threads = $list->fetchAll(PDO::FETCH_ASSOC);
$activeId = (int)($_GET['thread_id'] ?? 0); $active = null; $messages = [];
if ($activeId) {
    foreach ($threads as $t) if ((int)$t['thread_id'] === $activeId) $active = $t;
    if ($active) {
        $pdo->prepare('UPDATE support_messages SET read_at = CURRENT_TIMESTAMP WHERE thread_id = ? AND is_admin = 1 AND read_at IS NULL')->execute([$activeId]);
        $mq = $pdo->prepare('SELECT m.*, u.full_name AS sender_name FROM support_messages m JOIN users u ON u.user_id = m.sender_id WHERE m.thread_id = ? ORDER BY m.message_id'); $mq->execute([$activeId]); $messages = $mq->fetchAll(PDO::FETCH_ASSOC);
    }
}
$newMode = !$active;
$prefRef = strtoupper(preg_replace('/^BK-/i', '', trim((string)($_GET['booking'] ?? ''))));
if ($newMode && $_SERVER['REQUEST_METHOD'] !== 'POST' && $prefRef !== '') { $f['subject'] = 'About booking BK-' . $prefRef; $f['category'] = 'booking'; }
$mail = supportMailLinks($pdo, $active ? 'Re: ' . $active['subject'] : ($f['subject'] ?: 'StayExplore Kenya support'));
$flash = getFlash();
$lastId = $messages ? (int)end($messages)['message_id'] : 0;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Contact support — StayExplore Kenya</title>
<link rel="stylesheet" href="<?= h($base) ?>/assets/css/style.css"><link rel="stylesheet" href="<?= h($base) ?>/assets/css/ui.css"><script src="<?= h($base) ?>/assets/js/ui.js" defer></script>
<style>
.sp-page{max-width:1100px;margin:0 auto;padding:28px 20px 60px}.sp-layout{display:grid;grid-template-columns:300px minmax(0,1fr);gap:0;border:1px solid var(--border);border-radius:12px;overflow:hidden;background:var(--surface-2)}
.sp-side{border-right:1px solid var(--border);background:var(--surface-1,#f8faf9)}.sp-side h2{font-size:15px;margin:0;padding:14px 16px;border-bottom:1px solid var(--border)}
.sp-item{display:block;padding:12px 16px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit}.sp-item.active,.sp-item:hover{background:rgba(22,134,107,.08)}.sp-item strong{display:block;font-size:14px}.sp-item span{font-size:12px;color:var(--text-secondary)}
.sp-new{display:block;margin:12px 16px;text-align:center}.sp-main{padding:20px;min-width:0}.sp-main h2{margin:0 0 6px}.sp-field{margin:0 0 14px}.sp-field label{display:block;font-weight:600;margin-bottom:5px}
.sp-field input,.sp-field select,.sp-field textarea{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font:inherit;background:var(--surface-1,#fff);color:inherit}.sp-err{color:#b3261e;font-size:13px;margin-top:4px}
.sp-badge{display:inline-block;min-width:18px;padding:0 6px;border-radius:9px;background:#a3462a;color:#fff;font-size:11px;font-weight:700;text-align:center;margin-left:6px}
.sp-mail{margin-top:22px;padding:14px;border:1px dashed var(--border);border-radius:10px;font-size:14px}.sp-mail a{margin-right:12px;font-weight:600}
.chat-stream{background:var(--surface-1,#efeae2);border:1px solid var(--border);border-radius:10px;padding:14px;height:380px;overflow-y:auto;display:flex;flex-direction:column;gap:8px}
.chat-bubble{max-width:82%;padding:9px 12px;border-radius:12px 12px 12px 3px;background:var(--surface-2);box-shadow:0 1px 1px rgba(0,0,0,.12);white-space:pre-wrap;overflow-wrap:anywhere}.chat-bubble.mine{align-self:flex-end;background:#d9fdd3;color:#1a2a22;border-radius:12px 12px 3px 12px}.chat-bubble small{display:block;margin-top:4px;font-size:11px;opacity:.7;text-align:right}
.chat-compose{margin-top:12px}.chat-compose textarea{width:100%;min-height:70px;padding:10px;border:1px solid var(--border);border-radius:8px;font:inherit;background:var(--surface-1,#fff);color:inherit}
@media(max-width:820px){.sp-layout{grid-template-columns:1fr}.sp-side{border-right:0;border-bottom:1px solid var(--border)}.chat-stream{height:320px}}
</style><link rel="stylesheet" href="<?= h($base) ?>/assets/css/dark.css"><meta name="color-scheme" content="light dark"><script src="<?= h($base) ?>/assets/js/se-theme.js"></script><script src="<?= h($base) ?>/assets/js/se-i18n.js"></script></head><body>
<?php if ($isHost) { require_once __DIR__ . '/app_shell.php'; appShellStart('host'); } else { include __DIR__ . '/header.php'; } ?>
<<?= $isHost ? 'div' : 'main' ?> class="sp-page">
<div class="eyebrow">Help</div><h1>Contact support</h1>
<p class="small" style="margin-bottom:18px">Write to the StayExplore Kenya team here: it is private, you will get the answer on this page and in your notifications, and you can add details any time. Use this for payments, cancellations and refunds, problems with a host or guest, or your account.</p>
<?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<div class="sp-layout">
 <aside class="sp-side"><h2>Your messages (<?= count($threads) ?>)</h2><a class="btn sp-new" href="<?= h($self) ?>">New message</a>
  <?php if (!$threads): ?><p class="small" style="padding:0 16px 16px">Nothing yet.</p><?php endif; ?>
  <?php foreach ($threads as $t): ?><a class="sp-item <?= (int)$t['thread_id'] === $activeId ? 'active' : '' ?>" href="<?= h($self . '?thread_id=' . (int)$t['thread_id']) ?>"><strong><?= h($t['subject']) ?><?php if ((int)$t['unread']): ?><span class="sp-badge"><?= (int)$t['unread'] ?></span><?php endif; ?></strong><span><?= h($cats[$t['category']] ?? 'Other') ?> · <?= h(supportStatusLabel($t['status'], true)) ?> · <?= h(date('j M, H:i', strtotime($t['updated_at']))) ?></span></a><?php endforeach; ?>
 </aside>
 <section class="sp-main">
 <?php if ($active): ?>
  <h2><?= h($active['subject']) ?></h2><p class="small"><?= h($cats[$active['category']] ?? 'Other') ?><?= $active['booking_ref'] ? ' · booking BK-' . h($active['booking_ref']) : '' ?> · <?= h(supportStatusLabel($active['status'], true)) ?></p>
  <div class="chat-stream" id="chatStream" data-chat-kind="support" data-chat-id="<?= (int)$active['thread_id'] ?>" data-chat-last="<?= $lastId ?>" aria-live="polite">
   <?php foreach ($messages as $m): $mine = (int)$m['sender_id'] === $uid; ?><div class="chat-bubble <?= $mine ? 'mine' : '' ?>" data-mid="<?= (int)$m['message_id'] ?>"><?= h($m['body']) ?><small><?= $mine ? 'You' : ((int)$m['is_admin'] ? 'StayExplore support' : h($m['sender_name'])) ?> · <?= h(date('d M Y, H:i', strtotime($m['created_at']))) ?></small></div><?php endforeach; ?>
  </div>
  <form class="chat-compose" method="post" data-live-chat><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="reply"><input type="hidden" name="thread_id" value="<?= (int)$active['thread_id'] ?>">
   <label for="spBody" class="small">Add a message</label><textarea id="spBody" name="body" maxlength="2000" required placeholder="Write here…"></textarea><button class="btn" type="submit" style="margin-top:8px">Send</button></form>
 <?php else: ?>
  <h2>New message to support</h2>
  <form method="post" novalidate><input type="hidden" name="csrf_token" value="<?= h(generateCsrfToken()) ?>"><input type="hidden" name="action" value="new"><input type="hidden" name="booking_ref" value="<?= h($prefRef) ?>">
   <div class="sp-field"><label for="spCat">What is it about?</label><select id="spCat" name="category"><?php foreach ($cats as $k => $v): ?><option value="<?= h($k) ?>" <?= $f['category'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
   <div class="sp-field"><label for="spSub">Title</label><input id="spSub" name="subject" maxlength="160" value="<?= h($f['subject']) ?>" placeholder="For example: My refund for BK-ABC12345"><?php if (isset($errors['subject'])): ?><div class="sp-err"><?= h($errors['subject']) ?></div><?php endif; ?></div>
   <div class="sp-field"><label for="spBody">Your message</label><textarea id="spBody" name="body" rows="6" maxlength="2000" placeholder="Include the booking reference, dates and what you expected to happen."><?= h($f['body']) ?></textarea><?php if (isset($errors['body'])): ?><div class="sp-err"><?= h($errors['body']) ?></div><?php endif; ?></div>
   <button class="btn" type="submit">Send to support</button></form>
 <?php endif; ?>
  <div class="sp-mail"><strong>Prefer email?</strong> Your own email app opens with the support address already filled in, and the message is sent from your own email account.<br>
   <a href="<?= h($mail['mailto']) ?>">Open my email app</a><a href="<?= h($mail['gmail']) ?>" target="_blank" rel="noopener">Open in Gmail</a><a href="<?= h($mail['outlook']) ?>" target="_blank" rel="noopener">Open in Outlook</a><span class="small">To: <?= h($mail['to']) ?></span></div>
 </section>
</div>
</<?= $isHost ? 'div' : 'main' ?>>
<?php if ($isHost) { appShellEnd(); } else { include __DIR__ . '/footer.php'; } ?>
</body></html>