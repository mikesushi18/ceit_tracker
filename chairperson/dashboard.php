<?php
// chairperson/dashboard.php
require_once '../includes/config.php';
require_once '../includes/school_year_helper.php';
require_once '../includes/folder_subject_progress.php';
requireRole('chairperson');

$dept_id = $_SESSION['department_id'];
if (!$dept_id) { session_destroy(); redirect(BASE_URL . '/index.php'); }

// Profile photo
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL");
$conn->query("CREATE TABLE IF NOT EXISTS folder_groups (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, semester VARCHAR(100) DEFAULT 'End of Semester', description TEXT, status ENUM('active','pending') DEFAULT 'active', school_year_id INT, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS group_id INT NULL");
$conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS subfolder_position INT DEFAULT 0");
$conn->query("ALTER TABLE folder_groups ADD COLUMN IF NOT EXISTS status ENUM('active','pending') DEFAULT 'active'");
$_photo = $conn->query("SELECT profile_photo FROM users WHERE id = {$_SESSION['user_id']}")->fetch_assoc()['profile_photo'] ?? '';
$_photo_url = $_photo && storageExists(__DIR__ . '/../uploads/avatars/' . $_photo)
    ? BASE_URL . '/uploads/avatars/' . $_photo . '?v=' . (storageModified(__DIR__ . '/../uploads/avatars/' . $_photo) ?: 0)
    : '';

$dept = $conn->query("SELECT * FROM departments WHERE id = $dept_id")->fetch_assoc();

// Setup SY system
$active_sy        = setupSchoolYears($conn);
$viewing_sy_id = (int) ($active_sy['id'] ?? 0);
$viewing_sy = $active_sy;

