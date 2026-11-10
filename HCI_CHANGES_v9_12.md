# v9.12 - accessibility and usability pass (PHP application)

Checked first, then fixed:
- Mobile menu: the shared header's menu button had no click handler on PHP pages (CSS waited for a class nothing added). Fixed in assets/js/polish.js, with Escape to close and aria-expanded/aria-controls.
- Text size: body 15px, form labels 13.5px (were 9-11px), table headings 12px, small text 13px. Appended at the end of assets/css/style.css.
- Keyboard focus: one clear 3px ring on links, buttons and form fields (the old rule removed the outline on fields). "Skip to main content" link added by polish.js.
- Form fields: 77 fields had a visible label or placeholder but no programmatic name; each now has an accessible name.
- Images: all 26 <img> tags in the PHP pages already had alt; two weak ones improved, and the admin Commons photo suggestions now describe themselves.
- Admin listings: Reject and Suspend now ask for confirmation naming the listing, and the reason is required (the empty reason boxes also hid their placeholder text).
- Admin ID verification: Reject asks for confirmation.
- Reports (guest and host): the confirmation now shows the report reference.

Checked and found already fine / not as described:
- how-it-works.html exists in this project and the header link works.
- Add/edit listing forms already group fields into sections (Basic details, Location, Amenities, House rules, Photos).
- Most destructive actions already had confirmations.

