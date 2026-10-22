<?php
// admin/manage_folders.php — Create & manage folder groups (applies to all departments)
require_once '../includes/config.php';
require_once '../includes/school_year_helper.php';
requireAdminDashboardAccess();

$active_sy = setupSchoolYears($conn);

// Ensure folder_groups table exists
$conn->query("CREATE TABLE IF NOT EXISTS folder_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    semester VARCHAR(100) DEFAULT 'End of Semester',
    description TEXT,
    status ENUM('active','pending') DEFAULT 'active',
    school_year_id INT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
)");
$conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS group_id INT NULL");
$conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS subfolder_position INT DEFAULT 0");
$conn->query("ALTER TABLE folder_groups ADD COLUMN IF NOT EXISTS deadline DATETIME NULL");
$conn->query("ALTER TABLE folder_groups MODIFY COLUMN deadline DATETIME NULL");
$conn->query("ALTER TABLE folder_groups ADD COLUMN IF NOT EXISTS status ENUM('active','pending') DEFAULT 'active'");

$msg = $err = '';
$departments = $conn->query("SELECT * FROM departments ORDER BY code")->fetch_all(MYSQLI_ASSOC);

// Default 12 sub-folder names
$DEFAULT_SUBFOLDERS = [
    'Syllabus', 'Course Outline', 'Lesson Plans', 'Instructional Materials',
    'Attendance Records', 'Grading Sheet', 'Exam Papers', 'Student Outputs',
    'Research/Extension', 'Faculty Portfolio', 'Evaluation Results', 'Other Documents'
];

// ── CREATE FOLDER GROUP ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_group') {
    $gname    = trim($_POST['group_name'] ?? '');
    $gsem     = sanitize($_POST['semester'] ?? 'End of Semester');
    $gdesc    = sanitize($_POST['description'] ?? '');
    $gdeadline= trim($_POST['deadline'] ?? '');
    if ($gdeadline !== '') {
        $gdeadline = date('Y-m-d H:i:s', strtotime($gdeadline));
    }
    $gdeadline= $gdeadline !== '' ? $gdeadline : null;
    $gstatus  = in_array($_POST['status'] ?? 'active', ['active', 'pending'], true) ? $_POST['status'] : 'pending';
    $sy_id    = intval($_POST['sy_id'] ?? ($active_sy['id'] ?? 0));
    $sy_row   = $conn->query("SELECT label FROM school_years WHERE id = $sy_id")->fetch_assoc();
    $sy_label = $sy_row['label'] ?? '';
    $uid      = $_SESSION['user_id'];

    if (empty($gname)) {
        $err = 'Folder name is required.';
    } elseif (empty($gdeadline)) {
        $err = 'Deadline is required.';
    } else {
        if ($gstatus === 'active') {
            $conn->query("UPDATE folder_groups SET status = 'pending' WHERE school_year_id = $sy_id");
        }
        // 1. Create the group
        $stmt = $conn->prepare("INSERT INTO folder_groups (name, semester, description, deadline, status, school_year_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssssii', $gname, $gsem, $gdesc, $gdeadline, $gstatus, $sy_id, $uid);
        $stmt->execute();
        $group_id = $conn->insert_id;
        $stmt->close();

        // 2. Auto-create 12 sub-folders × all 5 departments
        $pos = 1;
        foreach ($DEFAULT_SUBFOLDERS as $sfname) {
            foreach ($departments as $dept) {
                $dept_id = $dept['id'];
                $stmt2 = $conn->prepare("INSERT INTO folders (folder_name, description, department_id, created_by, semester, school_year, school_year_id, group_id, subfolder_position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt2->bind_param('ssiisssii', $sfname, $gdesc, $dept_id, $uid, $gsem, $sy_label, $sy_id, $group_id, $pos);
                $stmt2->execute();
                $stmt2->close();
            }
            $pos++;
        }

        logActivity($conn, $uid, 'CREATE_FOLDER_GROUP', "Created folder group '$gname' with 12 sub-folders across all departments");
        $msg = "✅ Folder group '$gname' created! 12 sub-folders applied to all " . count($departments) . " departments.";
    }
}

// ── ACTIVATE FOLDER GROUP ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate_group') {
    $group_id = intval($_POST['group_id'] ?? 0);
    $group = $conn->query("SELECT school_year_id, name FROM folder_groups WHERE id = $group_id")->fetch_assoc();
    if (!$group) {
        $err = 'Folder group not found.';
    } else {
        $conn->query("UPDATE folder_groups SET status = 'pending' WHERE school_year_id = {$group['school_year_id']}");
        $stmt = $conn->prepare("UPDATE folder_groups SET status = 'active' WHERE id = ?");
        $stmt->bind_param('i', $group_id);
        if ($stmt->execute()) {
            logActivity($conn, $_SESSION['user_id'], 'ACTIVATE_FOLDER_GROUP', "Activated folder group '{$group['name']}'");
            $msg = "Folder group '{$group['name']}' is now active.";
        } else {
            $err = 'Failed to activate the folder group.';
        }
        $stmt->close();
    }
}

