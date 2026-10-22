<?php
// admin/school_year.php — Manage School Years
require_once '../includes/config.php';
requireRole('admin');

// Ensure school_years table exists and migrate
$conn->query("CREATE TABLE IF NOT EXISTS school_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(20) NOT NULL UNIQUE,
    status ENUM('active','closed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closed_at TIMESTAMP NULL
)");
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create_sy') {
        $label = trim($_POST['label'] ?? '');

        if (!preg_match('/^\d{4}-\d{4}$/', $label)) {
            $err = 'Invalid format. Use YYYY-YYYY (e.g. 2026-2027).';
        } else {
            $check_stmt = $conn->prepare("SELECT id FROM school_years WHERE label = ?");
            $check_stmt->bind_param('s', $label);
            $check_stmt->execute();
            $check = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();

            if ($check) {
                $err = "School year '$label' already exists.";
            } else {
                $close_stmt = $conn->prepare("UPDATE school_years SET status='closed', closed_at=NOW() WHERE status='active'");
                $close_stmt->execute();
                $close_stmt->close();

                $insert_stmt = $conn->prepare("INSERT INTO school_years (label, status) VALUES (?, 'active')");
                $insert_stmt->bind_param('s', $label);
                if ($insert_stmt->execute()) {
                    logActivity($conn, $_SESSION['user_id'], 'CREATE_SCHOOL_YEAR', "Created SY $label");
                    $msg = "School Year $label created and set as active!";
                } else {
                    $err = 'Failed to create the school year. Please try again.';
                }
                $insert_stmt->close();
            }
        }
    }

    if ($action === 'reopen_sy') {
        $sy_id = isset($_POST['sy_id']) ? intval($_POST['sy_id']) : 0;

        if ($sy_id > 0) {
            $close_stmt = $conn->prepare("UPDATE school_years SET status='closed', closed_at=NOW() WHERE status='active'");
            $close_stmt->execute();
            $close_stmt->close();

            $reopen_stmt = $conn->prepare("UPDATE school_years SET status='active', closed_at=NULL WHERE id = ?");
            $reopen_stmt->bind_param('i', $sy_id);
            if ($reopen_stmt->execute()) {
                logActivity($conn, $_SESSION['user_id'], 'REOPEN_SCHOOL_YEAR', "Reopened SY ID $sy_id");
                $msg = 'School year reopened as active.';
            } else {
                $err = 'Failed to reopen the selected school year.';
            }
            $reopen_stmt->close();
        } else {
            $err = 'Please select a valid school year to reopen.';
        }
    }
}

