<?php
// chairperson/assign_faculty.php - Assign faculty to folders for uploading documents
require_once '../includes/config.php';
require_once '../includes/school_year_helper.php';
requireRole('chairperson');

$dept_id = $_SESSION['department_id'];
$chair_id = $_SESSION['user_id'];
if (!$dept_id) { redirect(BASE_URL . '/index.php'); }

// Department info (for sidebar/header)
$dept = $conn->query("SELECT * FROM departments WHERE id = $dept_id")->fetch_assoc();
if (!$dept) { session_destroy(); redirect(BASE_URL . '/index.php'); }

// Profile photo (match dashboard)
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL");
$conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS group_id INT NULL");
$conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS subfolder_position INT DEFAULT 0");
$conn->query("CREATE TABLE IF NOT EXISTS folder_groups (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, semester VARCHAR(100) DEFAULT 'End of Semester', description TEXT, status ENUM('active','pending') DEFAULT 'active', school_year_id INT, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$conn->query("ALTER TABLE folder_groups ADD COLUMN IF NOT EXISTS status ENUM('active','pending') DEFAULT 'active'");
$_photo = $conn->query("SELECT profile_photo FROM users WHERE id = {$_SESSION['user_id']}")->fetch_assoc()['profile_photo'] ?? '';
$_photo_url = $_photo && file_exists(__DIR__ . '/../uploads/avatars/' . $_photo)
    ? BASE_URL . '/uploads/avatars/' . $_photo . '?v=' . filemtime(__DIR__ . '/../uploads/avatars/' . $_photo)
    : '';

// Ensure assignment tables exist
$conn->query("CREATE TABLE IF NOT EXISTS folder_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folder_id INT NOT NULL,
    assigned_to_user_id INT NOT NULL,
    assigned_by_user_id INT NOT NULL,
    can_delegate TINYINT(1) DEFAULT 0,
    has_subject_scope TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (folder_id) REFERENCES folders(id),
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id),
    FOREIGN KEY (assigned_by_user_id) REFERENCES users(id)
)");
$conn->query("ALTER TABLE folder_assignments ADD COLUMN IF NOT EXISTS has_subject_scope TINYINT(1) NOT NULL DEFAULT 0");

$conn->query("CREATE TABLE IF NOT EXISTS folder_delegations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    folder_id INT NOT NULL,
    delegated_from_user_id INT NOT NULL,
    delegated_to_user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (folder_id) REFERENCES folders(id),
    FOREIGN KEY (delegated_from_user_id) REFERENCES users(id),
    FOREIGN KEY (delegated_to_user_id) REFERENCES users(id)
)");

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
$conn->query("CREATE TABLE IF NOT EXISTS folder_assignment_subjects (
    assignment_id INT NOT NULL,
    subject_id INT NOT NULL,
    PRIMARY KEY (assignment_id, subject_id),
    FOREIGN KEY (assignment_id) REFERENCES folder_assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES department_subjects(id) ON DELETE CASCADE
)");

$msg = $err = '';

// Setup SY
$active_sy = setupSchoolYears($conn);
$viewing_sy_id = intval($_GET['sy'] ?? ($active_sy['id'] ?? 0));
$viewing_sy = $conn->query("SELECT * FROM school_years WHERE id = $viewing_sy_id")->fetch_assoc() ?? $active_sy;

// Pick up a one-time flash message left by a previous POST redirect
if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (isset($_SESSION['flash_err'])) { $err = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }

$department_subjects = $conn->query("SELECT id, subject_code, subject_description FROM department_subjects WHERE department_id = $dept_id ORDER BY subject_code")->fetch_all(MYSQLI_ASSOC);
$faculty_subject_ids = [];
$faculty_subject_rows = $conn->query("SELECT faculty_id, subject_code FROM subjects WHERE department_id = $dept_id ORDER BY subject_code")->fetch_all(MYSQLI_ASSOC);
foreach ($faculty_subject_rows as $faculty_subject_row) {
    $faculty_subject_ids[(int) $faculty_subject_row['faculty_id']][] = (string) $faculty_subject_row['subject_code'];
}

