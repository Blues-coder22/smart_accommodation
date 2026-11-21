# v9.28 - Commission modes, no guest fee by default, host agreement

## What changed
1. **Guest service fee now defaults to 0** (config.php / config.example.php / code fallback).
   If the admin already saved a fee under Revenue and commission, that saved value still applies: set it to 0 there.
2. **Commission mode** (Revenue and commission > "How commission is collected"):
   * *With the booking* (default, same as v9.27): the guest pays the commission to the platform together with the booking.
   * *Invoice after the stay*: the guest pays the host the full price. When the stay has ended (check-out passed, booking confirmed/completed)
     the commission is due; the host has N days (admin setting) to pay it, reports the payment on Host > Commission,
     and the admin confirms it. Payments are applied to the oldest bookings first. Hosts with overdue commission can be paused for new bookings (tick-box).
   * Every booking stores the mode it was made in (`bookings.commission_mode`), so switching never changes older bookings.
3. **Commission base**: stay + extras (default) or accommodation price only (nights x price).
4. **Host agreement** (Host > Host agreement): the host must accept it before adding a listing, and the admin cannot approve a listing
   whose host has not accepted. The exact wording accepted, the time and the IP are stored (`host_agreements`).

## Files
* changed: includes/commission.php, includes/booking_rules.php, includes/config.php, includes/config.example.php, includes/app_shell.php,
  tourist/book.php, tourist/payment.php, receipt.php, host/commission.php, host/bookings.php, host/add_listing.php, admin/revenue.php, admin/listings.php, database/full_schema.sql
* new: host/agreement.php
* new tables (also created automatically on first use): host_commission_payments, host_agreements; new column bookings.commission_mode

## Known limits
* Invoice mode counts a stay as due once its check-out date has passed; it does not check that the host really received the guest's money.
* No automatic reminder emails for overdue commission; hosts see it on their Commission page and in notifications only when the admin acts.
* The agreement wording (especially "bookings outside the platform") is a draft: have your advocate review it, together with the commission, invoice and tax arrangements.
* Tested with PHP syntax checks and a mock-database test of the split and ledger maths; not run against a live MySQL database or in a browser.
