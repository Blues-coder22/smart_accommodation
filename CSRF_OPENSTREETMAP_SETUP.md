# CSRF protection and OpenStreetMap integration

This package combines the latest navigation/map fixes with the CSRF-protected forms and OpenStreetMap discovery workflow.

## CSRF protection

- `includes/functions.php` defines `generateCsrfToken()`, `validateCsrfToken()`, and `requireCsrf()`.
- State-changing POST forms include a hidden `csrf_token` field.
- Relevant POST handlers call `requireCsrf()` before processing form data.
- If a token is missing or invalid, the request is rejected with HTTP 403. Reload the form page if a token expires or the session changes.

## OpenStreetMap

- `assets/js/osm-nearby.js` provides nearby-place discovery on the home, search, accommodation-details, and booking-confirmation maps.
- Live results are marked as unverified and are separate from approved MySQL attractions.
- Admins can open **Manage Attractions → Discover attractions from OpenStreetMap** to search an area and import selected candidates into a pending review queue.
- Admin approval adds a candidate to the attraction catalogue; rejection prevents it from being included.
- OpenStreetMap map tiles and data require attribution. The public Overpass endpoints may have rate limits or temporary outages.

## Run locally

1. Back up the existing project folder.
2. Extract this project into `C:\xampp\htdocs\smart_accommodation` (avoid creating a nested `smart_accommodation\smart_accommodation` folder).
3. Keep using the existing MySQL database. Do not import the schema a second time unless necessary.
4. Start Apache and MySQL in XAMPP.
5. Open `http://localhost/smart_accommodation/`.
6. Test the relevant forms while logged in, and test the maps with internet access.

## Important testing note

PHP syntax checks do not prove that all pages work against your specific database. Test registration/login, booking, upload, admin approval, and map discovery locally before relying on the project for assessment or deployment.
