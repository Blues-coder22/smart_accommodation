/* Shared motion for every page: cards fade up as they appear, and big numbers count up. */
(function () {
  'use strict';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce || !('IntersectionObserver' in window)) return;
  document.documentElement.classList.add('pl-js');

  var SELECTOR = '.card,.stat-card,.form-card,.section-card,.side-card,.detail-card,.review-card,.summary-card,.acc-card,.att-card,.booking-card,.result-card,.listing-card,.empty-state,.results-header,.filters-panel,.filter-panel,.no-results,table.data-table,.stats-row>*,.stats-grid>*,.content-grid>*';
  var nodes = Array.prototype.slice.call(document.querySelectorAll(SELECTOR)).filter(function (n) {
    // skip anything already animated by its own page or nested inside another revealed block
    if (n.closest('.leaflet-container, .att-reveal, .hx-reveal')) return false;
    if (n.classList.contains('att-fun')) return false;
    var p = n.parentElement;
    while (p) { if (p.classList && p.classList.contains('pl-reveal')) return false; p = p.parentElement; }
    return true;
  });

  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      e.target.classList.add('pl-in'); io.unobserve(e.target);
      var nums = e.target.querySelectorAll('.stat-value,.stat-num');
      if (e.target.matches('.stat-value,.stat-num')) countUp(e.target);
      nums.forEach(countUp);
    });
  }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

  nodes.forEach(function (n, i) {
    n.classList.add('pl-reveal');
    var sib = n.parentElement ? Array.prototype.indexOf.call(n.parentElement.children, n) : 0;
    n.style.setProperty('--pl-i', Math.min(sib, 8));
    io.observe(n);
  });

  function countUp(el) {
    if (el.__counted) return; el.__counted = true;
    var txt = el.textContent.trim();
    if (!/^\d[\d,]*$/.test(txt)) return;          // only plain whole numbers
    var end = parseInt(txt.replace(/,/g, ''), 10); if (!end || end > 1e7) return;
    var t0 = null, dur = 900;
    function step(t) {
      if (t0 === null) t0 = t;
      var p = Math.min(1, (t - t0) / dur), v = Math.round(end * (1 - Math.pow(1 - p, 3)));
      el.textContent = v.toLocaleString('en-US');
      if (p < 1) requestAnimationFrame(step); else el.textContent = txt;
    }
    requestAnimationFrame(step);
  }

  // safety: never leave content hidden if something goes wrong
  setTimeout(function () { document.querySelectorAll('.pl-reveal:not(.pl-in)').forEach(function (n) {
    var r = n.getBoundingClientRect(); if (r.top < window.innerHeight) n.classList.add('pl-in'); }); }, 2500);
})();

/* ---- v7: mobile dashboard menu + scrollable tables ---- */
(function () {
  var t = document.querySelector('.side-toggle'), sb = document.getElementById('appSidebar');
  if (t && sb) {
    t.addEventListener('click', function () {
      var open = sb.classList.toggle('open');
      t.setAttribute('aria-expanded', open ? 'true' : 'false');
      t.textContent = open ? 'Close' : 'Menu';
    });
  }
  document.querySelectorAll('table').forEach(function (tb) {
    if (tb.parentElement && /table-wrap|scroll-x/.test(tb.parentElement.className)) return;
    var w = document.createElement('div');
    w.className = 'scroll-x';
    tb.parentNode.insertBefore(w, tb);
    w.appendChild(tb);
  });
})();

/* ---- v9.12: mobile menu on every page that uses the shared header (it had no click handler before) ---- */
(function () {
  var btn = document.querySelector('.public-nav .nav-toggle');
  var bar = btn && btn.closest('.public-nav');
  if (!btn || !bar || btn.dataset.bound) return;
  btn.dataset.bound = '1';
  function setOpen(open) {
    bar.classList.toggle('nav-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    btn.textContent = open ? '\u2715' : '\u2630';
  }
  btn.addEventListener('click', function () { setOpen(!bar.classList.contains('nav-open')); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && bar.classList.contains('nav-open')) { setOpen(false); btn.focus(); }
  });
  // choosing a link or growing the window closes the menu again
  bar.addEventListener('click', function (e) { if (e.target.closest('.public-links a')) setOpen(false); });
  window.addEventListener('resize', function () { if (window.innerWidth > 760) setOpen(false); });
})();

/* ---- v9.12: "Skip to main content" for keyboard users ---- */
(function () {
  var main = document.querySelector('main'); if (!main || document.querySelector('.skip-link')) return;
  if (!main.id) main.id = 'main-content';
  main.setAttribute('tabindex', '-1');
  var a = document.createElement('a'); a.className = 'skip-link'; a.href = '#' + main.id; a.textContent = 'Skip to main content';
  document.body.insertBefore(a, document.body.firstChild);
})();
