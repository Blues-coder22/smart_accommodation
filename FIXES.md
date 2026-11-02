# StayExplore Kenya - fixes applied

## To use these changes (do these once)
1. **Database:** in phpMyAdmin open database `smart_accommodation` > SQL and run these files once each (all are safe to run twice):
   `database/migration_001_fixes.sql`, `database/migration_messaging.sql`, `database/migration_account_closure.sql`, `database/migration_account_deletion.sql`, `database/migration_002_features.sql`,
   and `database/osm_attraction_imports.sql` if you use the OpenStreetMap importer.
2. **Email:** copy `includes/config.example.php` to `includes/config.php` and fill in `SMTP_USERNAME` and `SMTP_PASSWORD`
   (see `SETUP_LOGIN_AND_EMAIL.md`). Until then every email is written to `logs/mail.log` instead of being sent.
3. **Uploads:** `uploads/{accommodations,profiles,evidence,attractions}` exist; listing photos you add are saved there.

## What was fixed
| Area | Fix |
|---|---|
| Availability calendar | Unique key on (listing, date): saving no longer creates duplicate rows. Host-blocked nights are now respected when booking, searching and in the date picker. |
| Favourites / payments | Unique keys stop duplicate rows (double click). One payment per booking. |
| Payments and booking | The tourist submits payment details (method and reference); the booking stays **pending** and the host gets an email. The host then **confirms or declines the booking** (Host > Bookings). The payment stays pending until the host clicks **Mark as paid** (Host > Payments; **Mark unpaid** undoes it, and cancelled bookings can't be changed). Dates stay held while a booking is pending or confirmed. |
| Booking | Dates validated (no past dates, no reversed or 0-night stays, guests within the limit). Booking is created inside a locked transaction so two guests cannot take the same nights. |
| Search | Dates, guests and a new Amenities filter now work. Average rating was skewed by a join that multiplied rows - fixed. A listing exactly at the search point (distance 0) was hidden by an acos() rounding error - fixed. |
| Reviews | Sealed reviews are now published automatically once the host has reviewed or 14 days after check-out (flagged reviews still wait for an admin). A crash after submitting a flagged review (missing email include) is fixed. |
| Incident reports | A tourist can only report an incident about a stay they booked, and only critical types suspend the listing (before, any tourist could suspend any listing). The form now has a stay picker. |
| Uploads | File type comes from the real image content, never the file name (an upload called `x.php` can no longer be saved as PHP). `uploads/.htaccess` blocks script execution. Three image tags with a line break inside `src` (broken photos) fixed. |
| Security | SMTP password moved out of the source. CSRF token checked on every POST (this version's `requireCsrf()`). Session id renewed at login. Cookies HttpOnly + SameSite. Output escaped everywhere through `h()`. |
| Messages | When a booking is refused (dates just taken, too many guests) the reason is now shown on the stay page; messages on My bookings and the host Payments page also show. |
| Text display | Apostrophes and ampersands ("Karen's Cottage & Garden") showed as `&#039;` and got worse on every edit. `h()` in `includes/helpers.php` fixes this. |

## Not done (features, not errors)
(Updated: password reset, host ID verification, the host-reviews-the-guest half of two-way reviews and the admin review-risk panel are now built; see the last sections.)
Host ID verification, messaging, and the admin review-risk panel from the HTML prototype are still not ported.
The host-reviews-the-guest half of the two-way review is not built: reviews reveal on the 14-day rule or when
`host_submitted` is set, but nothing in the host area sets it yet.

## Merged into the newer zip
This version keeps the newer zip's own features: OpenStreetMap importer and "Show nearby OpenStreetMap places" buttons,
messaging pages, role-based login redirects, `.html` info pages, `saveUploadedImage` uploads and the email config file.
The fixes above were added on top. My separate auto-loading live-places map was left out because the newer zip
already has its own nearby-places feature on the same pages.

## Permanent account deletion
Tourist and host profile pages have a **Delete account permanently** card (password + typing DELETE). It erases the
person's name, email, phone, photo, password, favourites and their own chat message texts for good; nobody can recover
or sign in to it, and the email address can be used to register again. The (now anonymous, "Deleted user") row is kept
so bookings, payments, reviews and reports that point at it stay valid and the rest of the database is not disturbed.
It is refused while the person has an upcoming or pending booking. A deleted host's listings are suspended, not removed.
Admins cannot restore or change a deleted account. This is separate from **Close account**, which can be reversed.

## Password reset
Login page > **Forgot your password?** (`forgot-password.php`, `reset-password.php`). The emailed link works once, expires after
60 minutes and only its hash is stored. The reply is the same whether or not the email is registered, an account can request at
most 3 links per hour, closed/deleted accounts get none, using a link voids the account's other links, and the owner is emailed
that the password changed. Until SMTP is configured the link is written to `logs/mail.log`.

## Host reviews the guest (two-way reviews)
After a completed stay a host can **Rate guest** (Host > Bookings): 1-5 stars and a comment, once per booking. Reviews stay sealed:
a guest's review is revealed as soon as the host has reviewed them, and a host's review of a guest is revealed once the guest has
reviewed the stay or 14 days after check-out. Hosts see a guest's average rating on each booking; guests see what hosts said on
their profile (**What hosts say about you**).

## Host ID verification
Host profile > **Host verification**: legal name, ID type/number, optional property proof and a photo of the ID. Administrators
review requests at the top of Admin > Users (they open the photo through `admin/verification_file.php`), then **Verify** or
**Reject with a reason** (the host is emailed). The ID number and photo are erased as soon as a decision is made, and also when
the host deletes their account. Photos are stored in `private_uploads/` (blocked from direct access). Verified hosts get a
"Verified host" badge on listing pages and search results; unverified hosts see a reminder on their dashboard.

## Admin review-risk panel
Admin > Reviews now shows a **Risk score (0-100)** with the reasons on every review, and a **High risk** tab (60+). Signals:
host reports, links/phone numbers, identical text, several reviews within minutes, very short or all-capitals text, a rating far
from the listing's average, and the existing anomaly score. It is a guide only; nothing is removed automatically.

## Verification wording, verification links and dashboard layout (latest pass)
- **"Verify account" vs "already verified":** these were two different checks sharing one word. Admin > Users showed *email* verification; the host dashboard nagged about *ID* verification. Admin > Users now has two columns, **Email** (verified / not verified) and **Host ID check** (verified / pending / rejected / not submitted). The host dashboard and profile now say "identity (ID) verification" and confirm the email is already verified.
- **Verification link opened an older dashboard:** the emailed link was built from `APP_BASE_URL` in `includes/config.php`, which still pointed at an older copy of the site (e.g. `/smart_accommodation`). Links now use the address of the copy that sent the email whenever the configured folder does not match. If you also want to fix it at the source, set `APP_BASE_URL` in `includes/config.php` to this copy's folder. `verify.php` now redirects inside this copy, and sends an already-signed-in user straight to their own dashboard.
- **Layout:** every host and admin page (including Add listing, Messages, Rate guest and the OpenStreetMap importer) now uses one shared shell, `includes/app_shell.php`, matching the frontend folder: green sidebar with grouped menu and Account links, and a slim top bar showing only the signed-in name and role. The public site header is no longer stacked on top of the sidebar, so the menu is not repeated. Hairline `0.5px` borders (which render as broken or missing lines on many screens) are now `1px`.

## v5.3 — profile photos, directions, amenities filter, fun attraction pages
- **Profile photos** are stored in the database (`users.profile_photo_data`) and served by `profile_photo.php`; header/top bar show the photo instead of the role word. Old files in `uploads/profiles/` are copied into the database on first view. (`database/migration_profile_photo.sql`, optional — columns are also added automatically.)
- **Directions** (`assets/js/directions.js`): 🧭 button on every map pin and on the map; routes from GPS/typed place/map click, driving/walking/cycling, turn-by-turn, live navigation, voice, Google Maps hand-off.
- **Amenities filter** on the tourist search page no longer stretches checkboxes (the "broken line").
- **Attraction pages** (`tourist/attraction.php`, search cards): animated hero, count-up stats, best-time month strip, "Did you know?" spotlight, "What's new" timeline (new stays nearby, recent ratings, season, admin posts), packing checklist, directions map, save-to-favourites and share. Styles in `assets/css/attractions.css`, behaviour in `assets/js/attractions.js`, content logic in `includes/attraction_content.php`.
  Admin > Manage attractions has new optional fields (tagline, best time, climate, landscape, best for, "Did you know?" facts, "What's new" updates). They are stored in a new table `attraction_extras` (created automatically; or run `database/migration_attraction_extras.sql`). Blank fields are filled with built-in facts for well-known places and general Kenya facts/tips.

## v5.5 — new homepage (index.php)
- Redesigned to match the supplied design: cream header, sliding hero images (real attraction photos from the database first, then built-in Kenya scenes), Featured stays and Popular attractions as sliding rows, host call-to-action, dark footer.
- The "Find accommodation / Explore attractions + where / dates / guests" search table was removed from the hero; two buttons replace it.
- Logged-in visitors see their profile photo, dashboard link and Sign out; tourists can save featured stays with the heart (same favourites table).
- "% match" on stay cards is a 70–99 ranking from rating, bookings and value for money.
- Files: `index.php`, `assets/css/home.css`, `assets/js/home.js`. The previous version is kept in the v5.4 zip.

## v5.6 — checked against your database dump
- Schema matches every query (users.profile_photo, accommodation_photos.is_primary, reviews.is_published, favourites.item_type, attractions.*).
- `h()` and `sanitize()` now cope with doubled HTML codes such as `cafe&amp;amp;#039;s` (your Karen House text), so apostrophes show correctly and stop re-encoding on every save.
- `database/update_for_current_db.sql`: photo columns, attraction_extras table, starter facts for Carnivore Restaurant and Ngong Reserve, and a cleanup of the doubled codes.

## v5.7 — logo, wording, map pins and shared look
- Homepage: green "SE" ring logo and the original wording restored ("Discover Kenya. Stay.Explore. Experience." and its paragraph, View stays / View attractions, original benefit and section captions).
- Map pins (`assets/js/map-pins.js`): green house pin = stay, terracotta star pin = attraction, amber dot = nearby place, with a key on the map. Used on search, stay details, attraction, booking confirmation, and the host/admin location pickers.
- Plain wording: removed technical and demo-style text (OpenStreetMap/Overpass/"unverified"/"coordinates"/"prototype"/"demo") from tourist, admin and information pages. The required "© OpenStreetMap contributors" map credit stays.
- Shared look and motion on every page: cream header, dark green footer, Instrument Serif + Work Sans, fade-up cards, hover lifts, counting numbers (`assets/css/style.css` end section, `assets/js/polish.js`, `assets/css/static-pages.css` for the information pages).
