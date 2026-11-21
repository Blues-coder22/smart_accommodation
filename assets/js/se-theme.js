/* StayExplore Kenya - dark mode (v9.27)
 * Load this in <head> WITHOUT defer so the theme is applied before the page paints (no white flash).
 * Choice is saved in localStorage ("se_theme": light | dark). With no saved choice the phone/computer setting is followed.
 */
(function () {
  'use strict';
  var KEY = 'se_theme', root = document.documentElement;

  function saved() { try { var v = localStorage.getItem(KEY); return v === 'dark' || v === 'light' ? v : ''; } catch (e) { return ''; } }
  function systemDark() { return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches); }
  function current() { return saved() || (systemDark() ? 'dark' : 'light'); }
  function apply(t) {
    root.setAttribute('data-theme', t);
    root.style.colorScheme = t;
    var m = document.querySelector('meta[name="theme-color"]');
    if (m) m.setAttribute('content', t === 'dark' ? '#0f1512' : '#0d3b2e');
    var btns = document.querySelectorAll('[data-se-theme]');
    for (var i = 0; i < btns.length; i++) paint(btns[i], t);
  }
  function paint(b, t) {
    var dark = t === 'dark', sw = (document.documentElement.lang || '').indexOf('sw') === 0;
    b.setAttribute('aria-pressed', dark ? 'true' : 'false');
    var label = dark ? (sw ? 'Washa hali ya mwanga' : 'Switch to light mode') : (sw ? 'Washa hali ya giza' : 'Switch to dark mode');
    b.setAttribute('aria-label', label); b.setAttribute('title', label);
    b.innerHTML = dark
      ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>'
      : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
  }
  function toggle() {
    var next = current() === 'dark' ? 'light' : 'dark';
    try { localStorage.setItem(KEY, next); } catch (e) {}
    apply(next);
  }

  apply(current());   // before first paint

  // follow the device setting live, but only while the person has not chosen for themselves
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    var onSys = function () { if (!saved()) apply(current()); };
    if (mq.addEventListener) mq.addEventListener('change', onSys); else if (mq.addListener) mq.addListener(onSys);
  }
  // keep several open tabs in step
  window.addEventListener('storage', function (e) { if (e.key === KEY) apply(current()); });

  window.seTheme = { get: current, set: function (t) { try { localStorage.setItem(KEY, t); } catch (e) {} apply(t); }, toggle: toggle };

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-se-theme]');
    if (b) { e.preventDefault(); toggle(); }
  });

  document.addEventListener('DOMContentLoaded', function () {
    // Pages with their own header (home page, static pages, host/admin shell) may have no toggle: add a small floating one.
    if (!document.querySelector('[data-se-theme]')) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'se-theme notranslate'; b.setAttribute('data-se-theme', '');
      b.setAttribute('translate', 'no');
      var host = document.querySelector('.public-actions,.hx-actions,.hp-actions,.app-top');
      if (host) host.insertBefore(b, host.firstChild);                 // sit in the page's own header
      else { b.className += ' se-theme-float'; document.body.appendChild(b); }   // no header: small floating button
    }
    apply(current());
  });
})();
