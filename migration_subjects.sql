-- Run this once on an existing CEIT Tracker database.
USE ceit_tracker;

CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    faculty_id INT NOT NULL,
    subject_code VARCHAR(100) NOT NULL,
    subject_description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_faculty_subject (faculty_id, subject_code),
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (faculty_id) REFERENCES users(id)
);

-- Compatibility for databases that already had a shared subjects table.
ALTER TABLE subjects ADD COLUMN IF NOT EXISTS department_id INT NULL AFTER id;
ALTER TABLE subjects ADD COLUMN IF NOT EXISTS faculty_id INT NULL AFTER department_id;
ALTER TABLE subjects ADD COLUMN IF NOT EXISTS subject_description VARCHAR(255) NULL AFTER subject_code;

-- Copy the legacy description only when that column exists.
SET @copy_subject_description_sql = IF(
    EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'subjects'
          AND COLUMN_NAME = 'description'
    ),
    'UPDATE subjects SET subject_description = description WHERE subject_description IS NULL',
    'SELECT 1'
);
PREPARE copy_subject_description_stmt FROM @copy_subject_description_sql;
EXECUTE copy_subject_description_stmt;
DEALLOCATE PREPARE copy_subject_description_stmt;

-- Replace the old global subject-code uniqueness with faculty-scoped uniqueness.
SET @drop_old_subject_index_sql = IF(
    EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'subjects'
          AND INDEX_NAME = 'subject_code'
    ),
    'ALTER TABLE subjects DROP INDEX subject_code',
    'SELECT 1'
);
PREPARE drop_old_subject_index_stmt FROM @drop_old_subject_index_sql;
EXECUTE drop_old_subject_index_stmt;
DEALLOCATE PREPARE drop_old_subject_index_stmt;

SET @add_faculty_subject_index_sql = IF(
    EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'subjects'
          AND INDEX_NAME = 'unique_faculty_subject'
    ),
    'SELECT 1',
    'ALTER TABLE subjects ADD UNIQUE KEY unique_faculty_subject (faculty_id, subject_code)'
);
PREPARE add_faculty_subject_index_stmt FROM @add_faculty_subject_index_sql;
EXECUTE add_faculty_subject_index_stmt;
DEALLOCATE PREPARE add_faculty_subject_index_stmt;
