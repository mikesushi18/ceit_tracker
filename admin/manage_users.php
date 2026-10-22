<?php
// admin/manage_users.php
require_once '../includes/config.php';
requireAdminDashboardAccess();

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS employment_type ENUM('regular', 'contract_of_service') NULL AFTER role");

$msg = $err = '';

// Toggle active status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_user'])) {
    $uid = intval($_POST['user_id']);
    $targetStmt = $conn->prepare('SELECT role, department_id, is_active FROM users WHERE id = ? AND role != \'admin\'');
    $targetStmt->bind_param('i', $uid);
    $targetStmt->execute();
    $targetUser = $targetStmt->get_result()->fetch_assoc();
    $targetStmt->close();

    if (!$targetUser) {
        $err = 'The account could not be found.';
    } else {
        $new = (int) $targetUser['is_active'] === 1 ? 0 : 1;
        if ($new === 1 && $targetUser['role'] === 'chairperson') {
            $chairperson = $conn->prepare("SELECT u.full_name, d.code FROM users u JOIN departments d ON d.id = u.department_id WHERE u.role = 'chairperson' AND u.is_active = 1 AND u.department_id = ? AND u.id != ? LIMIT 1");
            $departmentId = (int) $targetUser['department_id'];
            $chairperson->bind_param('ii', $departmentId, $uid);
            $chairperson->execute();
            $existingChairperson = $chairperson->get_result()->fetch_assoc();
            $chairperson->close();
            if ($existingChairperson) {
                $err = "Department {$existingChairperson['code']} already has an active chairperson ({$existingChairperson['full_name']}). Deactivate or delete that account before activating this chairperson.";
            }
        }

        if ($err === '') {
            $updateStmt = $conn->prepare('UPDATE users SET is_active = ? WHERE id = ?');
            $updateStmt->bind_param('ii', $new, $uid);
            if ($updateStmt->execute()) {
                $msg = 'User status updated.';
            } else {
                $err = 'User status could not be updated.';
            }
            $updateStmt->close();
        }
    }
}

