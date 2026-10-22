<?php
// admin/create_user.php
require_once '../includes/config.php';
requireAdminDashboardAccess();

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS employment_type ENUM('regular', 'contract_of_service') NULL AFTER role");

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

    if (empty($full_name) || empty($username) || empty($email) || empty($password) || empty($role)) {
        $err = 'All fields are required.';
    } elseif (!isAllowedEmail($email)) {
        $err = 'A valid email (' . allowedEmailDomainsLabel() . ') is required for Sign in with Google.';
    } elseif (!in_array($role, ['admin', 'sub-admin', 'chairperson', 'faculty'])) {
        $err = 'Invalid role.';
    } elseif ($role === 'faculty' && !in_array($employment_type, ['regular', 'contract_of_service'], true)) {
        $err = 'Please select the faculty employment status.';
    } elseif (!in_array($role, ['admin', 'sub-admin'], true) && !$dept_id) {
        $err = 'Please select a department.';
    } elseif ($role === 'chairperson') {
        $chairperson = $conn->prepare("SELECT u.full_name, d.code FROM users u JOIN departments d ON d.id = u.department_id WHERE u.role = 'chairperson' AND u.is_active = 1 AND u.department_id = ? LIMIT 1");
        $chairperson->bind_param('i', $dept_id);
        $chairperson->execute();
        $existingChairperson = $chairperson->get_result()->fetch_assoc();
        $chairperson->close();
        if ($existingChairperson) {
            $err = "Department {$existingChairperson['code']} already has an active chairperson ({$existingChairperson['full_name']}). Deactivate or delete that account before creating a new chairperson for this department.";
        }
    }

    if ($err === '') {
        // Check username AND email uniqueness
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $chk->bind_param('ss', $username, $email);
        $chk->execute();
        $chk->store_result();

        if ($chk->num_rows > 0) {
            $err = "This username or email is already used by another account.";
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $dept_param = $dept_id ?: null;
            $employment_param = $role === 'faculty' ? $employment_type : null;
            $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role, employment_type, department_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssssssi', $username, $hashed, $full_name, $email, $role, $employment_param, $dept_param);
            if ($stmt->execute()) {
                logActivity($conn, $_SESSION['user_id'], 'CREATE_USER', "Created $role account: $username");
                $msg = "Account '$username' created successfully!";
            } else {
                $err = 'Failed to create account.';
            }
            $stmt->close();
        }
        $chk->close();
    }

    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_err'] = $err;
    if ($err) { $_SESSION['flash_old'] = $_POST; }
    header('Location: create_user.php');
    exit;
}

