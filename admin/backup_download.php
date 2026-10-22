<?php
require_once '../includes/config.php';
require_once '../includes/google_drive_backup.php';
requireAdminDashboardAccess();

$name = basename((string) ($_GET['file'] ?? ''));
if (!preg_match('/^coltrace_(database|uploads|pdf_submissions)_\d{8}_\d{6}_[a-f0-9]{6}\.zip$/', $name)) {
    http_response_code(404);
    exit('Backup archive not found.');
}
$path = googleDriveBackupDirectory() . DIRECTORY_SEPARATOR . $name;
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('Backup archive is no longer available on this computer.');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
