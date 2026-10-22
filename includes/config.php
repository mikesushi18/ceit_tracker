<?php
// includes/config.php
<<<<<<< HEAD
$__env = static function (string $name, string $default = ''): string {
    $value = getenv($name);
    return $value === false ? $default : $value;
};
=======
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);   // <-- your XAMPP MySQL port. Change this if it's different (check phpMyAdmin / my.ini).
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ceit_tracker');
>>>>>>> d23090be378f126c4ebd2a4f64cd9cc99bb61fb4

define('DB_HOST', $__env('DB_HOST', 'localhost'));
define('DB_PORT', (int) $__env('DB_PORT', '3307'));
define('DB_USER', $__env('DB_USER', 'root'));
define('DB_PASS', $__env('DB_PASS'));
define('DB_NAME', $__env('DB_NAME', 'ceit_tracker'));

define('PROJECT_ROOT', dirname(__DIR__));
$__uploadDir = $__env('COLTRACE_UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_DIR', rtrim($__uploadDir, '\\/') . DIRECTORY_SEPARATOR);
define('S3_ENDPOINT', rtrim($__env('S3_ENDPOINT'), '/'));
define('S3_BUCKET', $__env('S3_BUCKET'));
define('S3_REGION', $__env('S3_REGION', 'auto'));
define('S3_ACCESS_KEY', $__env('S3_ACCESS_KEY'));
define('S3_SECRET_KEY', $__env('S3_SECRET_KEY'));

// Auto-detect BASE_URL from the actual host/port the app is being served on,
// so it works whether you access it via localhost, 127.0.0.1, a different
// port (e.g. :8080), etc. No need to hardcode it.
<<<<<<< HEAD
$__baseUrl = rtrim($__env('APP_BASE_URL'), '/');
if ($__baseUrl === '') {
    $__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
    $__vercelHost = $__env('VERCEL_URL', $__env('VERCEL_PROJECT_PRODUCTION_URL'));
    if ($__vercelHost !== '') {
        $__scheme = 'https';
        $__host = $__vercelHost;
    }
}
=======
$__scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$__host   = $_SERVER['HTTP_HOST'] ?? 'localhost'; // includes port if non-standard, e.g. localhost:8080
>>>>>>> d23090be378f126c4ebd2a4f64cd9cc99bb61fb4

// includes/config.php always lives at <project_root>/includes/config.php,
// so the project root's URL path is __DIR__ one level up, translated from
// a filesystem path to a URL path relative to the web server's document root.
$__docRoot    = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
$__projectDir = str_replace('\\', '/', rtrim(dirname(__DIR__), '/')); // one level up from /includes
$__urlPath = !empty($__vercelHost)
    ? ''
    : (($__docRoot !== '' && str_starts_with($__projectDir, $__docRoot))
        ? substr($__projectDir, strlen($__docRoot))
        : '/ceit_tracker');
if ($__baseUrl === '') {
    $__baseUrl = $__scheme . '://' . $__host . $__urlPath;
}

define('BASE_URL', $__baseUrl);

// ── Google Sign-In ──
// Get this from https://console.cloud.google.com/ > APIs & Services > Credentials
// (OAuth client ID, type "Web application"). Add BASE_URL as an
// "Authorized JavaScript origin" (no trailing slash, e.g. http://localhost).
define('GOOGLE_CLIENT_ID', $__env('GOOGLE_CLIENT_ID', '324607446073-jg6j0sbare8ot16ls68b1lstt6t5spet.apps.googleusercontent.com'));

// Only allow these email domains to be linked / used to sign in.
// Add more domains here anytime — e.g. your school's Google Workspace domain.
define('ALLOWED_EMAIL_DOMAINS', ['cvsu.edu.ph']);

// ── Gmail SMTP (for sending OTP codes) ──
// Use an App Password, NOT your real Gmail password:
// https://myaccount.google.com/apppasswords (requires 2-Step Verification ON)
<<<<<<< HEAD
define('SMTP_HOST', $__env('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', (int) $__env('SMTP_PORT', '587'));
define('SMTP_USER', $__env('SMTP_USER'));
define('SMTP_APP_PASSWORD', $__env('SMTP_APP_PASSWORD'));
define('SMTP_FROM_NAME', $__env('SMTP_FROM_NAME', 'ColTrace - CEIT File Tracking System'));
=======
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'mikecsv18@gmail.com');
define('SMTP_APP_PASSWORD', 'mnyt yqvh ekuo rikb');
define('SMTP_FROM_NAME', 'CollTrace - CEIT File Tracking System');
>>>>>>> d23090be378f126c4ebd2a4f64cd9cc99bb61fb4

// OTP settings
define('OTP_LENGTH', 6);
define('OTP_EXPIRY_MINUTES', 5);
define('OTP_MAX_ATTEMPTS', 5);
define('OTP_MAX_REQUESTS_PER_HOUR', 5);

require_once __DIR__ . '/object_storage.php';

// Local development uses disk; Vercel uses durable S3-compatible object storage.
if ($__env('VERCEL') === '' && !is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

if ($__env('VERCEL') !== '' && (DB_HOST === '' || DB_HOST === 'localhost' || DB_HOST === '127.0.0.1')) {
    throw new RuntimeException('Set DB_HOST to a reachable managed database in the Vercel project environment variables.');
}
if ($__env('VERCEL') !== '' && !objectStorageConfigured()) {
    throw new RuntimeException('Configure S3_ENDPOINT, S3_BUCKET, S3_ACCESS_KEY, and S3_SECRET_KEY in the Vercel project environment variables.');
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}

$conn->set_charset('utf8mb4');

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

require_once __DIR__ . '/database_session_handler.php';
$sessionHandler = new DatabaseSessionHandler($conn);
session_set_save_handler($sessionHandler, true);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $__env('VERCEL') !== '' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
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
