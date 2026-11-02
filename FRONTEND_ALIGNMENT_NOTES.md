# StayExplore Kenya — PHP flow alignment

The PHP application is the live/server-rendered version and uses the `smart_accommodation` MySQL database. The folder `StayExplore_Kenya_Authenticated_Frontend/` remains a standalone HTML reference demo and stores its demo data in browser local storage; it is not the PHP backend.

## Flow fixes in this pass

- Successful login now routes by role: tourists go to accommodation discovery, hosts go to the host dashboard, and admins go to the admin dashboard.
- If a user tries to open a protected page while signed out, the login redirect remembers the requested page. After login, it returns there only when the page belongs to that user's role area.
- If a signed-in user tries to open another role's protected page, they are returned to the correct home for their own role instead of being sent to a generic homepage.
- `register.php?role=host` keeps Host preselected.
- Added a PHP attraction-detail page (`tourist/attraction.php`) with links to nearby stays; attraction cards now offer both “Explore Attraction” and “Find Nearby Stays”.
- Added `mailbox.php` as a PHP explanation of real email verification and `resend-verification.php` to request a new verification link.
- Added tourist and host Messages entry points so the navigation does not lead to a missing page.

## Frontend page → PHP route mapping

| Frontend page | PHP route |
|---|---|
| `index.html` | `index.php` |
| `login.html` / `register.html` | `login.php` / `register.php` |
| `search.html` | `tourist/search.php?type=accommodation` or `?type=attraction` |
| `details.html` | `tourist/details.php?id=...` |
| `attraction-details.html` | `tourist/attraction.php?id=...` |
| `book.html` / `confirmation.html` / `bookings.html` / `payment.html` | `tourist/book.php` / `tourist/confirmation.php` / `tourist/bookings.php` / `tourist/payment.php` |
| `favourites.html` / `profile.html` / `review.html` / `report.html` | `tourist/favourites.php` / `tourist/profile.php` / `tourist/review.php` / `tourist/report.php` |
| `host-dashboard.html` / `host-manage-listings.html` / `host-manage-bookings.html` | `host/dashboard.php` / `host/listings.php` / `host/bookings.php` |
| `host-add-listing.html` / `host-edit-listing.html` / `host-availability.html` | `host/add_listing.php` / `host/edit_listing.php?id=...` / `host/availability.php` |
| `host-payments.html` / `host-reviews.html` / `host-report-incident.html` / `host-profile.html` | `host/payments.php` / `host/reviews.php` / `host/report.php` / `host/profile.php` |
| `admin-dashboard.html` / `admin-listing-approvals.html` / `admin-manage-users.html` | `admin/dashboard.php` / `admin/listings.php` / `admin/users.php` |
| `admin-moderate-reviews.html` / `admin-incident-reports.html` / `admin-attractions.html` / `admin-profile.html` | `admin/reviews.php` / `admin/reports.php` / `admin/attractions.php` / `admin/profile.php` |
| `confirm-email.html` / `mailbox.html` | `verify.php` / `mailbox.php` |

## Important limitations

- The HTML demo's messages are a browser-only preview. The current SQL schema has no messages table, so the PHP Messages pages are entry points explaining this limitation; they do not send or store messages yet. To implement real messaging, add a messages table and server-side send/read logic.
- Email verification uses real SMTP in PHP, not the HTML demo mailbox. Configure `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`, and `APP_BASE_URL` in the PHP/Apache environment. `.env.example` is only a template; PHP does not automatically load it.
- The PHP database schema was not changed by these navigation fixes. Keep your existing database backup.

## Run locally

1. Extract the whole folder into `C:\xampp\htdocs\smart_accommodation`.
2. Start Apache and MySQL in XAMPP.
3. Confirm the `smart_accommodation` database exists in phpMyAdmin.
4. Open `http://localhost/smart_accommodation/`.
5. Sign in with a verified account. Tourist, host and admin pages are protected by role as intended; a tourist cannot open host/admin pages and vice versa.
