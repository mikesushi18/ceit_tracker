-- ============================================================
-- Migration: Google Sign-In + OTP Password Reset
-- Run this ONCE in phpMyAdmin (or via MySQL client) on your
-- EXISTING ceit_tracker database. Safe to re-run (uses IF NOT EXISTS
-- guards via config.php too, but this file is the source of truth).
-- ============================================================

USE ceit_tracker;

-- Store folder deadlines with both date and time.
ALTER TABLE folder_groups MODIFY COLUMN deadline DATETIME NULL;

-- Add the dashboard-only administrator role for existing installations.
ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'sub-admin', 'chairperson', 'faculty') NOT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS employment_type ENUM('regular', 'contract_of_service') NULL AFTER role;

-- Keep the current school year active when creating a new pending year.
ALTER TABLE school_years MODIFY COLUMN status ENUM('active', 'closed', 'pending') DEFAULT 'active';

-- Email address linked to the account (used for Google Sign-In matching
-- and as the destination for OTP codes). Allowed domains are controlled
-- in includes/config.php via ALLOWED_EMAIL_DOMAINS (e.g. gmail.com,
-- cvsu.edu.ph).
ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL UNIQUE AFTER username;

-- Google's unique account identifier (the "sub" claim), stored once a
-- user successfully signs in with Google for the first time
ALTER TABLE users ADD COLUMN IF NOT EXISTS google_id VARCHAR(255) NULL UNIQUE AFTER email;

-- One-time codes for password reset / change, sent to the user's Gmail
CREATE TABLE IF NOT EXISTS otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    purpose VARCHAR(30) NOT NULL DEFAULT 'password_reset',
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    attempts INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Optional: backfill emails for your existing sample accounts so you can
-- test right away. Replace with real Gmail addresses.
-- UPDATE users SET email = 'yourgmail@gmail.com' WHERE username = 'admin';
