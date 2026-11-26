/* StayExplore Kenya v9.34: emoji picker for message, review and support boxes. Works with the keyboard's own emoji too:
 * the database now stores full emoji (utf8mb4). */
(function () {
  var SETS = [
    ['Smileys', '😀 😃 😄 😁 😆 😅 😂 🤣 😊 🙂 😉 😍 🥰 😘 😎 🤩 🥳 😇 🤗 🤔 😴 😢 😭 😮 😱 😡'],
    ['Hands', '👍 👎 👏 🙌 🙏 🤝 👋 ✌️ 🤞 💪 👌 ❤️ 💚 🧡 💯 🔥 ✨ 🎉'],
    ['Travel', '🏖️ 🌴 🌊 🏝️ 🏔️ 🌄 🌅 🦁 🐘 🦒 🦓 🦏 🐆 🦩 🚗 ✈️ 🚤 ⛺ 🏡 🛏️ 🍽️ ☕ 🍹 📍 🗺️ 📸'],
    ['Handy', '✅ ❌ ⭐ 🌟 💬 📞 💰 🕒 📅 🔑 🧳 ☀️ 🌧️ 🌙']
  ];
  var SEL = 'textarea[data-emoji], .msg-compose textarea, .chat-compose textarea, textarea[name="comment"], textarea[name="host_reply"], textarea[name="description"], textarea[name="intro"]';
  function attach(ta) {
    if (ta.dataset.emojiBound) return; ta.dataset.emojiBound = '1';
    var wrap = document.createElement('div'); wrap.className = 'em-wrap';
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'em-btn'; btn.setAttribute('aria-label', 'Add an emoji'); btn.setAttribute('aria-expanded', 'false'); btn.textContent = '😊';
    var pop = document.createElement('div'); pop.className = 'em-pop'; pop.hidden = true; pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-label', 'Emoji');
    SETS.forEach(function (s) {
      var h = document.createElement('div'); h.className = 'em-h'; h.textContent = s[0]; pop.appendChild(h);
      var g = document.createElement('div'); g.className = 'em-g';
      s[1].split(' ').forEach(function (e) { var b = document.createElement('button'); b.type = 'button'; b.textContent = e; b.setAttribute('aria-label', 'Insert ' + e); g.appendChild(b); });
      pop.appendChild(g);
    });
    wrap.appendChild(btn); wrap.appendChild(pop); ta.insertAdjacentElement('afterend', wrap);
    btn.addEventListener('click', function () { pop.hidden = !pop.hidden; btn.setAttribute('aria-expanded', pop.hidden ? 'false' : 'true'); });
    pop.addEventListener('click', function (ev) {
      var b = ev.target.closest('.em-g button'); if (!b) return;
      var s = ta.selectionStart == null ? ta.value.length : ta.selectionStart, e = ta.selectionEnd == null ? s : ta.selectionEnd;
      ta.value = ta.value.slice(0, s) + b.textContent + ta.value.slice(e); var p = s + b.textContent.length; ta.focus(); ta.setSelectionRange(p, p);
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    });
    document.addEventListener('click', function (ev) { if (!wrap.contains(ev.target)) { pop.hidden = true; btn.setAttribute('aria-expanded', 'false'); } });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !pop.hidden) { pop.hidden = true; btn.focus(); } });
  }
  function init() { document.querySelectorAll(SEL).forEach(attach); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
