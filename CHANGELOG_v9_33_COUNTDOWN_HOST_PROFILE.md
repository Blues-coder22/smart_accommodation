# v9.33 - Booking countdown and public host profile

## Booking countdown
* Confirmed upcoming bookings (My bookings > Upcoming, and the booking confirmation page) show a friendly countdown card: days / hours / min / sec ticking live, plus a message that changes as the trip gets closer (dreaming, packing, "Tomorrow is the day!", "Today's the day! Safari njema!").
* Counts down to the start of the check-in day, Kenya time (Africa/Nairobi).
* Pending bookings show "Your countdown starts once the host confirms." Cancelled and past bookings show nothing.
* Screen readers get a plain "N days to go"; the ticking boxes are hidden from them. The pulse animation is switched off for people who prefer reduced motion. Dark mode styles included.
* File: `includes/booking_countdown.php` (used by `tourist/bookings.php` and `tourist/confirmation.php`).

## Host profile (view_profile.php)
* Any visitor can now open the profile of a host who has an approved listing and an active account (no sign-in needed). Guest profiles stay limited to people who share a chat or booking with you; admins can view anyone.
* Shows: profile picture, name, verification status (Verified host / Not verified yet), response rate, number of properties, average rating and review count, the host's introduction, a list of properties, and the latest 10 published guest reviews (reviewer first name only, with the host's reply when there is one).
* Response rate = chat enquiries the host answered / enquiries received (a thread where the guest wrote at least one message). It is only shown from 3 enquiries; before that the page says "New".
* "Hosted by <name>" and a "View host profile" link on the listing page lead to the profile.
* Hosts write their introduction in Host > Profile ("About you", up to 600 characters). New column `users.host_intro` is added automatically; `database/migration_host_intro.sql` and `database/full_schema.sql` have it too. The introduction is erased when an account is deleted.
* Guests' pill now reads "Traveller" instead of "Tourist" (wording guide).

## Kiswahili
* `i18n/sw_e.txt` has the new strings. Have a native speaker review them (especially the countdown messages).

## Merged into the full v9.33 code base
This change set was originally written on v9.32. It has been merged by hand into the full v9.33 (booking workflow, host-paid commission, shared `ui.css`/`ui.js`, local Leaflet, dark-mode tokens), so none of v9.33 was overwritten.
* Countdown also handles the v9.33 statuses: `accepted` and `payment_pending` show "Your countdown starts once the host confirms your payment." (new Kiswahili line added to `sw_e.txt`).
* `host/profile.php` keeps the v9.33 "How guests pay you" field and adds the "About you" field after it.
* `includes/profile_functions.php`: only the host introduction (save, 600-character check, erase on account deletion) was added; the open-booking check keeps the v9.33 status list.
* `view_profile.php`: public host profile, using v9.33's `ui.css` and colour tokens (so dark mode works).
* `tourist/details.php`: "Hosted by" link and "View host profile" link only. `tourist/bookings.php`, `tourist/confirmation.php`: countdown only.
* Kiswahili: 42 wording fixes applied to `sw_a` to `sw_d` (e.g. "Weka nafasi" for Book/Reserve, "Kuwasili/Kuondoka" for Check-in/Check-out, "Alama mahiri" for Smart score, "Imechukuliwa" for Booked, "Ulioghairiwa" spelling); 35 retired strings removed (none used by v9.33); `sw_e.txt` added; `assets/js/se-i18n-sw.js` regenerated with `python3 tools/build_sw_wordlist.py` (975 phrases).
* Column `users.host_intro` is added automatically on first use; `database/migration_host_intro.sql` and `database/full_schema.sql` include it.

# v9.34 improvements (on top of the merged v9.33)
**Bookings and money**
* "Mark as paid" / "Confirm as received" no longer appear on cancelled (or not-yet-accepted) bookings, for host and admin; the server refuses them too.
* The host's commission is worked out the moment the payment is confirmed (invoice created then, due N days after check-out as before). It is withdrawn automatically if the booking is cancelled, no-show, or the payment is set back to unpaid, and reinstated if the payment is confirmed again.
* The transaction code is mandatory (label, `required` once a method is chosen, and server check).
* Guests cannot book today or days that have passed: notice under the date boxes, earliest date is tomorrow, and a clear message from the server.

