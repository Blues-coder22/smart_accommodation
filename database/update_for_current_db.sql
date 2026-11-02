-- One-time update for the smart_accommodation database you sent (safe to run more than once).
-- Run in phpMyAdmin > smart_accommodation > SQL. The site also does the first two steps automatically.

-- 1) Profile photos stored in the database (your users table only keeps file names).
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'profile_photo_data');
SET @q := IF(@c = 0, 'ALTER TABLE users ADD COLUMN profile_photo_data MEDIUMBLOB NULL DEFAULT NULL, ADD COLUMN profile_photo_mime VARCHAR(50) NULL DEFAULT NULL', 'SELECT 1');
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) Extra attraction content (tagline, best time, "Did you know?", "What's new").
CREATE TABLE IF NOT EXISTS attraction_extras (
  attraction_id INT NOT NULL PRIMARY KEY,
  tagline VARCHAR(220) NULL, best_time VARCHAR(160) NULL, climate VARCHAR(160) NULL,
  terrain VARCHAR(200) NULL, best_for VARCHAR(200) NULL,
  did_you_know TEXT NULL, whats_new TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Starter content for your attractions that have no built-in facts (Lake Naivasha and Maasai Mara already do).
INSERT IGNORE INTO attraction_extras (attraction_id, tagline, best_time, climate, best_for, did_you_know) VALUES
(1, 'Nyama choma, Nairobi style.', 'All year', 'Mild Nairobi evenings', 'Food lovers, groups and celebrations',
 'Meat is roasted on long spear-like skewers over a huge charcoal pit and carved at your table.\nPair your meal with a Dawa — Kenya''s famous vodka, lime and honey cocktail.'),
(2, 'A green escape right beside Nairobi.', 'June – October and January – February', 'Cool, fresh forest air', 'Nature walks, birdwatching and families',
 'Early mornings are the best time to spot birds and small wildlife at the forest edge.\nLook out for the monthly forest activities held at the reserve.');

-- 4) Tidy descriptions that were saved with doubled HTML codes (e.g. "cafe&amp;amp;#039;s").
UPDATE accommodations
   SET description = REPLACE(REPLACE(REPLACE(description, '&amp;amp;#039;', CHAR(39)), '&amp;#039;', CHAR(39)), '&#039;', CHAR(39))
 WHERE description LIKE '%#039;%';
UPDATE attractions
   SET description = REPLACE(REPLACE(REPLACE(description, '&amp;amp;#039;', CHAR(39)), '&amp;#039;', CHAR(39)), '&#039;', CHAR(39))
 WHERE description LIKE '%#039;%';
