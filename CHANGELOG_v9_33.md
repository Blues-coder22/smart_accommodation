# StayExplore Kenya v9.33: booking workflow, host-paid commission, shared UI, mobile, dark mode, performance

Upgrade from `smart_accommodation_v9_32.zip`. Same PHP + MySQL architecture, same tables and relationships; nothing was removed.
Read **"Install / upgrade"** and **"Known issues"** before going live.

## 1. Install / upgrade

1. **Back up** the database and the `uploads/` and `private_uploads/` folders.
2. Copy the new files over the old project. **Keep your own `includes/config.php`** (this zip's copy is unchanged from v9.32).
3. Run the migration (safe to repeat, never deletes data):
   `php database/migrate_v9_33.php`
   If you cannot use the command line, just open any page: the app runs the same upgrade on its first request.
   `database/migration_v9_33.sql` is the hand-run SQL equivalent (run once, on an un-upgraded database, then run the PHP script).
   After restoring a database backup, delete `logs/.schema_v933` so the upgrade runs again.
4. **Schedule the maintenance job** (expires unanswered requests and unpaid accepted bookings, sends payment reminders, completes finished stays, issues commission invoices, publishes reviews):
   - Linux: `crontab -e`, add `*/10 * * * * php /path/to/smart_accommodation/cron/maintenance.php >> /path/to/smart_accommodation/logs/cron.log 2>&1`
   - Windows/XAMPP: Task Scheduler, every 10 minutes: `C:\xampp\php\php.exe C:\xampp\htdocs\smart_accommodation\cron\maintenance.php`
   Without cron the site still works: pages run the same job as a safety net (at most every 5 minutes). With cron running, pages skip it.
5. Recommended PHP settings (OPcache, uploads): `docs/php-recommended.ini`. PHP needs the `gd` extension for image compression (and `exif` for phone-photo rotation).
6. Log in as each host once: every host must **accept the updated host agreement** (it now describes the host-paid commission) before adding listings; the admin cannot approve a listing whose host has not accepted it.
7. In **Admin > Revenue and commission** check: commission rate, "How hosts pay you" text, payment deadline (12 / 24 / 48 hours; default 24).
8. Hosts should fill **"How guests pay you"** (M-Pesa till/paybill or bank details) on their profile. A guest sees it only after the host accepts.

## 2. Booking workflow (Part 5)

`pending` (guest asked, no payment requested) -> `accepted` (host accepted; guest has 24 h to pay the host directly) -> `payment_pending` (guest reported a payment) -> `confirmed` (**the host confirmed the money arrived**) -> `completed`. `cancelled` covers declined, cancelled and expired. `no_show` is kept.

- One module owns every status change: `includes/booking_workflow.php`. It checks the actor's role and ownership, the allowed-transition table, locks the booking row (`SELECT ... FOR UPDATE`), updates with a compare-and-set on the old status, and writes an audit event. Pages no longer set status directly.
- The payment page opens only for `accepted` bookings inside the deadline. Forging a POST, amount, status or role does nothing (tested).
- Accepting re-checks the dates under a lock on the listing. `pending`, `accepted`, `payment_pending` and `confirmed` all hold the dates; cancelling or expiring releases them.
- Unanswered requests expire after the host-reply window (48 h). Accepted bookings not paid in time are cancelled and the dates released. Guests get a reminder in the last 3 hours before the deadline.
- Payment references are validated on the server (M-Pesa code format, bank reference length) and one reference cannot be reused on a second booking. Cash on arrival was removed for new bookings because it cannot be verified before the deadline.
- If the host says "Not received", the guest is told why and can send details again. The host can also reverse a confirmed payment (the booking returns to `payment_pending`).
- Cancellation and refunds: guests and hosts cancel through the same checked transitions. The platform never holds accommodation money, so refunds are between guest and host; the cancellation text says so. The legacy platform-share refund logic is kept for old bookings.
- Migration: old `pending` bookings that already had payment details became `payment_pending`; all other bookings kept their status.

## 3. Commission (Part 6)

**Guests pay the host directly; the host pays StayExplore Kenya separately, by invoice.**

- `commissionMode()` now always returns `invoice` for new bookings; the guest service fee is set to 0. Old bookings keep the `commission_mode`, amounts and receipts they were created with (verified).
- Commission becomes payable only when the stay is **completed** and the **host confirmed** the guest's payment. Rejected, unpaid, cancelled, no-show and expired bookings never get an invoice.
- Invoices are real records (`commission_invoices`, numbers like `SEK-2026-00001`, due N days after check-out). A printable invoice page is at `host/commission_invoice.php`; a host can open only their own invoices.
- Host Commission page: how it works, commission earned, outstanding (and overdue), pending verification, confirmed payments, invoices, payment-reference form, payment history. Host dashboard shows the same four figures.
- Admin Revenue page: total generated, outstanding, awaiting verification, confirmed, overdue invoices, per-host report, overdue list, transaction history, accept/reject with a note. Admin dashboard shows a summary.
- Guests: nothing about host commission appears on guest pages, notifications or emails (checked automatically). The shared booking record shows commission only to the host and admin.
- Host wording rewritten everywhere (Commission page, bookings, statement, agreement v2, emails): "Guests pay you directly for their accommodation. Your agreed platform commission is payable separately to StayExplore Kenya after eligible completed stays."

## 4. UI, mobile, dark mode, CSS (Parts 1-4, 8)

- New shared layer `assets/css/ui.css` + `assets/js/ui.js`, loaded on every page: one page header (the 6 conflicting `.page-title` definitions are gone), alerts, status pills, buttons, forms, tabs, empty and loading states, and the responsive system.
- **One breakpoint set: 1024 / 768 / 480** (16 different widths were merged). One documented exception: the signed-in tourist header collapses to the menu button below 1260 px, because it does not fit tablets and small laptops (it used to wrap links onto 2-3 lines and cut off Sign out).
- Phones: stat cards in two columns; every table becomes stacked cards (`ui.js` labels cells; add class `no-stack` to opt out); search filters collapse behind a "Filters" button; tabs scroll; buttons and inputs at least 44 px with 16 px text; page widths standardised (`.page-wrap`, `.page-wrap.narrow`).
- Dark mode: no second theme system. About 770 hardcoded colours became tokens whose light values equal the old colours (light mode is unchanged); `dark.css` supplies the dark values. Status badges, the homepage variable set, map controls and popups were fixed. Switching is instant, persists across pages and is the same for tourist, host and admin.
- Removed 125 dead and 61 duplicated per-page CSS rules.

## 5. Performance (Part 7)

Measured on a seeded database of 6,000 listings / 12,000 bookings, original v9.32 code vs this version (same machine, PHP built-in server):

| Measure | v9.32 | v9.33 |
|---|---|---|
| Nearby-stays query (median of 7) | 8.4 ms | 0.7 ms |
| SQL statements, search page | 111 | 8 |
| SQL statements, host bookings page (12,000 bookings) | 24,041 | 30 |
| Search results page HTML | 253 KB (100 cards) | 73 KB (12 cards, paged) |
| Schema-check/DDL statements per page | 1-16 | 0 |
| 4000x3000 phone photo upload | 11.3 MB stored | 673 KB + 40 KB thumbnail |

What changed: bounding-box pre-filter and new indexes (checked before adding); schema upgrades run once instead of per request (this also fixed a real bug: DDL inside a transaction silently committed it); maintenance moved to `cron/maintenance.php`; search and host bookings paginated, photo lookups batched; Leaflet is vendored locally (no CDN) and loaded only when a map is needed (search map: on "View map"); lazy-loaded images; uploads compressed with thumbnails; `.htaccess` adds compression and caching; OPcache guidance in `docs/`.
Page-level time on a single local request changed little (the page was already ~15 ms locally), so the gains are in database work, transfer size and scaling with data.

## 6. Security and integrity (Part 10)

Checked and kept: PDO prepared statements, CSRF on state-changing posts, role guards, upload validation, private proof storage. Added: central transition validation, server-side payment and reference validation, commission invoices computed server-side only, `.htaccess` deny rules for `includes/`, `database/`, `logs/`, `tools/`, `cron/` and for `.log/.sql/.env` files, audit events for every status change.

## 7. Testing results (what was actually run)

Environment: PHP 8.3, MariaDB 10.11, Firefox (headless, Selenium), PHP built-in server. Seeded with v9.32-shaped legacy data.

- **PHP syntax:** every `.php` file passes `php -l`.
- **Workflow and commission suite: 52/52 pass** (HTTP, three roles): request -> accept -> pay -> confirm -> complete -> invoice -> host reports payment -> admin verifies; decline; expiry releases dates; reminder sent once; payment rejected and retried; guest pages and notifications contain no commission; commission page wording; invoice ownership; two simultaneous requests for the same dates give exactly one booking; illegal transitions refused.
- **Security and edge suite: 24/24 pass:** missing CSRF, wrong role, other guest/host access, forged amount/status, short and reused payment references, cash refused, over-reporting commission, admin rejection, unanswered-request expiry, migration idempotence, legacy rows unchanged.
- **Mobile/desktop layout:** 7 widths (320, 375, 390, 430, 768, 1024, 1366) x 9 tourist, 8 host, 9 admin pages: no horizontal overflow (before: 3 guest page/width combinations overflowed at 320/375, e.g. booking panel and bookings tabs). Touch targets under 40 px at 375 px on 12 pages: 74 -> 6 (inline text links). Several pages checked visually in light and dark.
- **Dark mode:** automated check of light patches and low-contrast text on tourist, host, admin and public pages: clean after fixes (found and fixed: unmapped light backgrounds, badge colours, homepage variables, hero button). Live switch, persistence across pages and roles: passed.
- **Maps:** search, details, attraction, confirmation, host add listing, admin attractions and OSM import: Leaflet loads on demand, zero JavaScript errors (tiles themselves could not be fetched in the offline test environment).
- **Migration:** run twice (idempotent); manual SQL tested on a v9.32-shaped database.

## 8. Not tested / known issues

- Not tested: real email/SMS delivery, email and SMS verification flows, registration, image upload through the browser form (the optimiser was tested directly), review moderation and incident handling beyond page loads, attraction create/edit, real phones or Safari/Chrome (only Firefox), load beyond the two-request race, live OpenStreetMap tiles, Windows Task Scheduler, OPcache effect.
- **CSS consolidation is partial.** The shared layer, tokens, breakpoints and dark-mode fixes are done, but the 38 role pages still carry their own inline `<style>` blocks (reduced, not removed) and separate `tourist.css / host.css / admin.css` files were not extracted. About 740 hardcoded colours remain (emails, receipts, charts, JS, gradients), and `dark.css` still contains older `!important` patches.
- Hosts and the admin see one extra Revenue/Commission summary query set (host dashboard +4 queries).
- `includes/config.php` (unchanged) contains SMTP credentials in plain text. Rotate them and move them to environment variables.
- Bookings made before v9.33 under the guest-pays-platform-share arrangement still show that second payment when accepted, so they keep their original terms.
- Only the seeded states were exercised on every page; unusual data (very long names, hundreds of amenities) may still need adjustment.

## 9. Files

**New (15):**
- `.htaccess`
- `assets/css/ui.css`
- `assets/js/leaflet-loader.js`
- `assets/js/ui.js`
- `cron`
- `database/.htaccess`
- `database/migrate_v9_33.php`
- `database/migration_v9_33.sql`
- `docs`
- `host/commission_invoice.php`
- `includes/.htaccess`
- `includes/booking_workflow.php`
- `includes/maintenance.php`
- `tools/.htaccess`
- `assets/vendor/leaflet/ (Leaflet 1.9.4, served locally)`

**Modified (99):**
- `admin/attraction_reviews.php`
- `admin/attractions.php`
- `admin/bookings.php`
- `admin/certificates.php`
- `admin/currencies.php`
- `admin/dashboard.php`
- `admin/evidence.php`
- `admin/listings.php`
- `admin/osm_attractions.php`
- `admin/payments.php`
- `admin/photos.php`
- `admin/profile.php`
- `admin/reports.php`
- `admin/revenue.php`
- `admin/revenue_report.php`
- `admin/reviews.php`
- `admin/setup.php`
- `admin/users.php`
- `assets/css/a11y.css`
- `assets/css/attractions.css`
- `assets/css/dark.css`
- `assets/css/finance.css`
- `assets/css/home.css`
- `assets/css/static-pages.css`
- `assets/css/style.css`
- `booking_record.php`
- `certificate.php`
- `forgot-password.php`
- `host/add_listing.php`
- `host/agreement.php`
- `host/availability.php`
- `host/bookings.php`
- `host/commission.php`
- `host/dashboard.php`
- `host/edit_listing.php`
- `host/listings.php`
- `host/payments.php`
- `host/profile.php`
- `host/rate_guest.php`
- `host/report.php`
- `host/reviews.php`
- `host/statement.php`
- `how-hosting-works.php`
- `includes/alerts.php`
- `includes/attraction_content.php`
- `includes/attraction_reviews.php`
- `includes/booking_calendar.php`
- `includes/booking_events.php`
- `includes/booking_holds.php`
- `includes/booking_ref.php`
- `includes/booking_rules.php`
- `includes/certificates.php`
- `includes/commission.php`
- `includes/commons_photos.php`
- `includes/currency.php`
- `includes/db.php`
- `includes/delete_account_card.php`
- `includes/email.php`
- `includes/functions.php`
- `includes/gallery.php`
- `includes/guest_reputation_card.php`
- `includes/host_verification.php`
- `includes/login_throttle.php`
- `includes/media_store.php`
- `includes/messages_page.php`
- `includes/notifications.php`
- `includes/onsite_experience.php`
- `includes/payment_audit.php`
- `includes/profile_functions.php`
- `includes/profile_photo.php`
- `includes/review_extras.php`
- `includes/sms.php`
- `includes/upload_helpers.php`
- `includes/verify_wait.php`
- `index.php`
- `login.php`
- `mailbox.php`
- `notifications.php`
- `platform_receipt.php`
- `receipt.php`
- `register.php`
- `resend-verification.php`
- `reset-password.php`
- `tourist/attraction.php`
- `tourist/book.php`
- `tourist/bookings.php`
- `tourist/choose_destination.php`
- `tourist/confirmation.php`
- `tourist/details.php`
- `tourist/favourites.php`
- `tourist/payment.php`
- `tourist/profile.php`
- `tourist/report.php`
- `tourist/review.php`
- `tourist/search.php`
- `verify.php`
- `verify_certificate.php`
- `verify_wait.php`
- `view_profile.php`
