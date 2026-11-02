-- Run once in phpMyAdmin (database smart_accommodation). Optional: the site also adds these columns by itself.
-- Profile photos are stored in the database so they are never lost when the project folder is replaced.
ALTER TABLE users
  ADD COLUMN profile_photo_data MEDIUMBLOB NULL DEFAULT NULL,
  ADD COLUMN profile_photo_mime VARCHAR(50) NULL DEFAULT NULL;
