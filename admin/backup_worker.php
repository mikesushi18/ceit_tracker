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
try {
    $job = googleDriveLoadBackupJob($jobId);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()]);
    exit;
}
if (!$job) {
    http_response_code(404);
    echo json_encode(['error' => 'Backup job not found.']);
    exit;
}
if (($job['status'] ?? '') !== 'queued' || !empty($job['paused']) || !empty($job['cancel_requested'])) {
    http_response_code(202);
    echo json_encode(['status' => $job['status'] ?? 'unknown', 'paused' => !empty($job['paused'])]);
    exit;
}

$backupDirectory = googleDriveBackupDirectory();
if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to create the backup job folder.']);
    exit;
}
$workerLock = fopen($backupDirectory . DIRECTORY_SEPARATOR . 'worker.lock', 'c');
if (!$workerLock || !flock($workerLock, LOCK_EX | LOCK_NB)) {
    if ($workerLock) {
        fclose($workerLock);
    }
    http_response_code(202);
    echo json_encode(['status' => 'queued']);
    exit;
}

session_write_close();
ignore_user_abort(true);
set_time_limit(0);

try {
    $job = googleDriveUpdateBackupJob($jobId, static function (array $job): array {
        if (($job['status'] ?? '') === 'queued' && empty($job['paused']) && empty($job['cancel_requested'])) {
            $job['status'] = 'running';
            $job['progress'] = 1;
            $job['stage'] = 'Starting backup';
            $job['started_at'] = date(DATE_ATOM);
            $job['updated_at'] = date(DATE_ATOM);
        }
        return $job;
    });
    if (!$job || ($job['status'] ?? '') !== 'running') {
        echo json_encode(['status' => $job['status'] ?? 'unknown', 'paused' => !empty($job['paused'])]);
        exit;
    }

    $manifest = null;
    try {
        googleDriveWaitForBackupJob($jobId);
        $manifest = googleDriveCreateBackup(
            $conn,
            (int) $job['user_id'],
            (string) $job['reason'],
            isset($job['school_year_id']) ? (int) $job['school_year_id'] : null,
            static function (int $progress, string $stage) use ($jobId): void {
                googleDriveWaitForBackupJob($jobId);
                googleDriveUpdateBackupJob($jobId, static function (array $job) use ($progress, $stage): array {
                    if (($job['status'] ?? '') === 'running' && empty($job['paused']) && empty($job['pause_requested']) && empty($job['cancel_requested'])) {
                        $job['progress'] = max(1, min(99, $progress));
                        $job['stage'] = $stage;
                        $job['updated_at'] = date(DATE_ATOM);
                    }
                    return $job;
                });
                googleDriveWaitForBackupJob($jobId);
            },
            (string) ($job['destination'] ?? 'drive')
        );
        googleDriveWaitForBackupJob($jobId);
        $job = googleDriveUpdateBackupJob($jobId, static function (array $job) use ($manifest): array {
            if (!empty($job['cancel_requested'])) {
                throw new GoogleDriveBackupCancelledException('Backup cancelled.');
            }
            $job['status'] = 'completed';
            $job['progress'] = 100;
            $job['stage'] = 'Backup completed' . ((int) ($manifest['duplicate_count'] ?? 0) > 0
                ? ' (' . (int) $manifest['duplicate_count'] . ' unchanged files reused)'
                : '');
            $job['manifest'] = $manifest;
            $job['completed_at'] = date(DATE_ATOM);
            $job['updated_at'] = date(DATE_ATOM);
            return $job;
        });
    } catch (GoogleDriveBackupCancelledException $exception) {
        if (is_array($manifest)) {
            $backupId = (string) ($manifest['backup_id'] ?? '');
            if (preg_match('/^\d{8}_\d{6}_[a-f0-9]{6}$/', $backupId)) {
                $backupDirectory = googleDriveBackupDirectory();
                $expectedArchive = 'coltrace_pdf_submissions_' . $backupId . '.zip';
                foreach (($manifest['local_archives'] ?? []) as $archiveName) {
                    if (basename((string) $archiveName) === $expectedArchive) {
                        $archivePath = $backupDirectory . DIRECTORY_SEPARATOR . $expectedArchive;
                        if (is_file($archivePath)) {
                            unlink($archivePath);
                        }
                    }
                }
                $manifestPath = $backupDirectory . DIRECTORY_SEPARATOR . 'manifest_' . $backupId . '.json';
                if (is_file($manifestPath)) {
                    unlink($manifestPath);
                }
            }
        }
        $job = googleDriveUpdateBackupJob($jobId, static function (array $job): array {
            $job['status'] = 'cancelled';
            $job['stage'] = 'Backup cancelled';
            $job['cancelled_at'] = date(DATE_ATOM);
            $job['updated_at'] = date(DATE_ATOM);
            return $job;
        });
    } catch (Throwable $exception) {
        $job = googleDriveUpdateBackupJob($jobId, static function (array $job) use ($exception): array {
            $job['status'] = 'failed';
            $job['error'] = $exception->getMessage();
            $job['stage'] = 'Backup failed';
            $job['updated_at'] = date(DATE_ATOM);
            return $job;
        });
    }
    $job = googleDriveLoadBackupJob($jobId) ?? $job;
    echo json_encode(['status' => $job['status'], 'job' => $job], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
} finally {
    flock($workerLock, LOCK_UN);
    fclose($workerLock);
}
