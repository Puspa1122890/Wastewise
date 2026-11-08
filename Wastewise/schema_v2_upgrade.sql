-- ONLY needed if you already created the version 1 database and want to keep
-- it. If your tables are empty, it is simpler to import wastewise_db.sql
-- instead (it recreates everything).
--
-- Run in phpMyAdmin: select wastewise_db, open the SQL tab, paste, click Go.
-- Each statement fails harmlessly if it was already applied; just run the
-- remaining ones.

USE wastewise_db;

ALTER TABLE Households
    ADD COLUMN household_size TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER invite_code;

ALTER TABLE Products
    ADD COLUMN barcode VARCHAR(32) NULL AFTER category,
    ADD COLUMN outcome_date DATE NULL AFTER status;

ALTER TABLE Products
    MODIFY COLUMN status ENUM('active', 'used', 'wasted', 'unknown') NOT NULL DEFAULT 'active';

ALTER TABLE ProductLogs
    MODIFY COLUMN action ENUM('added', 'marked_used', 'marked_wasted', 'marked_unknown', 'edited') NOT NULL;

-- Fails if one user is in several households. Delete the extra rows first.
ALTER TABLE HouseholdMembers ADD UNIQUE KEY unique_user (user_id);

CREATE TABLE IF NOT EXISTS LoginAttempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_time (email, attempted_at),
    INDEX idx_ip_time (ip, attempted_at)
);
