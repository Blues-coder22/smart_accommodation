-- StayExplore Kenya: complete database structure (no data), version 9.28 (adds bookings.commission_mode, host_commission_payments and host_agreements; the site also creates them itself on first use).
-- Includes every table and column the site adds on top of the original database (booking references, split payments, settings,
-- notifications, currencies, certificates, photos...), so a fresh install needs only this file. Structure only: no data and no
-- administrator account (register one, then set role='admin' in phpMyAdmin).
-- Fresh install:  import this file in phpMyAdmin (Import tab).
-- Existing database: do NOT import this. Use Admin > Database setup (or `php database/run_setup.php`); it adds only what is missing.
CREATE DATABASE IF NOT EXISTS `smart_accommodation` DEFAULT CHARACTER SET utf8mb4;
USE `smart_accommodation`;
SET FOREIGN_KEY_CHECKS = 0;

/*M!999999\- enable the sandbox mode */ 
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accommodation_photos` (
  `photo_id` int(11) NOT NULL AUTO_INCREMENT,
  `accommodation_id` int(11) NOT NULL,
  `photo_path` varchar(255) NOT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`photo_id`),
  KEY `accommodation_id` (`accommodation_id`),
  CONSTRAINT `accommodation_photos_ibfk_1` FOREIGN KEY (`accommodation_id`) REFERENCES `accommodations` (`accommodation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accommodations` (
  `accommodation_id` int(11) NOT NULL AUTO_INCREMENT,
  `host_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `property_type` varchar(50) DEFAULT NULL,
  `price_per_night` decimal(10,2) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `location_name` varchar(150) DEFAULT NULL,
  `max_guests` int(11) DEFAULT 1,
  `status` enum('pending','approved','rejected','suspended') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `onsite_experience_name` varchar(120) DEFAULT NULL,
  `onsite_experience_fee` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`accommodation_id`),
  KEY `host_id` (`host_id`),
  CONSTRAINT `accommodations_ibfk_1` FOREIGN KEY (`host_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `amenities` (
  `amenity_id` int(11) NOT NULL AUTO_INCREMENT,
  `accommodation_id` int(11) NOT NULL,
  `amenity_name` varchar(100) NOT NULL,
  PRIMARY KEY (`amenity_id`),
  KEY `accommodation_id` (`accommodation_id`),
  CONSTRAINT `amenities_ibfk_1` FOREIGN KEY (`accommodation_id`) REFERENCES `accommodations` (`accommodation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attraction_extras` (
  `attraction_id` int(11) NOT NULL,
  `tagline` varchar(220) DEFAULT NULL,
  `best_time` varchar(160) DEFAULT NULL,
  `climate` varchar(160) DEFAULT NULL,
  `terrain` varchar(200) DEFAULT NULL,
  `best_for` varchar(200) DEFAULT NULL,
  `did_you_know` text DEFAULT NULL,
  `whats_new` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`attraction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attraction_photo_credits` (
  `photo_path` varchar(255) NOT NULL,
  `credit` varchar(255) NOT NULL,
  `license` varchar(120) DEFAULT NULL,
  `license_url` varchar(500) DEFAULT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `source_name` varchar(60) NOT NULL DEFAULT 'Wikimedia Commons',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`photo_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attraction_photos` (
  `photo_id` int(11) NOT NULL AUTO_INCREMENT,
  `attraction_id` int(11) NOT NULL,
  `photo_path` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`photo_id`),
  KEY `idx_att` (`attraction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attraction_reviews` (
  `review_id` int(11) NOT NULL AUTO_INCREMENT,
  `attraction_id` int(11) NOT NULL,
  `tourist_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL,
  `comment` text DEFAULT NULL,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `uq_att_tourist` (`attraction_id`,`tourist_id`),
  KEY `idx_att` (`attraction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attractions` (
  `attraction_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `location_name` varchar(150) DEFAULT NULL,
  `entry_fee` decimal(10,2) DEFAULT 0.00,
  `photo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attraction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `availability` (
  `availability_id` int(11) NOT NULL AUTO_INCREMENT,
  `accommodation_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `is_available` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`availability_id`),
  UNIQUE KEY `uq_availability_day` (`accommodation_id`,`date`),
  CONSTRAINT `availability_ibfk_1` FOREIGN KEY (`accommodation_id`) REFERENCES `accommodations` (`accommodation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_events` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `event` varchar(30) NOT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`event_id`),
  KEY `idx_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bookings` (
  `booking_id` int(11) NOT NULL AUTO_INCREMENT,
  `tourist_id` int(11) NOT NULL,
  `accommodation_id` int(11) NOT NULL,
  `check_in_date` date NOT NULL,
  `check_out_date` date NOT NULL,
  `num_guests` int(11) DEFAULT 1,
  `total_amount` decimal(10,2) NOT NULL,
  `rules_agreed` tinyint(1) DEFAULT 0,
  `status` enum('pending','confirmed','cancelled','completed','no_show') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `service_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `commission_amount` decimal(10,2) DEFAULT NULL,
  `commission_rate` decimal(5,2) DEFAULT NULL,
  `commission_mode` varchar(10) NOT NULL DEFAULT 'upfront',
  `booking_ref` varchar(12) DEFAULT NULL,
  PRIMARY KEY (`booking_id`),
  UNIQUE KEY `uq_booking_ref` (`booking_ref`),
  KEY `tourist_id` (`tourist_id`),
  KEY `idx_booking_dates` (`accommodation_id`,`status`,`check_in_date`,`check_out_date`),
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`tourist_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`accommodation_id`) REFERENCES `accommodations` (`accommodation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_log` (
  `sms_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `status` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`sms_id`),
  KEY `idx_phone` (`phone`,`created_at`),
  KEY `idx_ip` (`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `host_agreements` (
  `agreement_id` int(11) NOT NULL AUTO_INCREMENT,
  `host_id` int(11) NOT NULL,
  `version` varchar(20) NOT NULL,
  `terms_snapshot` text NOT NULL,
  `accepted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `accepted_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`agreement_id`),
  KEY `idx_host` (`host_id`,`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `host_commission_payments` (
  `hcp_id` int(11) NOT NULL AUTO_INCREMENT,
  `host_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `method` enum('mpesa','bank_transfer') NOT NULL,
  `reference` varchar(120) NOT NULL,
  `status` enum('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `review_note` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`hcp_id`),
  KEY `idx_host` (`host_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `certificates` (
  `cert_no` varchar(20) NOT NULL,
  `kind` enum('host','listing') NOT NULL,
  `subject_id` int(11) NOT NULL,
  `issued_to` varchar(160) NOT NULL,
  `detail` varchar(255) DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `issued_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`cert_no`),
  UNIQUE KEY `uq_subject` (`kind`,`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `currencies` (
  `code` char(3) NOT NULL,
  `name` varchar(60) NOT NULL,
  `kes_per_unit` decimal(14,4) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `favourites` (
  `favourite_id` int(11) NOT NULL AUTO_INCREMENT,
  `tourist_id` int(11) NOT NULL,
  `item_type` enum('accommodation','attraction') NOT NULL,
  `item_id` int(11) NOT NULL,
  `saved_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`favourite_id`),
  UNIQUE KEY `uq_favourite_item` (`tourist_id`,`item_type`,`item_id`),
  CONSTRAINT `favourites_ibfk_1` FOREIGN KEY (`tourist_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `guest_reviews` (
  `guest_review_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `host_id` int(11) NOT NULL,
  `tourist_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL,
  `review_text` varchar(1000) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `hidden_at` datetime DEFAULT NULL,
  PRIMARY KEY (`guest_review_id`),
  UNIQUE KEY `uq_guest_review_booking` (`booking_id`),
  KEY `idx_guest_review_tourist` (`tourist_id`),
  KEY `fk_guest_review_host` (`host_id`),
  CONSTRAINT `fk_guest_review_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`),
  CONSTRAINT `fk_guest_review_host` FOREIGN KEY (`host_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_guest_review_tourist` FOREIGN KEY (`tourist_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `host_verifications` (
  `verification_id` int(11) NOT NULL AUTO_INCREMENT,
  `host_id` int(11) NOT NULL,
  `legal_name` varchar(120) NOT NULL,
  `id_type` enum('National ID','Passport') NOT NULL DEFAULT 'National ID',
  `id_number` varchar(20) DEFAULT NULL,
  `id_photo` varchar(255) DEFAULT NULL,
  `business_ref` varchar(60) DEFAULT NULL,
  `status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `reason` varchar(500) DEFAULT NULL,
  `submitted_at` datetime NOT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`verification_id`),
  UNIQUE KEY `uq_verification_host` (`host_id`),
  KEY `idx_verification_status` (`status`),
  CONSTRAINT `fk_verification_host` FOREIGN KEY (`host_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `house_rules` (
  `rule_id` int(11) NOT NULL AUTO_INCREMENT,
  `accommodation_id` int(11) NOT NULL,
  `rule_description` text NOT NULL,
  PRIMARY KEY (`rule_id`),
  KEY `accommodation_id` (`accommodation_id`),
  CONSTRAINT `house_rules_ibfk_1` FOREIGN KEY (`accommodation_id`) REFERENCES `accommodations` (`accommodation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `attempt_id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_email_ip` (`email`,`ip`,`attempted_at`),
  KEY `idx_ip` (`ip`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `media_chunks` (
  `path` varchar(190) NOT NULL,
  `seq` int(11) NOT NULL,
  `data` mediumblob NOT NULL,
  PRIMARY KEY (`path`,`seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `media_files` (
  `path` varchar(190) NOT NULL,
  `mime` varchar(50) NOT NULL,
  `size` int(11) NOT NULL,
  `sha1` char(40) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`path`),
  KEY `idx_sha1` (`sha1`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_threads` (
  `thread_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `accommodation_id` int(11) NOT NULL,
  `tourist_id` int(11) NOT NULL,
  `host_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`thread_id`),
  UNIQUE KEY `uq_thread_listing_tourist` (`accommodation_id`,`tourist_id`),
  KEY `idx_thread_tourist` (`tourist_id`),
  KEY `idx_thread_host` (`host_id`),
  KEY `idx_thread_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `message_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `thread_id` int(10) unsigned NOT NULL,
  `sender_id` int(11) NOT NULL,
  `body` varchar(1000) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`message_id`),
  KEY `idx_message_thread_created` (`thread_id`,`created_at`),
  KEY `idx_message_sender` (`sender_id`),
  KEY `idx_message_unread` (`thread_id`,`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(160) NOT NULL,
  `message` varchar(500) NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_user_read` (`user_id`,`is_read`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `osm_attraction_imports` (
  `import_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `osm_type` varchar(12) NOT NULL,
  `osm_id` bigint(20) unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'Attraction',
  `location_name` varchar(150) DEFAULT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `tags_json` longtext DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `attraction_id` int(11) DEFAULT NULL,
  `imported_by` int(11) DEFAULT NULL,
  `imported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`import_id`),
  UNIQUE KEY `uq_osm_source` (`osm_type`,`osm_id`),
  KEY `idx_osm_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `reset_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `request_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reset_id`),
  UNIQUE KEY `uq_reset_token` (`token_hash`),
  KEY `idx_reset_user` (`user_id`,`created_at`),
  CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_audit` (
  `audit_id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `actor_role` varchar(20) NOT NULL,
  `action` varchar(30) NOT NULL,
  `old_status` varchar(20) DEFAULT NULL,
  `new_status` varchar(20) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`),
  KEY `idx_pay` (`payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `tourist_id` int(11) NOT NULL,
  `host_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_reference` varchar(120) DEFAULT NULL,
  `payment_status` enum('pending','confirmed','failed') DEFAULT 'pending',
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_proof` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `uq_payment_booking` (`booking_id`),
  KEY `tourist_id` (`tourist_id`),
  KEY `host_id` (`host_id`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`),
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`tourist_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`host_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `platform_payments` (
  `platform_payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `tourist_id` int(11) NOT NULL,
  `host_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `service_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `commission_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `method` enum('mpesa','bank_transfer') NOT NULL,
  `reference` varchar(120) NOT NULL,
  `proof_file` varchar(255) DEFAULT NULL,
  `status` enum('pending','confirmed','rejected','refunded') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `review_note` varchar(300) DEFAULT NULL,
  `refund_due` decimal(10,2) DEFAULT NULL,
  `refund_note` varchar(255) DEFAULT NULL,
  `refunded_amount` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`platform_payment_id`),
  UNIQUE KEY `uq_booking` (`booking_id`),
  KEY `idx_status` (`status`),
  KEY `idx_host` (`host_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reports` (
  `report_id` int(11) NOT NULL AUTO_INCREMENT,
  `reporter_id` int(11) NOT NULL,
  `report_type` enum('listing','review','tourist','incident') NOT NULL,
  `target_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `evidence_path` varchar(255) DEFAULT NULL,
  `incident_type` varchar(100) DEFAULT NULL,
  `status` enum('pending','reviewed','resolved','dismissed') DEFAULT 'pending',
  `admin_action` text DEFAULT NULL,
  `listing_suspended` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`report_id`),
  KEY `reporter_id` (`reporter_id`),
  CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reviews` (
  `review_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `tourist_id` int(11) NOT NULL,
  `accommodation_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL CHECK (`rating` between 1 and 5),
  `review_text` text DEFAULT NULL,
  `suspicion_score` tinyint(4) DEFAULT 0,
  `is_flagged` tinyint(1) DEFAULT 0,
  `host_reply` text DEFAULT NULL,
  `tourist_submitted` tinyint(1) DEFAULT 0,
  `host_submitted` tinyint(1) DEFAULT 0,
  `is_published` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `cleanliness_rating` tinyint(4) DEFAULT NULL,
  `location_rating` tinyint(4) DEFAULT NULL,
  `comfort_rating` tinyint(4) DEFAULT NULL,
  `value_rating` tinyint(4) DEFAULT NULL,
  `host_reply_hidden` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `booking_id` (`booking_id`),
  KEY `tourist_id` (`tourist_id`),
  KEY `accommodation_id` (`accommodation_id`),
  CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`),
  CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`tourist_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`accommodation_id`) REFERENCES `accommodations` (`accommodation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_settings` (
  `setting_key` varchar(60) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('tourist','host','admin') NOT NULL,
  `email_verified` tinyint(1) DEFAULT 0,
  `profile_photo` varchar(255) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `status` enum('active','suspended','banned') DEFAULT 'active',
  `verification_token` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `account_closed_at` datetime DEFAULT NULL,
  `account_deleted_at` datetime DEFAULT NULL,
  `profile_photo_data` mediumblob DEFAULT NULL,
  `profile_photo_mime` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `verify_codes` (
  `user_id` int(11) NOT NULL,
  `code_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `verify_waits` (
  `wait_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`wait_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

SET FOREIGN_KEY_CHECKS = 1;

-- v9.31: tourist alerts (also created automatically on first use)
CREATE TABLE IF NOT EXISTS `alert_log` (
  `user_id` int(11) NOT NULL,
  `kind` varchar(30) NOT NULL,
  `ref` varchar(60) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`kind`,`ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `saved_stay_state` (
  `tourist_id` int(11) NOT NULL,
  `accommodation_id` int(11) NOT NULL,
  `is_free` tinyint(1) NOT NULL,
  `checked_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tourist_id`,`accommodation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
