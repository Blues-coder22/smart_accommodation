# v9.31 - Alerts, destination chooser, map tidy-ups

## What is new
1. **New message notification.** When a guest or host sends a message, the other person gets an in-site notification
   ("New message", linking to that conversation). Only one unread alert per conversation, so a long chat does not flood the bell.
2. **Check-in reminder.** A confirmed booking that starts today, tomorrow or within 3 days produces one notification (once per booking).
3. **Saved stay available again.** For each stay a tourist saved as a favourite, the site looks at the next 14 nights
   (pending/confirmed bookings and host-blocked nights count as taken). If it had no free night at the last check and now has one,
   the tourist gets a notification with the first free date. The first check only records the state; nothing is sent for it.
4. **Where to go** (`tourist/choose_destination.php`, new "Where to go" link in the menu). Questions: interests, kind of place,
   month, most per night in KES. It ranks attractions out of 100: interests 40, kind of place 20, month 15 (from the attraction's
   best-time text), stays within 50 km and within budget 20, visitor rating 5 (pulled towards 3.0 when few ratings).
   Each result shows the reasons that counted and notes such as "outside its best season". Links go to the attraction page and to
   the stays search near it (with the budget applied).
5. **Map tidy-ups on the Discover page.** Clicking a stay pin highlights its result card; pointing at a card opens its pin when the map is open.
   Distances now read "km (straight line)" on the result cards, stay page and attraction page.

## How the alerts run (important for the report)
There is no scheduled job. The checks run when a signed-in tourist opens a page, at most once every 15 minutes per sign-in, and always
when they open the Notifications page (`includes/alerts.php`). So an alert appears the next time the tourist uses the site, not at the
moment the stay frees up. A real deployment would run the same functions from a cron job.

## Files
* new: includes/alerts.php, tourist/choose_destination.php, CHANGELOG_v9_31_ALERTS_DESTINATIONS.md
* changed: includes/messages_page.php, includes/header.php, notifications.php, tourist/search.php, tourist/details.php,
  tourist/attraction.php, database/full_schema.sql
* new tables (also created automatically on first use): alert_log, saved_stay_state

## Known limits
* Not run: no PHP or MySQL was available when this was written (checked by reading the code and bracket balance only). Test on XAMPP.
* Saved-stay alert watches the next 14 nights only, and only fires when a stay goes from fully taken to having a free night.
  It does not know the dates the tourist wants.
* No email or SMS for these alerts; they are in-site notifications only.
* Where to go uses only data in the database. "Best time" is free text, so a place with no recorded season gets a neutral score;
  stays are matched by straight-line distance (50 km), not driving time. Results improve as attractions get best-time and terrain details.
* Pointing at a result card opens its pin only when the map is showing.

## Quick tests
1. Send a message as a guest: the host's bell shows "New message". Send three more: still one unread alert.
2. Make a confirmed booking with check-in tomorrow, sign in as that tourist: "Check-in tomorrow" appears once, not again after reload.
3. Save a stay. Fill every night of the next 14 days with a booking or host block, load a page (state recorded as 0),
   then free one night and load a page after the 15-minute pause or open Notifications: "A stay you saved is available".
4. Where to go: tick Wildlife, month July, budget 8,000: park results rank first, in-season ones show "In season in July".
   Budget 100: results show "Stays nearby cost more than your budget".
5. Discover with the map open: click a pin, its card gets a green outline.