$school_years = $conn->query("
    SELECT sy.*,
        (SELECT COUNT(DISTINCT f.id) FROM folders f WHERE f.school_year_id = sy.id) AS folder_count,
        (SELECT COUNT(DISTINCT fi.id) FROM files fi JOIN folders f ON f.id = fi.folder_id WHERE f.school_year_id = sy.id) AS file_count
    FROM school_years sy
    ORDER BY sy.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$active_sy = $conn->query("SELECT * FROM school_years WHERE status='active' LIMIT 1")->fetch_assoc();
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

        .container { max-width: 860px; margin: 0 auto; padding: 28px 20px; }
        .breadcrumb { font-size: 13px; color: var(--muted); margin-bottom: 6px; }
        .breadcrumb a { color: var(--red); text-decoration: none; }
        .page-title { font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
        .page-title svg, .card-title svg, .msg svg, .warning-box svg, .btn-create svg, .btn-reopen svg {
            width: 1em; height: 1em; color: var(--red); flex-shrink: 0;
            display: inline-block; vertical-align: -0.12em; line-height: 1;
        }
        .page-sub { font-size: 13px; color: var(--muted); margin-bottom: 24px; }

        .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; }
        .msg.success { background: #edfff3; border: 1px solid #a3e6b3; color: #1a6b35; }
        .msg.error   { background: #fff3f3; border: 1px solid #ffb3b3; color: #c0351a; }

        .two-col { display: grid; grid-template-columns: 1fr 300px; gap: 22px; align-items: start; }

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
        .card { background: var(--card); border-radius: 14px; padding: 22px 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); }
        .card-title { font-size: 15px; font-weight: 700; color: var(--text); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }

        /* FORM */
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1.5px solid var(--border); border-radius: 8px; font-family: inherit; font-size: 14px; color: var(--text); background: #fafbfc; outline: none; transition: border-color 0.2s; }
        .form-control:focus { border-color: var(--red); background: #fff; }
        .btn-create { background: var(--red); color: #fff; border: none; padding: 11px 24px; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; width: 100%; transition: background 0.2s; }
        .btn-create:hover { background: var(--red2); }
        .form-hint { font-size: 12px; color: var(--muted); margin-top: 8px; }
        .warning-box { background: #fff8e1; border: 1px solid #f0d060; color: #7a5c00; padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }

        /* SY LIST */
        .sy-item {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 16px; border-radius: 10px;
            background: #fafbfc; border: 1.5px solid var(--border);
            margin-bottom: 10px; transition: border-color 0.2s;
        }
        .sy-item.active-item { border-color: #4ade80; background: #f0fff4; }
        .sy-item:hover { border-color: #c5cad4; }
        .sy-label { font-size: 17px; font-weight: 800; color: var(--text); min-width: 100px; }
        .sy-meta  { flex: 1; font-size: 13px; color: var(--muted); }
        .sy-meta span { display: block; margin-top: 2px; font-size: 12px; }
        .badge-active { background: #edfff3; color: #1a6b35; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .badge-closed { background: #f3f4f6; color: #6b7280; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .btn-reopen { background: var(--navy); color: #fff; border: none; padding: 6px 14px; border-radius: 7px; font-size: 12px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .btn-reopen:hover { background: #2d2d4e; }
        .sy-stats { display: flex; gap: 10px; }
        .sy-stat { font-size: 11px; color: var(--muted); background: #f0f2f8; padding: 3px 9px; border-radius: 8px; }

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
        <div class="brand"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><div class="brand-text">Coll<span>Trace</span></div></div>
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
            <p>Started <?= date('F d, Y', strtotime($active_sy['created_at'])) ?> · Folders and submissions are open</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="two-col">
        <!-- LEFT: List -->
        <div>
            <div class="card">
                <div class="card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10"/><path d="M7 12h5"/></svg> All School Years</div>
                <?php foreach ($school_years as $sy): ?>
                <div class="sy-item <?= $sy['status'] === 'active' ? 'active-item' : '' ?>">
                    <div class="sy-label"><?= sanitize($sy['label']) ?></div>
                    <div class="sy-meta">
                        <?php if ($sy['status'] === 'active'): ?>
                            <span class="badge-active">● Active</span>
                        <?php else: ?>
                            <span class="badge-closed">Closed <?= $sy['closed_at'] ? date('M Y', strtotime($sy['closed_at'])) : '' ?></span>
                        <?php endif; ?>
                        <div class="sy-stats" style="margin-top:6px;">
                            <span class="sy-stat"><?= $sy['folder_count'] ?> folders</span>
                            <span class="sy-stat"><?= $sy['file_count'] ?> files</span>
                        </div>
                    </div>
                    <?php if ($sy['status'] === 'closed'): ?>
                    <form method="POST" onsubmit="return confirm('Reopen this school year? The current active year will be closed.')">
                        <input type="hidden" name="action" value="reopen_sy">
                        <input type="hidden" name="sy_id" value="<?= $sy['id'] ?>">
                        <button type="submit" class="btn-reopen"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg> Reopen</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (empty($school_years)): ?>
                    <div style="text-align:center;color:var(--muted);padding:24px;font-size:14px;">No school years yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT: Create -->
        <div>
            <div class="card">
                <div class="card-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg> Create New School Year</div>
                <div class="warning-box"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Creating a new school year will <strong>close the current active year</strong>. Its folders and files will be archived but remain viewable.</div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_sy">
                    <div class="form-group">
                        <label>School Year Label</label>
                        <input type="text" name="label" class="form-control" placeholder="e.g. 2026-2027" pattern="\d{4}-\d{4}" required>
                    </div>
                    <button type="submit" class="btn-create"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 7l10 4 10-4-10-4Z"/><path d="M2 17l10 4 10-4"/><path d="M2 12l10 4 10-4"/></svg> Create & Activate</button>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
