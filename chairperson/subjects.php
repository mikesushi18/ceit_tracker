<?php
require_once '../includes/config.php';
require_once '../includes/school_year_helper.php';
requireRole('chairperson');

$department_id = intval($_SESSION['department_id'] ?? 0);
if (!$department_id) redirect(BASE_URL . '/index.php');

$department = $conn->query("SELECT * FROM departments WHERE id = $department_id")->fetch_assoc();
if (!$department) redirect(BASE_URL . '/index.php');

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL");
$photo_name = $conn->query("SELECT profile_photo FROM users WHERE id = " . intval($_SESSION['user_id']))->fetch_assoc()['profile_photo'] ?? '';
$photo_path = __DIR__ . '/../uploads/avatars/' . $photo_name;
$photo_url = $photo_name && storageExists($photo_path)
    ? BASE_URL . '/uploads/avatars/' . $photo_name . '?v=' . (storageModified($photo_path) ?: 0)
    : '';

$active_school_year = setupSchoolYears($conn);
$active_school_year_id = intval($active_school_year['id'] ?? 0);
$faculty_count = intval($conn->query("SELECT COUNT(*) AS total FROM users WHERE department_id = $department_id AND role IN ('faculty', 'chairperson') AND is_active = 1")->fetch_assoc()['total'] ?? 0);
$folders_sql = "SELECT f.id, f.folder_name,
        (SELECT COUNT(DISTINCT fi.uploaded_by) FROM files fi JOIN users fu ON fu.id = fi.uploaded_by AND fu.role IN ('faculty', 'chairperson') WHERE fi.folder_id = f.id) AS submitted_count
    FROM folders f
    LEFT JOIN folder_groups fg ON fg.id = f.group_id
    WHERE f.department_id = $department_id
        AND (f.group_id IS NULL OR fg.status = 'active')";
if ($active_school_year_id) $folders_sql .= " AND f.school_year_id = $active_school_year_id";
$folders_sql .= ' ORDER BY f.subfolder_position ASC, f.created_at ASC';
$sidebar_folders = $conn->query($folders_sql)->fetch_all(MYSQLI_ASSOC);

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

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $subject_id = intval($_POST['subject_id'] ?? 0);

    if ($action === 'delete') {
        $stmt = $conn->prepare('DELETE FROM department_subjects WHERE id = ? AND department_id = ?');
        $stmt->bind_param('ii', $subject_id, $department_id);
        $stmt->execute();
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();
        $_SESSION['subject_notice'] = $deleted ? 'Subject removed from the department catalog.' : 'Subject was not found.';
    } elseif ($action === 'save') {
        $code = strtoupper(trim($_POST['subject_code'] ?? ''));
        $description = trim($_POST['subject_description'] ?? '');

        if ($code === '' || $description === '') {
            $error = 'Enter both a subject code and description.';
        } elseif (strlen($code) > 100 || strlen($description) > 255) {
            $error = 'Subject code or description is too long.';
        } else {
            if ($subject_id) {
                $stmt = $conn->prepare('UPDATE department_subjects SET subject_code = ?, subject_description = ? WHERE id = ? AND department_id = ?');
                $stmt->bind_param('ssii', $code, $description, $subject_id, $department_id);
            } else {
                $created_by = intval($_SESSION['user_id']);
                $stmt = $conn->prepare('INSERT INTO department_subjects (department_id, subject_code, subject_description, created_by) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('issi', $department_id, $code, $description, $created_by);
            }

            try {
                $saved = $stmt->execute();
            } catch (mysqli_sql_exception $exception) {
                if ((int) $exception->getCode() !== 1062) {
                    $stmt->close();
                    throw $exception;
                }
                $error = 'That subject code already exists in this department.';
                $saved = false;
            }

            if ($saved) {
                $_SESSION['subject_notice'] = $subject_id ? 'Subject updated.' : 'Subject added to the department catalog.';
                $stmt->close();
                header('Location: subjects.php');
                exit;
            }
            if (!$error) $error = $conn->errno === 1062 ? 'That subject code already exists in this department.' : 'Unable to save the subject.';
            $stmt->close();
        }
    }

    if (!$error) {
        header('Location: subjects.php');
        exit;
    }
}

