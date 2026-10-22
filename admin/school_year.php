<?php
// admin/school_year.php — Manage School Years
require_once '../includes/config.php';
requireAdminDashboardAccess();
require_once '../includes/google_drive_backup.php';

// Ensure school_years table exists and migrate
$conn->query("CREATE TABLE IF NOT EXISTS school_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(20) NOT NULL UNIQUE,
    status ENUM('active','closed','pending') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL
)");
$conn->query("ALTER TABLE school_years MODIFY COLUMN status ENUM('active','closed','pending') DEFAULT 'active'");
$conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS school_year_id INT NULL");

// Seed a default SY if none exist yet
$sy_count = $conn->query("SELECT COUNT(*) as c FROM school_years")->fetch_assoc()['c'];
if ($sy_count == 0) {
    $current_year = date('Y');
    $label = $current_year . '-' . ($current_year + 1);
    $conn->query("INSERT INTO school_years (label, status) VALUES ('$label', 'active')");
    // Link existing folders with no SY to this default
    $default_sy = $conn->query("SELECT id FROM school_years WHERE status='active' LIMIT 1")->fetch_assoc()['id'];
    $conn->query("UPDATE folders SET school_year_id = $default_sy WHERE school_year_id IS NULL");
}

$msg = $err = '';
$closeSyToken = $_SESSION['close_sy_csrf_token'] ?? bin2hex(random_bytes(32));
$_SESSION['close_sy_csrf_token'] = $closeSyToken;

