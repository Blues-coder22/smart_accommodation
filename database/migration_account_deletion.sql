-- Run once in phpMyAdmin after selecting the smart_accommodation database (safe to run twice).
-- Permanent account deletion works by erasing the person's personal data and keeping the (now anonymous) user row,
-- so bookings, payments and reviews that point at it stay valid and nothing else in the database breaks.
ALTER TABLE users ADD COLUMN IF NOT EXISTS account_closed_at DATETIME NULL DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS account_deleted_at DATETIME NULL DEFAULT NULL;