// ── ADD SUB-FOLDER TO GROUP ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_subfolder') {
    $group_id = intval($_POST['group_id']);
    $sfname   = trim($_POST['subfolder_name'] ?? '');
    $uid = $_SESSION['user_id'];

    if (empty($sfname)) {
        $err = 'Sub-folder name is required.';
    } else {
        $grp = $conn->query("SELECT * FROM folder_groups WHERE id = $group_id")->fetch_assoc();
        if ($grp) {
            $max_pos = $conn->query("SELECT MAX(subfolder_position) as mp FROM folders WHERE group_id = $group_id")->fetch_assoc()['mp'] ?? 0;
            $pos = $max_pos + 1;
            $sy_row = $conn->query("SELECT label FROM school_years WHERE id = {$grp['school_year_id']}")->fetch_assoc();
            $sy_label = $sy_row['label'] ?? '';
            foreach ($departments as $dept) {
                $dept_id = $dept['id'];
                $stmt3 = $conn->prepare("INSERT INTO folders (folder_name, description, department_id, created_by, semester, school_year, school_year_id, group_id, subfolder_position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt3->bind_param('ssiisssii', $sfname, $grp['description'], $dept_id, $uid, $grp['semester'], $sy_label, $grp['school_year_id'], $group_id, $pos);
                $stmt3->execute();
                $stmt3->close();
            }
            logActivity($conn, $uid, 'ADD_SUBFOLDER', "Added sub-folder '$sfname' to group '$grp[name]'");
            $msg = "✅ Sub-folder '$sfname' added to all departments.";
        }
    }
}

// ── EDIT FOLDER GROUP ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_group') {
    $group_id = intval($_POST['group_id'] ?? 0);
    $gname = trim($_POST['group_name'] ?? '');
    $gsem = trim($_POST['semester'] ?? 'End of Semester');
    $gstatus = in_array($_POST['status'] ?? '', ['active', 'pending'], true) ? $_POST['status'] : '';
    $gdeadline = trim($_POST['deadline'] ?? '');
    $gdesc = sanitize($_POST['description'] ?? '');
    if ($gdeadline !== '') {
        $gdeadline = date('Y-m-d H:i:s', strtotime($gdeadline));
    }

    if (!$group_id || empty($gname) || empty($gsem) || empty($gstatus) || empty($gdeadline)) {
        $err = 'Folder name, semester, visibility, and deadline are required.';
    } else {
        $group = $conn->query("SELECT school_year_id FROM folder_groups WHERE id = $group_id")->fetch_assoc();
        if (!$group) {
            $err = 'Folder group not found.';
        } else {
            if ($gstatus === 'active') {
                $conn->query("UPDATE folder_groups SET status = 'pending' WHERE school_year_id = {$group['school_year_id']} AND id != $group_id");
            }
            $stmt = $conn->prepare("UPDATE folder_groups SET name = ?, semester = ?, description = ?, deadline = ?, status = ? WHERE id = ?");
            $stmt->bind_param('sssssi', $gname, $gsem, $gdesc, $gdeadline, $gstatus, $group_id);
            if ($stmt->execute()) {
                $sync_stmt = $conn->prepare("UPDATE folders SET semester = ?, description = ? WHERE group_id = ?");
                $sync_stmt->bind_param('ssi', $gsem, $gdesc, $group_id);
                $sync_stmt->execute();
                $sync_stmt->close();
                logActivity($conn, $_SESSION['user_id'], 'EDIT_FOLDER_GROUP', "Updated folder group ID $group_id to '$gname'");
                $msg = 'Folder group updated successfully.';
            } else {
                $err = 'Failed to update the folder group.';
            }
            $stmt->close();
        }
    }
}