function isValidSchoolYearLabel($label) {
    if (!preg_match('/^(\d{4})-(\d{4})$/', $label, $parts)) {
        return false;
    }

    return (int) $parts[2] === (int) $parts[1] + 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create_sy') {
        $label = trim($_POST['label'] ?? '');

        if (!isValidSchoolYearLabel($label)) {
            $err = 'Invalid school year. Use consecutive years (e.g. 2026-2027).';
        } else {
            $check_stmt = $conn->prepare("SELECT id FROM school_years WHERE label = ?");
            $check_stmt->bind_param('s', $label);
            $check_stmt->execute();
            $check = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if ($check) {
                $err = "School year '$label' already exists.";
            } else {
                $insert_stmt = $conn->prepare("INSERT INTO school_years (label, status) VALUES (?, 'pending')");
                $insert_stmt->bind_param('s', $label);
                if ($insert_stmt->execute()) {
                    logActivity($conn, $_SESSION['user_id'], 'CREATE_SCHOOL_YEAR', "Created SY $label");
                    $msg = "School Year $label created as pending. Activate it when ready.";
                } else {
                    $err = 'Failed to create the school year. Please try again.';
                }
                $insert_stmt->close();
            }
        }
    }

    if ($action === 'edit_sy') {
        $sy_id = intval($_POST['sy_id'] ?? 0);
        $label = trim($_POST['label'] ?? '');

        if ($sy_id <= 0) {
            $err = 'Please select a valid school year.';
        } elseif (!isValidSchoolYearLabel($label)) {
            $err = 'Invalid school year. Use consecutive years (e.g. 2026-2027).';
        } else {
            $check_stmt = $conn->prepare("SELECT id FROM school_years WHERE label = ? AND id != ?");
            $check_stmt->bind_param('si', $label, $sy_id);
            $check_stmt->execute();
            $duplicate = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if ($duplicate) {
                $err = "School year '$label' already exists.";
            } else {
                $update_stmt = $conn->prepare("UPDATE school_years SET label = ? WHERE id = ?");
                $update_stmt->bind_param('si', $label, $sy_id);
                if ($update_stmt->execute() && $update_stmt->affected_rows >= 0) {
                    $folder_stmt = $conn->prepare("UPDATE folders SET school_year = ? WHERE school_year_id = ?");
                    $folder_stmt->bind_param('si', $label, $sy_id);
                    $folder_stmt->execute();
                    $folder_stmt->close();

                    logActivity($conn, $_SESSION['user_id'], 'EDIT_SCHOOL_YEAR', "Renamed school year ID $sy_id to $label");
                    $msg = "School Year renamed to $label.";
                } else {
                    $err = 'Failed to update the school year. Please try again.';
                }
                $update_stmt->close();
            }
        }
    }

    if ($action === 'reopen_sy') {
        $sy_id = isset($_POST['sy_id']) ? intval($_POST['sy_id']) : 0;
        if (!hash_equals($closeSyToken, (string) ($_POST['csrf_token'] ?? ''))) {
            $err = 'The request could not be verified. Refresh the page and try again.';
        } elseif ($sy_id > 0) {
            $target_stmt = $conn->prepare("SELECT id, status FROM school_years WHERE id = ? LIMIT 1");
            $target_stmt->bind_param('i', $sy_id);
            $target_stmt->execute();
            $target_sy = $target_stmt->get_result()->fetch_assoc();
            $target_stmt->close();
            $active_sy_to_close = $conn->query("SELECT id, label FROM school_years WHERE status='active' LIMIT 1")->fetch_assoc();

            if (!$target_sy) {
                $err = 'The selected school year could not be found.';
            } elseif ($target_sy['status'] === 'active') {
                $err = 'This school year is already active.';
            } else {
                $transactionStarted = false;
                try {
                    $backupJob = null;
                    if ($active_sy_to_close && (int) $active_sy_to_close['id'] !== $sy_id) {
                        $backupJob = googleDriveQueueBackup(
                            (int) $_SESSION['user_id'],
                            'Automatic — closing School Year ' . $active_sy_to_close['label'],
                            (int) $active_sy_to_close['id'],
                            'both'
                        );
                    }

                    $conn->begin_transaction();
                    $transactionStarted = true;
                    $close_stmt = $conn->prepare("UPDATE school_years SET status='closed', closed_at=NOW() WHERE status='active'");
                    if (!$close_stmt->execute()) {
                        throw new RuntimeException('Failed to close the currently active school year.');
                    }
                    $close_stmt->close();

                    $reopen_stmt = $conn->prepare("UPDATE school_years SET status='active', closed_at=NULL WHERE id = ?");
                    $reopen_stmt->bind_param('i', $sy_id);
                    if (!$reopen_stmt->execute()) {
                        throw new RuntimeException('Failed to activate the selected school year.');
                    }
                    $reopen_stmt->close();
                    logActivity($conn, $_SESSION['user_id'], 'REOPEN_SCHOOL_YEAR', "Activated SY ID $sy_id");
                    $conn->commit();
                    $transactionStarted = false;
                    $msg = $backupJob
                        ? 'School year activated. Its backup is running in the background; progress is available in the admin area.'
                        : 'School year activated successfully.';
                } catch (Throwable $exception) {
                    if ($transactionStarted) {
                        $conn->rollback();
                    }
                    $err = 'School year activation stopped: ' . $exception->getMessage();
                }
            }
        } else {
            $err = 'Please select a valid school year to reopen.';
        }
    }

    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_err'] = $err;
    header('Location: school_year.php');
    exit;
}

if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (isset($_SESSION['flash_err'])) { $err = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }

$active_sy = $conn->query("SELECT * FROM school_years WHERE status='active' LIMIT 1")->fetch_assoc();

