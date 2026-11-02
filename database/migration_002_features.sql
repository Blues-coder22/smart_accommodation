-- Run once in phpMyAdmin (database smart_accommodation > SQL). Safe to run twice.
-- Adds: password reset tokens, host ID verification, and host reviews of guests.

CREATE TABLE IF NOT EXISTS password_resets (
  reset_id    INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NOT NULL,
  token_hash  CHAR(64) NOT NULL,            -- sha256 of the emailed token; the token itself is never stored
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL DEFAULT NULL,
  request_ip  VARCHAR(45) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reset_token (token_hash),
  KEY idx_reset_user (user_id, created_at),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS host_verifications (
  verification_id INT AUTO_INCREMENT PRIMARY KEY,
  host_id         INT NOT NULL,
  legal_name      VARCHAR(120) NOT NULL,
  id_type         ENUM('National ID','Passport') NOT NULL DEFAULT 'National ID',
  id_number       VARCHAR(20) NULL,           -- erased as soon as an administrator decides
  id_photo        VARCHAR(255) NULL,          -- private file, erased as soon as an administrator decides
  business_ref    VARCHAR(60) NULL,
  status          ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  reason          VARCHAR(500) NULL,          -- shown to the host when rejected
  submitted_at    DATETIME NOT NULL,
  decided_at      DATETIME NULL DEFAULT NULL,
  decided_by      INT NULL DEFAULT NULL,
  UNIQUE KEY uq_verification_host (host_id),
  KEY idx_verification_status (status),
  CONSTRAINT fk_verification_host FOREIGN KEY (host_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS guest_reviews (
  guest_review_id INT AUTO_INCREMENT PRIMARY KEY,
  booking_id      INT NOT NULL,
  host_id         INT NOT NULL,
  tourist_id      INT NOT NULL,
  rating          TINYINT NOT NULL,
  review_text     VARCHAR(1000) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_guest_review_booking (booking_id),
  KEY idx_guest_review_tourist (tourist_id),
  CONSTRAINT chk_guest_rating CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT fk_guest_review_booking FOREIGN KEY (booking_id) REFERENCES bookings(booking_id),
  CONSTRAINT fk_guest_review_host FOREIGN KEY (host_id) REFERENCES users(user_id),
  CONSTRAINT fk_guest_review_tourist FOREIGN KEY (tourist_id) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