// ── EDIT SUB-FOLDER ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_subfolder') {
    $group_id = intval($_POST['group_id'] ?? 0);
    $pos = intval($_POST['position'] ?? 0);
    $sfname = trim($_POST['subfolder_name'] ?? '');

    if (!$group_id || !$pos || empty($sfname)) {
        $err = 'Sub-folder name is required.';
    } else {
        $stmt = $conn->prepare("UPDATE folders SET folder_name = ? WHERE group_id = ? AND subfolder_position = ?");
        $stmt->bind_param('sii', $sfname, $group_id, $pos);
        if ($stmt->execute()) {
            logActivity($conn, $_SESSION['user_id'], 'EDIT_SUBFOLDER', "Renamed sub-folder $pos in group ID $group_id to '$sfname'");
            $msg = 'Sub-folder renamed successfully across all departments.';
        } else {
            $err = 'Failed to rename the sub-folder.';
        }
        $stmt->close();
    }
}

// ── DELETE GROUP ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_group') {
    $group_id = intval($_POST['group_id']);
    $folder_ids = $conn->query("SELECT id FROM folders WHERE group_id = $group_id")->fetch_all(MYSQLI_ASSOC);
    foreach ($folder_ids as $frow) {
        $conn->query("DELETE FROM files WHERE folder_id = {$frow['id']}");
    }
    $conn->query("DELETE FROM folders WHERE group_id = $group_id");
    $conn->query("DELETE FROM folder_groups WHERE id = $group_id");
    logActivity($conn, $_SESSION['user_id'], 'DELETE_FOLDER_GROUP', "Deleted folder group ID $group_id");
    $msg = '🗑️ Folder group and all its sub-folders deleted.';
}

// ── DELETE SUB-FOLDER ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_subfolder') {
    $group_id = intval($_POST['group_id']);
    $pos      = intval($_POST['position']);
    $folder_ids = $conn->query("SELECT id FROM folders WHERE group_id = $group_id AND subfolder_position = $pos")->fetch_all(MYSQLI_ASSOC);
    foreach ($folder_ids as $frow) {
        $conn->query("DELETE FROM files WHERE folder_id = {$frow['id']}");
        $conn->query("DELETE FROM folders WHERE id = {$frow['id']}");
    }
    $msg = '🗑️ Sub-folder removed from all departments.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_err'] = $err;
    $redirect_sy = $_POST['sy_id'] ?? ($_GET['sy'] ?? ($active_sy['id'] ?? ''));
    header('Location: manage_folders.php' . ($redirect_sy !== '' ? '?sy=' . intval($redirect_sy) : ''));
    exit;
}

if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (isset($_SESSION['flash_err'])) { $err = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }

// Folder management is limited to the active school year.
$viewing_sy_id = (int) ($active_sy['id'] ?? 0);
$viewing_sy = $active_sy;

