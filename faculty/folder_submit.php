<?php
// faculty/folder_submit.php
require_once '../includes/config.php';
require_once '../includes/school_year_helper.php';
require_once '../includes/file_feedback.php';
requireLogin();
if (!in_array($_SESSION['role'] ?? '', ['faculty', 'chairperson'], true)) {
    redirect(BASE_URL . '/index.php?error=unauthorized');
}
$is_chairperson = $_SESSION['role'] === 'chairperson';
$dashboard_url = $is_chairperson ? '../chairperson/dashboard.php' : 'dashboard.php';
$conn->query("ALTER TABLE files ADD COLUMN IF NOT EXISTS proxy_upload_group_id CHAR(32) NULL");
$conn->query("ALTER TABLE files ADD COLUMN IF NOT EXISTS content_hash CHAR(64) NULL");

$dept_id = $_SESSION['department_id'];
$user_id = $_SESSION['user_id'];
fileFeedbackEnsureSchema($conn);
$unreadFeedbackCount = fileFeedbackUnreadCount($conn, (int) $user_id);
$folder_id = intval($_GET['id'] ?? 0);

// Profile photo
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL");
$_photo = $conn->query("SELECT profile_photo FROM users WHERE id = $user_id")->fetch_assoc()['profile_photo'] ?? '';
$_photo_url = $_photo && file_exists(__DIR__ . '/../uploads/avatars/' . $_photo)
    ? BASE_URL . '/uploads/avatars/' . $_photo . '?v=' . filemtime(__DIR__ . '/../uploads/avatars/' . $_photo)
    : '';

    $conn->query("CREATE TABLE IF NOT EXISTS department_subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        department_id INT NOT NULL,
        subject_code VARCHAR(100) NOT NULL,
        subject_description VARCHAR(255) NOT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_department_subject (department_id, subject_code),
        FOREIGN KEY (department_id) REFERENCES departments(id)
    )");
    $conn->query("ALTER TABLE department_subjects ADD COLUMN IF NOT EXISTS created_by INT NULL");
    $conn->query("CREATE TABLE IF NOT EXISTS subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        department_id INT NOT NULL,
        faculty_id INT NOT NULL,
        subject_code VARCHAR(100) NOT NULL,
        subject_description VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_faculty_subject (faculty_id, subject_code),
        FOREIGN KEY (department_id) REFERENCES departments(id),
        FOREIGN KEY (faculty_id) REFERENCES users(id)
    )");
