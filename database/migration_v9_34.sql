-- StayExplore Kenya v9.34 (optional: the site also does all of this by itself the first time it runs).
-- 1. Host introduction
ALTER TABLE users ADD COLUMN IF NOT EXISTS host_intro TEXT NULL;
-- 2. Support inbox
CREATE TABLE IF NOT EXISTS support_threads (
  thread_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, subject VARCHAR(160) NOT NULL, category VARCHAR(40) NOT NULL DEFAULT 'other',
  booking_ref VARCHAR(20) NULL, status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_user (user_id, updated_at), KEY idx_status (status, updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS support_messages (
  message_id BIGINT AUTO_INCREMENT PRIMARY KEY, thread_id INT NOT NULL, sender_id INT NOT NULL, is_admin TINYINT(1) NOT NULL DEFAULT 0,
  body VARCHAR(2000) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, read_at DATETIME NULL,
  KEY idx_thread (thread_id, message_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 3. Emoji: convert any older table that is not utf8mb4 (messages, reviews, notifications, reports ...). Repeat per table if needed:
-- ALTER TABLE messages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
