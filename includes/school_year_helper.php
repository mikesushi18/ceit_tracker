<?php
// includes/school_year_helper.php
// Call this after config.php is loaded. Sets up tables and returns active SY.

function setupSchoolYears($conn) {
    // Create school_years table if not exists
    $conn->query("CREATE TABLE IF NOT EXISTS school_years (
        id INT AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(20) NOT NULL UNIQUE,
        status ENUM('active','closed','pending') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        closed_at TIMESTAMP NULL
    )");
    $conn->query("ALTER TABLE school_years MODIFY COLUMN status ENUM('active','closed','pending') DEFAULT 'active'");

    // Add school_year_id to folders if missing
    $conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS school_year_id INT NULL");

    // Seed default SY if none exist
    $sy_count = $conn->query("SELECT COUNT(*) as c FROM school_years")->fetch_assoc()['c'];
    if ($sy_count == 0) {
        $y     = date('Y');
        $label = $y . '-' . ($y + 1);
        $conn->query("INSERT INTO school_years (label, status) VALUES ('$label', 'active')");
        $default_id = $conn->insert_id;
        $conn->query("UPDATE folders SET school_year_id = $default_id WHERE school_year_id IS NULL");
    }

    return $conn->query("SELECT * FROM school_years WHERE status='active' LIMIT 1")->fetch_assoc();
}

function getAllSchoolYears($conn) {
    return $conn->query("SELECT * FROM school_years ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
}
