<?php
require_once '../includes/config.php';
require_once '../includes/google_drive_backup.php';
requireAdminDashboardAccess();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST is required.']);
    exit;
}

$csrfToken = (string) ($_SESSION['backup_csrf_token'] ?? '');
$providedToken = (string) ($_SERVER['HTTP_X_BACKUP_CSRF'] ?? '');
if ($csrfToken === '' || !hash_equals($csrfToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'The request could not be verified.']);
    exit;
}

$jobId = (string) ($_GET['job'] ?? '');
$action = (string) ($_POST['action'] ?? '');
if (!in_array($action, ['pause', 'resume', 'cancel'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Choose pause, resume, or cancel.']);
    exit;
}

try {
    $job = googleDriveUpdateBackupJob($jobId, static function (array $job) use ($action): array {
        if ((int) ($job['user_id'] ?? 0) !== (int) ($_SESSION['user_id'] ?? 0)) {
            throw new RuntimeException('You cannot control this backup job.');
        }
        if (!in_array($job['status'] ?? '', ['queued', 'running'], true)) {
            throw new DomainException('This backup is no longer active.');
        }

        if ($action === 'pause') {
            if (($job['status'] ?? '') === 'queued') {
                $job['paused'] = true;
                $job['stage'] = 'Backup paused';
            } elseif (empty($job['paused'])) {
                $job['pause_requested'] = true;
                $job['stage'] = 'Pausing after the current step';
            }
        } elseif ($action === 'resume') {
            $job['paused'] = false;
            $job['pause_requested'] = false;
            $job['stage'] = ($job['status'] ?? '') === 'queued' ? 'Waiting to start' : 'Resuming backup';
        } elseif (($job['status'] ?? '') === 'queued') {
            $job['status'] = 'cancelled';
            $job['stage'] = 'Backup cancelled';
            $job['cancelled_at'] = date(DATE_ATOM);
        } else {
            $job['cancel_requested'] = true;
            $job['stage'] = 'Cancelling after the current step';
        }

        $job['updated_at'] = date(DATE_ATOM);
        return $job;
    });

    if (!$job) {
        http_response_code(404);
        echo json_encode(['error' => 'Backup job not found.']);
        exit;
    }
    echo json_encode([
        'status' => $job['status'] ?? 'unknown',
        'paused' => !empty($job['paused']),
        'pause_requested' => !empty($job['pause_requested']),
        'cancel_requested' => !empty($job['cancel_requested']),
    ]);
} catch (DomainException $exception) {
    http_response_code(409);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    http_response_code(403);
    echo json_encode(['error' => $exception->getMessage()]);
}