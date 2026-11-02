-- Optional migration for the OpenStreetMap attraction review queue.
-- The admin/osm_attractions.php page also creates this table automatically.
CREATE TABLE IF NOT EXISTS osm_attraction_imports (
  import_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  osm_type VARCHAR(12) NOT NULL,
  osm_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  category VARCHAR(100) NOT NULL DEFAULT 'Attraction',
  location_name VARCHAR(150) DEFAULT NULL,
  latitude DECIMAL(10,8) NOT NULL,
  longitude DECIMAL(11,8) NOT NULL,
  tags_json LONGTEXT DEFAULT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  attraction_id INT DEFAULT NULL,
  imported_by INT DEFAULT NULL,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_osm_source (osm_type, osm_id),
  KEY idx_osm_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
