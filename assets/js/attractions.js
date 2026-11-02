/* Light animations for the attraction pages: scroll reveal, count-up numbers, "Did you know?" spotlight, share button. */
(function () {
  'use strict';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* scroll reveal */
  var items = document.querySelectorAll('.att-reveal');
  if ('IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: 0.15 });
    items.forEach(function (n) { io.observe(n); });
  } else {
    items.forEach(function (n) { n.classList.add('in'); });
  }

  /* count-up numbers: <strong data-count="12" data-suffix=" km" data-decimals="1"> */
  function countUp(node) {
    var end = parseFloat(node.getAttribute('data-count')); if (isNaN(end)) return;
    var dec = parseInt(node.getAttribute('data-decimals') || '0', 10), suf = node.getAttribute('data-suffix') || '', pre = node.getAttribute('data-prefix') || '';
    if (reduce) { node.textContent = pre + end.toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec }) + suf; return; }
    var t0 = null, dur = 1200;
    function step(t) {
      if (t0 === null) t0 = t;
      var p = Math.min(1, (t - t0) / dur), eased = 1 - Math.pow(1 - p, 3);
      node.textContent = pre + (end * eased).toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec }) + suf;
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  var counters = document.querySelectorAll('[data-count]');
  if ('IntersectionObserver' in window) {
    var co = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { countUp(e.target); co.unobserve(e.target); } });
    }, { threshold: 0.4 });
    counters.forEach(function (n) { co.observe(n); });
  } else counters.forEach(countUp);

  /* did-you-know spotlight */
  var box = document.querySelector('[data-dyk]');
  if (box) {
    var slides = box.querySelectorAll('.att-dyk-slide'), dotsWrap = box.querySelector('.att-dots'), bar = box.querySelector('.att-dyk-bar');
    var idx = 0, timer = null, paused = false;
    slides.forEach(function (s, i) {
      var d = document.createElement('i'); d.addEventListener('click', function () { go(i, true); }); dotsWrap.appendChild(d);
    });
    function go(i, user) {
      idx = (i + slides.length) % slides.length;
      slides.forEach(function (s, n) { s.classList.toggle('on', n === idx); });
      Array.prototype.forEach.call(dotsWrap.children, function (d, n) { d.classList.toggle('on', n === idx); });
      if (bar) { bar.classList.remove('run'); void bar.offsetWidth; if (!reduce && !paused) bar.classList.add('run'); }
      clearTimeout(timer);
      if (!reduce && !paused && slides.length > 1) timer = setTimeout(function () { go(idx + 1); }, 7000);
      if (user) paused = false;
    }
    box.querySelector('[data-next]').addEventListener('click', function () { go(idx + 1, true); });
    box.querySelector('[data-prev]').addEventListener('click', function () { go(idx - 1, true); });
    box.addEventListener('mouseenter', function () { paused = true; clearTimeout(timer); if (bar) bar.classList.remove('run'); });
    box.addEventListener('mouseleave', function () { paused = false; go(idx); });
    go(0);
  }

  /* share button */
  document.querySelectorAll('[data-share]').forEach(function (b) {
    b.addEventListener('click', function () {
      var data = { title: document.title, url: location.href };
      if (navigator.share) { navigator.share(data).catch(function () {}); return; }
      if (navigator.clipboard) navigator.clipboard.writeText(location.href).then(function () {
        var o = b.textContent; b.textContent = '✓ Link copied'; setTimeout(function () { b.textContent = o; }, 1800);
      });
    });
  });

  /* gentle hero parallax */
  var heroImg = document.querySelector('.att-hero-img');
  if (heroImg && !reduce) {
    window.addEventListener('scroll', function () {
      var y = Math.min(window.scrollY, 600); heroImg.style.marginTop = (y * 0.12) + 'px';
    }, { passive: true });
  }
})();
