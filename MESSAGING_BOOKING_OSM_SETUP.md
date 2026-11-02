# Messaging, booking and OpenStreetMap updates

## Install the database migration

1. Back up your `smart_accommodation` database in phpMyAdmin (Export).
2. Select the `smart_accommodation` database and choose **Import**.
3. Import `database/migration_messaging.sql` once. It creates the conversation and message tables and adds `payments.payment_reference`.
4. Do not run this migration repeatedly because the `ALTER TABLE` for `payment_reference` is intended to run once.

## What changed

- Admin OpenStreetMap search accepts an area name such as `Naivasha, Nakuru County, Kenya`. It geocodes the name and searches around the resulting point. The map marker and advanced coordinate fields remain available as a fallback.
- Tourist payment submission now stores the chosen method and optional reference as **pending** and leaves the booking **pending**. This prototype does not process a real M-Pesa/bank transaction.
- Hosts can confirm or decline a pending booking. Confirming marks the submitted payment details as reviewed and sends a confirmation email; declining cancels the request and notifies the tourist.
- Tourists can cancel their own pending or confirmed bookings before check-in. Completed, already-cancelled, and same-day/past check-in bookings cannot be cancelled through this action.
- Messages are stored in MySQL and shared between the tourist and host. Tourists can start a conversation from an approved accommodation page. Hosts can message a guest from that property's booking card; host-initiated messaging requires an existing booking.

## Test checklist

1. Log in as admin → Discover Attractions → enter an area name → search → select a place → import → approve it.
2. Log in as tourist → open an approved accommodation → Message host → send a message.
3. Log in as the matching host → Messages → open the thread → reply. Check both accounts see the same messages.
4. Make a booking as a tourist and submit payment details. It should still be pending.
5. Before host action, verify host sees Confirm booking and Decline booking. Confirm from the host account, then verify tourist sees Confirmed.
6. Create another future booking and cancel it from My Bookings. Verify it moves to the Cancelled tab.

## Notes

- OpenStreetMap and Overpass are public services with availability and rate limits. Search is intentionally triggered only when an admin clicks the button.
- The payment workflow is a record/review workflow only. Do not treat the selected payment method or typed reference as proof of a real money transfer until a real payment gateway or reconciliation process is integrated.