**Notifications, messages, support**
* A booking notification now opens that booking, in the right tab (e.g. Cancelled), highlighted. Cancelled tab explains refunds and links to support.
* Opening a page marks the notifications about it as read automatically (menu, link or bell).
* Live: bell count, pop-up for new notifications, and live chat (new messages appear within seconds; sending needs no reload). Pauses when the tab is hidden.
* New in-app "Contact support" (guest: tourist/support.php, host: host/support.php) and an admin "Support inbox" (admin/support.php). Email options open the person's own mail app, Gmail or Outlook addressed to the admin (From is always the person's own account; a web page cannot set it).
* Guests can report a host ("Report this host" on the stay page, or choose it in the report form). Admin sees it labelled "Host conduct".
* Emoji now save correctly (database connection is utf8mb4; older tables are converted once automatically). Emoji picker on message, support, review and intro boxes.

**Layout**
* New header: main links on the bar; Favourites, Contact support, language, currency, theme and Sign out are inside an account menu. Phone menu opens as a full list with the StayExplore brand on top and roomier spacing. Same on the home page.
* Photo gallery: stays show photos whole (toggle "Fill"), tap for a full-screen viewer (zoom, arrows, swipe, grid of all photos). Attractions have a photo gallery grid.

**Registration**
* A mistake clears only that field and shows the message beside it; everything else (including passwords that were fine) stays. Fields are also checked as you leave them.

Kiswahili: `sw_f.txt` adds the new strings (please have a native speaker review them). New files: includes/support*.php, includes/emoji_support.php, api/chat.php, api/live.php, api/public_support.php, assets/js/{live,emoji,nav,lightbox}.js, database/migration_v9_34.sql.

# v9.35: SMS verification removed
* Sign-up no longer offers "SMS code". Accounts are confirmed by the emailed link (or the 6-digit code in the same email), as before.
* The phone number is still on the form but is optional and only a contact detail.
* Removed: includes/sms.php, the SMS_* settings in includes/config.php and config.example.php, the sms_log table in database/full_schema.sql, and every SMS option on verify_wait.php.
* Kept: includes/phone.php (phone-number tidy-up). An existing database may still hold an unused `sms_log` table and `users.phone_verified` column; they are harmless and can be dropped.

# v9.36: verification code lock-out fix
* After 5 wrong tries the code is locked. Before, the lock lasted until a new email was sent, and if the resend failed (or was slow) the old locked code was put back, so the new code from the email kept being refused with "limit reached".
* Now: the lock ends by itself after 5 minutes; a failed resend restores the earlier code with fresh tries (never locked); messages say how long to wait and to use the code from the newest email. A new email still always gives a fresh code and fresh tries. (`verify_codes.last_try` is added automatically.)
* v9.36 also adds a plain tip on the verification page: "If you asked for the email more than once, use the code from the newest email. Older codes stop working." (with Kiswahili in sw_f.txt).

# v9.37: reminder emails for commission invoices
* New includes/invoice_reminders.php, run by the scheduled maintenance job (cron, or the page fallback).
* For every invoice not fully paid: a reminder email N days before the due date (default 2), one on the due date, one the day after, then every R days (default 7) until it is paid, at most 10 in all. Each reminder also appears in the host's notifications.
* One email per host per run lists all of that host's invoices needing a reminder (no spam). Amounts shown are what is still unpaid (part payments are subtracted). Paid and void invoices never get reminders.
* If an email cannot be sent it is retried after about 6 hours; if no mail account is configured the reminder is shown inside the site only.
* Admin: Revenue & commission now has "Email hosts reminders about unpaid commission invoices", "days before the due date" and "repeat every N days while overdue".
* Replaces the earlier single in-site overdue notice; the admin still gets one alert when invoices first become overdue. New columns on commission_invoices (reminders_sent, last_reminder_at, next_reminder_at) are added automatically.
