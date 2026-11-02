/* GPS-style directions for the Leaflet maps.
 *
 *   window.enableDirections(map, { destination: { lat, lng, name } })   // destination is optional
 *
 * - Every pin's popup gets a "Directions" button (stays, attractions, live OpenStreetMap places).
 * - Starts from the visitor's location (browser GPS), a typed place, or a point picked on the map.
 * - Driving / walking / cycling routes with distance, time and written turn-by-turn steps.
 * - "Start navigation" follows the live GPS position like a satnav: moves a blue dot, shows the next turn,
 *   the distance and time left, re-routes when you leave the route, and can speak the steps.
 * - "Open in Google Maps" hands the same trip to the Google Maps app/site.
 * Routing uses the free OpenStreetMap-based OSRM servers; no API key is needed. GPS needs https or localhost.
 */
(function () {
  'use strict';

  var ROUTERS = {
    driving: ['https://routing.openstreetmap.de/routed-car/route/v1/driving/', 'https://router.project-osrm.org/route/v1/driving/'],
    walking: ['https://routing.openstreetmap.de/routed-foot/route/v1/driving/'],
    cycling: ['https://routing.openstreetmap.de/routed-bike/route/v1/driving/']
  };
  var GMAPS_MODE = { driving: 'driving', walking: 'walking', cycling: 'bicycling' };
  var OFF_ROUTE_M = 120, REROUTE_EVERY_MS = 15000, ARRIVE_M = 35;

  /* ---------- small helpers ---------- */
  function toRad(d) { return d * Math.PI / 180; }
  function haversine(a, b) {            // metres between [lat,lng] points
    var R = 6371000, dLat = toRad(b[0] - a[0]), dLng = toRad(b[1] - a[1]);
    var x = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(toRad(a[0])) * Math.cos(toRad(b[0])) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(x)));
  }
  function fmtDist(m) {
    if (m < 1000) return Math.max(10, Math.round(m / 10) * 10) + ' m';
    return (m / 1000).toFixed(m < 10000 ? 1 : 0) + ' km';
  }
  function fmtTime(s) {
    var min = Math.max(1, Math.round(s / 60));
    if (min < 60) return min + ' min';
    var h = Math.floor(min / 60), r = min % 60;
    return h + ' h' + (r ? ' ' + r + ' min' : '');
  }
  function compass(b) {
    return ['north', 'north-east', 'east', 'south-east', 'south', 'south-west', 'west', 'north-west'][Math.round(((b % 360) + 360) % 360 / 45) % 8];
  }
  function stepText(st) {
    var m = st.maneuver || {}, t = m.type, mod = m.modifier || '', name = st.name ? st.name : '';
    var onto = name ? ' onto ' + name : '', on = name ? ' on ' + name : '';
    switch (t) {
      case 'depart': return 'Head ' + compass(m.bearing_after || 0) + on;
      case 'arrive': return 'You have arrived at your destination' + (mod && mod !== 'straight' ? ', on the ' + mod : '');
      case 'turn': return (mod === 'straight' ? 'Continue straight' : 'Turn ' + mod) + onto;
      case 'new name': return 'Continue' + onto;
      case 'continue': return (mod && mod !== 'straight' ? 'Bear ' + mod : 'Continue straight') + on;
      case 'merge': return 'Merge ' + mod + onto;
      case 'on ramp': return 'Take the ramp' + (mod ? ' on the ' + mod : '') + onto;
      case 'off ramp': return 'Take the exit' + (mod ? ' on the ' + mod : '') + onto;
      case 'fork': return 'Keep ' + (mod || 'straight') + ' at the fork' + onto;
      case 'end of road': return 'At the end of the road turn ' + (mod || 'around') + onto;
      case 'roundabout': case 'rotary':
        return 'At the roundabout take exit ' + (m.exit || '') + onto;
      case 'roundabout turn': return 'At the roundabout turn ' + mod + onto;
      default: return 'Continue' + onto;
    }
  }
  function arrowFor(st) {
    var m = st.maneuver || {}, mod = m.modifier || '';
    if (m.type === 'arrive') return '✓';
    if (m.type === 'depart') return '↑';
    if (mod.indexOf('sharp left') === 0) return '↰';
    if (mod.indexOf('sharp right') === 0) return '↱';
    if (mod.indexOf('slight left') === 0) return '↖';
    if (mod.indexOf('slight right') === 0) return '↗';
    if (mod === 'left') return '←';
    if (mod === 'right') return '→';
    if (mod === 'uturn') return '↶';
    return '↑';
  }
  // distance in metres from point p to segment a-b (all [lat,lng]); local flat approximation is fine at road scale
  function distToSegment(p, a, b) {
    var k = Math.cos(toRad(p[0]));
    var ax = a[1] * k, ay = a[0], bx = b[1] * k, by = b[0], px = p[1] * k, py = p[0];
    var dx = bx - ax, dy = by - ay, len2 = dx * dx + dy * dy;
    var t = len2 ? Math.max(0, Math.min(1, ((px - ax) * dx + (py - ay) * dy) / len2)) : 0;
    var q = [ay + t * dy, (ax + t * dx) / (k || 1)];
    return { dist: haversine(p, q), t: t };
  }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  var cssAdded = false;
  function addCss() {
    if (cssAdded) return; cssAdded = true;
    var s = document.createElement('style');
    s.textContent =
      '.dir-btn{border:1px solid #0d3b2e;background:#0d3b2e;color:#fff;border-radius:5px;padding:6px 11px;font-size:12px;font-weight:700;cursor:pointer;margin-top:6px}' +
      '.dir-btn:hover{background:#155b43}.dir-btn.alt{background:#fff;color:#0d3b2e}.dir-btn:disabled{opacity:.55;cursor:wait}' +
      '.dir-ctl{background:#fff;border:1px solid #0d3b2e;color:#0d3b2e;font-weight:700;font-size:12px;padding:8px 12px;border-radius:5px;cursor:pointer;box-shadow:0 1px 5px rgba(0,0,0,.25)}' +
      '.dir-panel{background:#fff;border:1px solid #d9ddd5;border-radius:10px;padding:16px;margin:0 0 20px;font-size:13px;color:#222}' +
      '.dir-panel[hidden]{display:none}.dir-panel h4{margin:0 0 10px;font:600 16px Georgia,serif;color:#0d3b2e}' +
      '.dir-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px}' +
      '.dir-row label{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#646a63;min-width:44px}' +
      '.dir-row input[type=text]{flex:1 1 180px;min-width:0;padding:8px 10px;border:1px solid #d5d8cf;border-radius:5px;font-size:13px}' +
      '.dir-row select{padding:8px 10px;border:1px solid #d5d8cf;border-radius:5px;font-size:13px}' +
      '.dir-to{font-weight:700;color:#0d3b2e}.dir-status{font-size:12px;color:#646a63;margin:6px 0}.dir-status.err{color:#a3392e}' +
      '.dir-summary{display:flex;gap:18px;flex-wrap:wrap;background:#eef4ef;border-radius:8px;padding:10px 14px;margin:10px 0;font-weight:700;color:#0d3b2e}' +
      '.dir-next{display:flex;gap:12px;align-items:center;background:#0d3b2e;color:#fff;border-radius:8px;padding:12px 14px;margin:10px 0}' +
      '.dir-next .arr{font-size:30px;line-height:1}.dir-next small{display:block;opacity:.8;font-size:11px}.dir-next b{font-size:15px}' +
      '.dir-steps{list-style:none;margin:0;padding:0;max-height:300px;overflow:auto;border-top:1px solid #e6e8e1}' +
      '.dir-steps li{display:flex;gap:12px;align-items:center;padding:9px 4px;border-bottom:1px solid #eef0ea}' +
      '.dir-steps li.now{background:#fff7e6}.dir-steps .arr{width:24px;text-align:center;font-size:18px;color:#0d3b2e;flex:0 0 24px}' +
      '.dir-steps .d{margin-left:auto;color:#646a63;font-size:12px;white-space:nowrap}' +
      '.dir-me{width:16px;height:16px;border-radius:50%;background:#2563eb;border:3px solid #fff;box-shadow:0 0 0 2px rgba(37,99,235,.35),0 1px 4px rgba(0,0,0,.4)}';
    document.head.appendChild(s);
  }

  /* ---------- main ---------- */
  window.enableDirections = function (map, options) {
    if (!map || typeof L === 'undefined') return;
    if (map._seDirections) return map._seDirections;
    addCss();
    options = options || {};

    var state = {
      dest: options.destination || null,   // {lat,lng,name}
      start: null,                          // [lat,lng]
      mode: 'driving',
      route: null,                          // {coords:[[lat,lng]...], cum:[m...], steps:[], distance, duration}
      line: null, casing: null, startMarker: null, destMarker: null, me: null, meAcc: null,
      watchId: null, lastReroute: 0, pickMode: false, speak: false, lastSpokenStep: -1, useGps: true
    };

    /* panel (placed right under the map) */
    var panel = el('div', 'dir-panel'); panel.hidden = true;
    panel.setAttribute('aria-live', 'polite');
    map.getContainer().insertAdjacentElement('afterend', panel);

    panel.appendChild(el('h4', null, 'Directions'));

    var rowTo = el('div', 'dir-row');
    rowTo.appendChild(el('label', null, 'To'));
    var toName = el('span', 'dir-to', 'Choose a pin on the map and press “Directions”');
    rowTo.appendChild(toName);
    var pickBtn = el('button', 'dir-btn alt', 'Pick on map'); pickBtn.type = 'button';
    rowTo.appendChild(pickBtn);
    panel.appendChild(rowTo);

    var rowFrom = el('div', 'dir-row');
    rowFrom.appendChild(el('label', null, 'From'));
    var fromInput = el('input'); fromInput.type = 'text';
    fromInput.placeholder = 'My location (GPS) — or type a place, e.g. Nairobi CBD';
    fromInput.setAttribute('aria-label', 'Starting point');
    rowFrom.appendChild(fromInput);
    var gpsBtn = el('button', 'dir-btn alt', 'Use my location'); gpsBtn.type = 'button';
    rowFrom.appendChild(gpsBtn);
    panel.appendChild(rowFrom);

    var rowMode = el('div', 'dir-row');
    rowMode.appendChild(el('label', null, 'By'));
    var modeSel = el('select');
    [['driving', 'Driving'], ['walking', 'Walking'], ['cycling', 'Cycling']].forEach(function (o) {
      var op = el('option', null, o[1]); op.value = o[0]; modeSel.appendChild(op);
    });
    rowMode.appendChild(modeSel);
    var goBtn = el('button', 'dir-btn', 'Get directions'); goBtn.type = 'button';
    var navBtn = el('button', 'dir-btn', '▶ Start navigation'); navBtn.type = 'button'; navBtn.disabled = true;
    var gmBtn = el('a', 'dir-btn alt', 'Open in Google Maps'); gmBtn.target = '_blank'; gmBtn.rel = 'noopener'; gmBtn.style.display = 'none'; gmBtn.style.textDecoration = 'none';
    var clearBtn = el('button', 'dir-btn alt', 'Clear'); clearBtn.type = 'button';
    [goBtn, navBtn, gmBtn, clearBtn].forEach(function (b) { rowMode.appendChild(b); });
    var voiceLbl = el('label'); voiceLbl.style.cssText = 'display:inline-flex;gap:5px;align-items:center;text-transform:none;letter-spacing:0;font-weight:400;font-size:12px;min-width:0';
    var voiceChk = el('input'); voiceChk.type = 'checkbox';
    voiceLbl.appendChild(voiceChk); voiceLbl.appendChild(document.createTextNode(' Voice guidance'));
    if (!('speechSynthesis' in window)) voiceLbl.style.display = 'none';
    rowMode.appendChild(voiceLbl);
    panel.appendChild(rowMode);

    var statusEl = el('div', 'dir-status');
    panel.appendChild(statusEl);
    var nextEl = el('div', 'dir-next'); nextEl.hidden = true;
    panel.appendChild(nextEl);
    var summaryEl = el('div', 'dir-summary'); summaryEl.hidden = true;
    panel.appendChild(summaryEl);
    var stepsEl = el('ol', 'dir-steps'); stepsEl.hidden = true;
    panel.appendChild(stepsEl);

    function setStatus(msg, isErr) { statusEl.textContent = msg || ''; statusEl.className = 'dir-status' + (isErr ? ' err' : ''); }
    function openPanel() { panel.hidden = false; }

    function setDestination(latlng, name) {
      state.dest = { lat: latlng.lat != null ? latlng.lat : latlng[0], lng: latlng.lng != null ? latlng.lng : latlng[1], name: name || 'Selected place' };
      toName.textContent = state.dest.name;
      if (state.destMarker) { map.removeLayer(state.destMarker); state.destMarker = null; }
      state.destMarker = L.circleMarker([state.dest.lat, state.dest.lng], { radius: 9, color: '#a3392e', weight: 3, fillColor: '#fff', fillOpacity: 1, interactive: false }).addTo(map);
      openPanel();
    }

    /* ---- locating the visitor ---- */
    function locate(thenRoute) {
      if (!navigator.geolocation) { setStatus('This browser cannot share its location. Type a starting place instead.', true); return; }
      setStatus('Finding your location…');
      navigator.geolocation.getCurrentPosition(function (pos) {
        state.useGps = true;
        state.start = [pos.coords.latitude, pos.coords.longitude];
        fromInput.value = '';
        showMe(state.start, pos.coords.accuracy);
        setStatus('Location found.');
        if (thenRoute) requestRoute();
      }, function (err) {
        var why = err && err.code === 1 ? 'Location permission was denied. Allow it in the browser, or type a starting place.' :
                  'Could not get your location (GPS needs https or localhost). Type a starting place instead.';
        setStatus(why, true);
      }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 10000 });
    }
    function showMe(ll, acc) {
      if (!state.me) {
        state.me = L.marker(ll, { icon: L.divIcon({ className: '', html: '<div class="dir-me"></div>', iconSize: [16, 16], iconAnchor: [8, 8] }), keyboard: false, zIndexOffset: 1000 }).addTo(map);
        state.me.options.isUserLoc = true;
        state.me.bindTooltip('You are here');
      } else state.me.setLatLng(ll);
      if (acc) {
        if (!state.meAcc) state.meAcc = L.circle(ll, { radius: acc, color: '#2563eb', weight: 1, fillOpacity: .08, interactive: false }).addTo(map);
        else { state.meAcc.setLatLng(ll); state.meAcc.setRadius(acc); }
      }
    }
    function geocode(q) {
      return fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=ke&q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!d.length) throw new Error('not found'); return [parseFloat(d[0].lat), parseFloat(d[0].lon)]; });
    }

    /* ---- routing ---- */
    function fetchRoute(from, to, mode) {
      var bases = ROUTERS[mode] || ROUTERS.driving, i = 0;
      var coords = from[1] + ',' + from[0] + ';' + to[1] + ',' + to[0];
      function attempt() {
        if (i >= bases.length) return Promise.reject(new Error('routing unavailable'));
        var url = bases[i++] + coords + '?overview=full&geometries=geojson&steps=true';
        return fetch(url).then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
          .then(function (d) {
            if (!d.routes || !d.routes.length) throw new Error('no route');
            return d.routes[0];
          }).catch(function (e) { if (e.message === 'no route') throw e; return attempt(); });
      }
      return attempt();
    }
    function buildRoute(r) {
      var coords = r.geometry.coordinates.map(function (c) { return [c[1], c[0]]; });
      var cum = [0];
      for (var i = 1; i < coords.length; i++) cum.push(cum[i - 1] + haversine(coords[i - 1], coords[i]));
      var steps = [];
      (r.legs || []).forEach(function (leg) { (leg.steps || []).forEach(function (s) { steps.push(s); }); });
      return { coords: coords, cum: cum, steps: steps, distance: r.distance, duration: r.duration };
    }
    function drawRoute(route) {
      if (state.line) { map.removeLayer(state.line); map.removeLayer(state.casing); }
      state.casing = L.polyline(route.coords, { color: '#fff', weight: 9, opacity: .9, interactive: false }).addTo(map);
      state.line = L.polyline(route.coords, { color: '#2563eb', weight: 5, opacity: .95, interactive: false }).addTo(map);
    }
    function renderSummary() {
      var rt = state.route; if (!rt) return;
      summaryEl.hidden = false; summaryEl.textContent = '';
      summaryEl.appendChild(el('span', null, fmtDist(rt.distance)));
      summaryEl.appendChild(el('span', null, fmtTime(rt.duration)));
      var eta = new Date(Date.now() + rt.duration * 1000);
      summaryEl.appendChild(el('span', null, 'Arrive ≈ ' + eta.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })));
      stepsEl.hidden = false; stepsEl.textContent = '';
      rt.steps.forEach(function (st, idx) {
        var li = el('li'); li.dataset.idx = idx;
        li.appendChild(el('span', 'arr', arrowFor(st)));
        li.appendChild(el('span', null, stepText(st)));
        li.appendChild(el('span', 'd', st.distance ? fmtDist(st.distance) : ''));
        stepsEl.appendChild(li);
      });
      gmBtn.style.display = '';
      gmBtn.href = 'https://www.google.com/maps/dir/?api=1&destination=' + state.dest.lat + ',' + state.dest.lng +
        (state.start ? '&origin=' + state.start[0] + ',' + state.start[1] : '') + '&travelmode=' + GMAPS_MODE[state.mode];
      navBtn.disabled = false;
    }
    function requestRoute(opts) {
      opts = opts || {};
      if (!state.dest) { setStatus('Choose a destination first: click a pin and press “Directions”, or use “Pick on map”.', true); openPanel(); return Promise.resolve(); }
      state.mode = modeSel.value;
      var ready;
      var typed = fromInput.value.trim();
      if (typed && !opts.silent) {
        setStatus('Looking up “' + typed + '”…');
        ready = geocode(typed).then(function (ll) { state.useGps = false; state.start = ll; });
      } else if (!state.start) {
        ready = new Promise(function (resolve, reject) {
          if (!navigator.geolocation) return reject(new Error('nogeo'));
          navigator.geolocation.getCurrentPosition(function (p) {
            state.useGps = true; state.start = [p.coords.latitude, p.coords.longitude]; showMe(state.start, p.coords.accuracy); resolve();
          }, function () { reject(new Error('geo')); }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 10000 });
        });
      } else ready = Promise.resolve();

      return ready.then(function () {
        if (!opts.silent) setStatus('Finding the best route…');
        return fetchRoute(state.start, [state.dest.lat, state.dest.lng], state.mode);
      }).then(function (r) {
        state.route = buildRoute(r);
        drawRoute(state.route);
        if (state.startMarker) { map.removeLayer(state.startMarker); state.startMarker = null; }
        if (!state.watchId) {
          state.startMarker = L.circleMarker(state.start, { radius: 7, color: '#0d3b2e', weight: 3, fillColor: '#fff', fillOpacity: 1, interactive: false }).addTo(map);
          map.fitBounds(state.line.getBounds(), { padding: [30, 30] });
        }
        renderSummary();
        setStatus(opts.silent ? '' : 'Route ready. Press “Start navigation” to follow it with live GPS.');
      }).catch(function (e) {
        var m = e && e.message;
        if (m === 'not found') setStatus('Could not find that starting place. Try a more specific name.', true);
        else if (m === 'geo' || m === 'nogeo') setStatus('Could not get your location. Type a starting place (for example “Nairobi CBD”) and try again.', true);
        else if (m === 'no route') setStatus('No ' + state.mode + ' route found between these points.', true);
        else setStatus('The route service is not reachable right now. Check your connection, or use “Open in Google Maps”.', true);
      });
    }

    /* ---- live navigation ---- */
    function speak(text) {
      if (!state.speak || !('speechSynthesis' in window)) return;
      try { window.speechSynthesis.cancel(); window.speechSynthesis.speak(new SpeechSynthesisUtterance(text)); } catch (e) {}
    }
    function stopNav() {
      if (state.watchId != null) navigator.geolocation.clearWatch(state.watchId);
      state.watchId = null; navBtn.textContent = '▶ Start navigation'; nextEl.hidden = true;
      if (state.speak && 'speechSynthesis' in window) window.speechSynthesis.cancel();
    }
    function progress(pos) {
      var rt = state.route; if (!rt) return;
      var coords = rt.coords, best = { dist: Infinity, i: 0, t: 0 };
      for (var i = 0; i < coords.length - 1; i++) {
        var d = distToSegment(pos, coords[i], coords[i + 1]);
        if (d.dist < best.dist) best = { dist: d.dist, i: i, t: d.t };
      }
      var segLen = rt.cum[best.i + 1] - rt.cum[best.i];
      var travelled = rt.cum[best.i] + best.t * segLen;
      var left = Math.max(0, rt.distance - travelled);
      var toDest = haversine(pos, [state.dest.lat, state.dest.lng]);

      if (toDest < ARRIVE_M) {
        nextEl.hidden = false; nextEl.textContent = '';
        nextEl.appendChild(el('span', 'arr', '✓')); var b0 = el('div'); b0.appendChild(el('b', null, 'You have arrived at ' + state.dest.name)); nextEl.appendChild(b0);
        speak('You have arrived at your destination.');
        stopNav(); setStatus('Arrived.');
        return;
      }
      if (best.dist > OFF_ROUTE_M) {
        if (Date.now() - state.lastReroute > REROUTE_EVERY_MS) {
          state.lastReroute = Date.now(); state.start = pos; setStatus('Off route — finding a new way…');
          speak('Re-routing.');
          requestRoute({ silent: true });
        }
        return;
      }
      // which step are we on? the step whose end lies furthest along the route but ahead of us
      var acc = 0, idx = 0, distToManeuver = 0;
      for (var s = 0; s < rt.steps.length; s++) {
        acc += rt.steps[s].distance || 0;
        if (travelled <= acc + 5) { idx = s; distToManeuver = Math.max(0, acc - travelled); break; }
        idx = s; distToManeuver = 0;
      }
      var upcoming = rt.steps[Math.min(idx + 1, rt.steps.length - 1)];
      nextEl.hidden = false; nextEl.textContent = '';
      nextEl.appendChild(el('span', 'arr', arrowFor(upcoming)));
      var box = el('div'); box.appendChild(el('small', null, 'In ' + fmtDist(distToManeuver)));
      box.appendChild(el('b', null, stepText(upcoming))); nextEl.appendChild(box);
      Array.prototype.forEach.call(stepsEl.children, function (li, n) { li.className = (n === idx + 1) ? 'now' : ''; });
      var remainingTime = rt.duration * (left / Math.max(1, rt.distance));
      summaryEl.textContent = '';
      summaryEl.appendChild(el('span', null, fmtDist(left) + ' left'));
      summaryEl.appendChild(el('span', null, fmtTime(remainingTime)));
      summaryEl.appendChild(el('span', null, 'Arrive ≈ ' + new Date(Date.now() + remainingTime * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })));
      if (idx + 1 !== state.lastSpokenStep && distToManeuver < 200) {
        state.lastSpokenStep = idx + 1;
        speak('In ' + fmtDist(distToManeuver) + ', ' + stepText(upcoming));
      }
    }
    function startNav() {
      if (!state.route) { requestRoute().then(function () { if (state.route) startNav(); }); return; }
      if (!navigator.geolocation) { setStatus('This browser cannot share its location, so live navigation is unavailable.', true); return; }
      if (state.watchId != null) { stopNav(); setStatus('Navigation stopped.'); return; }
      state.lastSpokenStep = -1; state.lastReroute = Date.now();
      navBtn.textContent = '■ Stop navigation'; setStatus('Navigating — follow the blue dot.');
      state.watchId = navigator.geolocation.watchPosition(function (p) {
        var ll = [p.coords.latitude, p.coords.longitude];
        state.useGps = true; state.start = ll;
        showMe(ll, p.coords.accuracy);
        map.panTo(ll, { animate: true });
        progress(ll);
      }, function (err) {
        setStatus(err && err.code === 1 ? 'Location permission denied — live navigation stopped.' : 'Lost the GPS signal…', true);
        if (err && err.code === 1) stopNav();
      }, { enableHighAccuracy: true, maximumAge: 2000, timeout: 20000 });
    }

    function clearAll() {
      stopNav();
      [state.line, state.casing, state.startMarker, state.destMarker].forEach(function (l) { if (l) map.removeLayer(l); });
      state.line = state.casing = state.startMarker = state.destMarker = null;
      state.route = null; state.dest = null; state.start = null; state.pickMode = false;
      summaryEl.hidden = nextEl.hidden = stepsEl.hidden = true; gmBtn.style.display = 'none'; navBtn.disabled = true;
      toName.textContent = 'Choose a pin on the map and press “Directions”';
      fromInput.value = ''; setStatus('');
      map.getContainer().style.cursor = '';
    }

    /* ---- wiring ---- */
    goBtn.addEventListener('click', function () { state.start = fromInput.value.trim() ? null : state.start; requestRoute(); });
    gpsBtn.addEventListener('click', function () { fromInput.value = ''; locate(!!state.dest); });
    navBtn.addEventListener('click', startNav);
    clearBtn.addEventListener('click', clearAll);
    modeSel.addEventListener('change', function () { if (state.dest && state.route) requestRoute(); });
    fromInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); state.start = null; requestRoute(); } });
    voiceChk.addEventListener('change', function () { state.speak = voiceChk.checked; });
    pickBtn.addEventListener('click', function () {
      state.pickMode = true; openPanel(); setStatus('Click the map where you want to go.');
      map.getContainer().style.cursor = 'crosshair';
    });
    map.on('click', function (e) {
      if (!state.pickMode) return;
      state.pickMode = false; map.getContainer().style.cursor = '';
      setDestination(e.latlng, 'Point on map (' + e.latlng.lat.toFixed(4) + ', ' + e.latlng.lng.toFixed(4) + ')');
      requestRoute();
    });

    // A "Directions" button inside every popup
    map.on('popupopen', function (e) {
      var popup = e.popup, node = popup.getElement && popup.getElement();
      if (!node) return;
      var content = node.querySelector('.leaflet-popup-content');
      if (!content || content.querySelector('.dir-btn')) return;
      var ll = popup.getLatLng(); if (!ll) return;
      var b = content.querySelector('b, strong, h3, h4');
      var name = (b && b.textContent.trim()) || (content.textContent || '').trim().split('\n')[0].slice(0, 60) || 'Selected place';
      var btn = el('button', 'dir-btn', 'Directions'); btn.type = 'button';
      btn.addEventListener('click', function (ev) {
        ev.preventDefault(); ev.stopPropagation();
        setDestination(ll, name);
        map.closePopup();
        requestRoute().then(function () { panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); });
      });
      var wrap = el('div'); wrap.appendChild(btn); content.appendChild(wrap);
      popup.update();
    });

    // Map button
    var Ctl = L.Control.extend({
      options: { position: 'topleft' },
      onAdd: function () {
        var b = L.DomUtil.create('button', 'dir-ctl'); b.type = 'button'; b.textContent = 'Directions';
        L.DomEvent.disableClickPropagation(b);
        L.DomEvent.on(b, 'click', function () {
          panel.hidden = !panel.hidden;
          if (!panel.hidden && !state.dest && options.destination) setDestination(options.destination, options.destination.name);
        });
        return b;
      }
    });
    new Ctl().addTo(map);

    if (state.dest) { toName.textContent = state.dest.name || 'Selected place'; }

    var api = { setDestination: setDestination, route: requestRoute, clear: clearAll, open: openPanel };
    map._seDirections = api;
    return api;
  };
})();
