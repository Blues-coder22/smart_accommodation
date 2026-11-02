-- Lets the browser that registered sign itself in after the email link is opened on ANY device.
-- (Created automatically by the site if missing; run this only if you prefer to create it yourself.)
CREATE TABLE IF NOT EXISTS verify_waits (
  wait_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
