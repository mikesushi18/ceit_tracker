<?php
session_start();
require_once '../includes/google_drive_backup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'sub-admin'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Administrator access is required.']);
    exit;
}

try {
    $jobs = array_map(static function (array $job): array {
        return [
            'id' => $job['id'] ?? '',
            'status' => $job['status'] ?? 'failed',
            'progress' => (int) ($job['progress'] ?? 0),
            'stage' => $job['stage'] ?? '',
            'reason' => $job['reason'] ?? 'Backup',
            'destination' => $job['destination'] ?? 'drive',
            'paused' => !empty($job['paused']),
            'pause_requested' => !empty($job['pause_requested']),
            'cancel_requested' => !empty($job['cancel_requested']),
            'user_id' => (int) ($job['user_id'] ?? 0),
            'created_at' => $job['created_at'] ?? '',
            'error' => $job['error'] ?? '',
            'manifest' => $job['manifest'] ?? null,
        ];
    }, googleDriveRecentBackupJobs());
    echo json_encode(['jobs' => $jobs], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}
