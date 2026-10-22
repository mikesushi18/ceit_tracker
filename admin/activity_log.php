<?php
// admin/activity_log.php
require_once '../includes/config.php';
requireAdminDashboardAccess();

$filter_role = sanitize($_GET['role'] ?? '');
$filter_dept = intval($_GET['dept'] ?? 0);

$where = "WHERE al.action NOT IN ('LOGIN', 'LOGOUT')";
if (in_array($filter_role, ['admin', 'sub-admin', 'chairperson', 'faculty'], true)) {
    $where .= " AND u.role = '$filter_role'";
}
if ($filter_dept) {
    $where .= " AND u.department_id = $filter_dept";
}

$logs = $conn->query("
    SELECT al.*, COALESCE(u.full_name, 'Deleted User') AS full_name,
        COALESCE(u.role, 'deleted') AS role, d.code AS dept_code
    FROM activity_log al
    LEFT JOIN users u ON u.id = al.user_id
    LEFT JOIN departments d ON d.id = u.department_id
    $where
    ORDER BY al.created_at DESC
    LIMIT 200
")->fetch_all(MYSQLI_ASSOC);

$action_labels = [
    'CREATE_REMARK' => 'Created Remark',
    'EDIT_REMARK' => 'Edited Remark',
    'COMPLETE_REMARK_TASK' => 'Completed Remark Task',
    'ASSIGN_SUBJECTS' => 'Assigned Subjects',
    'REMOVE_FACULTY_SUBJECT' => 'Removed Faculty Subject',
    'ASSIGN_DELEGATE' => 'Updated Delegate Access',
    'CREATE_FOLDER_GROUP' => 'Created Folder Group',
    'EDIT_FOLDER_GROUP' => 'Edited Folder Group',
    'DELETE_FOLDER_GROUP' => 'Deleted Folder Group',
    'ADD_SUBFOLDER' => 'Added Subfolder',
    'EDIT_SUBFOLDER' => 'Edited Subfolder',
    'SUBMIT_CLEARANCE' => 'Submitted Clearance',
    'UPLOAD_CLEARANCE_FORM' => 'Uploaded Clearance Form',
];
$departments = $conn->query("SELECT * FROM departments ORDER BY code")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Activity Log — CollTrace</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #f4f5f7; }
        .header { background: linear-gradient(120deg, #1C1917 0%, #0d0b0a 100%); border-bottom: 2px solid #F97316; color: #fff; padding: 0 30px; height: 60px; display: flex; align-items: center; justify-content: space-between; }
        .brand { display: flex; align-items: center; gap: 10px; font-size: 20px; font-weight: 800; } .brand-logo { width: 28px; height: 28px; object-fit: contain; flex-shrink: 0; } .brand-text { display: inline-flex; align-items: center; } .brand span { color: #F97316; }
        .header-right { display: flex; align-items: center; gap: 12px; }
        .role-badge { background: #F97316; color: #fff; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        a.btn-back { background: rgba(255,255,255,0.15); color: #fff; padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; }
        a.btn-logout { background: transparent; border: 1px solid rgba(255,255,255,0.4); color: #fff; padding: 6px 14px; border-radius: 6px; font-size: 13px; text-decoration: none; }
        .container { max-width: 1000px; margin: 0 auto; padding: 30px 20px; }
        .page-title { font-size: 22px; font-weight: 800; color: #1C1917; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
        .page-title svg { width: 20px; height: 20px; color: #F97316; flex-shrink: 0; }
        .top-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
        .filters { background: #fff; border-radius: 10px; padding: 16px 20px; margin-bottom: 20px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .filters label { font-size: 13px; font-weight: 600; color: #444; }
        .filters select { padding: 8px 12px; border: 1.5px solid #ddd; border-radius: 7px; font-size: 13px; outline: none; }
        .filters select:focus { border-color: #F97316; }
        .btn-filter { background: #1C1917; color: #fff; border: none; padding: 8px 16px; border-radius: 7px; font-size: 13px; cursor: pointer; }
        .btn-clear { font-size: 13px; color: #888; text-decoration: none; }
        .result-count { font-size: 13px; color: #888; margin-bottom: 12px; }

        .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.07); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; table-layout: fixed; }
        col.col-date { width: 160px; }
        col.col-user { width: 20%; }
        col.col-role { width: 110px; }
        col.col-dept { width: 90px; }
        col.col-action { width: 150px; }
        col.col-details { width: auto; }
        th { background: #f8f8f8; padding: 12px 16px; text-align: left; font-size: 12px; color: #555; font-weight: 700; text-transform: uppercase; vertical-align: middle; }
        td { padding: 12px 16px; border-top: 1px solid #f0f0f0; vertical-align: middle; overflow-wrap: break-word; }
        th.col-center, td.col-center { text-align: center; }
        .empty { text-align: center; color: #aaa; padding: 40px; }
        .action-tag { display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; white-space: nowrap; }
        .role-chip { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .role-admin, .role-sub-admin, .role-deleted, .role-chairperson, .role-faculty,
        .action-tag { background:#e5e7eb; color:#4b5563; }

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
    <div class="top-bar">
        <div class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10"/><path d="M7 12h5"/></svg> Activity Log</div>
    </div>

    <form method="GET" class="filters">
        <label>Show:</label>
        <select name="role">
            <option value="">All Users</option>
            <option value="admin" <?= $filter_role === 'admin' ? 'selected' : '' ?>>Admin Only</option>
            <option value="sub-admin" <?= $filter_role === 'sub-admin' ? 'selected' : '' ?>>Sub-Admin Only</option>
            <option value="chairperson" <?= $filter_role === 'chairperson' ? 'selected' : '' ?>>Department Chair Only</option>
            <option value="faculty" <?= $filter_role === 'faculty' ? 'selected' : '' ?>>Faculty & Staff Only</option>
        </select>
        <label>Department:</label>
        <select name="dept">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $filter_dept == $d['id'] ? 'selected' : '' ?>><?= sanitize($d['code']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-filter">Apply</button>
        <a href="activity_log.php" class="btn-clear">Clear</a>
    </form>

    <div class="result-count"><?= count($logs) ?> record<?= count($logs) === 1 ? '' : 's' ?> found</div>

    <div class="card">
        <?php if (empty($logs)): ?>
            <div class="empty">No activity found for this filter.</div>
        <?php else: ?>
        <table>
            <colgroup>
                <col class="col-date"><col class="col-user"><col class="col-role"><col class="col-dept"><col class="col-action"><col class="col-details">
            </colgroup>
            <tr>
                <th>Date & Time</th>
                <th>User</th>
                <th class="col-center">Role</th>
                <th class="col-center">Department</th>
                <th class="col-center">Action</th>
                <th>Details</th>
            </tr>
            <?php foreach ($logs as $log):
                $action = (string) $log['action'];
                $actionLabel = $action_labels[$action] ?? ucwords(strtolower(str_replace('_', ' ', $action)));
            ?>
            <tr>
                <td style="color:#666;font-size:13px;white-space:nowrap"><?= date('M d, Y g:i A', strtotime($log['created_at'])) ?></td>
                <td style="font-weight:600"><?= sanitize($log['full_name']) ?></td>
                <td class="col-center"><span class="role-chip role-<?= $log['role'] ?>"><?= strtoupper($log['role']) ?></span></td>
                <td class="col-center" style="color:#888;font-size:13px"><?= $log['dept_code'] ? sanitize($log['dept_code']) : '—' ?></td>
                <td class="col-center"><span class="action-tag <?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>"><?= sanitize($actionLabel) ?></span></td>
                <td style="color:#666;font-size:13px"><?= sanitize($log['details']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