$message = $_SESSION['subject_notice'] ?? '';
unset($_SESSION['subject_notice']);
$subjects = $conn->query("SELECT id, subject_code, subject_description, created_at FROM department_subjects WHERE department_id = $department_id ORDER BY subject_code ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Subjects — CollTrace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        svg { width: 1em; height: 1em; display: inline-block; vertical-align: -0.125em; }
        :root { --navy: #1C1917; --navy2: #231F1B; --red: #F97316; --red2: #EA580C; --bg: #FFF7ED; --card: #fff; --text: #1e2235; --muted: #6b7280; --border: #e5e7eb; --sidebar-w: 260px; --header-h: 62px; }
        body { min-height: 100vh; background: var(--bg); color: var(--text); font-family: 'Inter', 'Segoe UI', sans-serif; }
        .header { position: fixed; inset: 0 0 auto; z-index: 100; height: var(--header-h); padding: 0 28px; display: flex; align-items: center; justify-content: space-between; background: linear-gradient(120deg, var(--navy), #0d0b0a); border-bottom: 2px solid var(--red); box-shadow: 0 2px 16px rgba(0,0,0,.35); }
        .brand { display: flex; align-items: center; gap: 10px; color: #fff; font-size: 22px; font-weight: 800; }
        .brand-logo { width: 30px; height: 30px; object-fit: contain; }
        .brand-text { color: #fff; }
        .brand-text .accent { color: var(--red); }
        .header-right { display: flex; align-items: center; gap: 14px; }
        .dept-badge { padding: 4px 12px; border-radius: 20px; background: rgba(255,255,255,.13); color: #e8eaf0; font-size: 12px; }
        .role-badge { padding: 4px 12px; border-radius: 20px; background: var(--red); color: #fff; font-size: 11px; font-weight: 700; }
        .btn-logout { padding: 7px 16px; border: 1px solid rgba(255,255,255,.35); border-radius: 8px; color: #fff; font-size: 13px; text-decoration: none; }
        .btn-logout:hover { background: rgba(255,255,255,.1); }
        .layout { display: flex; min-height: 100vh; padding-top: var(--header-h); }
        .sidebar { position: fixed; top: var(--header-h); bottom: 0; left: 0; display: flex; flex-direction: column; width: var(--sidebar-w); min-height: calc(100vh - var(--header-h)); overflow-y: auto; background: var(--navy2); box-shadow: 2px 0 20px rgba(0,0,0,.18); }
        .account-section { padding: 22px 18px 16px; border-bottom: 1px solid rgba(255,255,255,.07); }
        .account-avatar { display: flex; align-items: center; justify-content: center; width: 54px; height: 54px; margin-bottom: 12px; border-radius: 50%; background: linear-gradient(135deg, #F97316, #FB923C); color: #fff; font-size: 22px; font-weight: 800; }
        .account-name { margin-bottom: 3px; color: #e8eaf0; font-size: 14px; font-weight: 700; }
        .account-role { margin-bottom: 2px; color: #7a84a0; font-size: 11px; }
        .account-dept { color: #5a8aff; font-size: 11px; font-weight: 600; }
        .sidebar-section-label { padding: 22px 20px 8px; color: #5a6480; font-size: 10px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; }
        .sidebar-folder-item { display: flex; align-items: center; gap: 11px; padding: 11px 18px; border-left: 3px solid transparent; color: #9ba3be; font-size: 13px; font-weight: 500; text-decoration: none; transition: all .2s; }
        .sidebar-folder-item:hover { border-left-color: var(--red); background: rgba(249,115,22,.1); color: #fff; }
        .sidebar-bottom { padding: 0 14px; }
        .sidebar-link-nav { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: 8px; color: #8a93ae; font-size: 13px; font-weight: 500; text-decoration: none; transition: all .2s; }
        .sidebar-link-nav:hover { background: rgba(255,255,255,.07); color: #fff; }
        .sidebar-link-nav.active-nav { background: rgba(255,255,255,.07); color: #fff; }
        .sidebar-link-nav .nav-icon { display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; flex-shrink: 0; color: var(--red); }
        .sidebar-link-nav .nav-icon svg { width: 16px; height: 16px; }
        .sidebar-folder-item svg, .sidebar-logout svg, .page-title svg, .section-title svg, .msg svg, .btn svg, .empty svg { flex-shrink: 0; color: var(--red); }
        .sidebar-folder-item span:nth-child(2) { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .folder-pill { flex-shrink: 0; margin-left: auto; padding: 2px 7px; border-radius: 8px; font-size: 10px; font-weight: 700; }
        .pill-done { background: rgba(74,222,128,.15); color: #4ade80; }
        .pill-partial { background: rgba(251,191,36,.15); color: #fbbf24; }
        .pill-empty { background: rgba(156,163,175,.15); color: #9ca3af; }
        .sidebar-logout { margin-top: auto; padding: 14px 18px; border-top: 1px solid rgba(255,255,255,.07); }
        .sidebar-logout a { display: flex; align-items: center; gap: 9px; padding: 9px 12px; border-radius: 8px; color: #c4cadd; font-size: 13px; text-decoration: none; }
        .sidebar-logout a:hover { background: rgba(249,115,22,.12); color: #fff; }
        .main { flex: 1; min-width: 0; margin-left: var(--sidebar-w); padding: 28px 30px; }
        .breadcrumb { margin-bottom: 6px; color: var(--muted); font-size: 13px; }
        .breadcrumb a { color: var(--red); text-decoration: none; }
        .page-title { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; color: #1c1917; font-size: 22px; font-weight: 800; }
        .page-sub { margin-bottom: 22px; color: var(--muted); font-size: 13px; line-height: 1.55; }
        .msg { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; padding: 12px 16px; border-radius: 8px; font-size: 13px; font-weight: 500; }
        .msg.success { border: 1px solid #bbf7d0; background: #f0fdf4; color: #166534; }
        .msg.success svg { color: #16a34a; }
        .msg.error { border: 1px solid #fecaca; background: #fff5f5; color: #b91c1c; }
        .msg.error svg { color: #dc2626; }
        .form-section { margin-bottom: 24px; padding: 22px; border: 1.5px solid var(--border); border-radius: 14px; background: var(--card); }
        .section-title { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; color: var(--text); font-size: 16px; font-weight: 700; }
        .form-row { display: grid; grid-template-columns: minmax(170px, .8fr) minmax(240px, 1.5fr) auto; gap: 14px; align-items: end; }
        .form-label { display: block; margin-bottom: 6px; color: #78716c; font-size: 12px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; }
        input[type=text] { width: 100%; min-height: 39px; padding: 9px 12px; border: 1.5px solid var(--border); border-radius: 8px; background: #fff; color: var(--text); font: inherit; font-size: 13px; }
        input:focus { border-color: var(--red); outline: none; box-shadow: 0 0 0 3px rgba(249,115,22,.12); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 39px; padding: 9px 18px; border: 0; border-radius: 8px; background: var(--red); color: #fff; font: inherit; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; white-space: nowrap; }
        .btn:hover { background: var(--red2); }
        .btn svg { color: inherit; }
        .assignments-list { overflow: hidden; border: 1.5px solid var(--border); border-radius: 12px; background: var(--card); }
        .group-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 20px; border-bottom: 1px solid var(--border); background: #f8fafc; color: #1c1917; font-size: 15px; font-weight: 750; }
        .subject-count { flex-shrink: 0; padding: 5px 9px; border: 1px solid #d6d3d1; border-radius: 6px; background: #fff; color: #44403c; font-size: 11px; font-weight: 700; }
        .subject-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 16px 24px; align-items: center; padding: 18px 20px; border-bottom: 1px solid var(--border); transition: background .15s; }
        .subject-row:hover { background: #fafaf9; }
        .subject-row:last-child { border-bottom: 0; }
        .subject-info { min-width: 0; display: grid; justify-items: start; gap: 7px; }
        .subject-code { display: inline-flex; align-items: center; min-height: 27px; padding: 4px 9px; border: 1px solid #fdba74; border-radius: 6px; background: #fff7ed; color: #9a3412; font-size: 12px; font-weight: 800; }
        .subject-description { color: #292524; font-size: 14px; font-weight: 500; line-height: 1.55; overflow-wrap: anywhere; }
        .subject-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-shrink: 0; }
        .btn-remove { flex-shrink: 0; min-height: 34px; padding: 7px 13px; border: 1px solid #fecaca; border-radius: 6px; background: #fff; color: #b91c1c; font-size: 11px; font-weight: 600; cursor: pointer; }
        .btn-remove:hover { background: #fecaca; }
        .subject-edit { grid-column: 1 / -1; }
        .subject-edit summary { display: inline-flex; align-items: center; justify-content: center; min-height: 34px; padding: 7px 13px; border: 1px solid #d6d3d1; border-radius: 6px; background: #fff; color: #292524; font-size: 12px; font-weight: 600; list-style: none; cursor: pointer; }
        .subject-edit summary:hover { background: #f5f5f4; border-color: #a8a29e; }
        .subject-edit summary::-webkit-details-marker { display: none; }
        .subject-edit[open] summary { display: none; }
        .subject-edit-form { display: grid; grid-template-columns: minmax(150px, .8fr) minmax(200px, 1.5fr) auto; gap: 12px; align-items: end; margin-top: 4px; padding: 16px; border: 1px solid var(--border); border-radius: 8px; background: #fafaf9; }
        .subject-edit-form .form-label { display: block; margin-bottom: 6px; color: #78716c; font-size: 11px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; }
        .subject-edit-form input { min-width: 0; }
        .subject-row.is-editing .subject-actions { grid-column: 1 / -1; display: grid; grid-template-columns: 1fr; }
        .subject-row.is-editing .subject-edit-form { grid-template-columns: minmax(150px, .8fr) minmax(200px, 1.5fr) auto auto; }
        .btn-cancel-edit { min-height: 39px; padding: 9px 14px; border: 1px solid var(--border); border-radius: 8px; background: #fff; color: var(--text); font: inherit; font-size: 12px; cursor: pointer; }
        .btn-cancel-edit:hover { background: #f3f4f6; }
        .empty { padding: 40px; color: var(--muted); text-align: center; }
        .empty svg { display: block; margin: 0 auto 10px; font-size: 26px; }
        @media (max-width: 920px) {
            .form-row { grid-template-columns: 1fr; }
            .form-row .btn { width: 100%; }
            .subject-edit-form { grid-template-columns: minmax(120px, .8fr) minmax(160px, 1.5fr) auto; }
        }
        @media (max-width: 700px) {
            .header { padding: 0 14px; }
            .brand { font-size: 18px; }
            .brand-logo { width: 26px; height: 26px; }
            .header-right { gap: 7px; }
            .dept-badge { display: none; }
            .sidebar { width: 230px; }
            .main { margin-left: 230px; padding: 22px 16px; }
            .subject-edit-form { grid-template-columns: 1fr; }
            .subject-edit-form .btn { width: 100%; }
            .subject-row.is-editing .subject-edit-form { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) {
            .layout { display: block; }
            .sidebar { position: relative; top: auto; bottom: auto; width: 100%; min-height: 0; max-height: 250px; overflow-y: auto; }
            .main { margin-left: 0; padding: 20px 14px; }
            .account-section { padding: 14px 16px; }
            .account-section > a { display: none !important; }
            .sidebar-section-label { padding: 12px 16px 6px; }
            .sidebar-link-nav { padding: 11px 14px; }
            .sidebar-folder-item { padding: 9px 16px; }
            .sidebar-folder-item, .sidebar-section-label:nth-of-type(3) { display: none; }
            .sidebar-logout { padding: 8px 16px; }
            .page-title { font-size: 20px; }
            .form-section { padding: 17px; }
            .subject-row { grid-template-columns: minmax(0, 1fr); gap: 14px; padding: 16px; }
            .subject-actions { justify-content: flex-start; }
            .subject-edit-form { grid-template-columns: 1fr; }
        }
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { border: 2px solid transparent; border-radius: 20px; background: #d6d3d1; background-clip: padding-box; }
        ::-webkit-scrollbar-thumb:hover { background: var(--red); }
        * { scrollbar-color: #d6d3d1 transparent; scrollbar-width: thin; }
    </style>
</head>
<body>
<div class="header">
    <a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><span class="brand-text">Coll<span class="accent">Trace</span></span></a>
    <div class="header-right">
        <span class="dept-badge"><?= sanitize($department['code']) ?></span>
        <span class="role-badge">CHAIRPERSON</span>
        <a href="../logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<div class="layout">
    <aside class="sidebar">
        <div class="account-section">
            <a href="<?= BASE_URL ?>/profile.php" style="display:block;width:fit-content;margin-bottom:12px;position:relative;" title="My Information">
                <?php if ($photo_url): ?>
                    <img src="<?= $photo_url ?>" alt="Profile" style="width:54px;height:54px;border-radius:50%;object-fit:cover;border:3px solid rgba(249,115,22,.6);display:block;">
                <?php else: ?>
                    <div class="account-avatar"><?= mb_strtoupper(mb_substr($_SESSION['full_name'], 0, 1)) ?></div>
                <?php endif; ?>
                <span style="position:absolute;bottom:0;right:0;width:18px;height:18px;background:#F97316;border-radius:50%;border:2px solid #231F1B;display:flex;align-items:center;justify-content:center;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:10px;height:10px;color:#fff;"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>
            </a>
            <div class="account-name"><?= sanitize($_SESSION['full_name']) ?></div>
            <div class="account-role">Department Chairperson</div>
            <div class="account-dept"><?= sanitize($department['name']) ?></div>
        </div>

        <div class="sidebar-section-label">Navigation</div>
        <div class="sidebar-bottom">
        <a href="dashboard.php" class="sidebar-link-nav"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14h3"/><path d="M7 10h3"/><path d="M13 18V8h3v10"/><path d="M17 13v5"/></svg></span> Dashboard</a>
        <a href="submission_status.php" class="sidebar-link-nav"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg></span> Submission Status</a>
        <a href="assign_faculty.php" class="sidebar-link-nav"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg></span> Assign Faculty</a>
        <a href="assign_subjects.php" class="sidebar-link-nav"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/><path d="M12 7v6"/><path d="M9 10h6"/></svg></span> Assign Subjects</a>
        <a href="subjects.php" class="sidebar-link-nav active-nav" aria-current="page"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg></span> Department Subjects</a>
        </div>

        <div class="sidebar-section-label">Department Folders</div>
        <?php if (!$sidebar_folders): ?>
            <div style="padding:12px 18px;font-size:12px;color:#5a6480;">No folders yet.</div>
        <?php else: ?>
            <?php foreach ($sidebar_folders as $folder):
                $submitted = intval($folder['submitted_count']);
                $pill_class = $submitted >= $faculty_count && $faculty_count > 0 ? 'pill-done' : ($submitted > 0 ? 'pill-partial' : 'pill-empty');
            ?>
            <a href="folder_view.php?id=<?= (int)$folder['id'] ?>" class="sidebar-folder-item">
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></span>
                <span><?= sanitize($folder['folder_name']) ?></span>
                <span class="folder-pill <?= $pill_class ?>"><?= $submitted ?>/<?= $faculty_count ?></span>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="sidebar-logout"><a href="<?= BASE_URL ?>/profile.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="7" r="4"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/></svg> My Information</a></div>
    </aside>

    <main class="main">
        <div class="breadcrumb"><a href="dashboard.php">Dashboard</a> &rsaquo; <?= sanitize($department['code']) ?> Subjects</div>
        <div class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg> <?= sanitize($department['code']) ?> Subjects</div>
        <p class="page-sub">Manage the approved subjects faculty can select when submitting files for <?= sanitize($department['name']) ?>.</p>

        <?php if ($message): ?><div class="msg success" role="status"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> <?= sanitize($message) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="msg error" role="alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg> <?= sanitize($error) ?></div><?php endif; ?>

        <section class="form-section" aria-labelledby="add-subject-title">
            <h2 class="section-title" id="add-subject-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg> Add Subject</h2>
            <form method="POST">
                <input type="hidden" name="action" value="save">
                <div class="form-row">
                    <div><label class="form-label" for="new-code">Subject Code</label><input type="text" id="new-code" name="subject_code" maxlength="100" placeholder="e.g. IT 101" required></div>
                    <div><label class="form-label" for="new-description">Subject Description</label><input type="text" id="new-description" name="subject_description" maxlength="255" placeholder="e.g. Fundamentals of Information Technology" required></div>
                    <button type="submit" class="btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg> Add Subject</button>
                </div>
            </form>
        </section>

        <h2 class="section-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h8"/></svg> <?= sanitize($department['code']) ?> Subjects</h2>
        <?php if (!$subjects): ?>
            <div class="assignments-list"><div class="empty"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>No department subjects yet. Add the subjects faculty should be able to select.</div></div>
        <?php else: ?>
            <div class="assignments-list">
                <div class="group-header"><span>Subject Catalog</span><span class="subject-count"><?= count($subjects) ?> SUBJECT<?= count($subjects) === 1 ? '' : 'S' ?></span></div>
                <?php foreach ($subjects as $subject): ?>
                <div class="subject-row">
                    <div class="subject-info">
                        <span class="subject-code"><?= sanitize($subject['subject_code']) ?></span>
                        <div class="subject-description"><?= sanitize($subject['subject_description']) ?></div>
                    </div>
                    <div class="subject-actions">
                        <details class="subject-edit" ontoggle="this.closest('.subject-row').classList.toggle('is-editing', this.open)">
                            <summary>Edit</summary>
                            <form method="POST" class="subject-edit-form">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="subject_id" value="<?= (int)$subject['id'] ?>">
                                <div><label class="form-label" for="code-<?= (int)$subject['id'] ?>">Subject Code</label><input id="code-<?= (int)$subject['id'] ?>" type="text" name="subject_code" value="<?= sanitize($subject['subject_code']) ?>" maxlength="100" required></div>
                                <div><label class="form-label" for="description-<?= (int)$subject['id'] ?>">Subject Description</label><input id="description-<?= (int)$subject['id'] ?>" type="text" name="subject_description" value="<?= sanitize($subject['subject_description']) ?>" maxlength="255" required></div>
                                <button type="submit" class="btn">Save</button>
                                <button type="button" class="btn-cancel-edit" onclick="this.closest('details').open = false">Cancel</button>
                            </form>
                        </details>
                        <form method="POST" onsubmit="return confirm('Remove this subject from the department catalog?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="subject_id" value="<?= (int)$subject['id'] ?>">
                            <button type="submit" class="btn-remove" aria-label="Delete <?= sanitize($subject['subject_code']) ?>">Delete</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