$groups = $conn->query("
    SELECT fg.*, u.full_name AS creator,
        (SELECT COUNT(DISTINCT subfolder_position) FROM folders f WHERE f.group_id = fg.id) AS subfolder_count,
        (SELECT COUNT(*) FROM files fi JOIN folders f ON f.id = fi.folder_id WHERE f.group_id = fg.id) AS total_files
    FROM folder_groups fg
    LEFT JOIN users u ON u.id = fg.created_by
    WHERE fg.school_year_id = $viewing_sy_id
    ORDER BY fg.created_at DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Folders — CollTrace Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --navy:#1C1917; --navy2:#231F1B; --red:#F97316; --red2:#EA580C; --bg:#FFF7ED; --card:#fff; --text:#1e2235; --muted:#6b7280; --border:#e5e7eb; }
        body { font-family: 'Inter', 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--bg); min-height: 100vh; }

        .header { background: linear-gradient(120deg, var(--navy) 0%, #0d0b0a 100%); border-bottom: 2px solid var(--red); color: #fff; padding: 0 28px; height: 62px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 16px rgba(0,0,0,.35); }
        .brand { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 800; } .brand-logo { width: 30px; height: 30px; object-fit: contain; flex-shrink: 0; } .brand-text { display: inline-flex; align-items: center; } .brand span { color: var(--red); }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .header-right { display: flex; align-items: center; gap: 12px; }
        .role-badge { background: var(--red); color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        a.btn-back { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2); color: #fff; padding: 7px 16px; border-radius: 8px; font-size: 13px; text-decoration: none; transition: background .2s; }
        a.btn-back:hover { background: rgba(255,255,255,.22); }
        a.btn-logout { background: transparent; border: 1px solid rgba(255,255,255,.35); color: #fff; padding: 7px 16px; border-radius: 8px; font-size: 13px; text-decoration: none; }

        .container { max-width: 1440px; margin: 0 auto; padding: 32px 28px 60px; }
        .breadcrumb { font-size: 13px; color: var(--muted); margin-bottom: 6px; }
        .breadcrumb a { color: var(--red); text-decoration: none; }
        .page-title { font-size: 24px; font-weight: 800; color: var(--text); display: flex; align-items: center; gap: 10px; }
        .page-title svg, .alert svg, .create-card-title svg, .group-icon svg, .empty-groups svg, .btn-primary svg, .btn-del-group svg, .btn-add-sf svg {
            width: 1em; height: 1em; color: var(--red); flex-shrink: 0;
            display: inline-block; vertical-align: -0.12em; line-height: 1;
        }
        .group-icon svg, .btn-primary svg, .btn-del-group svg, .btn-add-sf svg { color: inherit; }
        .page-sub { font-size: 13px; color: var(--muted); margin-top: 4px; margin-bottom: 22px; }

        .alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 18px; }
        .alert-ok  { background: #f0fdf4; border: 1.5px solid #bbf7d0; color: #166534; }
        .alert-err { background: #fff5f5; border: 1.5px solid #fecaca; color: #b91c1c; }

        /* SY TABS */
        .sy-tabs { display: flex; align-items: center; gap: 8px; background: var(--card); border-radius: 12px; padding: 8px 12px; margin-bottom: 26px; border: 1.5px solid var(--border); flex-wrap: wrap; }
        .sy-tab { padding: 7px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; color: var(--muted); transition: all .2s; display: flex; align-items: center; gap: 6px; }
        .sy-tab:hover { background: #f0f2f8; color: var(--text); }
        .sy-tab.active { background: var(--navy); color: #fff; }
        .sy-dot-green { width: 7px; height: 7px; border-radius: 50%; background: #4ade80; }
        .sy-dot-gray  { width: 7px; height: 7px; border-radius: 50%; background: #9ca3af; }

        /* LAYOUT */
        .layout { display: grid; grid-template-columns: 380px minmax(0, 1fr); gap: 30px; align-items: start; }
        .layout > div:first-child { order: 2; }
        .layout > div:last-child { order: 1; }

        /* CREATE CARD */
        .create-card { background: var(--card); border-radius: 14px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,.08); border: 1.5px solid var(--border); position: sticky; top: 20px; }
        .create-card-title { font-size: 15px; font-weight: 800; color: var(--text); margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
        .form-label { display: block; font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .7px; margin-bottom: 6px; }
        .form-group { margin-bottom: 14px; }
        .form-control { width: 100%; padding: 10px 13px; border: 1.5px solid var(--border); border-radius: 9px; font-size: 14px; font-family: inherit; outline: none; background: #fafbfc; transition: border-color .2s; }
        .form-control:focus { border-color: var(--red); background: #fff; }
        textarea.form-control { resize: vertical; min-height: 70px; }
        .dept-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
        .dept-chip { background: #FFF7ED; border: 1px solid #fed7aa; color: #EA580C; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .btn-primary { background: linear-gradient(135deg, #F97316, #EA580C); color: #fff; border: none; padding: 12px 22px; border-radius: 9px; font-size: 14px; font-weight: 700; cursor: pointer; font-family: inherit; width: 100%; box-shadow: 0 3px 12px rgba(249,115,22,.3); transition: all .2s; }
        .btn-primary:hover { background: linear-gradient(135deg, #EA580C, #C2410C); transform: translateY(-1px); }

        /* GROUP CARD */
        .group-card { background: var(--card); border-radius: 14px; margin-bottom: 20px; border: 1.5px solid var(--border); box-shadow: 0 2px 10px rgba(0,0,0,.07); overflow: hidden; }
        .group-header { padding: 20px 26px; display: flex; align-items: center; gap: 16px; background: linear-gradient(135deg, var(--navy), var(--navy2)); color: #fff; }
        .group-icon { width: 44px; height: 44px; border-radius: 10px; background: linear-gradient(135deg, #F97316, #FB923C); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
        .group-title { font-size: 17px; font-weight: 800; flex: 1; }
        .group-meta { display: flex; flex-wrap: wrap; gap: 4px 14px; font-size: 12px; color: #d6d3d1; margin-top: 6px; line-height: 1.5; }
        .group-meta strong { color: #a8a29e; font-weight: 600; }
        .group-deadline { display: flex; align-items: center; gap: 5px; margin-top: 8px; color: #FB923C; font-size: 12px; font-weight: 700; line-height: 1.5; }
        .group-deadline svg { width: 14px; height: 14px; flex: 0 0 auto; }
        .group-status { display: inline-flex; padding: 3px 9px; border-radius: 20px; font-size: 10px; font-weight: 800; text-transform: uppercase; margin-left: 8px; }
        .group-status.active { background: rgba(74,222,128,.18); color: #86efac; }
        .group-status.pending { background: rgba(251,191,36,.18); color: #fcd34d; }
        .group-stats { display: flex; gap: 16px; flex-shrink: 0; }
        .g-stat { text-align: center; }
        .g-stat .num { font-size: 20px; font-weight: 800; color: #FB923C; }
        .g-stat .lbl { font-size: 10px; color: #a8a29e; text-transform: uppercase; letter-spacing: .5px; }
        .btn-del-group { background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.3); color: #fca5a5; padding: 6px 13px; border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit; transition: all .2s; }
        .btn-del-group:hover { background: rgba(239,68,68,.3); }
        .btn-edit-group { background: #fff; border: 1px solid #fed7aa; color: #c2410c; padding: 6px 13px; border-radius: 7px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; transition: background .15s,border-color .15s; }
        .btn-edit-group:hover { background: #fff7ed; border-color: #f97316; }
        .btn-activate-group { background: rgba(74,222,128,.14); border: 1px solid rgba(74,222,128,.35); color: #86efac; padding: 6px 13px; border-radius: 7px; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; }
        .btn-activate-group:hover { background: rgba(74,222,128,.25); }
        .edit-modal { position: fixed; inset: 0; z-index: 1000; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(28,25,23,.72); }
        .edit-modal.open { display: flex; }
        .edit-modal-card { width: min(520px, 100%); max-height: 92vh; overflow-y: auto; background: var(--card); border-radius: 14px; padding: 24px; box-shadow: 0 24px 70px rgba(0,0,0,.3); }
        .edit-modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
        .edit-modal-title { font-size: 17px; font-weight: 800; color: var(--text); }
        .edit-modal-close { border: 0; background: transparent; color: var(--muted); font-size: 24px; cursor: pointer; line-height: 1; }
        .edit-modal-actions { display: flex; gap: 9px; margin-top: 4px; }
        .edit-modal-actions .btn-primary { width: auto; flex: 1; }
        .btn-modal-cancel { min-height: 42px; padding: 0 16px; border: 1px solid #d6d3d1; border-radius: 8px; background: #fff; color: #57534e; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; }
        .btn-modal-cancel:hover { background: #f5f5f4; }

        /* SUB-FOLDERS */
        .subfolders-body { padding: 22px 26px; }
        .sf-label { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .7px; margin-bottom: 12px; }
        .sf-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; margin-bottom: 18px; }
        .sf-item { background: #fafbfc; border: 1.5px solid var(--border); border-radius: 10px; padding: 14px 16px; display: flex; align-items: center; gap: 12px; transition: border-color .2s; }
        .sf-item:hover { border-color: #F97316; }
        .sf-pos { width: 26px; height: 26px; border-radius: 6px; background: linear-gradient(135deg, #F97316, #EA580C); color: #fff; font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .sf-name { font-size: 13px; font-weight: 600; color: var(--text); flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sf-del { background: none; border: none; cursor: pointer; color: #d1d5db; font-size: 14px; padding: 2px; transition: color .2s; flex-shrink: 0; }
        .sf-del:hover { color: #ef4444; }
        .sf-edit { background: #fff; border: 1px solid #fed7aa; color: #c2410c; width: 26px; height: 26px; border-radius: 6px; font-size: 12px; font-weight: 700; cursor: pointer; transition: background .15s,border-color .15s; }
        .sf-edit:hover { background: #fff7ed; border-color: #f97316; }

        /* ADD SUB-FOLDER INLINE */
        .add-sf-row { display: flex; gap: 8px; }
        .add-sf-input { flex: 1; padding: 9px 13px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 13px; font-family: inherit; outline: none; transition: border-color .2s; }
        .add-sf-input:focus { border-color: var(--red); }
        .btn-add-sf { background: var(--navy); color: #fff; border: none; padding: 9px 16px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; transition: background .2s; }
        .btn-add-sf:hover { background: #2d2520; }

        .empty-groups { background: var(--card); border-radius: 14px; padding: 48px; text-align: center; border: 1.5px dashed var(--border); color: var(--muted); }
        .empty-groups .ei { font-size: 40px; margin-bottom: 12px; }
        @media (max-width:700px) { .layout { grid-template-columns: 1fr; } .layout > div:first-child, .layout > div:last-child { order: initial; } }

        /* ============ Custom Scrollbar (CollTrace) ============ */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d6d3d1; border-radius: 20px; border: 2px solid transparent; background-clip: padding-box; }
        ::-webkit-scrollbar-thumb:hover { background: #F97316; }
        * { scrollbar-width: thin; scrollbar-color: #d6d3d1 transparent; }
    </style>
</head>
<body>

<div class="header">
    <div class="header-left">
        <a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><div class="brand-text">Coll<span>Trace</span></div></a>
        <a href="dashboard.php" class="btn-back">← Dashboard</a>
    </div>
    <div class="header-right">
        <span class="role-badge">ADMIN</span>
        <a href="../logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<div class="container">
    <div class="breadcrumb"><a href="dashboard.php">Dashboard</a> › Manage Folders</div>
    <div class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg> Folder Management</div>
    <?php if ($msg): ?><div class="alert alert-ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> <?= $msg ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-err"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> <?= $err ?></div><?php endif; ?>

    <!-- ACTIVE SCHOOL YEAR -->
    <div class="sy-tabs">
        <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;">School Year</span>
        <span class="sy-tab active"><span class="sy-dot-green"></span><?= sanitize($active_sy['label'] ?? 'No active school year') ?></span>
    </div>

    <div class="layout">
        <!-- LEFT: Groups list -->
        <div>
            <?php if (empty($groups)): ?>
            <div class="empty-groups">
                <div class="ei"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></div>
                <strong>No folder groups yet for <?= sanitize($viewing_sy['label']) ?>.</strong><br>
            </div>
            <?php else: ?>
            <?php foreach ($groups as $grp): ?>
            <!-- Fetch distinct sub-folder positions for this group -->
            <?php
                $subfolders = $conn->query("
                    SELECT subfolder_position, folder_name
                    FROM folders
                    WHERE group_id = {$grp['id']}
                    GROUP BY subfolder_position, folder_name
                    ORDER BY subfolder_position ASC
                ")->fetch_all(MYSQLI_ASSOC);
            ?>
            <div class="group-card">
                <div class="group-header">
                    <div class="group-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></div>
                    <div style="flex:1;min-width:0;">
                        <div class="group-title"><?= sanitize($grp['name']) ?><span class="group-status <?= $grp['status'] === 'active' ? 'active' : 'pending' ?>"><?= $grp['status'] === 'active' ? 'Active' : 'Pending' ?></span></div>
                        <div class="group-meta">
                            <span><strong>Semester:</strong> <?= sanitize($grp['semester']) ?></span>
                            <span><strong>School Year:</strong> <?= sanitize($viewing_sy['label']) ?></span>
                            <span><strong>Made by:</strong> <?= sanitize($grp['creator'] ?? 'Unknown') ?></span>
                        </div>
                        <?php if (!empty($grp['deadline'])): ?>
                        <div class="group-deadline"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg><span>Deadline: <?= date('M d, Y g:i A', strtotime($grp['deadline'])) ?></span></div>
                        <?php endif; ?>
                    </div>
                    <div class="group-stats">
                        <div class="g-stat"><div class="num"><?= $grp['subfolder_count'] ?></div><div class="lbl">Sub-folders</div></div>
                        <div class="g-stat"><div class="num"><?= $grp['total_files'] ?></div><div class="lbl">Files</div></div>
                    </div>
                    <?php if ($viewing_sy['status'] === 'active'): ?>
                    <?php if ($grp['status'] !== 'active'): ?>
                    <form method="POST" onsubmit="return confirm('Set this folder group as active? The current active group will become pending.')">
                        <input type="hidden" name="action" value="activate_group">
                        <input type="hidden" name="group_id" value="<?= $grp['id'] ?>">
                        <button type="submit" class="btn-activate-group">Set Active</button>
                    </form>
                    <?php endif; ?>
                    <button type="button" class="btn-edit-group" onclick='editGroup(<?= htmlspecialchars(json_encode([
                        "id" => (int) $grp["id"],
                        "name" => $grp["name"],
                        "semester" => $grp["semester"],
                        "status" => $grp["status"],
                        "deadline" => $grp["deadline"],
                        "description" => $grp["description"] ?? ""
                    ]), ENT_QUOTES, "UTF-8") ?>)'>Edit</button>
                    <form method="POST" onsubmit="return confirm('Delete this entire folder group and ALL its sub-folders and files?')">
                        <input type="hidden" name="action" value="delete_group">
                        <input type="hidden" name="group_id" value="<?= $grp['id'] ?>">
                        <button type="submit" class="btn-del-group"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
                    </form>
                    <?php endif; ?>
                </div>

                <div class="subfolders-body">
                    <div class="sf-label">Sub-folders (<?= count($subfolders) ?>)</div>
                    <div class="sf-grid">
                        <?php foreach ($subfolders as $sf): ?>
                        <div class="sf-item">
                            <div class="sf-pos"><?= $sf['subfolder_position'] ?></div>
                            <div class="sf-name" title="<?= sanitize($sf['folder_name']) ?>"><?= sanitize($sf['folder_name']) ?></div>
                            <?php if ($viewing_sy['status'] === 'active'): ?>
                            <button type="button" class="sf-edit" title="Edit" onclick="renameSubfolder(<?= $grp['id'] ?>, <?= $sf['subfolder_position'] ?>, <?= htmlspecialchars(json_encode($sf['folder_name']), ENT_QUOTES, 'UTF-8') ?>)">✎</button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this sub-folder from all departments?')">
                                <input type="hidden" name="action" value="delete_subfolder">
                                <input type="hidden" name="group_id" value="<?= $grp['id'] ?>">
                                <input type="hidden" name="position" value="<?= $sf['subfolder_position'] ?>">
                                <button type="submit" class="sf-del" title="Delete">✕</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($viewing_sy['status'] === 'active'): ?>
                    <!-- ADD SUB-FOLDER -->
                    <form method="POST">
                        <input type="hidden" name="action" value="add_subfolder">
                        <input type="hidden" name="group_id" value="<?= $grp['id'] ?>">
                        <div class="add-sf-row">
                            <input type="text" name="subfolder_name" class="add-sf-input" placeholder="+ Add sub-folder name..." required>
                            <button type="submit" class="btn-add-sf"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg> Add</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Create form -->
        <div>
            <?php if ($viewing_sy['status'] === 'active'): ?>
            <div class="create-card">
                <div class="create-card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg> Create Folder Group</div>
         

                <div class="dept-chips">
                    <?php foreach ($departments as $d): ?>
                    <span class="dept-chip">✓ <?= sanitize($d['code']) ?></span>
                    <?php endforeach; ?>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="create_group">
                    <input type="hidden" name="sy_id" value="<?= $active_sy['id'] ?>">

                    <div class="form-group">
                        <label class="form-label">Folder Name *</label>
                        <input type="text" name="group_name" class="form-control" placeholder="e.g. End of Semester Requirements" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-control">
                            <option>1st Semester</option>
                            <option>2nd Semester</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Visibility</label>
                        <select name="status" class="form-control">
                            <option value="active">Active</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Deadline *</label>
                        <input type="datetime-local" name="deadline" class="form-control" required min="<?= date('Y-m-d\TH:i') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description (optional)</label>
                        <textarea name="description" class="form-control"></textarea>
                    </div>

                    <button type="submit" class="btn-primary"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg> Create Folder Group</button>
                </form>
            </div>
            <?php else: ?>
            <div class="create-card">
                <div class="create-card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18"/><path d="M5 7v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7"/><path d="M9 11h6"/><path d="M9 15h4"/></svg> Archived SY</div>
                <p style="font-size:13px;color:var(--muted);line-height:1.6;">SY <strong><?= sanitize($viewing_sy['label']) ?></strong> is closed.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="editGroupModal" class="edit-modal" onclick="if (event.target === this) closeEditGroup()">
    <div class="edit-modal-card">
        <div class="edit-modal-head">
            <div class="edit-modal-title">Edit Folder Group</div>
            <button type="button" class="edit-modal-close" onclick="closeEditGroup()" aria-label="Close">&times;</button>
        </div>
        <form method="POST" id="editGroupForm" onsubmit="return confirm('Save changes to this folder group?')">
            <input type="hidden" name="action" value="edit_group">
            <input type="hidden" name="group_id" id="edit_group_id">
            <input type="hidden" name="sy_id" value="<?= intval($viewing_sy_id) ?>">

            <div class="form-group">
                <label class="form-label">Folder Name *</label>
                <input type="text" name="group_name" id="edit_group_name" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label">Semester *</label>
                <select name="semester" id="edit_semester" class="form-control" required>
                    <option value="1st Semester">1st Semester</option>
                    <option value="2nd Semester">2nd Semester</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Visibility *</label>
                <select name="status" id="edit_status" class="form-control" required>
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Deadline *</label>
                <input type="datetime-local" name="deadline" id="edit_deadline" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label">Description (optional)</label>
                <textarea name="description" id="edit_description" class="form-control"></textarea>
            </div>

            <div class="edit-modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeEditGroup()">Cancel</button>
                <button type="submit" class="btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toDatetimeLocal(value) {
        if (!value) return '';
        var s = String(value).replace(' ', 'T');
        return s.length >= 16 ? s.slice(0, 16) : s;
    }

    function editGroup(group) {
        document.getElementById('edit_group_id').value = group.id;
        document.getElementById('edit_group_name').value = group.name || '';
        var sem = document.getElementById('edit_semester');
        sem.value = group.semester || '1st Semester';
        if (!sem.value) sem.selectedIndex = 0;
        document.getElementById('edit_status').value = group.status === 'active' ? 'active' : 'pending';
        document.getElementById('edit_deadline').value = toDatetimeLocal(group.deadline);
        document.getElementById('edit_description').value = group.description || '';
        document.getElementById('editGroupModal').classList.add('open');
    }

    function closeEditGroup() {
        document.getElementById('editGroupModal').classList.remove('open');
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeEditGroup();
    });

    function submitRename(fields) {
        var form = document.createElement('form');
        form.method = 'POST';
        fields.forEach(function(field) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = field.name;
            input.value = field.value;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    }

    function renameSubfolder(groupId, position, currentName) {
        var name = prompt('Enter a new sub-folder name:', currentName);
        if (name === null || !name.trim() || name.trim() === currentName) return;
        if (!confirm('Rename this sub-folder?')) return;
        submitRename([
            { name: 'action', value: 'edit_subfolder' },
            { name: 'group_id', value: groupId },
            { name: 'position', value: position },
            { name: 'subfolder_name', value: name.trim() }
        ]);
    }
</script>
</body>
</html>
