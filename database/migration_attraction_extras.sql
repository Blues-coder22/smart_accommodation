-- Run once in phpMyAdmin (database smart_accommodation). Optional: the site also creates this table by itself.
-- Extra, fun content for attraction pages: tagline, best time, "Did you know?" facts (one per line), "What's new" (one per line).
CREATE TABLE IF NOT EXISTS attraction_extras (
  attraction_id INT NOT NULL PRIMARY KEY,
  tagline       VARCHAR(220) NULL,
  best_time     VARCHAR(160) NULL,
  climate       VARCHAR(160) NULL,
  terrain       VARCHAR(200) NULL,
  best_for      VARCHAR(200) NULL,
  did_you_know  TEXT NULL,
  whats_new     TEXT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
