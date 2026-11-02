-- StayExplore Kenya: migration 001 (run once in phpMyAdmin > SQL, database `smart_accommodation`)
-- Safe to re-run: uses IF NOT EXISTS (MariaDB 10.4+).

-- 1. Remove any duplicate rows first (keeps the newest) so the unique keys can be added.
DELETE a1 FROM availability a1
  JOIN availability a2
    ON a1.accommodation_id = a2.accommodation_id
   AND a1.`date` = a2.`date`
   AND a1.availability_id < a2.availability_id;

DELETE f1 FROM favourites f1
  JOIN favourites f2
    ON f1.tourist_id = f2.tourist_id
   AND f1.item_type = f2.item_type
   AND f1.item_id   = f2.item_id
   AND f1.favourite_id < f2.favourite_id;

DELETE p1 FROM payments p1
  JOIN payments p2
    ON p1.booking_id = p2.booking_id
   AND p1.payment_id < p2.payment_id;

-- 2. Unique keys.
--    availability: host/availability.php uses ON DUPLICATE KEY UPDATE, which needs this.
ALTER TABLE availability
  ADD UNIQUE KEY IF NOT EXISTS uq_availability_day (accommodation_id, `date`);

ALTER TABLE favourites
  ADD UNIQUE KEY IF NOT EXISTS uq_favourite_item (tourist_id, item_type, item_id);

ALTER TABLE payments
  ADD UNIQUE KEY IF NOT EXISTS uq_payment_booking (booking_id);

-- 3. Store the bank / M-Pesa reference the tourist types on the payment page.
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS payment_reference varchar(100) DEFAULT NULL AFTER payment_method;

-- 4. Useful lookup indexes.
ALTER TABLE bookings
  ADD INDEX IF NOT EXISTS idx_booking_dates (accommodation_id, status, check_in_date, check_out_date);
