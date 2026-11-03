/* Live nearby place discovery using OpenStreetMap's Overpass API.
 * Automatically refreshes when the map is moved, caches successful results,
 * and keeps live discoveries separate from approved database listings.
 */
(function () {
  'use strict';
  const ENDPOINTS = [
    'https://overpass-api.de/api/interpreter',
    'https://overpass.private.coffee/api/interpreter'
  ];
  const CACHE_TTL = 10 * 60 * 1000;
  const MAX_MARKERS = 70;
  const MIN_ZOOM_FOR_LIVE_RESULTS = 9;
  const REQUEST_TIMEOUT_MS = 18000;
  const MAX_RESULTS_ASKED = 120;
  const MAX_RADIUS = 5000;     // bigger circles regularly time out on the free public servers

  function placeCategory(tags) {
    if (tags.tourism) return tags.tourism.replace(/_/g, ' ');
    if (tags.historic) return 'historic site';
    if (tags.leisure) return tags.leisure.replace(/_/g, ' ');
    if (tags.natural) return tags.natural.replace(/_/g, ' ');
    if (tags.amenity) return tags.amenity.replace(/_/g, ' ');
    if (tags.shop) return tags.shop.replace(/_/g, ' ');
    return 'place';
  }
  function coordsFor(element) {
    if (Number.isFinite(element.lat) && Number.isFinite(element.lon)) return [element.lat, element.lon];
    if (element.center && Number.isFinite(element.center.lat) && Number.isFinite(element.center.lon)) return [element.center.lat, element.center.lon];
    return null;
  }
  function cacheKey(lat, lng, radius) {
    return 'osm-nearby-v3:' + Number(lat).toFixed(3) + ':' + Number(lng).toFixed(3) + ':' + radius;
  }
  function readCache(key) {
    try {
      const item = JSON.parse(sessionStorage.getItem(key) || 'null');
      if (item && Date.now() - item.savedAt < CACHE_TTL && Array.isArray(item.places)) return item.places;
    } catch (_) {}
    return null;
  }
  function writeCache(key, places) {
    try { sessionStorage.setItem(key, JSON.stringify({ savedAt: Date.now(), places: places })); } catch (_) {}
  }
  function makeQuery(lat, lng, radius) {
    // "out center tags 80" lets the server stop after 80 results, which keeps busy city areas from timing out.
    return '[out:json][timeout:20];(' +
      'nwr["name"]["tourism"~"^(attraction|museum|viewpoint|theme_park|zoo|gallery|artwork|aquarium|camp_site|hotel|guest_house|hostel)$"](around:' + radius + ',' + lat + ',' + lng + ');' +
      'nwr["name"]["historic"](around:' + radius + ',' + lat + ',' + lng + ');' +
      'nwr["name"]["leisure"="park"](around:' + radius + ',' + lat + ',' + lng + ');' +
      'nwr["name"]["natural"="beach"](around:' + radius + ',' + lat + ',' + lng + ');' +
      'nwr["name"]["amenity"~"^(restaurant|cafe)$"](around:' + radius + ',' + lat + ',' + lng + ');' +
      ');out center tags ' + MAX_RESULTS_ASKED + ';';
  }
  // Overpass reports "query timed out" / "out of memory" inside a normal HTTP 200 reply, with no places in it.
  function replyFailed(data) {
    const remark = String((data && data.remark) || '');
    return /timed out|runtime error|out of memory|too busy|rate.?limit/i.test(remark);
  }
  function toPlaces(data) {
    const seen = new Set();
    return data.elements.map(function (element) {
      const tags = element.tags || {};
      const coords = coordsFor(element);
      const name = String(tags.name || '').trim();
      if (!coords || !name) return null;
      const id = String(element.type || 'node') + '/' + String(element.id || '');
      if (seen.has(id)) return null;
      seen.add(id);
      return {
        id: id, name: name, category: placeCategory(tags), lat: coords[0], lng: coords[1],
        opening_hours: tags.opening_hours || '', website: tags.website || '', source: 'OpenStreetMap'
      };
    }).filter(Boolean).slice(0, MAX_MARKERS);
  }
  async function askOverpass(endpoint, query) {
    const controller = new AbortController();
    const timeout = setTimeout(function () { controller.abort(); }, REQUEST_TIMEOUT_MS);
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
        body: 'data=' + encodeURIComponent(query),
        signal: controller.signal
      });
      if (!response.ok) throw new Error('Overpass HTTP ' + response.status);
      const data = await response.json();
      if (!Array.isArray(data.elements)) throw new Error('Invalid Overpass response');
      if (replyFailed(data)) throw new Error('Overpass timed out');
      return data;
    } finally {
      clearTimeout(timeout);
    }
  }
  async function fetchPlaces(lat, lng, radius) {
    const key = cacheKey(lat, lng, radius);
    const cached = readCache(key);
    if (cached) return cached;
    // Try each server at the full distance, then once more at half the distance (busy areas time out on big circles).
    const attempts = [[ENDPOINTS[0], radius], [ENDPOINTS[1], radius], [ENDPOINTS[0], Math.max(1000, Math.round(radius / 2))]];
    let lastError = null;
    for (const attempt of attempts) {
      try {
        const data = await askOverpass(attempt[0], makeQuery(lat, lng, attempt[1]));
        const places = toPlaces(data);
        writeCache(key, places);   // only clean replies are remembered, never failures
        return places;
      } catch (error) {
        lastError = error;
      }
    }
    throw lastError || new Error('OpenStreetMap service unavailable');
  }
  function popupContent(place) {
    const box = document.createElement('div');
    const title = document.createElement('strong');
    const type = document.createElement('div');
    title.textContent = place.name;
    type.textContent = 'Type: ' + place.category;
    box.append(title, type);
    if (place.opening_hours) {
      const hours = document.createElement('div');
      hours.textContent = 'Opening hours: ' + place.opening_hours;
      box.appendChild(hours);
    }
    if (place.website && /^https?:\/\//i.test(place.website)) {
      const link = document.createElement('a');
      link.href = place.website;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = 'Website';
      box.appendChild(link);
    }
    return box;
  }

  window.enableOsmNearbyPlaces = function (map, options) {
    options = options || {};
    const button = options.buttonId ? document.getElementById(options.buttonId) : null;
    const status = options.statusId ? document.getElementById(options.statusId) : null;
    const resultsBox = options.resultsContainerId ? document.getElementById(options.resultsContainerId) : null;
    if (!map || typeof L === 'undefined') return;

    const layer = L.layerGroup().addTo(map);
    const markerById = Object.create(null);
    const icon = (window.SEPins ? window.SEPins.place() : L.divIcon({ className: '', html: '<span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:#d99a1f;border:2px solid #fff"></span>', iconAnchor: [7, 7] }));
    let busy = false;
    let pendingRefresh = false;
    let lastViewKey = '';
    let lastCentre = null;
    let needsSearchWhenVisible = false;
    let moveTimer = null;

    function renderList(places) {
      if (!resultsBox) return;
      resultsBox.replaceChildren();
      const heading = document.createElement('h3');
      heading.textContent = 'Nearby places';
      resultsBox.appendChild(heading);
      if (!places.length) {
        const message = document.createElement('p');
        message.textContent = 'No named places were found in this area. Move the map, zoom in, or try another location.';
        resultsBox.appendChild(message);
      } else {
        const grid = document.createElement('div');
        grid.className = 'osm-live-grid';
        places.forEach(function (place) {
          const card = document.createElement('article');
          card.className = 'osm-live-card';
          const name = document.createElement('strong');
          name.textContent = place.name;
          const category = document.createElement('p');
          category.textContent = place.category;
          const badge = document.createElement('small');
          badge.textContent = 'Nearby place';
          const focus = document.createElement('button');
          focus.type = 'button';
          focus.textContent = 'Show on map';
          focus.addEventListener('click', function () {
            map.setView([place.lat, place.lng], 15);
            const marker = markerById[place.id];
            if (marker) marker.openPopup();
          });
          card.append(name, category, badge, focus);
          grid.appendChild(card);
        });
        resultsBox.appendChild(grid);
      }
      const attribution = document.createElement('p');
      attribution.className = 'osm-attribution';
      attribution.textContent = 'Map data © OpenStreetMap contributors';
      resultsBox.appendChild(attribution);
    }

    function mapIsVisible() {
      const box = map.getContainer();
      return !!(box && box.offsetWidth > 0 && box.offsetHeight > 0);
    }
    function metresBetween(a, b) { return map.distance(a, b); }

    async function searchNearby(force) {
      if (busy) {
        pendingRefresh = true;
        if (force && status) status.textContent = 'Still searching… this can take up to a minute.';
        return;
      }
      // A map inside a hidden panel has no size; search once it is shown instead of wasting a request.
      if (!mapIsVisible()) { needsSearchWhenVisible = true; return; }
      if (map.getZoom() < MIN_ZOOM_FOR_LIVE_RESULTS) {
        if (status) status.textContent = 'Zoom in a little more to see nearby places.';
        return;
      }
      const centre = map.getCenter();
      const lat = Number.isFinite(options.latitude) ? options.latitude : centre.lat;
      const lng = Number.isFinite(options.longitude) ? options.longitude : centre.lng;
      const radius = Math.min(MAX_RADIUS, Math.max(1500, Number(options.radius) || 3000));
      // Automatic refreshes after a small pan are skipped; the button always searches.
      if (!force && lastCentre && metresBetween(lastCentre, L.latLng(lat, lng)) < radius * 0.4) return;
      lastCentre = L.latLng(lat, lng);
      lastViewKey = cacheKey(lat, lng, radius);
      needsSearchWhenVisible = false;
      busy = true;
      if (button) {
        button.disabled = true;
        button.textContent = 'Searching nearby places…';
      }
      if (status) status.textContent = 'Looking for places around this part of the map…';
      if (resultsBox) {
        resultsBox.replaceChildren();
        const message = document.createElement('p');
        message.textContent = 'Finding nearby places…';
        resultsBox.appendChild(message);
      }
      try {
        const places = await fetchPlaces(lat, lng, radius);
        layer.clearLayers();
        Object.keys(markerById).forEach(function (key) { delete markerById[key]; });
        places.forEach(function (place) {
          const marker = L.marker([place.lat, place.lng], { icon: icon, title: place.name })
            .bindPopup(popupContent(place)).addTo(layer);
          markerById[place.id] = marker;
        });
        renderList(places);
        if (status) status.textContent = places.length
          ? places.length + ' nearby places found. Move or zoom the map to see more.'
          : 'No named places found nearby. Pan the map or try a different location.';
      } catch (error) {
        lastCentre = null;   // so the next automatic or manual search tries again
        if (status) status.textContent = 'We couldn\u2019t load nearby places just now. Please try again in a minute, or when you\u2019re back online.';
        if (resultsBox) {
          resultsBox.replaceChildren();
          const message = document.createElement('p');
          message.textContent = 'Nearby places couldn’t be loaded just now. Your stays and attractions are still shown. Press the button again in a minute.';
          resultsBox.appendChild(message);
          const attribution = document.createElement('p');
          attribution.className = 'osm-attribution';
          attribution.textContent = 'Map data © OpenStreetMap contributors.';
          resultsBox.appendChild(attribution);
        }
      } finally {
        busy = false;
        if (button) {
          button.disabled = false;
          button.textContent = 'Refresh nearby places';
        }
        if (pendingRefresh) {
          pendingRefresh = false;
          window.setTimeout(function () { searchNearby(false); }, 250);
        }
      }
    }

    if (button) {
      button.dataset.originalLabel = button.textContent.trim() || 'Refresh nearby places';
      button.addEventListener('click', function () { searchNearby(true); });
    }
    map.on('moveend', function () {
      if (moveTimer) clearTimeout(moveTimer);
      moveTimer = setTimeout(function () {
        // Only search on map movement once a search was asked for (button or auto-fetch), not on every pan.
        if (options.autoFetch === true || lastCentre || needsSearchWhenVisible) searchNearby(false);
      }, 1200);
    });
    if (options.autoFetch === true) {
      window.setTimeout(function () { searchNearby(false); }, 500);
    }
  };
})();
