<?php

function googleDrivePrivateDirectory(): string
{
    return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'ceit_tracker_private';
}

function googleDriveBackupDirectory(): string
{
    return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'ceit_tracker_backups';
}

function googleDriveBackupJobsDirectory(): string
{
    return googleDriveBackupDirectory() . DIRECTORY_SEPARATOR . 'jobs';
}

function googleDriveBackupJobPath(string $jobId): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) {
        throw new InvalidArgumentException('Invalid backup job ID.');
    }
    return googleDriveBackupJobsDirectory() . DIRECTORY_SEPARATOR . $jobId . '.json';
}

function googleDriveSaveBackupJob(array $job): void
{
    $directory = googleDriveBackupJobsDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the private backup job folder.');
    }

    $path = googleDriveBackupJobPath((string) ($job['id'] ?? ''));
    $handle = fopen($path, 'c+');
    if (!$handle) {
        throw new RuntimeException('Unable to save backup job status.');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Unable to lock backup job status.');
    }
    $encoded = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || !ftruncate($handle, 0) || fseek($handle, 0) !== 0 || fwrite($handle, $encoded) === false || !fflush($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        throw new RuntimeException('Unable to write backup job status.');
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($path, 0600);
}

function googleDriveLoadBackupJob(string $jobId): ?array
{
    $path = googleDriveBackupJobPath($jobId);
    if (!is_file($path)) {
        return null;
    }
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('Unable to read backup job status.');
    }
    if (!flock($handle, LOCK_SH)) {
        fclose($handle);
        throw new RuntimeException('Unable to lock backup job status.');
    }
    $contents = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    $job = json_decode((string) $contents, true);
    return is_array($job) ? $job : null;
}

class GoogleDriveBackupCancelledException extends RuntimeException
{
}

function googleDriveUpdateBackupJob(string $jobId, callable $update): ?array
{
    $path = googleDriveBackupJobPath($jobId);
    if (!is_file($path)) {
        return null;
    }

    $handle = fopen($path, 'c+');
    if (!$handle) {
        throw new RuntimeException('Unable to update backup job status.');
    }
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Unable to lock backup job status.');
    }

    try {
        rewind($handle);
        $job = json_decode((string) stream_get_contents($handle), true);
        if (!is_array($job)) {
            return null;
        }
        $job = $update($job);
        $encoded = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_array($job) || $encoded === false || !ftruncate($handle, 0) || fseek($handle, 0) !== 0 || fwrite($handle, $encoded) === false || !fflush($handle)) {
            throw new RuntimeException('Unable to write backup job status.');
        }
        @chmod($path, 0600);
        return $job;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function googleDriveWaitForBackupJob(string $jobId): array
{
    while (true) {
        $job = googleDriveLoadBackupJob($jobId);
        if (!$job) {
            throw new RuntimeException('Backup job status is unavailable.');
        }
        if (($job['status'] ?? '') === 'cancelled' || !empty($job['cancel_requested'])) {
            throw new GoogleDriveBackupCancelledException('Backup cancelled.');
        }
        if (!empty($job['pause_requested']) && ($job['status'] ?? '') === 'running') {
            googleDriveUpdateBackupJob($jobId, static function (array $current): array {
                if (empty($current['cancel_requested']) && !empty($current['pause_requested'])) {
                    $current['paused'] = true;
                    $current['pause_requested'] = false;
                    $current['stage'] = 'Backup paused';
                    $current['updated_at'] = date(DATE_ATOM);
                }
                return $current;
            });
            continue;
        }
        if (!empty($job['paused'])) {
            usleep(250000);
            continue;
        }
        return $job;
    }
}

function googleDriveQueueBackup(int $userId, string $reason, ?int $schoolYearId = null, string $destination = 'drive'): array
{
    if (!in_array($destination, ['drive', 'local', 'both'], true)) {
        throw new InvalidArgumentException('Choose Google Drive, local storage, or both as the backup destination.');
    }
    if (in_array($destination, ['drive', 'both'], true) && !googleDriveLoadToken()) {
        throw new RuntimeException('Connect a CvSU Google account in Backup Storage before creating a backup.');
    }

    $job = [
        'id' => bin2hex(random_bytes(16)),
        'status' => 'queued',
        'progress' => 0,
        'stage' => 'Waiting to start',
        'reason' => $reason,
        'destination' => $destination,
        'user_id' => $userId,
        'school_year_id' => $schoolYearId,
        'created_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
    ];
    googleDriveSaveBackupJob($job);
    return $job;
}