$school_years = $conn->query("
    SELECT sy.*
    FROM school_years sy
    ORDER BY sy.status = 'active' DESC, sy.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$existing_school_years = array_fill_keys(array_column($school_years, 'label'), true);
$active_start_year = (int) date('Y');
if ($active_sy && preg_match('/^(\d{4})-(\d{4})$/', $active_sy['label'], $active_match)) {
    $active_start_year = (int) $active_match[1];
}
$school_year_suggestions = [];
$suggested_start_year = $active_start_year + 1;
while (count($school_year_suggestions) < 10) {
    $suggested_label = $suggested_start_year . '-' . ($suggested_start_year + 1);
    if (!isset($existing_school_years[$suggested_label])) {
        $school_year_suggestions[] = $suggested_label;
    }
    $suggested_start_year++;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Years — CollTrace Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --navy:#1C1917; --navy2:#231F1B; --red:#F97316; --red2:#EA580C; --bg:#FFF7ED; --card:#fff; --text:#1e2235; --muted:#6b7280; --border:#e5e7eb; }
        body { font-family: 'Inter', 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--bg); min-height: 100vh; }

        .header { background: linear-gradient(120deg, var(--navy) 0%, #0d0b0a 100%); border-bottom: 2px solid var(--red); color: #fff; padding: 0 28px; height: 62px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 16px rgba(0,0,0,0.35); }
        .brand { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 800; } .brand-logo { width: 30px; height: 30px; object-fit: contain; flex-shrink: 0; } .brand-text { display: inline-flex; align-items: center; } .brand span { color: var(--red); }
        .header-right { display: flex; align-items: center; gap: 12px; }
        .role-badge { background: var(--red); color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        a.btn-back { background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.2); color: #fff; padding: 7px 16px; border-radius: 8px; font-size: 13px; text-decoration: none; }
        a.btn-logout { background: transparent; border: 1px solid rgba(255,255,255,0.35); color: #fff; padding: 7px 16px; border-radius: 8px; font-size: 13px; text-decoration: none; }

        .container { max-width: 1320px; margin: 0 auto; padding: 32px 28px 48px; }
        .breadcrumb { font-size: 13px; color: var(--muted); margin-bottom: 6px; }
        .breadcrumb a { color: var(--red); text-decoration: none; }
        .page-title { font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
        .page-title svg, .card-title svg, .msg svg, .warning-box svg, .btn-create svg, .btn-reopen svg {
            width: 1em; height: 1em; color: var(--red); flex-shrink: 0;
            display: inline-block; vertical-align: -0.12em; line-height: 1;
        }
        .btn-create svg, .btn-reopen svg { color: inherit; }
        .page-sub { font-size: 13px; color: var(--muted); margin-bottom: 24px; }

        .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; }
        .msg.success { background: #edfff3; border: 1px solid #a3e6b3; color: #1a6b35; }
        .msg.error   { background: #fff3f3; border: 1px solid #ffb3b3; color: #c0351a; }

        .two-col { display: grid; grid-template-columns: 390px minmax(0, 1fr); gap: 28px; align-items: start; }
        .two-col > div:first-child { order: 2; }
        .two-col > div:last-child { order: 1; }

        /* ACTIVE SY BANNER */
        .active-banner {
            background: linear-gradient(135deg, var(--navy), var(--navy2));
            color: #fff; border-radius: 14px; padding: 22px 26px;
            margin-bottom: 22px; display: flex; align-items: center; gap: 18px;
            box-shadow: 0 4px 20px rgba(26,26,46,0.25);
        }
        .active-banner .sy-badge {
            background: var(--red); color: #fff;
            font-size: 22px; font-weight: 800;
            padding: 10px 20px; border-radius: 10px; letter-spacing: 0.5px;
        }
        .active-banner .sy-info h3 { font-size: 16px; font-weight: 700; margin-bottom: 4px; }
        .active-banner .sy-info p  { font-size: 13px; color: #8da0c0; }
        .active-dot { width: 10px; height: 10px; border-radius: 50%; background: #4ade80; display: inline-block; margin-right: 6px; box-shadow: 0 0 6px #4ade80; animation: pulse 2s infinite; }
        @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.5; } }

        /* CARD */
        .card { background: var(--card); border-radius: 14px; padding: 26px 28px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); }
        .card-title { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }

        /* FORM */
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-control { width: 100%; padding: 12px 15px; border: 1.5px solid var(--border); border-radius: 8px; font-family: inherit; font-size: 14px; color: var(--text); background: #fafbfc; outline: none; transition: border-color 0.2s; }
        .form-control:focus { border-color: var(--red); background: #fff; }
        .school-year-fields { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; }
        .school-year-fields.single { grid-template-columns: 1fr; }
        .btn-create { background: var(--red); color: #fff; border: none; padding: 11px 24px; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; width: 100%; transition: background 0.2s; }
        .btn-create:hover { background: var(--red2); }
        .form-hint { font-size: 12px; color: var(--muted); margin-top: 8px; }
        .warning-box { background: #fff8e1; border: 1px solid #f0d060; color: #7a5c00; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }

        /* SY TABLE */
        .sy-table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 10px; }
        .sy-table { width: 100%; border-collapse: collapse; min-width: 720px; }
        .sy-table th { background: #f8fafc; color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .5px; text-align: left; padding: 13px 18px; border-bottom: 1px solid var(--border); }
        .sy-table td { padding: 16px 18px; border-bottom: 1px solid var(--border); font-size: 13px; color: var(--text); vertical-align: middle; }
        .sy-table tr:last-child td { border-bottom: 0; }
        .sy-table tbody tr:hover td { background: #fffaf5; }
        .sy-table .active-row td { background: #f0fff4; }
        .sy-label { font-size: 16px; font-weight: 800; color: var(--text); }
        .sy-meta  { font-size: 12px; color: var(--muted); }
        .badge-active { background: #edfff3; color: #1a6b35; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .badge-closed { background: #f3f4f6; color: #6b7280; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .badge-pending { background: #fff8e1; color: #a16207; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .btn-reopen { background: var(--navy); color: #fff; border: none; padding: 6px 14px; border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .btn-reopen:hover { background: #2d2d4e; }
        .btn-edit-sy { display: inline-flex; align-items: center; justify-content: center; min-height: 34px; background: #fff; color: #c2410c; border: 1px solid #fed7aa; padding: 6px 14px; border-radius: 7px; font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: none; transition: background .15s,border-color .15s; }
        .btn-edit-sy:hover { background: #fff7ed; border-color: #f97316; }
        .school-year-actions { display: flex; gap: 9px; }
        .school-year-actions .btn-create { flex: 1; }
        .btn-cancel-sy { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; padding: 0 16px; border: 1px solid #d6d3d1; border-radius: 8px; background: #fff; color: #57534e; font-size: 12px; font-weight: 700; text-decoration: none; }
        .btn-cancel-sy:hover { background: #f5f5f4; }
        .see-all { display: block; width: 100%; margin-top: 12px; padding: 10px; border: 1px solid var(--border); border-radius: 8px; background: #fff; color: var(--red2); font-weight: 700; cursor: pointer; }
        .see-all:hover { background: #fff7ed; }
        @media (max-width:700px) { .school-year-fields { grid-template-columns: 1fr; } .two-col { grid-template-columns: 1fr; } .two-col > div:first-child, .two-col > div:last-child { order: initial; } }

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
    <div class="breadcrumb"><a href="dashboard.php">Dashboard</a> › School Years</div>
    <div class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg> School Year Management</div>
    <?php if ($msg): ?><div class="msg success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> <?= $msg ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg error"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> <?= $err ?></div><?php endif; ?>

    <?php if ($active_sy): ?>
    <div class="active-banner">
        <div class="sy-badge"><?= sanitize($active_sy['label']) ?></div>
        <div class="sy-info">
            <h3><span class="active-dot"></span>Currently Active School Year</h3>
        </div>
    </div>
    <?php endif; ?>

    <div class="two-col">
        <!-- LEFT: List -->
        <div>
            <div class="card">
                <div class="card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10"/><path d="M7 12h5"/></svg> All School Years</div>
                <div class="sy-table-wrap">
                <table class="sy-table">
                    <thead><tr><th>School Year</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                <?php foreach ($school_years as $index => $sy): ?>
                <tr class="<?= $sy['status'] === 'active' ? 'active-row' : '' ?>" <?= $index >= 8 ? 'hidden' : '' ?>>
                    <td><div class="sy-label"><?= sanitize($sy['label']) ?></div></td>
                    <td><div class="sy-meta">
                        <?php if ($sy['status'] === 'active'): ?>
                            <span class="badge-active">● Active</span>
                        <?php elseif ($sy['status'] === 'closed'): ?>
                            <span class="badge-closed">Closed <?= $sy['closed_at'] ? date('M Y', strtotime($sy['closed_at'])) : '' ?></span>
                        <?php else: ?>
                            <span class="badge-pending">Pending</span>
                        <?php endif; ?>
                    </div></td>
                    <td>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <a href="school_year.php?edit_id=<?= $sy['id'] ?>" class="btn-edit-sy">Edit</a>
                    <?php if ($sy['status'] !== 'active'): ?>
                    <form method="POST" onsubmit="return confirm('A backup of the current school year will be uploaded to Google Drive and downloaded before that year is closed. Continue?')">
                        <input type="hidden" name="action" value="reopen_sy">
                        <input type="hidden" name="sy_id" value="<?= $sy['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($closeSyToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn-reopen"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg> Activate</button>
                    </form>
                    <?php endif; ?>
                    </div></td>
                </tr>
                <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if (count($school_years) > 8): ?><button type="button" class="see-all" id="seeAllSchoolYears">See All School Years</button><?php endif; ?>
                <?php if (empty($school_years)): ?>
                    <div style="text-align:center;color:var(--muted);padding:24px;font-size:14px;">No school years yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT: Create/Edit -->
        <div>
            <div class="card">
                <?php $edit_id = intval($_GET['edit_id'] ?? 0); $edit_sy = $edit_id ? ($conn->query("SELECT * FROM school_years WHERE id = $edit_id")->fetch_assoc() ?: null) : null; ?>
                <div class="card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $edit_sy ? '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/>' : '<path d="M12 5v14"/><path d="M5 12h14"/>' ?></svg> <?= $edit_sy ? 'Edit School Year' : 'Create New School Year' ?></div>
                <form method="POST" onsubmit="return <?= $edit_sy ? "confirm('Save changes to this school year?')" : 'true' ?>">
                    <input type="hidden" name="action" value="<?= $edit_sy ? 'edit_sy' : 'create_sy' ?>">
                    <?php if ($edit_sy): ?><input type="hidden" name="sy_id" value="<?= $edit_sy['id'] ?>"><?php endif; ?>
                    <div class="form-group">
                        <label>School Year Label</label>
                        <div class="school-year-fields <?= $edit_sy ? 'single' : '' ?>">
                        <input type="text" name="label" id="schoolYearLabel" class="form-control" placeholder="e.g. 2026-2027" pattern="\d{4}-\d{4}" required value="<?= $edit_sy ? sanitize($edit_sy['label']) : '' ?>">
                        <?php if (!$edit_sy): ?>
                        <select id="schoolYearSuggestion" class="form-control" onchange="applySchoolYearSuggestion(this.value)">
                            <option value="">School Year</option>
                            <?php foreach ($school_year_suggestions as $suggestion): ?>
                            <option value="<?= $suggestion ?>"><?= $suggestion ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                        </div>
                    </div>
                    <div class="school-year-actions">
                        <button type="submit" class="btn-create"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $edit_sy ? '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>' : '<path d="M12 3 2 7l10 4 10-4-10-4Z"/><path d="M2 17l10 4 10-4"/><path d="M2 12l10 4 10-4"/>' ?></svg> <?= $edit_sy ? 'Save Changes' : 'Create & Activate' ?></button>
                        <?php if ($edit_sy): ?><a href="school_year.php" class="btn-cancel-sy">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
function applySchoolYearSuggestion(value) {
    if (value) document.getElementById('schoolYearLabel').value = value;
}
const seeAllSchoolYears = document.getElementById('seeAllSchoolYears');
if (seeAllSchoolYears) {
    seeAllSchoolYears.addEventListener('click', function () {
        document.querySelectorAll('.sy-table tbody tr[hidden]').forEach(function (row) { row.hidden = false; });
        this.remove();
    });
}
</script>
</body>
</html>
