/* StayExplore Kenya - shared UI behaviour (v9.33). Small and dependency free.
   1. Tables: gives every cell its column heading as data-label, so CSS can show the table as stacked cards on phones.
   2. Filter panels (.filters-panel): adds a "Filters" button on phones that opens and closes the panel.
   Works with both themes (no colours here) and does nothing without JavaScript: tables then simply scroll sideways. */
(function () {
  'use strict';
  function labelTables(root) {
    (root || document).querySelectorAll('table:not(.no-stack)').forEach(function (t) {
      if (t.classList.contains('rt')) return;
      var heads = [];
      var hr = t.querySelector('thead tr') || t.querySelector('tr');
      if (!hr) return;
      hr.querySelectorAll('th,td').forEach(function (th) {
        var span = parseInt(th.getAttribute('colspan') || '1', 10) || 1;
        for (var i = 0; i < span; i++) heads.push((th.textContent || '').replace(/\s+/g, ' ').trim());
      });
      if (!heads.length) return;
      t.querySelectorAll('tbody tr').forEach(function (tr) {
        var i = 0;
        tr.querySelectorAll('td,th').forEach(function (td) {
          if (!td.hasAttribute('data-label')) td.setAttribute('data-label', heads[i] || '');
          i += parseInt(td.getAttribute('colspan') || '1', 10) || 1;
        });
      });
      t.classList.add('rt');
    });
  }
  function filterPanels() {
    document.querySelectorAll('.filters-panel').forEach(function (panel) {
      if (panel.dataset.uiReady) return;
      panel.dataset.uiReady = '1';
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'filters-toggle'; btn.setAttribute('aria-expanded', 'false');
      var active = 0, skip = { type: 1, sort: 1, view: 1, page: 1 };
      panel.querySelectorAll('input:not([type=hidden]):not([type=submit]),select').forEach(function (el) {
        if (skip[el.name]) return;
        if (el.type === 'checkbox' || el.type === 'radio') { if (el.checked) active++; }
        else if (el.tagName === 'SELECT') { if (el.selectedIndex > 0 && el.value !== '') active++; }
        else if (el.value !== '') active++;
      });
      btn.innerHTML = '<span>Filters</span>' + (active ? '<span class="count">' + active + '</span>' : '<span aria-hidden="true">+</span>');
      panel.parentNode.insertBefore(btn, panel);
      var mq = window.matchMedia('(max-width: 768px)');
      function apply() { panel.classList.toggle('is-collapsed', mq.matches && btn.getAttribute('aria-expanded') !== 'true'); }
      btn.addEventListener('click', function () { btn.setAttribute('aria-expanded', btn.getAttribute('aria-expanded') === 'true' ? 'false' : 'true'); apply(); });
      if (mq.addEventListener) mq.addEventListener('change', apply); else if (mq.addListener) mq.addListener(apply);
      apply();
    });
  }
  function init() { labelTables(); filterPanels(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  window.seUiRefresh = init;   // call after inserting tables dynamically
})();
