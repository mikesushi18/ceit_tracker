<?php
// admin/department.php
require_once '../includes/config.php';
require_once '../includes/school_year_helper.php';
requireAdminDashboardAccess();

$dept_id = intval($_GET['id'] ?? 0);
if (!$dept_id) redirect(BASE_URL . '/admin/dashboard.php');

$dept = $conn->query("SELECT * FROM departments WHERE id = $dept_id")->fetch_assoc();
if (!$dept) redirect(BASE_URL . '/admin/dashboard.php');

// Setup SY system
$active_sy       = setupSchoolYears($conn);
$viewing_sy_id = (int) ($active_sy['id'] ?? 0);
$viewing_sy    = $active_sy;

$msg = $err = '';

// CREATE FOLDER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_folder') {
        $fname  = trim($_POST['folder_name'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $sem    = sanitize($_POST['semester'] ?? 'End of Semester');
        $sy_id  = intval($_POST['sy_id'] ?? ($active_sy['id'] ?? 0));
        $sy_row = $conn->query("SELECT label FROM school_years WHERE id = $sy_id")->fetch_assoc();
        $sy_label = $sy_row['label'] ?? '';

        if (empty($fname)) {
            $err = 'Folder name is required.';
        } else {
            $uid = $_SESSION['user_id'];
            $stmt = $conn->prepare("INSERT INTO folders (folder_name, description, department_id, created_by, semester, school_year, school_year_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssiissi', $fname, $desc, $dept_id, $uid, $sem, $sy_label, $sy_id);
            if ($stmt->execute()) {
                logActivity($conn, $uid, 'CREATE_FOLDER', "Created folder '$fname' in {$dept['code']}");
                $msg = "Folder '$fname' created successfully!";
                $viewing_sy_id = $sy_id;
            } else {
                $err = 'Failed to create folder.';
            }
            $stmt->close();
        }
    }

    if ($_POST['action'] === 'delete_folder') {
        $fid = intval($_POST['folder_id']);
        $conn->query("DELETE FROM files WHERE folder_id = $fid");
        $conn->query("DELETE FROM folders WHERE id = $fid AND department_id = $dept_id");
        logActivity($conn, $_SESSION['user_id'], 'DELETE_FOLDER', "Deleted folder ID $fid from {$dept['code']}");
        $msg = 'Folder deleted.';
    }

    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_err'] = $err;
    header('Location: department.php?id=' . $dept_id . '&sy=' . $viewing_sy_id);
    exit;
}

if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (isset($_SESSION['flash_err'])) { $err = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }

// Ensure folder_groups table and group columns exist
$conn->query("CREATE TABLE IF NOT EXISTS folder_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    semester VARCHAR(100) DEFAULT 'End of Semester',
    description TEXT,
    school_year_id INT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
)");
$conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS group_id INT NULL");
$conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS subfolder_position INT DEFAULT 0");

// Fetch folder groups that have folders for this dept in this SY
$groups_raw = $conn->query("
    SELECT DISTINCT fg.id, fg.name, fg.semester, fg.description, fg.created_at,
        u.full_name AS creator
    FROM folder_groups fg
    JOIN folders f ON f.group_id = fg.id
    LEFT JOIN users u ON u.id = fg.created_by
    WHERE f.department_id = $dept_id AND f.school_year_id = $viewing_sy_id
      AND fg.school_year_id = $viewing_sy_id
    ORDER BY fg.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

// Also fetch standalone folders (no group_id) for backwards compat
$standalone_folders = $conn->query("
    SELECT f.*,
        u.full_name AS created_by_name,
        (SELECT COUNT(*) FROM files fi WHERE fi.folder_id = f.id) AS file_count
    FROM folders f
    LEFT JOIN users u ON u.id = f.created_by
    WHERE f.department_id = $dept_id AND f.school_year_id = $viewing_sy_id
    AND (f.group_id IS NULL OR f.group_id = 0)
    ORDER BY f.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Fetch faculty and chairperson submission profiles for this department
$faculty = $conn->query("
    SELECT u.*,
        (SELECT COUNT(*) FROM files fi
         JOIN folders fo ON fo.id = fi.folder_id
         LEFT JOIN folder_groups fg ON fg.id = fo.group_id
         WHERE fi.uploaded_by = u.id AND fo.department_id = $dept_id AND fo.school_year_id = $viewing_sy_id
           AND (fo.group_id IS NULL OR fg.school_year_id = $viewing_sy_id)) AS submitted_files
    FROM users u WHERE u.department_id = $dept_id AND u.role IN ('faculty', 'chairperson') AND u.is_active = 1
")->fetch_all(MYSQLI_ASSOC);

$faculty_subjects = [];
$subjects_result = $conn->query("SELECT faculty_id, subject_code, subject_description FROM subjects WHERE department_id = $dept_id ORDER BY subject_code ASC");
if (!$subjects_result) {
    throw new RuntimeException('Unable to load department subjects: ' . $conn->error);
}
foreach ($subjects_result->fetch_all(MYSQLI_ASSOC) as $subject) {
    $faculty_subjects[(int) $subject['faculty_id']][] = $subject;
}

$status_folders = $conn->query("
    SELECT f.id, f.folder_name, f.semester
    FROM folders f
    LEFT JOIN folder_groups fg ON fg.id = f.group_id
    WHERE f.department_id = $dept_id AND f.school_year_id = $viewing_sy_id
      AND (f.group_id IS NULL OR fg.school_year_id = $viewing_sy_id)
    ORDER BY f.subfolder_position ASC, f.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

// Group status folders by semester label (preserving the order they were fetched in)
$status_folders_by_semester = [];
foreach ($status_folders as $status_folder) {
    $sem_key = trim($status_folder['semester']) !== '' ? $status_folder['semester'] : 'Other';
    $status_folders_by_semester[$sem_key][] = $status_folder;
}
$folder_subject_completion = [];
$faculty_submission_totals = [];
$faculty_subject_status = [];
foreach ($faculty as $faculty_member) {
    $faculty_id = (int) $faculty_member['id'];
    $faculty_submission_totals[$faculty_id] = ['required' => 0, 'submitted' => 0];
    $folder_subject_completion[$faculty_id] = [];
    $faculty_subject_status[$faculty_member['id']] = [];
    foreach ($status_folders as $status_folder) {
        $file_rows = $conn->query("SELECT id, original_name, uploaded_at, subject_code, description FROM files WHERE folder_id = {$status_folder['id']} AND uploaded_by = {$faculty_member['id']} ORDER BY uploaded_at DESC")->fetch_all(MYSQLI_ASSOC);

        $subjects_by_code = [];
        foreach ($faculty_subjects[$faculty_id] ?? [] as $subject) {
            $subjects_by_code[$subject['subject_code']] = [
                'subject_code' => $subject['subject_code'],
                'description' => $subject['subject_description'],
                'files' => [],
            ];
        }
        foreach ($file_rows as $file) {
            $subject_code = trim((string) ($file['subject_code'] ?? ''));
            $subject_key = $subject_code !== '' ? $subject_code : 'Unassigned subject';
            if (!isset($subjects_by_code[$subject_key])) {
                $subjects_by_code[$subject_key] = [
                    'subject_code' => $subject_key,
                    'description' => (string) ($file['description'] ?? ''),
                    'files' => [],
                ];
            }
            $subjects_by_code[$subject_key]['files'][] = $file;
        }
        $required_subject_count = count($faculty_subjects[$faculty_id] ?? []);
        $submitted_subject_count = 0;
        foreach ($faculty_subjects[$faculty_id] ?? [] as $subject) {
            if (!empty($subjects_by_code[$subject['subject_code']]['files'])) {
                $submitted_subject_count++;
            }
        }
        $folder_subject_completion[$faculty_id][$status_folder['id']] = [
            'required' => $required_subject_count,
            'submitted' => $submitted_subject_count,
        ];
        $faculty_submission_totals[$faculty_id]['required'] += $required_subject_count;
        $faculty_submission_totals[$faculty_id]['submitted'] += $submitted_subject_count;
        $faculty_subject_status[$faculty_member['id']][$status_folder['id']] = array_values($subjects_by_code);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $dept['code'] ?> — CollTrace Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --navy:#1C1917; --navy2:#231F1B; --red:#F97316; --red2:#EA580C; --bg:#FFF7ED; --card:#fff; --text:#1e2235; --muted:#6b7280; --border:#e5e7eb; }
        body { font-family: 'Inter', 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--bg); min-height: 100vh; }

        /* HEADER */
        .header { background: linear-gradient(120deg, var(--navy) 0%, #0d0b0a 100%); border-bottom: 2px solid var(--red); color: #fff; padding: 0 28px; height: 62px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 16px rgba(0,0,0,0.35); }
        .brand { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 800; } .brand-logo { width: 30px; height: 30px; object-fit: contain; flex-shrink: 0; } .brand-text { display: inline-flex; align-items: center; } .brand span { color: var(--red); }
        .header-right { display: flex; align-items: center; gap: 12px; font-size: 14px; }
        .role-badge { background: var(--red); color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        a.btn-back { background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.2); color: #fff; padding: 7px 16px; border-radius: 8px; font-size: 13px; text-decoration: none; transition: background 0.2s; }
        a.btn-back:hover { background: rgba(255,255,255,0.2); }
        a.btn-logout { background: transparent; border: 1px solid rgba(255,255,255,0.35); color: #fff; padding: 7px 16px; border-radius: 8px; font-size: 13px; text-decoration: none; }

        /* CONTAINER */
        .container { max-width: 1100px; margin: 0 auto; padding: 28px 20px; }
        .breadcrumb { font-size: 13px; color: var(--muted); margin-bottom: 6px; }
        .breadcrumb a { color: var(--red); text-decoration: none; }
        .page-title { font-size: 24px; font-weight: 800; color: var(--text); display: flex; align-items: center; gap: 10px; }
        .page-title svg, .card-title svg, .msg svg, .archived-notice svg, .sy-manage-link svg, .folder-icon svg {
            width: 1em; height: 1em; color: var(--red); flex-shrink: 0;
            display: inline-block; vertical-align: -0.12em; line-height: 1;
        }
        .page-sub { color: var(--muted); font-size: 13px; margin-top: 4px; margin-bottom: 18px; }

        /* MSG */
        .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; }
        .msg.success { background: #edfff3; border: 1px solid #a3e6b3; color: #1a6b35; }
        .msg.error   { background: #fff3f3; border: 1px solid #ffb3b3; color: #c0351a; }

        /* ── SCHOOL YEAR TABS ── */
        .sy-tabs-wrap {
            display: flex; align-items: center; gap: 0;
            background: var(--card); border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.07);
            padding: 6px; margin-bottom: 22px; flex-wrap: wrap;
            border: 1.5px solid var(--border);
        }
        .sy-tab {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 8px 18px; border-radius: 8px;
            font-size: 13px; font-weight: 600;
            text-decoration: none; color: var(--muted);
            transition: all 0.2s; white-space: nowrap;
        }
        .sy-tab:hover { background: #f0f2f8; color: var(--text); }
        .sy-tab.active {
            background: var(--navy); color: #fff;
            box-shadow: 0 2px 8px rgba(26,26,46,0.3);
        }
        .sy-tab.active-sy { color: var(--text); }
        .sy-active-dot { width: 7px; height: 7px; border-radius: 50%; background: #4ade80; display: inline-block; }
        .sy-closed-dot { width: 7px; height: 7px; border-radius: 50%; background: #9ca3af; display: inline-block; }
        .sy-tab-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); padding: 0 8px; align-self: center; }
        .sy-manage-link {
            margin-left: auto; font-size: 12px; font-weight: 600; color: var(--red);
            text-decoration: none; padding: 7px 14px; border-radius: 8px;
            border: 1px solid rgba(232,65,24,0.3); transition: all 0.2s; white-space: nowrap;
        }
        .sy-manage-link:hover { background: rgba(232,65,24,0.07); }

        /* ARCHIVED NOTICE */
        .archived-notice {
            background: #f3f4f6; border: 1px solid var(--border); color: var(--muted);
            padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 8px;
        }

        /* TWO COL */
        .two-col { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 24px; align-items: start; }

        /* CARD */
        .card { background: var(--card); border-radius: 12px; padding: 22px 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.07); margin-bottom: 20px; }
        .card-title { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }

        /* FORM */
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-control { width: 100%; padding: 9px 12px; border: 1.5px solid var(--border); border-radius: 7px; font-size: 14px; outline: none; font-family: inherit; background: #fafbfc; transition: border-color 0.2s; }
        .form-control:focus { border-color: var(--red); background: #fff; }
        textarea.form-control { resize: vertical; min-height: 70px; }
        .btn-create { background: var(--red); color: #fff; border: none; padding: 10px 22px; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; width: 100%; transition: background 0.2s; }
        .btn-create:hover { background: var(--red2); }
        .btn-create:disabled { background: #ccc; cursor: not-allowed; }

        /* FOLDERS */
        .folder-item { background: var(--card); border-radius: 12px; padding: 16px 20px; margin-bottom: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 16px; border: 1.5px solid var(--border); transition: border-color 0.2s, box-shadow 0.2s; }
        .folder-item:hover { border-color: #c5cad4; box-shadow: 0 4px 16px rgba(0,0,0,0.09); }
        .folder-icon { font-size: 0; }
        .folder-icon svg { width: 16px; height: 16px; }
        .folder-info { flex: 1; }
        .folder-name { font-size: 15px; font-weight: 700; color: var(--text); }
        .folder-meta { font-size: 12px; color: var(--muted); margin-top: 3px; }
        .folder-actions { display: flex; gap: 8px; flex-shrink: 0; }
        .btn-view { background: var(--navy); color: #fff; border: none; padding: 7px 14px; border-radius: 7px; font-size: 13px; cursor: pointer; text-decoration: none; font-weight: 600; transition: background 0.2s; }
        .btn-view:hover { background: #2d2d4e; }
        .btn-delete { background: #fff; color: var(--red2); border: 1.5px solid #fecaca; padding: 7px 14px; border-radius: 7px; font-size: 13px; cursor: pointer; font-weight: 600; transition: all 0.2s; }
        .btn-delete:hover { background: #fff3f3; }
        .file-badge { background: var(--red); color: #fff; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; margin-left: 6px; }
        .empty-state { text-align: center; color: var(--muted); padding: 36px; font-size: 14px; }
        .status-card { background: var(--card); border-radius: 12px; padding: 18px; box-shadow: 0 2px 10px rgba(0,0,0,0.07); margin-bottom: 20px; }
        .status-card-title { font-size: 15px; font-weight: 800; color: var(--text); display:flex; align-items:center; gap:8px; margin-bottom:14px; }
        .status-card-title svg { width: 14px; height: 14px; flex-shrink: 0; }
        .faculty-search { width:100%; padding:9px 12px; border:1.5px solid var(--border); border-radius:8px; font:inherit; font-size:13px; margin-bottom:12px; outline:none; }
        .faculty-search:focus { border-color:var(--red); }
        .faculty-status { border: 1px solid var(--border); border-radius: 10px; padding: 12px; margin-bottom: 10px; background: #fafbfc; }
        .faculty-status:last-child { margin-bottom: 0; }
        .faculty-status-head { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
        .faculty-avatar { width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,#F97316,#FB923C); color:#fff; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:800; flex-shrink:0; }
        .faculty-status-name { flex:1; min-width:0; font-size:14px; font-weight:800; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .faculty-employment { display:block; color:var(--muted); font-size:11.5px; font-weight:600; margin-top:2px; }
        .faculty-progress { font-size:12.5px; color:var(--muted); font-weight:700; white-space:nowrap; }
        .faculty-submit-status { display:inline-flex; align-items:center; gap:4px; font-size:11.5px; font-weight:800; white-space:nowrap; }
        .faculty-submit-status::before { content:''; width:7px; height:7px; border-radius:50%; display:inline-block; }
        .faculty-submit-status.complete { color:#15803d; }
        .faculty-submit-status.complete::before { background:#22c55e; }
        .faculty-submit-status.incomplete { color:#b45309; }
        .faculty-submit-status.incomplete::before { background:#f97316; }
        .faculty-submit-status.none { color:#b91c1c; }
        .faculty-submit-status.none::before { background:#ef4444; }
        .progress-track { height:5px; background:#e5e7eb; border-radius:20px; overflow:hidden; margin-bottom:9px; }
        .progress-fill { height:100%; border-radius:20px; }
        .semester-group { margin-bottom:14px; border:1px solid var(--border); border-radius:8px; background:#fff; }
        .semester-group:last-child { margin-bottom:0; }
        .semester-group-label { display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 11px;color:#fff;background:var(--navy);border-radius:7px;cursor:pointer;font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;list-style:none; }
        .semester-group-label::-webkit-details-marker { display:none; }
        .semester-group-label::after { content:'⌄';font-size:16px;line-height:1;transition:transform .15s; }
        .semester-group[open] > .semester-group-label { border-radius:7px 7px 0 0; }
        .semester-group[open] > .semester-group-label::after { transform:rotate(180deg); }
        .semester-group-content { padding:10px; }
        .folder-status-list { display:grid; gap:7px; }
        .folder-status-row { display:flex; align-items:center; gap:7px; font-size:13px; font-weight:700; color:var(--muted); }
        .folder-status-row.done { color:#166534; }
        .folder-status-row.incomplete { color:#b45309; }
        .folder-status-row.none { color:#991b1b; }
        .folder-status-name { flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-weight:600; }
        .subject-status-list { display:grid; gap:6px; margin:7px 0 8px 13px; }
        .subject-status { border:1px solid #e7e5e4; border-radius:7px; background:#fff; }
        .subject-status summary { display:flex; align-items:center; gap:7px; min-height:34px; padding:6px 9px; color:#57534e; font-size:12px; cursor:pointer; list-style:none; }
        .subject-status summary::-webkit-details-marker { display:none; }
        .subject-status summary::before { content:'›'; color:#a8a29e; font-size:17px; line-height:12px; transition:transform .15s; }
        .subject-status[open] summary::before { transform:rotate(90deg); }
        .subject-status-name { flex:1; min-width:0; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .subject-status-description { color:var(--muted); font-weight:500; }
        .subject-status-state { width:8px; height:8px; flex:none; border-radius:50%; }
        .subject-status.submitted .subject-status-state { background:#22c55e; }
        .subject-status.pending .subject-status-state { background:#b91c1c; }
        .subject-status-content { padding:0 9px 7px 24px; }
        .subject-empty { color:var(--muted); font-size:11.5px; }
        .faculty-toggle { width:100%; border:0; background:transparent; padding:0; text-align:left; cursor:pointer; color:inherit; }
        .faculty-chevron { color:var(--muted); font-size:16px; transition:transform .2s; }
        .faculty-body { display:none; border-top:1px solid var(--border); margin-top:10px; padding-top:10px; }
        .folder-file-list { display:grid; gap:5px; margin:2px 0 8px 13px; }
        .folder-file { display:flex; align-items:center; gap:7px; color:#57534e; font-size:12px; }
        .folder-file a { color:#c2410c; text-decoration:none; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .folder-file a:hover { text-decoration:underline; }
        .faculty-empty-search { display:none; color:var(--muted); font-size:13px; padding:10px 0; text-align:center; }

        /* FACULTY TABLE */
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { background: #f8f9fb; padding: 10px 14px; text-align: left; font-size: 11px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 10px 14px; border-top: 1px solid var(--border); }

        @media (max-width: 850px) {
            .two-col { grid-template-columns: 1fr; }
        }

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
    <div style="display:flex;align-items:center;gap:16px;">
        <a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><div class="brand-text">Coll<span>Trace</span></div></a>
        <a href="dashboard.php" class="btn-back">← Dashboard</a>
    </div>
    <div class="header-right">
        <span class="role-badge">ADMIN</span>
        <a href="../logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<div class="container">
    <div class="breadcrumb"><a href="dashboard.php">Dashboard</a> › <?= sanitize($dept['code']) ?></div>
    <div class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg> <?= sanitize($dept['code']) ?></div>

    <?php if ($msg): ?><div class="msg success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> <?= $msg ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg error"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> <?= $err ?></div><?php endif; ?>

    <!-- ── ACTIVE SCHOOL YEAR ── -->
    <div class="sy-tabs-wrap">
        <span class="sy-tab-label">School Year:</span>
        <span class="sy-tab active"><span class="sy-active-dot"></span><?= sanitize($active_sy['label'] ?? 'No active school year') ?></span>
        <a href="school_year.php" class="sy-manage-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg> Manage SY</a>
    </div>

    <!-- Archived notice -->
    <?php if ($viewing_sy['status'] === 'closed'): ?>
    <div class="archived-notice">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18"/><path d="M5 7v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7"/><path d="M9 11h6"/><path d="M9 15h4"/></svg> <strong>Archived School Year <?= sanitize($viewing_sy['label']) ?></strong> — This year is closed. Folders are read-only. You can still view files and monitor submissions.
    </div>
    <?php endif; ?>

    <div class="two-col">
        <!-- LEFT: Grouped Folders -->
        <div>
            <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:14px;display:flex;align-items:center;gap:10px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;color:var(--red);"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg> Folders for <span style="background:var(--navy);color:#fff;padding:2px 12px;border-radius:8px;font-size:14px;"><?= sanitize($viewing_sy['label']) ?></span>
                <a href="manage_folders.php" style="font-size:12px;color:var(--red);font-weight:600;text-decoration:none;border:1px solid rgba(249,115,22,.3);padding:4px 12px;border-radius:8px;">+ Manage Folders</a>
            </div>

            <?php if (empty($groups_raw) && empty($standalone_folders)): ?>
                <div class="card"><div class="empty-state">No folders for <?= sanitize($viewing_sy['label']) ?> yet.<br><a href="manage_folders.php" style="color:var(--red);font-weight:700;text-decoration:none;display:inline-block;margin-top:8px;">Create a folder group</a></div></div>
            <?php endif; ?>

            <?php foreach ($groups_raw as $grp):
                // Fetch sub-folders for this group in this dept
                $subfolders = $conn->query("
                    SELECT f.*, 
                        (SELECT COUNT(*) FROM files fi WHERE fi.folder_id = f.id) AS file_count
                    FROM folders f
                    WHERE f.group_id = {$grp['id']} AND f.department_id = $dept_id
                      AND f.school_year_id = $viewing_sy_id
                    ORDER BY f.subfolder_position ASC
                ")->fetch_all(MYSQLI_ASSOC);
                // Total files in this group for this dept
                $grp_files = array_sum(array_column($subfolders, 'file_count'));
            ?>
            <div style="background:var(--card);border-radius:14px;margin-bottom:20px;border:1.5px solid var(--border);box-shadow:0 2px 10px rgba(0,0,0,.07);overflow:hidden;">
                <!-- Group Header -->
                <div style="background:linear-gradient(135deg,var(--navy),var(--navy2));padding:16px 20px;display:flex;align-items:center;gap:14px;cursor:pointer;" onclick="toggleGroup('grp-<?= $grp['id'] ?>')">
                    <div style="width:40px;height:40px;border-radius:9px;background:linear-gradient(135deg,#F97316,#FB923C);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;color:#fff;"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:16px;font-weight:800;color:#fff;"><?= sanitize($grp['name']) ?></div>
                        <div style="font-size:12px;color:#a8a29e;margin-top:2px;"><?= sanitize($grp['semester']) ?> · <?= count($subfolders) ?> sub-folders · <?= sanitize($grp['creator']) ?></div>
                    </div>
                    <div style="display:flex;gap:20px;flex-shrink:0;">
                        <div style="text-align:center;"><div style="font-size:18px;font-weight:800;color:#FB923C;"><?= count($subfolders) ?></div><div style="font-size:10px;color:#a8a29e;text-transform:uppercase;">Sub-folders</div></div>
                        <div style="text-align:center;"><div style="font-size:18px;font-weight:800;color:#4ade80;"><?= $grp_files ?></div><div style="font-size:10px;color:#a8a29e;text-transform:uppercase;">Files</div></div>
                    </div>
                    <div style="color:#a8a29e;font-size:18px;transition:transform .2s;" id="chevron-<?= $grp['id'] ?>">▼</div>
                </div>

                <!-- Sub-folders accordion -->
                <div id="grp-<?= $grp['id'] ?>" style="display:block;">
                    <?php foreach ($subfolders as $idx => $sf): ?>
                    <div style="border-top:1px solid var(--border);">
                        <!-- Sub-folder row -->
                        <div style="padding:13px 20px;display:flex;align-items:center;gap:12px;cursor:pointer;background:#fafbfc;" onclick="toggleSf('sf-<?= $sf['id'] ?>')">
                            <div style="width:28px;height:28px;border-radius:6px;background:linear-gradient(135deg,#1C1917,#231F1B);color:#FB923C;font-size:11px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?= $sf['subfolder_position'] ?></div>
                            <div style="flex:1;font-size:14px;font-weight:600;color:var(--text);"><?= sanitize($sf['folder_name']) ?></div>
                            <span style="background:<?= $sf['file_count'] > 0 ? 'var(--red)' : '#e5e7eb' ?>;color:<?= $sf['file_count'] > 0 ? '#fff' : 'var(--muted)' ?>;font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;"><?= $sf['file_count'] ?> files</span>
                            <a href="folder_view.php?id=<?= $sf['id'] ?>" onclick="event.stopPropagation()" class="btn-view" style="font-size:12px;padding:5px 12px;">View Files</a>
                            <span style="color:var(--muted);font-size:13px;">›</span>
                        </div>

                        <!-- Faculty inside sub-folder -->
                        <div id="sf-<?= $sf['id'] ?>" style="display:none;background:#fff;padding:12px 20px 14px 60px;border-top:1px solid var(--border);">
                            <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:10px;">Department Submissions</div>
                            <?php if (empty($faculty)): ?>
                                <div style="font-size:13px;color:var(--muted);">No department members yet.</div>
                            <?php else: ?>
                            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px;">
                            <?php foreach ($faculty as $fac):
                                $fac_files = $conn->query("SELECT COUNT(*) AS c FROM files WHERE folder_id = {$sf['id']} AND uploaded_by = {$fac['id']}")->fetch_assoc()['c'];
                            ?>
                            <div style="background:#fafbfc;border:1.5px solid <?= $fac_files > 0 ? '#bbf7d0' : 'var(--border)' ?>;border-radius:9px;padding:10px 13px;display:flex;align-items:center;gap:9px;">
                                <?php $fac_avatar_path = __DIR__ . '/../uploads/avatars/' . ($fac['profile_photo'] ?? ''); ?>
                                <?php if (!empty($fac['profile_photo']) && storageExists($fac_avatar_path)): ?>
                                    <img src="<?= BASE_URL . '/uploads/avatars/' . rawurlencode($fac['profile_photo']) . '?v=' . (storageModified($fac_avatar_path) ?: 0) ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                                <?php else: ?>
                                    <div style="width:32px;height:32px;border-radius:50%;background:<?= $fac_files > 0 ? 'linear-gradient(135deg,#22c55e,#16a34a)' : 'linear-gradient(135deg,#9ca3af,#6b7280)' ?>;color:#fff;font-size:13px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?= mb_strtoupper(mb_substr($fac['full_name'],0,1)) ?></div>
                                <?php endif; ?>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-size:12px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($fac['full_name']) ?></div>
                                    <div style="font-size:11px;color:<?= $fac_files > 0 ? '#16a34a' : 'var(--muted)' ?>;font-weight:600;"><?= $fac_files > 0 ? '✓ ' . $fac_files . ' file' . ($fac_files > 1 ? 's' : '') : 'Pending' ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php foreach ($standalone_folders as $folder): ?>
            <div class="folder-item">
                <div class="folder-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></div>
                <div class="folder-info">
                    <div class="folder-name">
                        <?= sanitize($folder['folder_name']) ?>
                        <span class="file-badge"><?= $folder['file_count'] ?> files</span>
                    </div>
                    <div class="folder-meta">
                        <?= sanitize($folder['semester']) ?> · SY <?= sanitize($viewing_sy['label']) ?>
                        · Created <?= date('M d, Y', strtotime($folder['created_at'])) ?>
                        · by <?= sanitize($folder['created_by_name']) ?>
                    </div>
                </div>
                <div class="folder-actions">
                    <a href="folder_view.php?id=<?= $folder['id'] ?>" class="btn-view">View Files</a>
                    <?php if ($viewing_sy['status'] === 'active'): ?>
                    <form method="POST" onsubmit="return confirm('Delete this folder and all its files?')">
                        <input type="hidden" name="action" value="delete_folder">
                        <input type="hidden" name="folder_id" value="<?= $folder['id'] ?>">
                        <button type="submit" class="btn-delete">Delete</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- RIGHT: Department Submission Status -->
        <div>
            <div class="status-card">
                <div class="status-card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0-2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg> Department Submission Status</div>
                <?php if (empty($faculty)): ?>
                    <div class="empty-state" style="padding:16px;">No department members yet.</div>
                <?php else: ?>
                <input type="search" id="faculty-search" class="faculty-search" placeholder="Search department members by name..." oninput="filterFaculty(this.value)">
                <div id="faculty-empty-search" class="faculty-empty-search">No department members match your search.</div>
                <?php foreach ($faculty as $f):
                    $faculty_id = (int) $f['id'];
                    $folder_total = count($status_folders);
                    $submitted_count = count(array_filter($folder_subject_completion[$faculty_id] ?? [], static fn($status) => $status['required'] > 0 && $status['submitted'] >= $status['required']));
                    $completion = $folder_total > 0 ? round($submitted_count / $folder_total * 100) : 0;
                    $employment_label = $f['role'] === 'chairperson' ? 'Chairperson' : ($f['employment_type'] === 'regular' ? 'Regular' : ($f['employment_type'] === 'contract_of_service' ? 'Contract of Service' : 'Status not set'));
                    $total_submitted_subjects = $faculty_submission_totals[$faculty_id]['submitted'] ?? 0;
                    $total_required_subjects = $faculty_submission_totals[$faculty_id]['required'] ?? 0;
                    $submission_class = $total_submitted_subjects === 0 ? 'none' : (($folder_total > 0 && $submitted_count >= $folder_total && $total_required_subjects > 0) ? 'complete' : 'incomplete');
                    $submission_label = $submission_class === 'none' ? 'No submission' : ($submission_class === 'complete' ? 'Completed' : 'Incomplete');
                ?>
                <div class="faculty-status" data-faculty-name="<?= strtolower(sanitize($f['full_name'])) ?>">
                    <button type="button" class="faculty-toggle" onclick="toggleFaculty(this)">
                      <div class="faculty-status-head">
                        <?php
                            $f_avatar_path = __DIR__ . '/../uploads/avatars/' . ($f['profile_photo'] ?? '');
                            $f_has_avatar  = !empty($f['profile_photo']) && storageExists($f_avatar_path);
                        ?>
                        <?php if ($f_has_avatar): ?>
                        <img class="faculty-avatar" src="<?= BASE_URL . '/uploads/avatars/' . rawurlencode($f['profile_photo']) . '?v=' . (storageModified($f_avatar_path) ?: 0) ?>" alt="" style="object-fit:cover;">
                        <?php else: ?>
                        <div class="faculty-avatar"><?= mb_strtoupper(mb_substr($f['full_name'], 0, 1)) ?></div>
                        <?php endif; ?>
                        <div class="faculty-status-name">
                            <?= sanitize($f['full_name']) ?>
                            <span class="faculty-employment"><?= $employment_label ?></span>
                        </div>
                        <div class="faculty-progress"><?= $submitted_count ?>/<?= $folder_total ?></div>
                        <span class="faculty-submit-status <?= $submission_class ?>"><?= $submission_label ?></span>
                        <span class="faculty-chevron">⌄</span>
                      </div>
                    </button>
                    <div class="progress-track"><div class="progress-fill" style="width:<?= $completion ?>%;background:<?= $completion === 100 && $folder_total > 0 ? '#22c55e' : ($completion > 0 ? '#f59e0b' : '#ef4444') ?>"></div></div>
                    <div class="faculty-body">
                      <?php foreach ($status_folders_by_semester as $sem_label => $sem_folders): ?>
                      <details class="semester-group">
                          <summary class="semester-group-label"><?= sanitize($sem_label) ?></summary>
                          <div class="semester-group-content">
                          <div class="folder-status-list">
                            <?php foreach ($sem_folders as $status_folder):
                                $folder_status = $folder_subject_completion[$faculty_id][$status_folder['id']] ?? ['required' => 0, 'submitted' => 0];
                                $folder_complete = $folder_status['required'] > 0 && $folder_status['submitted'] >= $folder_status['required'];
                                $folder_has_submission = $folder_status['submitted'] > 0;
                                $folder_status_class = $folder_complete ? 'done' : ($folder_has_submission ? 'incomplete' : 'none');
                                $folder_status_label = $folder_complete ? 'Completed' : ($folder_has_submission ? 'Incomplete' : 'No submission');
                            ?>
                            <div class="folder-status-row <?= $folder_status_class ?>">
                                <span class="folder-status-name" title="<?= sanitize($status_folder['folder_name']) ?>"><?= sanitize($status_folder['folder_name']) ?></span>
                                <span><?= $folder_status_label ?></span>
                            </div>
                            <div class="subject-status-list">
                            <?php foreach (($faculty_subject_status[$faculty_id][$status_folder['id']] ?? []) as $subject_status):
                                $subject_is_submitted = !empty($subject_status['files']);
                            ?>
                            <details class="subject-status <?= $subject_is_submitted ? 'submitted' : 'pending' ?>">
                                <summary>
                                    <span class="subject-status-name"><?= sanitize($subject_status['subject_code']) ?><?php if ($subject_status['description'] !== ''): ?> <span class="subject-status-description">— <?= sanitize($subject_status['description']) ?></span><?php endif; ?></span>
                                    <span class="subject-status-state" role="img" aria-label="<?= $subject_is_submitted ? 'Submitted' : 'Pending' ?>" title="<?= $subject_is_submitted ? 'Submitted' : 'Pending' ?>"></span>
                                </summary>
                                <div class="subject-status-content">
                                    <?php if ($subject_is_submitted): ?>
                                        <?php foreach ($subject_status['files'] as $file): ?>
                                        <div class="folder-file"><span>↳</span><a href="<?= BASE_URL ?>/includes/file_viewer.php?id=<?= $file['id'] ?>&dl=1" target="_blank" title="<?= sanitize($file['original_name']) ?>"><?= sanitize($file['original_name']) ?></a><span><?= date('M d, Y', strtotime($file['uploaded_at'])) ?></span></div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="subject-empty">No files submitted for this subject.</div>
                                    <?php endif; ?>
                                </div>
                            </details>
                            <?php endforeach; ?>
                            </div>
                            <?php endforeach; ?>
                          </div>
                          </div>
                      </details>
                      <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleGroup(id) {
    const el = document.getElementById(id);
    const gid = id.replace('grp-', '');
    const chevron = document.getElementById('chevron-' + gid);
    if (el.style.display === 'none') {
        el.style.display = 'block';
        if (chevron) chevron.style.transform = 'rotate(0deg)';
    } else {
        el.style.display = 'none';
        if (chevron) chevron.style.transform = 'rotate(-90deg)';
    }
}
function toggleSf(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function toggleFaculty(button) {
    const card = button.closest('.faculty-status');
    const body = card.querySelector('.faculty-body');
    const chevron = card.querySelector('.faculty-chevron');
    const isOpen = body.style.display === 'block';
    body.style.display = isOpen ? 'none' : 'block';
    chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(180deg)';
}
function filterFaculty(value) {
    const query = value.trim().toLowerCase();
    const cards = document.querySelectorAll('.faculty-status');
    let visible = 0;
    cards.forEach(function(card) {
        const matches = card.dataset.facultyName.includes(query);
        card.style.display = matches ? '' : 'none';
        if (matches) visible++;
    });
    document.getElementById('faculty-empty-search').style.display = visible ? 'none' : 'block';
}
</script>
</body>
</html>