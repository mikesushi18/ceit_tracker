<?php
// faculty/clearance_form.php
require_once '../includes/config.php';
requireRole('faculty');

$user_id = $_SESSION['user_id'];
$user = $conn->query("SELECT u.*, d.code AS dept_code FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = $user_id")->fetch_assoc();
$form_types = ['regular' => 'Regular', 'contract_of_service' => 'Contract of Service'];
$form_type = $user['employment_type'] ?? '';
$template = null;

$conn->query("CREATE TABLE IF NOT EXISTS clearance_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_type ENUM('regular', 'contract_of_service') NOT NULL UNIQUE,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
)");

if (isset($form_types[$form_type])) {
    $stmt = $conn->prepare('SELECT file_name, original_name, uploaded_at FROM clearance_forms WHERE form_type = ?');
    $stmt->bind_param('s', $form_type);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clearance Form — CollTrace</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', 'Segoe UI', Roboto, Arial, sans-serif; background: #FFF7ED; color: #1e2235; min-height: 100vh; }
        .header { height: 62px; padding: 0 28px; display: flex; align-items: center; justify-content: space-between; color: #fff; background: linear-gradient(120deg, #1C1917, #0d0b0a); border-bottom: 2px solid #F97316; box-shadow: 0 2px 16px rgba(0,0,0,.35); }
        .brand { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 800; } .brand-logo { width: 30px; height: 30px; object-fit: contain; } .brand span { color: #F97316; }
        .header-actions { display: flex; align-items: center; gap: 12px; } .btn { color: #fff; padding: 7px 16px; border-radius: 8px; font-size: 13px; text-decoration: none; } .btn-back { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2); } .btn-logout { border: 1px solid rgba(255,255,255,.35); }
        .container { max-width: 760px; margin: 0 auto; padding: 28px 20px; } .breadcrumb { font-size: 13px; color: #6b7280; margin-bottom: 6px; } .breadcrumb a { color: #F97316; text-decoration: none; }
        .title { font-size: 22px; font-weight: 800; margin-bottom: 5px; } .sub { color: #6b7280; font-size: 13px; margin-bottom: 22px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 26px; box-shadow: 0 2px 12px rgba(0,0,0,.07); }
        .status { display: inline-flex; padding: 5px 10px; border-radius: 20px; background: #fef3c7; color: #92400e; font-size: 11px; font-weight: 800; margin-bottom: 18px; }
        .document { border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; display: flex; align-items: center; gap: 14px; background: #fafbfc; } .document-icon { width: 42px; height: 42px; border-radius: 10px; background: #fff3e0; color: #EA580C; display: flex; align-items: center; justify-content: center; flex-shrink: 0; } .document-icon svg { width: 20px; height: 20px; }
        .document-info { min-width: 0; flex: 1; } .document-name { font-size: 14px; font-weight: 800; overflow-wrap: anywhere; } .document-meta { color: #6b7280; font-size: 12px; margin-top: 4px; }
        .view-btn { display: inline-flex; align-items: center; gap: 6px; background: #1C1917; color: #fff; padding: 8px 12px; border-radius: 7px; font-size: 12px; font-weight: 700; text-decoration: none; white-space: nowrap; } .view-btn:hover { background: #37302a; }
        .notice { padding: 14px 16px; border-radius: 9px; background: #fff8e1; border: 1px solid #f0d060; color: #7a5c00; font-size: 13px; line-height: 1.5; }
        .next { margin-top: 18px; display: inline-flex; background: #F97316; color: #fff; padding: 10px 15px; border-radius: 8px; font-size: 13px; font-weight: 700; text-decoration: none; } .next:hover { background: #EA580C; }
        @media (max-width: 650px) { .header { padding: 0 16px; } .header .btn-back { display: none; } .header-actions { gap: 6px; } .container { padding: 24px 14px; } .document { align-items: flex-start; flex-wrap: wrap; } }
    </style>
</head>
<body>
<div class="header">
    <div style="display:flex;align-items:center;gap:16px;"><a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" class="brand-logo" alt="CEIT Logo"><div>Coll<span>Trace</span></div></a><a class="btn btn-back" href="dashboard.php">← Dashboard</a></div>
    <div class="header-actions"><a class="btn btn-logout" href="../logout.php">Logout</a></div>
</div>
<div class="container">
    <div class="breadcrumb"><a href="dashboard.php">Dashboard</a> › Clearance Form</div>
    <div class="title">Clearance Form</div>
    <div class="sub">View the clearance document assigned to your faculty employment status.</div>
    <div class="card">
        <?php if (isset($form_types[$form_type])): ?>
            <span class="status"><?= $form_types[$form_type] ?></span>
            <?php if ($template): ?>
            <div class="document">
                <div class="document-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg></div>
                <div class="document-info"><div class="document-name"><?= sanitize($template['original_name']) ?></div><div class="document-meta">Uploaded <?= date('M d, Y g:i A', strtotime($template['uploaded_at'])) ?></div></div>
                <a class="view-btn" href="<?= BASE_URL ?>/uploads/clearance/<?= rawurlencode($template['file_name']) ?>" download="<?= sanitize($template['original_name']) ?>">Download Form</a>
            </div>
            <?php else: ?>
            <div class="notice">The administrator has not uploaded the <?= $form_types[$form_type] ?> clearance form yet.</div>
            <?php endif; ?>
        <?php else: ?>
            <div class="notice">Your account does not have a Regular or Contract of Service status. Please contact the administrator.</div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