$dept = $conn->query("SELECT * FROM departments WHERE id = $dept_id")->fetch_assoc();
$active_sy = setupSchoolYears($conn);
$active_sy_id = (int) ($active_sy['id'] ?? 0);
$conn->query("CREATE TABLE IF NOT EXISTS folder_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    semester VARCHAR(100) DEFAULT 'End of Semester',
    description TEXT,
    status ENUM('active','pending') DEFAULT 'active',
    school_year_id INT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("ALTER TABLE folder_groups ADD COLUMN IF NOT EXISTS status ENUM('active','pending') DEFAULT 'active'");

$folder = $conn->query("
    SELECT f.*, d.code AS dept_code
    FROM folders f JOIN departments d ON d.id = f.department_id
    LEFT JOIN folder_groups fg ON fg.id = f.group_id
    WHERE f.id = $folder_id AND f.department_id = $dept_id AND f.school_year_id = $active_sy_id
        AND (f.group_id IS NULL OR fg.status = 'active')
")->fetch_assoc();
if (!$folder) redirect(BASE_URL . ($is_chairperson ? '/chairperson/dashboard.php' : '/faculty/dashboard.php'));

$subject_scope_sql = " AND id IN (SELECT ds.id FROM department_subjects ds JOIN subjects s ON s.department_id = ds.department_id AND s.subject_code = ds.subject_code WHERE s.faculty_id = $user_id AND s.department_id = $dept_id)";
$my_subjects = $conn->query("SELECT id, subject_code, subject_description FROM department_subjects WHERE department_id = $dept_id$subject_scope_sql ORDER BY subject_code ASC")->fetch_all(MYSQLI_ASSOC);
$faculty_subject_count = (int) ($conn->query("SELECT COUNT(*) AS total FROM subjects WHERE faculty_id = $user_id AND department_id = $dept_id")->fetch_assoc()['total'] ?? 0);
// This folder's deadline is inherited from its folder group (if any).
// Once the deadline has passed, uploads/edits/deletes are locked.
$folder_deadline = null;
if (!empty($folder['group_id'])) {
    $deadline_row = $conn->query("SELECT deadline FROM folder_groups WHERE id = " . intval($folder['group_id']))->fetch_assoc();
    $folder_deadline = $deadline_row['deadline'] ?? null;
}
$is_locked = !empty($folder_deadline) && strtotime($folder_deadline) < time();

// Folder groups for sidebar
$folder_groups_sidebar = $conn->query("
    SELECT DISTINCT fg.id, fg.name
    FROM folder_groups fg
    JOIN folders f ON f.group_id = fg.id
    WHERE f.department_id = $dept_id AND f.school_year_id = $active_sy_id AND fg.status = 'active'
    ORDER BY fg.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

// All folders for this dept + SY
$all_folders = $conn->query("
    SELECT f.*,
        (SELECT COUNT(DISTINCT fi.subject_code)
            FROM files fi
            JOIN subjects s ON s.subject_code = fi.subject_code
                AND s.faculty_id = $user_id AND s.department_id = $dept_id
            WHERE fi.folder_id = f.id AND fi.uploaded_by = $user_id) AS my_submitted_subject_count
        FROM folders f
        LEFT JOIN folder_groups fg ON fg.id = f.group_id
        WHERE f.department_id = $dept_id AND f.school_year_id = $active_sy_id
            AND (f.group_id IS NULL OR fg.status = 'active')
    ORDER BY f.subfolder_position ASC, f.created_at DESC
")->fetch_all(MYSQLI_ASSOC);
foreach ($all_folders as &$sidebar_folder) {
    $sidebar_folder['is_complete'] = $faculty_subject_count > 0
        && (int) $sidebar_folder['my_submitted_subject_count'] >= $faculty_subject_count;
}
unset($sidebar_folder);
if ($faculty_subject_count === 0) {
    $all_folders = [];
    $folder_groups_sidebar = [];
}
$msg = $err = '';

// UPLOAD FILE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    if ($is_locked) {
        $err = 'The submission deadline for this folder has passed. You can no longer upload files.';
    } else {
        $max_size = 10 * 1024 * 1024;
        $files = $_FILES['files'] ?? null;
        $subject_ids = $_POST['subject_id'] ?? [];
        $uploaded_count = 0;
        $failed = [];
        $upload_rows = is_array($files['name'] ?? null) ? $files['name'] : [];
        foreach ($upload_rows as $row_id => $row_names) {
            $row_subject_id = (int) ($subject_ids[$row_id] ?? 0);
            $subject = $conn->query("SELECT subject_code, subject_description FROM department_subjects WHERE id = $row_subject_id AND department_id = $dept_id$subject_scope_sql")->fetch_assoc();
            $file_indexes = is_array($row_names) ? array_keys($row_names) : [0];

            foreach ($file_indexes as $file_index) {
                $name = is_array($row_names) ? $files['name'][$row_id][$file_index] : $files['name'][$row_id];
                $tmp_name = is_array($row_names) ? $files['tmp_name'][$row_id][$file_index] : $files['tmp_name'][$row_id];
                $file_size = is_array($row_names) ? (int) $files['size'][$row_id][$file_index] : (int) $files['size'][$row_id];
                $file_type = is_array($row_names) ? ($files['type'][$row_id][$file_index] ?? '') : ($files['type'][$row_id] ?? '');
                $file_error = is_array($row_names) ? ($files['error'][$row_id][$file_index] ?? UPLOAD_ERR_NO_FILE) : ($files['error'][$row_id] ?? UPLOAD_ERR_NO_FILE);
                if ($file_error === UPLOAD_ERR_NO_FILE) continue;
                $original = basename((string) $name);
                if ($file_error !== UPLOAD_ERR_OK) { $failed[] = $original . ' could not be uploaded'; continue; }
                if ($file_size > $max_size) { $failed[] = $original . ' is over 10MB'; continue; }
                if (!$subject) { $failed[] = $original . ' has no valid assigned subject'; continue; }

                $content_hash = hash_file('sha256', $tmp_name);
                if ($content_hash !== false) {
                    $duplicate_stmt = $conn->prepare('SELECT id FROM files WHERE folder_id = ? AND uploaded_by = ? AND subject_code = ? AND content_hash = ? LIMIT 1');
                    $duplicate_stmt->bind_param('iiss', $folder_id, $user_id, $subject['subject_code'], $content_hash);
                    $duplicate_stmt->execute();
                    $is_duplicate = $duplicate_stmt->get_result()->num_rows > 0;
                    $duplicate_stmt->close();
                    if ($is_duplicate) { $failed[] = $original . ' is already uploaded for ' . $subject['subject_code']; continue; }
                }

                $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
                $safe_name = uniqid('file_') . '_' . time() . '.' . $ext;
                $dest = UPLOAD_DIR . $safe_name;
                if (!move_uploaded_file($tmp_name, $dest)) { $failed[] = $original . ' could not be saved'; continue; }
                $file_type = $file_type ?: 'application/octet-stream';
                $stmt = $conn->prepare("INSERT INTO files (folder_id, uploaded_by, file_name, original_name, subject_code, description, file_size, file_type, content_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('iissssiss', $folder_id, $user_id, $safe_name, $original, $subject['subject_code'], $subject['subject_description'], $file_size, $file_type, $content_hash);
                if ($stmt->execute()) {
                    $uploaded_count++;
                    logActivity($conn, $user_id, 'UPLOAD_FILE', "Uploaded '$original' to folder '{$folder['folder_name']}' for {$subject['subject_code']}");
                } else {
                    $failed[] = $original . ' database error';
                    unlink($dest);
                }
                $stmt->close();
            }
        }
        if ($uploaded_count) $msg = "$uploaded_count file(s) uploaded successfully.";
        if ($failed) {
            $err = implode('; ', $failed) . '.';
            if ($uploaded_count) $err = 'Some files were not uploaded: ' . $err;
        }
        if (!$uploaded_count && !$failed) {
            $err = 'Please select at least one file with a valid subject.';
        }
    }

    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_err'] = $err;
    header('Location: folder_submit.php?id=' . $folder_id);
    exit;
}

// DELETE FILE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_file') {
    if ($is_locked) {
        $err = 'The submission deadline for this folder has passed. You can no longer modify your submission.';
    } else {
    $file_id  = intval($_POST['file_id']);
    $file_row = $conn->query("SELECT * FROM files WHERE id = $file_id AND uploaded_by = $user_id AND folder_id = $folder_id")->fetch_assoc();
    if ($file_row && !empty($file_row['proxy_upload_group_id'])) {
        $err = 'This is a shared upload. Use Edit Shared Upload so every recipient stays in sync.';
    } elseif ($file_row) {
        $fpath = UPLOAD_DIR . $file_row['file_name'];
        if (file_exists($fpath)) unlink($fpath);
        $conn->query("DELETE FROM files WHERE id = $file_id");
        logActivity($conn, $user_id, 'DELETE_FILE', "Deleted '{$file_row['original_name']}'");
        $msg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg> File deleted.';
    }
    }

    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_err'] = $err;
    header('Location: folder_submit.php?id=' . $folder_id);
    exit;
}

// REPLACE (EDIT) FILE — swap out an existing submission for a new file, as long as the deadline hasn't passed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'replace_file') {
    $file_id  = intval($_POST['file_id'] ?? 0);
    $file_row = $conn->query("SELECT * FROM files WHERE id = $file_id AND uploaded_by = $user_id AND folder_id = $folder_id")->fetch_assoc();

    if ($is_locked) {
        $err = 'The submission deadline for this folder has passed. You can no longer modify your submission.';
    } elseif (!$file_row) {
        $err = 'File not found.';
    } elseif (!empty($file_row['proxy_upload_group_id'])) {
        $err = 'This is a shared upload. Use Edit Shared Upload so every recipient stays in sync.';
    } elseif (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $err = 'Please select a valid replacement file.';
    } else {
        $file = $_FILES['file'];
        $max_size = 10 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            $err = 'File is too large. Maximum size is 10MB.';
        } else {
            $original  = basename($file['name']);
            $ext       = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            $safe_name = uniqid('file_') . '_' . time() . '.' . $ext;
            $dest      = UPLOAD_DIR . $safe_name;

            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $old_path = UPLOAD_DIR . $file_row['file_name'];
                $remarks  = sanitize($_POST['remarks'] ?? $file_row['remarks']);
                $ftype    = $file['type'];
                $fsize    = $file['size'];
                $stmt = $conn->prepare("UPDATE files SET file_name = ?, original_name = ?, file_size = ?, file_type = ?, remarks = ?, uploaded_at = NOW() WHERE id = ?");
                $stmt->bind_param('ssissi', $safe_name, $original, $fsize, $ftype, $remarks, $file_id);
                $conn->begin_transaction();
                $replacementSaved = false;
                try {
                    if (!$stmt->execute()) {
                        throw new RuntimeException('Unable to replace the uploaded file: ' . $stmt->error);
                    }
                    fileFeedbackNotifyFileReplaced($conn, $file_id);
                    $conn->commit();
                    $replacementSaved = true;
                } catch (Throwable $exception) {
                    $conn->rollback();
                    $err = $exception->getMessage();
                    unlink($dest);
                }
                if ($replacementSaved) {
                    if (file_exists($old_path) && !unlink($old_path)) {
                        error_log('Unable to remove replaced upload: ' . $old_path);
                    }
                    logActivity($conn, $user_id, 'REPLACE_FILE', "Replaced submission with '$original' in folder '{$folder['folder_name']}'");
                    $msg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> \'' . $original . '\' updated successfully!';
                }
                $stmt->close();
            } else {
                $err = 'Failed to save file. Check server upload directory permissions.';
            }
        }
    }

    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_err'] = $err;
    header('Location: folder_submit.php?id=' . $folder_id);
    exit;
}

