/* Photo slideshows: arrows, dots, swipe, thumbnails, optional autoplay. Works inside clickable cards. */
(function () {
  function init(root) {
    var slides = [].slice.call(root.querySelectorAll('.se-slide'));
    if (slides.length < 2) return;
    var dots = [].slice.call(root.querySelectorAll('.se-dot'));
    var thumbs = root.parentNode ? [].slice.call(root.parentNode.querySelectorAll('[data-thumbs] .se-thumb')) : [];
    var count = root.querySelector('.se-count'), i = 0, timer = null, startX = null;
    function show(n) {
      i = (n + slides.length) % slides.length;
      slides.forEach(function (s, k) { s.classList.toggle('is-active', k === i); });
      dots.forEach(function (d, k) { d.classList.toggle('is-active', k === i); });
      thumbs.forEach(function (t, k) { t.classList.toggle('is-active', k === i); });
      if (count) count.textContent = (i + 1) + ' / ' + slides.length;
    }
    function stop(e) { if (e) { e.preventDefault(); e.stopPropagation(); } }
    var p = root.querySelector('.se-prev'), n = root.querySelector('.se-next');
    if (p) p.addEventListener('click', function (e) { stop(e); show(i - 1); restart(); });
    if (n) n.addEventListener('click', function (e) { stop(e); show(i + 1); restart(); });
    dots.forEach(function (d, k) { d.addEventListener('click', function (e) { stop(e); show(k); restart(); }); });
    thumbs.forEach(function (t, k) { t.addEventListener('click', function () { show(k); restart(); }); });
    root.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
    root.addEventListener('touchend', function (e) {
      if (startX === null) return;
      var dx = e.changedTouches[0].clientX - startX; startX = null;
      if (Math.abs(dx) > 40) { show(i + (dx < 0 ? 1 : -1)); restart(); }
    });
    root.setAttribute('tabindex', '0');
    root.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { show(i - 1); restart(); } else if (e.key === 'ArrowRight') { show(i + 1); restart(); }
    });
    var ms = parseInt(root.getAttribute('data-autoplay') || '0', 10);
    function restart() { if (!ms) return; clearInterval(timer); timer = setInterval(function () { show(i + 1); }, ms); }
    if (ms) {
      restart();
      root.addEventListener('mouseenter', function () { clearInterval(timer); });
      root.addEventListener('mouseleave', restart);
    }
  }
  function boot() { [].forEach.call(document.querySelectorAll('[data-slider]'), init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
