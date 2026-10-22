<?php
// admin/clearance_submissions.php
require_once '../includes/config.php';
requireAdminDashboardAccess();

$conn->query("CREATE TABLE IF NOT EXISTS clearance_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id INT NULL,
    form_type ENUM('regular', 'contract_of_service') NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY faculty_clearance (faculty_id),
    FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE SET NULL
)");

if (isset($_GET['view'])) {
    $submission_id = (int) $_GET['view'];
    $stmt = $conn->prepare('SELECT file_name, original_name FROM clearance_submissions WHERE id = ?');
    $stmt->bind_param('i', $submission_id);
    $stmt->execute();
    $submission = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$submission) {
        http_response_code(404);
        exit('Submission not found.');
    }

    $file_path = __DIR__ . '/../uploads/clearance_submissions/' . basename($submission['file_name']);
    if (!is_file($file_path)) {
        http_response_code(404);
        exit('Submission file not found.');
    }

    $extension = strtolower(pathinfo($submission['file_name'], PATHINFO_EXTENSION));
    $mime_types = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    $mime = $mime_types[$extension] ?? (mime_content_type($file_path) ?: 'application/octet-stream');
    $force_download = ($_GET['download'] ?? '') === '1'
        || !in_array($extension, ['pdf', 'docx'], true)
        || ($extension === 'docx' && ($_GET['raw'] ?? '') !== '1');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($force_download ? 'attachment' : 'inline') . '; filename="' . addslashes($submission['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
}

$filter_dept = intval($_GET['dept'] ?? 0);
$filter_type = $_GET['type'] ?? '';
$where = "WHERE u.role = 'faculty' AND u.is_active = 1";
if ($filter_dept > 0) $where .= " AND d.id = $filter_dept";
if (in_array($filter_type, ['regular', 'contract_of_service'], true)) $where .= " AND u.employment_type = '" . $conn->real_escape_string($filter_type) . "'";

$faculty = $conn->query("SELECT u.id, u.full_name, u.username, u.employment_type, d.code AS dept_code, d.name AS dept_name, cs.id AS submission_id, cs.file_name, cs.original_name, cs.submitted_at FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN clearance_submissions cs ON cs.faculty_id = u.id $where ORDER BY d.code, u.employment_type, u.full_name")->fetch_all(MYSQLI_ASSOC);
$departments = $conn->query('SELECT * FROM departments ORDER BY code')->fetch_all(MYSQLI_ASSOC);
$labels = ['regular' => 'Regular', 'contract_of_service' => 'Contract of Service'];
$grouped = [];
foreach ($faculty as $row) $grouped[$row['dept_code'] ?: 'No Department'][] = $row;
$total_submitted = count(array_filter($faculty, fn($row) => !empty($row['submission_id'])));
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Clearance Submission — CollTrace</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.6/dist/docx-preview.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Inter','Segoe UI',Roboto,Arial,sans-serif;background:#f4f5f7;color:#1c1917}.header{background:linear-gradient(120deg,#1c1917,#0d0b0a);border-bottom:2px solid #f97316;color:#fff;padding:0 30px;height:60px;display:flex;align-items:center;justify-content:space-between}.brand{display:flex;align-items:center;gap:10px;font-size:20px;font-weight:800}.brand-logo{width:28px;height:28px;object-fit:contain}.brand span{color:#f97316}.header-right{display:flex;align-items:center;gap:12px}.btn-back,.btn-logout{color:#fff;padding:6px 14px;border-radius:6px;font-size:13px;text-decoration:none}.btn-back{background:rgba(255,255,255,.15)}.btn-logout{border:1px solid rgba(255,255,255,.4)}.container{max-width:1080px;margin:0 auto;padding:32px 20px}.title{font-size:24px;font-weight:800;margin:0 0 14px}.sub{color:#78716c;font-size:14px;margin-bottom:22px}.summary{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}.stat{display:block;text-align:left;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 20px;box-shadow:0 2px 8px rgba(0,0,0,.06);min-width:170px;color:inherit;font:inherit;cursor:pointer;transition:border-color .15s,box-shadow .15s,transform .15s}.stat:hover,.stat[aria-pressed="true"]{border-color:#f97316;box-shadow:0 4px 14px rgba(249,115,22,.14);transform:translateY(-1px)}.stat:focus-visible{outline:3px solid rgba(249,115,22,.28);outline-offset:2px}.stat strong{display:block;font-size:22px}.stat span{font-size:12px;color:#78716c}.filters{background:#fff;padding:14px 16px;border-radius:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:20px}.filters select,.filters button{padding:8px 11px;border:1px solid #d6d3d1;border-radius:7px;font:inherit;font-size:13px}.filters button{background:#1c1917;color:#fff;cursor:pointer}.clear{font-size:13px;color:#78716c;text-decoration:none}.dept{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.07);margin-bottom:18px;overflow:hidden}.dept-head{background:#231f1b;color:#fff;padding:14px 18px;display:flex;justify-content:space-between;align-items:center}.dept-head h2{font-size:16px}.dept-head span{font-size:12px;color:#d6d3d1}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:13px}th{background:#fafaf9;color:#78716c;text-align:left;font-size:11px;text-transform:uppercase;padding:10px 14px}td{padding:12px 14px;border-top:1px solid #eee} .type{display:inline-block;border-radius:14px;padding:3px 8px;font-size:10px;font-weight:800}.regular{background:#dcfce7;color:#166534}.contract_of_service{background:#fef3c7;color:#92400e}.submitted{color:#15803d;font-weight:800}.pending{color:#b91c1c;font-weight:800}.view{color:#c2410c;text-decoration:none;font-weight:700}.empty{background:#fff;padding:36px;text-align:center;color:#78716c;border-radius:10px}.filter-empty{display:none;margin-bottom:18px;border:1px dashed #d6d3d1;border-radius:10px;background:#fff;padding:26px;text-align:center;color:#78716c}.filter-empty.visible{display:block}@media(max-width:650px){.header{padding:0 16px}.header .btn-back{display:none}.container{padding:24px 14px}.stat{flex:1;min-width:145px}}
</style>
<style>
    .role-badge { background:#F97316; color:#fff; padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700; }
    .breadcrumb { font-size:13px; color:#6b7280; margin-bottom:6px; }
    .breadcrumb a { color:#F97316; text-decoration:none; }
    .title { color:#1e2235; }
    .summary .stat { border:1px solid #e5e7eb; border-radius:12px; }
    .filters { border:1px solid #e5e7eb; box-shadow:0 2px 10px rgba(0,0,0,.05); }
    .dept { border:1px solid #e5e7eb; }
    .dept-head { background:linear-gradient(135deg,#1C1917,#231F1B); }
    .empty { border:1px solid #e5e7eb; }
    @media (max-width:650px) { .header { padding:0 16px; } }
    .page-design-match { font-family:'Inter',sans-serif; }
    body { font-family:'Inter',sans-serif; background:#FFF7ED; color:#1e2235; font-size:14px; }
    .header { height:62px; padding:0 28px; border-bottom:2px solid #F97316; box-shadow:0 2px 16px rgba(0,0,0,.35); }
    .brand { font-size:22px; }
    .brand-logo { width:30px; height:30px; }
    .btn-back,.btn-logout { padding:7px 16px; border-radius:8px; font-size:13px; }
    .container { max-width:1080px; padding:28px 20px; }
    .title { font-size:22px; color:#1e2235; margin-bottom:14px; }
    .sub { color:#6b7280; font-size:13px; margin-bottom:24px; }
    .stat { border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.07); }
    .stat strong { font-size:22px; color:#1e2235; }
    .stat span { color:#6b7280; }
    .filters { border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.05); }
    .filters button { background:#F97316; border-color:#F97316; color:#fff; font-weight:700; }
    .filters button:hover { background:#EA580C; }
    .dept { border:1px solid #e5e7eb; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.07); }
    .dept-head { background:linear-gradient(135deg,#F97316,#EA580C); padding:14px 18px; }
    th { background:#FFF7ED; color:#6b7280; font-size:11px; }
    td { font-size:13px; }
    .empty { border:1px solid #e5e7eb; border-radius:12px; }
    @media (max-width:650px) { .header { padding:0 16px; } .container { padding:24px 14px; } }
    body { background:#FFF7ED; color:#1e2235; }
    .container { max-width:1180px; padding:32px 24px 48px; }
    .title { color:#1e2235; font-size:22px; letter-spacing:0; }
    .sub { color:#6b7280; }
    .stat, .filters, .dept { border:1px solid #e5e7eb; background:#fff; }
    .stat { border-radius:12px; padding:16px 20px; box-shadow:0 2px 12px rgba(0,0,0,.07); }
    .stat strong { color:#1e2235; }
    .filters { border-radius:12px; padding:18px 20px; box-shadow:0 2px 10px rgba(0,0,0,.05); }
    .filters select, .filters button { border-color:#e5e7eb; border-radius:8px; }
    .filters button { background:#F97316; border-color:#F97316; font-weight:700; }
    .filters button:hover { background:#EA580C; }
    .clear { color:#6b7280; }
    .dept { border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.07); }
    .dept-head { background:linear-gradient(135deg,#1C1917,#231F1B); border-bottom:2px solid #F97316; padding:16px 20px; }
    .dept-head h2 { font-size:16px; }
    .dept-head span { color:#c4b9b0; }
    table { font-size:14px; }
    th { background:#FFF7ED; color:#6b7280; padding:13px 18px; font-size:11px; }
    td { padding:14px 18px; border-top:1px solid #f1f1f1; color:#1e2235; }
    tr:hover td { background:#fffaf5; }
    .type { border-radius:20px; padding:4px 10px; font-size:11px; font-weight:700; }
    .type.regular { background:#edfff3; color:#166534; }
    .type.contract_of_service { background:#fff8e1; color:#a16207; }
    td.submitted { color:#166534; font-weight:700; }
    td.pending { color:#a16207; font-weight:700; }
    .view { color:#EA580C; font-weight:700; text-decoration:none; }
    .view:hover { color:#C2410C; text-decoration:underline; }
    .empty { border:1px solid #e5e7eb; border-radius:12px; background:#fff; color:#6b7280; padding:40px; }
    .preview-overlay { position:fixed; inset:0; z-index:1000; display:none; align-items:center; justify-content:center; padding:24px; background:rgba(12,10,9,.72); }
    .preview-overlay.open { display:flex; }
    .preview-dialog { width:min(1000px, 100%); height:min(850px, 92vh); display:flex; flex-direction:column; overflow:hidden; background:#fff; border-radius:8px; box-shadow:0 20px 60px rgba(0,0,0,.35); }
    .preview-header { min-height:58px; display:flex; align-items:center; justify-content:space-between; gap:16px; padding:10px 16px; background:#1c1917; color:#fff; }
    .preview-header h2 { min-width:0; font-size:15px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .preview-actions { display:flex; align-items:center; gap:14px; flex:none; }
    .preview-download { color:#fff; font-size:13px; font-weight:600; }
    .preview-close { width:34px; height:34px; border:0; border-radius:5px; background:rgba(255,255,255,.14); color:#fff; font-size:22px; cursor:pointer; }
    .preview-body { flex:1; min-height:0; overflow:auto; position:relative; background:#e7e5e4; }
    .preview-body iframe { width:100%; height:100%; min-height:400px; display:block; border:0; background:#fff; }
    .preview-loading, .preview-error { padding:30px; text-align:center; color:#57534e; }
    .preview-body .docx-wrapper { min-height:100%; }
    @media (max-width:600px) { .preview-overlay { padding:8px; } .preview-dialog { height:94vh; } .preview-header { padding:9px; } .preview-actions { gap:9px; } .preview-download { font-size:12px; } }
</style></head><body>
<div class="header"><div style="display:flex;align-items:center;gap:16px"><a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" class="brand-logo" alt="CEIT Logo"><div>Coll<span>Trace</span></div></a><a href="dashboard.php" class="btn-back">← Dashboard</a></div><div class="header-right"><span class="role-badge">ADMIN</span><a href="../logout.php" class="btn-logout">Logout</a></div></div>
<div class="container"><div class="breadcrumb"><a href="dashboard.php">Dashboard</a> › Clearance Submission</div><div class="title">Clearance Submission</div><div class="sub">Faculty clearance submissions grouped by department and employment status.</div>
<div class="summary" role="group" aria-label="Filter clearance submissions"><button type="button" class="stat" data-clearance-filter="all" aria-pressed="true"><strong><?= count($faculty) ?></strong><span>Active Faculty</span></button><button type="button" class="stat" data-clearance-filter="submitted" aria-pressed="false"><strong><?= $total_submitted ?></strong><span>Submitted</span></button><button type="button" class="stat" data-clearance-filter="pending" aria-pressed="false"><strong><?= count($faculty) - $total_submitted ?></strong><span>Pending</span></button></div>
<form method="GET" class="filters"><strong style="font-size:13px">Filter:</strong><select name="dept"><option value="">All Departments</option><?php foreach ($departments as $department): ?><option value="<?= $department['id'] ?>" <?= $filter_dept === (int)$department['id'] ? 'selected' : '' ?>><?= sanitize($department['code']) ?></option><?php endforeach; ?></select><select name="type"><option value="">All Faculty Status</option><option value="regular" <?= $filter_type === 'regular' ? 'selected' : '' ?>>Regular</option><option value="contract_of_service" <?= $filter_type === 'contract_of_service' ? 'selected' : '' ?>>Contract of Service</option></select><button type="submit">Apply</button><a class="clear" href="clearance_submissions.php">Clear</a></form>
<?php if (!$grouped): ?><div class="empty">No active faculty found for this filter.</div><?php endif; ?>
<div class="filter-empty" id="clearanceFilterEmpty" role="status" aria-live="polite">No faculty match this clearance status.</div>
<?php foreach ($grouped as $department => $rows): ?><section class="dept"><div class="dept-head"><h2><?= sanitize($department) ?></h2><span><?= count($rows) ?> faculty</span></div><div class="table-wrap"><table><thead><tr><th>Faculty</th><th>Employment Status</th><th>Submission</th><th>Date</th><th>File</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr data-clearance-row="<?= $row['submission_id'] ? 'submitted' : 'pending' ?>"><td><strong><?= sanitize($row['full_name']) ?></strong><br><span style="color:#a8a29e">@<?= sanitize($row['username']) ?></span></td><td><span class="type <?= sanitize($row['employment_type'] ?: '') ?>"><?= $labels[$row['employment_type']] ?? 'Status not set' ?></span></td><td class="<?= $row['submission_id'] ? 'submitted' : 'pending' ?>"><?= $row['submission_id'] ? 'Submitted' : 'Pending' ?></td><td><?= $row['submitted_at'] ? date('M d, Y g:i A', strtotime($row['submitted_at'])) : '—' ?></td><td><?php if ($row['submission_id']): $extension = strtolower(pathinfo($row['file_name'], PATHINFO_EXTENSION)); ?><?php if (in_array($extension, ['pdf', 'docx'], true)): ?><a class="view" href="#" onclick="openAdminSubmissionPreview(<?= (int) $row['submission_id'] ?>, <?= htmlspecialchars(json_encode($row['original_name']), ENT_QUOTES, 'UTF-8') ?>, '<?= sanitize($extension) ?>'); return false;">View file</a><?php else: ?><a class="view" href="<?= BASE_URL ?>/admin/clearance_submissions.php?view=<?= (int) $row['submission_id'] ?>&amp;download=1" download>Download file</a><?php endif; ?><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endforeach; ?>
</div>
<div class="preview-overlay" id="adminSubmissionPreview" onclick="closeAdminSubmissionPreview(event)" aria-hidden="true">
    <section class="preview-dialog" role="dialog" aria-modal="true" aria-labelledby="adminPreviewTitle">
        <div class="preview-header">
            <h2 id="adminPreviewTitle">Clearance submission preview</h2>
            <div class="preview-actions">
                <a class="preview-download" id="adminPreviewDownload" href="#" download>Download file</a>
                <button type="button" class="preview-close" aria-label="Close preview" onclick="closeAdminSubmissionPreview()">&times;</button>
            </div>
        </div>
        <div class="preview-body" id="adminPreviewBody"></div>
    </section>
</div>
<script>
document.querySelectorAll('[data-clearance-filter]').forEach((button) => {
    button.addEventListener('click', () => {
        const filter = button.dataset.clearanceFilter;
        let visibleCount = 0;
        document.querySelectorAll('[data-clearance-row]').forEach((row) => {
            const visible = filter === 'all' || row.dataset.clearanceRow === filter;
            row.hidden = !visible;
            if (visible) visibleCount++;
        });
        document.querySelectorAll('.dept').forEach((department) => {
            department.hidden = !department.querySelector('[data-clearance-row]:not([hidden])');
        });
        document.querySelectorAll('[data-clearance-filter]').forEach((filterButton) => {
            filterButton.setAttribute('aria-pressed', String(filterButton === button));
        });
        document.getElementById('clearanceFilterEmpty').classList.toggle('visible', visibleCount === 0);
    });
});

function openAdminSubmissionPreview(submissionId, fileName, extension) {
    const modal = document.getElementById('adminSubmissionPreview');
    const body = document.getElementById('adminPreviewBody');
    const fileUrl = '<?= BASE_URL ?>/admin/clearance_submissions.php?view=' + submissionId;
    const downloadLink = document.getElementById('adminPreviewDownload');

    document.getElementById('adminPreviewTitle').textContent = fileName;
    downloadLink.href = fileUrl + '&download=1';
    downloadLink.download = fileName;
    body.innerHTML = '<div class="preview-loading">Loading preview...</div>';
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    if (extension === 'pdf') {
        const frame = document.createElement('iframe');
        frame.title = fileName;
        frame.src = fileUrl;
        frame.onload = () => body.querySelector('.preview-loading')?.remove();
        body.appendChild(frame);
        return;
    }

    fetch(fileUrl + '&raw=1')
        .then(response => {
            if (!response.ok) throw new Error('Preview unavailable');
            return response.arrayBuffer();
        })
        .then(documentData => {
            body.innerHTML = '';
            return window.docx.renderAsync(documentData, body);
        })
        .catch(() => {
            body.innerHTML = '<div class="preview-error">Could not load the Word preview. Use Download file to open it.</div>';
        });
}

function closeAdminSubmissionPreview(event) {
    const modal = document.getElementById('adminSubmissionPreview');
    if (event && event.target !== modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('adminPreviewBody').innerHTML = '';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeAdminSubmissionPreview();
});
</script>
</body></html>
