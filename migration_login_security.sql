-- Progressive local-login lockout tracking.
-- Safe to run more than once.
USE ceit_tracker;

CREATE TABLE IF NOT EXISTS login_attempts (
    username VARCHAR(100) NOT NULL PRIMARY KEY,
    failed_attempts INT NOT NULL DEFAULT 0,
    lock_level INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
