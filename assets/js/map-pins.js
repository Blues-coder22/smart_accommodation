/* Map pins: green house pin = stay, terracotta star pin = attraction, amber dot = nearby place, blue dot = you.
 *   SEPins.stay() / .attraction() / .place() / .me()   -> Leaflet icons
 *   SEPins.legend(map, ['stay','attraction','place'])   -> small key in the corner of the map
 */
(function () {
  'use strict';
  var css = document.createElement('style');
  css.textContent =
    '.se-pin{background:none!important;border:0!important}' +
    '.se-pin span{display:grid;place-items:center;width:30px;height:30px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:2px solid #fff;box-shadow:0 3px 9px rgba(0,0,0,.4);transition:transform .25s}' +
    '.se-pin i{transform:rotate(45deg);font-style:normal;color:#fff;font-size:14px;line-height:1}' +
    '.se-pin:hover span{transform:rotate(-45deg) scale(1.15)}' +
    '.se-pin.stay span{background:#0d3b2e}.se-pin.attraction span{background:#c8562f}' +
    '.se-pin.place span{background:#d99a1f;width:20px;height:20px}.se-pin.place i{font-size:10px}' +
    '.se-legend{background:rgba(255,253,248,.96);border:1px solid #d9d5ca;border-radius:10px;padding:8px 12px;font:600 12px Arial,sans-serif;color:#1d241f;box-shadow:0 2px 8px rgba(0,0,0,.18);line-height:1.9}' +
    '.se-legend b{display:inline-block;width:12px;height:12px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);margin-right:8px;vertical-align:-1px;border:1px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.15)}' +
    '.se-legend div{white-space:nowrap}' +
    '.leaflet-popup-content-wrapper{border-radius:12px}.leaflet-popup-content{font-family:Arial,sans-serif;font-size:13px;line-height:1.5}' +
    '.leaflet-popup-content a{color:#0d3b2e;font-weight:700}';
  document.head.appendChild(css);

  function make(kind, glyph, size) {
    return L.divIcon({ className: 'se-pin ' + kind, html: '<span><i>' + glyph + '</i></span>',
      iconSize: size, iconAnchor: [size[0] / 2, size[1]], popupAnchor: [0, -size[1] + 4] });
  }
  var LEGEND = { stay: ['#0d3b2e', 'Stays'], attraction: ['#c8562f', 'Attractions'], place: ['#d99a1f', 'Nearby places'] };

  window.SEPins = {
    stay:       function () { return make('stay', '⌂', [30, 36]); },
    attraction: function () { return make('attraction', '★', [30, 36]); },
    place:      function () { return make('place', '•', [20, 26]); },
    me:         function () { return L.divIcon({ className: '', html: '<div style="width:16px;height:16px;border-radius:50%;background:#2563eb;border:3px solid #fff;box-shadow:0 0 0 2px rgba(37,99,235,.35)"></div>', iconSize: [16, 16], iconAnchor: [8, 8] }); },
    legend: function (map, kinds) {
      var Ctl = L.Control.extend({
        options: { position: 'bottomleft' },
        onAdd: function () {
          var d = L.DomUtil.create('div', 'se-legend');
          (kinds || ['stay', 'attraction']).forEach(function (k) {
            var row = document.createElement('div'), dot = document.createElement('b');
            dot.style.background = LEGEND[k][0]; row.appendChild(dot); row.appendChild(document.createTextNode(LEGEND[k][1])); d.appendChild(row);
          });
          return d;
        }
      });
      return new Ctl().addTo(map);
    }
  };
})();
