<?php
// admin/edit_user.php
require_once '../includes/config.php';
requireAdminDashboardAccess();

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS employment_type ENUM('regular', 'contract_of_service') NULL AFTER role");

$uid = intval($_GET['id'] ?? 0);
if (!$uid) redirect(BASE_URL . '/admin/manage_users.php');

$user = $conn->query("SELECT * FROM users WHERE id = $uid AND role != 'admin'")->fetch_assoc();
if (!$user) redirect(BASE_URL . '/admin/manage_users.php');

$msg = $err = '';
$departments = $conn->query("SELECT * FROM departments ORDER BY code")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = sanitize($_POST['full_name'] ?? '');
    $username  = sanitize($_POST['username'] ?? '');
    $email     = strtolower(sanitize($_POST['email'] ?? ''));
    $password  = $_POST['password'] ?? '';
    $role      = sanitize($_POST['role'] ?? '');
    $employment_type = sanitize($_POST['employment_type'] ?? '');
    $dept_id   = intval($_POST['department_id'] ?? 0);

    if (empty($full_name) || empty($username) || empty($email) || empty($role)) {
        $err = 'Full name, username, email, and role are required.';
    } elseif (!isAllowedEmail($email)) {
        $err = 'A valid email (' . allowedEmailDomainsLabel() . ') is required.';
    } elseif (!in_array($role, ['sub-admin', 'chairperson', 'faculty'])) {
        $err = 'Invalid role.';
    } elseif ($role === 'faculty' && !in_array($employment_type, ['regular', 'contract_of_service'], true)) {
        $err = 'Please select the faculty employment status.';
    } elseif ($role !== 'sub-admin' && !$dept_id) {
        $err = 'Please select a department.';
    } else {
        if ($role === 'chairperson' && (int) $user['is_active'] === 1) {
            $chairperson = $conn->prepare("SELECT u.full_name, d.code FROM users u JOIN departments d ON d.id = u.department_id WHERE u.role = 'chairperson' AND u.is_active = 1 AND u.department_id = ? AND u.id != ? LIMIT 1");
            $chairperson->bind_param('ii', $dept_id, $uid);
            $chairperson->execute();
            $existingChairperson = $chairperson->get_result()->fetch_assoc();
            $chairperson->close();
            if ($existingChairperson) {
                $err = "Department {$existingChairperson['code']} already has an active chairperson ({$existingChairperson['full_name']}). Deactivate or delete that account before assigning another chairperson to this department.";
            }
        }

        $chk = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?");
        if ($err === '') {
            $chk->bind_param('ssi', $username, $email, $uid);
            $chk->execute();
            $chk->store_result();
        }

        if ($err === '') {
            if ($chk->num_rows > 0) {
                $err = 'This username or email is already used by another account.';
            }
        }
        if ($err === '') {
            $employment_param = $role === 'faculty' ? $employment_type : null;
            $department_param = $role === 'sub-admin' ? null : $dept_id;
            if ($password !== '') {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("UPDATE users SET full_name=?, username=?, email=?, password=?, role=?, employment_type=?, department_id=? WHERE id=?");
                $stmt->bind_param('ssssssii', $full_name, $username, $email, $hashed, $role, $employment_param, $department_param, $uid);
            } else {
                $stmt = $conn->prepare("UPDATE users SET full_name=?, username=?, email=?, role=?, employment_type=?, department_id=? WHERE id=?");
                $stmt->bind_param('sssssii', $full_name, $username, $email, $role, $employment_param, $department_param, $uid);
            }
            if ($stmt->execute()) {
                logActivity($conn, $_SESSION['user_id'], 'EDIT_USER', "Updated account: $username");
                $msg = "Account '$username' updated successfully!";
            } else {
                $err = 'Failed to update account.';
            }
            $stmt->close();
        }
        if ($chk instanceof mysqli_stmt) {
            $chk->close();
        }
    }

    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_err'] = $err;
    if ($err) { $_SESSION['flash_old'] = $_POST; }
    header('Location: edit_user.php?id=' . $uid);
    exit;
}