$folders = $conn->query("
    SELECT f.*,
        (SELECT COUNT(*) FROM files fi JOIN users fu ON fu.id = fi.uploaded_by AND fu.role IN ('faculty', 'chairperson') WHERE fi.folder_id = f.id) AS file_count,
        u.full_name AS created_by_name
    FROM folders f
    LEFT JOIN folder_groups fg ON fg.id = f.group_id
    LEFT JOIN users u ON u.id = f.created_by
        WHERE f.department_id = $dept_id AND f.school_year_id = $viewing_sy_id
            AND (f.group_id IS NULL OR (fg.status = 'active' AND fg.school_year_id = $viewing_sy_id))
    ORDER BY f.subfolder_position ASC, f.created_at ASC
")->fetch_all(MYSQLI_ASSOC);
$folder_subject_progress = getFolderSubjectProgress($conn, (int) $dept_id, array_column($folders, 'id'));

$faculty_count   = $conn->query("SELECT COUNT(*) as c FROM users WHERE department_id = $dept_id AND role IN ('faculty', 'chairperson') AND is_active = 1")->fetch_assoc()['c'];
$submitted_count = $conn->query("SELECT COUNT(DISTINCT fi.uploaded_by) as c FROM files fi JOIN users fu ON fu.id = fi.uploaded_by AND fu.role IN ('faculty', 'chairperson') JOIN folders fo ON fo.id = fi.folder_id LEFT JOIN folder_groups fg ON fg.id = fo.group_id WHERE fo.department_id = $dept_id AND fo.school_year_id = $viewing_sy_id AND (fo.group_id IS NULL OR (fg.status = 'active' AND fg.school_year_id = $viewing_sy_id))")->fetch_assoc()['c'];
$total_files     = $conn->query("SELECT COUNT(*) as c FROM files fi JOIN users fu ON fu.id = fi.uploaded_by AND fu.role IN ('faculty', 'chairperson') JOIN folders fo ON fo.id = fi.folder_id LEFT JOIN folder_groups fg ON fg.id = fo.group_id WHERE fo.department_id = $dept_id AND fo.school_year_id = $viewing_sy_id AND (fo.group_id IS NULL OR (fg.status = 'active' AND fg.school_year_id = $viewing_sy_id))")->fetch_assoc()['c'];

// (announcements fetched inline in HTML section below)

// Fetch faculty list with submission counts per folder for quick overview
$all_faculty = $conn->query("
    SELECT id, full_name, username FROM users
    WHERE department_id = $dept_id AND role IN ('faculty', 'chairperson') AND is_active = 1
    ORDER BY full_name ASC
")->fetch_all(MYSQLI_ASSOC);

$faculty_progress = [];
foreach ($all_faculty as $fac) {
    $submitted = $conn->query("
        SELECT COUNT(DISTINCT fi.folder_id) as c
        FROM files fi
        JOIN folders fo ON fo.id = fi.folder_id
        LEFT JOIN folder_groups fg ON fg.id = fo.group_id
        WHERE fi.uploaded_by = {$fac['id']}
          AND fo.department_id = $dept_id
          AND fo.school_year_id = $viewing_sy_id
          AND (fo.group_id IS NULL OR (fg.status = 'active' AND fg.school_year_id = $viewing_sy_id))
    ")->fetch_assoc()['c'];
    $faculty_progress[] = [
        'id'        => $fac['id'],
        'full_name' => $fac['full_name'],
        'username'  => $fac['username'],
        'submitted' => intval($submitted),
        'total'     => count($folders),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chairperson Dashboard — CollTrace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        svg { width: 1em; height: 1em; display: inline-block; vertical-align: -0.125em; }

        :root {
            --navy:      #1C1917;
            --navy2:     #231F1B;
            --red:       #F97316;
            --red2:      #EA580C;
            --bg:        #FFF7ED;
            --card:      #ffffff;
            --text:      #1e2235;
            --muted:     #6b7280;
            --border:    #e5e7eb;
            --sidebar-w: 270px;
            --header-h:  62px;
        }

        body { font-family: 'Inter', 'Segoe UI', sans-serif; background: var(--bg); min-height: 100vh; }

        .loading-screen {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
            backdrop-filter: blur(6px);
            transition: opacity 0.6s ease, visibility 0.6s ease;
        }
        .loading-screen.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        .loading-logo {
            width: 110px;
            height: 110px;
            object-fit: contain;
            animation: spin-logo 1s linear infinite;
            filter: drop-shadow(0 10px 24px rgba(0,0,0,0.25));
        }
        @keyframes spin-logo {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .page-transition {
            position: fixed;
            inset: 0;
            z-index: 10001;
            display: flex;
            align-items: center;
            justify-content: center;
            background-image: linear-gradient(135deg, rgba(249,115,22,0.82) 0%, rgba(28,25,23,0.88) 100%), url('../ceit_bg.jpg');
            background-size: cover;
            background-position: center;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .page-transition.active {
            opacity: 1;
            visibility: visible;
        }
        .page-transition-spinner {
            width: 56px;
            height: 56px;
            border: 5px solid rgba(255,255,255,0.28);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin-circle 0.7s linear infinite;
        }
        @keyframes spin-circle {
            to { transform: rotate(360deg); }
        }
        .dashboard-shell {
            opacity: 0;
            transform: translateY(8px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }
        body.page-loaded .dashboard-shell {
            opacity: 1;
            transform: translateY(0);
        }

        /* ── HEADER ── */
        .header {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            height: var(--header-h);
            background: linear-gradient(120deg, var(--navy) 0%, #0d0b0a 100%);
            border-bottom: 2px solid var(--red);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 28px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.35);
        }
        .brand { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -0.5px; }
        .brand-logo { width: 32px; height: 32px; object-fit: contain; flex-shrink: 0; }
        .brand-text { color: #fff; }
        .brand-text .accent { color: var(--red); }
        .header-right { display: flex; align-items: center; gap: 14px; }
        .dept-badge { background: rgba(255,255,255,0.13); color: #e8eaf0; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .role-badge { background: var(--red); color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 0.6px; }
        a.btn-logout { background: transparent; border: 1px solid rgba(255,255,255,0.35); color: #fff; padding: 7px 16px; border-radius: 8px; font-size: 13px; text-decoration: none; transition: background 0.2s; }
        a.btn-logout:hover { background: rgba(255,255,255,0.1); }

        /* ── LAYOUT ── */
        .layout { display: flex; padding-top: var(--header-h); min-height: 100vh; }

        /* ── SIDEBAR ── */
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

        /* MY ACCOUNT SECTION */
        .account-section {
            padding: 22px 18px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.07);
        }
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
        .account-stats { display: flex; gap: 8px; margin-top: 14px; }
        .acc-stat {
            flex: 1; background: rgba(255,255,255,0.06); border-radius: 8px;
            padding: 8px 10px; text-align: center;
        }
        .acc-stat .n { font-size: 18px; font-weight: 800; color: #fff; }
        .acc-stat .l { font-size: 10px; color: #7a84a0; margin-top: 2px; }

        /* SIDEBAR NAV LINKS */
        .sidebar-link-nav {
            display: flex; align-items: center; gap: 11px;
            padding: 11px 18px; color: #9ba3be;
            text-decoration: none; font-size: 13px; font-weight: 500;
            border-left: 3px solid transparent; transition: all 0.2s;
        }
        .sidebar-link-nav:hover { background: rgba(232,65,24,0.1); color: #fff; border-left-color: var(--red); }
        .sidebar-link-nav.active-nav { background: rgba(232,65,24,0.15); color: #fff; border-left-color: var(--red); }

        /* SIDEBAR FOLDERS */
        .sidebar-section-label {
            font-size: 10px; font-weight: 700; letter-spacing: 1.5px;
            color: #5a6480; text-transform: uppercase;
            padding: 18px 18px 8px;
        }
        .sidebar-folder-item {
            display: flex; align-items: center; gap: 11px;
            padding: 11px 18px;
            color: #9ba3be;
            text-decoration: none;
            font-size: 13px; font-weight: 500;
            border-left: 3px solid transparent;
            transition: all 0.2s;
        }
        .sidebar-folder-item:hover {
            background: rgba(232,65,24,0.1);
            color: #fff;
            border-left-color: var(--red);
        }
        .folder-pill {
            margin-left: auto;
            font-size: 10px; font-weight: 700;
            padding: 2px 7px; border-radius: 8px;
            flex-shrink: 0;
        }
        .pill-done    { background: rgba(74,222,128,0.18); color: #86efac; border:1px solid rgba(74,222,128,.36); }
        .pill-partial { background: rgba(245,158,11,0.2); color: #fbbf24; border:1px solid rgba(245,158,11,.42); }
        .pill-empty   { background: rgba(148,163,184,0.18); color: #d1d5db; border:1px solid rgba(148,163,184,.3); }

        /* SIDEBAR BOTTOM */
        .sidebar-logout {
            margin-top: auto;
            padding: 14px 18px;
            border-top: 1px solid rgba(255,255,255,0.07);
        }
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

        /* ── MAIN CONTENT ── */
        .main {
            margin-left: var(--sidebar-w);
            flex: 1;
            padding: 28px 30px;
            min-width: 0;
        }

        /* PAGE TITLE */
        .page-title { font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
        .page-sub { color: var(--muted); font-size: 13px; margin-bottom: 24px; }
        .welcome-msg { font-size: 24px; font-weight: 700; color: var(--text); letter-spacing: -0.3px; margin-bottom: 3px; }
        .welcome-msg strong { color: var(--red); font-weight: 800; }
        .welcome-sub { font-size: 13.5px; font-weight: 500; color: var(--muted); margin-bottom: 22px; }
        .page-title svg, .section-title svg, .ann-pdf-link svg, .ann-empty svg, .sidebar-link-nav svg, .sidebar-folder-item svg, .sidebar-logout svg, .account-avatar svg {
            width: 1em; height: 1em; color: var(--red); flex-shrink: 0;
            display: inline-block; vertical-align: -0.12em; line-height: 1;
        }
        .sidebar-link-nav svg { width: 16px; height: 16px; }
        /* Badge sits on an orange background, so its icon needs to be white to stay visible */
        .ann-badge svg {
            width: 1em; height: 1em; color: #fff; flex-shrink: 0;
            display: inline-block; vertical-align: -0.12em; line-height: 1;
        }

        /* STATS */
        .stats-row { display: flex; gap: 14px; margin-bottom: 26px; flex-wrap: wrap; }
        .stat-card { background: var(--card); border-radius: 12px; padding: 16px 20px; flex: 1; min-width: 130px; border-left: 4px solid var(--red); box-shadow: 0 2px 10px rgba(0,0,0,0.06); }
        .stat-card .num { font-size: 28px; font-weight: 800; color: var(--text); }
        .stat-card .lbl { font-size: 12px; color: var(--muted); margin-top: 3px; font-weight: 500; }

        /* VIEW-ONLY NOTICE */
        .view-only-note {
            background: #fff8e1; border: 1px solid #f0d060; color: #7a5c00;
            padding: 10px 16px; border-radius: 8px; font-size: 13px;
            margin-bottom: 22px; display: flex; align-items: center; gap: 8px;
        }

        /* SECTION TITLE */
        .section-title { font-size: 16px; font-weight: 700; color: var(--text); margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }

        /* 2-COLUMN GRID */
        .dash-grid { display: grid; grid-template-columns: 1fr 380px; gap: 22px; align-items: start; margin-bottom: 26px; }
        @media (max-width: 900px) { .dash-grid { grid-template-columns: 1fr; } }

        /* ── ANNOUNCEMENTS ── */
        .ann-list { display: flex; flex-direction: column; gap: 14px; margin-bottom: 30px; }
        .ann-card {
            background: var(--card); border-radius: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            padding: 0;
            border: 1.5px solid var(--border);
            overflow: hidden;
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .ann-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.1); transform: translateY(-1px); }
        .ann-card-header {
            background: linear-gradient(135deg, var(--navy), var(--navy2));
            padding: 14px 20px;
            display: flex; align-items: center; gap: 10px;
        }
        .ann-badge { background: var(--red); color: #fff; font-size: 10px; font-weight: 700; padding: 2px 9px; border-radius: 20px; letter-spacing: .5px; text-transform: uppercase; }
        .ann-date { font-size: 11px; color: #a8a29e; margin-left: auto; }
        .ann-card-body { padding: 16px 20px; }
        .ann-title { font-size: 15px; font-weight: 800; color: var(--text); margin-bottom: 8px; }
        .ann-body  { font-size: 14px; color: #4b5568; line-height: 1.65; white-space: pre-wrap; }
        .ann-attachments { margin-top: 12px; display: flex; flex-wrap: wrap; gap: 8px; }
        .ann-meta  { font-size: 12px; color: var(--muted); margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); display: flex; align-items: center; gap: 6px; }
        .ann-edit-history { margin-top:10px;color:var(--muted);font-size:12px; }
        .ann-edit-history summary { cursor:pointer;color:#57534e;font-weight:650; }
        .ann-edit-history-list { margin-top:8px; }
        .ann-edit-revision { padding-top:9px;margin-top:9px;border-top:1px solid var(--border); }
        .ann-edit-revision-meta { margin-bottom:5px;color:var(--muted);font-size:11px; }
        .ann-edit-revision-title { margin:4px 0;color:var(--text);font-weight:650; }
        .ann-edit-revision-body { white-space:pre-wrap;line-height:1.5;overflow-wrap:anywhere; }
        .ann-edit-revision a { display:block;margin-top:5px;color:#c2410c;overflow-wrap:anywhere; }
        .ann-meta .av { width: 22px; height: 22px; border-radius: 50%; background: var(--red); color: #fff; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .ann-empty { background: var(--card); border-radius: 14px; padding: 48px; text-align: center; color: var(--muted); box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 30px; border: 1.5px dashed var(--border); }
        .ann-empty .ei { font-size: 40px; margin-bottom: 12px; }
        .ann-image { max-width: 100%; border-radius: 8px; margin-top: 4px; display: block; max-height: 280px; object-fit: cover; border: 1.5px solid var(--border); cursor: zoom-in; }
        .ann-image:hover { filter: brightness(0.98); }
        .ann-pdf-link {
            display:inline-flex;align-items:center;gap:8px;margin-top:10px;padding:8px 16px;border-radius:8px;
            background:#fff1f1;border:1.5px solid #fecaca;color:#b91c1c;font-size:13px;font-weight:600;
            text-decoration:none;transition:background .2s;
        }
        .ann-pdf-link:hover { background:#ffe4e4; }
        .ann-print-link { display:inline-flex;align-items:center;justify-content:center;margin-top:10px;background:#fff;color:#c2410c;border:1px solid #fed7aa;padding:8px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s,border-color .15s; }
        .ann-print-link:hover { background:#fff7ed;border-color:#f97316; }
        .ann-print-link:focus-visible { outline:3px solid #fdba74;outline-offset:2px; }

        /* ── FOLDER CARDS ── */
        .folder-card { background: var(--card); border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.07); margin-bottom: 14px; overflow: hidden; transition: box-shadow 0.2s; }
        .folder-card:hover { box-shadow: 0 6px 22px rgba(0,0,0,0.1); }
        .folder-header { background: #f8f9fb; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border); }
        .folder-name { font-size: 15px; font-weight: 700; color: var(--text); }
        .folder-meta { font-size: 12px; color: var(--muted); margin-top: 2px; }
        .file-badge { background: var(--red); color: #fff; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; }
        .btn-monitor { background: var(--navy); color: #fff; border: none; padding: 8px 16px; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; transition: background 0.2s; }
        .btn-monitor:hover { background: #2d2d4e; }
        .faculty-grid { padding: 14px 20px; display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 8px; }
        .faculty-item { display: flex; align-items: center; justify-content: space-between; background: #fafafa; border-radius: 8px; padding: 9px 12px; font-size: 13px; }
        .submitted-tag { color: #1a6b35; font-weight: 700; font-size: 12px; }
        .pending-tag   { color: #b8860b; font-weight: 700; font-size: 12px; }
        .empty-folders { text-align: center; color: var(--muted); padding: 40px; background: var(--card); border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }

        /* ── IMAGE LIGHTBOX ── */
        .img-modal {
            position: fixed; inset: 0; z-index: 10000;
            background: rgba(10,12,24,0.82);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .img-modal.open { display: flex; }
        .img-modal-box {
            position: relative;
            width: min(1100px, 96vw);
            max-height: 92vh;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 24px 80px rgba(0,0,0,0.45);
        }
        .img-modal-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            background: rgba(28,25,23,0.65);
            color: #fff;
        }
        .img-modal-title {
            font-size: 12px;
            color: rgba(255,255,255,0.8);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .img-modal-close {
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.22);
            color: #fff;
            width: 34px;
            height: 34px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .img-modal-close:hover { background: rgba(255,255,255,0.22); }
        .img-modal-imgwrap {
            background: rgba(0,0,0,0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 14px;
        }
        .img-modal-img {
            width: 100%;
            height: auto;
            max-height: calc(92vh - 54px);
            object-fit: contain;
            display: block;
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
<div id="loadingScreen" class="loading-screen" aria-hidden="true">
    <img class="loading-logo" src="../ceit_logo.png" alt="Loading CEIT logo">
</div>
<div id="pageTransition" class="page-transition" aria-hidden="true">
    <div class="page-transition-spinner"></div>
</div>

<div class="dashboard-shell">
<!-- HEADER -->
<div class="header">
    <a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><span class="brand-text">Coll<span class="accent">Trace</span></span></a>
    <div class="header-right">
        <span class="dept-badge"><?= sanitize($dept['code']) ?></span>
        <span class="role-badge">CHAIRPERSON</span>
        <a href="../logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<div class="layout">

    <!-- ── SIDEBAR ── -->
    <aside class="sidebar">

        <!-- MY ACCOUNT -->
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

        <!-- NAV LINKS -->
        </div>
        <div class="sidebar-section-label">Navigation</div>
        <a href="dashboard.php" class="sidebar-link-nav active-nav">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14h3"/><path d="M7 10h3"/><path d="M13 18V8h3v10"/><path d="M17 13v5"/></svg></span> Dashboard
        </a>
        <a href="submission_status.php" class="sidebar-link-nav">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg></span> Submission Status
        </a>
        <a href="assign_faculty.php" class="sidebar-link-nav">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg></span> Assign Faculty
        </a>
        <a href="assign_subjects.php" class="sidebar-link-nav">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/><path d="M12 7v6"/><path d="M9 10h6"/></svg></span> Assign Subjects
        </a>
        <a href="subjects.php" class="sidebar-link-nav">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg></span> Department Subjects
        </a>
        <div style="display:none">
            <div class="account-stats">
                <div class="acc-stat">
                    <div class="n"><?= $faculty_count ?></div>
                    <div class="l">Faculty</div>
                </div>
                <div class="acc-stat">
                    <div class="n"><?= count($folders) ?></div>
                    <div class="l">Folders</div>
                </div>
                <div class="acc-stat">
                    <div class="n"><?= $submitted_count ?></div>
                    <div class="l">Submitted</div>
                </div>
            </div>
        </div>

        <!-- FOLDERS LIST -->
        <div class="sidebar-section-label">Department Folders</div>

        <?php if (empty($folders)): ?>
            <div style="padding: 12px 18px; font-size: 12px; color: #5a6480;">No folders yet.</div>
        <?php else: ?>
            <?php foreach ($folders as $folder):
                $progress = $folder_subject_progress[(int) $folder['id']] ?? ['assigned_faculty_count' => 0, 'complete_faculty_count' => 0, 'started_faculty_count' => 0];
                $complete_faculty_count = $progress['complete_faculty_count'];
                $assigned_faculty_count = $progress['assigned_faculty_count'];
                if ($complete_faculty_count >= $assigned_faculty_count && $assigned_faculty_count > 0) $pillClass = 'pill-done';
                elseif ($progress['started_faculty_count'] > 0) $pillClass = 'pill-partial';
                else $pillClass = 'pill-empty';
                $pillLabel = $complete_faculty_count . '/' . $assigned_faculty_count;
            ?>
            <a href="folder_view.php?id=<?= $folder['id'] ?>" class="sidebar-folder-item">
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></span>
                <span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($folder['folder_name']) ?></span>
                <span class="folder-pill <?= $pillClass ?>"><?= $pillLabel ?></span>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- LOGOUT -->
        <div class="sidebar-logout">
            <a href="<?= BASE_URL ?>/profile.php" class="sb-info-link" style="margin-bottom:6px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> My Information</a>
</div>

    </aside>

    <!-- ── MAIN CONTENT ── -->
    <main class="main">
        <div class="welcome-msg">Welcome, <strong><?= sanitize($_SESSION['full_name']) ?></strong>!</div>
        <div class="welcome-sub">Monitor department submissions and submit your own requirements.</div>
        <div class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h7l2 3h9v12H3z"/><path d="M3 10h18"/></svg> Department Monitor</div>


        <!-- ── ANNOUNCEMENTS ── -->
        <div class="section-title" style="margin-top:6px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H9l-4 3v-3H5z"/><path d="M8 9h8"/><path d="M8 13h5"/></svg> Announcements</div>
        <?php
            $conn->query("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS image_file VARCHAR(255) NULL");
            $conn->query("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS pdf_file VARCHAR(255) NULL");
            $conn->query("CREATE TABLE IF NOT EXISTS announcement_edits (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                announcement_id INT NOT NULL,
                edited_by INT NULL,
                edited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                previous_title VARCHAR(200) NOT NULL,
                previous_body TEXT NOT NULL,
                previous_image_file VARCHAR(255) NULL,
                previous_pdf_file VARCHAR(255) NULL,
                previous_pdf_original_name VARCHAR(255) NULL,
                INDEX idx_announcement_edits_announcement (announcement_id, edited_at),
                FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
                FOREIGN KEY (edited_by) REFERENCES users(id) ON DELETE SET NULL
            )");
            $ann_res = $conn->query("
                SELECT a.*, COALESCE(u.full_name, 'Admin') AS author, u.profile_photo AS author_photo
                FROM announcements a
                LEFT JOIN users u ON u.id = a.posted_by
                ORDER BY a.created_at DESC
                LIMIT 30
            ");
            $announcements = ($ann_res !== false) ? $ann_res->fetch_all(MYSQLI_ASSOC) : [];
            $ann_base = BASE_URL . '/uploads/announcements/';
            $history_res = $conn->query("
                SELECT ae.*, COALESCE(u.full_name, 'Admin') AS editor
                FROM announcement_edits ae
                LEFT JOIN users u ON u.id = ae.edited_by
                JOIN (SELECT id FROM announcements ORDER BY created_at DESC LIMIT 30) recent ON recent.id = ae.announcement_id
                ORDER BY ae.edited_at DESC
            ");
            $announcement_edit_history = [];
            if ($history_res) {
                foreach ($history_res->fetch_all(MYSQLI_ASSOC) as $revision) {
                    $announcement_edit_history[(int) $revision['announcement_id']][] = $revision;
                }
            }
        ?>
        <?php if (empty($announcements)): ?>
            <div class="ann-empty">
                <div class="ei"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2"/><path d="M4 8h16"/><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8"/><path d="M9 12h6"/></svg></div>
                <strong>No announcements yet.</strong><br>
                <span style="font-size:13px;">Check back later!</span>
            </div>
        <?php else: ?>
        <div class="ann-list">
            <?php foreach ($announcements as $a):
                $a['image_url'] = ($a['image_file'] ?? '') ? $ann_base . $a['image_file'] : null;
                $a['pdf_url']   = ($a['pdf_file']   ?? '') ? $ann_base . $a['pdf_file']   : null;
                $a['edit_history'] = $announcement_edit_history[(int) $a['id']] ?? [];
                $author_photo_path = dirname(__DIR__) . '/uploads/avatars/' . ($a['author_photo'] ?? '');
                $a['author_photo_url'] = !empty($a['author_photo']) && storageExists($author_photo_path)
                    ? BASE_URL . '/uploads/avatars/' . rawurlencode($a['author_photo']) . '?v=' . (storageModified($author_photo_path) ?: 0)
                    : null;
            ?>
            <div class="ann-card">
                <div class="ann-card-header">
                    <span class="ann-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H9l-4 3v-3H5z"/><path d="M8 9h8"/><path d="M8 13h5"/></svg> Admin</span>
                    <span class="ann-date"><?= date('M d, Y · g:i A', strtotime($a['created_at'])) ?></span>
                </div>
                <div class="ann-card-body">
                    <div class="ann-title"><?= sanitize($a['title']) ?></div>
                    <div class="ann-body"><?= sanitize($a['body']) ?></div>
                    <?php if (!empty($a['image_url'])): ?>
                    <img src="<?= $a['image_url'] ?>" class="ann-image" alt="Announcement image">
                    <?php endif; ?>
                    <?php if (!empty($a['pdf_url']) || !empty($a['image_url'])): ?>
                    <div class="ann-attachments">
                        <?php if (!empty($a['pdf_url'])): ?>
                        <a href="<?= $a['pdf_url'] ?>" download target="_blank" class="ann-pdf-link">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 13v6"/><path d="M9 16l3 3 3-3"/></svg>                             Download Attached PDF / Memo
                        </a>
                        <?php endif; ?>
                        <button type="button" class="ann-print-link" aria-label="Print announcement" title="Print announcement" onclick="printAnnouncement(this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg></button>
                    </div>
                    <?php endif; ?>
                    <div class="ann-meta">
                        <?php if (!empty($a['author_photo_url'])): ?>
                            <img class="av" src="<?= $a['author_photo_url'] ?>" alt="">
                        <?php else: ?>
                            <span class="av"><?= mb_strtoupper(mb_substr($a['author'], 0, 1)) ?></span>
                        <?php endif; ?>
                        Posted by <strong><?= sanitize($a['author']) ?></strong>
                    </div>
                    <?php if (!empty($a['edit_history'])): ?>
                    <details class="ann-edit-history">
                        <summary>Edited · View history</summary>
                        <div class="ann-edit-history-list">
                            <?php foreach ($a['edit_history'] as $revision): ?>
                            <article class="ann-edit-revision">
                                <div class="ann-edit-revision-meta">Edited <?= date('M d, Y · g:i A', strtotime($revision['edited_at'])) ?> by <?= sanitize($revision['editor']) ?></div>
                                <strong>Previous version</strong>
                                <div class="ann-edit-revision-title"><?= sanitize($revision['previous_title']) ?></div>
                                <div class="ann-edit-revision-body"><?= sanitize($revision['previous_body']) ?></div>
                                <?php if ($revision['previous_image_file']): ?>
                                <a href="<?= $ann_base . rawurlencode($revision['previous_image_file']) ?>" target="_blank" rel="noopener">Previous image: <?= sanitize(basename($revision['previous_image_file'])) ?></a>
                                <?php endif; ?>
                                <?php if ($revision['previous_pdf_file']): ?>
                                <a href="<?= $ann_base . rawurlencode($revision['previous_pdf_file']) ?>" target="_blank" rel="noopener">Previous file: <?= sanitize($revision['previous_pdf_original_name'] ?: basename($revision['previous_pdf_file'])) ?></a>
                                <?php endif; ?>
                            </article>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>



    </main>
</div>

<!-- Image lightbox modal -->
<div class="img-modal" id="imgModal" aria-hidden="true" onclick="closeImgModalOnBackdrop(event)">
    <div class="img-modal-box" role="dialog" aria-modal="true" aria-label="Image preview">
        <div class="img-modal-top">
            <div class="img-modal-title" id="imgModalTitle">Image preview</div>
            <button class="img-modal-close" type="button" onclick="closeImgModal()">✕</button>
        </div>
        <div class="img-modal-imgwrap">
            <img class="img-modal-img" id="imgModalImg" alt="Announcement image preview">
        </div>
    </div>
</div>

<script>
function openImgModal(src, title='Announcement image') {
    const modal = document.getElementById('imgModal');
    const img   = document.getElementById('imgModalImg');
    const ttl   = document.getElementById('imgModalTitle');
    img.src = src;
    ttl.textContent = title;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function printAnnouncement(button) {
    const card = button.closest('.ann-card');
    if (!card) return;
    const printedBy = <?= json_encode((string) ($_SESSION['full_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const printAssets = <?= json_encode(BASE_URL . '/admin/print-assets/', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const logoUrl = <?= json_encode(BASE_URL . '/ceit_logo.png', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const printWindow = window.open('', '_blank', 'width=900,height=700');
    if (!printWindow) return;
    const doc = printWindow.document;
    doc.open();
    doc.write('<!doctype html><html><head><meta charset="utf-8"><title>CollTrace - Announcement</title><style>@page{size:A4;margin:0}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#111;max-width:1000px;margin:0 auto;padding:14mm 16mm}.institution-header{display:grid;grid-template-columns:150px 1fr 150px;align-items:center;gap:12px;text-align:center}.institution-logo{width:140px;height:120px;object-fit:contain;justify-self:center}.university-copy{font-size:16px;line-height:1.25}.university-copy strong{display:block;font-size:26px;line-height:1.15}.campus{display:block;font-size:18px;font-weight:700}.website{font-style:italic}.college-name{margin:16px 0 12px;font-size:23px;line-height:1.2;text-align:center}.header-rule{height:3px;margin:0 0 24px;border:0;background:#111}.coltrace-brand{display:flex;align-items:center;gap:6px;margin:0 0 28px;font-size:12px;font-weight:700;opacity:.7}.coltrace-brand img{width:24px;height:24px;object-fit:contain;margin:0}h1{font-size:26px;margin:0 0 12px;padding-bottom:12px;border-bottom:1px solid #d6d3d1}.body{font-size:15px;line-height:1.65;white-space:pre-wrap;margin:20px 0}.attachment-name{margin-top:20px;font-size:12px;color:#444;overflow-wrap:anywhere}.print-footer{margin-top:30px;padding-top:8px;border-top:1px solid #a8a29e;color:#57534e;font-size:10px;text-align:left}img.attachment{max-width:100%;max-height:420px;object-fit:contain;margin:12px 0}a{color:#c2410c}@media(max-width:650px){.institution-header{grid-template-columns:72px 1fr 72px;gap:5px}.institution-logo{width:70px;height:78px}.university-copy{font-size:11px}.university-copy strong{font-size:16px}.campus{font-size:12px}.college-name{font-size:16px}}</style></head><body><header class="institution-header"><img class="institution-logo" alt="Cavite State University seal"><div class="university-copy">Republic of the Philippines<strong>CAVITE STATE UNIVERSITY</strong><span class="campus">Don Severino de las Alas Campus</span>Indang, Cavite<br>(046) 483-9250<br><span class="website">www.cvsu.edu.ph</span></div><img class="institution-logo" alt="Bagong Pilipinas"></header><h2 class="college-name">COLLEGE OF ENGINEERING AND INFORMATION TECHNOLOGY</h2><hr class="header-rule"><div class="coltrace-brand"><img alt="CollTrace logo"><span>CollTrace - Announcement</span></div><h1></h1><div class="body"></div><div class="attachments"></div><footer class="print-footer"></footer></body></html>');
    const logoStyle = doc.createElement('style');
    logoStyle.textContent = '.institution-header{grid-template-columns:220px minmax(0,1fr) 220px}.institution-logo{height:auto;object-fit:contain}.institution-logo:first-of-type{width:140px;height:120px}.institution-logo:last-of-type{width:220px;height:147px}@media(max-width:650px){.institution-header{grid-template-columns:120px minmax(0,1fr) 120px}.institution-logo:first-of-type{width:70px;height:78px}.institution-logo:last-of-type{width:120px;height:80px}}';
    doc.head.appendChild(logoStyle);
    doc.querySelectorAll('.institution-logo')[0].src = printAssets + 'cvsu-seal.jpeg';
    doc.querySelectorAll('.institution-logo')[1].src = printAssets + 'bagong-pilipinas.jpeg';
    doc.querySelector('.coltrace-brand img').src = logoUrl;
    doc.querySelector('h1').textContent = card.querySelector('.ann-title')?.textContent || 'Announcement';
    doc.querySelector('.body').innerHTML = card.querySelector('.ann-body')?.innerHTML || '';
    doc.querySelector('.print-footer').textContent = 'Printed by: ' + printedBy + ' · ' + new Date().toLocaleString();
    const image = card.querySelector('.ann-image');
    if (image) {
        const printedImage = doc.createElement('img');
        printedImage.className = 'attachment';
        printedImage.src = image.src;
        printedImage.alt = 'Announcement attachment';
        doc.querySelector('.attachments').appendChild(printedImage);
    }
    const pdf = card.querySelector('.ann-pdf-link');
    if (pdf) {
        const link = doc.createElement('a');
        link.className = 'attachment-name';
        link.href = pdf.href;
        link.textContent = pdf.dataset.fileName || pdf.textContent;
        doc.querySelector('.attachments').appendChild(link);
    }
    doc.close();
    printWindow.focus();
    setTimeout(() => printWindow.print(), 300);
}
function closeImgModal() {
    const modal = document.getElementById('imgModal');
    const img   = document.getElementById('imgModalImg');
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    setTimeout(() => { img.src = ''; }, 50);
}
function closeImgModalOnBackdrop(e) {
    if (e.target === document.getElementById('imgModal')) closeImgModal();
}
document.addEventListener('click', (e) => {
    const t = e.target;
    if (t && t.classList && t.classList.contains('ann-image')) {
        openImgModal(t.src, t.alt || 'Announcement image');
    }
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const modal = document.getElementById('imgModal');
        if (modal && modal.classList.contains('open')) closeImgModal();
    }
});
</script>
<script>
window.addEventListener('load', () => {
    const loader = document.getElementById('loadingScreen');
    if (loader) {
        setTimeout(() => {
            loader.classList.add('hidden');
            document.body.classList.add('page-loaded');
        }, 900);
    } else {
        document.body.classList.add('page-loaded');
    }
});
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
