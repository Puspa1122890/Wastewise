-- WasteWise database schema (version 2, complete)
--
-- WARNING: this file DROPS and recreates every table. Importing it again
-- later will erase all data. That is fine for a first install or a reset.
--
-- How to use: open phpMyAdmin, click "Import", choose this file, click Go.

CREATE DATABASE IF NOT EXISTS wastewise_db
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE wastewise_db;

DROP TABLE IF EXISTS LoginAttempts;
DROP TABLE IF EXISTS ProductLogs;
DROP TABLE IF EXISTS Products;
DROP TABLE IF EXISTS HouseholdMembers;
DROP TABLE IF EXISTS Households;
DROP TABLE IF EXISTS Users;

CREATE TABLE Users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE Households (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    invite_code VARCHAR(10) NOT NULL UNIQUE,
    -- Number of people who eat from this household's food. Declared by the
    -- user, NOT the number of app accounts. Only used for the cold-start
    -- fallback estimate in the scoring (see docs/DESIGN_NOTES.md).
    household_size TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES Users(id) ON DELETE CASCADE
);

-- A user belongs to exactly one household (UNIQUE on user_id).
CREATE TABLE HouseholdMembers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    household_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('creator', 'member') DEFAULT 'member',
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (household_id) REFERENCES Households(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES Users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user (user_id)
);

CREATE TABLE Products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    household_id INT NOT NULL,
    added_by INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    category VARCHAR(50) NOT NULL,
    barcode VARCHAR(32) NULL,
    purchase_date DATE NOT NULL,
    expiry_date DATE NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    -- Total price paid for this entry (not per unit).
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    -- 'active'  : no outcome recorded yet
    -- 'used'    : the household reported it was used
    -- 'wasted'  : the household reported it was thrown away
    -- 'unknown' : the household could not say what happened
    -- The system never sets 'used' or 'wasted' on its own.
    status ENUM('active', 'used', 'wasted', 'unknown') NOT NULL DEFAULT 'active',
    -- The date the household says the outcome happened (not the date it
    -- was typed in). Used for the usage-time estimates.
    outcome_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (household_id) REFERENCES Households(id) ON DELETE CASCADE,
    FOREIGN KEY (added_by) REFERENCES Users(id) ON DELETE CASCADE,
    INDEX idx_household_status (household_id, status),
    INDEX idx_history (household_id, category, status, outcome_date)
);

CREATE TABLE ProductLogs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    action ENUM('added', 'marked_used', 'marked_wasted', 'marked_unknown', 'edited') NOT NULL,
    performed_by INT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES Products(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES Users(id) ON DELETE CASCADE,
    INDEX idx_product (product_id)
);

-- Used to limit repeated failed logins (brute-force protection).
CREATE TABLE LoginAttempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_time (email, attempted_at),
    INDEX idx_ip_time (ip, attempted_at)
);
