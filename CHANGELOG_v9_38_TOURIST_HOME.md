# v9.38 - Tourist homepage and navigation (UI only)

No business logic, database or existing page was changed. Discover, Attractions, filters, ranking, distances, listings and booking flows are untouched.

## What changed
1. **Landing page** - `roleHome('tourist')` (includes/auth.php) now returns `index.php`, so a traveller lands on the homepage right after signing in (and after email verification). A saved "return to the page you were on" link still wins, as before.
2. **Map preview** - new section on `index.php` (after the hero): small interactive Leaflet map with stay and attraction pins (same pins as the Discover map), All / Attractions / Stays chips, and an **Expand map** button that opens the same map full screen (Esc or Close map to return).
3. **Two buttons** - **Explore Stays** and **Discover Attractions** sit beside/under the map (and in the expanded map). They show no counts.
4. **Optional destination** - the box, suggestions (built-in places, your attractions and stays, OpenStreetMap) and "Use my location" are optional. With no destination the buttons open the normal Discover pages. With one, it is passed to the existing `tourist/search.php` (`location=`, `attraction_id=`, `stay_id=`, `search_lat/search_lng`, `lat/lng`, `radius`), which does all matching and ranking.
5. **Popular attractions** - the "insight" box is removed; the words sit on the photo, with only the bottom of the picture shaded.
6. **Top bar (travellers)** - logo, Discover, Attractions, Where to go, My bookings; icons for messages, notifications, favourites and an account menu (profile, favourites, messages, support, how it works, about, language, currency, dark mode, sign out). `includes/tourist_nav.php` is used by `includes/header.php` (all /tourist/ pages, messages) and by `index.php`.
7. **Phone drawer** - logo on top, then Explore, Your account, Help, Preferences and Sign out, with a dimmed backdrop, scroll lock, Esc to close and focus kept inside.
8. Styling is forest green + neutrals, light and dark, responsive.

## Files
New: `includes/tourist_nav.php`, `assets/css/tourist-nav.css`, `assets/css/tourist-home.css`, `assets/js/tourist-nav.js`, `assets/js/home-map.js`, `i18n/sw_g.txt`.
Edited: `includes/auth.php`, `includes/header.php`, `index.php`, `assets/css/home.css` (old attraction-card override removed), `assets/js/se-i18n-sw.js` (rebuilt with `tools/build_sw_wordlist.py`).
Hosts, admins and signed-out visitors keep their existing headers.
