-- ============================================================
-- CEIT CollTrace - File Tracking System
-- Database Setup Script
-- Run this ONCE in phpMyAdmin
-- ============================================================

CREATE DATABASE IF NOT EXISTS ceit_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ceit_tracker;

CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO departments (code, name) VALUES
('DAFE',  'Department of Agricultural and Food Engineering'),
('DCEA',  'Department of Civil Engineering and Allied Studies'),
('DCEEE', 'Department of Computer, Electronic, and Electrical Engineering'),
('DIET',  'Department of Industrial Engineering and Technology'),
('DIT',   'Department of Information Technology');

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'sub-admin', 'chairperson', 'faculty') NOT NULL,
    employment_type ENUM('regular', 'contract_of_service') NULL,
    department_id INT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id)
);

-- Default admin: username=admin, password=admin123
INSERT INTO users (username, password, full_name, role, department_id)
VALUES ('admin', 'admin123', 'System Administrator', 'admin', NULL);

-- Sample test accounts
INSERT INTO users (username, password, full_name, role, department_id)
VALUES ('chair_diet', 'chair123', 'Dr. Maria Santos', 'chairperson', 1);

INSERT INTO users (username, password, full_name, role, department_id)
VALUES ('faculty1', 'faculty123', 'Prof. Jose Reyes', 'faculty', 1);

INSERT INTO users (username, password, full_name, role, department_id)
VALUES ('faculty2', 'faculty123', 'Prof. Ana Cruz', 'faculty', 1);

CREATE TABLE IF NOT EXISTS school_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(20) NOT NULL UNIQUE,
    status ENUM('active','closed','pending') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL
);

-- Default school year (change to current SY)
INSERT INTO school_years (label, status) VALUES ('2025-2026', 'active');

CREATE TABLE IF NOT EXISTS folder_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    semester VARCHAR(100) DEFAULT 'End of Semester',
    description TEXT,
    deadline DATETIME NULL,
    status ENUM('active','pending') DEFAULT 'active',
    school_year_id INT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS folders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folder_name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    department_id INT NOT NULL,
    created_by INT NOT NULL,
    semester VARCHAR(30) DEFAULT 'End of Semester',
    school_year VARCHAR(20) NULL,
    school_year_id INT NULL,
    group_id INT NULL,
    subfolder_position INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (school_year_id) REFERENCES school_years(id)
);

CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folder_id INT NOT NULL,
    uploaded_by INT NOT NULL,
    uploaded_via_proxy_id INT NULL,
    proxy_upload_group_id CHAR(32) NULL,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    content_hash CHAR(64) NULL,
    subject_code VARCHAR(100) NULL,
    description TEXT NULL,
    file_size INT NOT NULL,
    file_type VARCHAR(100) NOT NULL,
    remarks TEXT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (folder_id) REFERENCES folders(id),
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS file_feedback (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    faculty_id INT NOT NULL,
    created_by INT NULL,
    parent_feedback_id BIGINT NULL,
    message TEXT NOT NULL,
    entry_type ENUM('comment', 'task') NOT NULL DEFAULT 'comment',
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    INDEX idx_file_feedback_faculty (faculty_id, is_read, created_at),
    INDEX idx_file_feedback_file (file_id, created_at),
    INDEX idx_file_feedback_parent (parent_feedback_id),
    CONSTRAINT fk_file_feedback_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
    CONSTRAINT fk_file_feedback_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_file_feedback_author FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_file_feedback_parent FOREIGN KEY (parent_feedback_id) REFERENCES file_feedback(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS file_feedback_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    feedback_id BIGINT NOT NULL,
    recipient_id INT NOT NULL,
    event_type ENUM('new_feedback', 'task_completed', 'file_replaced', 'reply') NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_feedback_recipient_event (feedback_id, recipient_id, event_type),
    INDEX idx_feedback_notifications_recipient (recipient_id, is_read, created_at),
    CONSTRAINT fk_feedback_notification_feedback FOREIGN KEY (feedback_id) REFERENCES file_feedback(id) ON DELETE CASCADE,
    CONSTRAINT fk_feedback_notification_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS folder_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folder_id INT NOT NULL,
    assigned_to_user_id INT NOT NULL,
    assigned_by_user_id INT NOT NULL,
    can_delegate TINYINT(1) DEFAULT 0,
    has_subject_scope TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (folder_id) REFERENCES folders(id),
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id),
    FOREIGN KEY (assigned_by_user_id) REFERENCES users(id)
);

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

CREATE TABLE IF NOT EXISTS folder_assignment_subjects (
    assignment_id INT NOT NULL,
    subject_id INT NOT NULL,
    PRIMARY KEY (assignment_id, subject_id),
    FOREIGN KEY (assignment_id) REFERENCES folder_assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES department_subjects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    posted_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (posted_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS login_attempts (
    username VARCHAR(100) NOT NULL PRIMARY KEY,
    failed_attempts INT NOT NULL DEFAULT 0,
    lock_level INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
