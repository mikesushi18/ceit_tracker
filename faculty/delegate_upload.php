<?php
// faculty/delegate_upload.php
// Lets a faculty member with chairperson-granted "can_delegate" permission
// upload a file into a shared folder ON BEHALF OF another faculty member —
// the file is attributed to the chosen colleague (files.uploaded_by = their
// user id), so it counts as THEIR submission, without ever touching their
// account or password.
require_once '../includes/config.php';
requireRole('faculty');

$dept_id   = $_SESSION['department_id'];
$user_id   = $_SESSION['user_id']; // the proxy uploader (Faculty A)
$folder_id = intval($_GET['folder_id'] ?? 0);

if (!$dept_id) { redirect(BASE_URL . '/index.php'); }
$conn->query("ALTER TABLE folder_assignments ADD COLUMN IF NOT EXISTS has_subject_scope TINYINT(1) NOT NULL DEFAULT 0");
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
$conn->query("CREATE TABLE IF NOT EXISTS folder_assignment_subjects (
    assignment_id INT NOT NULL,
    subject_id INT NOT NULL,
    PRIMARY KEY (assignment_id, subject_id),
    FOREIGN KEY (assignment_id) REFERENCES folder_assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES department_subjects(id) ON DELETE CASCADE
)");

// Make sure we can track who physically performed a proxy upload,
// separate from who it's attributed to.
$conn->query("ALTER TABLE files ADD COLUMN IF NOT EXISTS uploaded_via_proxy_id INT NULL");
$conn->query("ALTER TABLE files ADD COLUMN IF NOT EXISTS proxy_upload_group_id CHAR(32) NULL");
$conn->query("ALTER TABLE files ADD COLUMN IF NOT EXISTS subject_code VARCHAR(100) NULL");
$conn->query("ALTER TABLE files ADD COLUMN IF NOT EXISTS description TEXT NULL");

