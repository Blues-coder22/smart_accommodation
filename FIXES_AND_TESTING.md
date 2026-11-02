# Smart Accommodation — Homepage, Search and OpenStreetMap Fixes

## What changed

- The public homepage (`index.php`) now follows the original `StayExplore_Kenya_Authenticated_Frontend/index.html` marketing layout and stylesheet.
- The homepage map has been removed. The **View stays** and **View attractions** buttons open the PHP search page, where the map and live nearby-place discovery are displayed.
- The homepage loads approved accommodation records from MySQL and shows featured listings when they exist. It shows a clear empty state instead of inventing bookable properties when there are no approved stays.
- General stay searches now query all approved listings rather than silently limiting results to a 20 km circle around Nairobi. Location-based searches use coordinates and the selected radius.
- The Overpass request body is sent using URL-encoded `data=` format, a second public endpoint is tried if the first fails, successful results are cached for 10 minutes, and live results are displayed in a separate list and marker layer.
- Live OpenStreetMap results are explicitly labelled unverified. They do not become approved/bookable listings unless an administrator imports and approves them.
- The root-level HTML informational pages are now `how-it-works.html`, `help.html`, `privacy.html`, `contact.html`, `about.html`, and `terms.html`. Their links lead back to the PHP application's actual routes. The contact page opens an email to the support address instead of pretending a message was sent by a frontend-only demo form.
- Mobile navigation toggles are included on the homepage and informational pages.
- Existing CSRF protection, uploads, and host/admin PHP dashboards have been preserved.

## Important database note

The SQL dump supplied with the project has no accommodation rows. Therefore, the homepage and search page cannot show actual bookable stays until a host creates a listing and an administrator approves it. This build intentionally does not create fake approved properties.

## Local test checklist

1. Back up the existing `smart_accommodation` folder.
2. Extract this archive so the project folder is `C:\\xampp\\htdocs\\smart_accommodation\\`.
3. Start Apache and MySQL, then keep using the existing `smart_accommodation` database.
4. Open `http://localhost/smart_accommodation/index.php`.
5. Click **View stays** and **View attractions**. The map should be on the search pages, not the homepage.
6. Search for a location such as `Diani` or `Nanyuki`. With internet access, the page attempts to geocode the location and display nearby OpenStreetMap results.
7. Test a host listing, approve it in the admin dashboard, and confirm it appears under Featured Stays and in the stays search.

OpenStreetMap/Overpass is a public service and can temporarily be unavailable or rate-limited. Its live results are discovery suggestions, not verified listings. Keep the visible OpenStreetMap attribution and respect the service's usage policy.