if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (isset($_SESSION['flash_err'])) { $err = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }
$old = $_SESSION['flash_old'] ?? [];
unset($_SESSION['flash_old']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account — CollTrace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #f4f5f7; }
        .header { background: linear-gradient(120deg, #1C1917 0%, #0d0b0a 100%); border-bottom: 2px solid #F97316; color: #fff; padding: 0 30px; height: 60px; display: flex; align-items: center; justify-content: space-between; }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .brand { display: flex; align-items: center; gap: 10px; font-size: 20px; font-weight: 800; }
        .brand-logo { width: 28px; height: 28px; object-fit: contain; flex-shrink: 0; } .brand-text { display: inline-flex; align-items: center; }
        .brand span { color: #F97316; }
        .header-right { display: flex; align-items: center; gap: 12px; }
        .role-badge { background: #F97316; color: #fff; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        a.btn-back { background: rgba(255,255,255,0.15); color: #fff; padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; }
        a.btn-logout { background: transparent; border: 1px solid rgba(255,255,255,0.4); color: #fff; padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; }

        .container { width: min(100% - 40px, 960px); margin: 0 auto; padding: 32px 0 54px; }
        .breadcrumb { margin-bottom: 16px; color: #888; font-size: 12px; }
        .breadcrumb a { color: #EA580C; text-decoration: none; }
        .page-heading { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin-bottom: 22px; }
        .eyebrow { margin-bottom: 6px; color: #EA580C; font-size: 10px; font-weight: 800; letter-spacing: 1px; }
        h1 { color: #1c1917; font-size: 24px; line-height: 1.25; font-weight: 800; }
        .page-subtitle { margin-top: 6px; color: #78716c; font-size: 13px; }
        .account-highlight { display: flex; align-items: center; gap: 12px; min-width: 250px; padding: 12px 15px; border: 1px solid #fed7aa; border-left: 4px solid #f97316; border-radius: 8px; background: linear-gradient(110deg,#fff7ed,#fff); }
        .account-avatar { display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; flex: 0 0 42px; border: 2px solid #fed7aa; border-radius: 50%; background: #f97316; color: #fff; font-size: 17px; font-weight: 800; }
        .account-meta { display: grid; min-width: 0; gap: 3px; }
        .account-label { color: #9a3412; font-size: 9px; font-weight: 800; letter-spacing: .7px; }
        .account-name { color: #1c1917; font-size: 13px; font-weight: 700; }
        .account-handle { color: #78716c; font-size: 11px; }
        .card { overflow: hidden; border: 1px solid #e7e7e7; border-radius: 12px; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.07); }
        .card-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 24px; border-bottom: 1px solid #eee; background: linear-gradient(90deg,#fff7ed,#fff 60%); }
        .card-title { color: #1c1917; font-size: 16px; font-weight: 800; }
        .card-description { margin-top: 4px; color: #78716c; font-size: 11px; }
        .form-content { padding: 24px; }
        .msg { display: flex; align-items: center; gap: 9px; margin-bottom: 18px; padding: 12px 16px; border: 1px solid; border-radius: 8px; font-size: 13px; line-height: 1.5; }
        .msg svg { width: 18px; height: 18px; flex: 0 0 auto; color: #f97316; }
        .msg.success { border-color: #a3e6b3; background: #edfff3; color: #1a6b35; }
        .msg.error { border-color: #ffb3b3; background: #fff3f3; color: #c2410c; }
        .form-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 0 20px; }
        .field { min-width: 0; }
        .field.full { grid-column: 1 / -1; }
        label { display: block; margin-bottom: 6px; color: #444; font-size: 12px; font-weight: 700; }
        input[type=text],input[type=email],input[type=password],select { display: block; width: 100%; height: 42px; margin-bottom: 17px; padding: 0 12px; border: 1.5px solid #ddd; border-radius: 7px; background: #fff; color: #1c1917; font: inherit; font-size: 13px; outline: none; transition: border-color .15s,box-shadow .15s,background .15s; }
        input::placeholder { color: #a8a29e; }
        input:focus,select:focus { border-color: #f97316; background: #fffdfa; box-shadow: 0 0 0 3px rgba(249,115,22,.13); }
        .field-highlight { padding: 14px 14px 0; border: 1px solid #fed7aa; border-radius: 8px; background: #fffaf5; }
        .field-highlight label { color: #9a3412; }
        .dept-note { margin: -5px 0 14px; padding: 10px 12px; border-left: 3px solid #fb923c; border-radius: 0 6px 6px 0; background: #fff3e0; color: #7c2d12; font-size: 11px; line-height: 1.5; }
        .form-footer { grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 4px; padding-top: 18px; border-top: 1px solid #eee; }
        .save-note { color: #78716c; font-size: 11px; }
        .form-actions { display: flex; align-items: center; gap: 9px; }
        .btn-cancel,.btn-submit { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 0 16px; border-radius: 7px; font: inherit; font-size: 12px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .btn-cancel { border: 1px solid #d6d3d1; background: #fff; color: #57534e; }
        .btn-cancel:hover { background: #f5f5f4; }
        .btn-submit { border: 1px solid #f97316; background: #f97316; color: #fff; }
        .btn-submit:hover { border-color: #ea580c; background: #ea580c; }
        @media (max-width:700px) { .header { padding: 0 16px; } .container { width: min(100% - 28px,540px); padding-top: 24px; } .page-heading { align-items: stretch; flex-direction: column; gap: 14px; } .account-highlight { min-width: 0; } .form-content { padding: 18px; } }
        @media (max-width:520px) { .role-badge { display: none; } .form-grid { grid-template-columns: 1fr; } .field.full,.form-footer { grid-column: auto; } .card-heading { padding: 16px 18px; } .form-footer { align-items: stretch; flex-direction: column; } .form-actions { width: 100%; } .btn-cancel,.btn-submit { flex: 1; } }

        /* ============ Custom Scrollbar (CollTrace) ============ */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d6d3d1; border-radius: 20px; border: 2px solid transparent; background-clip: padding-box; }
        ::-webkit-scrollbar-thumb:hover { background: #F97316; }
        * { scrollbar-width: thin; scrollbar-color: #d6d3d1 transparent; }
    </style>
    <script>
        function toggleDept() {
            var role = document.getElementById('role').value;
            var show = role && !['admin', 'sub-admin'].includes(role);
            var departmentSection = document.getElementById('dept-section');
            var employmentSection = document.getElementById('employment-section');
            departmentSection.style.display = show ? 'block' : 'none';
            employmentSection.style.display = role === 'faculty' ? 'block' : 'none';
            departmentSection.querySelector('select').required = !!show;
            employmentSection.querySelector('select').required = role === 'faculty';
        }
    </script>
</head>
<body onload="toggleDept()">
<div class="header">
    <div class="header-left">
        <a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><div class="brand-text">Coll<span>Trace</span></div></a>
        <a href="manage_users.php" class="btn-back">← Users</a>
    </div>
    <div class="header-right">
        <span class="role-badge"><?= strtoupper(sanitize($_SESSION['role'])) ?></span>
        <a href="../logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<main class="container">
    <div class="breadcrumb"><a href="manage_users.php">Users</a> / Create Account</div>
    <div class="page-heading">
        <div><p class="eyebrow">USER MANAGEMENT</p><h1>Create account</h1><p class="page-subtitle">Create a user profile and assign the appropriate access.</p></div>
        <div class="account-highlight"><div class="account-avatar">+</div><div class="account-meta"><span class="account-label">NEW ACCOUNT</span><strong class="account-name">Account setup</strong><span class="account-handle">Required fields are marked *</span></div></div>
    </div>

    <section class="card" aria-labelledby="account-details-title">
        <div class="card-heading"><div><h2 class="card-title" id="account-details-title">Account details</h2><p class="card-description">Enter the user's profile, role, and department.</p></div></div>
        <div class="form-content">
        <?php if ($msg): ?><div class="msg success" role="status"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> <?= sanitize($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="msg error" role="alert"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg> <?= sanitize($err) ?></div><?php endif; ?>
        <form method="POST" class="form-grid">
            <div class="field"><label for="full-name">Full name *</label><input id="full-name" type="text" name="full_name" autocomplete="name" placeholder="e.g. Juan dela Cruz" required value="<?= isset($old['full_name']) ? sanitize($old['full_name']) : '' ?>"></div>
            <div class="field"><label for="username">Username *</label><input id="username" type="text" name="username" autocomplete="username" placeholder="e.g. jdelacruz" required value="<?= isset($old['username']) ? sanitize($old['username']) : '' ?>"></div>
            <div class="field"><label for="email">Email address *</label><input id="email" type="email" name="email" autocomplete="email" placeholder="e.g. jdelacruz@gmail.com" required value="<?= isset($old['email']) ? sanitize($old['email']) : '' ?>"></div>
            <div class="field"><label for="password">Password *</label><input id="password" type="password" name="password" autocomplete="new-password" placeholder="Set a password" required></div>
            <div class="field field-highlight"><label for="role">Access role *</label><select name="role" id="role" required onchange="toggleDept()">
                <option value="">— Select Role —</option>
                <option value="admin" <?= (($old['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option>
                <option value="sub-admin" <?= (($old['role'] ?? '') === 'sub-admin') ? 'selected' : '' ?>>Sub-Admin</option>
                <option value="chairperson" <?= (($old['role'] ?? '') === 'chairperson') ? 'selected' : '' ?>>Chairperson</option>
                <option value="faculty" <?= (($old['role'] ?? '') === 'faculty') ? 'selected' : '' ?>>Faculty</option>
            </select></div>

            <div class="field field-highlight" id="employment-section" style="display:none">
                <label for="employment-type">Faculty status *</label>
                <select name="employment_type" id="employment-type">
                    <option value="">— Select Status —</option>
                    <option value="regular" <?= (($old['employment_type'] ?? '') === 'regular') ? 'selected' : '' ?>>Regular</option>
                    <option value="contract_of_service" <?= (($old['employment_type'] ?? '') === 'contract_of_service') ? 'selected' : '' ?>>Contract of Service</option>
                </select>
            </div>

            <div class="field field-highlight full" id="dept-section">
                <label for="department">Department *</label>
                <select name="department_id" id="department">
                    <option value="">— Select Department —</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= (($old['department_id'] ?? 0) == $d['id']) ? 'selected' : '' ?>>
                        <?= $d['code'] ?> — <?= sanitize($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="dept-note">Users can only access folders in their assigned department.</div>
            </div>
            <div class="form-footer"><span class="save-note">New accounts are recorded in the admin activity log.</span><div class="form-actions"><a href="manage_users.php" class="btn-cancel">Cancel</a><button type="submit" class="btn-submit">Create account</button></div></div>
        </form>
        </div>
    </section>
</main>
</body>
</html>
