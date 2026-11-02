# OpenStreetMap nearby places and attraction review

## What was added

- `assets/js/osm-nearby.js` provides opt-in nearby-place markers on the home, search, accommodation details, and booking confirmation maps.
- Live markers are separate from the attraction records already stored in MySQL. They are labelled as unverified OpenStreetMap results and are never automatically treated as approved attractions.
- The admin page `admin/osm_attractions.php` searches OpenStreetMap via Overpass, lets an administrator select candidates, stores them in a pending queue, and provides Approve/Reject actions.
- Approving a candidate adds it to the existing `attractions` table. The admin can then use Manage Attractions to complete its description, location, fee, and photo.
- The import queue table is created automatically when an administrator opens the discovery page. An optional SQL definition is provided in `database/osm_attraction_imports.sql`.

## How to test in XAMPP

1. Extract the project into `C:\xampp\htdocs\smart_accommodation` (or your existing project folder).
2. Start Apache and MySQL and open the site through `http://localhost/smart_accommodation/`.
3. Open a map and click **Show nearby OpenStreetMap places**. The browser needs internet access. The button prevents repeated automatic requests; successful results are cached in the browser session for ten minutes.
4. Sign in as an administrator and open **Manage Attractions**, then choose **Discover attractions from OpenStreetMap**.
5. Search around a point, select candidates, and click **Import selected for review**. The imported candidates appear as Pending.
6. Review the names, categories, coordinates, and source tags. Approve only appropriate places, or reject them. Approved places enter the attraction catalogue; rejected places do not.
7. Use Manage Attractions to complete the approved attraction details and add a photo if needed.

## Notes and limitations

- Public Overpass endpoints are shared community services with rate limits and occasional downtime. The feature reports an error instead of breaking the rest of the site; retry later if unavailable.
- OpenStreetMap data can be incomplete or out of date. Administrator review is required before imported places enter the catalogue.
- Map tiles and data require visible OpenStreetMap attribution. Do not remove the attribution text.
- The discovery search currently focuses on named attractions, historic places, parks, beaches, museums, viewpoints, galleries, zoos, restaurants, and cafes. It is a candidate finder, not a guarantee that every result is a tourist attraction.
- Live markers do not carry the website's verification status or reviews. Those belong to the application's own MySQL records.

## Admin map page: place types and Add attraction (v9.2)

- `admin/osm_attractions.php` now has a **Type of place** dropdown (restaurants & cafés, museums, galleries & theatres, parks, beaches, historical sites, viewpoints, waterfalls/peaks, zoos, entertainment, camp sites, places of worship, shopping & markets, or all types). The chosen type is turned into an Overpass query for named places within the chosen distance; results are sorted nearest first.
- Click an orange dot (or the **Add attraction** button on a result card) to open Manage Attractions with the name, category, area and exact position already filled in. Clicking an empty spot on the map places the pin and offers **Search around here** and **Add attraction here**. **+ Add attraction at the pin** does the same for the current pin.
- If you placed the pin by hand, **Find places** searches around that pin; the area name is only looked up when you have not placed one (or after you type a new area name).
- `admin/attractions.php` accepts `?add=1&name=&category=&location=&lat=&lng=` to prefill the add form. Invalid coordinates are ignored.
- Imported places use the same category names as Manage Attractions, so the category is pre-selected.

## Duplicate protection (v9.3)
- Saving a **new** attraction is refused when one with the same name (any capitalisation) already exists within about 200 m. The message links to the existing attraction so you can edit it instead.
- On the admin OpenStreetMap page, results that are already in your attractions get a green dot, an "Already in your attractions" tag and an **Edit attraction** button instead of **Add attraction**. They are also skipped by "Add to review list".
- The tourist "Show nearby places" button (assets/js/osm-nearby.js) now retries when the free Overpass service is busy and no longer remembers failed searches.
