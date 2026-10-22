<?php
// includes/config.php
define('DB_HOST', 'localhost');
define('DB_PORT', 3307);   // <-- your XAMPP MySQL port. Change this if it's different (check phpMyAdmin / my.ini).
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ceit_tracker');

define('UPLOAD_DIR', __DIR__ . '/../uploads/');

// Auto-detect BASE_URL from the actual host/port the app is being served on,
// so it works whether you access it via localhost, 127.0.0.1, a different
// port (e.g. :8080), etc. No need to hardcode it.
$__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__host   = $_SERVER['HTTP_HOST'] ?? 'localhost:8080'; // includes port if non-standard, e.g. localhost:8080

// includes/config.php always lives at <project_root>/includes/config.php,
// so the project root's URL path is __DIR__ one level up, translated from
// a filesystem path to a URL path relative to the web server's document root.
$__docRoot    = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
$__projectDir = str_replace('\\', '/', rtrim(dirname(__DIR__), '/')); // one level up from /includes
$__urlPath    = ($__docRoot !== '' && str_starts_with($__projectDir, $__docRoot))
    ? substr($__projectDir, strlen($__docRoot))
    : '/ceit_tracker'; // fallback if document root can't be determined

define('BASE_URL', $__scheme . '://' . $__host . $__urlPath);

// ── Google Sign-In ──
// Get this from https://console.cloud.google.com/ > APIs & Services > Credentials
// (OAuth client ID, type "Web application"). Add BASE_URL as an
// "Authorized JavaScript origin" (no trailing slash, e.g. http://localhost).
define('GOOGLE_CLIENT_ID', '324607446073-jg6j0sbare8ot16ls68b1lstt6t5spet.apps.googleusercontent.com');

// Only allow these email domains to be linked / used to sign in.
// Add more domains here anytime — e.g. your school's Google Workspace domain.
define('ALLOWED_EMAIL_DOMAINS', ['cvsu.edu.ph']);

// ── Gmail SMTP (for sending OTP codes) ──
// Use an App Password, NOT your real Gmail password:
// https://myaccount.google.com/apppasswords (requires 2-Step Verification ON)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'mikecsv18@gmail.com');
define('SMTP_APP_PASSWORD', 'mnyt yqvh ekuo rikb');
define('SMTP_FROM_NAME', 'ColTrace - CEIT File Tracking System');

// OTP settings
define('OTP_LENGTH', 6);
define('OTP_EXPIRY_MINUTES', 5);
define('OTP_MAX_ATTEMPTS', 5);
define('OTP_MAX_REQUESTS_PER_HOUR', 5);

// Create upload dir if not exists
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}

$conn->set_charset('utf8mb4');

// Repair databases where activity_log.id was imported without AUTO_INCREMENT.
$activity_log_id = $conn->query("SHOW COLUMNS FROM activity_log LIKE 'id'");
if ($activity_log_id->num_rows !== 1) {
    throw new RuntimeException('The activity_log table must contain an id column.');
}
$activity_log_id_column = $activity_log_id->fetch_assoc();
$activity_log_id->free();
if (stripos($activity_log_id_column['Extra'], 'auto_increment') === false) {
    $zero_activity_id = $conn->query("SELECT 1 FROM activity_log WHERE id = 0 LIMIT 1");
    if ($zero_activity_id->num_rows > 0) {
        $zero_activity_id->free();
        $next_activity_id_result = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM activity_log");
        $next_activity_id = $next_activity_id_result->fetch_assoc()['next_id'];
        $next_activity_id_result->free();

        $stmt = $conn->prepare("UPDATE activity_log SET id = ? WHERE id = 0");
        $stmt->bind_param('i', $next_activity_id);
        $stmt->execute();
        $stmt->close();
    } else {
        $zero_activity_id->free();
    }
    $conn->query("ALTER TABLE activity_log MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
}

// Keep the subject catalog compatible with installations created before
// faculty ownership and department scoping were added.
$conn->query("CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NULL,
    faculty_id INT NULL,
    subject_code VARCHAR(100) NOT NULL,
    subject_description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$conn->query("ALTER TABLE subjects ADD COLUMN IF NOT EXISTS department_id INT NULL AFTER id");
$conn->query("ALTER TABLE subjects ADD COLUMN IF NOT EXISTS faculty_id INT NULL AFTER department_id");
$conn->query("ALTER TABLE subjects ADD COLUMN IF NOT EXISTS subject_description VARCHAR(255) NULL AFTER subject_code");
$legacy_description = $conn->query("SHOW COLUMNS FROM subjects LIKE 'description'");
if ($legacy_description && $legacy_description->num_rows > 0) {
    $conn->query("UPDATE subjects SET subject_description = description WHERE subject_description IS NULL");
}
$legacy_subject_index = $conn->query("SHOW INDEX FROM subjects WHERE Key_name = 'subject_code'");
if ($legacy_subject_index && $legacy_subject_index->num_rows > 0) {
    $conn->query("ALTER TABLE subjects DROP INDEX subject_code");
}
$subject_index = $conn->query("SHOW INDEX FROM subjects WHERE Key_name = 'unique_faculty_subject'");
if (!$subject_index || $subject_index->num_rows === 0) {
    $conn->query("ALTER TABLE subjects ADD UNIQUE KEY unique_faculty_subject (faculty_id, subject_code)");
}

session_start();

require_once __DIR__ . '/role_sidebar.php';
if (is_callable('injectRoleSidebar')) {
    ob_start('injectRoleSidebar');
}

function redirect($url) {
    header("Location: $url");
    exit();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect(BASE_URL . '/index.php');
    }
}

function requireRole($role) {
    requireLogin();
    if ($_SESSION['role'] !== $role) {
        redirect(BASE_URL . '/index.php?error=unauthorized');
    }
}

function requireAdminDashboardAccess() {
    requireLogin();
    if (!in_array($_SESSION['role'], ['admin', 'sub-admin'], true)) {
        redirect(BASE_URL . '/index.php?error=unauthorized');
    }
}

function logActivity($conn, $user_id, $action, $details = '') {
    $stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, details) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $user_id, $action, $details);
    $stmt->execute();
    $stmt->close();
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

function isAllowedEmail($email) {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    foreach (ALLOWED_EMAIL_DOMAINS as $domain) {
        if (str_ends_with($email, '@' . strtolower($domain))) return true;
    }
    return false;
}

function allowedEmailDomainsLabel() {
    return implode(' o ', array_map(fn($d) => '@' . $d, ALLOWED_EMAIL_DOMAINS));
}

// Starts an authenticated session for a user row fetched from `users`.
// Used by both the local-password login and the Google Sign-In login,
// so both paths stay in sync.
function loginUser($conn, $user) {
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['email']         = $user['email'] ?? '';
    $_SESSION['full_name']     = $user['full_name'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['department_id'] = $user['department_id'];
    logActivity($conn, $user['id'], 'LOGIN', 'User logged in');
}

function dashboardUrlForRole($role) {
    return match ($role) {
        'admin'       => BASE_URL . '/admin/dashboard.php',
        'sub-admin'   => BASE_URL . '/admin/dashboard.php',
        'chairperson' => BASE_URL . '/chairperson/dashboard.php',
        'faculty'     => BASE_URL . '/faculty/dashboard.php',
        default       => BASE_URL . '/index.php',
    };
}
?>