// Confirm this user has been granted proxy-upload permission for this folder
$assignment = $conn->query("
    SELECT fa.* FROM folder_assignments fa
    JOIN folders f ON f.id = fa.folder_id
    WHERE fa.folder_id = $folder_id AND fa.assigned_to_user_id = $user_id AND f.department_id = $dept_id AND fa.can_delegate = 1 AND fa.is_active = 1
")->fetch_assoc();

if (!$assignment) {
    redirect(BASE_URL . '/faculty/dashboard.php?error=not_assigned');
}

$delegated_subject_ids = array_map('intval', array_column(
    $conn->query("SELECT fas.subject_id FROM folder_assignment_subjects fas JOIN department_subjects ds ON ds.id = fas.subject_id WHERE fas.assignment_id = " . (int) $assignment['id'] . " AND ds.department_id = $dept_id ORDER BY ds.subject_code")->fetch_all(MYSQLI_ASSOC),
    'subject_id'
));
if (empty($assignment['has_subject_scope']) || !$delegated_subject_ids) {
    redirect(BASE_URL . '/faculty/dashboard.php?error=delegate_subjects_not_configured');
}

$folder = $conn->query("SELECT * FROM folders WHERE id = $folder_id AND department_id = $dept_id")->fetch_assoc();
if (!$folder) { redirect(BASE_URL . '/faculty/dashboard.php'); }

$folder_deadline = null;
if (!empty($folder['group_id'])) {
    $deadline_row = $conn->query("SELECT deadline FROM folder_groups WHERE id = " . (int) $folder['group_id'])->fetch_assoc();
    $folder_deadline = $deadline_row['deadline'] ?? null;
}
$is_locked = !empty($folder_deadline) && strtotime($folder_deadline) < time();

$msg = $err = '';

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
$conn->query("ALTER TABLE department_subjects ADD COLUMN IF NOT EXISTS created_by INT NULL");
$delegated_subject_id_list = implode(',', $delegated_subject_ids);
$subject_scope_sql = " AND id IN ($delegated_subject_id_list) AND id IN (SELECT ds.id FROM department_subjects ds JOIN subjects s ON s.department_id = ds.department_id AND s.subject_code = ds.subject_code WHERE s.faculty_id = $user_id AND s.department_id = $dept_id)";
$my_subjects = $conn->query("SELECT id, subject_code, subject_description FROM department_subjects WHERE department_id = $dept_id$subject_scope_sql ORDER BY subject_code ASC")->fetch_all(MYSQLI_ASSOC);

$requested_edit_group_id = (string) ($_GET['edit_group'] ?? '');
$edit_group_id = preg_match('/^[a-f0-9]{32}$/', $requested_edit_group_id) ? $requested_edit_group_id : '';
$edit_group_rows = [];
$edit_colleague_ids = [];
if ($edit_group_id !== '') {
    $stmt = $conn->prepare('SELECT fi.*, u.full_name FROM files fi JOIN users u ON u.id = fi.uploaded_by WHERE fi.proxy_upload_group_id = ? AND fi.folder_id = ? AND fi.uploaded_via_proxy_id = ? ORDER BY fi.id');
    $stmt->bind_param('sii', $edit_group_id, $folder_id, $user_id);
    $stmt->execute();
    $edit_group_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $has_own_group_row = (bool) array_filter($edit_group_rows, static fn($row) => (int) $row['uploaded_by'] === $user_id);
    if (!$edit_group_rows || !$has_own_group_row) {
        $edit_group_id = '';
        $edit_group_rows = [];
        $err = 'That shared upload could not be found.';
    } else {
        $edit_colleague_ids = array_values(array_map(
            'intval',
            array_filter(array_column($edit_group_rows, 'uploaded_by'), static fn($id) => (int) $id !== $user_id)
        ));
    }
}

// Save one shared file for the uploader and every selected colleague.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['proxy_upload', 'proxy_update'], true)) {
    $is_update = $_POST['action'] === 'proxy_update';
    $submitted_group_id = (string) ($_POST['proxy_upload_group_id'] ?? '');
    $group_id = $is_update && preg_match('/^[a-f0-9]{32}$/', $submitted_group_id) ? $submitted_group_id : '';
    $existing_rows = [];

    $post_assignment = $conn->query("SELECT fa.* FROM folder_assignments fa JOIN folders f ON f.id = fa.folder_id WHERE fa.folder_id = $folder_id AND fa.assigned_to_user_id = $user_id AND f.department_id = $dept_id AND fa.can_delegate = 1 AND fa.is_active = 1 AND fa.has_subject_scope = 1")->fetch_assoc();
    $post_delegate_subject_ids = $post_assignment
        ? array_map('intval', array_column($conn->query("SELECT fas.subject_id FROM folder_assignment_subjects fas JOIN department_subjects ds ON ds.id = fas.subject_id WHERE fas.assignment_id = " . (int) $post_assignment['id'] . " AND ds.department_id = $dept_id")->fetch_all(MYSQLI_ASSOC), 'subject_id'))
        : [];
    if ($is_update && $group_id !== '') {
        $stmt = $conn->prepare('SELECT * FROM files WHERE proxy_upload_group_id = ? AND folder_id = ? AND uploaded_via_proxy_id = ? ORDER BY id');
        $stmt->bind_param('sii', $group_id, $folder_id, $user_id);
        $stmt->execute();
        $existing_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    $has_own_group_row = (bool) array_filter($existing_rows, static fn($row) => (int) $row['uploaded_by'] === $user_id);

    $raw_colleague_ids = $_POST['colleague_ids'] ?? [];
    $colleague_ids = is_array($raw_colleague_ids)
        ? array_values(array_unique(array_filter(array_map('intval', $raw_colleague_ids), static fn($id) => $id > 0 && $id !== (int) $_SESSION['user_id'])))
        : [];
    $valid_colleague_count = 0;
    if ($colleague_ids) {
        $colleague_id_list = implode(',', $colleague_ids);
        $valid_colleague_count = (int) ($conn->query("SELECT COUNT(*) AS total FROM users WHERE id IN ($colleague_id_list) AND id <> $user_id AND department_id = $dept_id AND role = 'faculty' AND is_active = 1")->fetch_assoc()['total'] ?? 0);
    }
    $subject_id = (int) ($_POST['subject_id'] ?? 0);
    $post_delegate_subject_id_list = implode(',', $post_delegate_subject_ids);
    $post_subject_scope_sql = $post_delegate_subject_id_list !== ''
        ? " AND id IN ($post_delegate_subject_id_list) AND id IN (SELECT ds.id FROM department_subjects ds JOIN subjects s ON s.department_id = ds.department_id AND s.subject_code = ds.subject_code WHERE s.faculty_id = $user_id AND s.department_id = $dept_id)"
        : ' AND 1 = 0';
    $subject = $post_assignment && $post_delegate_subject_ids
        ? $conn->query("SELECT subject_code, subject_description FROM department_subjects WHERE id = $subject_id AND department_id = $dept_id$post_subject_scope_sql")->fetch_assoc()
        : null;
    $selected_recipients = array_values(array_unique(array_merge([$user_id], $colleague_ids)));
    $recipients_with_subject = 0;
    if ($subject && $selected_recipients) {
        $recipient_id_list = implode(',', $selected_recipients);
        $recipients_with_subject = (int) ($conn->query("SELECT COUNT(DISTINCT faculty_id) AS total FROM subjects WHERE department_id = $dept_id AND subject_code = '" . $conn->real_escape_string($subject['subject_code']) . "' AND faculty_id IN ($recipient_id_list)")->fetch_assoc()['total'] ?? 0);
    }
    $file = $_FILES['file'] ?? null;
    $has_new_file = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $new_file_error = $has_new_file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK;
    $max_size = 10 * 1024 * 1024;

    if ($is_locked) {
        $err = 'The submission deadline for this folder has passed. Uploading and editing shared files are no longer available.';
    } elseif (!$post_assignment) {
        $err = 'Delegation permission is no longer active for this folder.';
    } elseif (!$post_delegate_subject_ids) {
        $err = 'No delegate subjects are configured for this folder. Ask your chairperson to update the delegation scope.';
    } elseif ($is_update && (!$group_id || !$has_own_group_row)) {
        $err = 'That shared upload could not be found or is no longer editable.';
    } elseif ($valid_colleague_count !== count($colleague_ids)) {
        $err = 'One or more selected colleagues are not valid active faculty in your department.';
    } elseif (!$subject) {
        $err = 'Please choose a subject assigned to this folder.';
    } elseif ($recipients_with_subject !== count($selected_recipients)) {
        $err = 'Assign this subject to every selected colleague before sharing files for it.';
    } elseif ($new_file_error || (!$is_update && !$has_new_file)) {
        $err = 'Please choose a valid file to upload.';
    } elseif ($has_new_file && $file['size'] > $max_size) {
        $err = 'The file must be 10MB or smaller.';
    } else {
        $recipients = $selected_recipients;
        $old_file_names = array_values(array_unique(array_column($existing_rows, 'file_name')));
        $original = $has_new_file ? basename($file['name']) : ($existing_rows[0]['original_name'] ?? '');
        $stored_name = $has_new_file
            ? 'file_' . bin2hex(random_bytes(12)) . '_' . time() . '.' . strtolower(pathinfo($original, PATHINFO_EXTENSION))
            : ($existing_rows[0]['file_name'] ?? '');
        $file_size = $has_new_file ? (int) $file['size'] : (int) ($existing_rows[0]['file_size'] ?? 0);
        $file_type = $has_new_file ? ($file['type'] ?: 'application/octet-stream') : ($existing_rows[0]['file_type'] ?? 'application/octet-stream');
        if (!$is_update) $group_id = bin2hex(random_bytes(16));
        $destination = UPLOAD_DIR . $stored_name;
        $file_saved = !$has_new_file || move_uploaded_file($file['tmp_name'], $destination);

        if (!$file_saved) {
            $err = 'The file could not be saved. Check the uploads folder permissions.';
        } else {
            $existing_by_recipient = [];
            foreach ($existing_rows as $row) $existing_by_recipient[(int) $row['uploaded_by']] = (int) $row['id'];
            $transaction_saved = false;
            try {
                $conn->begin_transaction();
                foreach ($existing_by_recipient as $recipient_id => $file_id) {
                    if (!in_array($recipient_id, $recipients, true)) {
                        $stmt = $conn->prepare('DELETE FROM files WHERE id = ? AND proxy_upload_group_id = ? AND uploaded_via_proxy_id = ?');
                        $stmt->bind_param('isi', $file_id, $group_id, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                foreach ($recipients as $recipient_id) {
                    if (isset($existing_by_recipient[$recipient_id])) {
                        $file_id = $existing_by_recipient[$recipient_id];
                        $stmt = $conn->prepare('UPDATE files SET file_name = ?, original_name = ?, subject_code = ?, description = ?, file_size = ?, file_type = ?, uploaded_at = NOW() WHERE id = ? AND proxy_upload_group_id = ? AND uploaded_via_proxy_id = ?');
                        $stmt->bind_param('ssssisisi', $stored_name, $original, $subject['subject_code'], $subject['subject_description'], $file_size, $file_type, $file_id, $group_id, $user_id);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        $stmt = $conn->prepare('INSERT INTO files (folder_id, uploaded_by, uploaded_via_proxy_id, proxy_upload_group_id, file_name, original_name, subject_code, description, file_size, file_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->bind_param('iiisssssis', $folder_id, $recipient_id, $user_id, $group_id, $stored_name, $original, $subject['subject_code'], $subject['subject_description'], $file_size, $file_type);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
                $conn->commit();
                $transaction_saved = true;
            } catch (Throwable $exception) {
                $conn->rollback();
                if ($has_new_file && is_file($destination)) unlink($destination);
                $_SESSION['flash_msg'] = '';
                $_SESSION['flash_err'] = 'The shared upload could not be saved. Please try again.';
            }

            if ($transaction_saved) {
                foreach ($old_file_names as $old_file_name) {
                    if ($old_file_name !== $stored_name) {
                        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM files WHERE file_name = ?');
                        $stmt->bind_param('s', $old_file_name);
                        $stmt->execute();
                        $still_used = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
                        $stmt->close();
                        $old_path = UPLOAD_DIR . basename($old_file_name);
                        if (!$still_used && is_file($old_path)) unlink($old_path);
                    }
                }

                $recipient_names = $conn->query('SELECT full_name FROM users WHERE id IN (' . implode(',', $recipients) . ') ORDER BY id')->fetch_all(MYSQLI_ASSOC);
                $recipient_label = implode(', ', array_column($recipient_names, 'full_name'));
                logActivity($conn, $user_id, $is_update ? 'UPDATE_SHARED_UPLOAD' : 'PROXY_UPLOAD_FILE', ($is_update ? 'Updated' : 'Uploaded') . " '$original' to '{$folder['folder_name']}' for $recipient_label ({$subject['subject_code']})");
                $_SESSION['flash_msg'] = $is_update ? 'Shared upload updated for you and the selected colleagues.' : 'File uploaded for you and the selected colleagues.';
                $_SESSION['flash_err'] = '';
            }
        }
    }

    if ($err !== '') {
        $_SESSION['flash_msg'] = '';
        $_SESSION['flash_err'] = $err;
    }
    $redirect = 'delegate_upload.php?folder_id=' . $folder_id;
    if ($err !== '' && $is_update && $group_id !== '') $redirect .= '&edit_group=' . rawurlencode($group_id);
    header('Location: ' . $redirect);
    exit;
}

if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (isset($_SESSION['flash_err'])) { $err = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }

// All faculty in this department, with their current submission status for this folder,
// so Faculty A can see at a glance who still needs a file uploaded for them.
$dept_faculty = $conn->query("
    SELECT u.id, u.full_name,
        (SELECT GROUP_CONCAT(DISTINCT s.subject_code ORDER BY s.subject_code SEPARATOR '|') FROM subjects s WHERE s.faculty_id = u.id AND s.department_id = $dept_id) AS subject_codes,
        (SELECT COUNT(*) FROM files fi WHERE fi.folder_id = $folder_id AND fi.uploaded_by = u.id) AS file_count
    FROM users u
    WHERE u.department_id = $dept_id AND u.role = 'faculty' AND u.is_active = 1
    ORDER BY u.full_name ASC
")->fetch_all(MYSQLI_ASSOC);

// Recent uploads made by this proxy on behalf of others, for this folder
$recent_proxy_uploads = $conn->query("
    SELECT fi.proxy_upload_group_id, fi.file_name, fi.original_name, fi.subject_code,
        MAX(fi.uploaded_at) AS uploaded_at,
        GROUP_CONCAT(u.full_name ORDER BY u.full_name SEPARATOR ', ') AS recipient_names
    FROM files fi
    JOIN users u ON u.id = fi.uploaded_by
    WHERE fi.folder_id = $folder_id AND fi.uploaded_via_proxy_id = $user_id AND fi.proxy_upload_group_id IS NOT NULL
    GROUP BY fi.proxy_upload_group_id, fi.file_name, fi.original_name, fi.subject_code
    ORDER BY MAX(fi.uploaded_at) DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

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
    <title>Upload for Colleague — CollTrace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --navy: #1C1917; --navy2: #231F1B; --red: #F97316;
            --bg: #FFF7ED; --card: #ffffff; --text: #1e2235; --muted: #6b7280;
            --border: #e5e7eb;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg); min-height: 100vh; padding: 92px 20px 48px; color: var(--text); }
        .header { position: fixed; top: 0; left: 0; right: 0; z-index: 100; height: 62px; display: flex; align-items: center; justify-content: space-between; padding: 0 28px; background: var(--navy); border-bottom: 2px solid var(--red); box-shadow: 0 2px 16px rgba(0,0,0,.25); }
        .header-left, .header-right { display: flex; align-items: center; gap: 14px; }
        .brand { display: flex; align-items: center; gap: 10px; color: #fff; font-size: 22px; font-weight: 800; text-decoration: none; }
        .brand-logo { width: 30px; height: 30px; object-fit: contain; flex-shrink: 0; }
        .brand span { color: var(--red); }
        .brand .brand-name { color: #fff; }
        .brand .brand-name span { color: var(--red); }
        .role-badge { padding: 4px 12px; border-radius: 20px; background: var(--red); color: #fff; font-size: 11px; font-weight: 700; }
        .header-link { padding: 7px 16px; border: 1px solid rgba(255,255,255,.2); border-radius: 8px; background: rgba(255,255,255,.12); color: #fff; font-size: 13px; text-decoration: none; transition: background .2s; }
        .header-link:hover { background: rgba(255,255,255,.22); }
        .header-link.logout { border-color: rgba(255,255,255,.35); background: transparent; }
        .header-link.logout:hover { background: rgba(255,255,255,.1); }
        .container { width: 100%; max-width: 680px; margin: 0 auto; }
        .card { background: var(--card); border-radius: 12px; padding: 24px; border: 1px solid var(--border); box-shadow: 0 4px 16px rgba(0,0,0,0.06); margin-bottom: 18px; }
        .title { font-size: 20px; line-height: 1.3; font-weight: 800; color: var(--text); margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .subtitle { font-size: 13px; line-height: 1.6; color: var(--muted); margin-bottom: 22px; }
        .msg { padding: 12px 14px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; line-height: 1.45; display: flex; align-items: center; gap: 8px; }
        .msg.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .msg.error { background: #fff5f5; color: #b91c1c; border: 1px solid #fecaca; }
        .title svg, .msg svg, .btn svg, .back-link svg, .card-title svg, .status-row svg {
            width: 1em; height: 1em; color: currentColor; flex-shrink: 0; display: inline-block; vertical-align: -0.12em; line-height: 1;
        }
        .title svg, .card-title svg { color: var(--red); }
        .form-group { display: grid; gap: 7px; margin-bottom: 16px; }
        .label { display: block; font-size: 11px; line-height: 1.2; font-weight: 800; color: #78716c; letter-spacing: .55px; text-transform: uppercase; }
        select, input[type=file], input[type=text], textarea { width: 100%; min-height: 42px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; background: #fff; color: var(--text); font-size: 13px; line-height: 1.4; font-family: inherit; }
        select:focus, input:focus, textarea:focus { border-color: var(--red); outline: 3px solid rgba(249,115,22,.12); }
        textarea { resize: vertical; min-height: 88px; }
        input[type=file] { padding: 9px 10px; color: var(--muted); }
        .recipient-list { max-height: 210px; overflow-y: auto; border: 1px solid var(--border); border-radius: 8px; background: #fff; padding: 5px 12px; }
        .recipient-option { display: flex; align-items: flex-start; gap: 9px; padding: 9px 0; border-bottom: 1px solid #f3f4f6; font-size: 13px; cursor: pointer; }
        .recipient-option[hidden] { display: none; }
        .recipient-option:last-child { border-bottom: 0; }
        .recipient-option input { margin-top: 2px; accent-color: var(--red); }
        .self-recipient { padding: 10px 12px; border-radius: 8px; background: #fff7ed; color: #9a3412; font-size: 13px; }
        .form-actions { margin-top: 4px; }
        .btn { width: 100%; min-height: 44px; padding: 11px 14px; background: linear-gradient(135deg, var(--red), #EA580C); color: #fff; border: none; border-radius: 8px; font-size: 13px; font-family: inherit; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 7px; box-shadow: 0 3px 12px rgba(249,115,22,.25); transition: background .2s, transform .2s; }
        .btn:hover { background: linear-gradient(135deg, #EA580C, #C2410C); transform: translateY(-1px); }
        .btn:disabled { background: #d1d5db; box-shadow: none; cursor: not-allowed; transform: none; }
        .card-title { font-size: 14px; line-height: 1.4; font-weight: 800; color: var(--text); margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .status-row { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 11px 0; border-bottom: 1px solid var(--border); font-size: 13px; line-height: 1.4; }
        .status-row:last-child { border-bottom: none; }
        .status-pill { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; }
        .pill-done { background: #dcfce7; color: #166534; }
        .pill-pending { background: #fef3c7; color: #92400e; }
        .proxy-row { font-size: 12px; line-height: 1.5; color: var(--muted); padding: 10px 0; border-bottom: 1px solid var(--border); }
        .proxy-row:last-child { border-bottom: none; }
        .proxy-row strong { color: var(--text); }
        .proxy-row-main { min-width: 0; flex: 1; }
        .proxy-row-actions { display: flex; align-items: center; gap: 12px; }
        .edit-upload { display: inline-flex; flex: none; align-items: center; justify-content: center; min-height: 36px; padding: 7px 13px; border: 1.5px solid #fed7aa; border-radius: 8px; background: #fff; color: var(--red); font-size: 12px; font-weight: 700; text-decoration: none; transition: background .2s, border-color .2s; }
        .edit-upload:hover { background: #fff7ed; border-color: var(--red); }
        @media (max-width: 560px) {
            .header { height: 58px; padding: 0 14px; }
            .header-left, .header-right { gap: 8px; }
            .brand { gap: 7px; font-size: 18px; }
            .brand-logo { width: 26px; height: 26px; }
            .header-link { padding: 6px 9px; font-size: 12px; }
            .role-badge { display: none; }
            body { padding: 78px 14px 32px; }
            .card { padding: 18px; }
        }

        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d6d3d1; border-radius: 20px; border: 2px solid transparent; background-clip: padding-box; }
        ::-webkit-scrollbar-thumb:hover { background: #F97316; }
        * { scrollbar-width: thin; scrollbar-color: #d6d3d1 transparent; }
    </style>
</head>
<body>

<header class="header">
    <div class="header-left">
        <a class="brand" href="dashboard.php"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><span class="brand-name">Coll<span>Trace</span></span></a>
        <a href="dashboard.php" class="header-link">← Dashboard</a>
    </div>
    <div class="header-right">
        <span class="role-badge">FACULTY</span>
        <a href="../logout.php" class="header-link logout">Logout</a>
    </div>
</header>

<div class="container">
    <div class="card">
        <div class="title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg><?= $edit_group_id ? 'Edit Shared Upload' : 'Upload for You &amp; Colleagues' ?></div>

        <?php if ($folder_deadline): ?>
        <div class="msg <?= $is_locked ? 'error' : 'success' ?>">
            <?= $is_locked ? 'The submission deadline has passed. Uploading and editing are closed.' : 'Submission deadline: ' . date('M d, Y g:i A', strtotime($folder_deadline)) ?>
        </div>
        <?php endif; ?>

        <?php if ($msg): ?>
        <div class="msg success"><?= $msg ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
        <div class="msg error"><?= $err ?></div>
        <?php endif; ?>

        <?php if (!$is_locked && $edit_group_id): ?>
        <p class="subtitle">The updated file, subject, and recipient list will be synchronized to your submission and the selected colleagues.</p>
        <?php elseif (!$is_locked): ?>
        <p class="subtitle">Your submission is always included. Select any colleagues who should receive the same file in this folder.</p>
        <?php endif; ?>

        <?php if ($is_locked): ?>
        <p class="subtitle">This folder is closed. Shared uploads cannot be created or changed after the deadline.</p>
        <?php else: ?>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?= $edit_group_id ? 'proxy_update' : 'proxy_upload' ?>">
            <?php if ($edit_group_id): ?><input type="hidden" name="proxy_upload_group_id" value="<?= sanitize($edit_group_id) ?>"><?php endif; ?>

            <div class="form-group">
                <label class="label">Also upload for these colleagues</label>
                <div class="self-recipient">Your account (<?= sanitize($_SESSION['full_name']) ?>) is always included.</div>
                <div class="recipient-list" id="recipientList">
                    <?php foreach ($dept_faculty as $fac): if ((int) $fac['id'] === $user_id) continue; ?>
                    <label class="recipient-option" data-subject-codes="<?= htmlspecialchars(json_encode(explode('|', $fac['subject_codes'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="checkbox" name="colleague_ids[]" value="<?= (int) $fac['id'] ?>" <?= in_array((int) $fac['id'], $edit_colleague_ids, true) ? 'checked' : '' ?>>
                        <span><?= sanitize($fac['full_name']) ?><?= $fac['file_count'] > 0 ? ' (has files in this folder)' : '' ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="label">File <?= $edit_group_id ? '(optional; leave empty to keep the current file)' : '' ?></label>
                <?php if ($edit_group_id && !empty($edit_group_rows[0]['original_name'])): ?><div class="self-recipient">Current file: <?= sanitize($edit_group_rows[0]['original_name']) ?></div><?php endif; ?>
                <input type="file" name="file" <?= $edit_group_id ? '' : 'required' ?>>
            </div>

            <div class="form-group">
                <label class="label">Subject</label>
                <select name="subject_id" id="uploadSubject" required>
                    <option value="">-- Select subject --</option>
                    <?php foreach ($my_subjects as $subject): ?>
                    <option value="<?= (int) $subject['id'] ?>" data-subject-code="<?= sanitize($subject['subject_code']) ?>" <?= $edit_group_id && ($edit_group_rows[0]['subject_code'] ?? '') === $subject['subject_code'] ? 'selected' : '' ?>><?= sanitize($subject['subject_code'] . ' - ' . $subject['subject_description']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$my_subjects): ?><p style="font-size:12px;color:#b91c1c;">No subjects are assigned to you for this folder. Please contact your chairperson.</p><?php else: ?><p id="recipientSubjectHint" style="font-size:12px;color:var(--muted);">Choose a subject to see only colleagues assigned to the same subject.</p><?php endif; ?>
            <div class="form-actions"><button type="submit" class="btn" <?= !$my_subjects ? 'disabled' : '' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 19h14"/></svg><?= $edit_group_id ? 'Update Upload for You &amp; Colleagues' : 'Upload for You &amp; Colleagues' ?></button></div>
            <?php if ($edit_group_id): ?><p style="margin-top:12px;text-align:center;"><a class="edit-upload" href="delegate_upload.php?folder_id=<?= $folder_id ?>">Cancel edit</a></p><?php endif; ?>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/><path d="M3 3h18v18H3z"/></svg> Submission Status — <?= sanitize($folder['folder_name']) ?></div>
        <?php foreach ($dept_faculty as $fac): ?>
        <div class="status-row">
            <span><?= sanitize($fac['full_name']) ?></span>
            <span class="status-pill <?= $fac['file_count'] > 0 ? 'pill-done' : 'pill-pending' ?>"><?= $fac['file_count'] > 0 ? 'Done' : 'Pending' ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($recent_proxy_uploads)): ?>
    <div class="card">
        <div class="card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="10"/></svg> Your Shared Uploads</div>
        <?php foreach ($recent_proxy_uploads as $p): ?>
        <div class="proxy-row" style="display:flex;align-items:center;justify-content:space-between;gap:14px;">
            <div class="proxy-row-main">
                <strong><?= sanitize($p['original_name']) ?></strong><br>
                For <?= sanitize($p['recipient_names']) ?> · <?= sanitize($p['subject_code']) ?> · <?= date('M d, Y g:i A', strtotime($p['uploaded_at'])) ?>
            </div>
            <?php if (!$is_locked): ?><div class="proxy-row-actions"><a class="edit-upload" href="delegate_upload.php?folder_id=<?= $folder_id ?>&amp;edit_group=<?= rawurlencode($p['proxy_upload_group_id']) ?>">Edit</a></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<script>
(function() {
    const subjectSelect = document.getElementById('uploadSubject');
    const recipientList = document.getElementById('recipientList');
    if (!subjectSelect || !recipientList) return;

    const recipientOptions = recipientList.querySelectorAll('.recipient-option');
    const hint = document.getElementById('recipientSubjectHint');
    function filterRecipients() {
        const selectedSubject = subjectSelect.options[subjectSelect.selectedIndex]?.dataset.subjectCode || '';
        let matches = 0;
        recipientOptions.forEach(option => {
            const subjectCodes = JSON.parse(option.dataset.subjectCodes || '[]');
            const matchesSubject = !selectedSubject || subjectCodes.includes(selectedSubject);
            option.hidden = !matchesSubject;
            const checkbox = option.querySelector('input[type="checkbox"]');
            if (!matchesSubject) checkbox.checked = false;
            else matches++;
        });
        if (hint && selectedSubject) {
            hint.textContent = matches
                ? 'Only colleagues assigned to ' + selectedSubject + ' are shown.'
                : 'No other faculty members are assigned to this subject.';
        }
    }

    subjectSelect.addEventListener('change', filterRecipients);
    filterRecipients();
})();
</script>

</body>
</html>