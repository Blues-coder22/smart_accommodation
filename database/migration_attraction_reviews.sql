-- Attraction ratings (optional: the site creates this table automatically when it is first needed).
CREATE TABLE IF NOT EXISTS attraction_reviews (
  review_id INT AUTO_INCREMENT PRIMARY KEY,
  attraction_id INT NOT NULL,
  tourist_id INT NOT NULL,
  rating TINYINT NOT NULL,
  comment TEXT NULL,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_att_tourist (attraction_id, tourist_id),
  KEY idx_att (attraction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
