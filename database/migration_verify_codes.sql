-- 6-digit email verification codes (typed on the waiting page when the phone cannot open the link).
-- Created automatically by the site if missing; run this only if you prefer to create it yourself.
CREATE TABLE IF NOT EXISTS verify_codes (
  user_id INT NOT NULL PRIMARY KEY,
  code_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  attempts TINYINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
