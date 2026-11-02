-- Optional tour guide / on-site attraction fee set by the host (per guest, added to the booking total).
ALTER TABLE accommodations
  ADD COLUMN onsite_experience_name VARCHAR(120) NULL DEFAULT NULL,
  ADD COLUMN onsite_experience_fee DECIMAL(10,2) NULL DEFAULT NULL;