// Delete user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $uid = intval($_POST['user_id']);
    if ($_SESSION['role'] !== 'admin') {
        $err = 'Sub-admin accounts cannot delete users.';
    } elseif ($uid != $_SESSION['user_id']) {
        try {
            // Detach the account from its records so files, folders, and history remain.
            $conn->query("ALTER TABLE files ADD COLUMN IF NOT EXISTS uploaded_via_proxy_id INT NULL");
            $transaction_started = false;

            $user_stmt = $conn->prepare("SELECT username, full_name, role, profile_photo FROM users WHERE id = ? AND role != 'admin'");
            $user_stmt->bind_param('i', $uid);
            $user_stmt->execute();
            $target_user = $user_stmt->get_result()->fetch_assoc();
            $user_stmt->close();

            if (!$target_user) {
                throw new RuntimeException('User could not be found.');
            }

            $reference_columns = [
                'activity_log' => ['user_id'],
                'announcements' => ['posted_by'],
                'files' => ['uploaded_by', 'uploaded_via_proxy_id'],
                'folders' => ['created_by'],
                'folder_groups' => ['created_by'],
                'folder_assignments' => ['assigned_to_user_id', 'assigned_by_user_id'],
                'folder_delegations' => ['delegated_from_user_id', 'delegated_to_user_id'],
            ];

            foreach ($reference_columns as $table => $columns) {
                foreach ($columns as $column) {
                    $exists_stmt = $conn->prepare(
                        "SELECT COUNT(*) AS column_exists
                         FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
                    );
                    $db_name = DB_NAME;
                    $exists_stmt->bind_param('sss', $db_name, $table, $column);
                    $exists_stmt->execute();
                    $column_exists = $exists_stmt->get_result()->fetch_assoc()['column_exists'] ?? 0;
                    $exists_stmt->close();

                    if ($column_exists) {
                        $conn->query("ALTER TABLE `$table` MODIFY COLUMN `$column` INT NULL");
                    }
                }
            }

            $conn->begin_transaction();
            $transaction_started = true;

            $stmt = $conn->prepare("DELETE FROM otps WHERE user_id = ?");
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $stmt->close();

            foreach ($reference_columns as $table => $columns) {
                foreach ($columns as $column) {
                    $exists_stmt = $conn->prepare(
                        "SELECT COUNT(*) AS column_exists
                         FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
                    );
                    $db_name = DB_NAME;
                    $exists_stmt->bind_param('sss', $db_name, $table, $column);
                    $exists_stmt->execute();
                    $column_exists = $exists_stmt->get_result()->fetch_assoc()['column_exists'] ?? 0;
                    $exists_stmt->close();

                    if ($column_exists) {
                        $stmt = $conn->prepare("UPDATE `$table` SET `$column` = NULL WHERE `$column` = ?");
                        $stmt->bind_param('i', $uid);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }

            $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('User could not be deleted.');
            }
            $stmt->close();

            $conn->commit();

            logActivity(
                $conn,
                $_SESSION['user_id'],
                'DELETE_USER',
                "Permanently deleted account: {$target_user['username']} ({$target_user['role']})"
            );

            if (!empty($target_user['profile_photo'])) {
                $avatar_path = __DIR__ . '/../uploads/avatars/' . $target_user['profile_photo'];
                if (storageExists($avatar_path)) storageDelete($avatar_path);
            }
            $msg = 'User permanently deleted.';
        } catch (Throwable $delete_error) {
            if ($transaction_started ?? false) {
                $conn->rollback();
            }
            $err = 'User could not be permanently deleted. ' . $delete_error->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_err'] = $err;
    $redirect_qs = http_build_query(array_filter(['role' => $_GET['role'] ?? '', 'dept' => $_GET['dept'] ?? '']));
    header('Location: manage_users.php' . ($redirect_qs ? '?' . $redirect_qs : ''));
    exit;
}

if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (isset($_SESSION['flash_err'])) { $err = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }

$filter_role = sanitize($_GET['role'] ?? '');
$filter_dept = intval($_GET['dept'] ?? 0);

$where = "WHERE u.role != 'admin'";
if ($filter_role) $where .= " AND u.role = '$filter_role'";
if ($filter_dept) $where .= " AND u.department_id = $filter_dept";

$users = $conn->query("
    SELECT u.*, d.code AS dept_code
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    $where
    ORDER BY u.role, u.full_name
")->fetch_all(MYSQLI_ASSOC);

$departments = $conn->query("SELECT * FROM departments ORDER BY code")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users — CollTrace</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #f4f5f7; }
        .header { background: linear-gradient(120deg, #1C1917 0%, #0d0b0a 100%); border-bottom: 2px solid #F97316; color: #fff; padding: 0 30px; height: 60px; display: flex; align-items: center; justify-content: space-between; }
        .brand { display: flex; align-items: center; gap: 10px; font-size: 20px; font-weight: 800; }
        .brand-logo { width: 28px; height: 28px; object-fit: contain; flex-shrink: 0; } .brand-text { display: inline-flex; align-items: center; }
        .brand span { color: #F97316; }
        .header-right { display: flex; align-items: center; gap: 12px; font-size: 14px; }
        .role-badge { background: #F97316; color: #fff; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        a.btn-back { background: rgba(255,255,255,0.15); color: #fff; padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; }
        a.btn-logout { background: transparent; border: 1px solid rgba(255,255,255,0.4); color: #fff; padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; }

        .container { width: min(100% - 40px, 1440px); margin: 0 auto; padding: 34px 0 48px; }
        .top-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .page-title { font-size: 22px; font-weight: 800; color: #1C1917; display: flex; align-items: center; gap: 8px; }
        .page-title svg { width: 18px; height: 18px; color: #F97316; flex-shrink: 0; }
        .btn-create svg { width: 18px; height: 18px; color: #fff; flex-shrink: 0; }
        .btn-create { background: #F97316; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }

        .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .msg svg { width: 1em; height: 1em; flex-shrink: 0; }
        .msg.success { background: #edfff3; border: 1px solid #a3e6b3; color: #1a6b35; }

        .filters { background: #fff; border-radius: 12px; padding: 18px 22px; margin-bottom: 24px; display: flex; gap: 14px; align-items: center; flex-wrap: wrap; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .filters select { padding: 8px 12px; border: 1.5px solid #ddd; border-radius: 7px; font-size: 13px; outline: none; }
        .filters select:focus { border-color: #F97316; }
        .btn-filter { background: #1C1917; color: #fff; border: none; padding: 8px 16px; border-radius: 7px; font-size: 13px; cursor: pointer; }

        .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.07); overflow: hidden; }
        .table-scroll { overflow-x: auto; }
        table { width: 100%; min-width: 1120px; border-collapse: collapse; font-size: 14px; }
        th { background: #f8f8f8; padding: 14px 20px; text-align: left; font-size: 12px; color: #555; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        td { padding: 15px 20px; border-top: 1px solid #f0f0f0; vertical-align: middle; }
        th:first-child, td:first-child { min-width: 250px; }
        th:nth-child(2), td:nth-child(2) { min-width: 150px; }
        th:nth-child(3), td:nth-child(3) { min-width: 130px; }
        th:nth-child(4), td:nth-child(4) { min-width: 160px; }
        th:nth-child(5), td:nth-child(5) { min-width: 140px; }
        th:nth-child(6), td:nth-child(6) { min-width: 120px; }
        th:last-child, td:last-child { min-width: 250px; }

        .role-chip { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .role-chairperson { background: #fff3e0; color: #b8560a; }
        .role-sub-admin { background: #fef3c7; color: #92400e; }
        .role-faculty { background: #e8f4ff; color: #1a5fa6; }
        .employment-chip { display: inline-block; margin-top: 4px; padding: 2px 7px; border-radius: 12px; font-size: 10px; font-weight: 700; }
        .employment-regular { background: #edfff3; color: #1a6b35; }
        .employment-contract_of_service { background: #fff8e1; color: #a16207; }
        .active-yes { color: #1a6b35; font-weight: 700; font-size: 12px; }
        .active-no { color: #aaa; font-size: 12px; }

        .btn-sm { padding: 7px 13px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: none; }
        .btn-edit { background: #fff; color: #c2410c; border: 1px solid #fed7aa; text-decoration: none; display: inline-flex; align-items: center; transition: background .15s,border-color .15s; }
        .btn-edit:hover { background: #fff7ed; border-color: #f97316; }
        .btn-toggle-on { background: #fff3f3; color: #EA580C; border: 1px solid #ffb3b3; }
        .btn-toggle-off { background: #edfff3; color: #1a6b35; border: 1px solid #a3e6b3; }
        .btn-del { background: #fff; color: #EA580C; border: 1px solid #ffb3b3; }
        .btn-del:hover { background: #fff3f3; }
        .empty { text-align: center; color: #aaa; padding: 40px; }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; vertical-align: middle; margin-right: 8px; border: 2px solid #fed7aa; }
        .user-avatar-fallback { display: inline-flex; align-items: center; justify-content: center; background: #F97316; color: #fff; font-size: 13px; font-weight: 800; }

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
        <span class="role-badge"><?= strtoupper(sanitize($_SESSION['role'])) ?></span>
        <a href="../logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<div class="container">
    <div class="top-bar">
        <div class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg> Manage Users</div>
        <a href="create_user.php" class="btn-create"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg> Create Account</a>
    </div>

    <?php if ($msg): ?><div class="msg success"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> <?= $msg ?></div><?php endif; ?>
    <form method="GET" action="manage_users.php">
        <div class="filters">
            <span style="font-size:13px;font-weight:600;color:#444;">Filter:</span>
            <select name="role">
                <option value="">All Roles</option>
                <option value="sub-admin" <?= $filter_role === 'sub-admin' ? 'selected' : '' ?>>Sub-admin</option>
                <option value="chairperson" <?= $filter_role === 'chairperson' ? 'selected' : '' ?>>Chairperson</option>
                <option value="faculty" <?= $filter_role === 'faculty' ? 'selected' : '' ?>>Faculty</option>
            </select>
            <select name="dept">
                <option value="">All Departments</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $filter_dept == $d['id'] ? 'selected' : '' ?>><?= $d['code'] ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-filter">Apply</button>
            <a href="manage_users.php" style="font-size:13px;color:#888;text-decoration:none;">Clear</a>
        </div>
    </form>

    <div class="card">
        <?php if (empty($users)): ?>
            <div class="empty">No users found.</div>
        <?php else: ?>
        <div class="table-scroll">
        <table>
            <tr>
                <th>Full Name</th>
                <th>Username</th>
                <th>Role</th>
                <th>Faculty Status</th>
                <th>Department</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($users as $u): ?>
            <tr>
                <td style="font-weight:600">
                    <?php $avatar_path = __DIR__ . '/../uploads/avatars/' . ($u['profile_photo'] ?? ''); ?>
                    <?php if (!empty($u['profile_photo']) && storageExists($avatar_path)): ?>
                        <img class="user-avatar" src="<?= BASE_URL . '/uploads/avatars/' . rawurlencode($u['profile_photo']) . '?v=' . (storageModified($avatar_path) ?: 0) ?>" alt="">
                    <?php else: ?>
                        <span class="user-avatar user-avatar-fallback"><?= mb_strtoupper(mb_substr($u['full_name'], 0, 1)) ?></span>
                    <?php endif; ?>
                    <?= sanitize($u['full_name']) ?>
                </td>
                <td style="color:#888"><?= sanitize($u['username']) ?></td>
                <td><span class="role-chip role-<?= $u['role'] ?>"><?= strtoupper($u['role']) ?></span></td>
                <td>
                    <?php if ($u['role'] === 'faculty' && !empty($u['employment_type'])): ?>
                        <span class="employment-chip employment-<?= $u['employment_type'] ?>"><?= $u['employment_type'] === 'regular' ? 'Regular' : 'Contract of Service' ?></span>
                    <?php else: ?>
                        <span style="color:#aaa">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $u['dept_code'] ? sanitize($u['dept_code']) : '<span style="color:#aaa">—</span>' ?></td>
                <td>
                    <?php if ($u['is_active']): ?>
                        <span class="active-yes">● Active</span>
                    <?php else: ?>
                        <span class="active-no">○ Inactive</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;gap:6px;">
                        <a href="edit_user.php?id=<?= $u['id'] ?>" class="btn-sm btn-edit">Edit</a>
                        <form method="POST" style="display:inline" onsubmit="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> this account?')">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="current_status" value="<?= $u['is_active'] ?>">
                            <button type="submit" name="toggle_user" class="btn-sm <?= $u['is_active'] ? 'btn-toggle-on' : 'btn-toggle-off' ?>">
                                <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" name="delete_user" class="btn-sm btn-del" onclick="return confirm('Do you want to permanently delete this account and its associated records? This cannot be undone.')">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
