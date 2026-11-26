/* StayExplore Kenya v9.38 - homepage map preview.
 * A small interactive map of approved stays and attractions with an "Expand map" view, an OPTIONAL destination box and two buttons
 * (Explore Stays / Discover Attractions) that open the existing Discover pages. Nothing is searched here: the buttons simply pass the
 * destination, when there is one, to tourist/search.php, which already does the matching, filters, ranking and distances.
 * Reuses: leaflet-loader.js (on-demand Leaflet), map-pins.js (the same stay / attraction pins as the Discover map).
 * Data comes from window.SE_HOME_MAP (built in index.php from the existing tables; no database changes).
 */
(function () {
  'use strict';
  var C = window.SE_HOME_MAP; if (!C) return;
  var root = document.getElementById('kenyaMap'); if (!root) return;
  var $ = function (s) { return root.querySelector(s); };
  var input = $('#hmInput'), box = $('#hmSuggest'), radiusSel = $('#hmRadius'), panel = $('#hmNearby'), wrap = $('#hmMapWrap'), mapEl = $('#homeMap');
  var form = $('#hmForm'), expandBtn = $('#hmExpand'), closeBtn = $('#hmClose'), locBtn = $('#hmLocate');
  var stayLinks = root.querySelectorAll('[data-go="stays"]'), attrLinks = root.querySelectorAll('[data-go="attractions"]');

  var BOUNDS = [[-4.95, 33.85], [5.1, 42.0]];
  var PLACES = [['Nairobi', -1.2921, 36.8219], ['Westlands', -1.2676, 36.8108], ['Karen', -1.3226, 36.7085], ['Mombasa', -4.0435, 39.6682], ['Diani', -4.32, 39.58], ['Malindi', -3.2192, 40.1169], ['Watamu', -3.3547, 40.0247],
    ['Kilifi', -3.6305, 39.8499], ['Lamu', -2.2717, 40.902], ['Kisumu', -0.0917, 34.768], ['Nakuru', -0.3031, 36.08], ['Naivasha', -0.7167, 36.4333], ['Eldoret', 0.5143, 35.2698], ['Nanyuki', 0.0167, 37.0722],
    ['Nyeri', -0.4201, 36.9476], ['Meru', 0.0463, 37.6559], ['Thika', -1.0333, 37.0693], ['Narok', -1.0783, 35.86], ['Maasai Mara', -1.49, 35.14], ['Amboseli', -2.6527, 37.2606], ['Tsavo', -2.98, 38.46],
    ['Samburu', 0.6, 37.5333], ['Mount Kenya', -0.1521, 37.3084], ['Laikipia', 0.36, 36.78], ['Lake Turkana', 3.5, 36.0], ['Lake Victoria', -0.5, 33.3], ["Hell's Gate", -0.91, 36.32]];

  var stays = (C.stays || []).filter(ok), attractions = (C.attractions || []).filter(ok);
  function ok(o) { return o && isFinite(+o.lat) && isFinite(+o.lng) && o.lat !== null && o.lng !== null; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function rad(d) { return d * Math.PI / 180; }
  function km(a, b) { var R = 6371, dLa = rad(b.lat - a.lat), dLn = rad(b.lng - a.lng), h = Math.sin(dLa / 2) * Math.sin(dLa / 2) + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLn / 2) * Math.sin(dLn / 2); return 2 * R * Math.asin(Math.sqrt(h)); }
  function fmtKm(k) { return k < 1 ? 'under 1 km' : k < 10 ? k.toFixed(1) + ' km' : Math.round(k) + ' km'; }
  function R() { return parseInt(radiusSel.value, 10) || 100; }
  function withUrl(base, params) {
    var q = [], k; for (k in params) if (params[k] !== '' && params[k] != null) q.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
    return base + (q.length ? (base.indexOf('?') > -1 ? '&' : '?') + q.join('&') : '');
  }

  var map = null, layer = null, extra = null, kind = 'all', centre = null, sugg = [], active = -1;

  /* ---------- the two buttons: destination is optional ---------- */
  function updateLinks() {
    var text = input.value.trim(), c = centre, s = {}, a = {};
    var matches = c && c.type !== 'me' && c.label === text;
    if (matches && c.type === 'attraction') { s = { attraction_id: c.id, location: c.label, radius: R() }; a = { location: c.label }; }
    else if (matches && c.type === 'stay') { s = { location: c.label }; a = { stay_id: c.id, radius: R() }; }
    else if (matches && c.type === 'place') { s = { location: c.label, search_lat: c.lat.toFixed(5), search_lng: c.lng.toFixed(5), radius: R() }; a = { location: c.label }; }
    else if (c && c.type === 'me' && text === '') { s = { lat: c.lat.toFixed(5), lng: c.lng.toFixed(5), radius: R() }; }
    else if (text !== '') { s = { location: text }; a = { location: text }; }
    var su = withUrl(C.stayUrl, s), au = withUrl(C.attrUrl, a);
    Array.prototype.forEach.call(stayLinks, function (l) { l.setAttribute('href', su); });
    Array.prototype.forEach.call(attrLinks, function (l) { l.setAttribute('href', au); });
  }

  /* ---------- pins ---------- */
  function popup(o, type) {
    var sub = type === 'stay' ? (o.loc || '') + (o.price ? ' · KSh ' + Math.round(o.price).toLocaleString('en-KE') + '/night' : '') : (o.cat || '') + (o.loc ? ' · ' + o.loc : '');
    return '<strong>' + esc(o.name) + '</strong><br><span>' + esc(sub) + '</span><br><a href="' + esc(o.url) + '">' + (type === 'stay' ? 'View stay' : 'Explore attraction') + ' &rarr;</a>';
  }
  function paint() {
    if (!map) return;
    map.invalidateSize(); layer.clearLayers(); extra.clearLayers();
    if (kind !== 'stays') attractions.forEach(function (o) { L.marker([+o.lat, +o.lng], { icon: SEPins.attraction(), title: o.name, alt: o.name }).bindPopup(popup(o, 'attraction')).addTo(layer); });
    if (kind !== 'attractions') stays.forEach(function (o) { L.marker([+o.lat, +o.lng], { icon: SEPins.stay(), title: o.name, alt: o.name }).bindPopup(popup(o, 'stay')).addTo(layer); });
    if (centre) {
      if (centre.type === 'me' || centre.type === 'place') L.marker([centre.lat, centre.lng], { icon: SEPins.me(), title: centre.label }).bindPopup('<strong>' + esc(centre.label) + '</strong>').addTo(extra);
      var circle = L.circle([centre.lat, centre.lng], { radius: R() * 1000, color: '#0d3b2e', weight: 1, fillOpacity: .05, interactive: false }).addTo(extra);
      map.fitBounds(circle.getBounds(), { padding: [20, 20], maxZoom: 13 });
    }
  }
  function fitAll() { if (!map) return; var pts = stays.concat(attractions).map(function (o) { return [+o.lat, +o.lng]; }); if (pts.length > 1) map.fitBounds(pts, { padding: [30, 30], maxZoom: 9 }); else map.fitBounds(BOUNDS); }

  /* ---------- what is close to the chosen place (kept short; full lists are on the Discover pages) ---------- */
  function nearList(title, items, type, c) {
    var list = items.map(function (o) { return { o: o, d: km(c, { lat: +o.lat, lng: +o.lng }) }; }).sort(function (x, y) { return x.d - y.d; });
    var inR = list.filter(function (x) { return x.d <= R(); }), show = (inR.length ? inR : list).slice(0, 3);
    if (!show.length) return '';
    return '<div class="hm-near-sec"><h4>' + title + '</h4>' + (inR.length ? '' : '<p class="hm-note">Nothing within ' + R() + ' km. The closest are:</p>') + show.map(function (x) {
      return '<a class="hm-near-item" href="' + esc(x.o.url) + '"><span class="hm-dot ' + type + '"></span><span><strong>' + esc(x.o.name) + '</strong><small>' + esc(type === 'stay' ? x.o.loc : (x.o.cat || x.o.loc)) + '</small></span><span class="hm-km">' + fmtKm(x.d) + '</span></a>';
    }).join('') + '</div>';
  }
  function drawPanel() {
    if (!centre) {
      panel.innerHTML = '<h3>Across Kenya</h3><p>Every pin is an approved stay or an attraction. Tap one to see it, search a place to see what is around it, or skip the search and open the full lists below.</p>';
      return;
    }
    var c = { lat: centre.lat, lng: centre.lng };
    var A = attractions.filter(function (o) { return !(centre.type === 'attraction' && String(o.id) === String(centre.id)); });
    var S = stays.filter(function (o) { return !(centre.type === 'stay' && String(o.id) === String(centre.id)); });
    var parts = centre.type === 'attraction' ? [nearList('Stays near here', S, 'stay', c), nearList('Other attractions nearby', A, 'attraction', c)]
              : [nearList('Attractions nearby', A, 'attraction', c), nearList('Stays nearby', S, 'stay', c)];
    if (kind === 'stays') parts = [nearList('Stays nearby', S, 'stay', c)]; else if (kind === 'attractions') parts = [nearList('Attractions nearby', A, 'attraction', c)];
    panel.innerHTML = '<h3>Near ' + esc(centre.label) + '</h3>' + (centre.note ? '<p class="hm-note">' + esc(centre.note) + '</p>' : '') + parts.join('') + '<button type="button" class="hm-clear" data-clear>Clear destination</button>';
  }
  function choose(c) {
    centre = c; input.value = c.type === 'me' ? '' : c.label; box.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1;
    drawPanel(); paint(); updateLinks();
  }
  function clearAll() { centre = null; input.value = ''; drawPanel(); paint(); fitAll(); updateLinks(); }

  /* ---------- destination box (optional) ---------- */
  function localMatches(q) {
    var t = q.toLowerCase(), out = [];
    attractions.filter(function (o) { return (o.name + ' ' + (o.loc || '') + ' ' + (o.cat || '')).toLowerCase().indexOf(t) > -1; }).slice(0, 4).forEach(function (o) { out.push({ type: 'attraction', label: o.name, sub: 'Attraction' + (o.loc ? ' · ' + o.loc : ''), lat: +o.lat, lng: +o.lng, id: o.id }); });
    stays.filter(function (o) { return (o.name + ' ' + (o.loc || '')).toLowerCase().indexOf(t) > -1; }).slice(0, 4).forEach(function (o) { out.push({ type: 'stay', label: o.name, sub: 'Stay' + (o.loc ? ' · ' + o.loc : ''), lat: +o.lat, lng: +o.lng, id: o.id }); });
    PLACES.filter(function (p) { return p[0].toLowerCase().indexOf(t) > -1; }).slice(0, 4).forEach(function (p) { out.push({ type: 'place', label: p[0], sub: 'Place in Kenya', lat: p[1], lng: p[2] }); });
    return out;
  }
  function drawSugg(note) {
    box.innerHTML = sugg.map(function (s, i) { return '<li role="option" data-i="' + i + '" class="' + (i === active ? 'on' : '') + '"><span class="hm-dot ' + s.type + '"></span><span><strong>' + esc(s.label) + '</strong><small>' + esc(s.sub) + '</small></span></li>'; }).join('') + (note ? '<li class="hm-sugg-note">' + note + '</li>' : '');
    box.hidden = !sugg.length && !note; input.setAttribute('aria-expanded', String(!box.hidden));
  }
  function update() {
    updateLinks();
    var q = input.value.trim(); if (q.length < 2) { sugg = []; drawSugg(); return; }
    sugg = localMatches(q); active = sugg.length ? 0 : -1;
    drawSugg(q.length >= 3 ? '<a href="#" data-remote>Search all of Kenya for &ldquo;' + esc(q) + '&rdquo; on OpenStreetMap</a>' : '');
  }
  function remote() {
    var q = input.value.trim(); if (q.length < 3) return;
    drawSugg('Searching OpenStreetMap&hellip;');
    var ctl = window.AbortController ? new AbortController() : null, to = ctl ? setTimeout(function () { ctl.abort(); }, 7000) : null;
    fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&countrycodes=ke&limit=6&q=' + encodeURIComponent(q), { signal: ctl ? ctl.signal : undefined, headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (to) clearTimeout(to);
        sugg = data.map(function (d) { var parts = d.display_name.split(','); return { type: 'place', label: parts.slice(0, 2).join(',').trim(), sub: 'OpenStreetMap · ' + parts.slice(-3, -1).join(',').trim(), lat: +d.lat, lng: +d.lon }; });
        active = sugg.length ? 0 : -1; drawSugg(sugg.length ? '' : 'No places found in Kenya for that search.');
      })
      .catch(function () { drawSugg('Could not reach OpenStreetMap search. Built-in places still work, and the buttons below still search for what you typed.'); });
  }
  input.addEventListener('input', update); input.addEventListener('focus', update);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); if (sugg.length) { active = (active + 1) % sugg.length; drawSugg(); } }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (sugg.length) { active = (active - 1 + sugg.length) % sugg.length; drawSugg(); } }
    else if (e.key === 'Escape') { box.hidden = true; input.setAttribute('aria-expanded', 'false'); }
  });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (sugg[active] && !box.hidden) choose(sugg[active]);
    else if (input.value.trim() === '') clearAll();
    else { var m = localMatches(input.value.trim()); if (m.length) choose(m[0]); else remote(); }
  });
  box.addEventListener('mousedown', function (e) {
    e.preventDefault();
    var li = e.target.closest('[data-i]'), rm = e.target.closest('[data-remote]');
    if (li) choose(sugg[+li.dataset.i]); else if (rm) remote();
  });
  document.addEventListener('click', function (e) { if (!root.contains(e.target) || (!e.target.closest('.hm-box'))) { box.hidden = true; input.setAttribute('aria-expanded', 'false'); } });
  panel.addEventListener('click', function (e) { if (e.target.closest('[data-clear]')) clearAll(); });
  radiusSel.addEventListener('change', function () { if (centre) { drawPanel(); paint(); } updateLinks(); });

  /* ---------- use my location (browser geolocation, optional) ---------- */
  locBtn.addEventListener('click', function () {
    if (!navigator.geolocation) { panel.insertAdjacentHTML('afterbegin', '<p class="hm-note">Your browser does not support location.</p>'); return; }
    var label = locBtn.innerHTML; locBtn.disabled = true; locBtn.textContent = 'Locating…';
    navigator.geolocation.getCurrentPosition(function (p) {
      locBtn.disabled = false; locBtn.innerHTML = label;
      var la = p.coords.latitude, ln = p.coords.longitude, inK = la >= -4.95 && la <= 5.1 && ln >= 33.85 && ln <= 42;
      choose({ type: 'me', label: 'your location', lat: la, lng: ln, note: inK ? '' : 'You appear to be outside Kenya, so these are the closest places we have.' });
    }, function (err) {
      locBtn.disabled = false; locBtn.innerHTML = label;
      drawPanel(); panel.insertAdjacentHTML('afterbegin', '<p class="hm-note">' + (err.code === 1 ? 'Location permission was denied. You can still search for a place.' : 'We could not get your location. Try searching for a place.') + '</p>');
    }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 0 });
  });

  /* ---------- filter chips ---------- */
  Array.prototype.forEach.call(root.querySelectorAll('[data-kind]'), function (b) {
    b.addEventListener('click', function () {
      kind = b.dataset.kind;
      Array.prototype.forEach.call(root.querySelectorAll('[data-kind]'), function (x) { var on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      drawPanel(); paint();
    });
  });

  /* ---------- expand / collapse the map ---------- */
  function setExpanded(on) {
    wrap.classList.toggle('expanded', on); document.documentElement.classList.toggle('hm-locked', on);
    wrap.setAttribute('role', on ? 'dialog' : 'region'); if (on) wrap.setAttribute('aria-modal', 'true'); else wrap.removeAttribute('aria-modal');
    expandBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (map) { if (on) map.scrollWheelZoom.enable(); else map.scrollWheelZoom.disable(); setTimeout(function () { map.invalidateSize(); }, 80); }
    if (on) closeBtn.focus(); else expandBtn.focus();
  }
  expandBtn.addEventListener('click', function () { setExpanded(true); });
  closeBtn.addEventListener('click', function () { setExpanded(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && wrap.classList.contains('expanded')) setExpanded(false); });

  /* ---------- start ---------- */
  drawPanel(); updateLinks();
  function start() {
    map = L.map(mapEl, { scrollWheelZoom: false, minZoom: 5, maxBounds: [[-9, 29], [10, 47]], maxBoundsViscosity: .8 });
    var tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors' }).addTo(map);
    map.attributionControl.setPrefix('<a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a>');
    tiles.on('tileerror', function () { mapEl.classList.add('tiles-offline'); }); tiles.on('tileload', function () { mapEl.classList.remove('tiles-offline'); });
    layer = L.layerGroup().addTo(map); extra = L.layerGroup().addTo(map);
    SEPins.legend(map, ['stay', 'attraction']);
    map.on('click', function () { map.scrollWheelZoom.enable(); }); mapEl.addEventListener('mouseleave', function () { if (!wrap.classList.contains('expanded')) map.scrollWheelZoom.disable(); });
    fitAll(); paint();
    setTimeout(function () { map.invalidateSize(); }, 200);
  }
  function boot() {
    if (!window.seLeaflet) { mapEl.innerHTML = '<p class="hm-fail">The map could not be loaded.</p>'; return; }
    window.seLeaflet().then(start, function () { mapEl.innerHTML = '<p class="hm-fail">The map library could not be loaded. The buttons below still work.</p>'; });
  }
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (en) { if (en[0].isIntersecting) { io.disconnect(); boot(); } }, { rootMargin: '400px' }); io.observe(mapEl);
  } else boot();
})();
