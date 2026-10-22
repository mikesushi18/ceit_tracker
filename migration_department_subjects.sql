-- Run once on an existing CEIT Tracker database.
USE ceit_tracker;

CREATE TABLE IF NOT EXISTS department_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    subject_code VARCHAR(100) NOT NULL,
    subject_description VARCHAR(255) NOT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_department_subject (department_id, subject_code),
    FOREIGN KEY (department_id) REFERENCES departments(id)
);

ALTER TABLE department_subjects ADD COLUMN IF NOT EXISTS created_by INT NULL;