function googleDriveRecentBackupJobs(int $limit = 10): array
{
    $directory = googleDriveBackupJobsDirectory();
    if (!is_dir($directory)) {
        return [];
    }
    $paths = glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $jobs = [];
    foreach ($paths as $path) {
        $job = googleDriveLoadBackupJob((string) pathinfo($path, PATHINFO_FILENAME));
        if ($job) {
            $jobs[] = $job;
        }
    }
    usort($jobs, static fn(array $left, array $right): int => strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? '')));
    $activeJobs = array_values(array_filter($jobs, static fn(array $job): bool => in_array($job['status'] ?? '', ['queued', 'running'], true)));
    $finishedJobs = array_values(array_filter($jobs, static fn(array $job): bool => !in_array($job['status'] ?? '', ['queued', 'running'], true)));
    return array_slice(array_merge($activeJobs, $finishedJobs), 0, count($activeJobs) + max(1, min($limit, 50)));
}

function googleDriveRedirectUri(): string
{
    return BASE_URL . '/admin/google_drive_oauth.php';
}

function googleDriveTokenPath(): string
{
    return googleDrivePrivateDirectory() . DIRECTORY_SEPARATOR . 'google_drive_token.json';
}

function googleDriveLoadToken(): ?array
{
    $path = googleDriveTokenPath();
    if (!is_file($path)) {
        return null;
    }

    $token = json_decode((string) file_get_contents($path), true);
    return is_array($token) && !empty($token['refresh_token']) ? $token : null;
}

function googleDriveSaveToken(array $token): void
{
    $directory = googleDrivePrivateDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the private Google Drive configuration folder.');
    }

    $written = file_put_contents(googleDriveTokenPath(), json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($written === false) {
        throw new RuntimeException('Unable to save the Google Drive account connection.');
    }
    @chmod(googleDriveTokenPath(), 0600);
}

function googleDriveClientSecret(): string
{
    $path = googleDrivePrivateDirectory() . DIRECTORY_SEPARATOR . 'google_drive.php';
    if (!is_file($path)) {
        throw new RuntimeException('Google Drive OAuth is not configured. Contact your system administrator.');
    }

    $config = require $path;
    if (!is_array($config) || empty($config['client_secret'])) {
        throw new RuntimeException('The private Google Drive configuration file must return a client_secret value.');
    }

    return (string) $config['client_secret'];
}

function googleDriveHttp(string $url, string $method = 'GET', array $headers = [], ?string $body = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Google Drive backups. Enable extension=curl in php.ini and restart Apache.');
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($curl);
    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Google Drive request failed: ' . $error);
    }

    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);

    $rawHeaders = substr($response, 0, $headerSize);
    $responseHeaders = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        $separator = strpos($line, ':');
        if ($separator !== false) {
            $responseHeaders[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
        }
    }

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => substr($response, $headerSize),
    ];
}

function googleDriveDecodeResponse(array $response): array
{
    $data = json_decode($response['body'], true);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $message = '';
        if (is_array($data)) {
            $error = $data['error'] ?? null;
            $message = is_array($error) ? (string) ($error['message'] ?? '') : (is_string($error) ? $error : '');
            if ($message === '') {
                $message = (string) ($data['error_description'] ?? '');
            }
        }
        throw new RuntimeException('Google Drive returned HTTP ' . $response['status'] . ($message !== '' ? ': ' . $message : '.'));
    }
    if (!is_array($data)) {
        throw new RuntimeException('Google Drive returned an unreadable response.');
    }
    return $data;
}

function googleDriveExchangeCode(string $code): array
{
    $response = googleDriveHttp(
        'https://oauth2.googleapis.com/token',
        'POST',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'code' => $code,
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => googleDriveClientSecret(),
            'redirect_uri' => googleDriveRedirectUri(),
            'grant_type' => 'authorization_code',
        ])
    );
    return googleDriveDecodeResponse($response);
}