## Follow-up pass (static pages and review items)
- The six static pages (about, help, privacy, terms, contact, how-it-works) used the old frontend stylesheet and had no polish.js, so the v9.12 fixes never reached them (body was 14px, no skip link, 2px focus ring). The accessibility block is now also in assets/css/a11y.css, linked from those pages, and they load polish.js (shared mobile menu, Escape to close, skip link). Their duplicate inline menu script was removed.
- Static-page hamburger was white on a cream header (invisible); now dark green.
- Checked in headless Chromium at 1280px and 390px: body 15px, skip link present, no sideways overflow, menu opens and closes with Escape.
- verify_status.php: JSON polling endpoint, no UI. Logic reviewed, no change needed (no-store, stops when verified/expired/blocked, expires after 30 min).
- mailbox.php: wording no longer claims an email was already sent. Nothing in the PHP app links to this page; decide whether to link it from login/register or remove it.
- NOT done: PHP pages were not rendered (no PHP in this environment); unified headings/spacing/card layout; Lighthouse on a real device.
- v9.13: the banner headline on login, register, verify, verify_wait, mailbox, forgot-password, resend-verification and reset-password is now a styled paragraph (.auth-heading), so each page has a single <h1> (the form title). Not yet viewed in a browser with PHP running.
- v9.13 photos: (1) "No photo" in host/listings.php: photos added through edit_listing were always saved as non-primary, and the list only looked for a primary photo. All photo lookups now fall back to the oldest photo, edit_listing always promotes one, and database_fix_primary_photos.sql repairs existing rows. (2) Stay-page slideshow no longer crops (object-fit: contain, letterboxed); attraction banners still fill their frame.
- v9.13 homepage (index.php + assets/css/home.css): Featured Stays now show photo, name, a short description (3 lines) and "View stay" only; the Smart score badge, rating, location and price were removed (Discover/search.php still shows them). Popular Attractions: text no longer sits on the photo; each card shows the clean photo on top with the words below on paper. Checked with a static mock in Chromium (1280px); the real PHP page was not run.
- v9.13 ranking: applyWeightedScoring (includes/functions.php) treats a stay with no reviews as a neutral 3/5 for the Smart score only (displayed rating unchanged); the explainer in tourist/search.php says so.
- v9.13 attraction ratings: new includes/attraction_reviews.php (+ database/migration_attraction_reviews.sql; the table is also created automatically). Only a tourist with a COMPLETED booking at an approved stay within 50 km of the attraction can rate (checked on the server, not just hidden in the form); one rating per tourist per attraction, editable; CSRF protected. Shown on the attraction page (#ratings), as stars on attraction cards in Discover (with a "Highest rated" sort; unrated last, shown as "No ratings yet"), and on the homepage cards. NOT part of the Smart score. Admin: new "Attraction ratings" page (hide / restore / delete).
- v9.13 homepage: "New" badge (stays listed in the last 14 days, homepage and Discover), "Great in <month>" strip (attractions whose best months include the current month; hidden if none; all-year places are skipped) and "Newly added stays" row (last 30 days; hidden if none).
- Tested against the provided SQL dump with PHP 8.3 + MariaDB: all changed files lint clean; pages load without warnings; rating allowed/blocked as expected; hide/restore works.

## v9.14: merged with v9.11 (payment safeguards)
v9.11 (payment emails, reversal rules, booking holds) and v9.12 (accessibility and wording) had been built separately, so v9.12 and v9.13 did not contain the v9.11 payment work. v9.14 has both:
- includes/booking_holds.php (pending requests with no payment after 2 h, or no host reply after 48 h, are cancelled and the dates released; confirmed bookings are never auto-cancelled) and the calls to it on the booking, payment, search, details and admin booking pages.
- host/payments.php: "Mark unpaid" needs a written reason (5+ characters), is locked from the check-in day or once the stay is completed, and cannot undo a payment an admin confirmed. Reversals email the guest and the admins.
- New emails in includes/email.php: payment details submitted (host + guest), payment reversed (guest + admin), booking request expired. Admin emails follow ADMIN_NOTIFY_EMAIL when it is set.
- tourist/payment.php keeps the v9.12 duplicate-submission guard and uses the v9.11 emails.
- Tested on MariaDB with your SQL dump: 2h/48h expiry, confirmed bookings untouched, no reason / short reason / check-in day / admin-locked all refused, valid reversal recorded in payment_audit.

## v9.15: booking and payment rules
- includes/booking_rules.php: one place for the rules (2 h payment hold, 48 h host reply, free cancellation until 48 h before check-in: BOOKING_FREE_CANCEL_HOURS). Shown in a pop-up window (a <dialog>, closes with Esc, scrolls on phones) from the booking page, payment page, confirmation page and My bookings, so those pages stay uncluttered. A short version is in the booking-confirmed and payment-received emails.
- Guest cancelling: allowed until the day of check-in. Inside 48 hours the confirm box, the message and the email to the host say any refund is up to the host.
- Reminder email about 30 minutes before a request with no payment details is released (sent once per booking).
- Host "Decline booking" is now "Release dates" with an optional note that is emailed to the guest and saved in the booking history.
- Host "Cancel booking" now needs a written reason (5+ characters), is logged, emails the guest (and says to expect a refund if the payment was confirmed), and alerts the admins when a confirmed booking is cancelled. Emails escape the property and guest names.
- Not changed: cash-on-arrival bookings (confirmed but unpaid) are still never auto-cancelled.

## v9.16: design polish pass on all pages (checklist items)
Measured with axe-core in Chromium over 40 pages (guest, tourist, host, admin, static pages): colour-contrast failures went from 359 to 0.
- Contrast (15): darker muted grey (#565c55), terracotta (#a3462a), green (#126b4e), amber (#8f4b00) and red text; faded (opacity) small text made solid; applied across CSS, PHP pages and the static-page stylesheet.
- Tabular numbers (18): prices, amounts, totals, stats and table cells use tabular figures so digits line up.
- Headlines (16): h1-h3 use balanced wrapping, paragraphs avoid one-word last lines.
- Loading states (19): the pressed button on any form shows a spinner and a second submit is ignored while the first is running (stops double bookings, payments and ratings). Add data-no-loading to a form to opt out.
- Feedback (20): flash messages ease in, success ticks pop, buttons respond to a press; all of it is off for people who prefer reduced motion.
- Specific headlines and CTAs (9, 10): homepage hero ("Stay close to everything you came to see." with "Find a stay" as the main button), benefits, host call to action, sign-in and Discover headings.
- Homepage now has a <main> landmark, the skip link, the focus styles and polish.js (it had none of them).
- Footer column titles are h2 (they were h4 right after the h1), fixing the most common heading-order warning.
- Still open: some card titles jump from h1 to h3 (14 axe heading-order warnings left); layout unification (button corners, card radius, spacing) was not changed; Lighthouse and a real phone still need to be run by you.

## v9.18: commission (model B), receipts, payment evidence
Guests still pay hosts directly. The platform records its commission and tracks when it is really received.
- includes/commission.php: tables commission_ledger and commission_remittances (created automatically), reconcile logic, private file storage, receipt numbers and verification codes. Settings in includes/config.php: COMMISSION_RATE_PERCENT (10), COMMISSION_START_DATE (empty = every confirmed payment), COMMISSION_PAY_INSTRUCTIONS, RECEIPT_SECRET (set a long random text).
- Commission is EARNED when a host confirms a payment on a booking that is not cancelled. It is only RECEIVED once a host pays it and the admin accepts it, so earned is never reported as received. The rate is saved on each record. If the payment is reversed or the booking cancelled before it is paid, the record becomes void (and returns if the payment is confirmed again); if that happens after it was paid, it is flagged for review.
- Host: new Commission page (host/commission.php): what is owed, tick the bookings paid, enter the M-Pesa/bank reference, attach a screenshot, send to the admin (admin is emailed).
- Admin: new Revenue & commission page (admin/revenue.php): totals, per-host balances, host reports to accept or reject (host is emailed), ledger, and "Mark received" for payments made outside the site.
- Guest payment screenshot (optional) on the payment page; stored in private_uploads/ (denied to direct web access), shown only to the guest, the host and admins through proof.php. Real file type is checked.
- Printable receipt (tourist/receipt.php) for confirmed payments with receipt number and verification code; links on My bookings, the confirmation page, host and admin payment pages.
- Printable dispute evidence pack (admin/evidence.php): booking, parties, payments, screenshot, receipt code, who changed what and when, booking history, commission. Opening it is logged.
- Tested on MariaDB: fake image refused, proof visible to owner/host/admin only, commission 10% of 10,000 = 1,000, host report then admin accept, forged request for another host's commission refused, receipt/evidence access limited, reversal voids or flags.
- Not included (future work): M-Pesa Daraja, automatic payouts, SMS verification, guest service fee.

## v9.19: service fee and M-Pesa-style payment messages
- Service fee: SERVICE_FEE_PERCENT in includes/config.php (default 5, 0 turns it off). Added to the guest's total (percent of stay + extras), shown on the booking page before they reserve, stored per booking (bookings.service_fee, created automatically). The guest pays it with the booking, to the host, who passes it on to the platform with the commission. Commission is worked out on the host's price (total minus the fee). The ledger, host Commission page, admin Revenue page, receipt and evidence pack all show commission and service fee separately; the host's remittance covers both. Bookings made before this keep a fee of 0. The rules pop-up explains the fee.
- Emails now read like M-Pesa messages: guest "KES x sent to HOST - awaiting confirmation"; on confirmation guest "KES x received - payment confirmed" (with a receipt link) and host "KES x received from GUEST" with commission, service fee, amount due to the platform and what the host keeps.
- SMS verification was NOT added: it needs a paid SMS provider and API key (for example Africa's Talking or Twilio); Daraja is for M-Pesa payments, not SMS. Future enhancement; verification stays by email link or 6-digit code with resend.
- Tested on MariaDB: 2 nights x KES 8,500 + 5% fee = KES 17,850; commission 10% of 17,000 = 1,700; due 2,550; host keeps 15,300; earlier payment, commission, rules and expiry tests still pass.
