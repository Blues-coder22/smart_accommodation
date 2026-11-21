/* StayExplore Kenya - language switch (v9.27)
 *
 * English and Kiswahili work OFFLINE: Kiswahili is a built-in word list (assets/js/se-i18n-sw.js) applied in the browser,
 * so it needs no internet, no Google and no database. Every other language still uses Google's website translator,
 * which needs an internet connection (see includes/translate.php).
 *
 * Load this in <head> WITHOUT defer. When Kiswahili is chosen it loads the word list straight away (same server, works offline).
 * Anything not in the word list stays in English. Open any page with ?i18n_debug=1 to list what is still untranslated.
 */
(function () {
  'use strict';
  var COOKIE = 'se_lang', OFFLINE = { en: 1, sw: 1 };
  var root = document.documentElement;

  function getCookie(n) { var m = document.cookie.match(new RegExp('(?:^|; )' + n + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : ''; }
  function setCookie(n, v, days) {
    var e = days ? '; max-age=' + days * 86400 : '; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    document.cookie = n + '=' + encodeURIComponent(v) + e + '; path=/; SameSite=Lax';
  }
  function clearGoogle() {
    var past = 'expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    document.cookie = 'googtrans=; ' + past;
    document.cookie = 'googtrans=; ' + past + '; domain=' + location.hostname;
  }
  function currentLang() {
    var c = getCookie(COOKIE);
    if (c) return c;
    var g = getCookie('googtrans').match(/^\/en\/([A-Za-z-]+)$/);
    return g ? g[1] : 'en';
  }
  var LANG = currentLang();

  /* ---------- choosing a language ---------- */
  function setLang(code) {
    clearGoogle();
    setCookie(COOKIE, code, 365);
    if (!OFFLINE[code]) {                       // needs Google (online)
      document.cookie = 'googtrans=/en/' + code + '; path=/';
      document.cookie = 'googtrans=/en/' + code + '; path=/; domain=' + location.hostname;
    }
    location.reload();
  }
  window.seSetLang = setLang;
  document.addEventListener('change', function (e) {
    var s = e.target;
    if (s && s.matches && s.matches('select[data-se-lang]')) setLang(s.value);
  });

  /* ---------- the Kiswahili engine (only when Kiswahili is chosen) ---------- */
  if (LANG === 'sw') {
    root.setAttribute('lang', 'sw');
    root.classList.add('se-sw-pending');        // hide the page for a split second so English never flashes
    var st = document.createElement('style');
    st.textContent = 'html.se-sw-pending body{opacity:0}html body{transition:opacity .12s}';
    (document.head || root).appendChild(st);
    setTimeout(function () { root.classList.remove('se-sw-pending'); }, 1500);   // never leave a page hidden

    var me = document.currentScript, base = me && me.src ? me.src.replace(/se-i18n\.js(\?.*)?$/, '') : 'assets/js/';
    document.write('<script src="' + base + 'se-i18n-sw.js"><\/script>');
    document.write('<script>window.__seI18nStart&&window.__seI18nStart();<\/script>');
  }

  var started = false;
  window.__seI18nStart = function () {
    if (started || LANG !== 'sw') return; started = true;
    var DICT = window.SE_SW || {}, PATTERNS = window.SE_SW_PATTERNS || [];
    var MONTHS = { January: 'Januari', February: 'Februari', March: 'Machi', April: 'Aprili', May: 'Mei', June: 'Juni', July: 'Julai', August: 'Agosti', September: 'Septemba', October: 'Oktoba', November: 'Novemba', December: 'Desemba',
                   Jan: 'Jan', Feb: 'Feb', Mar: 'Mac', Apr: 'Apr', Jun: 'Jun', Jul: 'Jul', Aug: 'Ago', Sep: 'Sep', Oct: 'Okt', Nov: 'Nov', Dec: 'Des' };
    var DAYS = { Monday: 'Jumatatu', Tuesday: 'Jumanne', Wednesday: 'Jumatano', Thursday: 'Alhamisi', Friday: 'Ijumaa', Saturday: 'Jumamosi', Sunday: 'Jumapili',
                 Mon: 'Jtatu', Tue: 'Jnne', Wed: 'Jtano', Thu: 'Alh', Fri: 'Ijm', Sat: 'Jmosi', Sun: 'Jpili' };
    var monthRe = /\b(\d{1,2}\s+)?(January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\b(\s+\d{4})?/g;
    var dayRe = /\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b|\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun)(?=[,\s]+\d)/g;
    var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, CODE: 1, PRE: 1, NOSCRIPT: 1, SVG: 1, IFRAME: 1 };
    var ATTRS = ['placeholder', 'title', 'aria-label', 'alt'];
    var missing = {}, done = new WeakMap();

    function norm(s) { return s.replace(/[\u2018\u2019]/g, "'").replace(/[\u201C\u201D]/g, '"').replace(/\s+/g, ' ').trim(); }
    function lookup(k) { return Object.prototype.hasOwnProperty.call(DICT, k) ? DICT[k] : null; }

    function dates(s) {
      if (!/[A-Za-z]/.test(s)) return s;
      s = s.replace(monthRe, function (all, d, mon, y) {
        if (!d && !y && !/^[A-Z][a-z]{2,}$/.test(mon)) return all;
        if (!d && !y && mon === 'May' ) return all;            // "May" on its own is probably the English word
        return (d || '') + (MONTHS[mon] || mon) + (y || '');
      });
      return s.replace(dayRe, function (all, full, short) { return DAYS[full || short] || all; });
    }
    function tr(raw) {
      var k = norm(raw); if (!k) return null;
      var v = lookup(k);
      if (v === null && k.charAt(k.length - 1) === ':') { var w = lookup(k.slice(0, -1)); if (w !== null) v = w + ':'; }
      if (v === null) {
        for (var i = 0; i < PATTERNS.length; i++) {
          var m = k.match(PATTERNS[i][0]);
          if (m) {
            var args = m.slice(1).map(function (a) { var t = a === undefined ? '' : lookup(a); return t === null ? a : t; });
            v = PATTERNS[i][1].replace(/\{(\d)\}/g, function (_, n) { return args[+n - 1] !== undefined ? args[+n - 1] : ''; });
            break;
          }
        }
      }
      if (v === null) { var d = dates(k); if (d !== k) v = d; }
      return v;
    }
    function textNode(n) {
      var raw = n.nodeValue; if (!raw || !/[A-Za-z]/.test(raw)) return;
      if (done.get(n) === raw) return;
      var v = tr(raw);
      if (v === null) { var k = norm(raw); if (k.length > 2 && !/^[\W\d_]+$/.test(k)) missing[k] = 1; return; }
      var lead = raw.match(/^\s*/)[0], trail = raw.match(/\s*$/)[0], out = lead + v + trail;
      n.nodeValue = out; done.set(n, out);
    }
    function skipEl(el) { return !el || SKIP[el.nodeName.toUpperCase()] || (el.closest && el.closest('[translate="no"],.notranslate,[data-no-i18n]')); }
    function attrs(el) {
      if (el.nodeType !== 1 || skipEl(el)) return;
      for (var i = 0; i < ATTRS.length; i++) {
        var a = el.getAttribute(ATTRS[i]); if (!a) continue;
        var v = tr(a); if (v !== null) el.setAttribute(ATTRS[i], v); else { var k = norm(a); if (k.length > 2 && /[A-Za-z]{2}/.test(k)) missing[k] = 1; }
      }
      if (el.nodeName === 'INPUT' && /^(submit|button|reset)$/i.test(el.type) && el.value) { var t = tr(el.value); if (t !== null) el.value = t; }
    }
    function walk(node) {
      if (node.nodeType === 3) { if (!skipEl(node.parentNode)) textNode(node); return; }
      if (node.nodeType !== 1 || skipEl(node)) return;
      attrs(node);
      var w = document.createTreeWalker(node, 1 | 4, { acceptNode: function (n) {
        if (n.nodeType === 1) return skipEl(n) ? 2 : 1;      // 2 = skip the whole subtree
        return 1;
      } });
      var n; while ((n = w.nextNode())) { if (n.nodeType === 3) textNode(n); else attrs(n); }
    }
    function title() {
      var parts = document.title.split(' \u2014 '), out = parts.map(function (p) { var v = tr(p); return v === null ? p : v; }).join(' \u2014 ');
      if (out !== document.title) document.title = out;
    }
    function run() { walk(document.body); title(); }

    var queued = [], raf = 0;
    function flush() { raf = 0; var q = queued; queued = []; for (var i = 0; i < q.length; i++) if (document.contains(q[i]) || q[i].nodeType === 3) walk(q[i]); }
    function start() {
      run();
      root.classList.remove('se-sw-pending');
      new MutationObserver(function (list) {
        for (var i = 0; i < list.length; i++) {
          var m = list[i];
          if (m.type === 'characterData') queued.push(m.target);
          else if (m.type === 'attributes') queued.push(m.target);
          else for (var j = 0; j < m.addedNodes.length; j++) queued.push(m.addedNodes[j]);
        }
        if (!raf) raf = requestAnimationFrame(flush);
      }).observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS });
      if (/[?&]i18n_debug=1/.test(location.search)) setTimeout(function () {
        var l = Object.keys(missing).sort(); console.info('[se-i18n] ' + l.length + ' strings not in the Kiswahili list on this page:'); console.log(JSON.stringify(l, null, 1));
      }, 1500);
    }
    window.seI18n = { missing: function () { return Object.keys(missing).sort(); }, translate: tr };
    if (document.body) start(); else document.addEventListener('DOMContentLoaded', start);
  };

  /* ---------- language selector on pages that have none (home page, static pages, host/admin shell) ---------- */
  var NAMES = { en: 'English', sw: 'Kiswahili', fr: 'Fran\u00e7ais', zu: 'isiZulu', it: 'Italiano', es: 'Espa\u00f1ol', de: 'Deutsch', pt: 'Portugu\u00eas', ar: '\u0627\u0644\u0639\u0631\u0628\u064a\u0629', hi: '\u0939\u093f\u0928\u094d\u0926\u0940', 'zh-CN': '\u4e2d\u6587', am: '\u12a0\u121b\u122d\u129b', so: 'Soomaali', yo: 'Yor\u00f9b\u00e1', rw: 'Kinyarwanda' };
  document.addEventListener('DOMContentLoaded', function () {
    if (document.querySelector('select[data-se-lang]')) return;
    var old = document.querySelector('.se-lang select');
    if (old) { old.setAttribute('data-se-lang', ''); old.removeAttribute('onchange'); return; }
    var lab = document.createElement('label'); lab.className = 'se-lang notranslate'; lab.setAttribute('translate', 'no');
    var sel = document.createElement('select'); sel.setAttribute('data-se-lang', ''); sel.setAttribute('aria-label', 'Language / Lugha');
    var g1 = document.createElement('optgroup'); g1.label = 'Works offline';
    var g2 = document.createElement('optgroup'); g2.label = 'Needs internet';
    Object.keys(NAMES).forEach(function (c) { var o = document.createElement('option'); o.value = c; o.textContent = NAMES[c]; if (c === LANG) o.selected = true; (OFFLINE[c] ? g1 : g2).appendChild(o); });
    sel.appendChild(g1); sel.appendChild(g2); lab.appendChild(sel);
    var host = document.querySelector('.public-actions,.hx-actions,.hp-actions,.app-top');
    if (host) host.insertBefore(lab, host.firstChild);                    // sit in the page's own header, left of the dark mode button
    else { lab.className += ' se-lang-float'; document.body.appendChild(lab); }
  });

  /* ---------- Google languages need internet: say so instead of silently staying in English ---------- */
  if (LANG !== 'en' && !OFFLINE[LANG]) {
    window.addEventListener('load', function () {
      setTimeout(function () {
        if (window.google && window.google.translate) return;
        var d = document.createElement('div'); d.setAttribute('role', 'status'); d.className = 'se-lang-note notranslate'; d.setAttribute('translate', 'no');
        d.innerHTML = '<span>This language needs an internet connection, so the page is shown in English for now.</span> <button type="button" class="btn btn-sm" data-sw>Kiswahili</button> <button type="button" class="btn btn-sm btn-outline" data-en>English</button>';
        d.querySelector('[data-sw]').onclick = function () { setLang('sw'); };
        d.querySelector('[data-en]').onclick = function () { setLang('en'); };
        document.body.appendChild(d);
      }, 6000);
    });
  }
})();
