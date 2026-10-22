<?php
// chairperson/folder_view.php
require_once '../includes/config.php';
require_once '../includes/school_year_helper.php';
require_once '../includes/folder_subject_progress.php';
require_once '../includes/file_feedback.php';
requireRole('chairperson');
fileFeedbackEnsureSchema($conn);
$feedbackCsrfToken = fileFeedbackCsrfToken();
$feedbackMessage = $_SESSION['feedback_message'] ?? '';
$feedbackError = $_SESSION['feedback_error'] ?? '';
unset($_SESSION['feedback_message'], $_SESSION['feedback_error']);

$dept_id   = $_SESSION['department_id'];
$folder_id = intval($_GET['id'] ?? 0);
$active_sy = setupSchoolYears($conn);

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL");
$conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS group_id INT NULL");
$conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS subfolder_position INT DEFAULT 0");
$conn->query("CREATE TABLE IF NOT EXISTS folder_groups (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, semester VARCHAR(100) DEFAULT 'End of Semester', description TEXT, status ENUM('active','pending') DEFAULT 'active', school_year_id INT, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$conn->query("ALTER TABLE folder_groups ADD COLUMN IF NOT EXISTS status ENUM('active','pending') DEFAULT 'active'");
$_photo = $conn->query("SELECT profile_photo FROM users WHERE id = {$_SESSION['user_id']}")->fetch_assoc()['profile_photo'] ?? '';
$_photo_url = $_photo && file_exists(__DIR__ . '/../uploads/avatars/' . $_photo)
    ? BASE_URL . '/uploads/avatars/' . $_photo . '?v=' . filemtime(__DIR__ . '/../uploads/avatars/' . $_photo)
    : '';

$dept = $conn->query("SELECT * FROM departments WHERE id = $dept_id")->fetch_assoc();
if (!$dept) { session_destroy(); redirect(BASE_URL . '/index.php'); }

$folder = $conn->query("
    SELECT f.*, d.code AS dept_code, d.name AS dept_name
        FROM folders f JOIN departments d ON d.id = f.department_id
        LEFT JOIN folder_groups fg ON fg.id = f.group_id
        WHERE f.id = $folder_id AND f.department_id = $dept_id
            AND f.school_year_id = " . (int) ($active_sy['id'] ?? 0) . "
            AND (f.group_id IS NULL OR (fg.status = 'active' AND fg.school_year_id = " . (int) ($active_sy['id'] ?? 0) . "))
")->fetch_assoc();

if (!$folder) redirect(BASE_URL . '/chairperson/dashboard.php');

$files = $conn->query("
    SELECT fi.*, COALESCE(u.full_name, 'Deleted User') AS faculty_name, u.username, u.role AS uploader_role
    FROM files fi LEFT JOIN users u ON u.id = fi.uploaded_by
    WHERE fi.folder_id = $folder_id
    ORDER BY fi.uploaded_at DESC
")->fetch_all(MYSQLI_ASSOC);
$faculty_submission_files = array_values(array_filter($files, static fn($file) => in_array(($file['uploader_role'] ?? ''), ['faculty', 'chairperson'], true)));
$feedbackByFile = fileFeedbackForFiles($conn, array_column($files, 'id'));

$all_faculty   = $conn->query("SELECT id, full_name, role FROM users WHERE department_id = $dept_id AND role IN ('faculty', 'chairperson') AND is_active = 1 ORDER BY role = 'chairperson', full_name")->fetch_all(MYSQLI_ASSOC);
$faculty_subjects = [];
foreach ($all_faculty as $faculty_member) {
    $faculty_subjects[(int) $faculty_member['id']] = [];
}
$assigned_subject_rows = $conn->query("SELECT faculty_id, subject_code FROM subjects WHERE department_id = $dept_id ORDER BY subject_code")->fetch_all(MYSQLI_ASSOC);
foreach ($assigned_subject_rows as $subject_row) {
    $faculty_subjects[(int) $subject_row['faculty_id']][] = $subject_row['subject_code'];
}
$submitted_subjects = [];
foreach ($files as $file) {
    if (!empty($file['subject_code'])) {
        $submitted_subjects[(int) $file['uploaded_by']][(string) $file['subject_code']] = true;
    }
}
$files_by_faculty = [];
foreach ($all_faculty as $faculty_member) {
    $files_by_faculty[(int) $faculty_member['id']] = [];
}
foreach ($files as $file) {
    $faculty_file_id = (int) $file['uploaded_by'];
    if (isset($files_by_faculty[$faculty_file_id])) {
        $files_by_faculty[$faculty_file_id][] = $file;
    }
}

function fileIcon($mime) {
    if (str_contains($mime, 'pdf')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h4"/></svg>';
    if (str_contains($mime, 'word') || str_contains($mime, 'document')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>';
    if (str_contains($mime, 'sheet') || str_contains($mime, 'excel')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3h16a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/></svg>';
    if (str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M8 18h8"/><path d="M8 7h5"/><path d="M8 11h8"/></svg>';
    if (str_contains($mime, 'image')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10.5" r="1.5"/><path d="m21 15-5-5-6 6"/></svg>';
    if (str_contains($mime, 'zip') || str_contains($mime, 'compressed')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h6l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z"/><path d="M8 10h4"/><path d="M8 14h4"/><path d="M8 18h4"/></svg>';
    if (str_contains($mime, 'text')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M13 2v7h7"/><path d="M10 13h4"/><path d="M10 17h4"/></svg>';
}

function isPreviewable($mime) {
    return str_contains($mime, 'pdf') || str_contains($mime, 'image/');
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
        svg { width: 1em; height: 1em; display: inline-block; vertical-align: -0.125em; }
        :root {
            --navy: #1C1917; --navy2: #231F1B; --red: #F97316; --red2: #EA580C;
            --bg: #FFF7ED; --card: #fff; --text: #1e2235; --muted: #6b7280; --border: #e5e7eb;
            --sidebar-w: 270px; --header-h: 62px;
        }
        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: var(--bg); min-height: 100vh; }

        /* HEADER */
        .header { position: fixed; top: 0; left: 0; right: 0; z-index: 100; background: linear-gradient(120deg, var(--navy) 0%, #0d0b0a 100%); border-bottom: 2px solid var(--red); color: #fff; padding: 0 28px; height: var(--header-h); display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 16px rgba(0,0,0,0.35); }
        .brand { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 800; } .brand-logo { width: 30px; height: 30px; object-fit: contain; flex-shrink: 0; } .brand-text { color: #fff; } .brand-text .accent { color: var(--red); }
        .header-right { display: flex; align-items: center; gap: 12px; font-size: 14px; }
        .role-badge { background: var(--red); color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        a.btn-back { background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.2); color: #fff; padding: 7px 16px; border-radius: 8px; font-size: 13px; text-decoration: none; transition: background 0.2s; }
        a.btn-back:hover { background: rgba(255,255,255,0.2); }

        /* LAYOUT */
        .layout { display: flex; padding-top: var(--header-h); min-height: 100vh; }

        /* SIDEBAR */
        .sidebar { width: var(--sidebar-w); min-height: calc(100vh - var(--header-h)); background: var(--navy2); position: fixed; top: var(--header-h); left: 0; bottom: 0; overflow-y: auto; display: flex; flex-direction: column; box-shadow: 2px 0 20px rgba(0,0,0,0.18); }
        .account-section { padding: 22px 18px 16px; border-bottom: 1px solid rgba(255,255,255,0.07); }
        .account-avatar { width: 54px; height: 54px; border-radius: 50%; background: linear-gradient(135deg, #F97316, #FB923C); color: #fff; font-size: 22px; font-weight: 800; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; box-shadow: 0 4px 12px rgba(249,115,22,0.35); }
        .account-name { font-size: 14px; font-weight: 700; color: #e8eaf0; margin-bottom: 3px; }
        .account-role { font-size: 11px; color: #7a84a0; margin-bottom: 2px; }
        .account-dept { font-size: 11px; color: #5a8aff; font-weight: 600; }
        .sidebar-section-label { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; color: #5a6480; text-transform: uppercase; padding: 18px 18px 8px; }
        .sidebar-link-nav { display: flex; align-items: center; gap: 11px; padding: 11px 18px; color: #9ba3be; text-decoration: none; font-size: 13px; font-weight: 500; border-left: 3px solid transparent; transition: all 0.2s; }
        .sidebar-link-nav:hover { background: rgba(232,65,24,0.1); color: #fff; border-left-color: var(--red); }
        .sidebar-link-nav.active-nav { background: rgba(232,65,24,0.15); color: #fff; border-left-color: var(--red); }
        .sidebar-folder-item { display: flex; align-items: center; gap: 11px; padding: 11px 18px; color: #9ba3be; text-decoration: none; font-size: 13px; font-weight: 500; border-left: 3px solid transparent; transition: all 0.2s; }
        .sidebar-folder-item:hover { background: rgba(232,65,24,0.1); color: #fff; border-left-color: var(--red); }
        .sidebar-folder-item.active, .sidebar-folder-item[aria-current="page"] { background: rgba(232,65,24,0.18); color: #fff; border-left-color: var(--red); }
        .folder-pill { margin-left: auto; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 8px; flex-shrink: 0; }
        .pill-done    { background: rgba(74,222,128,0.18); color: #86efac; border:1px solid rgba(74,222,128,.36); }
        .pill-partial { background: rgba(245,158,11,0.2); color: #fbbf24; border:1px solid rgba(245,158,11,.42); }
        .pill-empty   { background: rgba(148,163,184,0.18); color: #d1d5db; border:1px solid rgba(148,163,184,.3); }
        .sidebar-logout { margin-top: auto; padding: 14px 18px; border-top: 1px solid rgba(255,255,255,0.07); }
        .sidebar-logout a { display: flex; align-items: center; gap: 9px; color: #f87171; font-size: 13px; font-weight: 500; text-decoration: none; padding: 9px 12px; border-radius: 8px; transition: background 0.2s; }
        .sidebar-logout a:hover { background: rgba(248,113,113,0.12); }

        
        .sidebar-logout a.sb-info-link { color: #c4cadd; }
        .sidebar-logout a.sb-info-link:hover { background: rgba(249,115,22,0.12); color: #fff; }
        /* MAIN */
        .main { margin-left: var(--sidebar-w); flex: 1; padding: 28px 30px; }

        /* CONTAINER */
        .container { max-width: 1060px; margin: 0 auto; }
        .breadcrumb { font-size: 13px; color: var(--muted); margin-bottom: 6px; }
        .breadcrumb a { color: var(--red); text-decoration: none; }
        .page-title { font-size: 22px; font-weight: 800; color: var(--text); display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
        .page-sub { font-size: 13px; color: var(--muted); margin-top: 4px; margin-bottom: 22px; }

        .view-only-note { background: #fff8e1; border: 1px solid #f0d060; color: #7a5c00; padding: 10px 16px; border-radius: 8px; font-size: 13px; margin-bottom: 22px; display: flex; align-items: center; gap: 8px; }
        .submit-requirements-row { display:flex;justify-content:flex-end;margin-bottom:16px; }
        .btn-submit-requirements { display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 16px;border:1px solid var(--red);border-radius:8px;background:var(--red);color:#fff;font-size:13px;font-weight:700;line-height:1.2;text-decoration:none;white-space:nowrap;transition:background .2s,border-color .2s,box-shadow .2s; }
        .btn-submit-requirements:hover { border-color:var(--red2);background:var(--red2);box-shadow:0 3px 9px rgba(249,115,22,.22); }
        .btn-submit-requirements:focus-visible { outline:3px solid rgba(249,115,22,.3);outline-offset:2px; }
        .page-title svg, .view-only-note svg, .card-title svg, .sidebar-link-nav svg, .sidebar-folder-item svg, .sidebar-logout a svg, .file-icon svg, .btn-view svg, .btn-download svg, .submitted-tag svg, .pending-tag svg, .np-icon svg, .modal-close svg, .modal-subheader a svg {
            width: 1em; height: 1em; color: var(--red); flex-shrink: 0; display: inline-block; vertical-align: -0.12em; line-height: 1;
        }
        .sidebar-link-nav svg { width: 16px; height: 16px; }

        /* TWO COL */
        .two-col { display: grid; grid-template-columns: 1fr 260px; gap: 20px; }

        /* CARD */
        .card { background: var(--card); border-radius: 14px; padding: 22px 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); }
        .card-title { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 16px; }

        /* TABLE */
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { background: #f8f9fb; padding: 10px 14px; text-align: left; font-size: 11px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 16px 16px; border-top: 1px solid var(--border); vertical-align: middle; }
        tr:hover td { background: #fafbfc; }

        .file-icon { font-size: 22px; }
        .file-name { font-weight: 600; color: var(--text); font-size: 14px; line-height: 1.45; overflow-wrap: anywhere; }
        .feedback-alert { margin:16px 0;padding:12px 15px;border-radius:8px;background:#ecfdf5;color:#166534; }
        .feedback-alert.error { background:#fff1f2;color:#9f1239; }
        .file-feedback { padding:12px 14px;background:#fafaf9;border:1px solid var(--border);border-radius:8px; }
        .file-feedback-thread { padding:8px 0;border-bottom:1px solid var(--border); }
        .file-feedback-item { display:grid;gap:4px;padding:9px 0;border-bottom:1px solid var(--border); }
        .file-feedback-reply { display:grid;gap:4px;margin:8px 0 2px 22px;padding:10px 12px;border-left:3px solid #fdba74;border-radius:0 7px 7px 0;background:#fff7ed; }
        .file-feedback-item small,.file-feedback-empty { color:var(--muted);font-size:12px; }
        .file-feedback-edited { color:#c2410c!important;font-weight:700; }
        .file-feedback-type { color:#9a3412;font-size:11px;font-weight:700;text-transform:uppercase; }
        .file-feedback-status { font-weight:600; }
        .file-feedback-form { display:grid;gap:8px;margin-top:10px; }
        .file-feedback-form textarea { width:100%;padding:11px;border:1px solid var(--border);border-radius:8px;font:inherit; }
        .file-feedback-form textarea { min-height:70px;resize:vertical; }
        .file-feedback-form button { justify-self:start;padding:8px 12px;border:0;border-radius:6px;background:var(--red);color:#fff;font-weight:700;cursor:pointer; }
        tr:target td { background:#fff7ed;box-shadow:inset 0 1px #fed7aa,inset 0 -1px #fed7aa; }
        tr:target td:first-child { box-shadow:inset 3px 0 #f97316,inset 0 1px #fed7aa,inset 0 -1px #fed7aa; }
        .feedback-modal[hidden] { display:none; }
        .feedback-modal { position:fixed;inset:0;z-index:1100;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(12,10,9,.62);opacity:0;transition:opacity .2s; }
        .feedback-modal.open { opacity:1; }
        .feedback-modal-box { width:min(600px,100%);max-height:min(720px,90vh);overflow:auto;border:1px solid var(--border);border-radius:12px;background:#fff;box-shadow:0 20px 60px rgba(0,0,0,.22);color:#292524;font-family:Inter,'Segoe UI',Arial,sans-serif;font-size:14px;line-height:1.5;transform:translateY(8px);transition:transform .2s; }
        .feedback-modal.open .feedback-modal-box { transform:translateY(0); }
        .feedback-modal-header { display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:20px 22px 16px;border-bottom:1px solid var(--border); }
        .feedback-modal-header h2 { margin:0;color:#1c1917;font-size:18px;font-weight:700;line-height:1.35; }
        .feedback-modal-header p { margin:5px 0 0;color:#78716c;font-size:13px;font-weight:400;line-height:1.5; }
        .feedback-modal-close { display:grid;width:34px;height:34px;flex:0 0 34px;place-items:center;border:1px solid #e7e5e4;border-radius:7px;background:#fafaf9;color:#57534e;font-size:20px;line-height:1;cursor:pointer; }
        .feedback-modal-close:hover { background:#f5f5f4;border-color:#d6d3d1; }
        .feedback-modal-content { padding:18px 22px 22px;font-size:14px;line-height:1.5; }
        .feedback-modal-content .file-feedback-empty { margin:0 0 14px;color:#78716c;font-size:13px;line-height:1.5; }
        .feedback-modal-content .file-feedback-thread { font-size:14px;line-height:1.5; }
        .feedback-modal-content .file-feedback-type { font-size:11px;line-height:1.4; }
        .feedback-modal-content .file-feedback-item small,.feedback-modal-content .file-feedback-reply small { font-size:12px;line-height:1.45; }
        .feedback-modal-content .file-feedback-form { gap:10px;margin-top:14px; }
        .feedback-modal-content .file-feedback-form textarea { display:block;width:100%;min-height:96px;padding:11px 12px;border:1px solid #d6d3d1;border-radius:8px;background:#fff;color:#292524;font-family:inherit;font-size:14px;line-height:1.5; }
        .feedback-modal-content .file-feedback-form textarea::placeholder { color:#78716c;font-size:14px;opacity:1; }
        .feedback-modal-content .file-feedback-form textarea:focus { border-color:#f97316;outline:0;box-shadow:0 0 0 3px rgba(249,115,22,.12); }
        .feedback-modal-content .file-feedback-form button { min-height:38px;padding:0 14px;border-radius:7px;font-family:inherit;font-size:13px;font-weight:650;line-height:1.4; }
        @media(max-width:600px) { .feedback-modal { padding:12px; }.feedback-modal-header { padding:17px 16px 14px; }.feedback-modal-content { padding:16px; } }

        /* ACTION BUTTONS */
        .btn-view {
            width: 36px; height: 36px; justify-content: center;
            background: var(--navy); color: #fff; border: none;
            padding: 0; border-radius: 8px; cursor: pointer;
            display: inline-flex; align-items: center; flex: 0 0 36px;
            transition: background 0.2s;
        }
        .btn-view:hover { background: #2d2d4e; }
        .btn-view svg { width: 17px; height: 17px; color: #fff; }
        .btn-download {
            width: 36px; height: 36px; justify-content: center; flex: 0 0 36px;
            background: #fff; color: #57534e; border: 1px solid var(--border);
            padding: 0; border-radius: 8px; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center;
            transition: all 0.2s;
        }
        .btn-download:hover { border-color: var(--red); color: var(--red); }
        .btn-download svg { width: 17px; height: 17px; color: currentColor; }
        .btn-comment { width:36px;height:36px;justify-content:center;flex:0 0 36px;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;padding:0;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center; }
        .btn-comment:hover { border-color:var(--red);background:#ffedd5; }
        .btn-comment svg { width:17px;height:17px;color:currentColor; }
        .btn-comment-edit span { display:none; }
        .btn-comment-edit { width:auto;flex:0 0 auto;gap:6px;padding:0 9px;font-size:11px;font-weight:700;white-space:nowrap; }
        .comment-status { color:#166534;font-size:10px;font-weight:700;white-space:nowrap; }
        .feedback-edit-toggle { justify-self:start;padding:5px 8px;border:1px solid #fed7aa;border-radius:6px;background:#fff7ed;color:#9a3412;font-size:11px;font-weight:700;cursor:pointer; }
        .feedback-edit-toggle:hover { background:#ffedd5; }
        .feedback-edit-form[hidden] { display:none; }
        .btn-view:focus-visible,.btn-download:focus-visible,.btn-comment:focus-visible { outline:3px solid rgba(249,115,22,.3);outline-offset:2px; }
        .action-cell { display:flex;gap:9px;align-items:center;flex-wrap:nowrap;white-space:nowrap; }
        .action-cell > * { flex:0 0 auto; }
        td:last-child { min-width:92px; }

        /* Faculty groups inside the submitted files table */
        .faculty-table-heading td { padding:12px 14px;background:#f8f9fb;border-top:1px solid var(--border); }
        .faculty-table-heading:first-of-type td { border-top:0; }
        .faculty-table-heading-content { display:flex;align-items:flex-start;justify-content:space-between;gap:14px; }
        .faculty-table-identity { display:flex;flex-wrap:wrap;align-items:center;gap:7px 10px;min-width:0; }
        .faculty-table-name { color:#292524;font-size:13px;font-weight:750; }
        .faculty-subject-count { flex:none;color:#78716c;font-size:11px;font-weight:600; }
        .faculty-table-subjects { display:flex;flex-wrap:wrap;gap:5px;width:100%;margin-top:2px; }
        .faculty-subject-toggle { display:inline-flex;align-items:center;justify-content:center;min-height:30px;flex:none;padding:0 10px;border:1px solid #d6d3d1;border-radius:6px;background:#fff;color:#44403c;font:inherit;font-size:11px;font-weight:700;cursor:pointer;transition:background .15s,border-color .15s; }
        .faculty-subject-toggle:hover { border-color:#a8a29e;background:#fafaf9; }
        .faculty-file-group[hidden] { display:none; }
        .faculty-subject-chip { display:inline-flex;align-items:center;gap:4px;padding:4px 7px;border:1px solid #fde68a;border-radius:5px;background:#fffbeb;color:#92400e;font-size:10px;font-weight:700;white-space:nowrap; }
        .faculty-subject-chip.submitted { border-color:#bbf7d0;background:#f0fdf4;color:#166534; }
        .faculty-subject-chip svg { width:12px;height:12px; }
        .subject-code-tag { display:inline-flex;align-items:center;padding:4px 8px;border:1px solid #fed7aa;border-radius:5px;background:#fff7ed;color:#c2410c;font-size:11px;font-weight:700;white-space:nowrap; }
        @media(max-width:760px){.faculty-table-heading-content{gap:8px}.faculty-table-subjects{gap:4px}.faculty-table-heading td{padding:10px}.faculty-subject-count{font-size:10px}}
        .empty { text-align: center; color: var(--muted); padding: 32px; font-size: 14px; }

        /* ── FILE PREVIEW MODAL ── */
        .modal-overlay {
            position: fixed; inset: 0; z-index: 1000;
            background: rgba(10,12,24,0.75);
            display: flex; align-items: center; justify-content: center;
            padding: 20px;
            opacity: 0; pointer-events: none;
            transition: opacity 0.25s;
        }
        .modal-overlay.open { opacity: 1; pointer-events: all; }

        .modal-box {
            background: var(--card); border-radius: 16px;
            width: 100%; max-width: 880px; max-height: 90vh;
            display: flex; flex-direction: column;
            box-shadow: 0 24px 80px rgba(0,0,0,0.35);
            transform: scale(0.95); transition: transform 0.25s;
            overflow: hidden;
        }
        .modal-overlay.open .modal-box { transform: scale(1); }

        .modal-header {
            background: var(--navy); color: #fff;
            padding: 16px 22px;
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        .modal-header h3 { font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 10px; max-width: calc(100% - 80px); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .modal-close {
            background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.2);
            color: #fff; width: 32px; height: 32px; border-radius: 8px;
            font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center;
            transition: background 0.2s; flex-shrink: 0;
        }
        .modal-close:hover { background: rgba(255,255,255,0.22); }

        .modal-subheader {
            background: #f8f9fb; padding: 10px 22px;
            border-bottom: 1px solid var(--border);
            font-size: 12px; color: var(--muted);
            display: flex; align-items: center; gap: 16px; flex-shrink: 0;
        }
        .modal-subheader a { color: var(--red); text-decoration: none; font-weight: 600; }
        .modal-subheader a:hover { text-decoration: underline; }

        .modal-body { flex: 1; overflow: auto; background: #1a1a2e; position: relative; min-height: 300px; }

        .modal-body iframe,
        .modal-body img {
            width: 100%; height: 100%;
            min-height: 560px;
            border: none; display: block;
        }
        .modal-body img { object-fit: contain; background: #1a1a2e; max-height: 75vh; }

        .no-preview {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 60px 24px; color: #9ba3be; text-align: center; gap: 14px;
        }
        .no-preview .np-icon { font-size: 56px; }
        .no-preview h4 { font-size: 17px; font-weight: 700; color: #e2e6f0; }
        .no-preview p { font-size: 14px; line-height: 1.6; }
        .btn-dl-big {
            background: var(--red); color: #fff; border: none;
            padding: 12px 28px; border-radius: 10px;
            font-size: 14px; font-weight: 700; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
            transition: background 0.2s;
        }
        .btn-dl-big:hover { background: var(--red2); }

        .loading-overlay {
            position: absolute; inset: 0; background: #000;
            display: flex; align-items: center; justify-content: center;
            color: #9ba3be; font-size: 14px; gap: 10px;
        }
        .spinner { width: 22px; height: 22px; border: 3px solid rgba(255,255,255,0.15); border-top-color: var(--red); border-radius: 50%; animation: spin 0.7s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ============ Custom Scrollbar (CollTrace) ============ */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d6d3d1; border-radius: 20px; border: 2px solid transparent; background-clip: padding-box; }
        ::-webkit-scrollbar-thumb:hover { background: #F97316; }
        * { scrollbar-width: thin; scrollbar-color: #d6d3d1 transparent; }

        /* Dark-surface variant for the file preview modal */
        .modal-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); }
        .modal-body::-webkit-scrollbar-thumb:hover { background: var(--red); }
        .modal-body { scrollbar-color: rgba(255,255,255,0.25) transparent; }
    
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
        <span class="dept-badge" style="background: rgba(255,255,255,0.13); color: #e8eaf0; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;"><?= sanitize($dept['code']) ?></span>
        <span class="role-badge">CHAIRPERSON</span>
        <a href="../logout.php" class="btn-back">Logout</a>
    </div>
</div>

<div class="layout">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="account-section">
            <a href="<?= BASE_URL ?>/profile.php" style="display:block;width:fit-content;margin-bottom:12px;position:relative;" title="My Information">
                <?php if ($_photo_url): ?>
                    <img src="<?= $_photo_url ?>" alt="Profile" style="width:54px;height:54px;border-radius:50%;object-fit:cover;border:3px solid rgba(249,115,22,.6);display:block;">
                <?php else: ?>
                    <div class="account-avatar"><?= mb_strtoupper(mb_substr($_SESSION['full_name'],0,1)) ?></div>
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
        <a href="assign_faculty.php" class="sidebar-link-nav"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg></span> Assign Faculty</a>
        <a href="assign_subjects.php" class="sidebar-link-nav"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/><path d="M12 7v6"/><path d="M9 10h6"/></svg></span> Assign Subjects</a>
        <a href="subjects.php" class="sidebar-link-nav"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg></span> Department Subjects</a>

        <div class="sidebar-section-label">Department Folders</div>
        <?php
            $folders_sidebar = $conn->query("
                SELECT f.id, f.folder_name
                FROM folders f
                LEFT JOIN folder_groups fg ON fg.id = f.group_id
                WHERE f.department_id = $dept_id AND f.school_year_id = {$folder['school_year_id']}
                    AND (f.group_id IS NULL OR fg.status = 'active')
                ORDER BY f.subfolder_position ASC, f.created_at ASC
            ")->fetch_all(MYSQLI_ASSOC);
            $folder_subject_progress = getFolderSubjectProgress($conn, (int) $dept_id, array_column($folders_sidebar, 'id'));
        ?>
        <?php if (empty($folders_sidebar)): ?>
            <div style="padding: 12px 18px; font-size: 12px; color: #5a6480;">No folders yet.</div>
        <?php else: ?>
            <?php foreach ($folders_sidebar as $fo):
                $progress = $folder_subject_progress[(int) $fo['id']] ?? ['assigned_faculty_count' => 0, 'complete_faculty_count' => 0, 'started_faculty_count' => 0];
                $complete_faculty_count = $progress['complete_faculty_count'];
                $assigned_faculty_count = $progress['assigned_faculty_count'];
                if ($complete_faculty_count >= $assigned_faculty_count && $assigned_faculty_count > 0) $pillClass = 'pill-done';
                elseif ($progress['started_faculty_count'] > 0) $pillClass = 'pill-partial';
                else $pillClass = 'pill-empty';
                $pillLabel = $complete_faculty_count . '/' . $assigned_faculty_count;
            ?>
            <a href="folder_view.php?id=<?= $fo['id'] ?>" class="sidebar-folder-item<?= (int) $fo['id'] === $folder_id ? ' active' : '' ?>"<?= (int) $fo['id'] === $folder_id ? ' aria-current="page"' : '' ?>>
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></span>
                <span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($fo['folder_name']) ?></span>
                <span class="folder-pill <?= $pillClass ?>"><?= $pillLabel ?></span>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="sidebar-logout">
            <a href="<?= BASE_URL ?>/profile.php" class="sb-info-link" style="margin-bottom:6px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> My Information</a>
</div>
    </aside>

    <!-- MAIN -->
    <main class="main">
        <div class="container">
    <?php if ($feedbackMessage): ?><div class="feedback-alert"><?= htmlspecialchars($feedbackMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($feedbackError): ?><div class="feedback-alert error"><?= htmlspecialchars($feedbackError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a> › <?= sanitize($folder['folder_name']) ?>
    </div>
    <div class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg> <?= sanitize($folder['folder_name']) ?></div>

    <div class="submit-requirements-row">
        <a href="../faculty/folder_submit.php?id=<?= $folder_id ?>" class="btn-submit-requirements">Submit my requirements</a>
    </div>

    <div class="view-only-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg> <strong>Review files and send faculty comments or checklist tasks.</strong></div>

    <div class="two-col">
        <!-- Files submitted -->
        <div class="card" style="grid-column:1 / -1;">
            <div class="card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg> Submitted Files (<?= count($faculty_submission_files) ?>)</div>
            <?php if (empty($faculty_submission_files)): ?>
                <div class="empty">No files submitted yet.</div>
            <?php else: ?>
            <table>
                <tr>
                    <th>File</th>
                    <th>Submitted By</th>
                    <th>Subject</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
                <?php foreach ($all_faculty as $faculty_member):
                    $faculty_id = (int) $faculty_member['id'];
                    $faculty_files = $files_by_faculty[$faculty_id] ?? [];
                    $assigned_codes = $faculty_subjects[$faculty_id] ?? [];
                    $faculty_submitted_codes = array_values(array_filter($assigned_codes, static fn($code) => !empty($submitted_subjects[$faculty_id][$code])));
                    $faculty_pending_codes = array_values(array_diff($assigned_codes, $faculty_submitted_codes));
                    $faculty_complete = count($assigned_codes) > 0 && !$faculty_pending_codes;
                    $faculty_file_group_id = 'faculty-files-' . $faculty_id;
                ?>
                <tbody class="faculty-table-heading">
                    <tr><td colspan="5">
                        <div class="faculty-table-heading-content">
                            <div class="faculty-table-identity">
                                <span class="faculty-table-name"><?= sanitize($faculty_member['full_name']) ?></span>
                                <span class="<?= $faculty_complete ? 'submitted-tag' : 'pending-tag' ?>"><?= $faculty_complete ? 'Complete' : 'Pending' ?></span>
                                <?php if (($faculty_member['role'] ?? '') === 'chairperson'): ?><span class="faculty-subject-count">Chairperson</span><?php endif; ?>
                                <span class="faculty-subject-count"><?= count($faculty_submitted_codes) ?>/<?= count($assigned_codes) ?> subjects</span>
                                <div class="faculty-table-subjects">
                                    <?php if (!$assigned_codes): ?><span class="faculty-subject-chip">No subjects assigned</span><?php endif; ?>
                                    <?php foreach ($assigned_codes as $subject_code): $subject_submitted = !empty($submitted_subjects[$faculty_id][$subject_code]); ?>
                                    <span class="faculty-subject-chip<?= $subject_submitted ? ' submitted' : '' ?>">
                                        <?php if ($subject_submitted): ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg><?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><?php endif; ?>
                                        <?= sanitize($subject_code) ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <button type="button" class="faculty-subject-toggle" aria-expanded="false" aria-controls="<?= $faculty_file_group_id ?>" onclick="toggleFacultyFiles(this)"><span>See all</span></button>
                        </div>
                    </td></tr>
                </tbody>
                <tbody class="faculty-file-group" id="<?= $faculty_file_group_id ?>" hidden>
                    <?php if (!$faculty_files): ?>
                    <tr><td colspan="5" class="empty" style="padding:16px;">No files submitted for this folder.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($faculty_files as $file): ?>
                    <tr id="file-<?= (int) $file['id'] ?>">
                        <td>
                            <div style="display:flex;align-items:center;gap:14px;">
                                <span class="file-icon"><?= fileIcon($file['file_type']) ?></span>
                                <div>
                                    <div class="file-name"><?= sanitize($file['original_name']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="font-size:13px;"><?= sanitize($faculty_member['full_name']) ?></td>
                        <td style="font-size:13px;"><?= !empty($file['subject_code']) ? '<span class="subject-code-tag">' . sanitize($file['subject_code']) . '</span>' : '—' ?></td>
                        <td style="font-size:13px;color:var(--muted)"><?= date('M d, Y g:i A', strtotime($file['uploaded_at'])) ?></td>
                        <td>
                            <div class="action-cell">
                                <button type="button" class="btn-view" aria-label="View <?= sanitize($file['original_name']) ?>" title="View file" onclick="openPreview(<?= $file['id'] ?>, '<?= addslashes(sanitize($file['original_name'])) ?>', '<?= addslashes($file['file_type']) ?>')">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>
                                <a href="<?= BASE_URL ?>/includes/file_viewer.php?id=<?= $file['id'] ?>&dl=1" class="btn-download" aria-label="Download <?= sanitize($file['original_name']) ?>" title="Download file" download="<?= sanitize($file['original_name']) ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 19h14"/></svg>
                                </a>
                                <?php renderFileFeedbackButton((int) $file['id'], $feedbackByFile[(int) $file['id']] ?? [], (int) $_SESSION['user_id'], ($file['uploader_role'] ?? '') === 'faculty'); ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php endforeach; ?>
            </table>
            <?php foreach ($faculty_submission_files as $file): ?>
                <?php renderFileFeedbackPanel($feedbackByFile[(int) $file['id']] ?? [], (int) $file['id'], $feedbackCsrfToken, ($file['uploader_role'] ?? '') === 'faculty'); ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php renderFileFeedbackScripts(); ?>

<!-- ── FILE PREVIEW MODAL ── -->
<div class="modal-overlay" id="previewModal" onclick="closeOnBackdrop(event)">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modal-filename">File Preview</h3>
            <button class="modal-close" onclick="closePreview()">✕</button>
        </div>
        <div class="modal-subheader">
            <span id="modal-filetype" style="font-weight:600;"></span>
            <a id="modal-download-link" href="#" download>⬇ Download file</a>
        </div>
        <div class="modal-body" id="modal-body">
            <div class="loading-overlay" id="modal-loader">
                <div class="spinner"></div> Loading file…
            </div>
        </div>
    </div>
</div>

<script>
const BASE_URL = '<?= BASE_URL ?>';

function toggleFacultyFiles(button) {
    const fileGroup = document.getElementById(button.getAttribute('aria-controls'));
    if (!fileGroup) return;
    const expanded = button.getAttribute('aria-expanded') === 'true';
    fileGroup.hidden = expanded;
    button.setAttribute('aria-expanded', String(!expanded));
    button.querySelector('span').textContent = expanded ? 'See all' : 'Show less';
}

window.addEventListener('load', () => {
    const target = document.getElementById(window.location.hash.slice(1));
    if (!target) return;
    const fileGroup = target.closest('.faculty-file-group');
    if (fileGroup && fileGroup.hidden) {
        fileGroup.hidden = false;
        document.querySelectorAll('.faculty-subject-toggle').forEach((button) => {
            if (button.getAttribute('aria-controls') === fileGroup.id) {
                button.setAttribute('aria-expanded', 'true');
                button.querySelector('span').textContent = 'Show less';
            }
        });
    }
    window.setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'center' }), 100);
});

function openPreview(fileId, fileName, fileType) {
    const modal = document.getElementById('previewModal');
    const body  = document.getElementById('modal-body');
    const dlLink = document.getElementById('modal-download-link');

    document.getElementById('modal-filename').textContent = fileName;
    document.getElementById('modal-filetype').textContent  = fileType;

    const viewerUrl = BASE_URL + '/includes/file_viewer.php?id=' + fileId;
    const dlUrl     = viewerUrl + '&dl=1';
    dlLink.href     = dlUrl;
    dlLink.download = fileName;

    body.innerHTML = '';
    const loaderEl = document.createElement('div');
    loaderEl.className = 'loading-overlay';
    loaderEl.innerHTML = '<div class="spinner"></div> Loading file…';
    body.appendChild(loaderEl);

    const isPDF   = fileType.includes('pdf');
    const isImage = fileType.includes('image/');

    if (isPDF) {
        const iframe = document.createElement('iframe');
        iframe.src = viewerUrl;
        iframe.onload = () => loaderEl.remove();
        body.appendChild(iframe);
    } else if (isImage) {
        const img = document.createElement('img');
        img.src = viewerUrl;
        img.alt = fileName;
        img.onload  = () => loaderEl.remove();
        img.onerror = () => showNoPreview(body, loaderEl, fileName, dlUrl);
        body.appendChild(img);
    } else {
        loaderEl.remove();
        showNoPreview(body, null, fileName, dlUrl);
    }

    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function showNoPreview(body, loader, fileName, dlUrl) {
    if (loader) loader.remove();
    const ext = fileName.split('.').pop().toUpperCase();
    body.innerHTML = `
        <div class="no-preview">
            <div class="np-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M13 2v7h7"/><path d="M10 13h4"/><path d="M10 17h4"/></svg></div>
            <h4>${escHtml(fileName)}</h4>
                <p>This file type (<strong>${ext}</strong>) cannot be previewed in the browser.</p>
            <a href="${escHtml(dlUrl)}" class="btn-dl-big" download="${escHtml(fileName)}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 19h14"/></svg> Download File</a>
        </div>`;
}

function closePreview() {
    document.getElementById('previewModal').classList.remove('open');
    document.body.style.overflow = '';
    setTimeout(() => { document.getElementById('modal-body').innerHTML = ''; }, 280);
}

function closeOnBackdrop(e) {
    if (e.target === document.getElementById('previewModal')) closePreview();
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closePreview(); });
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
