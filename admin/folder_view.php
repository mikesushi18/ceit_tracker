<?php
// admin/folder_view.php
require_once '../includes/config.php';
require_once '../includes/school_year_helper.php';
require_once '../includes/file_feedback.php';
requireAdminDashboardAccess();
$active_sy = setupSchoolYears($conn);
fileFeedbackEnsureSchema($conn);
$feedbackCsrfToken = fileFeedbackCsrfToken();
$feedbackMessage = $_SESSION['feedback_message'] ?? '';
$feedbackError = $_SESSION['feedback_error'] ?? '';
unset($_SESSION['feedback_message'], $_SESSION['feedback_error']);

$folder_id = intval($_GET['id'] ?? 0);
if (!$folder_id) redirect(BASE_URL . '/admin/dashboard.php');

$folder = $conn->query("
    SELECT f.*, d.code AS dept_code, d.name AS dept_name, d.id AS dept_id, u.full_name AS created_by_name
    FROM folders f
    JOIN departments d ON d.id = f.department_id
    LEFT JOIN users u ON u.id = f.created_by
    LEFT JOIN folder_groups fg ON fg.id = f.group_id
    WHERE f.id = $folder_id AND f.school_year_id = " . (int) ($active_sy['id'] ?? 0) . "
      AND (f.group_id IS NULL OR fg.school_year_id = " . (int) ($active_sy['id'] ?? 0) . ")
")->fetch_assoc();
if (!$folder) redirect(BASE_URL . '/admin/dashboard.php');

$files = $conn->query("
    SELECT fi.*, COALESCE(u.full_name, 'Deleted User') AS faculty_name, u.username, u.role AS uploader_role
    FROM files fi
    LEFT JOIN users u ON u.id = fi.uploaded_by
    WHERE fi.folder_id = $folder_id
    ORDER BY fi.uploaded_at DESC
")->fetch_all(MYSQLI_ASSOC);
$feedbackByFile = fileFeedbackForFiles($conn, array_column($files, 'id'));

$all_faculty = $conn->query("
    SELECT u.id, u.full_name
    FROM users u
    WHERE u.department_id = {$folder['dept_id']} AND u.role IN ('faculty', 'chairperson') AND u.is_active = 1
")->fetch_all(MYSQLI_ASSOC);

$submitted_ids = array_column($files, 'uploaded_by');

function fileIcon($mime) {
    if (str_contains($mime, 'pdf')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h4"/></svg>';
    if (str_contains($mime, 'word') || str_contains($mime, 'document')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>';
    if (str_contains($mime, 'sheet') || str_contains($mime, 'excel')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10"/><path d="M7 12h10"/><path d="M7 16h6"/></svg>';
    if (str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 8h8"/><path d="M8 12h5"/><path d="M8 16h3"/></svg>';
    if (str_contains($mime, 'image')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m21 15-5-5-8 8"/></svg>';
    if (str_contains($mime, 'zip') || str_contains($mime, 'compressed')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h4"/></svg>';
    if (str_contains($mime, 'text')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>';
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
        :root {
            --navy: #1C1917; --navy2: #231F1B; --red: #F97316; --red2: #EA580C;
            --bg: #FFF7ED; --card: #fff; --text: #1e2235; --muted: #6b7280; --border: #e5e7eb;
        }
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
        .container { max-width: 1060px; margin: 0 auto; padding: 28px 20px; }
        .breadcrumb { font-size: 13px; color: var(--muted); margin-bottom: 6px; }
        .breadcrumb a { color: var(--red); text-decoration: none; }
        .page-title { font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 14px; }
        .page-sub { font-size: 13px; color: var(--muted); margin-top: 4px; margin-bottom: 26px; }

        /* TWO COL */
        .two-col { display: grid; grid-template-columns: 1fr 280px; gap: 20px; }

        /* CARD */
        .card { background: var(--card); border-radius: 14px; padding: 22px 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); margin-bottom: 20px; }
        .card-title { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }

        /* TABLE */
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { background: #f8f9fb; padding: 10px 14px; text-align: left; font-size: 11px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 16px 16px; border-top: 1px solid var(--border); vertical-align: middle; }
        tr:hover td { background: #fafbfc; }

        .file-icon { font-size: 22px; display: inline-flex; align-items: center; justify-content: center; }
        .file-icon svg, .page-title svg, .card-title svg, .btn-view svg, .btn-download svg, .modal-close svg, .modal-subheader svg, .no-preview svg {
            width: 1em; height: 1em; color: var(--red); flex-shrink: 0;
            display: inline-block; vertical-align: -0.12em; line-height: 1;
        }
        .file-name { font-weight: 600; color: var(--text); font-size: 14px; line-height: 1.45; overflow-wrap: anywhere; }

        .status-submitted { background: #edfff3; color: #1a6b35; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }
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
            width:36px;height:36px;justify-content:center;flex:0 0 36px;
            background:var(--navy);color:#fff;border:0;padding:0;border-radius:8px;cursor:pointer;
            text-decoration:none;display:inline-flex;align-items:center;
            transition: background 0.2s;
        }
        .btn-view:hover { background: #2d2d4e; }
        .btn-view svg { width:17px;height:17px;color:#fff; }
        .btn-download {
            width:36px;height:36px;justify-content:center;flex:0 0 36px;
            background:#fff;color:#57534e;border:1px solid var(--border);padding:0;border-radius:8px;cursor:pointer;
            text-decoration:none;display:inline-flex;align-items:center;
            transition: all 0.2s;
        }
        .btn-download:hover { border-color: var(--red); color: var(--red); }
        .btn-download svg { width:17px;height:17px;color:currentColor; }
        .btn-comment { width:36px;height:36px;justify-content:center;flex:0 0 36px;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;padding:0;border-radius:8px;cursor:pointer;display:inline-flex;align-items:center; }
        .btn-comment:hover { border-color:var(--red);background:#ffedd5; }
        .btn-comment svg { width:17px;height:17px;color:currentColor; }
        .btn-comment-edit span { display:none; }
        .btn-comment:focus-visible { outline:3px solid rgba(249,115,22,.3);outline-offset:2px; }
        .btn-view:focus-visible,.btn-download:focus-visible { outline:3px solid rgba(249,115,22,.3);outline-offset:2px; }
        .comment-status { color:#166534;font-size:10px;font-weight:700;white-space:nowrap; }
        .feedback-edit-toggle { justify-self:start;padding:5px 8px;border:1px solid #fed7aa;border-radius:6px;background:#fff7ed;color:#9a3412;font-size:11px;font-weight:700;cursor:pointer; }
        .feedback-edit-toggle:hover { background:#ffedd5; }
        .feedback-edit-form[hidden] { display:none; }
        .action-cell { display:flex;gap:8px;align-items:center;flex-wrap:nowrap;white-space:nowrap; }
        .action-cell > * { flex:0 0 auto; }
        td:last-child { min-width:132px; }

        /* FACULTY STATUS */
        .faculty-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-top: 1px solid var(--border); font-size: 14px; }
        .faculty-row:first-child { border-top: none; }
        .submitted-tag { color: #1a6b35; font-size: 12px; font-weight: 700; }
        .pending-tag   { color: #b8860b; font-size: 12px; font-weight: 700; }
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

        /* PDF / IMAGE inside modal */
        .modal-body iframe,
        .modal-body img {
            width: 100%; height: 100%;
            min-height: 560px;
            border: none; display: block;
        }
        .modal-body img { object-fit: contain; background: #1a1a2e; max-height: 75vh; }

        /* Non-previewable placeholder */
        .no-preview {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 60px 24px; color: #9ba3be; text-align: center;
            gap: 14px;
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
            position: absolute; inset: 0;
            background: #000;
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
    </style>
</head>
<body>

<div class="header">
    <div style="display:flex;align-items:center;gap:16px;">
        <a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><div class="brand-text">Coll<span>Trace</span></div></a>
        <a href="department.php?id=<?= $folder['dept_id'] ?>" class="btn-back">← <?= $folder['dept_code'] ?></a>
    </div>
    <div class="header-right">
        <span class="role-badge">ADMIN</span>
        <a href="../logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<div class="container">
    <?php if ($feedbackMessage): ?><div class="feedback-alert"><?= htmlspecialchars($feedbackMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($feedbackError): ?><div class="feedback-alert error"><?= htmlspecialchars($feedbackError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a> ›
        <a href="department.php?id=<?= $folder['dept_id'] ?>"><?= $folder['dept_code'] ?></a> ›
        <?= sanitize($folder['folder_name']) ?>
    </div>
    <div class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg> <?= sanitize($folder['folder_name']) ?></div>

    <div class="two-col">
        <!-- Files submitted -->
        <div class="card">
            <div class="card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg> Submitted Files (<?= count($files) ?>)</div>
            <?php if (empty($files)): ?>
                <div class="empty">No files submitted yet.</div>
            <?php else: ?>
            <table>
                <tr>
                    <th>File</th>
                    <th>Subject</th>
                    <th>Submitted By</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
                <?php foreach ($files as $file): ?>
                <tr id="file-<?= (int) $file['id'] ?>">
                    <td>
                        <div style="display:flex;align-items:center;gap:14px;">
                            <span class="file-icon"><?= fileIcon($file['file_type']) ?></span>
                            <div>
                                <div class="file-name"><?= sanitize($file['original_name']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if (!empty($file['subject_code'])): ?>
                            <?= sanitize($file['subject_code']) ?>
                        <?php else: ?>
                            <span style="color:var(--muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= sanitize($file['faculty_name']) ?>
                        <br><span style="font-size:12px;color:#888"><?= sanitize($file['username']) ?></span>
                    </td>
                    <td style="font-size:13px;color:var(--muted)"><?= date('M d, Y g:i A', strtotime($file['uploaded_at'])) ?></td>
                    <td>
                        <div class="action-cell">
                            <button type="button" class="btn-view" aria-label="View <?= sanitize($file['original_name']) ?>" title="View file"
                                onclick="openPreview(<?= $file['id'] ?>, '<?= addslashes(sanitize($file['original_name'])) ?>', '<?= addslashes($file['file_type']) ?>')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                            <a href="<?= BASE_URL ?>/includes/file_viewer.php?id=<?= $file['id'] ?>&dl=1" class="btn-download" aria-label="Download <?= sanitize($file['original_name']) ?>" title="Download file" download="<?= sanitize($file['original_name']) ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 8 5 5 5-5"/><path d="M5 21h14"/></svg>
                            </a>
                            <?php renderFileFeedbackButton((int) $file['id'], $feedbackByFile[(int) $file['id']] ?? [], (int) $_SESSION['user_id'], ($file['uploader_role'] ?? '') === 'faculty'); ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php foreach ($files as $file): ?>
                <?php renderFileFeedbackPanel($feedbackByFile[(int) $file['id']] ?? [], (int) $file['id'], $feedbackCsrfToken, ($file['uploader_role'] ?? '') === 'faculty'); ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Faculty submission status -->
        <div class="card">
            <div class="card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/></svg> Submission Status</div>
            <?php if (empty($all_faculty)): ?>
                <div class="empty" style="padding:16px;">No faculty in this department.</div>
            <?php else: ?>
            <?php foreach ($all_faculty as $f): ?>
            <div class="faculty-row">
                <span><?= sanitize($f['full_name']) ?></span>
                <?php if (in_array($f['id'], $submitted_ids)): ?>
                    <span class="submitted-tag">✓ Submitted</span>
                <?php else: ?>
                    <span class="pending-tag">Pending</span>
                <?php endif; ?>
            </div>
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
            <a id="modal-download-link" href="#" download><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 8 5 5 5-5"/><path d="M5 21h14"/></svg> Download file</a>
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

function openPreview(fileId, fileName, fileType) {
    const modal  = document.getElementById('previewModal');
    const body   = document.getElementById('modal-body');
    const loader = document.getElementById('modal-loader');
    const dlLink = document.getElementById('modal-download-link');

    document.getElementById('modal-filename').textContent = fileName;
    document.getElementById('modal-filetype').textContent  = fileType;

    const viewerUrl  = BASE_URL + '/includes/file_viewer.php?id=' + fileId;
    const dlUrl      = viewerUrl + '&dl=1';
    dlLink.href      = dlUrl;
    dlLink.download  = fileName;

    // Clear previous content
    body.innerHTML = '';
    const loaderEl = document.createElement('div');
    loaderEl.className = 'loading-overlay';
    loaderEl.id = 'modal-loader';
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
        // Not previewable in browser — show download prompt
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
            <div class="np-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></div>
            <h4>${escHtml(fileName)}</h4>
                <p>This file type (<strong>${ext}</strong>) cannot be previewed in the browser.</p>
            <a href="${escHtml(dlUrl)}" class="btn-dl-big" download="${escHtml(fileName)}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 8 5 5 5-5"/><path d="M5 21h14"/></svg> Download File</a>
        </div>`;
}

function closePreview() {
    document.getElementById('previewModal').classList.remove('open');
    document.body.style.overflow = '';
    // Clear iframe/img to stop loading
    setTimeout(() => {
        document.getElementById('modal-body').innerHTML = '';
    }, 280);
}

function closeOnBackdrop(e) {
    if (e.target === document.getElementById('previewModal')) closePreview();
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closePreview(); });
</script>
</body>
</html>
