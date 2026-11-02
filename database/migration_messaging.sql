-- Run once in phpMyAdmin against the smart_accommodation database.
CREATE TABLE IF NOT EXISTS message_threads (
  thread_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  accommodation_id INT NOT NULL,
  tourist_id INT NOT NULL,
  host_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (thread_id),
  UNIQUE KEY uq_thread_listing_tourist (accommodation_id, tourist_id),
  KEY idx_thread_tourist (tourist_id),
  KEY idx_thread_host (host_id),
  KEY idx_thread_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id INT UNSIGNED NOT NULL,
  sender_id INT NOT NULL,
  body VARCHAR(1000) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME DEFAULT NULL,
  PRIMARY KEY (message_id),
  KEY idx_message_thread_created (thread_id, created_at),
  KEY idx_message_sender (sender_id),
  KEY idx_message_unread (thread_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payment reference entered by the tourist is retained for host review.
ALTER TABLE payments ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(120) NULL AFTER payment_method;
