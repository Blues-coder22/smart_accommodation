-- Run once in phpMyAdmin after selecting the smart_accommodation database.
-- Adds soft account closure without deleting booking/payment/review history.
ALTER TABLE users ADD COLUMN account_closed_at DATETIME NULL DEFAULT NULL;