// ── ASSIGN FACULTY TO FOLDER ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_faculty') {
    $folder_id = intval($_POST['folder_id'] ?? 0);
    $faculty_id = intval($_POST['faculty_id'] ?? 0);
    $can_delegate = isset($_POST['can_delegate']) ? 1 : 0;
    $raw_subject_ids = $_POST['subject_ids'] ?? [];
    $subject_ids = is_array($raw_subject_ids)
        ? array_values(array_unique(array_filter(array_map('intval', $raw_subject_ids), static fn($id) => $id > 0)))
        : [];
    
    $folder = $conn->query("SELECT id, folder_name FROM folders WHERE id = $folder_id AND department_id = $dept_id AND school_year_id = $viewing_sy_id")->fetch_assoc();
    $faculty = $conn->query("SELECT * FROM users WHERE id = $faculty_id AND department_id = $dept_id AND role = 'faculty' AND is_active = 1")->fetch_assoc();
    $valid_subject_count = 0;
    if ($can_delegate && $subject_ids && $faculty) {
        $subject_id_list = implode(',', $subject_ids);
        $valid_subject_count = (int) ($conn->query("SELECT COUNT(DISTINCT ds.id) AS total FROM department_subjects ds JOIN subjects s ON s.department_id = ds.department_id AND s.subject_code = ds.subject_code WHERE ds.department_id = $dept_id AND s.faculty_id = $faculty_id AND s.department_id = $dept_id AND ds.id IN ($subject_id_list)")->fetch_assoc()['total'] ?? 0);
    }
    
    if (!$folder || !$faculty) {
        $_SESSION['flash_err'] = 'Select a valid document folder and active faculty member.';
    } elseif ($can_delegate && (!$subject_ids || $valid_subject_count !== count($subject_ids))) {
        $_SESSION['flash_err'] = 'Select one or more subjects assigned to this faculty member for delegate access.';
    } else {
        try {
            $conn->begin_transaction();
            if ($can_delegate) {
                $existing = $conn->query("SELECT id FROM folder_assignments WHERE folder_id = $folder_id AND assigned_to_user_id = $faculty_id")->fetch_assoc();

                if ($existing) {
                    $assignment_id = (int) $existing['id'];
                    $conn->query("UPDATE folder_assignments SET can_delegate = 1, has_subject_scope = 1, is_active = 1 WHERE id = $assignment_id");
                } else {
                    $stmt = $conn->prepare("INSERT INTO folder_assignments (folder_id, assigned_to_user_id, assigned_by_user_id, can_delegate, has_subject_scope) VALUES (?, ?, ?, 1, 1)");
                    $stmt->bind_param('iii', $folder_id, $faculty_id, $chair_id);
                    $stmt->execute();
                    $assignment_id = (int) $stmt->insert_id;
                    $stmt->close();
                }

                $stmt = $conn->prepare('DELETE FROM folder_assignment_subjects WHERE assignment_id = ?');
                $stmt->bind_param('i', $assignment_id);
                $stmt->execute();
                $stmt->close();
                $scope_stmt = $conn->prepare('INSERT INTO folder_assignment_subjects (assignment_id, subject_id) VALUES (?, ?)');
                foreach ($subject_ids as $subject_id) {
                    $scope_stmt->bind_param('ii', $assignment_id, $subject_id);
                    $scope_stmt->execute();
                }
                $scope_stmt->close();
            } else {
                $stmt = $conn->prepare('DELETE FROM folder_assignments WHERE folder_id = ? AND assigned_to_user_id = ?');
                $stmt->bind_param('ii', $folder_id, $faculty_id);
                $stmt->execute();
                $stmt->close();
            }
            $conn->commit();

            logActivity($conn, $chair_id, 'ASSIGN_DELEGATE', ($can_delegate ? 'Allowed' : 'Removed') . " delegate upload for {$faculty['full_name']} in folder '{$folder['folder_name']}'");
            $_SESSION['flash_msg'] = $can_delegate
                ? "{$faculty['full_name']} can now upload for colleagues in '{$folder['folder_name']}' for the selected subjects only."
                : "Delegate upload permission removed for {$faculty['full_name']} in '{$folder['folder_name']}'.";
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            $_SESSION['flash_err'] = 'Unable to save the faculty assignment. Please try again.';
        }
    }
    redirect(BASE_URL . '/chairperson/assign_faculty.php?sy=' . $viewing_sy_id);
    exit;
}

// ── REMOVE ASSIGNMENT ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_assignment') {
    $assignment_id = intval($_POST['assignment_id'] ?? 0);
    $assignment = $conn->query("SELECT fa.*, f.folder_name, u.full_name FROM folder_assignments fa JOIN folders f ON f.id = fa.folder_id JOIN users u ON u.id = fa.assigned_to_user_id WHERE fa.id = $assignment_id AND f.department_id = $dept_id")->fetch_assoc();
    
    if ($assignment) {
        $conn->query("DELETE FROM folder_assignments WHERE id = $assignment_id");
        $conn->query("DELETE FROM folder_delegations WHERE folder_id = {$assignment['folder_id']}");
        logActivity($conn, $chair_id, 'REMOVE_ASSIGNMENT', "Removed {$assignment['full_name']} from '{$assignment['folder_name']}'");
        $_SESSION['flash_msg'] = "Assignment removed.";
    } else {
        $_SESSION['flash_err'] = "Assignment not found.";
    }
    redirect(BASE_URL . '/chairperson/assign_faculty.php?sy=' . $viewing_sy_id);
    exit;
}

