/* StayExplore Kenya v9.34: header behaviour. Account menu (desktop) and the phone menu (opens as a full list with the brand on top). */
(function () {
  var hdr = document.querySelector('.se-hdr, .hx-header'); if (!hdr) return;
  var isHx = hdr.classList.contains('hx-header');
  var btn = hdr.querySelector('.nav-toggle, .hx-burger');
  function setOpen(open) {
    hdr.classList.toggle(isHx ? 'open' : 'nav-open', open); document.documentElement.classList.toggle('nav-locked', open && window.innerWidth <= 1100);
    if (!btn) return; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu'); btn.textContent = open ? '\u2715' : '\u2630';
  }
  if (btn && !btn.dataset.bound && !isHx) {
    btn.dataset.bound = '1';
    btn.addEventListener('click', function () { setOpen(!hdr.classList.contains(isHx ? 'open' : 'nav-open')); });
  }
  hdr.addEventListener('click', function (e) { if (e.target.closest('.public-links a, .hp-nav a, .hx-nav a, .acct-menu a')) setOpen(false); });
  window.addEventListener('resize', function () { if (window.innerWidth > 1100) setOpen(false); });
  var acct = hdr.querySelector('[data-acct]'), ab = acct && acct.querySelector('.acct-btn');
  function closeAcct() { if (acct) { acct.classList.remove('open'); if (ab) ab.setAttribute('aria-expanded', 'false'); } }
  if (ab) {
    ab.addEventListener('click', function (e) { e.stopPropagation(); var o = acct.classList.toggle('open'); ab.setAttribute('aria-expanded', o ? 'true' : 'false'); });
    document.addEventListener('click', function (e) { if (acct && !acct.contains(e.target)) closeAcct(); });
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeAcct(); if (hdr.classList.contains(isHx ? 'open' : 'nav-open')) { setOpen(false); if (btn) btn.focus(); } } });
})();
