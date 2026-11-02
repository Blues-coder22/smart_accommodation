-- Several photos per attraction (the first photo stays in attractions.photo_path as the cover).
CREATE TABLE IF NOT EXISTS attraction_photos (
  photo_id INT AUTO_INCREMENT PRIMARY KEY,
  attraction_id INT NOT NULL,
  photo_path VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_att (attraction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
