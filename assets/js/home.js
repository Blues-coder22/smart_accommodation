/* Homepage: sliding hero images, sliding rows, scroll reveal, mobile menu. */
(function () {
  'use strict';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* mobile menu */
  var burger = document.getElementById('hxBurger'), header = document.getElementById('hxHeader');
  if (burger && header) burger.addEventListener('click', function () {
    var open = header.classList.toggle('open'); burger.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  /* hero slider: slides move sideways; the first slide is cloned at the end so it loops smoothly */
  var track = document.getElementById('hxTrack');
  if (track) {
    var slides = Array.prototype.slice.call(track.children), n = slides.length;
    var dotsBox = document.getElementById('hxDots'), capText = document.getElementById('hxCapText'), capLink = document.getElementById('hxCapLink'), caption = document.getElementById('hxCaption');
    var idx = 0, timer = null, busy = false;
    if (n > 1) track.appendChild(slides[0].cloneNode(true));
    var dots = slides.map(function (s, i) {
      var b = document.createElement('button'); b.type = 'button'; b.setAttribute('aria-label', 'Go to image ' + (i + 1));
      b.addEventListener('click', function () { go(i); }); dotsBox.appendChild(b); return b;
    });
    function mark() {
      var real = idx % n;
      dots.forEach(function (d, i) { d.classList.toggle('on', i === real); });
      Array.prototype.forEach.call(track.children, function (s, i) { s.classList.toggle('on', i === idx); });
      var s = slides[real];
      if (capText) capText.textContent = '' + s.getAttribute('data-label');
      if (capLink) capLink.setAttribute('href', s.getAttribute('data-url') || '#');
    }
    function move(animate) {
      track.style.transition = animate ? '' : 'none';
      track.style.transform = 'translateX(' + (-idx * 100) + '%)';
      if (!animate) { void track.offsetWidth; track.style.transition = ''; }
      mark();
    }
    function go(i) { if (busy && i === idx) return; idx = i; move(true); restart(); }
    function next() {
      if (n < 2) return;
      idx += 1; move(true);
      if (idx === n) {            // sitting on the clone of slide 1: jump back without animation
        busy = true;
        setTimeout(function () { idx = 0; move(false); busy = false; }, 1050);
      }
    }
    function prev() {
      if (n < 2) return;
      if (idx === 0) { idx = n; move(false); void track.offsetWidth; }
      idx -= 1; move(true);
    }
    function restart() { clearInterval(timer); if (!reduce && n > 1) timer = setInterval(next, 6500); }
    document.getElementById('hxNext').addEventListener('click', function () { if (!busy) { next(); restart(); } });
    document.getElementById('hxPrev').addEventListener('click', function () { if (!busy) { prev(); restart(); } });
    var hero = track.closest('.hx-hero');
    hero.addEventListener('mouseenter', function () { clearInterval(timer); });
    hero.addEventListener('mouseleave', restart);
    // swipe
    var x0 = null;
    hero.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    hero.addEventListener('touchend', function (e) {
      if (x0 === null) return; var dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) > 50 && !busy) { dx < 0 ? next() : prev(); restart(); }
    });
    document.addEventListener('visibilitychange', function () { document.hidden ? clearInterval(timer) : restart(); });
    mark(); restart();
  }

  /* sliding rows (stays / attractions) */
  document.querySelectorAll('[data-slide]').forEach(function (b) {
    b.addEventListener('click', function () {
      var row = document.querySelector(b.getAttribute('data-slide')); if (!row) return;
      var step = row.firstElementChild ? row.firstElementChild.getBoundingClientRect().width + 24 : 320;
      row.scrollBy({ left: step * parseInt(b.getAttribute('data-dir'), 10), behavior: 'smooth' });
    });
  });

  /* scroll reveal */
  var els = document.querySelectorAll('.hx-reveal');
  if ('IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }); }, { threshold: 0.12 });
    els.forEach(function (e) { io.observe(e); });
  } else els.forEach(function (e) { e.classList.add('in'); });
})();