function googleDriveAccessToken(): string
{
    $token = googleDriveLoadToken();
    if (!$token) {
        throw new RuntimeException('Connect a CvSU Google account before creating a backup.');
    }
    if (!empty($token['access_token']) && (int) ($token['expires_at'] ?? 0) > time() + 60) {
        return $token['access_token'];
    }

    $response = googleDriveHttp(
        'https://oauth2.googleapis.com/token',
        'POST',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => googleDriveClientSecret(),
            'refresh_token' => $token['refresh_token'],
            'grant_type' => 'refresh_token',
        ])
    );
    $fresh = googleDriveDecodeResponse($response);
    $token['access_token'] = $fresh['access_token'] ?? '';
    $token['expires_at'] = time() + (int) ($fresh['expires_in'] ?? 0);
    googleDriveSaveToken($token);
    if ($token['access_token'] === '') {
        throw new RuntimeException('Google did not return an access token. Reconnect the account and try again.');
    }
    return $token['access_token'];
}

function googleDriveApiRequest(string $url, string $method, array $payload = []): array
{
    $accessToken = googleDriveAccessToken();
    $headers = ['Authorization: Bearer ' . $accessToken];
    $body = null;
    if ($payload) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
    return googleDriveDecodeResponse(googleDriveHttp($url, $method, $headers, $body));
}

