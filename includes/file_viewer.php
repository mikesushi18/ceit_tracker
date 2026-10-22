<?php
// includes/file_viewer.php
// Serves a file inline for browser preview (used by admin & chairperson).
require_once __DIR__ . '/config.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit('Access denied.');
}

$role = $_SESSION['role'];
if (!in_array($role, ['admin', 'chairperson'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

$file_id = intval($_GET['id'] ?? 0);
if (!$file_id) { http_response_code(404); exit('File not found.'); }

// For chairperson: only files from their own department
if ($role === 'chairperson') {
    $dept_id = $_SESSION['department_id'];
    $file = $conn->query("
        SELECT fi.*, fo.department_id
        FROM files fi
        JOIN folders fo ON fo.id = fi.folder_id
        JOIN users u ON u.id = fi.uploaded_by
        WHERE fi.id = $file_id AND fo.department_id = $dept_id
    ")->fetch_assoc();
} else {
    $file = $conn->query("SELECT * FROM files WHERE id = $file_id")->fetch_assoc();
}

if (!$file) { http_response_code(404); exit('File not found.'); }

$filepath = UPLOAD_DIR . $file['file_name'];
if (!file_exists($filepath)) { http_response_code(404); exit('File not found on server.'); }

$mime = $file['file_type'];
$original = $file['original_name'];
$download = isset($_GET['dl']) && $_GET['dl'] === '1';

// Serve inline so browser can display it, or as attachment for download
header('Content-Type: ' . $mime);
if ($download) {
    header('Content-Disposition: attachment; filename="' . addslashes($original) . '"');
} else {
    header('Content-Disposition: inline; filename="' . addslashes($original) . '"');
}
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, max-age=3600');
readfile($filepath);
exit;
