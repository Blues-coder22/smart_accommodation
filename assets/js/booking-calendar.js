(function () {
  document.querySelectorAll('[data-bc]').forEach(function (root) {
    var months = [].slice.call(root.querySelectorAll('.bc-month'));
    var per = window.matchMedia('(max-width:700px)').matches ? 1 : parseInt(root.getAttribute('data-per-view') || '2', 10);
    var start = 0, prev = root.querySelector('[data-bc-prev]'), next = root.querySelector('[data-bc-next]'), title = root.querySelector('[data-bc-title]');
    function show() {
      months.forEach(function (m, i) { m.classList.toggle('is-shown', i >= start && i < start + per); });
      var a = months[start], b = months[Math.min(start + per, months.length) - 1];
      title.textContent = a === b ? a.dataset.label : a.dataset.label + ' – ' + b.dataset.label;
      prev.disabled = start === 0; next.disabled = start + per >= months.length;
    }
    prev.addEventListener('click', function () { start = Math.max(0, start - per); show(); });
    next.addEventListener('click', function () { start = Math.min(months.length - per, start + per); show(); });
    show();
  });
})();
