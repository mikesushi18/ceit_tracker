<?php
require_once '../includes/config.php';
require_once '../includes/google_drive_backup.php';
require_once '../includes/school_year_helper.php';
requireAdminDashboardAccess();

$active_sy = setupSchoolYears($conn);
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL");
$_photo = $conn->query("SELECT profile_photo FROM users WHERE id = " . (int) $_SESSION['user_id'])->fetch_assoc()['profile_photo'] ?? '';
$_photo_url = $_photo && file_exists(__DIR__ . '/../uploads/avatars/' . $_photo)
    ? BASE_URL . '/uploads/avatars/' . $_photo . '?v=' . filemtime(__DIR__ . '/../uploads/avatars/' . $_photo)
    : '';

$departments = $conn->query("SELECT * FROM departments ORDER BY code")->fetch_all(MYSQLI_ASSOC);
$department_metrics = [];
foreach ($departments as $department) {
    $department_metrics[(int) $department['id']] = [
        'required_folder_requirements' => 0,
        'completed_folder_requirements' => 0,
        'completion_percent' => 0,
    ];
}
$submission_rows = $conn->query("
    SELECT d.id AS department_id,
        COUNT(DISTINCT s.subject_code) AS required_subjects,
        COUNT(DISTINCT CASE WHEN fi.id IS NOT NULL THEN s.subject_code END) AS submitted_subjects
    FROM departments d
    JOIN folders f ON f.department_id = d.id
    JOIN users u ON u.department_id = d.id AND u.role = 'faculty' AND u.is_active = 1
    LEFT JOIN subjects s ON s.faculty_id = u.id AND s.department_id = d.id
    LEFT JOIN files fi ON fi.folder_id = f.id AND fi.uploaded_by = u.id AND fi.subject_code = s.subject_code
    WHERE f.school_year_id = " . (int) ($active_sy['id'] ?? 0) . "
    GROUP BY d.id, f.id, u.id
")->fetch_all(MYSQLI_ASSOC);
foreach ($submission_rows as $row) {
    $required = (int) $row['required_subjects'];
    if ($required === 0) {
        continue;
    }
    $department_id = (int) $row['department_id'];
    $department_metrics[$department_id]['required_folder_requirements']++;
    if (min((int) $row['submitted_subjects'], $required) >= $required) {
        $department_metrics[$department_id]['completed_folder_requirements']++;
    }
}
foreach ($department_metrics as &$metrics) {
    $metrics['completion_percent'] = $metrics['required_folder_requirements'] > 0
        ? (int) round($metrics['completed_folder_requirements'] / $metrics['required_folder_requirements'] * 100)
        : 0;
}
unset($metrics);

$message = (string) ($_SESSION['backup_flash_message'] ?? '');
$error = (string) ($_SESSION['backup_flash_error'] ?? '');
unset($_SESSION['backup_flash_message'], $_SESSION['backup_flash_error']);
$csrfToken = $_SESSION['backup_csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['backup_csrf_token'] = $csrfToken;

if (isset($_GET['oauth'])) {
    $oauthStatus = (string) $_GET['oauth'];
    if ($oauthStatus === 'connected') {
        $message = 'Google Drive account connected successfully.';
    } elseif ($oauthStatus === 'disconnected') {
        $message = 'Google Drive account disconnected.';
    } elseif ($oauthStatus === 'failed') {
        $error = $_SESSION['google_drive_oauth_error'] ?? 'Google account connection failed.';
        unset($_SESSION['google_drive_oauth_error']);
    } elseif ($oauthStatus === 'cancelled') {
        $error = 'Google account connection was cancelled.';
    } elseif ($oauthStatus === 'invalid_state') {
        $error = 'Google account connection could not be verified. Please try again.';
    } elseif ($oauthStatus === 'disconnect_failed') {
        $error = 'Unable to remove the saved Google Drive account connection.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['manual_drive_backup', 'manual_local_backup'], true)) {
    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'The request could not be verified. Refresh the page and try again.';
    } else {
        try {
            $destination = $_POST['action'] === 'manual_drive_backup' ? 'drive' : 'local';
            googleDriveQueueBackup((int) $_SESSION['user_id'], 'Manual', null, $destination);
            $message = ($destination === 'drive' ? 'Google Drive backup' : 'Local PDF archive') .
                ' queued. You can leave this page; progress will remain visible in the admin area.';
        } catch (Throwable $exception) {
            $error = 'Backup failed: ' . $exception->getMessage();
        }
    }
    $_SESSION['backup_flash_message'] = $message;
    $_SESSION['backup_flash_error'] = $error;
    header('Location: backup_storage.php', true, 303);
    exit;
}

$connectedAccount = googleDriveLoadToken();
$backups = googleDriveRecentBackups();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Storage — CollTrace Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --navy: #1C1917;
            --navy2: #231F1B;
            --red: #F97316;
            --red2: #EA580C;
            --bg: #FFF7ED;
            --card: #ffffff;
            --text: #1e2235;
            --muted: #6b7280;
            --border: #e5e7eb;
            --sidebar-w: 260px;
            --header-h: 62px;
        }
        body { min-height: 100vh; background: var(--bg); color: var(--text); font-family: 'Inter', 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
        .header {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            height: var(--header-h); padding: 0 28px;
            display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(120deg, var(--navy) 0%, #0d0b0a 100%);
            border-bottom: 2px solid var(--red); box-shadow: 0 2px 16px rgba(0,0,0,.35);
        }
        .brand { display:flex; align-items:center; gap:10px; color:#fff; font-size:22px; font-weight:800; letter-spacing:-.5px; }
        .brand-logo { width:32px; height:32px; object-fit:contain; flex-shrink:0; }
        .brand-text { display:inline-flex; align-items:center; }
        .brand span { color:var(--red); }
        .header-right { display:flex; align-items:center; gap:14px; }
        .header-right .name { color:#cdd3e0; font-size:14px; font-weight:500; }
        .profile-link { display:inline-flex; align-items:center; gap:8px; text-decoration:none; }
        .profile-photo, .profile-initial { width:32px; height:32px; border:2px solid rgba(249,115,22,.7); border-radius:50%; object-fit:cover; }
        .profile-initial { display:inline-flex; align-items:center; justify-content:center; background:linear-gradient(135deg,#f97316,#ea580c); color:#fff; font-size:13px; font-weight:800; }
        .role-badge { padding:4px 12px; border-radius:20px; background:var(--red); color:#fff; font-size:11px; font-weight:700; letter-spacing:.6px; }
        .btn-logout { padding:7px 16px; border:1px solid rgba(255,255,255,.35); border-radius:8px; color:#fff; font-size:13px; text-decoration:none; transition:background .2s; }
        .btn-logout:hover { background:rgba(255,255,255,.1); }
        .layout { display:flex; min-height:100vh; padding-top:var(--header-h); }
        .sidebar {
            position:fixed; top:var(--header-h); left:0; z-index:50;
            display:flex; flex-direction:column; width:var(--sidebar-w); max-height:calc(100vh - var(--header-h));
            overflow-y:auto; padding:0 0 24px; background:var(--navy2); box-shadow:2px 0 20px rgba(0,0,0,.18);
        }
        .sidebar-account { display:flex; align-items:center; gap:11px; margin:14px 14px 4px; padding:12px; border:1px solid rgba(255,255,255,.08); border-radius:9px; background:rgba(255,255,255,.035); text-decoration:none; }
        .sidebar-account-avatar { display:grid; width:42px; height:42px; flex:0 0 42px; place-items:center; overflow:hidden; border:2px solid rgba(249,115,22,.55); border-radius:50%; background:linear-gradient(135deg,#f97316,#ea580c); color:#fff; font-size:16px; font-weight:800; }
        .sidebar-account-avatar img { width:100%; height:100%; object-fit:cover; }
        .sidebar-account-info { min-width:0; }
        .sidebar-account-name { overflow:hidden; color:#f5f5f4; font-size:12px; font-weight:700; text-overflow:ellipsis; white-space:nowrap; }
        .sidebar-account-role { margin-top:3px; color:#9ca3af; font-size:10px; font-weight:600; letter-spacing:.35px; text-transform:uppercase; }
        .sidebar-section-label { padding:22px 20px 8px; color:#5a6480; font-size:10px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; }
        .dept-nav-item { display:flex; align-items:center; gap:12px; padding:13px 20px; border-left:3px solid transparent; color:#9ba3be; font-size:13px; font-weight:500; text-decoration:none; transition:all .2s; }
        .dept-nav-item:hover { border-left-color:var(--red); background:rgba(232,65,24,.12); color:#fff; }
        .dept-nav-item .dept-icon { display:flex; width:34px; height:34px; flex-shrink:0; align-items:center; justify-content:center; border-radius:8px; background:rgba(255,255,255,.06); color:#c9d0e4; font-size:14px; font-weight:800; }
        .dept-nav-item:hover .dept-icon { background:rgba(232,65,24,.25); color:#fff; }
        .dept-nav-item .dept-info { flex:1; min-width:0; }
        .dept-nav-item .dept-code { overflow:hidden; color:#e2e6f0; font-size:13px; font-weight:700; text-overflow:ellipsis; white-space:nowrap; }
        .dept-sub { display:flex; align-items:center; gap:7px; margin-top:4px; color:#a8b0c2; font-size:11px; }
        .dept-progress-track { height:4px; flex:1; overflow:hidden; border-radius:10px; background:rgba(255,255,255,.12); }
        .dept-progress-fill { height:100%; border-radius:inherit; background:var(--dept-progress-color,#f97316); }
        .dept-nav-item .dept-count { flex-shrink:0; padding:2px 8px; border-radius:10px; background:rgba(255,255,255,.08); color:#8d96b0; font-size:11px; font-weight:600; }
        .sidebar-divider { margin:10px 16px; border:0; border-top:1px solid rgba(255,255,255,.06); }
        .sidebar-bottom { padding:0 14px; }
        .sidebar-nav-link { display:flex; align-items:center; gap:10px; padding:11px 14px; border-radius:8px; color:#8a93ae; font-size:13px; font-weight:500; text-decoration:none; transition:all .2s; }
        .sidebar-nav-link:hover { background:rgba(255,255,255,.07); color:#fff; }
        .sidebar-nav-link .nav-icon { font-size:16px; }
        .main { flex:1; min-width:0; margin-left:var(--sidebar-w); padding:28px 30px; }
        .page-title { margin-bottom:8px; color:var(--text); font-size:25px; }
        .muted { color:var(--muted); font-size:13px; line-height:1.6; }
        .card { margin:18px 0; padding:22px; border:1px solid var(--border); border-radius:9px; background:var(--card); box-shadow:0 2px 8px rgba(0,0,0,.045); }
        .card h2 { margin-bottom:12px; color:var(--text); font-size:17px; }
        .notice { margin:16px 0; padding:12px 15px; border:1px solid #bbf7d0; border-radius:8px; background:#ecfdf5; color:#166534; }
        .notice.error { border-color:#fecdd3; background:#fff1f2; color:#9f1239; overflow-wrap:anywhere; }
        .actions, .backup-action-group { display:flex; flex-wrap:wrap; align-items:center; gap:10px; }
        .actions { margin-top:12px; }
        .backup-action-group { gap:12px; margin-top:16px; }
        .backup-action-form { margin:0; }
        .button { display:inline-flex; min-height:36px; align-items:center; justify-content:center; gap:8px; padding:7px 11px; border:1px solid transparent; border-radius:6px; font:inherit; font-size:12px; font-weight:650; line-height:1.2; text-decoration:none; white-space:nowrap; cursor:pointer; transition:background .15s,border-color .15s,color .15s,box-shadow .15s; }
        .button svg { width:16px; height:16px; flex:none; }
        .button-primary { background:var(--red2); color:#fff; box-shadow:0 1px 2px rgba(124,45,18,.12); }
        .button-primary:hover { background:#c2410c; }
        .button-secondary { border-color:#d6d3d1; background:#fff; color:#292524; }
        .button-secondary:hover { border-color:#a8a29e; background:#fafaf9; }
        .button-danger { border-color:#fecaca; background:#fff; color:#b91c1c; }
        .button-danger:hover { border-color:#fca5a5; background:#fef2f2; }
        .button-small { min-height:32px; padding:6px 10px; font-size:12px; }
        .button:focus-visible { outline:3px solid rgba(249,115,22,.35); outline-offset:2px; }
        .button:disabled { cursor:not-allowed; opacity:.55; box-shadow:none; }
        .code { display:block; overflow-wrap:anywhere; padding:10px; border-radius:6px; background:#f5f5f4; font-family:Consolas,monospace; font-size:12px; }
        table { width:100%; border-collapse:collapse; color:var(--text); font-size:13px; text-align:left; }
        .backups-table { min-width:880px; }
        .backups-table th { white-space:nowrap; }
        .backups-table th:first-child, .backups-table td:first-child { min-width:145px; white-space:nowrap; }
        .backups-table th:nth-child(2), .backups-table td:nth-child(2) { min-width:190px; white-space:nowrap; }
        .drive-folder-link { display:inline-flex; min-height:30px; align-items:center; gap:7px; padding:5px 9px; border:1px solid #d6d3d1; border-radius:5px; background:#fff; color:#44403c; font-size:12px; font-weight:600; text-decoration:none; white-space:nowrap; transition:background .15s,border-color .15s; }
        .drive-folder-link:hover { border-color:#a8a29e; background:#fafaf9; }
        .drive-folder-link svg { width:14px; height:14px; flex:none; }
        th, td { padding:11px 8px; border-bottom:1px solid var(--border); vertical-align:top; }
        .backup-row-hidden { display:none; }
        .backup-row-hidden.is-visible { display:table-row; }
        .backups-footer { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:14px; }
        .backups-count { color:var(--muted); font-size:12px; }
        .sidebar { scrollbar-color:rgba(255,255,255,.25) transparent; }
        .sidebar::-webkit-scrollbar-thumb { background:rgba(255,255,255,.18); }
        .sidebar::-webkit-scrollbar-thumb:hover { background:var(--red); }
        @media(max-width:760px) {
            :root { --sidebar-w:220px; }
            .header { padding:0 16px; }
            .header-right { gap:8px; }
            .header-right .name { display:none; }
            .main { padding:22px 18px; }
        }
        @media(max-width:560px) {
            :root { --sidebar-w:72px; }
            .sidebar-account { justify-content:center; margin:12px 8px; padding:8px 4px; }
            .sidebar-account-info, .sidebar-section-label, .sidebar-active-year, .sidebar-folder-cta, .dept-info, .dept-count { display:none; }
            .dept-nav-item { justify-content:center; padding:10px 8px; }
            .dept-nav-item .dept-icon { width:38px; height:38px; }
            .sidebar-bottom { padding:0 8px; }
            .sidebar-nav-link { justify-content:center; padding:12px 6px; font-size:0; }
            .sidebar-nav-link .nav-icon { font-size:16px; }
            .main { padding:20px 12px; }
            .card { padding:16px; }
        }
    </style>
</head>
<body>
<div class="header">
    <a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><div class="brand-text">Coll<span>Trace</span></div></a>
    <div class="header-right">
        <a class="profile-link" href="<?= BASE_URL ?>/profile.php" title="My Information">
            <?php if ($_photo_url): ?>
                <img class="profile-photo" src="<?= htmlspecialchars($_photo_url, ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php else: ?>
                <span class="profile-initial"><?= mb_strtoupper(mb_substr($_SESSION['full_name'] ?? 'A', 0, 1)) ?></span>
            <?php endif; ?>
            <span class="name"><?= sanitize($_SESSION['full_name'] ?? 'Administrator') ?></span>
        </a>
        <button type="button" id="coltrace-backup-status-trigger" class="backup-status-trigger" aria-label="Show backup status" title="Show backup status" style="display:none">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m8 11 4 4 4-4"/><path d="M5 18v2h14v-2"/></svg>
            <span class="backup-status-dot" aria-hidden="true"></span>
        </button>
        <span class="role-badge"><?= strtoupper(sanitize($_SESSION['role'] ?? 'admin')) ?></span>
        <a href="../logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<div class="layout">
    <aside class="sidebar">
        <a class="sidebar-account" href="<?= BASE_URL ?>/profile.php" title="My Account">
            <span class="sidebar-account-avatar">
                <?php if ($_photo_url): ?><img src="<?= htmlspecialchars($_photo_url, ENT_QUOTES, 'UTF-8') ?>" alt="">
                <?php else: ?><?= mb_strtoupper(mb_substr($_SESSION['full_name'] ?? 'A', 0, 1)) ?><?php endif; ?>
            </span>
            <span class="sidebar-account-info">
                <span class="sidebar-account-name"><?= sanitize($_SESSION['full_name'] ?? 'Administrator') ?></span>
                <span class="sidebar-account-role"><?= ($_SESSION['role'] ?? '') === 'sub-admin' ? 'Sub-admin' : 'Administrator' ?></span>
            </span>
        </a>

        <?php if ($active_sy): ?>
        <div class="sidebar-active-year" style="margin:16px 14px 4px">
            <a href="school_year.php" style="display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,rgba(232,65,24,.18),rgba(232,65,24,.08));border:1px solid rgba(232,65,24,.3);border-radius:10px;padding:10px 14px;text-decoration:none">
                <div>
                    <div style="font-size:10px;color:#8a93ae;text-transform:uppercase;letter-spacing:1px;font-weight:700">Active School Year</div>
                    <div style="font-size:16px;font-weight:800;color:#fff;margin-top:2px"><?= sanitize($active_sy['label']) ?></div>
                </div>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;color:#FB923C"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
            </a>
        </div>
        <?php endif; ?>

        <a class="sidebar-folder-cta" href="manage_folders.php" style="display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,rgba(249,115,22,.22),rgba(249,115,22,.1));border:1.5px solid rgba(249,115,22,.45);border-radius:10px;padding:10px 14px;margin:10px 14px 4px;text-decoration:none">
            <span style="display:flex;align-items:center;gap:10px">
                <span style="width:34px;height:34px;border-radius:8px;background:rgba(249,115,22,.16);display:flex;align-items:center;justify-content:center"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;color:#FB923C"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></span>
                <span><span style="display:block;font-size:10px;color:#FB923C;text-transform:uppercase;letter-spacing:1px;font-weight:700">Create Folder</span><span style="display:block;font-size:12px;font-weight:600;color:#fff;margin-top:2px">Apply to all departments</span></span>
            </span>
            <span style="width:24px;height:24px;border-radius:6px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;color:#fff"><path d="M12 5v14"/><path d="M5 10h14"/></svg></span>
        </a>

        <div class="sidebar-bottom" style="margin-top:4px">
            <a href="dashboard.php" class="sidebar-nav-link" title="Dashboard"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="5" rx="1"/><rect x="13" y="10" width="8" height="11" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/></svg></span> Dashboard</a>
        </div>

        <div class="sidebar-section-label">Departments</div>
        <?php foreach ($departments as $dept): ?>
        <?php $dept_metric = $department_metrics[(int) $dept['id']] ?? ['completion_percent' => 0]; ?>
        <a href="department.php?id=<?= (int) $dept['id'] ?>" class="dept-nav-item">
            <span class="dept-icon"><?= htmlspecialchars(mb_substr((string) $dept['code'], 0, 1), ENT_QUOTES, 'UTF-8') ?></span>
            <span class="dept-info">
                <span class="dept-code"><?= sanitize($dept['code']) ?></span>
                <span class="dept-sub">
                    <span><?= (int) $dept_metric['completion_percent'] ?>%</span>
                    <span class="dept-progress-track"><span class="dept-progress-fill" style="display:block;width:<?= (int) $dept_metric['completion_percent'] ?>%;--dept-progress-color:<?= $dept_metric['completion_percent'] >= 100 ? '#4ade80' : ($dept_metric['completion_percent'] > 0 ? '#fbbf24' : '#9ca3af') ?>"></span></span>
                    <span>completion</span>
                </span>
            </span>
            <span class="dept-count">›</span>
        </a>
        <?php endforeach; ?>

        <hr class="sidebar-divider">
        <nav class="sidebar-bottom" aria-label="Admin navigation">
            <a href="school_year.php" class="sidebar-nav-link" title="School Years"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg></span> School Years</a>
            <a href="backup_storage.php" class="sidebar-nav-link" title="Backup Storage"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M20 16.2A4.5 4.5 0 0 0 18 7.5a6 6 0 0 0-11.5 1.8A3.5 3.5 0 0 0 7 16h3"/><path d="m12 12 0 9"/><path d="m8 17 4 4 4-4"/></svg></span> Backup Storage</a>
            <a href="manage_folders.php" class="sidebar-nav-link" title="Manage Folders"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></span> Manage Folders</a>
            <a href="manage_users.php" class="sidebar-nav-link" title="Manage Users"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg></span> Manage Users</a>
            <a href="create_user.php" class="sidebar-nav-link" title="Create Account"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M12 5v14"/><path d="M5 10h14"/></svg></span> Create Account</a>
            <a href="activity_log.php" class="sidebar-nav-link" title="Activity Log"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10"/><path d="M7 12h5"/></svg></span> Activity Log</a>
            <a href="clearance_form.php" class="sidebar-nav-link" title="Clearance Form"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h6"/></svg></span> Clearance Form</a>
            <a href="clearance_submissions.php" class="sidebar-nav-link" title="Clearance Submission"><span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg></span> Clearance Submission</a>
        </nav>
    </aside>

    <main class="main">
        <h1 class="page-title">Backup Storage</h1>
        <div class="muted">Manage the connected Google Drive account and system backups.</div>
        <?php if ($message): ?><div class="notice success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <section class="card">
            <h2>Google Drive account</h2>
            <?php if ($connectedAccount): ?>
                <p>Connected account: <strong><?= htmlspecialchars($connectedAccount['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong></p>
                <div class="actions">
                    <a class="button button-secondary" href="google_drive_oauth.php?connect=1">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h12a4 4 0 0 1 4 4v1"/><path d="m17 9 3 3 3-3"/><path d="M20 17H8a4 4 0 0 1-4-4v-1"/><path d="m7 15-3-3-3 3"/></svg>
                        <span>Switch account</span>
                    </a>
                    <form method="POST" action="google_drive_oauth.php" onsubmit="return confirm('Disconnect the saved Google Drive account?')">
                        <input type="hidden" name="action" value="disconnect">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button class="button button-danger" type="submit">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7.1-7.1l-1.1 1.1"/><path d="M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7.1 7.1l1.1-1.1"/><path d="m9 15 6-6"/></svg>
                            <span>Disconnect</span>
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <a class="button button-primary" href="google_drive_oauth.php?connect=1">Sign in with Google</a>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Manual backup</h2>
            <p class="muted">Choose where to create the backup. These actions run separately.</p>
            <div class="backup-action-group">
                <form class="backup-action-form" method="POST" onsubmit="if (!confirm('Upload the system documents to Google Drive now?')) return false; const button = this.querySelector('button[type=submit]'); button.disabled = true; button.querySelector('span').textContent = 'Queuing…'; return true;">
                    <input type="hidden" name="action" value="manual_drive_backup">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button class="button button-primary" type="submit" aria-label="Back Up to Google Drive" title="Back up to Google Drive" <?= $connectedAccount ? '' : 'disabled title="Connect Google Drive first"' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 16.2A4.5 4.5 0 0 0 18 7.5a6 6 0 0 0-8.6-3.1"/><path d="M7 8.5A4.5 4.5 0 0 0 7 17h3"/><path d="M12 21V11"/><path d="m8 15 4-4 4 4"/></svg><span>Back Up to Drive</span></button>
                </form>
                <form class="backup-action-form" method="POST" onsubmit="if (!confirm('Create and download a local ZIP containing faculty and chairperson PDF submissions?')) return false; const button = this.querySelector('button[type=submit]'); button.disabled = true; button.querySelector('span').textContent = 'Queuing…'; return true;">
                    <input type="hidden" name="action" value="manual_local_backup">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button class="button button-secondary" type="submit" aria-label="Create Local PDF ZIP" title="Create local PDF ZIP"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4H20v16H6.5A2.5 2.5 0 0 1 4 17.5z"/><path d="M4 17.5A2.5 2.5 0 0 1 6.5 15H20"/><path d="M12 7v5"/><path d="m10 10 2 2 2-2"/></svg><span>Local PDF ZIP</span></button>
                </form>
            </div>
        </section>

        <section class="card">
            <h2>Recent backups</h2>
            <?php if (!$backups): ?>
                <p class="muted">No backups yet.</p>
            <?php else: ?>
                <div class="backups-table-wrap" style="overflow-x:auto">
                    <table class="backups-table">
                        <thead><tr><th>Created</th><th>Type</th><th>Drive files</th><th>Duplicates reused</th><th>PDF files in ZIP</th><th>ZIP download</th><th>Google Drive</th></tr></thead>
                        <tbody>
                        <?php foreach ($backups as $backupIndex => $backup): ?>
                            <?php
                            $destinationLabel = match ($backup['destination'] ?? 'drive') {
                                'local' => 'Local PDF ZIP',
                                'both' => 'Google Drive + Local PDF ZIP',
                                default => 'Google Drive',
                            };
                            ?>
                            <tr class="<?= $backupIndex >= 5 ? 'backup-row-hidden' : '' ?>">
                                <td><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($backup['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(($backup['reason'] ?? 'Backup') . ' — ' . $destinationLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) ($backup['file_count'] ?? $backup['drive_folder']['file_count'] ?? 0) ?></td>
                                <td><?= (int) ($backup['duplicate_count'] ?? 0) ?></td>
                                <td><?= (int) ($backup['pdf_file_count'] ?? 0) ?></td>
                                <td>
                                    <?php foreach (($backup['local_archives'] ?? []) as $archiveName): ?>
                                        <?php if (str_contains($archiveName, 'pdf_submissions') && is_file(googleDriveBackupDirectory() . DIRECTORY_SEPARATOR . basename($archiveName))): ?>
                                            <button type="button" class="button button-secondary button-small" data-backup-download="<?= htmlspecialchars(basename($archiveName), ENT_QUOTES, 'UTF-8') ?>">Download PDF ZIP</button><br>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php if (!empty($backup['drive_folder']['folder_url'])): ?>
                                        <a class="drive-folder-link" href="<?= htmlspecialchars($backup['drive_folder']['folder_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" title="Open backup folder in Google Drive">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3h7v7"/><path d="M10 14 21 3"/><path d="M19 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h6"/></svg>
                                            <span>Open Drive</span>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($backup['google_account'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($backups) > 5): ?>
                    <div class="backups-footer">
                        <span class="backups-count">Showing 5 of <?= count($backups) ?> backups</span>
                        <button class="button button-secondary button-small" id="toggleBackups" type="button" aria-expanded="false">See all (<?= count($backups) ?>)</button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </main>
</div>
<script>
const toggleBackups = document.getElementById('toggleBackups');
if (toggleBackups) {
    toggleBackups.addEventListener('click', () => {
        const expanded = toggleBackups.getAttribute('aria-expanded') === 'true';
        document.querySelectorAll('.backup-row-hidden').forEach(row => row.classList.toggle('is-visible', !expanded));
        toggleBackups.setAttribute('aria-expanded', String(!expanded));
        toggleBackups.textContent = expanded ? 'See all (<?= count($backups) ?>)' : 'Show fewer';
        document.querySelector('.backups-count').textContent = expanded
            ? 'Showing 5 of <?= count($backups) ?> backups'
            : 'Showing all <?= count($backups) ?> backups';
    });
}
</script>
</body>
</html>
