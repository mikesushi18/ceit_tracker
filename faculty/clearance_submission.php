<?php
// faculty/clearance_submission.php
require_once '../includes/config.php';
requireRole('faculty');

$user_id = $_SESSION['user_id'];
$user = $conn->query("SELECT u.*, d.code AS dept_code, d.name AS dept_name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = $user_id")->fetch_assoc();
$form_types = ['regular' => 'Regular', 'contract_of_service' => 'Contract of Service'];
$form_type = $user['employment_type'] ?? '';
$clearance_dir = __DIR__ . '/../uploads/clearance_submissions/';
if (!objectStorageConfigured() && !is_dir($clearance_dir)) mkdir($clearance_dir, 0755, true);

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
    $stmt = $conn->prepare('SELECT file_name, original_name FROM clearance_submissions WHERE faculty_id = ?');
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $view_file = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$view_file) {
        http_response_code(404);
        exit('Submission not found.');
    }

    $file_path = $clearance_dir . basename($view_file['file_name']);
    if (!storageExists($file_path)) {
        http_response_code(404);
        exit('Submission file not found.');
    }

    $extension = strtolower(pathinfo($view_file['file_name'], PATHINFO_EXTENSION));
    $mime_types = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    $mime = $mime_types[$extension] ?? 'application/octet-stream';
    $force_download = ($_GET['download'] ?? '') === '1'
        || !in_array($extension, ['pdf', 'docx'], true)
        || ($extension === 'docx' && ($_GET['raw'] ?? '') !== '1');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($force_download ? 'attachment' : 'inline') . '; filename="' . addslashes($view_file['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . storageSize($file_path));
    $fileBytes = storageRead($file_path);
    if ($fileBytes === false) { http_response_code(404); exit('Submission file not found.'); }
    echo $fileBytes;
    exit;
}

$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = $_FILES['clearance_file'] ?? null;
    $allowed_extensions = ['pdf', 'doc', 'docx'];
    $max_size = 10 * 1024 * 1024;
    if (!in_array($form_type, array_keys($form_types), true)) {
        $err = 'Your faculty employment status is not set. Please contact the administrator.';
    } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $err = 'Please choose a file to submit.';
    } else {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed_mimes = ['pdf' => ['application/pdf'], 'doc' => ['application/msword', 'application/octet-stream'], 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']];
        if (!in_array($extension, $allowed_extensions, true) || !in_array($mime, $allowed_mimes[$extension], true)) {
            $err = 'Only PDF, DOC, and DOCX files are allowed.';
        } elseif ($file['size'] > $max_size) {
            $err = 'The file must be 10MB or smaller.';
        } else {
            $stored_name = 'faculty_' . $user_id . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
            $destination = $clearance_dir . $stored_name;
            $old = $conn->query("SELECT file_name FROM clearance_submissions WHERE faculty_id = $user_id")->fetch_assoc();
            if (!storageMoveUploadedFile($file['tmp_name'], $destination)) {
                $err = 'The file could not be saved.';
            } else {
                $stmt = $conn->prepare('INSERT INTO clearance_submissions (faculty_id, form_type, file_name, original_name) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE form_type = VALUES(form_type), file_name = VALUES(file_name), original_name = VALUES(original_name), submitted_at = CURRENT_TIMESTAMP');
                $stmt->bind_param('isss', $user_id, $form_type, $stored_name, $file['name']);
                if ($stmt->execute()) {
                    if ($old && storageExists($clearance_dir . basename($old['file_name']))) storageDelete($clearance_dir . basename($old['file_name']));
                    logActivity($conn, $user_id, 'SUBMIT_CLEARANCE', 'Submitted clearance form');
                    $msg = 'Clearance form submitted successfully.';
                } else {
                    storageDelete($destination);
                    $err = 'The clearance form could not be saved.';
                }
                $stmt->close();
            }
        }
    }
}