if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (isset($_SESSION['flash_err'])) { $err = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }
$old = $_SESSION['flash_old'] ?? [];
unset($_SESSION['flash_old']);
$display_name = $old['full_name'] ?? $user['full_name'];
$display_username = $old['username'] ?? $user['username'];
$display_role = $old['role'] ?? $user['role'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Account — CollTrace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --ink: #1c1917; --muted: #78716c; --line: #e5e7eb; --orange: #f97316; --orange-dark: #ea580c; --page: #f4f5f7; }
        body { min-height: 100vh; background: var(--page); color: var(--ink); font-family: 'Inter', 'Segoe UI', sans-serif; }
        .header { height: 60px; padding: 0 30px; display: flex; align-items: center; justify-content: space-between; gap: 16px; background: linear-gradient(120deg, #1c1917, #0d0b0a); border-bottom: 2px solid var(--orange); color: #fff; }
        .header-left, .brand, .header-right { display: flex; align-items: center; }
        .header-left { gap: 16px; }
        .brand { gap: 10px; font-size: 20px; font-weight: 800; }
        .brand-logo { width: 28px; height: 28px; object-fit: contain; flex-shrink: 0; }
        .brand-text { display: inline-flex; align-items: center; }
        .brand span { color: var(--orange); }
        .header-right { gap: 12px; }
        .role-badge { padding: 3px 10px; border-radius: 20px; background: var(--orange); color: #fff; font-size: 11px; font-weight: 700; }
        .btn-back, .btn-logout { padding: 6px 14px; border-radius: 6px; color: #fff; font-size: 13px; text-decoration: none; }
        .btn-back { background: rgba(255,255,255,.15); }
        .btn-logout { border: 1px solid rgba(255,255,255,.4); }
        .btn-back:hover, .btn-logout:hover { background: rgba(255,255,255,.23); }
        .container { width: min(100% - 40px, 960px); margin: 0 auto; padding: 32px 0 54px; }
        .breadcrumb { margin-bottom: 16px; color: #888; font-size: 12px; }
        .breadcrumb a { color: var(--orange-dark); text-decoration: none; }
        .page-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin-bottom: 22px; }
        .eyebrow { margin-bottom: 6px; color: var(--orange-dark); font-size: 10px; font-weight: 800; letter-spacing: 1px; }
        h1 { color: var(--ink); font-size: 24px; line-height: 1.25; font-weight: 800; }
        .page-subtitle { margin-top: 6px; color: var(--muted); font-size: 13px; }
        .account-highlight { display: flex; align-items: center; gap: 12px; min-width: 250px; padding: 12px 15px; border: 1px solid #fed7aa; border-left: 4px solid var(--orange); border-radius: 8px; background: linear-gradient(110deg, #fff7ed, #fff); }
        .account-avatar { display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; flex: 0 0 42px; border: 2px solid #fed7aa; border-radius: 50%; background: var(--orange); color: #fff; font-size: 17px; font-weight: 800; }
        .account-meta { display: grid; min-width: 0; gap: 3px; }
        .account-label { color: #9a3412; font-size: 9px; font-weight: 800; letter-spacing: .7px; }
        .account-name { overflow: hidden; color: var(--ink); font-size: 13px; font-weight: 700; text-overflow: ellipsis; white-space: nowrap; }
        .account-handle { color: var(--muted); font-size: 11px; }
        .card { overflow: hidden; border: 1px solid #e7e7e7; border-radius: 12px; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.07); }
        .card-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 24px; border-bottom: 1px solid #eee; background: linear-gradient(90deg, #fff7ed, #fff 60%); }
        .card-title { display: flex; align-items: center; gap: 9px; color: var(--ink); font-size: 16px; font-weight: 800; }
        .card-title svg, .msg svg { width: 18px; height: 18px; flex: 0 0 auto; color: var(--orange); }
        .card-description { margin-top: 4px; color: var(--muted); font-size: 11px; }
        .current-role { padding: 5px 10px; border: 1px solid #fed7aa; border-radius: 20px; background: #fff3e0; color: #b8560a; font-size: 10px; font-weight: 800; white-space: nowrap; }
        .form-content { padding: 24px; }
        .msg { display: flex; align-items: center; gap: 9px; margin-bottom: 18px; padding: 12px 16px; border: 1px solid; border-radius: 8px; font-size: 13px; line-height: 1.5; }
        .msg.success { border-color: #a3e6b3; background: #edfff3; color: #1a6b35; }
        .msg.error { border-color: #ffb3b3; background: #fff3f3; color: #c2410c; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 20px; }
        .field { min-width: 0; }
        .field.full { grid-column: 1 / -1; }
        label { display: block; margin-bottom: 6px; color: #444; font-size: 12px; font-weight: 700; }
        input[type=text], input[type=email], input[type=password], select { display: block; width: 100%; height: 42px; margin-bottom: 17px; padding: 0 12px; border: 1.5px solid #ddd; border-radius: 7px; background: #fff; color: var(--ink); font: inherit; font-size: 13px; outline: none; transition: border-color .15s, box-shadow .15s, background .15s; }
        input::placeholder { color: #a8a29e; }
        input:focus, select:focus { border-color: var(--orange); background: #fffdfa; box-shadow: 0 0 0 3px rgba(249,115,22,.13); }
        .field-highlight { padding: 14px 14px 0; border: 1px solid #fed7aa; border-radius: 8px; background: #fffaf5; }
        .field-highlight label { color: #9a3412; }
        .dept-note { margin: -5px 0 14px; padding: 10px 12px; border-left: 3px solid #fb923c; border-radius: 0 6px 6px 0; background: #fff3e0; color: #7c2d12; font-size: 11px; line-height: 1.5; }
        .hint { margin: -10px 0 17px; color: #888; font-size: 11px; line-height: 1.5; }
        .form-footer { grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 4px; padding-top: 18px; border-top: 1px solid #eee; }
        .save-note { color: var(--muted); font-size: 11px; }
        .form-actions { display: flex; align-items: center; gap: 9px; }
        .btn-cancel, .btn-submit { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 0 16px; border-radius: 7px; font: inherit; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .btn-cancel { border: 1px solid #d6d3d1; background: #fff; color: #57534e; }
        .btn-cancel:hover { background: #f5f5f4; }
        .btn-submit { border: 1px solid var(--orange); background: var(--orange); color: #fff; }
        .btn-submit:hover { border-color: var(--orange-dark); background: var(--orange-dark); }
        @media (max-width: 700px) {
            .header { padding: 0 16px; }
            .header-left { gap: 10px; }
            .header-right { gap: 7px; }
            .container { width: min(100% - 28px, 540px); padding-top: 24px; }
            .page-heading { align-items: stretch; flex-direction: column; gap: 14px; }
            .account-highlight { min-width: 0; }
            .form-content { padding: 18px; }
        }
        @media (max-width: 520px) {
            .role-badge { display: none; }
            .form-grid { grid-template-columns: 1fr; }
            .field.full, .form-footer { grid-column: auto; }
            .card-heading { align-items: flex-start; padding: 16px 18px; }
            .current-role { max-width: 115px; overflow: hidden; text-overflow: ellipsis; }
            .form-footer { align-items: stretch; flex-direction: column; }
            .form-actions { width: 100%; }
            .btn-cancel, .btn-submit { flex: 1; }
        }
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d6d3d1; border: 2px solid transparent; border-radius: 20px; background-clip: padding-box; }
        ::-webkit-scrollbar-thumb:hover { background: var(--orange); }
        * { scrollbar-width: thin; scrollbar-color: #d6d3d1 transparent; }
    </style>
    <script>
        function toggleRoleFields() {
            var role = document.getElementById('role').value;
            var departmentSection = document.getElementById('dept-section');
            var employmentSection = document.getElementById('employment-section');
            departmentSection.style.display = role === 'sub-admin' ? 'none' : 'block';
            employmentSection.style.display = role === 'faculty' ? 'block' : 'none';
            departmentSection.querySelector('select').required = role !== 'sub-admin';
            employmentSection.querySelector('select').required = role === 'faculty';
        }
    </script>
</head>
<body onload="toggleRoleFields()">
<div class="header">
    <div class="header-left">
        <a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><div class="brand-text">Coll<span>Trace</span></div></a>
        <a href="manage_users.php" class="btn-back">&larr; Users</a>
    </div>
    <div class="header-right">
        <span class="role-badge"><?= strtoupper(sanitize($_SESSION['role'])) ?></span>
        <a href="../logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<main class="container">
    <div class="breadcrumb"><a href="manage_users.php">Users</a> / Edit Account</div>
    <div class="page-heading">
        <div>
            <p class="eyebrow">USER MANAGEMENT</p>
            <h1>Edit account</h1>
            <p class="page-subtitle">Update this user's profile, access role, and department.</p>
        </div>
        <div class="account-highlight">
            <div class="account-avatar"><?= mb_strtoupper(mb_substr($display_name, 0, 1)) ?></div>
            <div class="account-meta">
                <span class="account-label">EDITING ACCOUNT #<?= (int)$user['id'] ?></span>
                <strong class="account-name"><?= sanitize($display_name) ?></strong>
                <span class="account-handle">@<?= sanitize($display_username) ?></span>
            </div>
        </div>
    </div>

    <section class="card" aria-labelledby="account-details-title">
        <div class="card-heading">
            <div>
                <h2 class="card-title" id="account-details-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg> Account details</h2>
                <p class="card-description">Fields marked with * are required.</p>
            </div>
            <span class="current-role"><?= strtoupper(sanitize($display_role)) ?></span>
        </div>

        <div class="form-content">
            <?php if ($msg): ?><div class="msg success" role="status"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> <?= sanitize($msg) ?></div><?php endif; ?>
            <?php if ($err): ?><div class="msg error" role="alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg> <?= sanitize($err) ?></div><?php endif; ?>

            <form method="POST" class="form-grid" onsubmit="return confirm('Save these account changes?')">
                <div class="field">
                    <label for="full-name">Full name *</label>
                    <input id="full-name" type="text" name="full_name" autocomplete="name" placeholder="e.g. Juan dela Cruz" required value="<?= sanitize($old['full_name'] ?? $user['full_name']) ?>">
                </div>
                <div class="field">
                    <label for="username">Username *</label>
                    <input id="username" type="text" name="username" autocomplete="username" placeholder="e.g. jdelacruz" required value="<?= sanitize($old['username'] ?? $user['username']) ?>">
                </div>
                <div class="field full">
                    <label for="email">Email address *</label>
                    <input id="email" type="email" name="email" autocomplete="email" placeholder="e.g. jdelacruz@gmail.com" required value="<?= sanitize($old['email'] ?? ($user['email'] ?? '')) ?>">
                </div>
                <div class="field field-highlight">
                    <label for="role">Access role *</label>
                    <select name="role" id="role" required onchange="toggleRoleFields()">
                        <option value="sub-admin" <?= $display_role === 'sub-admin' ? 'selected' : '' ?>>Sub-Admin</option>
                        <option value="chairperson" <?= $display_role === 'chairperson' ? 'selected' : '' ?>>Chairperson</option>
                        <option value="faculty" <?= $display_role === 'faculty' ? 'selected' : '' ?>>Faculty</option>
                    </select>
                </div>
                <div class="field field-highlight" id="employment-section" style="display:none">
                    <label for="employment-type">Faculty status *</label>
                    <select name="employment_type" id="employment-type">
                        <?php $cur_employment = $old['employment_type'] ?? ($user['employment_type'] ?? ''); ?>
                        <option value="">Select status</option>
                        <option value="regular" <?= $cur_employment === 'regular' ? 'selected' : '' ?>>Regular</option>
                        <option value="contract_of_service" <?= $cur_employment === 'contract_of_service' ? 'selected' : '' ?>>Contract of Service</option>
                    </select>
                </div>
                <div class="field field-highlight full" id="dept-section">
                    <label for="department">Department *</label>
                    <select name="department_id" id="department">
                        <option value="">Select department</option>
                        <?php $cur_dept = $old['department_id'] ?? $user['department_id']; ?>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $cur_dept == $d['id'] ? 'selected' : '' ?>><?= $d['code'] ?> — <?= sanitize($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="dept-note">Users can only access folders in their assigned department.</div>
                </div>
                <div class="field full">
                    <label for="password">New password</label>
                    <input id="password" type="password" name="password" autocomplete="new-password" placeholder="Leave blank to keep the current password">
                    <p class="hint">Only enter a password if you want to replace the current one.</p>
                </div>
                <div class="form-footer">
                    <span class="save-note">Changes are recorded in the admin activity log.</span>
                    <div class="form-actions">
                        <a href="manage_users.php" class="btn-cancel">Cancel</a>
                        <button type="submit" class="btn-submit">Save changes</button>
                    </div>
                </div>
            </form>
        </div>
    </section>
</main>
</body>
</html>