// Get folder groups for this SY
$folder_groups = $conn->query("
    SELECT DISTINCT fg.id, fg.name, fg.deadline
    FROM folder_groups fg
    JOIN folders f ON f.group_id = fg.id
    WHERE f.department_id = $dept_id AND f.school_year_id = $viewing_sy_id AND fg.status = 'active'
    ORDER BY fg.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

// Get all folders for this department and SY
$folders = $conn->query("
    SELECT f.*, fg.name as group_name, fg.deadline as group_deadline,
        (SELECT COUNT(*) FROM folder_assignments WHERE folder_id = f.id AND is_active = 1) AS assignment_count
    FROM folders f
    LEFT JOIN folder_groups fg ON fg.id = f.group_id
        WHERE f.department_id = $dept_id AND f.school_year_id = $viewing_sy_id
            AND (f.group_id IS NULL OR fg.status = 'active')
    ORDER BY f.subfolder_position ASC, f.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

// Get all active faculty in this department
$all_faculty = $conn->query("
    SELECT id, full_name, username FROM users
    WHERE department_id = $dept_id AND role = 'faculty' AND is_active = 1
    ORDER BY full_name ASC
")->fetch_all(MYSQLI_ASSOC);

// Get all assignments with details
$assignments = $conn->query("
    SELECT fa.*, f.folder_name, f.id as folder_id, u.full_name, u.id as faculty_id, fg.name as group_name,
        (SELECT GROUP_CONCAT(DISTINCT ds.subject_code ORDER BY ds.subject_code SEPARATOR ', ')
            FROM folder_assignment_subjects fas
            JOIN department_subjects ds ON ds.id = fas.subject_id AND ds.department_id = f.department_id
            WHERE fas.assignment_id = fa.id) AS delegated_subjects
    FROM folder_assignments fa
    JOIN folders f ON f.id = fa.folder_id
    JOIN users u ON u.id = fa.assigned_to_user_id
    LEFT JOIN folder_groups fg ON fg.id = f.group_id
        WHERE f.department_id = $dept_id AND fa.is_active = 1 AND fa.can_delegate = 1
            AND (f.group_id IS NULL OR fg.status = 'active')
    ORDER BY fg.name, f.subfolder_position, u.full_name
")->fetch_all(MYSQLI_ASSOC);

// Group assignments by folder group
$assignments_by_group = [];
foreach ($assignments as $a) {
    $gname = $a['group_name'] ?? 'Ungrouped';
    if (!isset($assignments_by_group[$gname])) {
        $assignments_by_group[$gname] = [];
    }
    $assignments_by_group[$gname][] = $a;
}

// File type hints for documents
$file_types = [
    'Syllabus' => 'PDF',
    'Course Outline' => 'PDF',
    'Lesson Plans' => 'PDF / DOC',
    'Instructional Materials' => 'PDF / PPT',
    'Attendance Records' => 'Excel / PDF',
    'Grading Sheet' => 'Excel / PDF',
    'Exam Papers' => 'PDF / Image',
    'Student Outputs' => 'PDF / DOC / Image',
    'Research/Extension' => 'PDF / DOC',
    'Faculty Portfolio' => 'PDF / Image',
    'Evaluation Results' => 'Excel / PDF',
    'Other Documents' => 'Any File Type'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Faculty — CollTrace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        svg { width: 1em; height: 1em; display: inline-block; vertical-align: -0.125em; }

        :root {
            --navy: #1C1917; --navy2: #231F1B; --red: #F97316; --red2: #EA580C;
            --bg: #FFF7ED; --card: #ffffff; --text: #1e2235; --muted: #6b7280;
            --border: #e5e7eb; --sidebar-w: 270px; --header-h: 62px;
        }

        body { font-family: 'Inter', sans-serif; background: var(--bg); min-height: 100vh; }

        .header {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            height: var(--header-h); background: linear-gradient(120deg, var(--navy) 0%, #0d0b0a 100%);
            border-bottom: 2px solid var(--red);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 28px; box-shadow: 0 2px 16px rgba(0,0,0,0.35);
        }
        .brand { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 800; color: #fff; }
        .brand-logo { width: 30px; height: 30px; object-fit: contain; flex-shrink: 0; }
        .brand-text { color: #fff; }
        .brand-text .accent { color: var(--red); }
        .header-right { display: flex; align-items: center; gap: 14px; }
        .dept-badge { background: rgba(255,255,255,0.13); color: #e8eaf0; padding: 4px 12px; border-radius: 20px; font-size: 12px; }
        .role-badge { background: var(--red); color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 11px; }
        a.btn-logout { background: transparent; border: 1px solid rgba(255,255,255,0.35); color: #fff; padding: 7px 16px; border-radius: 8px; font-size: 13px; text-decoration: none; transition: background 0.2s; }
        a.btn-logout:hover { background: rgba(255,255,255,0.1); }

        .layout { display: flex; padding-top: var(--header-h); min-height: 100vh; }
        .main { margin-left: var(--sidebar-w); flex: 1; padding: 28px 30px; min-width: 0; }

        /* ── SIDEBAR (match chairperson dashboard) ── */
        .sidebar {
            width: var(--sidebar-w);
            min-height: calc(100vh - var(--header-h));
            background: var(--navy2);
            position: fixed;
            top: var(--header-h);
            left: 0; bottom: 0;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 20px rgba(0,0,0,0.18);
        }
        .account-section { padding: 22px 18px 16px; border-bottom: 1px solid rgba(255,255,255,0.07); }
        .account-avatar {
            width: 54px; height: 54px; border-radius: 50%;
            background: linear-gradient(135deg, #F97316, #FB923C);
            color: #fff; font-size: 22px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 12px;
            box-shadow: 0 4px 12px rgba(232,65,24,0.35);
        }
        .account-name { font-size: 14px; font-weight: 700; color: #e8eaf0; margin-bottom: 3px; }
        .account-role { font-size: 11px; color: #7a84a0; margin-bottom: 2px; }
        .account-dept { font-size: 11px; color: #5a8aff; font-weight: 600; }

        .sidebar-section-label {
            font-size: 10px; font-weight: 700; letter-spacing: 1.5px;
            color: #5a6480; text-transform: uppercase;
            padding: 18px 18px 8px;
        }
        .sidebar-link-nav {
            display: flex; align-items: center; gap: 11px;
            padding: 11px 18px; color: #9ba3be;
            text-decoration: none; font-size: 13px; font-weight: 500;
            border-left: 3px solid transparent; transition: all 0.2s;
        }
        .sidebar-link-nav:hover { background: rgba(232,65,24,0.1); color: #fff; border-left-color: var(--red); }
        .sidebar-link-nav.active-nav { background: rgba(232,65,24,0.15); color: #fff; border-left-color: var(--red); }

        .sidebar-folder-item {
            display: flex; align-items: center; gap: 11px;
            padding: 11px 18px;
            color: #9ba3be;
            text-decoration: none;
            font-size: 13px; font-weight: 500;
            border-left: 3px solid transparent;
            transition: all 0.2s;
        }
        .sidebar-folder-item:hover { background: rgba(232,65,24,0.1); color: #fff; border-left-color: var(--red); }
        .folder-pill {
            margin-left: auto;
            font-size: 10px; font-weight: 700;
            padding: 2px 7px; border-radius: 8px;
            flex-shrink: 0;
        }
        .pill-done    { background: rgba(74,222,128,0.15); color: #4ade80; }
        .pill-partial { background: rgba(251,191,36,0.15);  color: #fbbf24; }
        .pill-empty   { background: rgba(156,163,175,0.15); color: #9ca3af; }

        .sidebar-logout { margin-top: auto; padding: 14px 18px; border-top: 1px solid rgba(255,255,255,0.07); }
        .sidebar-logout a {
            display: flex; align-items: center; gap: 9px;
            color: #f87171; font-size: 13px; font-weight: 500;
            text-decoration: none;
            padding: 9px 12px; border-radius: 8px;
            transition: background 0.2s;
        }
        .sidebar-logout a:hover { background: rgba(248,113,113,0.12); }

        
        .sidebar-logout a.sb-info-link { color: #c4cadd; }
        .sidebar-logout a.sb-info-link:hover { background: rgba(249,115,22,0.12); color: #fff; }
        .page-title { font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
        .page-sub { color: var(--muted); font-size: 13px; margin-bottom: 24px; }
        .page-title svg, .sidebar-link-nav svg, .sidebar-folder-item svg, .sidebar-logout svg, .form-section svg, .group-header svg, .assignment-row svg, .empty svg, .btn svg, .msg svg {
            width: 1em; height: 1em; color: var(--red); flex-shrink: 0;
            display: inline-block; vertical-align: -0.12em; line-height: 1;
        }
        .sidebar-link-nav svg { width: 16px; height: 16px; }
        .btn svg { color: inherit; }

        .msg { display: flex; align-items: center; gap: 8px; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; font-weight: 500; }
        .msg.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .msg.success svg { color: #16a34a; }
        .msg.error { background: #fff5f5; color: #b91c1c; border: 1px solid #fecaca; }
        .msg.error svg { color: #dc2626; }

        .form-section { max-width:980px;margin-bottom:24px;padding:24px 26px;border:1px solid var(--border);border-radius:12px;background:var(--card);box-shadow:0 3px 12px rgba(28,25,23,.06); }
        .form-section-heading { display:flex;align-items:center;gap:12px;margin-bottom:8px; }
        .form-section-icon { display:grid;width:40px;height:40px;flex:0 0 40px;place-items:center;border:1px solid #fed7aa;border-radius:9px;background:#fff7ed;color:#c2410c; }
        .form-section-icon svg { width:20px;height:20px; }
        .form-section-title { color:var(--text);font-size:16px;font-weight:750;line-height:1.4; }
        .form-section-description { max-width:740px;margin:0 0 20px 52px;color:var(--muted);font-size:13px;line-height:1.6; }
        .form-row { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 20px;margin-bottom:18px;align-items:start; }
        .form-field { min-width:0; }
        .form-label { display:block;margin-bottom:7px;color:#57534e;font-size:11px;font-weight:750;letter-spacing:.35px; }
        select, input[type="text"] { width:100%;height:44px;padding:0 36px 0 13px;border:1px solid #d6d3d1;border-radius:7px;background-color:#fff;color:#292524;font-size:13px;font-family:inherit;transition:border-color .15s,box-shadow .15s; }
        select { appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2378716c' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 13px center;cursor:pointer; }
        select:focus, input:focus { border-color:#f97316;outline:none;box-shadow:0 0 0 3px rgba(249,115,22,.12); }
        .select-hint { margin-top:6px;color:#8a8179;font-size:11px;line-height:1.45; }
        .form-check-group { grid-column:1 / -1;min-height:76px;padding:15px 17px;border:1px solid #e7e5e4;border-radius:9px;background:#fafaf9; }
        .form-check-group .form-label { margin-bottom:10px; }
        .form-check { display:flex;align-items:flex-start;gap:11px;min-height:26px;padding:0; }
        .form-check input[type="checkbox"] { width:19px;height:19px;margin-top:1px;accent-color:#ea580c;cursor:pointer;flex:none; }
        .form-check span { color:#44403c;font-size:13px;line-height:1.5;user-select:none; }
        .delegate-subject-scope { grid-column:1 / -1;padding:16px 17px;border:1px solid #fed7aa;border-radius:9px;background:#fffaf5; }
        .delegate-subject-scope[hidden] { display:none; }
        .delegate-subject-scope .form-label { margin-bottom:4px; }
        .delegate-subject-help { margin-bottom:12px;color:#78716c;font-size:12px;line-height:1.5; }
        .delegate-subject-options { display:flex;flex-wrap:wrap;gap:8px; }
        .delegate-subject-option { display:inline-flex;align-items:center;gap:8px;min-height:38px;padding:8px 11px;border:1px solid #e7e5e4;border-radius:7px;background:#fff;color:#44403c;font-size:12px;font-weight:650;cursor:pointer; }
        .delegate-subject-option[hidden] { display:none; }
        .delegate-subject-option:has(input:checked) { border-color:#fdba74;background:#fff7ed;color:#9a3412; }
        .delegate-subject-option input { width:16px;height:16px;margin:0;accent-color:#ea580c; }
        .delegate-subject-empty { display:none;color:#92400e;font-size:12px; }
        .delegate-subject-empty.visible { display:block; }
        .form-actions { display:flex;justify-content:flex-end;padding-top:12px;border-top:1px solid #f1f0ee; }
        .btn { display:inline-flex;align-items:center;justify-content:center;gap:8px;min-width:185px;min-height:38px;padding:0 14px;border:1px solid #c2410c;border-radius:6px;background:#c2410c;color:#fff;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;transition:background .15s,border-color .15s,box-shadow .15s; }
        .btn:hover { border-color:#9a3412;background:#9a3412;box-shadow:0 3px 9px rgba(154,52,18,.2); }
        .btn svg { width:17px;height:17px;flex:none;color:#fff;stroke-width:2.2; }
        .btn.secondary { border-color:#d6d3d1;background:#fff;color:#44403c; }
        .btn.secondary:hover { background:#f5f5f4; }

        /* Subject selector helpers retained for the shared assignment control styles. */
        .subject-dropdown { position: relative; }
        .subject-dropdown > summary { display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; height: 39px; padding: 9px 12px; border: 1.5px solid var(--border); border-radius: 8px; background: #fff; color: var(--text); font-size: 13px; cursor: pointer; list-style: none; }
        .subject-dropdown > summary::-webkit-details-marker { display: none; }
        .subject-dropdown > summary span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .subject-dropdown > summary svg { flex: none; width: 14px; height: 14px; transition: transform .15s; }
        .subject-dropdown[open] > summary { border-color: var(--red); box-shadow: 0 0 0 3px rgba(249,115,22,.12); }
        .subject-dropdown[open] > summary svg { transform: rotate(180deg); }
        .subject-picker { position: absolute; top: calc(100% + 5px); left: 0; right: 0; z-index: 30; max-height: 220px; overflow-y: auto; padding: 6px 10px; border: 1.5px solid var(--border); border-radius: 8px; background: #fff; box-shadow: 0 8px 24px rgba(28,25,23,.15); }
        .subject-option { display: flex; align-items: flex-start; gap: 8px; padding: 6px 0; color: var(--text); font-size: 12px; line-height: 1.4; cursor: pointer; }
        .subject-option[hidden] { display: none; }
        .subject-option + .subject-option { border-top: 1px solid #f1f0ee; }
        .subject-option input { margin-top: 2px; accent-color: var(--red); flex-shrink: 0; }
        .subject-option strong { color: #1c1917; font-size: 12px; }
        .subject-option span { color: #57534e; }
        .subject-picker-empty { padding: 11px 10px; border: 1.5px dashed #d6d3d1; border-radius: 8px; color: #78716c; font-size: 12px; }
        @media (max-width: 920px) { .form-row { grid-template-columns:1fr;gap:15px; }.form-check-group{grid-column:auto}.form-actions{justify-content:stretch}.btn{width:100%} }
        @media (max-width: 600px) { .form-section{padding:18px 15px}.form-section-description{margin-left:0}.assignment-row{align-items:flex-start;flex-direction:column}.assignment-actions{width:100%;justify-content:flex-end}.group-header{padding:12px 14px} }

        .assignments-heading { display:flex;align-items:flex-end;justify-content:space-between;gap:14px;margin:2px 0 10px; }
        .assignments-heading h3 { color:var(--text);font-size:15px;font-weight:750; }
        .assignments-heading p { margin-top:3px;color:var(--muted);font-size:10px; }
        .assignment-total { padding:5px 9px;border:1px solid #e7e5e4;border-radius:6px;background:#fff;color:#57534e;font-size:10px;font-weight:700;white-space:nowrap; }
        .assignments-list { overflow:hidden;border:1px solid var(--border);border-radius:8px;background:var(--card); }
        .group-header { display:flex;align-items:center;gap:8px;padding:9px 13px;border-bottom:1px solid var(--border);background:#f8f7f5;color:#57534e;font-size:10px;font-weight:800;letter-spacing:.4px;text-transform:uppercase; }
        .group-header svg { width:14px;height:14px;color:#a8a29e; }
        .assignment-row { display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 13px;border-bottom:1px solid #f1f0ee;transition:background .15s; }
        .assignment-row:hover { background:#fffdfa; }
        .assignment-row:last-child { border-bottom:none; }
        .assignment-info { flex:1;min-width:0; }
        .assignment-topline { display:flex;align-items:center;flex-wrap:wrap;gap:5px 8px; }
        .assignment-folder { color:#292524;font-size:12px;font-weight:700; }
        .assignment-type { padding:2px 6px;border:1px solid #e7e5e4;border-radius:4px;background:#fafaf9;color:#78716c;font-size:8px;font-weight:700;letter-spacing:.2px;text-transform:uppercase; }
        .assignment-faculty { display:flex;align-items:center;gap:5px;margin-top:4px;color:#6b7280;font-size:10px; }
        .assignment-faculty svg { width:14px;height:14px;color:#78716c; }
        .assignment-delegate { display:inline-flex;align-items:center;gap:4px;margin-top:6px;padding:3px 7px;border:1px solid #bbf7d0;border-radius:4px;background:#f0fdf4;color:#166534;font-size:9px;font-weight:650; }
        .assignment-delegate svg { width:13px;height:13px;color:#15803d; }
        .assignment-actions { display:flex;align-items:center;gap:7px;flex:none; }
        .assignment-subjects { display:flex;flex-wrap:wrap;gap:4px;margin-top:6px; }
        .assignment-subject-chip { padding:4px 8px;border:1px solid #fed7aa;border-radius:5px;background:#fff7ed;color:#9a3412;font-size:10px;font-weight:700; }
        .assignment-subject-empty { margin-top:5px;color:#a16207;font-size:9px; }
        .assignment-actions { display:flex;align-items:center;gap:8px;flex:none; }
        .btn-remove { display:inline-grid;place-items:center;width:32px;height:32px;border:1px solid #fecaca;border-radius:6px;background:#fff;color:#b91c1c;cursor:pointer;transition:background .15s,border-color .15s; }
        .btn-remove:hover { border-color:#fca5a5;background:#fff5f5; }
        .btn-remove svg { width:15px;height:15px;color:currentColor; }
        .btn-remove:focus-visible { outline:3px solid rgba(239,68,68,.2);outline-offset:2px; }

        .empty { display:flex;flex-direction:column;align-items:center;gap:10px;margin-top:18px;padding:34px 20px;border:1px dashed #d6d3d1;border-radius:9px;background:#fff;color:var(--muted);font-size:12px; }
        .empty svg { width:27px;height:27px;color:#a8a29e; }

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


<div class="header">
    <a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><span class="brand-text">Coll<span class="accent">Trace</span></span></a>
    <div class="header-right">
        <span class="dept-badge"><?= sanitize($dept['code']) ?></span>
        <span class="role-badge">CHAIRPERSON</span>
        <a href="../logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<div class="layout">
    <aside class="sidebar">
        <div class="account-section">
            <a href="<?= BASE_URL ?>/profile.php" style="display:block;width:fit-content;margin-bottom:12px;position:relative;" title="My Information">
                <?php if ($_photo_url): ?>
                    <img src="<?= $_photo_url ?>" alt="Profile" style="width:54px;height:54px;border-radius:50%;object-fit:cover;border:3px solid rgba(249,115,22,.6);display:block;">
                <?php else: ?>
                    <div class="account-avatar"><?= mb_strtoupper(mb_substr($_SESSION['full_name'], 0, 1)) ?></div>
                <?php endif; ?>
                <span style="position:absolute;bottom:0;right:0;width:18px;height:18px;background:#F97316;border-radius:50%;border:2px solid #231F1B;display:flex;align-items:center;justify-content:center;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:10px;height:10px;color:#fff;display:block;"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>
            </a>
            <div class="account-name"><?= sanitize($_SESSION['full_name']) ?></div>
            <div class="account-role">Department Chairperson</div>
            <div class="account-dept"><?= sanitize($dept['name']) ?></div>
        </div>

        <div class="sidebar-section-label">Navigation</div>
        <a href="dashboard.php" class="sidebar-link-nav"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14h3"/><path d="M7 10h3"/><path d="M13 18V8h3v10"/><path d="M17 13v5"/></svg></span> Dashboard</a>
        <a href="submission_status.php" class="sidebar-link-nav"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg></span> Submission Status</a>
        <a href="assign_faculty.php" class="sidebar-link-nav active-nav"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg></span> Assign Faculty</a>
        <a href="assign_subjects.php" class="sidebar-link-nav"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/><path d="M12 7v6"/><path d="M9 10h6"/></svg></span> Assign Subjects</a>
        <a href="subjects.php" class="sidebar-link-nav"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg></span> Department Subjects</a>

        <div class="sidebar-section-label">Department Folders</div>
        <?php if (empty($folders)): ?>
            <div style="padding: 12px 18px; font-size: 12px; color: #5a6480;">No folders yet.</div>
        <?php else: ?>
            <?php
                $faculty_count = count($all_faculty);
                foreach ($folders as $folder):
                    $sub_raw = $conn->query("SELECT COUNT(DISTINCT uploaded_by) as c FROM files WHERE folder_id = {$folder['id']}")->fetch_assoc()['c'];
                    if ($sub_raw >= $faculty_count && $faculty_count > 0) $pillClass = 'pill-done';
                    elseif ($sub_raw > 0) $pillClass = 'pill-partial';
                    else $pillClass = 'pill-empty';
                    $pillLabel = $sub_raw . '/' . $faculty_count;
            ?>
            <a href="folder_view.php?id=<?= $folder['id'] ?>" class="sidebar-folder-item">
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></span>
                <span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($folder['folder_name']) ?></span>
                <span class="folder-pill <?= $pillClass ?>"><?= $pillLabel ?></span>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="sidebar-logout">
            <a href="<?= BASE_URL ?>/profile.php" class="sb-info-link" style="margin-bottom:6px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> My Information</a>
</div>
    </aside>

    <main class="main">
        <div class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg> Assign Faculty to Folders</div>

        <?php if ($msg): ?>
        <div class="msg success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> <?= sanitize($msg) ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
        <div class="msg error"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg> <?= sanitize($err) ?></div>
        <?php endif; ?>

        <!-- ASSIGNMENT FORM -->
        <div class="form-section">
            <div class="form-section-heading">
                <span class="form-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg></span>
                <h2 class="form-section-title">Delegate Upload Access</h2>
            </div>
            <p class="form-section-description">Choose a folder and a faculty member. Delegates can upload documents for colleagues when their assigned subjects match.</p>
            <form method="POST">
                <input type="hidden" name="action" value="assign_faculty">
                <div class="form-row">
                    <div class="form-field">
                        <label class="form-label" for="delegate-folder">FOLDER / DOCUMENT</label>
                        <select name="folder_id" id="delegate-folder" required>
                            <option value="">-- Choose a folder --</option>
                            <?php foreach ($folder_groups as $grp): ?>
                                <?php 
                                    $grp_folders = array_filter($folders, fn($f) => ($f['group_id'] ?? 0) == $grp['id']);
                                    if (!empty($grp_folders)):
                                ?>
                                <optgroup label="<?= sanitize($grp['name']) ?>">
                                    <?php foreach ($grp_folders as $f): ?>
                                    <?php $file_type = $file_types[$f['folder_name']] ?? 'Any File'; ?>
                                    <option value="<?= (int) $f['id'] ?>"><?= sanitize($f['folder_name']) ?> — <?= sanitize($file_type) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php
                                $grouped_ids = array_column($folder_groups, 'id');
                                $ungrouped_folders = array_filter($folders, fn($f) => empty($f['group_id']) || !in_array($f['group_id'], $grouped_ids));
                            ?>
                            <?php if (!empty($ungrouped_folders)): ?>
                            <optgroup label="Ungrouped">
                                <?php foreach ($ungrouped_folders as $f): ?>
                                <?php $file_type = $file_types[$f['folder_name']] ?? 'Any File'; ?>
                                <option value="<?= (int) $f['id'] ?>"><?= sanitize($f['folder_name']) ?> — <?= sanitize($file_type) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                        <div class="select-hint">Folder where delegate upload permission will apply.</div>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="assignedFaculty">FACULTY DELEGATE</label>
                        <select name="faculty_id" id="assignedFaculty" required>
                            <option value="">-- Choose faculty --</option>
                            <?php foreach ($all_faculty as $fac): ?>
                            <option value="<?= $fac['id'] ?>"><?= sanitize($fac['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="select-hint">Select an active faculty member from this department.</div>
                    </div>
                    <div class="form-check-group">
                        <label class="form-label">PERMISSION</label>
                        <label class="form-check" for="can_delegate">
                            <input type="checkbox" name="can_delegate" id="can_delegate" value="1">
                            <span>Allow uploads for colleagues, limited to the selected subjects below.</span>
                        </label>
                    </div>
                    <div class="delegate-subject-scope" id="delegateSubjectScope" hidden>
                        <div class="form-label">SUBJECTS ALLOWED FOR DELEGATION</div>
                        <p class="delegate-subject-help">Only checked subjects will be available when this faculty member uploads for colleagues.</p>
                        <div class="delegate-subject-options">
                            <?php foreach ($department_subjects as $subject):
                                $assigned_faculty_ids = [];
                                foreach ($faculty_subject_ids as $assigned_faculty_id => $codes) {
                                    if (in_array($subject['subject_code'], $codes, true)) $assigned_faculty_ids[] = (int) $assigned_faculty_id;
                                }
                            ?>
                            <label class="delegate-subject-option" data-faculty-ids="<?= htmlspecialchars(implode(',', $assigned_faculty_ids), ENT_QUOTES, 'UTF-8') ?>" hidden>
                                <input type="checkbox" name="subject_ids[]" value="<?= (int) $subject['id'] ?>" disabled>
                                <span><?= sanitize($subject['subject_code']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="delegate-subject-empty" id="delegateSubjectEmpty">Choose a faculty member with assigned subjects to continue.</div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg><span>Save Delegate Access</span></button>
                </div>
            </form>
        </div>

        <!-- CURRENT ASSIGNMENTS -->
        <div>
            <div class="assignments-heading">
                <div><h3>Current Assignments</h3><p>Faculty members with delegate upload access in this school year.</p></div>
                <span class="assignment-total"><?= count($assignments) ?> <?= count($assignments) === 1 ? 'assignment' : 'assignments' ?></span>
            </div>
            <?php if (empty($assignments)): ?>
                <div class="empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h8"/><path d="M8 10h8"/><path d="M8 14h5"/><path d="M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"/></svg> No assignments yet. Create one above!</div>
            <?php else: ?>
                <div class="assignments-list">
                    <?php foreach ($assignments_by_group as $group_name => $group_assignments): ?>
                        <div class="group-header"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg><?= sanitize($group_name) ?></div>
                        <?php foreach ($group_assignments as $a): ?>
                        <div class="assignment-row">
                            <div class="assignment-info">
                                <div class="assignment-topline"><span class="assignment-folder"><?= sanitize($a['folder_name']) ?></span><span class="assignment-type"><?= sanitize($file_types[$a['folder_name']] ?? 'Any file') ?></span></div>
                                <div class="assignment-faculty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/></svg><?= sanitize($a['full_name']) ?></div>
                                <?php if (!empty($a['delegated_subjects'])): ?>
                                <div class="assignment-subjects" aria-label="Delegated subjects"><?php foreach (explode(', ', $a['delegated_subjects']) as $subject_code): ?><span class="assignment-subject-chip"><?= sanitize($subject_code) ?></span><?php endforeach; ?></div>
                                <?php else: ?>
                                <div class="assignment-subject-empty">No delegate subjects selected</div>
                                <?php endif; ?>
                                <?php if ($a['can_delegate']): ?>
                                <div class="assignment-delegate"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg> Delegate access enabled</div>
                                <?php endif; ?>
                            </div>
                            <div class="assignment-actions">
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="remove_assignment">
                                    <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                                    <button type="submit" class="btn-remove" aria-label="Remove delegate access for <?= htmlspecialchars($a['full_name'], ENT_QUOTES, 'UTF-8') ?> from <?= htmlspecialchars($a['folder_name'], ENT_QUOTES, 'UTF-8') ?>" title="Remove assignment" onclick="return confirm('Remove this assignment?')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg></button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<script>
(() => {
    const facultySelect = document.getElementById('assignedFaculty');
    const delegationToggle = document.getElementById('can_delegate');
    const scopePanel = document.getElementById('delegateSubjectScope');
    const emptyMessage = document.getElementById('delegateSubjectEmpty');
    const subjectOptions = Array.from(document.querySelectorAll('.delegate-subject-option'));
    const subjectCheckboxes = subjectOptions.map((option) => option.querySelector('input'));
    const form = delegationToggle?.form;
    if (!facultySelect || !delegationToggle || !scopePanel || !form) return;

    const updateSubjectChoices = () => {
        const facultyId = facultySelect.value;
        let visibleCount = 0;
        subjectOptions.forEach((option) => {
            const assignedIds = option.dataset.facultyIds.split(',').filter(Boolean);
            const available = Boolean(facultyId) && assignedIds.includes(facultyId);
            option.hidden = !available;
            const checkbox = option.querySelector('input');
            checkbox.disabled = !available || !delegationToggle.checked;
            if (!available) checkbox.checked = false;
            if (available) visibleCount++;
        });
        scopePanel.hidden = !delegationToggle.checked;
        emptyMessage.classList.toggle('visible', delegationToggle.checked && visibleCount === 0);
        facultySelect.setCustomValidity('');
    };

    facultySelect.addEventListener('change', updateSubjectChoices);
    delegationToggle.addEventListener('change', updateSubjectChoices);
    form.addEventListener('submit', (event) => {
        if (!delegationToggle.checked) return;
        const selectedCount = subjectCheckboxes.filter((checkbox) => !checkbox.disabled && checkbox.checked).length;
        if (selectedCount === 0) {
            event.preventDefault();
            facultySelect.setCustomValidity('Select a faculty member and at least one subject for delegation.');
            facultySelect.reportValidity();
        }
    });
    updateSubjectChoices();
})();
</script>

<script>
// Keep the sidebar's scroll position when navigating to another page (e.g. clicking a folder)
(function() {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    const KEY = 'coltrace_sidebar_scroll';
    const saved = sessionStorage.getItem(KEY);
    if (saved !== null) sidebar.scrollTop = parseInt(saved, 10);
    sidebar.addEventListener('scroll', () => sessionStorage.setItem(KEY, sidebar.scrollTop));
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