// Pick up a one-time flash message left by a redirect above (Post/Redirect/Get).
// This is what makes the success/error banner disappear on refresh instead of
// resubmitting the form or sticking around forever.
if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (isset($_SESSION['flash_err'])) { $err = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }

$my_files = $conn->query("
    SELECT * FROM files WHERE folder_id = $folder_id AND uploaded_by = $user_id ORDER BY uploaded_at DESC
")->fetch_all(MYSQLI_ASSOC);
$files_by_subject = [];
$submitted_subjects = [];
foreach ($my_files as $my_file) {
    $subject_key = $my_file['subject_code'] ?: '__unassigned__';
    if (!empty($my_file['subject_code'])) {
        $submitted_subjects[(string) $my_file['subject_code']] = true;
    }
    $files_by_subject[$subject_key]['subject_code'] = $my_file['subject_code'] ?: 'Other documents';
    $files_by_subject[$subject_key]['description'] = $my_file['subject_code'] ? ($my_file['description'] ?? '') : '';
    $files_by_subject[$subject_key]['files'][] = $my_file;
}

function formatSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes/1024,1) . ' KB';
    return round($bytes/1048576,1) . ' MB';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($folder['folder_name']) ?> — CollTrace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --navy: #1C1917; --navy2: #231F1B;
            --red: #F97316;  --red2: #EA580C;
            --bg: #FFF7ED;   --card: #fff;
            --text: #1e2235; --muted: #6b7280;
            --border: #e5e7eb;
            --sidebar-w: 270px; --header-h: 62px;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg); min-height: 100vh; }

        /* HEADER */
        .header { position:fixed;top:0;left:0;right:0;z-index:100;height:var(--header-h);background:linear-gradient(120deg,var(--navy) 0%,#0d0b0a 100%);border-bottom:2px solid var(--red);display:flex;align-items:center;justify-content:space-between;padding:0 28px;box-shadow:0 2px 16px rgba(0,0,0,.35); }
        .brand { display:flex;align-items:center;gap:10px;font-size:22px;font-weight:800;color:#fff;letter-spacing:-.5px; } .brand-logo { width:32px;height:32px;object-fit:contain;flex-shrink:0; } .brand span { color:var(--red); }
        .header-right { display:flex;align-items:center;gap:14px; }
        .role-badge { background:var(--red);color:#fff;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700; }
        a.hbtn { background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;padding:7px 16px;border-radius:8px;font-size:13px;text-decoration:none;transition:background .2s; }
        a.hbtn:hover { background:rgba(255,255,255,.22); }
        a.hbtn-out { background:transparent;border:1px solid rgba(255,255,255,.35);color:#fff;padding:7px 16px;border-radius:8px;font-size:13px;text-decoration:none; }

        /* LAYOUT */
        .layout { display:flex;padding-top:var(--header-h);min-height:100vh; }

        /* SIDEBAR */
        .sidebar { width:var(--sidebar-w);min-height:calc(100vh - var(--header-h));background:var(--navy2);position:fixed;top:var(--header-h);left:0;bottom:0;overflow-y:auto;display:flex;flex-direction:column;box-shadow:2px 0 20px rgba(0,0,0,.18); }
        .account-section { padding:26px 20px 20px;border-bottom:1px solid rgba(255,255,255,.07); }
        .account-avatar { width:54px;height:54px;border-radius:50%;background:linear-gradient(135deg,#F97316,#FB923C);color:#fff;font-size:22px;font-weight:800;display:flex;align-items:center;justify-content:center;margin-bottom:12px;box-shadow:0 4px 12px rgba(249,115,22,.35); }
        .account-name { font-size:15px;line-height:1.35;font-weight:700;color:#e8eaf0;margin-bottom:7px; }
        .account-role { font-size:12px;line-height:1.4;color:#9ba3be;margin-bottom:5px; }
        .account-dept { font-size:12px;line-height:1.45;color:#7da3ff;font-weight:600; }
        .sidebar-section-label { font-size:10px;font-weight:700;letter-spacing:1.5px;color:#5a6480;text-transform:uppercase;padding:22px 20px 10px; }
        .sidebar-folder-item { display:flex;align-items:center;gap:11px;padding:13px 20px;color:#9ba3be;text-decoration:none;font-size:13px;font-weight:500;border-left:3px solid transparent;transition:all .2s; }
        .sidebar-folder-item:hover { background:rgba(249,115,22,.1);color:#fff;border-left-color:var(--red); }
        .sidebar-folder-item.active { background:rgba(249,115,22,.18);color:#fff;border-left-color:var(--red); }
        .sidebar-folder-item.submitted { color:#4ade80; }
        .sidebar-folder-item.submitted:hover { color:#4ade80; }
        .folder-pill { margin-left:auto;font-size:10px;font-weight:700;padding:2px 7px;border-radius:8px;flex-shrink:0; }
        .pill-done    { background:rgba(74,222,128,.15);color:#4ade80; }
        .pill-pending { background:rgba(251,191,36,.15);color:#fbbf24; }
        .sidebar-logout { margin-top:auto;padding:14px 18px;border-top:1px solid rgba(255,255,255,.07); }
        .sidebar-logout a { display:flex;align-items:center;gap:9px;color:#f87171;font-size:13px;font-weight:500;text-decoration:none;padding:9px 12px;border-radius:8px;transition:background .2s; }
        .sidebar-logout a:hover { background:rgba(248,113,113,.12); }

        
        .sidebar-logout a.sb-info-link { color: #c4cadd; }
        .sidebar-logout a.sb-info-link:hover { background: rgba(249,115,22,0.12); color: #fff; }
        /* MAIN */
        .main { display:flex;flex-direction:column;margin-left:var(--sidebar-w);flex:1;padding:34px 36px;min-width:0; }
        .breadcrumb { font-size:13px;color:var(--muted);margin-bottom:6px; }
        .breadcrumb a { color:var(--red);text-decoration:none; }
        .page-title { font-size:22px;font-weight:800;color:var(--text);margin-bottom:4px;display:flex;align-items:center;gap:8px; }
        .page-sub { font-size:13px;color:var(--muted);margin-bottom:22px; }
        .folder-context { display:flex;align-items:center;justify-content:space-between;gap:14px;margin:14px 0 20px;padding:14px 17px;border:1px solid #fed7aa;border-left:5px solid var(--red);border-radius:10px;background:linear-gradient(110deg,#fff7ed 0%,#fff 78%);box-shadow:0 4px 14px rgba(124,45,18,.07); }
        .folder-context-label { flex:0 0 auto;color:#7c2d12;font-size:12px;font-weight:800;letter-spacing:.35px; }
        .subject-tags { display:flex;flex-wrap:wrap;justify-content:flex-end;gap:7px; }
        .subject-tag { display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #fecaca;border-radius:6px;background:#fff1f2;color:#b91c1c;font-size:11px;font-weight:750;white-space:nowrap; }
        .subject-tag.is-submitted { border-color:#86efac;background:#f0fdf4;color:#15803d; }
        .page-title svg, .card-title svg, .sidebar-folder-item svg, .sidebar-logout a svg, .alert svg, .upload-area svg, .file-ext svg, .btn-del svg, .btn-edit svg, .hbtn svg, .hbtn-out svg, .btn-upload svg, .deadline-note svg, .locked-badge svg {
            width: 1em; height: 1em; color: var(--red); flex-shrink: 0; display: inline-block; vertical-align: -0.12em; line-height: 1;
        }
        .btn-upload svg { color: #fff; }

        /* ALERTS */
        .alert { padding:12px 16px;border-radius:10px;font-size:14px;margin-bottom:18px;display:flex;align-items:center;gap:9px; }
        .alert-ok  { background:#f0fdf4;border:1.5px solid #bbf7d0;color:#166534; }
        .alert-err { background:#fff5f5;border:1.5px solid #fecaca;color:#b91c1c; }

        /* CARDS */
        .card { background:var(--card);border-radius:12px;padding:22px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:18px;border:1px solid var(--border); }
        .submitted-card { order:1; }
        .upload-card { order:2; }
        .card-title { font-size:15px;font-weight:700;color:var(--text);margin-bottom:18px;display:flex;align-items:center;gap:8px; }

        /* UPLOAD AREA */
        .upload-row { display:grid;gap:8px;margin-bottom:12px;padding:15px;border:1px solid var(--border);border-radius:9px;background:#fff; }
        .upload-row-head { display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:2px;color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.6px; }
        .upload-index { display:grid;place-items:center;width:23px;height:23px;border-radius:6px;background:#fff7ed;color:#c2410c;font-size:11px; }
        .remove-upload-row { display:inline-grid;place-items:center;width:30px;height:30px;border:1px solid #fed7aa;border-radius:7px;background:#fff7ed;color:#c2410c;font-size:19px;line-height:1;cursor:pointer; }
        .remove-upload-row:hover { border-color:#ea580c;background:#ffedd5;color:#9a3412; }
        .remove-upload-row:focus-visible { outline:3px solid rgba(249,115,22,.25);outline-offset:2px; }
        .upload-row input[type=file] { width:100%;min-height:44px;padding:6px;border:1.5px dashed #d6d3d1;border-radius:8px;background:#fafaf9;color:var(--muted);font:inherit;font-size:12px; }
        .upload-row input[type=file]::file-selector-button { margin-right:10px;padding:7px 10px;border:1px solid #44403c;border-radius:6px;background:#292524;color:#fff;font:inherit;font-size:11px;font-weight:700;cursor:pointer;transition:background .15s,border-color .15s; }
        .upload-row input[type=file]::file-selector-button:hover { border-color:#57534e;background:#44403c; }
        .upload-row select { width:100%;height:42px;padding:0 12px;border:1.5px solid var(--border);border-radius:8px;background:#fff;color:var(--text);font:inherit;font-size:13px; }
        .upload-row select:focus,.upload-row input[type=file]:focus { outline:3px solid rgba(249,115,22,.12);border-color:var(--red); }
        .selected-file { padding:8px 10px;border:1px solid #bbf7d0;border-radius:7px;background:#f0fdf4;color:#166534;font-size:11px;overflow-wrap:anywhere; }

        .form-label { display:block;font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.7px; }
        textarea { width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;margin-bottom:16px;outline:none;font-family:inherit;resize:vertical;min-height:70px;transition:border-color .2s; }
        textarea:focus { border-color:var(--red); }

        .upload-actions { display:flex;align-items:center;gap:9px;margin-top:5px; }
        .btn-upload { background:var(--red);color:#fff;border:1px solid var(--red);padding:10px 18px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s,border-color .15s; }
        .btn-upload:hover { background:var(--red2);border-color:var(--red2); }
        .btn-upload:disabled { background:#d1d5db;border-color:#d1d5db;box-shadow:none;cursor:not-allowed;transform:none; }
        .btn-add-file { display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:40px;padding:0 15px;border:1px solid var(--red);border-radius:8px;background:var(--red);color:#fff;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s,border-color .15s; }
        #showUploadForm { margin-top:10px; }
        .btn-add-file:hover { background:var(--red2);border-color:var(--red2); }
        .btn-add-file svg { width:15px;height:15px;color:inherit; }
        .allowed-types { font-size:11px;color:var(--muted);margin-top:11px;line-height:1.5; }

        /* FILES LIST */
        .submitted-subject { margin-top:16px;border:1px solid #e7e5e4;border-radius:9px;background:#fff;overflow:hidden; }
        .submitted-subject:first-of-type { margin-top:0; }
        .submitted-subject-head { display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 14px;background:#fafaf9;border-bottom:1px solid #e7e5e4; }
        .submitted-subject-title { color:#292524;font-size:13px;font-weight:750; }
        .submitted-subject-description { display:block;margin-top:2px;color:#78716c;font-size:11px;font-weight:400; }
        .submitted-subject-count { flex-shrink:0;padding:3px 8px;border-radius:20px;background:#ffedd5;color:#9a3412;font-size:10px;font-weight:800; }
        .submitted-subject-files { padding:0 14px; }
        .file-row { display:flex;align-items:center;gap:14px;padding:13px 0;border-top:1px solid #f0f0f0; }
        .file-row-wrap:target { margin:4px 0;padding:0 10px;border-left:3px solid #f97316;border-radius:8px;background:#fff7ed;box-shadow:0 2px 8px rgba(124,45,18,.08); }
        .file-row-wrap:target .file-name::after { content:"Remarked file";display:inline-block;margin-left:9px;padding:3px 7px;border:1px solid #fed7aa;border-radius:999px;background:#fff;color:#9a3412;font-size:10px;font-weight:700;line-height:1.2;vertical-align:middle; }
        .file-row:first-child { border-top:none; }
        .file-ext { width:44px;height:44px;border-radius:9px;background:var(--navy);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;text-transform:uppercase;flex-shrink:0; }
        .file-info { flex:1;min-width:0; }
        .file-name { font-size:14px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
        .file-meta { font-size:12px;color:var(--muted);margin-top:2px; }
        .btn-del,.btn-edit { width:36px;height:36px;padding:0;justify-content:center;border-radius:8px; }
        .btn-del { background:#fff;color:#b91c1c;border:1.5px solid #fecaca;cursor:pointer;font-family:inherit;transition:background .2s;display:inline-flex;align-items:center; }
        .btn-del:hover { background:#fff5f5; }
        .btn-edit { background:#fff;color:var(--red);border:1.5px solid #fed7aa;cursor:pointer;font-family:inherit;transition:background .2s;display:inline-flex;align-items:center; }
        .btn-edit:hover { background:#fff8f0; }
        .btn-del svg,.btn-edit svg { width:16px;height:16px; }
        .btn-del:focus-visible,.btn-edit:focus-visible { outline:3px solid rgba(249,115,22,.25);outline-offset:2px; }
        .file-actions { display:flex;gap:8px;flex-shrink:0; }
        .empty-files { text-align:center;color:var(--muted);padding:28px;font-size:14px; }

        /* DEADLINE */
        .deadline-note { display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:4px 11px;border-radius:20px;margin-top:8px;margin-bottom:20px; }
        .deadline-note.open   { background:rgba(249,115,22,.1);color:var(--red2); }
        .deadline-note.closed { background:#fff5f5;color:#b91c1c; }

        /* LOCKED / READ-ONLY SUBMISSION */
        .locked-badge { display:inline-flex;align-items:center;gap:5px;background:#f3f4f6;color:#6b7280;padding:6px 13px;border-radius:7px;font-size:12px;font-weight:600;white-space:nowrap; }
        .locked-notice { text-align:center;color:var(--muted);padding:28px;font-size:14px;background:#fafbfc;border-radius:12px;border:1.5px dashed var(--border); }

        /* INLINE EDIT (REPLACE FILE) FORM */
        .edit-file-form { display:none;padding:14px 0 4px;border-top:1px dashed var(--border);margin-top:12px; }
        .edit-file-form.show { display:block; }
        .edit-file-form input[type="file"] { display:block;width:100%;font-size:13px;margin-bottom:10px; }
        .edit-file-form textarea { min-height:50px; }
        .edit-file-form .row { display:flex;gap:8px; }
        .btn-save-edit { background:var(--red);color:#fff;border:1px solid var(--red);padding:9px 16px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit; }
        .btn-save-edit:hover { background:var(--red2);border-color:var(--red2); }
        .btn-cancel-edit { background:#fff;color:#57534e;border:1px solid #d6d3d1;padding:9px 16px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit; }
        .btn-cancel-edit:hover { background:#f5f5f4; }
        @media(max-width:760px) {
            .header { padding:0 16px; }
            .main { padding:22px 16px; }
            .folder-context { align-items:flex-start;flex-direction:column;gap:9px;padding:13px; }
            .subject-tags { justify-content:flex-start; }
        }
        @media(max-width:560px) {
            .main { padding:18px 12px; }
            .card { padding:17px; }
            .upload-actions { align-items:stretch; flex-direction:column; }
            .upload-actions button { width:100%; }
            .file-row { align-items:flex-start; flex-wrap:wrap; }
            .file-actions { width:100%; justify-content:flex-end; }
        }

        /* ============ Custom Scrollbar (CollTrace) ============ */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d6d3d1; border-radius: 20px; border: 2px solid transparent; background-clip: padding-box; }
        ::-webkit-scrollbar-thumb:hover { background: #F97316; }
        * { scrollbar-width: thin; scrollbar-color: #d6d3d1 transparent; }
    
        .loading-screen {
            position: fixed; inset: 0; z-index: 9999;
            display: flex; align-items: center; justify-content: center;
                background: #000; backdrop-filter: blur(6px);
            transition: opacity 0.6s ease, visibility 0.6s ease;
        }
        .loading-screen.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
        .loading-logo {
            width: 110px; height: 110px; object-fit: contain;
            animation: spin-logo 1s linear infinite;
            filter: drop-shadow(0 10px 24px rgba(0,0,0,0.25));
        }
        @keyframes spin-logo { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
</head>
<body>
<div id="loadingScreen" class="loading-screen" aria-hidden="true">
    <img class="loading-logo" src="../ceit_logo.png" alt="Loading CEIT logo">
</div>


<!-- HEADER -->
<div class="header">
    <div style="display:flex;align-items:center;gap:16px;">
        <a href="<?= $dashboard_url ?>" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo">Coll<span>Trace</span></a>
        <a href="<?= $dashboard_url ?>" class="hbtn">← Dashboard</a>
    </div>
    <div class="header-right">
        <span class="role-badge"><?= $is_chairperson ? 'CHAIRPERSON' : 'FACULTY' ?></span>
        <a href="../logout.php" class="hbtn-out">Logout</a>
    </div>
</div>

<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="folderSidebar">
        <div class="account-section">
            <a href="<?= BASE_URL ?>/profile.php" style="display:block;width:fit-content;margin-bottom:12px;position:relative;" title="My Information">
                <?php if ($_photo_url): ?>
                    <img src="<?= $_photo_url ?>" alt="Profile" style="width:54px;height:54px;border-radius:50%;object-fit:cover;border:3px solid rgba(249,115,22,.6);display:block;">
                <?php else: ?>
                    <div class="account-avatar"><?= mb_strtoupper(mb_substr($_SESSION['full_name'], 0, 1)) ?></div>
                <?php endif; ?>
                <span style="position:absolute;bottom:0;right:0;width:18px;height:18px;background:#F97316;border-radius:50%;border:2px solid #231F1B;display:flex;align-items:center;justify-content:center;color:#fff;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="10" height="10"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>
            </a>
            <div class="account-name"><?= sanitize($_SESSION['full_name']) ?></div>
            <div class="account-role"><?= $is_chairperson ? 'Chairperson' : 'Faculty Instructor' ?></div>
            <div class="account-dept"><?= sanitize($dept['name']) ?></div>
            <?php if ($active_sy): ?>
            <div style="margin-top:8px;background:rgba(249,115,22,0.15);border:1px solid rgba(249,115,22,0.3);border-radius:6px;padding:5px 10px;display:inline-flex;align-items:center;gap:6px;">
                <span style="font-size:10px;color:#8a93ae;font-weight:700;text-transform:uppercase;">SY</span>
                <span style="font-size:12px;font-weight:700;color:#fff;"><?= sanitize($active_sy['label']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <div class="sidebar-section-label">Navigation</div>
        <a href="<?= $dashboard_url ?>" class="sidebar-folder-item">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="5" rx="1"/><rect x="13" y="10" width="8" height="11" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/></svg></span> Dashboard
        </a>
        <?php if (!$is_chairperson): ?>
        <a href="clearance_form.php" class="sidebar-folder-item">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg></span> Clearance Form
        </a>
        <a href="clearance_submission.php" class="sidebar-folder-item">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg></span> Submit Clearance
        </a>
        <?php endif; ?>
        <a href="<?= $is_chairperson ? '../chairperson/assign_subjects.php' : 'subjects.php' ?>" class="sidebar-folder-item">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/>            </svg></span> <?= $is_chairperson ? 'Assign Subjects' : 'My Subjects' ?>
        </a>

        <div class="sidebar-section-label">My Folders</div>

        <?php if (empty($all_folders)): ?>
            <div style="padding:12px 18px;font-size:12px;color:#5a6480;">No folders yet.</div>
        <?php else: ?>
            <?php if (!empty($folder_groups_sidebar)): ?>
                <?php foreach ($folder_groups_sidebar as $grp):
                    $grp_folders = array_filter($all_folders, fn($f) => ($f['group_id'] ?? 0) == $grp['id']);
                    $grp_done = count(array_filter($grp_folders, fn($f) => $f['is_complete']));
                    $grp_total = count($grp_folders);
                ?>
                <div style="padding:8px 18px 4px;cursor:pointer;display:flex;align-items:center;gap:8px;" onclick="toggleSG('sg-<?= $grp['id'] ?>')">
                    <span style="font-size:13px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></span>
                    <span style="flex:1;font-size:12px;font-weight:700;color:#e8eaf0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($grp['name']) ?></span>
                    <span style="font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;background:<?= $grp_done==$grp_total ? 'rgba(74,222,128,.2)' : 'rgba(249,115,22,.15)' ?>;color:<?= $grp_done==$grp_total ? '#4ade80' : '#FB923C' ?>;white-space:nowrap;"><?= $grp_done ?>/<?= $grp_total ?></span>
                </div>
                <div id="sg-<?= $grp['id'] ?>" style="display:block;">
                    <?php foreach ($grp_folders as $f): ?>
                    <a href="folder_submit.php?id=<?= $f['id'] ?>"
                       class="sidebar-folder-item <?= $f['id'] == $folder_id ? 'active' : '' ?> <?= $f['is_complete'] ? 'submitted' : '' ?>" style="padding-left:30px;">
                        <span><?= $f['is_complete'] ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg>' ?></span>
                        <span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($f['folder_name']) ?></span>
                        <span class="folder-pill <?= $f['is_complete'] ? 'pill-done' : 'pill-pending' ?>">
                            <?= $f['is_complete'] ? 'Done' : 'Pending' ?>
                        </span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php $ungrouped = array_filter($all_folders, fn($f) => empty($f['group_id']));
            foreach ($ungrouped as $f): ?>
            <a href="folder_submit.php?id=<?= $f['id'] ?>"
               class="sidebar-folder-item <?= $f['id'] == $folder_id ? 'active' : '' ?> <?= $f['is_complete'] ? 'submitted' : '' ?>">
                <span><?= $f['is_complete'] ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg>' ?></span>
                <span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($f['folder_name']) ?></span>
                <span class="folder-pill <?= $f['is_complete'] ? 'pill-done' : 'pill-pending' ?>">
                    <?= $f['is_complete'] ? 'Done' : 'Pending' ?>
                </span>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="sidebar-logout">
            <a href="<?= BASE_URL ?>/profile.php" class="sb-info-link" style="margin-bottom:6px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> My Information</a>
</div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main">
        <div class="breadcrumb"><a href="<?= $dashboard_url ?>">Dashboard</a> › <?= sanitize($folder['folder_name']) ?></div>
        <div class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg> <?= sanitize($folder['folder_name']) ?></div>
        <div class="folder-context">
            <span class="folder-context-label">Document requirement</span>
            <div class="subject-tags" aria-label="Subjects assigned to you">
                <?php foreach ($my_subjects as $subject): ?>
                    <?php $subject_code = (string) $subject['subject_code']; $is_subject_submitted = isset($submitted_subjects[$subject_code]); ?>
                    <span class="subject-tag<?= $is_subject_submitted ? ' is-submitted' : '' ?>" title="<?= $is_subject_submitted ? 'Submitted' : 'Not submitted' ?>"><?= sanitize($subject_code) ?></span>
                <?php endforeach; ?>
                <?php if (!$my_subjects): ?><span class="subject-tag">No subjects assigned</span><?php endif; ?>
            </div>
        </div>
        <?php if ($folder_deadline): ?>
        <div class="deadline-note <?= $is_locked ? 'closed' : 'open' ?>">
            <?= $is_locked
                ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
                : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>' ?>
            Deadline: <?= date('M d, Y g:i A', strtotime($folder_deadline)) ?><?= $is_locked ? ' — submission closed' : '' ?>
        </div>
        <?php endif; ?>

        <?php if ($msg): ?><div class="alert alert-ok"><?= $msg ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert alert-err"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg> <?= $err ?></div><?php endif; ?>

        <?php if (empty($my_files) || !$is_locked): ?>
        <!-- Upload Form -->
        <div class="card upload-card" id="uploadCard"<?= !empty($my_files) ? ' hidden' : '' ?>>
            <div class="card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 19h14"/></svg> Upload a document</div>
            <?php if ($is_locked): ?>
                <div class="locked-notice"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:1.2em;height:1.2em;color:var(--muted);vertical-align:-0.2em;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> The submission deadline for this folder has passed. Uploading is no longer available.</div>
            <?php else: ?>
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="action" value="upload">
                <div id="upload-rows">
                    <div class="upload-row">
                        <div class="upload-row-head"><span>DOCUMENT FILE <span class="upload-index">1</span></span><button type="button" class="remove-upload-row" aria-label="Remove this file upload row" title="Remove this upload row" hidden onclick="removeUploadRow(this)">&times;</button></div>
                        <label class="form-label">Choose file(s)</label><input type="file" name="files[0][]" multiple required onchange="showFile(this)">
                        <div class="selected-file" hidden></div>
                        <label class="form-label">Subject</label>
                        <select name="subject_id[0]" required><option value="">-- Select subject for these files --</option><?php foreach ($my_subjects as $subject): ?><option value="<?= $subject['id'] ?>"><?= sanitize($subject['subject_code'] . ' - ' . $subject['subject_description']) ?></option><?php endforeach; ?></select>
                    </div>
                </div>
                <?php if (!$my_subjects): ?><p class="allowed-types"><?= $is_chairperson ? 'No subjects are assigned to you yet. Assign your subjects from Assign Subjects.' : 'No subjects are assigned to you yet. Please contact your chairperson through My Subjects.' ?></p><?php endif; ?>
                <div class="upload-actions">
                    <button type="button" class="btn-add-file" onclick="addUploadRow()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg> Add another file</button>
                    <button type="submit" class="btn-upload" id="submitBtn" <?= !$my_subjects ? 'disabled' : '' ?>>Upload</button>
                </div>
                <div class="allowed-types">Choose one or more files for each subject · Max 10MB per file</div>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($my_files)): ?>

        <!-- My Submitted Files (shown instead of the upload box once a file exists) -->
        <div class="card submitted-card">
            <div class="card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg> My Submitted Files (<?= count($my_files) ?>)</div>
            <?php foreach ($files_by_subject as $subject_files): ?>
            <section class="submitted-subject">
                <div class="submitted-subject-head"><div class="submitted-subject-title"><?= sanitize($subject_files['subject_code']) ?><?php if ($subject_files['description'] !== ''): ?><span class="submitted-subject-description"><?= sanitize($subject_files['description']) ?></span><?php endif; ?></div><span class="submitted-subject-count"><?= count($subject_files['files']) ?> file<?= count($subject_files['files']) === 1 ? '' : 's' ?></span></div>
                <div class="submitted-subject-files">
            <?php foreach ($subject_files['files'] as $f):
                $ext = strtolower(pathinfo($f['original_name'], PATHINFO_EXTENSION));
            ?>
            <div class="file-row-wrap" id="file-<?= (int) $f['id'] ?>">
                <div class="file-row">
                    <div class="file-ext"><?= $ext ?></div>
                    <div class="file-info">
                        <div class="file-name"><?= sanitize($f['original_name']) ?></div>
                        <div class="file-meta">
                            <?= formatSize($f['file_size']) ?>
                            · <?= date('M d, Y g:i A', strtotime($f['uploaded_at'])) ?>
                            <?= $f['remarks'] ? '· ' . sanitize($f['remarks']) : '' ?>
                        </div>
                    </div>
                    <?php if (!empty($f['proxy_upload_group_id'])): ?>
                    <?php if ((int) $f['uploaded_via_proxy_id'] === $user_id): ?>
                    <a class="btn-edit" href="delegate_upload.php?folder_id=<?= $folder_id ?>&amp;edit_group=<?= rawurlencode($f['proxy_upload_group_id']) ?>" aria-label="Edit shared upload" title="Edit shared upload"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></a>
                    <?php else: ?>
                    <span class="locked-badge">Shared upload</span>
                    <?php endif; ?>
                    <?php elseif ($is_locked): ?>
                    <span class="locked-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> Locked</span>
                    <?php else: ?>
                    <div class="file-actions">
                        <button type="button" class="btn-edit" aria-label="Edit <?= htmlspecialchars($f['original_name'], ENT_QUOTES, 'UTF-8') ?>" title="Edit file" onclick="toggleEdit(<?= $f['id'] ?>)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></button>
                        <form method="POST" onsubmit="return confirm('Delete this file?')">
                            <input type="hidden" name="action" value="delete_file">
                            <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                            <button type="submit" class="btn-del" aria-label="Delete <?= htmlspecialchars($f['original_name'], ENT_QUOTES, 'UTF-8') ?>" title="Delete file"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!$is_locked): ?>
                <form method="POST" enctype="multipart/form-data" id="edit-form-<?= $f['id'] ?>" class="edit-file-form" onsubmit="return confirm('Save changes to this file?')">
                    <input type="hidden" name="action" value="replace_file">
                    <input type="hidden" name="file_id" value="<?= $f['id'] ?>">
                    <label class="form-label">Replace with a new file</label>
                    <input type="file" name="file" required>
                    <label class="form-label">Remarks / Description (optional)</label>
                    <textarea name="remarks"><?= sanitize($f['remarks']) ?></textarea>
                    <div class="row">
                        <button type="submit" class="btn-save-edit">Save Changes</button>
                        <button type="button" class="btn-cancel-edit" onclick="toggleEdit(<?= $f['id'] ?>)">Cancel</button>
                    </div>
                </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>
            <?php if (!$is_locked): ?>
            <button type="button" class="btn-add-file" id="showUploadForm" aria-expanded="false" aria-controls="uploadCard" onclick="toggleUploadForm()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
                Add file
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </main>
</div>

<script>
function showFile(input) {
    var row = input.closest('.upload-row');
    var el = row ? row.querySelector('.selected-file') : null;
    if (!el || !input.files || !input.files[0]) return;
    el.hidden = false;
    var totalSize = Array.from(input.files).reduce(function(total, file) { return total + file.size; }, 0);
    var names = Array.from(input.files).map(function(file) { return file.name; });
    el.textContent = input.files.length + ' file(s) selected · ' + (totalSize / 1024).toFixed(1) + ' KB total: ' + names.join(', ');
}
function addUploadRow() {
    var rows = document.getElementById('upload-rows');
    var first = rows.querySelector('.upload-row');
    var clone = first.cloneNode(true);
    var rowIndex = rows.querySelectorAll('.upload-row').length;
    var fileInput = clone.querySelector('input[type="file"]');
    var subjectSelect = clone.querySelector('select[name^="subject_id"]');
    fileInput.value = '';
    fileInput.name = 'files[' + rowIndex + '][]';
    subjectSelect.value = '';
    subjectSelect.name = 'subject_id[' + rowIndex + ']';
    clone.querySelector('.selected-file').hidden = true;
    clone.querySelector('.selected-file').textContent = '';
    clone.querySelector('.remove-upload-row').hidden = false;
    rows.appendChild(clone);
    updateUploadRowIndexes();
}
function removeUploadRow(button) {
    var row = button.closest('.upload-row');
    var rows = document.getElementById('upload-rows');
    if (!row || !rows || rows.querySelectorAll('.upload-row').length <= 1) return;
    row.remove();
    updateUploadRowIndexes();
}
function updateUploadRowIndexes() {
    document.querySelectorAll('#upload-rows .upload-row').forEach(function(row, position) {
        row.querySelector('.upload-index').textContent = String(position + 1);
        row.querySelector('input[type="file"]').name = 'files[' + position + '][]';
        row.querySelector('select[name^="subject_id"]').name = 'subject_id[' + position + ']';
    });
}
function toggleUploadForm() {
    var uploadCard = document.getElementById('uploadCard');
    var toggleButton = document.getElementById('showUploadForm');
    if (!uploadCard || !toggleButton) return;
    uploadCard.hidden = !uploadCard.hidden;
    toggleButton.setAttribute('aria-expanded', String(!uploadCard.hidden));
    if (!uploadCard.hidden) {
        uploadCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var firstFileInput = uploadCard.querySelector('input[type="file"]');
        if (firstFileInput) firstFileInput.focus({ preventScroll: true });
    }
}
function handleDrop(e) {
    e.preventDefault();
    e.currentTarget.classList.remove('dragover');
    var input = document.getElementById('fileInput');
    input.files = e.dataTransfer.files;
    showFile(input);
}
function toggleSG(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function toggleEdit(fileId) {
    const el = document.getElementById('edit-form-' + fileId);
    if (el) el.classList.toggle('show');
}

// Keep the sidebar's scroll position when navigating between folders —
// each folder link is a full page load, so without this the sidebar
// would jump back to the top every time.
(function() {
    var sidebar = document.getElementById('folderSidebar');
    if (!sidebar) return;
    var saved = sessionStorage.getItem('folderSidebarScroll');
    if (saved !== null) sidebar.scrollTop = parseInt(saved, 10);
    sidebar.addEventListener('scroll', function() {
        sessionStorage.setItem('folderSidebarScroll', sidebar.scrollTop);
    });
})();
</script>

<script>
window.addEventListener('load', () => {
    const loader = document.getElementById('loadingScreen');
    if (loader) { setTimeout(() => { loader.classList.add('hidden'); }, 900); }
});
(function () {
    const loader = document.getElementById('loadingScreen');
    if (!loader) return;
    document.addEventListener('click', function (e) {
        const link = e.target.closest('a[href]');
        if (!link) return;
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
        if (link.target === '_blank' || link.hasAttribute('download')) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
        e.preventDefault();
        loader.classList.remove('hidden');
        setTimeout(() => { window.location.href = href; }, 300);
    });
    window.addEventListener('pageshow', () => loader.classList.add('hidden'));
})();
</script>
</body>
</html>
