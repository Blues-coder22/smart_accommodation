-- v9.33: host introduction shown on the public host profile (also added automatically by includes/host_profile.php)
ALTER TABLE users ADD COLUMN host_intro TEXT NULL DEFAULT NULL;
