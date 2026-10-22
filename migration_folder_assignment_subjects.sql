-- Run once on an existing CEIT Tracker database after migration_department_subjects.sql.
USE ceit_tracker;

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

CREATE TABLE IF NOT EXISTS folder_assignment_subjects (
    assignment_id INT NOT NULL,
    subject_id INT NOT NULL,
    PRIMARY KEY (assignment_id, subject_id),
    FOREIGN KEY (assignment_id) REFERENCES folder_assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES department_subjects(id) ON DELETE CASCADE
);

ALTER TABLE folder_assignments ADD COLUMN IF NOT EXISTS has_subject_scope TINYINT(1) NOT NULL DEFAULT 0;