$submission = $conn->query("SELECT * FROM clearance_submissions WHERE faculty_id = $user_id")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Clearance Submission — CollTrace</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.6/dist/docx-preview.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font-family:'Inter','Segoe UI',Roboto,Arial,sans-serif;background:#f4f5f7;color:#1c1917}.header{background:linear-gradient(120deg,#1c1917,#0d0b0a);border-bottom:2px solid #f97316;color:#fff;padding:0 30px;height:60px;display:flex;align-items:center;justify-content:space-between}.brand{display:flex;align-items:center;gap:10px;font-size:20px;font-weight:800}.brand-logo{width:28px;height:28px;object-fit:contain}.brand span{color:#f97316}.header-right{display:flex;align-items:center;gap:12px}.btn-back,.btn-logout{color:#fff;padding:6px 14px;border-radius:6px;font-size:13px;text-decoration:none}.btn-back{background:rgba(255,255,255,.15)}.btn-logout{border:1px solid rgba(255,255,255,.4)}.container{max-width:720px;margin:0 auto;padding:34px 20px}.title{font-size:24px;font-weight:800;margin-bottom:6px}.sub{color:#78716c;font-size:14px;margin-bottom:22px}.card{background:#fff;border-radius:12px;padding:26px;box-shadow:0 2px 10px rgba(0,0,0,.07)}.status{padding:12px 14px;border-radius:8px;margin-bottom:18px;font-size:14px}.ok{background:#edfff3;border:1px solid #a3e6b3;color:#166534}.err{background:#fff3f3;border:1px solid #ffb3b3;color:#b91c1c}.meta{font-size:13px;color:#78716c;margin-bottom:18px}.meta strong{color:#1c1917}.file{width:100%;padding:12px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#f8fafc;margin-bottom:14px}.submit{width:100%;padding:11px;border:0;border-radius:8px;background:#f97316;color:#fff;font-size:14px;font-weight:700;cursor:pointer}.submit:hover{background:#ea580c}.current{margin-top:18px;border-top:1px solid #eee;padding-top:15px;font-size:13px;color:#57534e}.current a{color:#c2410c;font-weight:700;text-decoration:none}.notice{margin-top:16px;padding:12px;background:#fff8e1;border:1px solid #f0d060;border-radius:8px;color:#7a5c00;font-size:13px}@media(max-width:600px){.header{padding:0 16px}.header-right .btn-back{display:none}.container{padding:24px 14px}}
</style>
<style>
    .header { height:62px; padding:0 28px; box-shadow:0 2px 16px rgba(0,0,0,.35); }
    .btn-back { border:1px solid rgba(255,255,255,.2); }
    .container { max-width:860px; padding:28px 20px; }
    .breadcrumb { font-size:13px; color:#6b7280; margin-bottom:6px; }
    .breadcrumb a { color:#F97316; text-decoration:none; }
    .title { font-size:22px; color:#1e2235; }
    .card { border:1px solid #e5e7eb; border-radius:14px; box-shadow:0 2px 12px rgba(0,0,0,.07); }
    .submit { background:#F97316; }
    .submit:hover { background:#EA580C; }
</style>
<style>
    body { font-family:'Inter',sans-serif; background:#FFF7ED; color:#1e2235; font-size:14px; }
    .header { height:62px; padding:0 28px; background:#1C1917; border-bottom:2px solid #F97316; box-shadow:0 2px 16px rgba(0,0,0,.35); }
    .brand { font-size:22px; }
    .brand-logo { width:30px; height:30px; }
    .btn-back,.btn-logout { padding:7px 16px; border-radius:8px; font-size:13px; }
    .btn-back { background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2); }
    .btn-logout { border:1px solid rgba(255,255,255,.35); }
    .container { max-width:760px; padding:28px 20px; }
    .title { font-size:22px; color:#1e2235; margin-bottom:5px; }
    .sub { color:#6b7280; font-size:13px; margin-bottom:24px; }
    .card { border:1px solid #e5e7eb; border-top:3px solid #F97316; border-radius:14px; padding:26px; box-shadow:0 2px 12px rgba(0,0,0,.07); }
    .status { padding:12px 16px; border-radius:8px; margin-bottom:18px; font-size:14px; }
    .meta { font-size:13px; color:#6b7280; margin-bottom:18px; line-height:1.6; }
    .meta strong { color:#1e2235; }
    .file { padding:12px; border:1.5px dashed #cbd5e1; border-radius:8px; background:#fafbfc; font-size:13px; margin-bottom:14px; }
    .submit { padding:11px 16px; border-radius:8px; background:#F97316 !important; color:#fff; font-size:14px; font-weight:700; }
    .current { margin-top:18px; padding-top:14px; border-top:1px solid #e5e7eb; font-size:13px; color:#6b7280; }
    .current strong { color:#1e2235; }
    .current a { color:#c2410c; }
    .notice { margin-top:16px; padding:14px 16px; border-radius:9px; background:#fff8e1; border:1px solid #f0d060; color:#7a5c00; font-size:13px; line-height:1.5; }
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
    @media (max-width:650px) {
        .header { padding:0 16px; }
        .header-right .btn-back { display:none; }
        .container { padding:24px 14px; }
        .card { padding:22px 18px; }
        .preview-overlay { padding:8px; }
        .preview-dialog { height:94vh; }
        .preview-header { padding:9px; }
        .preview-actions { gap:9px; }
        .preview-download { font-size:12px; }
    }
</style>
</head>
<body>
<div class="header"><div style="display:flex;align-items:center;gap:16px"><a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" class="brand-logo" alt="CEIT Logo"><div>Coll<span>Trace</span></div></a><a href="dashboard.php" class="btn-back">← Dashboard</a></div><div class="header-right"><a href="../logout.php" class="btn-logout">Logout</a></div></div>
<div class="container"><div class="breadcrumb"><a href="dashboard.php">Dashboard</a> › Clearance Submission</div><div class="title">Clearance Submission</div><div class="sub">Submit your clearance form for administrator review.</div><div class="card">
<?php if ($msg): ?><div class="status ok"><?= sanitize($msg) ?></div><?php endif; ?><?php if ($err): ?><div class="status err"><?= sanitize($err) ?></div><?php endif; ?>
<div class="meta"><strong>Department:</strong> <?= sanitize($user['dept_code'] ?? '—') ?><br><strong>Faculty status:</strong> <?= $form_types[$form_type] ?? 'Not set' ?></div>
<?php if (isset($form_types[$form_type])): ?>
<form method="POST" enctype="multipart/form-data" onsubmit="<?= $submission ? "return confirm('Replace your submitted clearance form?')" : 'return true' ?>"><input type="file" name="clearance_file" class="file" accept=".pdf,.doc,.docx" required><button class="submit" type="submit"><?= $submission ? 'Replace Clearance Form' : 'Submit Clearance Form' ?></button></form>
<?php if ($submission): ?><div class="current"><strong>Submitted file:</strong> <?= sanitize($submission['original_name']) ?><br><?= date('M d, Y g:i A', strtotime($submission['submitted_at'])) ?><br>
<?php $submission_extension = strtolower(pathinfo($submission['file_name'], PATHINFO_EXTENSION)); ?>
<?php if (in_array($submission_extension, ['pdf', 'docx'], true)): ?>
<a href="#" onclick="openSubmissionPreview(<?= htmlspecialchars(json_encode($submission['original_name']), ENT_QUOTES, 'UTF-8') ?>, '<?= sanitize($submission_extension) ?>'); return false;">View submitted file</a>
<?php else: ?>
<a href="<?= BASE_URL ?>/faculty/clearance_submission.php?view=1&amp;download=1" download>Download submitted file</a>
<?php endif; ?></div><?php endif; ?>
<?php else: ?><div class="notice">Your account does not have a Regular or Contract of Service status. Please contact the administrator.</div><?php endif; ?>
</div></div>
<div class="preview-overlay" id="submissionPreview" onclick="closeSubmissionPreview(event)" aria-hidden="true">
    <section class="preview-dialog" role="dialog" aria-modal="true" aria-labelledby="submissionPreviewTitle">
        <div class="preview-header">
            <h2 id="submissionPreviewTitle">Clearance submission preview</h2>
            <div class="preview-actions">
                <a class="preview-download" id="submissionPreviewDownload" href="#" download>Download file</a>
                <button type="button" class="preview-close" aria-label="Close preview" onclick="closeSubmissionPreview()">&times;</button>
            </div>
        </div>
        <div class="preview-body" id="submissionPreviewBody"></div>
    </section>
</div>
<script>
function openSubmissionPreview(fileName, extension) {
    const modal = document.getElementById('submissionPreview');
    const body = document.getElementById('submissionPreviewBody');
    const fileUrl = '<?= BASE_URL ?>/faculty/clearance_submission.php?view=1';
    const downloadLink = document.getElementById('submissionPreviewDownload');

    document.getElementById('submissionPreviewTitle').textContent = fileName;
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

function closeSubmissionPreview(event) {
    const modal = document.getElementById('submissionPreview');
    if (event && event.target !== modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('submissionPreviewBody').innerHTML = '';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeSubmissionPreview();
});
</script>
</body></html>
