/* On-demand Leaflet loader (v9.33). Leaflet (served from assets/vendor/leaflet, no CDN) is downloaded only when a map is needed.
   A page marks its map script as <script type="text/se-leaflet" data-target="mapElementId" [data-trigger="#button"] [data-load="now"]>.
   - data-trigger : load when that button is clicked (map hidden until then); the click is then replayed.
   - data-target  : load when that element is within 400px of the viewport.
   - data-load="now": load straight away, without blocking the page.
   The marked script then runs in the global scope exactly as it did before. Script errors are collected in window.__seErrors for testing. */
(function () {
  'use strict';
  window.__seErrors = window.__seErrors || [];
  window.addEventListener('error', function (e) { window.__seErrors.push((e.message || 'error') + (e.filename ? ' @' + e.filename.split('/').pop() + ':' + e.lineno : '')); });
  var cur = document.currentScript, base = cur && cur.src ? cur.src.replace(/js\/leaflet-loader\.js.*$/, '') : '/assets/';
  var loading = null;
  function load() {
    if (window.L) return Promise.resolve();
    if (loading) return loading;
    loading = new Promise(function (resolve, reject) {
      var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = base + 'vendor/leaflet/leaflet.css'; document.head.appendChild(l);
      var s = document.createElement('script'); s.src = base + 'vendor/leaflet/leaflet.js';
      s.onload = resolve; s.onerror = function () { loading = null; reject(new Error('Leaflet failed to load')); };
      document.head.appendChild(s);
    });
    return loading;
  }
  window.seLeaflet = load;
  function run(el) { var s = document.createElement('script'); s.text = el.textContent; el.parentNode.replaceChild(s, el); }
  function boot() {
    document.querySelectorAll('script[type="text/se-leaflet"]').forEach(function (el) {
      var done = false, trig = el.getAttribute('data-trigger'), target = document.getElementById(el.getAttribute('data-target') || '');
      function go(after) { if (done) return; done = true; load().then(function () { run(el); if (after) after(); }, function () { window.__seErrors.push('Leaflet failed to load'); }); }
      if (el.getAttribute('data-load') === 'now') return go();
      if (trig) {
        var b = document.querySelector(trig);
        if (b) { b.addEventListener('click', function h(e) { if (done) return; e.preventDefault(); e.stopImmediatePropagation(); b.removeEventListener('click', h, true); go(function () { b.click(); }); }, true); return; }
      }
      if (target && 'IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (en) { if (en.some(function (x) { return x.isIntersecting; })) { io.disconnect(); go(); } }, { rootMargin: '400px' });
        io.observe(target);
      } else go();
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
