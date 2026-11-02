-- Category scores on guest reviews, admin moderation of host replies and host reviews of guests.
ALTER TABLE reviews
  ADD COLUMN cleanliness_rating TINYINT NULL DEFAULT NULL,
  ADD COLUMN location_rating TINYINT NULL DEFAULT NULL,
  ADD COLUMN comfort_rating TINYINT NULL DEFAULT NULL,
  ADD COLUMN value_rating TINYINT NULL DEFAULT NULL,
  ADD COLUMN host_reply_hidden TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE guest_reviews
  ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN hidden_at DATETIME NULL DEFAULT NULL;
