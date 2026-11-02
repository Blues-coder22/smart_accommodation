-- Credit (photographer, licence, source link) for photos that came from Wikimedia Commons.
-- Run once in phpMyAdmin (database smart_accommodation). Optional: the site also creates this table by itself.
CREATE TABLE IF NOT EXISTS attraction_photo_credits (
  photo_path  VARCHAR(255) NOT NULL PRIMARY KEY,   -- same file name as in attractions.photo_path / attraction_photos.photo_path
  credit      VARCHAR(255) NOT NULL,
  license     VARCHAR(120) NULL,
  license_url VARCHAR(500) NULL,
  source_url  VARCHAR(500) NULL,
  source_name VARCHAR(60)  NOT NULL DEFAULT 'Wikimedia Commons',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
