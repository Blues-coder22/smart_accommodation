-- StayExplore Kenya v9.33: manual SQL equivalent of database/migrate_v9_33.php
-- Most people should run:  php database/migrate_v9_33.php   (it checks each step first and is safe to repeat).
-- Run this file ONLY ONCE, on a database that has not been upgraded, and take a backup first.
-- MySQL 8 / MariaDB 10.x. Nothing here deletes or rewrites existing records, except the single status remap in step 1.

-- 1. New booking statuses
ALTER TABLE bookings MODIFY status ENUM('pending','accepted','payment_pending','confirmed','cancelled','completed','no_show') NOT NULL DEFAULT 'pending';
-- Old flow: the guest sent payment details while the request was still 'pending'. Those are now 'payment_pending'.
UPDATE bookings b SET b.status = 'payment_pending' WHERE b.status = 'pending' AND EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.booking_id);

-- 2. Payment deadline columns and the new default commission mode (existing rows are not changed)
ALTER TABLE bookings ADD COLUMN accepted_at DATETIME NULL DEFAULT NULL, ADD COLUMN payment_due_at DATETIME NULL DEFAULT NULL, ADD COLUMN payment_reminder_sent TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE bookings ALTER COLUMN commission_mode SET DEFAULT 'invoice';

-- 3. Host payment instructions and commission invoices
ALTER TABLE users ADD COLUMN payment_instructions VARCHAR(600) NULL;
ALTER TABLE host_commission_payments ADD COLUMN proof_note VARCHAR(300) NULL;
CREATE TABLE IF NOT EXISTS commission_invoices (
  invoice_id INT AUTO_INCREMENT PRIMARY KEY, invoice_no VARCHAR(40) NOT NULL, host_id INT NOT NULL, booking_id INT NOT NULL,
  base_amount DECIMAL(10,2) NOT NULL DEFAULT 0, rate DECIMAL(5,2) NULL, amount DECIMAL(10,2) NOT NULL, paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  status ENUM('unpaid','partial','paid','void') NOT NULL DEFAULT 'unpaid', issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_date DATE NOT NULL, overdue_notified TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_invoice_no (invoice_no), UNIQUE KEY uq_invoice_booking (booking_id), KEY idx_host_status (host_id, status), KEY idx_due (status, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Launch settings
INSERT INTO site_settings (setting_key, setting_value) VALUES ('commission_mode','invoice'),('service_fee_percent','0'),('payment_deadline_hours','24'),('schema_version','9.33')
  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- 5. Indexes for the busiest queries
ALTER TABLE bookings ADD INDEX idx_status_created (status, created_at), ADD INDEX idx_tourist_status (tourist_id, status), ADD INDEX idx_status_due (status, payment_due_at), ADD INDEX idx_status_checkout (status, check_out_date);
ALTER TABLE payments ADD INDEX idx_payment_status (payment_status);
ALTER TABLE reviews ADD INDEX idx_acc_published (accommodation_id, is_published);
ALTER TABLE accommodations ADD INDEX idx_status_created (status, created_at), ADD INDEX idx_status_geo (status, latitude, longitude), ADD INDEX idx_status_type_price (status, property_type, price_per_night);
ALTER TABLE accommodation_photos ADD INDEX idx_acc_primary (accommodation_id, is_primary, photo_id);
ALTER TABLE amenities ADD INDEX idx_acc_amenity (accommodation_id, amenity_name);
ALTER TABLE availability ADD INDEX idx_acc_date (accommodation_id, is_available, `date`);
ALTER TABLE host_commission_payments ADD INDEX idx_host_status (host_id, status);
ALTER TABLE platform_payments ADD INDEX idx_pp_status (status);
-- After running this SQL by hand, also run: php database/migrate_v9_33.php   (creates the supporting tables and writes the marker file)
