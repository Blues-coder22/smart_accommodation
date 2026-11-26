/* StayExplore Kenya v9.34: photo gallery viewer. Click a photo (or "View all photos") to see it whole, full screen.
 * Arrows / swipe / keyboard to move, click the photo to zoom, "All photos" for a grid. Works on any [data-slider][data-expand]. */
(function () {
  var lb = null, items = [], cur = 0, zoomed = false, opener = null, sx = null;
  function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt) e.textContent = txt; return e; }
  function build() {
    lb = el('div', 'lb'); lb.setAttribute('role', 'dialog'); lb.setAttribute('aria-modal', 'true'); lb.setAttribute('aria-label', 'Photo viewer'); lb.hidden = true;
    var bar = el('div', 'lb-bar'); lb.count = el('span', 'lb-count');
    lb.gridBtn = el('button', 'lb-btn', 'All photos'); lb.gridBtn.type = 'button';
    lb.zoomBtn = el('button', 'lb-btn', 'Zoom'); lb.zoomBtn.type = 'button';
    var close = el('button', 'lb-btn lb-close', '\u2715'); close.type = 'button'; close.setAttribute('aria-label', 'Close');
    bar.appendChild(lb.count); bar.appendChild(lb.gridBtn); bar.appendChild(lb.zoomBtn); bar.appendChild(close);
    lb.stage = el('div', 'lb-stage'); lb.img = el('img', 'lb-img'); lb.stage.appendChild(lb.img);
    lb.prev = el('button', 'lb-nav lb-prev', '\u2039'); lb.prev.type = 'button'; lb.prev.setAttribute('aria-label', 'Previous photo');
    lb.next = el('button', 'lb-nav lb-next', '\u203A'); lb.next.type = 'button'; lb.next.setAttribute('aria-label', 'Next photo');
    lb.cap = el('div', 'lb-cap'); lb.strip = el('div', 'lb-strip'); lb.grid = el('div', 'lb-grid'); lb.grid.hidden = true;
    lb.appendChild(bar); lb.appendChild(lb.stage); lb.appendChild(lb.prev); lb.appendChild(lb.next); lb.appendChild(lb.cap); lb.appendChild(lb.strip); lb.appendChild(lb.grid);
    document.body.appendChild(lb);
    close.addEventListener('click', hide);
    lb.prev.addEventListener('click', function () { go(cur - 1); }); lb.next.addEventListener('click', function () { go(cur + 1); });
    lb.zoomBtn.addEventListener('click', toggleZoom); lb.img.addEventListener('click', toggleZoom);
    lb.gridBtn.addEventListener('click', function () { showGrid(lb.grid.hidden); });
    lb.addEventListener('click', function (e) { if (e.target === lb || e.target === lb.stage) hide(); });
    lb.stage.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
    lb.stage.addEventListener('touchend', function (e) { if (sx === null || zoomed) return; var dx = e.changedTouches[0].clientX - sx; sx = null; if (Math.abs(dx) > 50) go(cur + (dx < 0 ? 1 : -1)); });
    document.addEventListener('keydown', function (e) {
      if (lb.hidden) return;
      if (e.key === 'Escape') hide(); else if (e.key === 'ArrowLeft') go(cur - 1); else if (e.key === 'ArrowRight') go(cur + 1);
      else if (e.key === 'Tab') { var f = [].slice.call(lb.querySelectorAll('button')).filter(function (b) { return !b.hidden && b.offsetParent !== null; }); if (!f.length) return; var i = f.indexOf(document.activeElement); if (e.shiftKey && i <= 0) { e.preventDefault(); f[f.length - 1].focus(); } else if (!e.shiftKey && i === f.length - 1) { e.preventDefault(); f[0].focus(); } }
    });
  }
  function toggleZoom() { zoomed = !zoomed; lb.stage.classList.toggle('zoomed', zoomed); lb.zoomBtn.textContent = zoomed ? 'Fit to screen' : 'Zoom'; lb.stage.scrollTop = 0; lb.stage.scrollLeft = 0; }
  function go(n) {
    cur = (n + items.length) % items.length; zoomed = false; lb.stage.classList.remove('zoomed'); lb.zoomBtn.textContent = 'Zoom';
    lb.img.src = items[cur].src; lb.img.alt = items[cur].alt;
    lb.count.textContent = (cur + 1) + ' / ' + items.length; lb.cap.textContent = items[cur].alt.replace(/\s+\u2014 photo \d+$/, '');
    [].forEach.call(lb.strip.children, function (b, k) { b.classList.toggle('on', k === cur); if (k === cur && b.scrollIntoView) b.scrollIntoView({ block: 'nearest', inline: 'center' }); });
    var many = items.length > 1; lb.prev.hidden = lb.next.hidden = lb.strip.hidden = lb.gridBtn.hidden = !many;
  }
  function showGrid(on) {
    lb.grid.hidden = !on; lb.stage.hidden = !on ? false : true; lb.strip.hidden = on; lb.cap.hidden = on; lb.prev.hidden = lb.next.hidden = on || items.length < 2; lb.zoomBtn.hidden = on;
    lb.gridBtn.textContent = on ? 'Back to photo' : 'All photos';
  }
  function fill() {
    lb.strip.textContent = ''; lb.grid.textContent = '';
    items.forEach(function (it, k) {
      var t = el('button', 'lb-th'); t.type = 'button'; t.setAttribute('aria-label', 'Photo ' + (k + 1)); var im = el('img'); im.src = it.src; im.alt = ''; im.loading = 'lazy'; t.appendChild(im);
      t.addEventListener('click', function () { go(k); }); lb.strip.appendChild(t);
      var g = el('button', 'lb-gi'); g.type = 'button'; g.setAttribute('aria-label', 'Open photo ' + (k + 1)); var gi = el('img'); gi.src = it.src; gi.alt = it.alt; gi.loading = 'lazy'; g.appendChild(gi);
      g.addEventListener('click', function () { showGrid(false); go(k); }); lb.grid.appendChild(g);
    });
  }
  function show(list, start, grid) {
    if (!lb) build(); items = list; opener = document.activeElement; fill();
    lb.hidden = false; document.documentElement.classList.add('lb-open'); showGrid(false); go(start || 0); if (grid && items.length > 1) showGrid(true);
    lb.querySelector('.lb-close').focus();
  }
  function hide() { if (!lb || lb.hidden) return; lb.hidden = true; document.documentElement.classList.remove('lb-open'); lb.img.removeAttribute('src'); if (opener && opener.focus) opener.focus(); }
  function collect(root) { return [].map.call(root.querySelectorAll('.se-slide'), function (i) { return { src: i.currentSrc || i.src, alt: i.alt || '' }; }); }
  function setup(root) {
    if (root.dataset.lbBound) return; root.dataset.lbBound = '1';
    var list = collect(root); if (!list.length) return;
    var b = el('button', 'se-expand', '\u2922'); b.type = 'button'; b.setAttribute('aria-label', 'View photos full size'); root.appendChild(b);
    function active() { var s = root.querySelectorAll('.se-slide'), k = 0; [].forEach.call(s, function (x, i) { if (x.classList.contains('is-active')) k = i; }); return k; }
    b.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); show(collect(root), active()); });
    root.addEventListener('click', function (e) { if (e.target.closest('.se-nav,.se-dot,.se-expand')) return; if (e.target.classList.contains('se-slide')) show(collect(root), active()); });
    root.querySelectorAll('.se-slide').forEach(function (s) { s.style.cursor = 'zoom-in'; });
    if (list.length > 1) {   // a clear "all photos" button under the picture
      var all = el('button', 'se-allphotos', 'View all ' + list.length + ' photos'); all.type = 'button';
      all.addEventListener('click', function () { show(collect(root), active(), true); });
      var host = root.nextElementSibling && root.nextElementSibling.hasAttribute('data-thumbs') ? root.nextElementSibling : root;
      host.insertAdjacentElement('afterend', all);
    }
    var fit = el('button', 'se-fitbtn', 'Fill'); fit.type = 'button'; fit.setAttribute('aria-pressed', 'false'); fit.setAttribute('aria-label', 'Switch between whole photo and filled frame');
    fit.addEventListener('click', function (e) { e.stopPropagation(); var f = root.classList.toggle('se-cover'); fit.textContent = f ? 'Whole photo' : 'Fill'; fit.setAttribute('aria-pressed', f ? 'true' : 'false'); });
    root.appendChild(fit);
  }
  function setupGallery(g) {
    if (g.dataset.lbBound) return; g.dataset.lbBound = '1';
    var btns = [].slice.call(g.querySelectorAll('[data-full]'));
    var list = btns.map(function (b) { return { src: b.getAttribute('data-full'), alt: b.getAttribute('data-alt') || '' }; });
    btns.forEach(function (b, k) { b.addEventListener('click', function () { show(list, k); }); });
  }
  function boot() { [].forEach.call(document.querySelectorAll('[data-slider][data-expand]'), setup); [].forEach.call(document.querySelectorAll('[data-gallery]'), setupGallery); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
  window.seLightbox = { open: show };
})();