function googleDriveFolderId(): string
{
    $token = googleDriveLoadToken();
    if (!empty($token['folder_id'])) {
        return $token['folder_id'];
    }

    $query = urlencode("name = 'CollTrace Backups' and mimeType = 'application/vnd.google-apps.folder' and trashed = false");
    $found = googleDriveApiRequest('https://www.googleapis.com/drive/v3/files?q=' . $query . '&fields=files(id,name)&pageSize=10', 'GET');
    if (!empty($found['files'][0]['id'])) {
        $folderId = $found['files'][0]['id'];
    } else {
        $created = googleDriveApiRequest('https://www.googleapis.com/drive/v3/files?fields=id,name', 'POST', [
            'name' => 'CollTrace Backups',
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);
        $folderId = $created['id'] ?? '';
    }
    if ($folderId === '') {
        throw new RuntimeException('Google Drive did not create or find the CollTrace Backups folder.');
    }

    $token['folder_id'] = $folderId;
    googleDriveSaveToken($token);
    return $folderId;
}

function googleDriveEnsureFolder(string $name, string $parentId, array &$cache): array
{
    $cacheKey = $parentId . "\0" . strtolower($name);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $escapedName = str_replace(['\\', "'"], ['\\\\', "\\'"], $name);
    $query = urlencode("'" . $parentId . "' in parents and name = '" . $escapedName . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false");
    $found = googleDriveApiRequest(
        'https://www.googleapis.com/drive/v3/files?q=' . $query . '&fields=files(id,name,webViewLink)&pageSize=10',
        'GET'
    );

    if (!empty($found['files'][0]['id'])) {
        $cache[$cacheKey] = $found['files'][0];
        return $cache[$cacheKey];
    }

    $created = googleDriveApiRequest('https://www.googleapis.com/drive/v3/files?fields=id,name,webViewLink', 'POST', [
        'name' => $name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$parentId],
    ]);
    if (empty($created['id'])) {
        throw new RuntimeException('Google Drive did not create the "' . $name . '" backup folder.');
    }
    $cache[$cacheKey] = $created;
    return $cache[$cacheKey];
}

function googleDriveUploadFile(string $path, string $name, string $folderId, string $mimeType = 'application/octet-stream'): array
{
    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('Unable to read the backup file before upload.');
    }
    $session = googleDriveHttp(
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id,name,webViewLink',
        'POST',
        [
            'Authorization: Bearer ' . googleDriveAccessToken(),
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: ' . $mimeType,
            'X-Upload-Content-Length: ' . $size,
        ],
        json_encode(['name' => $name, 'mimeType' => $mimeType, 'parents' => [$folderId]], JSON_UNESCAPED_SLASHES)
    );
    if ($session['status'] < 200 || $session['status'] >= 300 || empty($session['headers']['location'])) {
        googleDriveDecodeResponse($session);
        throw new RuntimeException('Google Drive did not provide an upload session.');
    }

    $curl = curl_init($session['headers']['location']);
    $file = fopen($path, 'rb');
    if (!$file) {
        throw new RuntimeException('Unable to open the backup file for upload.');
    }
    curl_setopt_array($curl, [
        CURLOPT_UPLOAD => true,
        CURLOPT_INFILE => $file,
        CURLOPT_INFILESIZE => $size,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $mimeType, 'Content-Length: ' . $size],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($body === false) {
        $error = curl_error($curl);
        curl_close($curl);
        fclose($file);
        throw new RuntimeException('Backup upload failed: ' . $error);
    }
    curl_close($curl);
    fclose($file);

    $result = googleDriveDecodeResponse(['status' => $status, 'body' => $body]);
    if (empty($result['id'])) {
        throw new RuntimeException('Google Drive did not confirm that the backup file was saved.');
    }
    return $result;
}

function googleDriveUploadSubmissionFiles(mysqli $conn, string $backupId, string $reason, string $localZipPath, ?int $schoolYearId = null, ?callable $progressCallback = null, string $destination = 'both'): array
{
    $uploadToDrive = in_array($destination, ['drive', 'both'], true);
    $createLocalZip = in_array($destination, ['local', 'both'], true);
    $backupFolder = null;
    $cache = [];
    if ($uploadToDrive) {
        $rootId = googleDriveFolderId();
        $backupFolderName = 'Backup ' . date('Y-m-d H-i-s') . ' - ' . $reason . ' - ' . substr($backupId, -6);
        $backupFolder = googleDriveEnsureFolder($backupFolderName, $rootId, $cache);
    }
    if ($progressCallback) {
        $progressCallback(2, 'Reading uploaded files');
    }
    $query = "
        SELECT fi.id, fi.file_name, fi.original_name, fi.file_type, fi.uploaded_by,
               d.code AS department_code, d.name AS department_name,
               COALESCE(sy.label, f.school_year, 'Unassigned School Year') AS school_year_label,
               COALESCE(u.full_name, CONCAT('Deleted User ', fi.uploaded_by)) AS uploader_name,
               COALESCE(u.username, CONCAT('user-', fi.uploaded_by)) AS uploader_username,
               COALESCE(u.role, 'unknown') AS uploader_role,
               COALESCE(fg.name, 'Ungrouped Folders') AS folder_group_name,
               f.folder_name
        FROM files fi
        JOIN folders f ON f.id = fi.folder_id
        JOIN departments d ON d.id = f.department_id
        LEFT JOIN school_years sy ON sy.id = f.school_year_id
        LEFT JOIN folder_groups fg ON fg.id = f.group_id
        LEFT JOIN users u ON u.id = fi.uploaded_by";
    $conditions = [];
    if ($schoolYearId !== null) {
        $conditions[] = 'f.school_year_id = ' . (int) $schoolYearId;
    }
    if (!$uploadToDrive) {
        $conditions[] = "u.role IN ('faculty', 'chairperson') AND LOWER(fi.original_name) LIKE '%.pdf'";
    }
    if ($conditions) {
        $query .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $query .= ' ORDER BY d.code, uploader_role, uploader_name, f.folder_name, fi.id';
    $fileRows = $conn->query($query);
    if (!$fileRows) {
        throw new RuntimeException('Unable to read uploaded file records for backup: ' . $conn->error);
    }
    $totalRows = (int) $fileRows->num_rows;
    $scanUpdateInterval = max(1, (int) ceil(max(1, $totalRows) / 20));
    $scannedRows = 0;

    $directory = googleDriveBackupDirectory();
    $indexPath = $directory . DIRECTORY_SEPARATOR . 'drive_file_index.json';
    $index = [];
    if (is_file($indexPath)) {
        $savedIndex = json_decode((string) file_get_contents($indexPath), true);
        if (!is_array($savedIndex)) {
            throw new RuntimeException('The Google Drive duplicate-upload index is unreadable.');
        }
        $index = $savedIndex;
    }
    $hashCache = is_array($index['_source_hashes'] ?? null) ? $index['_source_hashes'] : [];
    $records = [];
    $missingFiles = [];
    while ($row = $fileRows->fetch_assoc()) {
        $scannedRows++;
        $storedName = basename((string) $row['file_name']);
        $sourcePath = UPLOAD_DIR . $storedName;
        if ($storedName === '' || is_link($sourcePath) || !is_file($sourcePath) || !is_readable($sourcePath)) {
            $missingFiles[] = (string) ($row['original_name'] ?: $storedName);
            if ($progressCallback && $scannedRows % $scanUpdateInterval === 0) {
                $scanRange = $uploadToDrive ? 5 : 13;
                $progressCallback(2 + (int) floor(($scannedRows / max(1, $totalRows)) * $scanRange), 'Checking uploaded files (' . $scannedRows . ' of ' . $totalRows . ')');
            }
            continue;
        }

        $size = filesize($sourcePath);
        $modifiedAt = filemtime($sourcePath);
        $cachedHash = $hashCache[$storedName] ?? null;
        if (is_array($cachedHash) && (int) ($cachedHash['size'] ?? -1) === $size &&
            (int) ($cachedHash['modified_at'] ?? -1) === $modifiedAt &&
            preg_match('/^[a-f0-9]{64}$/', (string) ($cachedHash['hash'] ?? ''))) {
            $contentHash = (string) $cachedHash['hash'];
        } else {
            $contentHash = hash_file('sha256', $sourcePath);
            if ($contentHash === false) {
                throw new RuntimeException('Unable to verify an uploaded file before backup: ' . ($row['original_name'] ?: $storedName));
            }
            $hashCache[$storedName] = [
                'size' => $size,
                'modified_at' => $modifiedAt,
                'hash' => $contentHash,
            ];
        }
        $records[] = [$row, $sourcePath, $storedName, $contentHash];
        if ($progressCallback && $scannedRows % $scanUpdateInterval === 0) {
            $scanRange = $uploadToDrive ? 5 : 13;
            $progressCallback(2 + (int) floor(($scannedRows / max(1, $totalRows)) * $scanRange), 'Checking uploaded files (' . $scannedRows . ' of ' . $totalRows . ')');
        }
    }

    if ($missingFiles) {
        throw new RuntimeException(
            'Some uploaded files are missing or unreadable and were not backed up: ' .
            implode(', ', array_slice(array_unique($missingFiles), 0, 10))
        );
    }

    $index['_source_hashes'] = $hashCache;
    if (file_put_contents($indexPath, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        throw new RuntimeException('Unable to save the local file verification cache.');
    }
    @chmod($indexPath, 0600);

    if ($createLocalZip && $progressCallback) {
        $progressCallback(8, 'Creating PDF-only ZIP archive');
    }
    $pdfCount = $createLocalZip
        ? googleDriveCreatePdfArchive(
            $records,
            $localZipPath,
            $progressCallback
                    ? static function (int $processed, int $total) use ($progressCallback, $uploadToDrive): void {
                        $progress = $uploadToDrive
                            ? 8 + (int) floor(($processed / max(1, $total)) * 7)
                            : 15 + (int) floor(($processed / max(1, $total)) * 80);
                        $progressCallback($progress, 'Creating PDF-only ZIP archive (' . $processed . ' of ' . $total . ')');
                    }
                    : null
        )
        : 0;
    if ($pdfCount === 0 && is_file($localZipPath) && !unlink($localZipPath)) {
        throw new RuntimeException('No PDF submissions were found, and the empty local archive could not be removed.');
    }

    if ($uploadToDrive && $progressCallback) {
        $progressCallback(15, 'Uploading files to Google Drive');
    }

    $account = $uploadToDrive ? strtolower((string) (googleDriveLoadToken()['email'] ?? '')) : '';
    $accountIndex = is_array($index[$account] ?? null) ? $index[$account] : [];
    $uploadedCount = 0;
    $reusedCount = 0;
    $recordCount = count($records);
    foreach ($uploadToDrive ? $records : [] as $recordIndex => [$row, $sourcePath, $storedName, $contentHash]) {
        $departmentName = $row['department_code'] . ' - ' . $row['department_name'];
        $role = match ($row['uploader_role']) {
            'chairperson' => 'Chairpersons',
            'faculty' => 'Faculty',
            'sub-admin' => 'Sub-admins',
            default => 'Administrators',
        };
        $personName = $row['uploader_name'] . ' (' . $row['uploader_username'] . ')';

        $schoolYearFolder = googleDriveEnsureFolder('School Year ' . $row['school_year_label'], $backupFolder['id'], $cache);
        $departmentFolder = googleDriveEnsureFolder($departmentName, $schoolYearFolder['id'], $cache);
        $roleFolder = googleDriveEnsureFolder($role, $departmentFolder['id'], $cache);
        $personFolder = googleDriveEnsureFolder($personName, $roleFolder['id'], $cache);
        $groupFolder = googleDriveEnsureFolder((string) $row['folder_group_name'], $personFolder['id'], $cache);
        $submissionFolder = googleDriveEnsureFolder((string) $row['folder_name'], $groupFolder['id'], $cache);
        $originalName = basename((string) ($row['original_name'] ?: $storedName));
        $mimeType = trim((string) $row['file_type']);
        if (!preg_match('/^[a-zA-Z0-9!#$&^_.+-]+\/[a-zA-Z0-9!#$&^_.+-]+$/', $mimeType)) {
            $mimeType = 'application/octet-stream';
        }
        $cachedFileId = (string) ($accountIndex[$contentHash] ?? '');
        $reused = false;
        if ($cachedFileId !== '') {
            try {
                googleDriveApiRequest('https://www.googleapis.com/drive/v3/files?fields=id,name,mimeType,shortcutDetails', 'POST', [
                    'name' => $originalName,
                    'mimeType' => 'application/vnd.google-apps.shortcut',
                    'parents' => [$submissionFolder['id']],
                    'shortcutDetails' => ['targetId' => $cachedFileId],
                ]);
                $reused = true;
                $reusedCount++;
            } catch (RuntimeException $exception) {
                if (!str_contains($exception->getMessage(), 'HTTP 404')) {
                    throw $exception;
                }
                unset($accountIndex[$contentHash]);
            }
        }
        if (!$reused) {
            $uploadedFile = googleDriveUploadFile($sourcePath, $originalName, $submissionFolder['id'], $mimeType);
            $accountIndex[$contentHash] = $uploadedFile['id'];
            $index[$account] = $accountIndex;
            if (file_put_contents($indexPath, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
                throw new RuntimeException('The file was uploaded, but the duplicate-upload index could not be saved.');
            }
            @chmod($indexPath, 0600);
        }
        $uploadedCount++;
        if ($progressCallback && $recordCount > 0) {
            $progressCallback(15 + (int) floor((($recordIndex + 1) / $recordCount) * 80), 'Backed up ' . ($recordIndex + 1) . ' of ' . $recordCount . ' files');
        }
    }
    if ($progressCallback && $recordCount === 0) {
        $progressCallback(95, 'No uploaded files found');
    }

    return [
        'folder_id' => $backupFolder['id'] ?? '',
        'folder_url' => $backupFolder['webViewLink'] ?? '',
        'folder_name' => $backupFolder['name'] ?? '',
        'file_count' => $uploadedCount,
        'reused_count' => $reusedCount,
        'pdf_count' => $pdfCount,
        'local_archive' => $createLocalZip && $pdfCount > 0 ? basename($localZipPath) : null,
    ];
}

function googleDriveArchivePathSegment(string $value): string
{
    $segment = preg_replace('/[\x00-\x1F<>:"\/\\\\|?*]/u', '_', trim($value));
    $segment = trim((string) $segment, " .\t\n\r\0\x0B");
    return $segment !== '' ? $segment : 'Unnamed';
}

function googleDriveCreatePdfArchive(array $records, string $zipPath, ?callable $progressCallback = null): int
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP ZIP extension is required. Enable extension=zip in php.ini and restart Apache.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create the PDF submissions ZIP archive.');
    }

    $addedCount = 0;
    $entryPaths = [];
    $totalPdfFiles = 0;
    foreach ($records as [$row, $sourcePath, $storedName]) {
        $originalName = basename((string) ($row['original_name'] ?: $storedName));
        if (in_array($row['uploader_role'], ['faculty', 'chairperson'], true) &&
            strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) === 'pdf') {
            $totalPdfFiles++;
        }
    }
    $updateInterval = max(1, (int) ceil(max(1, $totalPdfFiles) / 20));
    foreach ($records as [$row, $sourcePath, $storedName]) {
        if (!in_array($row['uploader_role'], ['faculty', 'chairperson'], true)) {
            continue;
        }
        $originalName = basename((string) ($row['original_name'] ?: $storedName));
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
            continue;
        }

        $role = $row['uploader_role'] === 'chairperson' ? 'Chairpersons' : 'Faculty';
        $person = $row['uploader_name'] . ' (' . $row['uploader_username'] . ')';
        $parts = [
            'School Year ' . $row['school_year_label'],
            $row['department_code'] . ' - ' . $row['department_name'],
            $role,
            $person,
            $row['folder_group_name'],
            $row['folder_name'],
            $originalName,
        ];
        $entryPath = implode('/', array_map('googleDriveArchivePathSegment', $parts));
        if (isset($entryPaths[$entryPath])) {
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            $baseName = pathinfo($originalName, PATHINFO_FILENAME) . ' (' . (int) $row['id'] . ')';
            $parts[count($parts) - 1] = $baseName . ($extension !== '' ? '.' . $extension : '');
            $entryPath = implode('/', array_map('googleDriveArchivePathSegment', $parts));
        }
        if (!$zip->addFile($sourcePath, $entryPath)) {
            $zip->close();
            throw new RuntimeException('Unable to add a PDF submission to the ZIP archive.');
        }
        $entryPaths[$entryPath] = true;
        $addedCount++;
        if ($progressCallback && $addedCount % $updateInterval === 0) {
            $progressCallback($addedCount, $totalPdfFiles);
        }
    }

    if (!$zip->close()) {
        throw new RuntimeException('Unable to finish the PDF submissions ZIP archive.');
    }
    return $addedCount;
}

