/* StayExplore Kenya v9.38 - tourist top bar: account menu (desktop) and navigation drawer (phones).
   The language / currency / dark-mode controls exist once in the page; they are moved into the drawer on small screens
   and back into the account menu on wide ones, so nothing is duplicated. */
(function () {
  var hdr = document.querySelector('[data-tn]'); if (!hdr) return;
  var burger = hdr.querySelector('.tn-burger'), panel = hdr.querySelector('#tnPanel'), scrim = hdr.querySelector('.tn-scrim');
  var acct = hdr.querySelector('[data-tn-acct]'), acctBtn = acct && acct.querySelector('.tn-acct-btn');
  var prefs = hdr.querySelector('[data-tn-prefs]'), prefsDrawer = hdr.querySelector('[data-tn-prefs-drawer]');
  var mq = window.matchMedia ? window.matchMedia('(max-width:1000px)') : { matches: false };
  var lastFocus = null;

  /* ----- drawer ----- */
  function setOpen(open) {
    hdr.classList.toggle('open', open);
    document.documentElement.classList.toggle('tn-locked', open);
    if (scrim) scrim.hidden = !open;
    if (burger) { burger.setAttribute('aria-expanded', open ? 'true' : 'false'); burger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu'); }
    if (open) { lastFocus = document.activeElement; var first = panel.querySelector('.tn-close'); if (first) first.focus(); }
    else if (lastFocus && lastFocus.focus) { lastFocus.focus(); lastFocus = null; }
  }
  if (burger) burger.addEventListener('click', function () { setOpen(!hdr.classList.contains('open')); });
  hdr.addEventListener('click', function (e) {
    if (e.target.closest('[data-tn-close]')) { setOpen(false); return; }
    if (e.target.closest('.tn-panel a')) { setOpen(false); }
  });

  /* keep Tab inside the open drawer */
  panel.addEventListener('keydown', function (e) {
    if (e.key !== 'Tab' || !hdr.classList.contains('open')) return;
    var f = panel.querySelectorAll('a[href],button,select'); if (!f.length) return;
    var a = f[0], z = f[f.length - 1];
    if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); }
    else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); }
  });

  /* ----- account menu ----- */
  function closeAcct() { if (acct) { acct.classList.remove('open'); if (acctBtn) acctBtn.setAttribute('aria-expanded', 'false'); } }
  if (acctBtn) {
    acctBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      var o = acct.classList.toggle('open'); acctBtn.setAttribute('aria-expanded', o ? 'true' : 'false');
    });
    document.addEventListener('click', function (e) { if (!acct.contains(e.target)) closeAcct(); });
  }
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (acct && acct.classList.contains('open')) { closeAcct(); if (acctBtn) acctBtn.focus(); }
    if (hdr.classList.contains('open')) setOpen(false);
  });

  /* ----- move the preference controls between the dropdown and the drawer ----- */
  function placePrefs() {
    if (!prefs || !prefsDrawer) return;
    var kids = Array.prototype.slice.call(prefs.children).filter(function (n) { return !n.classList.contains('tn-label'); });
    var kidsD = Array.prototype.slice.call(prefsDrawer.children).filter(function (n) { return !n.classList.contains('tn-label'); });
    var all = kids.concat(kidsD);
    var target = mq.matches ? prefsDrawer : prefs;
    all.forEach(function (n) { if (n.parentNode !== target) target.appendChild(n); });
  }
  placePrefs();
  function onChange() { if (!mq.matches) setOpen(false); placePrefs(); }
  if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
})();
