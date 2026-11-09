-- Run once in phpMyAdmin (database: smart_accommodation).
-- Gives every listing that has photos but no main photo a main photo (its oldest one).
UPDATE accommodation_photos p
JOIN (
  SELECT accommodation_id, MIN(photo_id) AS first_id
  FROM accommodation_photos
  GROUP BY accommodation_id
  HAVING SUM(is_primary) = 0
) x ON x.first_id = p.photo_id
SET p.is_primary = 1;
