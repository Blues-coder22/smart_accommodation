/* StayExplore Kenya v9.34: live notifications and live chat (no page reload).
 * - Every ~8 s asks api/live.php for new notifications: the bell updates and a small pop-up appears.
 * - A conversation on screen ([data-chat-id]) fetches new messages every ~3 s; sending happens without leaving the page.
 * Pauses while the tab is hidden, backs off after errors, and falls back to normal page loads if JavaScript is off. */
(function () {
  var C = window.SE_LIVE || {}; if (!C.base) return;
  var role = C.role || '';
  function api(path, qs) { return C.base + '/api/' + path + '?as=' + encodeURIComponent(role) + (qs ? '&' + qs : ''); }

  /* ---------- notifications ---------- */
  var since = null, delay = 8000, failures = 0, timer = null;
  function setBell(n) {
    var bell = document.querySelector('.se-bell'); if (!bell) return;
    var badge = bell.querySelector('.se-bell-count');
    if (n > 0) { if (!badge) { badge = document.createElement('span'); badge.className = 'se-bell-count'; bell.appendChild(badge); } badge.textContent = n > 99 ? '99+' : n; }
    else if (badge) badge.remove();
    bell.setAttribute('aria-label', 'Notifications' + (n ? ', ' + n + ' unread' : ''));
  }
  function toastBox() {
    var b = document.getElementById('seToasts'); if (b) return b;
    b = document.createElement('div'); b.id = 'seToasts'; b.setAttribute('aria-live', 'polite'); document.body.appendChild(b); return b;
  }
  function toast(n) {
    var a = document.createElement('a'); a.className = 'se-toast'; a.href = C.base + '/' + n.go + '&as=' + encodeURIComponent(role);
    var t = document.createElement('strong'); t.textContent = n.title; var m = document.createElement('span'); m.textContent = n.message;
    a.appendChild(t); a.appendChild(m); toastBox().appendChild(a);
    setTimeout(function () { a.classList.add('out'); setTimeout(function () { a.remove(); }, 400); }, 7000);
  }
  function poll() {
    clearTimeout(timer);
    if (document.hidden) { timer = setTimeout(poll, 15000); return; }
    fetch(api('live.php', 'since=' + (since === null ? 2000000000 : since)), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (r.status === 401) throw new Error('signed-out'); return r.json(); })
      .then(function (d) {
        failures = 0; delay = 8000;
        if (!d || !d.ok) return;
        if (since !== null) (d.items || []).forEach(toast);
        since = Math.max(since || 0, d.latest || 0);
        setBell(d.unread || 0);
        document.querySelectorAll('[data-live-messages]').forEach(function (el) { el.textContent = d.messages > 0 ? d.messages : ''; el.hidden = !(d.messages > 0); });
        document.dispatchEvent(new CustomEvent('se:live', { detail: d }));
      })
      .catch(function (e) { failures++; delay = Math.min(60000, 8000 * (failures + 1)); if (e && e.message === 'signed-out') { return; } })
      .then(function () { if (failures < 6 || true) timer = setTimeout(poll, delay); });
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  poll();

  /* ---------- live chat ---------- */
  var stream = document.querySelector('[data-chat-id]');
  if (stream) {
    var kind = stream.dataset.chatKind || 'thread', id = stream.dataset.chatId, last = parseInt(stream.dataset.chatLast || '0', 10) || 0;
    var cls = stream.dataset.bubble || 'chat-bubble';
    var seen = {}; stream.querySelectorAll('[data-mid]').forEach(function (e) { seen[e.dataset.mid] = 1; });
    var empty = stream.querySelector('.msg-empty');
    function add(m) {
      if (seen[m.id]) return; seen[m.id] = 1; last = Math.max(last, m.id);
      if (empty) { empty.remove(); empty = null; }
      var near = stream.scrollHeight - stream.scrollTop - stream.clientHeight < 120 || m.mine;
      var b = document.createElement('div'); b.className = cls + (m.mine ? ' mine' : ''); b.dataset.mid = m.id;
      var t = document.createElement('div'); t.style.whiteSpace = 'pre-wrap'; t.style.overflowWrap = 'anywhere'; t.textContent = m.body;
      var s = document.createElement('small'); s.textContent = (m.mine ? 'You' : m.name) + ' · ' + m.time;
      b.appendChild(t); b.appendChild(s); stream.appendChild(b);
      if (near) stream.scrollTop = stream.scrollHeight;
    }
    var ct = null, cfail = 0;
    function chatPoll() {
      clearTimeout(ct);
      if (document.hidden) { ct = setTimeout(chatPoll, 8000); return; }
      fetch(api('chat.php', 'kind=' + kind + '&id=' + id + '&after=' + last), { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) { cfail = 0; if (d && d.ok) (d.messages || []).forEach(add); })
        .catch(function () { cfail++; })
        .then(function () { ct = setTimeout(chatPoll, cfail ? Math.min(30000, 3000 * (cfail + 1)) : 3000); });
    }
    chatPoll();
    stream.scrollTop = stream.scrollHeight;
    var form = document.querySelector('form[data-live-chat]');
    if (form) form.addEventListener('submit', function (e) {
      var ta = form.querySelector('textarea[name="body"]'); if (!ta) return;
      var text = ta.value.trim(); if (!text) { e.preventDefault(); return; }
      e.preventDefault();
      var btn = form.querySelector('button[type="submit"]'); if (btn) btn.disabled = true;
      var fd = new FormData(); fd.append('kind', kind); fd.append('id', id); fd.append('body', text);
      fd.append('csrf_token', (form.querySelector('input[name="csrf_token"]') || {}).value || '');
      fetch(api('chat.php'), { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Could not send. Please try again.' }; }); })
        .then(function (d) {
          if (d && d.ok) { (d.messages || []).forEach(add); ta.value = ''; ta.focus(); }
          else { var er = form.querySelector('.chat-err') || form.insertAdjacentElement('afterbegin', Object.assign(document.createElement('div'), { className: 'chat-err', role: 'alert' })); er.style.color = '#b3261e'; er.textContent = (d && d.error) || 'Could not send.'; }
        })
        .catch(function () { form.submit(); })   // network trouble: fall back to the normal form
        .then(function () { if (btn) btn.disabled = false; });
    });
    var ta2 = document.querySelector('form[data-live-chat] textarea[name="body"]');
    if (ta2) ta2.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); ta2.form.requestSubmit(); } });
  }
})();