function googleDriveCreateBackup(mysqli $conn, int $userId, string $reason, ?int $schoolYearId = null, ?callable $progressCallback = null, string $destination = 'both'): array
{
    set_time_limit(0);
    ignore_user_abort(true);
    if (!in_array($destination, ['drive', 'local', 'both'], true)) {
        throw new InvalidArgumentException('Choose Google Drive, local storage, or both as the backup destination.');
    }
    if (in_array($destination, ['drive', 'both'], true) && !googleDriveLoadToken()) {
        throw new RuntimeException('Connect a CvSU Google account in Backup Storage before creating a backup.');
    }
    $directory = googleDriveBackupDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the local backup archive folder.');
    }

    $backupId = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $pdfZip = 'coltrace_pdf_submissions_' . $backupId . '.zip';
    $pdfZipPath = $directory . DIRECTORY_SEPARATOR . $pdfZip;

    try {
        $driveBackup = googleDriveUploadSubmissionFiles($conn, $backupId, $reason, $pdfZipPath, $schoolYearId, $progressCallback, $destination);

        $manifest = [
            'backup_id' => $backupId,
            'created_at' => date(DATE_ATOM),
            'reason' => $reason,
            'user_id' => $userId,
            'destination' => $destination,
            'google_account' => in_array($destination, ['drive', 'both'], true) ? (googleDriveLoadToken()['email'] ?? '') : '',
            'drive_folder' => $driveBackup,
            'file_count' => $driveBackup['file_count'],
            'duplicate_count' => $driveBackup['reused_count'],
            'pdf_file_count' => $driveBackup['pdf_count'],
            'local_archives' => $driveBackup['local_archive'] ? [$driveBackup['local_archive']] : [],
        ];
        $manifestPath = $directory . DIRECTORY_SEPARATOR . 'manifest_' . $backupId . '.json';
        if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new RuntimeException('The backups reached Google Drive, but the local backup history could not be saved.');
        }
        $activity = in_array($destination, ['drive', 'both'], true)
            ? 'GOOGLE_DRIVE_BACKUP'
            : 'LOCAL_PDF_BACKUP';
        logActivity($conn, $userId, $activity, $reason . ' backup completed: ' . $backupId);
        return $manifest;
    } catch (Throwable $exception) {
        if (is_file($pdfZipPath)) {
            unlink($pdfZipPath);
        }
        throw $exception;
    }
}

function googleDriveRecentBackups(): array
{
    $directory = googleDriveBackupDirectory();
    if (!is_dir($directory)) {
        return [];
    }
    $manifests = glob($directory . DIRECTORY_SEPARATOR . 'manifest_*.json') ?: [];
    rsort($manifests, SORT_STRING);
    $backups = [];
    foreach (array_slice($manifests, 0, 20) as $path) {
        $data = json_decode((string) file_get_contents($path), true);
        if (is_array($data) && !empty($data['backup_id'])) {
            $backups[] = $data;
        }
    }
    return $backups;
}
