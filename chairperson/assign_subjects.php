<?php
require_once '../includes/config.php';
requireRole('chairperson');

$department_id = (int) ($_SESSION['department_id'] ?? 0);
$user_id = (int) $_SESSION['user_id'];
if (!$department_id) redirect(BASE_URL . '/index.php');

$department = $conn->query("SELECT id, code, name FROM departments WHERE id = $department_id")->fetch_assoc();
if (!$department) redirect(BASE_URL . '/index.php');

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

$msg = $err = '';
$post_action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $post_action === 'save_chairperson_subjects') {
    $raw_subject_ids = $_POST['subject_ids'] ?? [];
    $subject_ids = is_array($raw_subject_ids)
        ? array_values(array_unique(array_filter(array_map('intval', $raw_subject_ids), static fn($id) => $id > 0)))
        : [];
    $valid_subject_count = 0;
    if ($subject_ids) {
        $valid_subject_count = (int) ($conn->query("SELECT COUNT(*) AS total FROM department_subjects WHERE department_id = $department_id AND id IN (" . implode(',', $subject_ids) . ')')->fetch_assoc()['total'] ?? 0);
    }

    if ($valid_subject_count !== count($subject_ids)) {
        $_SESSION['flash_err'] = 'Select valid subjects from your department.';
    } else {
        $selected_subjects = $subject_ids
            ? $conn->query("SELECT subject_code, subject_description FROM department_subjects WHERE department_id = $department_id AND id IN (" . implode(',', $subject_ids) . ') ORDER BY subject_code')->fetch_all(MYSQLI_ASSOC)
            : [];
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare('DELETE FROM subjects WHERE faculty_id = ? AND department_id = ?');
            $stmt->bind_param('ii', $user_id, $department_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('INSERT INTO subjects (department_id, faculty_id, subject_code, subject_description) VALUES (?, ?, ?, ?)');
            foreach ($selected_subjects as $subject) {
                $stmt->bind_param('iiss', $department_id, $user_id, $subject['subject_code'], $subject['subject_description']);
                $stmt->execute();
            }
            $stmt->close();
            $conn->commit();

            logActivity($conn, $user_id, 'ASSIGN_SUBJECTS', 'Updated chairperson submission subjects');
            $_SESSION['flash_msg'] = 'Your submission subjects have been saved.';
            $_SESSION['flash_err'] = '';
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            $_SESSION['flash_err'] = 'Unable to save your submission subjects. Please try again.';
        }
    }
    header('Location: assign_subjects.php#chairperson-subjects');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $post_action === 'remove_faculty_subject') {
    $faculty_id = (int) ($_POST['faculty_id'] ?? 0);
    $subject_code = trim($_POST['subject_code'] ?? '');
    $faculty = $conn->query("SELECT id, full_name FROM users WHERE id = $faculty_id AND department_id = $department_id AND role = 'faculty'")->fetch_assoc();
    if (!$faculty || $subject_code === '') {
        $_SESSION['flash_err'] = 'Faculty subject assignment not found.';
    } else {
        $stmt = $conn->prepare('DELETE FROM subjects WHERE faculty_id = ? AND department_id = ? AND subject_code = ?');
        $stmt->bind_param('iis', $faculty_id, $department_id, $subject_code);
        $stmt->execute();
        $removed = $stmt->affected_rows > 0;
        $stmt->close();
        if ($removed) {
            logActivity($conn, $user_id, 'REMOVE_FACULTY_SUBJECT', "Removed subject $subject_code from {$faculty['full_name']}");
            $_SESSION['flash_msg'] = "$subject_code removed from {$faculty['full_name']}.";
            $_SESSION['flash_err'] = '';
        } else {
            $_SESSION['flash_err'] = 'Faculty subject assignment not found.';
        }
    }
    header('Location: assign_subjects.php#assigned-subject-list');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $post_action === 'save_faculty_subjects') {
    $faculty_id = (int) ($_POST['faculty_id'] ?? 0);
    $raw_subject_ids = $_POST['subject_ids'] ?? [];
    $subject_ids = is_array($raw_subject_ids)
        ? array_values(array_unique(array_filter(array_map('intval', $raw_subject_ids), static fn($id) => $id > 0)))
        : [];
    $faculty = $conn->query("SELECT id, full_name FROM users WHERE id = $faculty_id AND department_id = $department_id AND role = 'faculty' AND is_active = 1")->fetch_assoc();
    $valid_subject_count = 0;
    if ($subject_ids) {
        $subject_id_list = implode(',', $subject_ids);
        $valid_subject_count = (int) ($conn->query("SELECT COUNT(*) AS total FROM department_subjects WHERE department_id = $department_id AND id IN ($subject_id_list)")->fetch_assoc()['total'] ?? 0);
    }

    if (!$faculty) {
        $err = 'Select an active faculty member from your department.';
    } elseif (!$subject_ids || $valid_subject_count !== count($subject_ids)) {
        $err = 'Select one or more valid department subjects.';
    } else {
        $selected_subjects = $conn->query("SELECT subject_code, subject_description FROM department_subjects WHERE department_id = $department_id AND id IN (" . implode(',', $subject_ids) . ') ORDER BY subject_code')->fetch_all(MYSQLI_ASSOC);
        try {
            $conn->begin_transaction();
            $stmt = $conn->prepare('DELETE FROM subjects WHERE faculty_id = ? AND department_id = ?');
            $stmt->bind_param('ii', $faculty_id, $department_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('INSERT INTO subjects (department_id, faculty_id, subject_code, subject_description) VALUES (?, ?, ?, ?)');
            foreach ($selected_subjects as $subject) {
                $stmt->bind_param('iiss', $department_id, $faculty_id, $subject['subject_code'], $subject['subject_description']);
                $stmt->execute();
            }
            $stmt->close();
            $conn->commit();

            logActivity($conn, $user_id, 'ASSIGN_SUBJECTS', 'Assigned ' . count($selected_subjects) . " subject(s) to {$faculty['full_name']}");
            $_SESSION['flash_msg'] = 'Subjects saved for ' . $faculty['full_name'] . '.';
            $_SESSION['flash_err'] = '';
            header('Location: assign_subjects.php');
            exit;
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            $err = 'Unable to save the faculty subjects. Please try again.';
        }
    }
}

if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (isset($_SESSION['flash_err'])) { $err = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }

$faculty_members = $conn->query("SELECT id, full_name, username FROM users WHERE department_id = $department_id AND role = 'faculty' AND is_active = 1 ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$department_subjects = $conn->query("SELECT id, subject_code, subject_description FROM department_subjects WHERE department_id = $department_id ORDER BY subject_code")->fetch_all(MYSQLI_ASSOC);
$chairperson_subject_codes = [];
$stmt = $conn->prepare('SELECT subject_code FROM subjects WHERE faculty_id = ? AND department_id = ?');
$stmt->bind_param('ii', $user_id, $department_id);
$stmt->execute();
$chairperson_subject_codes = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'subject_code');
$stmt->close();
$faculty_subject_assignments = $conn->query("SELECT s.faculty_id, s.subject_code, s.subject_description, u.full_name, u.username, u.is_active FROM subjects s JOIN users u ON u.id = s.faculty_id WHERE s.department_id = $department_id AND u.department_id = $department_id AND u.role = 'faculty' ORDER BY u.full_name, s.subject_code")->fetch_all(MYSQLI_ASSOC);
$faculty_subject_groups = [];
foreach ($faculty_subject_assignments as $assignment) {
    $faculty_id = (int) $assignment['faculty_id'];
    if (!isset($faculty_subject_groups[$faculty_id])) {
        $faculty_subject_groups[$faculty_id] = [
            'faculty_id' => $faculty_id,
            'full_name' => $assignment['full_name'],
            'username' => $assignment['username'],
            'is_active' => (int) $assignment['is_active'],
            'subjects' => [],
        ];
    }
    $faculty_subject_groups[$faculty_id]['subjects'][] = $assignment;
}
$selected_faculty_id = (int) ($_GET['faculty_id'] ?? ($_POST['faculty_id'] ?? 0));
$selected_faculty = null;
$selected_subject_codes = [];
if ($selected_faculty_id) {
    $selected_faculty = $conn->query("SELECT id, full_name, username FROM users WHERE id = $selected_faculty_id AND department_id = $department_id AND role = 'faculty' AND is_active = 1")->fetch_assoc();
    if ($selected_faculty) {
        $stmt = $conn->prepare('SELECT subject_code FROM subjects WHERE faculty_id = ? AND department_id = ?');
        $stmt->bind_param('ii', $selected_faculty_id, $department_id);
        $stmt->execute();
        $selected_subject_codes = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'subject_code');
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Subjects — CollTrace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --ink:#1c1917; --muted:#78716c; --line:#e5e7eb; --orange:#f97316; --orange-dark:#ea580c; --page:#fff7ed; }
        body { min-height:100vh; background:var(--page); color:var(--ink); font-family:'Inter','Segoe UI',sans-serif; }
        .header { position:fixed; inset:0 0 auto; z-index:10; height:62px; display:flex; align-items:center; justify-content:space-between; gap:16px; padding:0 28px; background:#1c1917; border-bottom:2px solid var(--orange); box-shadow:0 2px 16px rgba(0,0,0,.25); color:#fff; }
        .header-left,.header-right,.brand { display:flex; align-items:center; }
        .header-left,.header-right { gap:14px; }
        .brand { gap:10px; color:#fff; font-size:21px; font-weight:800; text-decoration:none; }
        .brand img { width:30px; height:30px; object-fit:contain; }
        .brand-accent { color:var(--orange); }
        .role-badge { padding:4px 12px; border-radius:20px; background:var(--orange); color:#fff; font-size:11px; font-weight:700; }
        .header-link { padding:7px 14px; border:1px solid rgba(255,255,255,.25); border-radius:7px; color:#fff; font-size:13px; text-decoration:none; }
        .header-link:hover { background:rgba(255,255,255,.1); }
        main { width:min(100% - 40px,980px); margin:0 auto; padding:94px 0 48px; }
        .breadcrumb { margin-bottom:7px; color:var(--muted); font-size:12px; }
        .breadcrumb a { color:var(--orange-dark); text-decoration:none; }
        h1 { font-size:23px; line-height:1.3; }
        .subtitle { margin-top:5px; color:var(--muted); font-size:13px; line-height:1.5; }
        .panel { margin-top:20px; overflow:hidden; border:1px solid var(--line); border-radius:10px; background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.06); }
        .panel-head { padding:17px 20px; border-bottom:1px solid var(--line); background:linear-gradient(90deg,#fff7ed,#fff 60%); }
        .panel-head h2 { font-size:15px; }
        .panel-head p { margin-top:4px; color:var(--muted); font-size:11px; }
        .panel-body { padding:20px; }
        .message { margin-bottom:16px; padding:11px 14px; border:1px solid; border-radius:8px; font-size:13px; }
        .message.success { border-color:#a3e6b3; background:#edfff3; color:#166534; }
        .message.error { border-color:#fecaca; background:#fff5f5; color:#b91c1c; }
        .field { margin-bottom:18px; }
        label { display:block; margin-bottom:6px; color:#44403c; font-size:12px; font-weight:700; }
        select { width:100%; height:42px; padding:0 12px; border:1.5px solid #d6d3d1; border-radius:7px; background:#fff; color:var(--ink); font:inherit; font-size:13px; }
        select:focus { border-color:var(--orange); outline:3px solid rgba(249,115,22,.13); }
        .subject-search { width:100%; height:40px; margin-bottom:10px; padding:0 12px; border:1.5px solid #d6d3d1; border-radius:7px; background:#fff; color:var(--ink); font:inherit; font-size:13px; }
        .subject-search:focus { border-color:var(--orange); outline:3px solid rgba(249,115,22,.13); }
        .subject-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
        .subject-option { display:flex; align-items:flex-start; gap:10px; min-width:0; padding:11px 12px; border:1px solid var(--line); border-radius:8px; background:#fff; cursor:pointer; }
        .subject-option[hidden] { display:none; }
        .subject-option:has(input:checked) { border-color:#fdba74; background:#fff7ed; }
        .subject-option input { flex:none; margin-top:2px; accent-color:var(--orange); }
        .subject-option strong { display:block; color:var(--orange-dark); font-size:12px; }
        .subject-option span { display:block; margin-top:3px; color:#57534e; font-size:11px; line-height:1.4; }
        .list-panel { margin-top:20px; overflow:hidden; border:1px solid var(--line); border-radius:10px; background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.06); }
        .list-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:13px 16px; border-bottom:1px solid var(--line); background:#fafaf9; }
        .list-head h2 { font-size:14px; }
        .list-count { color:var(--muted); font-size:11px; }
        .faculty-group + .faculty-group { border-top:1px solid #f1f0ee; }
        .faculty-summary { display:grid; grid-template-columns:minmax(0,1fr) auto auto; align-items:center; gap:8px; min-height:54px; padding:8px 12px 8px 16px; }
        .faculty-summary:hover { background:#fffaf5; }
        .faculty-toggle { display:flex; align-items:center; min-width:0; gap:10px; padding:0; border:0; background:transparent; color:var(--ink); text-align:left; cursor:pointer; }
        .faculty-name { overflow:hidden; font-size:12px; font-weight:700; text-overflow:ellipsis; white-space:nowrap; }
        .faculty-handle { margin-left:6px; color:var(--muted); font-size:10px; font-weight:400; }
        .subject-count { color:var(--muted); font-size:11px; white-space:nowrap; }
        .subject-count strong { color:#57534e; }
        .row-actions { display:flex; justify-content:flex-end; gap:6px; }
        .faculty-chevron { transition:transform .15s; }
        .faculty-chevron.open { transform:rotate(180deg); }
        .faculty-subject-list { padding:2px 12px 10px 16px; background:#fffdfa; }
        .faculty-subject-list[hidden] { display:none; }
        .faculty-subject-row { display:grid; grid-template-columns:minmax(0,1fr) auto; align-items:center; gap:10px; min-height:42px; padding:6px 0 6px 18px; border-top:1px solid #f5f3f1; }
        .subject-code { color:var(--orange-dark); font-size:11px; font-weight:800; }
        .subject-description { margin-left:7px; color:var(--muted); font-size:11px; }
        .icon-action { display:inline-grid; place-items:center; width:32px; height:32px; border:1px solid #fed7aa; border-radius:6px; background:#fff; color:#c2410c; cursor:pointer; text-decoration:none; transition:background .15s,border-color .15s; }
        .icon-action:hover { background:#fff7ed; border-color:#f97316; }
        .icon-action svg { width:15px; height:15px; }
        .icon-action.delete { border-color:#fecaca; color:#b91c1c; }
        .icon-action.delete:hover { background:#fff1f2; border-color:#ef4444; }
        .assignment-empty { padding:26px 18px; color:var(--muted); font-size:12px; text-align:center; }
        @media(max-width:520px) { .faculty-summary { grid-template-columns:minmax(0,1fr) auto auto; gap:5px; padding:7px 8px; } .faculty-handle { display:none; } .subject-count { font-size:10px; } .row-actions { gap:4px; } .icon-action { width:28px; height:28px; } .faculty-subject-list { padding-left:10px; } .faculty-subject-row { padding-left:12px; } .subject-description { display:block; margin:3px 0 0; } }
        .actions { display:flex; justify-content:flex-end; gap:9px; padding-top:17px; border-top:1px solid #eee; }
        .btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:0 16px; border:1px solid var(--orange); border-radius:7px; background:var(--orange); color:#fff; font:inherit; font-size:12px; font-weight:700; text-decoration:none; cursor:pointer; }
        .btn:hover { border-color:var(--orange-dark); background:var(--orange-dark); }
        .btn.secondary { border-color:#d6d3d1; background:#fff; color:#57534e; }
        .btn.secondary:hover { background:#f5f5f4; }
        .empty { padding:24px; border:1px dashed #d6d3d1; border-radius:8px; color:var(--muted); font-size:13px; text-align:center; }
        @media(max-width:650px) { .header { height:58px; padding:0 14px; } .header-left,.header-right { gap:7px; } .brand { gap:7px; font-size:18px; } .brand img { width:26px; height:26px; } .header-link { padding:6px 9px; font-size:12px; } .role-badge { display:none; } main { width:calc(100% - 28px); padding-top:82px; } .panel-body { padding:16px; } .subject-list { grid-template-columns:1fr; } .actions { flex-direction:column-reverse; } .actions .btn { width:100%; } }
    </style>
</head>
<body>
<header class="header">
    <div class="header-left"><a class="brand" href="dashboard.php"><img src="../ceit_logo.png" alt="CEIT Logo"><span>Coll<span class="brand-accent">Trace</span></span></a><a class="header-link" href="dashboard.php">← Dashboard</a></div>
    <div class="header-right"><span class="role-badge">CHAIRPERSON</span><a class="header-link" href="../logout.php">Logout</a></div>
</header>
<main>
    <div class="breadcrumb"><a href="dashboard.php">Dashboard</a> / Assign Subjects</div>
    <h1>Assign Subjects</h1>
    <p class="subtitle"><?= sanitize($department['code']) ?> · Assign department subjects to yourself and to faculty members. This is separate from folder access and upload scope.</p>
    <?php if ($msg): ?><div class="message success" role="status"><?= sanitize($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="message error" role="alert"><?= sanitize($err) ?></div><?php endif; ?>
    <section class="panel" id="chairperson-subjects">
        <div class="panel-head"><h2>Your submission subjects</h2><p>These subjects determine your required submissions and progress in department monitoring and admin reports.</p></div>
        <div class="panel-body">
            <?php if (!$department_subjects): ?>
                <div class="empty">No department subjects have been added yet. Add subjects in Department Subjects first.</div>
            <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="save_chairperson_subjects">
                <div class="field">
                    <label>Subjects assigned to you</label>
                    <div class="subject-list">
                        <?php foreach ($department_subjects as $subject): ?>
                        <label class="subject-option">
                            <input type="checkbox" name="subject_ids[]" value="<?= (int) $subject['id'] ?>" <?= in_array($subject['subject_code'], $chairperson_subject_codes, true) ? 'checked' : '' ?>>
                            <span><strong><?= sanitize($subject['subject_code']) ?></strong><span><?= sanitize($subject['subject_description']) ?></span></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="actions"><button type="submit" class="btn">Save My Subjects</button></div>
            </form>
            <?php endif; ?>
        </div>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>Faculty subject assignment</h2><p>Assigned subjects appear on the faculty member's My Subjects page.</p></div>
        <div class="panel-body">
            <?php if (!$department_subjects): ?>
                <div class="empty">No department subjects have been added yet. Add subjects in Department Subjects first.</div>
            <?php elseif (!$faculty_members): ?>
                <div class="empty">No active faculty members are registered in this department.</div>
            <?php else: ?>
            <form method="GET" class="field">
                <label for="faculty-select">Faculty member</label>
                <select id="faculty-select" name="faculty_id" onchange="this.form.submit()" required>
                    <option value="">-- Choose a faculty member --</option>
                    <?php foreach ($faculty_members as $faculty): ?>
                    <option value="<?= (int) $faculty['id'] ?>" <?= $selected_faculty_id === (int) $faculty['id'] ? 'selected' : '' ?>><?= sanitize($faculty['full_name']) ?> (@<?= sanitize($faculty['username']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php if ($selected_faculty): ?>
            <form method="POST" id="assignment-form">
                <input type="hidden" name="action" value="save_faculty_subjects">
                <input type="hidden" name="faculty_id" value="<?= (int) $selected_faculty['id'] ?>">
                <div class="field">
                    <label>Subjects assigned to <?= sanitize($selected_faculty['full_name']) ?></label>
                    <label for="subject-search">Search subjects</label>
                    <input type="search" id="subject-search" class="subject-search" placeholder="Search by code or description" oninput="filterDepartmentSubjects()" autocomplete="off">
                    <div id="subject-search-status" class="list-count" role="status" aria-live="polite">Showing all <?= count($department_subjects) ?> subjects</div>
                    <div class="subject-list">
                        <?php foreach ($department_subjects as $subject): ?>
                        <label class="subject-option" data-search="<?= sanitize(strtolower($subject['subject_code'] . ' ' . $subject['subject_description'])) ?>">
                            <input type="checkbox" name="subject_ids[]" value="<?= (int) $subject['id'] ?>" <?= in_array($subject['subject_code'], $selected_subject_codes, true) ? 'checked' : '' ?>>
                            <span><strong><?= sanitize($subject['subject_code']) ?></strong><span><?= sanitize($subject['subject_description']) ?></span></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="actions"><a class="btn secondary" href="dashboard.php">Cancel</a><button type="submit" class="btn">Save Subjects</button></div>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
    <section class="list-panel" id="assigned-subject-list" aria-labelledby="assigned-subject-title">
        <div class="list-head"><h2 id="assigned-subject-title">Faculty subject assignments</h2><span class="list-count"><?= count($faculty_subject_groups) ?> facult<?= count($faculty_subject_groups) === 1 ? 'y' : 'ies' ?></span></div>
        <?php if (!$faculty_subject_groups): ?>
        <div class="assignment-empty">No faculty subjects assigned yet.</div>
        <?php else: ?>
        <?php foreach ($faculty_subject_groups as $faculty_group): $faculty_id = $faculty_group['faculty_id']; $subjects_panel_id = 'faculty-subjects-' . $faculty_id; ?>
        <section class="faculty-group">
            <div class="faculty-summary">
                <button type="button" class="faculty-toggle" aria-expanded="false" aria-controls="<?= $subjects_panel_id ?>" onclick="toggleFacultySubjects('<?= $subjects_panel_id ?>', this)">
                    <span class="faculty-name"><?= sanitize($faculty_group['full_name']) ?><span class="faculty-handle">@<?= sanitize($faculty_group['username']) ?><?= !$faculty_group['is_active'] ? ' · Inactive' : '' ?></span></span>
                </button>
                <span class="subject-count"><strong><?= count($faculty_group['subjects']) ?></strong> subject<?= count($faculty_group['subjects']) === 1 ? '' : 's' ?> handled</span>
                <div class="row-actions">
                    <a class="icon-action" href="assign_subjects.php?faculty_id=<?= $faculty_id ?>#assignment-form" aria-label="Edit subjects for <?= sanitize($faculty_group['full_name']) ?>" title="Edit faculty subjects"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></a>
                    <button type="button" class="icon-action" aria-label="Show subjects for <?= sanitize($faculty_group['full_name']) ?>" title="Show or hide subjects" aria-expanded="false" aria-controls="<?= $subjects_panel_id ?>" onclick="toggleFacultySubjects('<?= $subjects_panel_id ?>', this)"><svg class="faculty-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
                </div>
            </div>
            <div class="faculty-subject-list" id="<?= $subjects_panel_id ?>" hidden>
                <?php foreach ($faculty_group['subjects'] as $assignment): ?>
                <div class="faculty-subject-row">
                    <div><span class="subject-code"><?= sanitize($assignment['subject_code']) ?></span><span class="subject-description"><?= sanitize($assignment['subject_description']) ?></span></div>
                    <form method="POST" onsubmit="return confirm('Remove this faculty subject assignment?')">
                        <input type="hidden" name="action" value="remove_faculty_subject">
                        <input type="hidden" name="faculty_id" value="<?= $faculty_id ?>">
                        <input type="hidden" name="subject_code" value="<?= sanitize($assignment['subject_code']) ?>">
                        <button type="submit" class="icon-action delete" aria-label="Remove <?= sanitize($assignment['subject_code']) ?> from <?= sanitize($faculty_group['full_name']) ?>" title="Remove subject"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/></svg></button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>
<script>
function toggleFacultySubjects(panelId, control) {
    const panel = document.getElementById(panelId);
    const expanded = control.getAttribute('aria-expanded') === 'true';
    panel.hidden = expanded;
    document.querySelectorAll('[aria-controls="' + panelId + '"]').forEach(button => {
        button.setAttribute('aria-expanded', String(!expanded));
        const chevron = button.querySelector('.faculty-chevron');
        if (chevron) chevron.classList.toggle('open', !expanded);
        if (button.classList.contains('faculty-toggle')) {
            button.setAttribute('aria-label', (!expanded ? 'Hide' : 'Show') + ' subjects for ' + button.textContent.trim());
        }
    });
}

function filterDepartmentSubjects() {
    const query = document.getElementById('subject-search').value.trim().toLocaleLowerCase();
    const options = Array.from(document.querySelectorAll('.subject-option'));
    let visibleCount = 0;
    options.forEach(option => {
        const matches = option.dataset.search.includes(query);
        option.hidden = !matches;
        if (matches) visibleCount++;
    });
    document.getElementById('subject-search-status').textContent = query
        ? `Showing ${visibleCount} of ${options.length} subjects`
        : `Showing all ${options.length} subjects`;
}

</script>
</body>
</html>
