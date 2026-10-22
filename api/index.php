<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$requestedStorageKey = $_GET['storage'] ?? null;
if (is_string($requestedStorageKey)) {
    require_once $projectRoot . '/includes/config.php';
    requireLogin();

    $storageKey = rawurldecode($requestedStorageKey);
    $accessible = false;
    $role = (string) ($_SESSION['role'] ?? '');
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $departmentStatement = $conn->prepare('SELECT department_id FROM users WHERE id = ?');
    $departmentStatement->bind_param('i', $userId);
    $departmentStatement->execute();
    $actorDepartmentId = (int) ($departmentStatement->get_result()->fetch_assoc()['department_id'] ?? 0);
    $departmentStatement->close();

    if (preg_match('~^announcements/([A-Za-z0-9_.-]+)$~', $storageKey, $matches)) {
        $announcementFile = $matches[1];
        $announcementStatement = $conn->prepare(
            'SELECT 1 FROM announcements
             WHERE image_file = ? OR pdf_file = ?
             UNION ALL
             SELECT 1 FROM announcement_edits
             WHERE previous_image_file = ? OR previous_pdf_file = ?
             LIMIT 1'
        );
        $announcementStatement->bind_param('ssss', $announcementFile, $announcementFile, $announcementFile, $announcementFile);
        $announcementStatement->execute();
        $accessible = $userId > 0 && (bool) $announcementStatement->get_result()->fetch_row();
        $announcementStatement->close();
    } elseif (preg_match('~^avatars/([A-Za-z0-9_.-]+)$~', $storageKey, $matches)) {
        $avatarStatement = $conn->prepare('SELECT 1 FROM users WHERE profile_photo = ? LIMIT 1');
        $avatarStatement->bind_param('s', $matches[1]);
        $avatarStatement->execute();
        $accessible = $userId > 0 && (bool) $avatarStatement->get_result()->fetch_row();
        $avatarStatement->close();
    } elseif (preg_match('/^file_[A-Za-z0-9_.-]+$/', $storageKey)) {
        $statement = $conn->prepare(
            'SELECT f.uploaded_by, f.uploaded_via_proxy_id, d.id AS department_id
             FROM files f
             JOIN folders fo ON fo.id = f.folder_id
             LEFT JOIN departments d ON d.id = fo.department_id
             WHERE f.file_name = ?'
        );
        $statement->bind_param('s', $storageKey);
        $statement->execute();
        $storedFiles = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        foreach ($storedFiles as $storedFile) {
            if (
                in_array($role, ['admin', 'sub-admin'], true)
                || ($role === 'chairperson' && (int) $storedFile['department_id'] === $actorDepartmentId)
                || ($role === 'faculty' && (
                    (int) $storedFile['uploaded_by'] === $userId
                    || (int) ($storedFile['uploaded_via_proxy_id'] ?? 0) === $userId
                ))
            ) {
                $accessible = true;
                break;
            }
        }
    } elseif (str_starts_with($storageKey, 'clearance_submissions/')) {
        $statement = $conn->prepare(
            'SELECT cs.faculty_id, u.department_id
             FROM clearance_submissions cs
             LEFT JOIN users u ON u.id = cs.faculty_id
             WHERE cs.file_name = ? LIMIT 1'
        );
        $fileName = basename($storageKey);
        $statement->bind_param('s', $fileName);
        $statement->execute();
        $submission = $statement->get_result()->fetch_assoc();
        $statement->close();
        $accessible = $submission && (
            in_array($role, ['admin', 'sub-admin'], true)
            || ($role === 'chairperson' && (int) $submission['department_id'] === $actorDepartmentId)
            || ($role === 'faculty' && (int) $submission['faculty_id'] === $userId)
        );
    } elseif (preg_match('~^clearance/([A-Za-z0-9_.-]+)$~', $storageKey, $matches)) {
        $formStatement = $conn->prepare('SELECT 1 FROM clearance_forms WHERE file_name = ? LIMIT 1');
        $formStatement->bind_param('s', $matches[1]);
        $formStatement->execute();
        $accessible = in_array($role, ['admin', 'sub-admin', 'faculty'], true)
            && (bool) $formStatement->get_result()->fetch_row();
        $formStatement->close();
    }

    if (!$accessible) {
        http_response_code(404);
        exit('File not found.');
    }

    $bytes = storageRead($projectRoot . '/uploads/' . $storageKey);
    if ($bytes === false) {
        http_response_code(404);
        exit('File not found.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($bytes));
    header('Content-Disposition: inline; filename="' . addcslashes(basename($storageKey), '"\\') . '"');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

$requestedPath = $_GET['path'] ?? 'index.php';
if (!is_string($requestedPath)) {
    http_response_code(404);
    exit('Not found.');
}
$requestedPath = rawurldecode($requestedPath);
$requestedPath = ltrim($requestedPath, '/');
if ($requestedPath === '') {
    $requestedPath = 'index.php';
}
if (
    str_contains($requestedPath, "\0")
    || str_contains($requestedPath, '\\')
    || preg_match('~(^|/)\.\.?(/|$)~', $requestedPath)
    || !str_ends_with(strtolower($requestedPath), '.php')
    || str_starts_with($requestedPath, 'uploads/')
    || str_starts_with($requestedPath, 'api/')
) {
    http_response_code(404);
    exit('Not found.');
}

$target = realpath($projectRoot . DIRECTORY_SEPARATOR . $requestedPath);
if ($target === false || !str_starts_with($target, $projectRoot . DIRECTORY_SEPARATOR) || !is_file($target)) {
    http_response_code(404);
    exit('Not found.');
}

$relativePath = str_replace('\\', '/', substr($target, strlen($projectRoot) + 1));
if (str_starts_with($relativePath, 'includes/') && $relativePath !== 'includes/file_viewer.php') {
    http_response_code(404);
    exit('Not found.');
}

$_SERVER['SCRIPT_NAME'] = '/' . $relativePath;
$_SERVER['SCRIPT_FILENAME'] = $target;
$_SERVER['PHP_SELF'] = '/' . $relativePath;
chdir(dirname($target));
require $target;
