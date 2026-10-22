<?php
// admin/clearance_form.php
require_once '../includes/config.php';
requireAdminDashboardAccess();

$clearance_dir = __DIR__ . '/../uploads/clearance/';

if (isset($_GET['view'])) {
    $form_id = (int) $_GET['view'];
    $stmt = $conn->prepare('SELECT file_name, original_name FROM clearance_forms WHERE id = ?');
    $stmt->bind_param('i', $form_id);
    $stmt->execute();
    $form = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$form) {
        http_response_code(404);
        exit('Form not found.');
    }

    $file_path = $clearance_dir . basename($form['file_name']);
    if (!storageExists($file_path)) {
        http_response_code(404);
        exit('Form file not found.');
    }

    $extension = strtolower(pathinfo($form['file_name'], PATHINFO_EXTENSION));
    $mime_types = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    $mime = $mime_types[$extension] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    $force_download = ($_GET['download'] ?? '') === '1'
        || !in_array($extension, ['pdf', 'docx'], true)
        || ($extension === 'docx' && ($_GET['raw'] ?? '') !== '1');
    header('Content-Disposition: ' . ($force_download ? 'attachment' : 'inline') . '; filename="' . addslashes($form['original_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . storageSize($file_path));
    $fileBytes = storageRead($file_path);
    if ($fileBytes === false) { http_response_code(404); exit('Form file not found.'); }
    echo $fileBytes;
    exit;
}

if (!objectStorageConfigured() && !is_dir($clearance_dir)) {
    mkdir($clearance_dir, 0755, true);
}

$conn->query("CREATE TABLE IF NOT EXISTS clearance_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_type ENUM('regular', 'contract_of_service') NOT NULL UNIQUE,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    uploaded_by INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
)");

$msg = $err = '';
$form_types = [
    'regular' => 'Regular',
    'contract_of_service' => 'Contract of Service',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_clearance') {
    $form_type = $_POST['form_type'] ?? '';
    $file = $_FILES['clearance_file'] ?? null;
    $allowed_extensions = ['pdf', 'doc', 'docx'];
    $max_size = 10 * 1024 * 1024;

    if (!isset($form_types[$form_type])) {
        $err = 'Please select a valid clearance form type.';
    } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $err = 'Please choose a file to upload.';
    } else {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed_mimes = [
            'pdf' => ['application/pdf'],
            'doc' => ['application/msword', 'application/octet-stream'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        ];

        if (!in_array($extension, $allowed_extensions, true) || !in_array($mime, $allowed_mimes[$extension], true)) {
            $err = 'Only PDF, DOC, and DOCX files are allowed.';
        } elseif ($file['size'] > $max_size) {
            $err = 'The file must be 10MB or smaller.';
        } else {
            $stored_name = 'clearance_' . $form_type . '_' . bin2hex(random_bytes(12)) . '.' . $extension;
            $destination = $clearance_dir . $stored_name;
            $existing_stmt = $conn->prepare('SELECT file_name FROM clearance_forms WHERE form_type = ?');
            $existing_stmt->bind_param('s', $form_type);
            $existing_stmt->execute();
            $existing = $existing_stmt->get_result()->fetch_assoc();
            $existing_stmt->close();

            if (!storageMoveUploadedFile($file['tmp_name'], $destination)) {
                $err = 'The file could not be saved. Check the uploads folder permissions.';
            } else {
                $stmt = $conn->prepare(
                    'INSERT INTO clearance_forms (form_type, file_name, original_name, uploaded_by) VALUES (?, ?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), original_name = VALUES(original_name), uploaded_by = VALUES(uploaded_by), uploaded_at = CURRENT_TIMESTAMP'
                );
                $stmt->bind_param('sssi', $form_type, $stored_name, $file['name'], $_SESSION['user_id']);
                if ($stmt->execute()) {
                    if ($existing && $existing['file_name'] !== $stored_name) {
                        $old_path = $clearance_dir . basename($existing['file_name']);
                        if (storageExists($old_path)) {
                            storageDelete($old_path);
                        }
                    }
                    logActivity($conn, $_SESSION['user_id'], 'UPLOAD_CLEARANCE_FORM', "Uploaded {$form_types[$form_type]} clearance form");
                    $msg = $form_types[$form_type] . ' clearance form uploaded successfully.';
                } else {
                    storageDelete($destination);
                    $err = 'The clearance form could not be saved.';
                }
                $stmt->close();
            }
        }
    }
}

$forms = [];
$result = $conn->query('SELECT * FROM clearance_forms ORDER BY form_type');
while ($row = $result->fetch_assoc()) {
    $forms[$row['form_type']] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clearance Form — CollTrace</title>
    <script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/docx-preview@0.3.6/dist/docx-preview.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #f4f5f7; color: #1C1917; }
        .header { background: linear-gradient(120deg, #1C1917 0%, #0d0b0a 100%); border-bottom: 2px solid #F97316; color: #fff; padding: 0 30px; height: 60px; display: flex; align-items: center; justify-content: space-between; }
        .brand { display:flex; align-items:center; gap:10px; font-size:20px; font-weight:800; } .brand-logo { width:28px; height:28px; object-fit:contain; } .brand span { color:#F97316; }
        .header-right { display:flex; align-items:center; gap:12px; } .role-badge { background:#F97316; color:#fff; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
        a.btn-back, a.btn-logout { color:#fff; padding:6px 14px; border-radius:6px; font-size:13px; text-decoration:none; } a.btn-back { background:rgba(255,255,255,.15); } a.btn-logout { background:transparent; border:1px solid rgba(255,255,255,.4); }
        .container { max-width:900px; margin:0 auto; padding:32px 20px; } .title { font-size:24px; font-weight:800; margin-bottom:6px; } .subtitle { color:#78716c; font-size:14px; margin-bottom:24px; }
        .msg { padding:12px 16px; border-radius:8px; margin-bottom:18px; font-size:14px; } .success { background:#edfff3; border:1px solid #a3e6b3; color:#1a6b35; } .error { background:#fff3f3; border:1px solid #ffb3b3; color:#c0351a; }
        .grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:20px; } .card { background:#fff; border-radius:12px; padding:24px; box-shadow:0 2px 10px rgba(0,0,0,.07); }
        .card h2 { font-size:17px; margin-bottom:8px; } .card p { color:#78716c; font-size:13px; margin-bottom:18px; min-height:34px; }
        .file-input { width:100%; border:1.5px dashed #cbd5e1; border-radius:8px; padding:12px; background:#f8fafc; font-size:13px; margin-bottom:14px; }
        .btn-upload { width:100%; border:0; border-radius:8px; padding:11px 16px; background:#F97316; color:#fff; font-size:14px; font-weight:700; cursor:pointer; } .btn-upload:hover { background:#EA580C; }
        .current { margin-top:18px; padding-top:14px; border-top:1px solid #eee; font-size:13px; color:#57534e; } .current strong { display:block; color:#1C1917; margin-bottom:5px; overflow-wrap:anywhere; }
        .current-file-row { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
        .current-file-row strong { flex:1; min-width:0; }
        .btn-edit-file { flex:none; width:34px; height:34px; display:grid; place-items:center; border:1px solid #d6d3d1; border-radius:6px; background:#fff; color:#57534e; cursor:pointer; }
        .btn-edit-file:hover { background:#f5f5f4; color:#c2410c; }
        .btn-edit-file svg { width:16px; height:16px; }
        [hidden] { display:none !important; }
        .download { color:#c2410c; font-weight:700; text-decoration:none; } .hint { margin-top:22px; color:#78716c; font-size:12px; }
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
        @media (max-width:700px) { .grid { grid-template-columns:1fr; } .header { padding:0 16px; } .brand { font-size:17px; } .header-right .role-badge { display:none; } }
        @media (max-width:600px) { .preview-overlay { padding:8px; } .preview-dialog { height:94vh; } .preview-header { padding:9px; } .preview-actions { gap:9px; } .preview-download { font-size:12px; } }
    </style>
</head>
<body>
<div class="header">
    <div style="display:flex;align-items:center;gap:16px;">
        <a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><div>Coll<span>Trace</span></div></a>
        <a href="dashboard.php" class="btn-back">← Dashboard</a>
    </div>
    <div class="header-right"><span class="role-badge">ADMIN</span><a href="../logout.php" class="btn-logout">Logout</a></div>
</div>
<div class="container">
    <div class="title">Clearance Form</div>
    <div class="subtitle">Upload the current clearance form for each employment category.</div>
    <?php if ($msg): ?><div class="msg success"><?= sanitize($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="msg error"><?= sanitize($err) ?></div><?php endif; ?>
    <div class="grid">
        <?php foreach ($form_types as $type => $label): $form = $forms[$type] ?? null; ?>
        <div class="card">
            <h2><?= sanitize($label) ?></h2>
            <p>Upload a PDF, DOC, or DOCX clearance form for <?= sanitize($label) ?> employees.</p>
            <form method="POST" enctype="multipart/form-data" onsubmit="<?= $form ? "return confirm('Replace the current clearance form for this category?')" : 'return true' ?>">
                <input type="hidden" name="action" value="upload_clearance">
                <input type="hidden" name="form_type" value="<?= sanitize($type) ?>">
                <input type="file" name="clearance_file" class="file-input" accept=".pdf,.doc,.docx" required <?= $form ? 'hidden disabled' : '' ?>>
                <button type="submit" class="btn-upload" <?= $form ? 'hidden' : '' ?>><?= $form ? 'Replace Form' : 'Upload Form' ?></button>
            </form>
            <?php if ($form): ?>
            <div class="current">
                <div class="current-file-row">
                    <strong><?= sanitize($form['original_name']) ?></strong>
                    <button type="button" class="btn-edit-file" aria-label="Replace <?= sanitize($label) ?> clearance form" title="Replace file" onclick="enableFileReplace(this)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>
                    </button>
                </div>
                Uploaded <?= date('M d, Y g:i A', strtotime($form['uploaded_at'])) ?><br>
                <?php $extension = strtolower(pathinfo($form['file_name'], PATHINFO_EXTENSION)); ?>
                <?php if (in_array($extension, ['pdf', 'docx'], true)): ?>
                <a class="download" href="#" onclick="openClearancePreview(<?= (int) $form['id'] ?>, <?= htmlspecialchars(json_encode($form['original_name']), ENT_QUOTES, 'UTF-8') ?>, '<?= sanitize($extension) ?>'); return false;">View current form</a>
                <?php else: ?>
                <a class="download" href="<?= BASE_URL ?>/admin/clearance_form.php?view=<?= (int) $form['id'] ?>&amp;download=1" download>Download current form</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="current">No form uploaded yet.</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<div class="preview-overlay" id="clearancePreview" onclick="closeClearancePreview(event)" aria-hidden="true">
    <section class="preview-dialog" role="dialog" aria-modal="true" aria-labelledby="previewTitle">
        <div class="preview-header">
            <h2 id="previewTitle">Clearance form preview</h2>
            <div class="preview-actions">
                <a class="preview-download" id="previewDownload" href="#" download>Download file</a>
                <button type="button" class="preview-close" aria-label="Close preview" onclick="closeClearancePreview()">&times;</button>
            </div>
        </div>
        <div class="preview-body" id="previewBody"></div>
    </section>
</div>
<script>
function enableFileReplace(button) {
    const card = button.closest('.card');
    const fileInput = card.querySelector('input[type="file"]');
    const uploadButton = card.querySelector('.btn-upload');
    fileInput.disabled = false;
    fileInput.hidden = false;
    uploadButton.hidden = false;
    button.hidden = true;
    fileInput.focus();
}

function openClearancePreview(formId, fileName, extension) {
    const modal = document.getElementById('clearancePreview');
    const body = document.getElementById('previewBody');
    const fileUrl = '<?= BASE_URL ?>/admin/clearance_form.php?view=' + formId;
    const downloadLink = document.getElementById('previewDownload');

    document.getElementById('previewTitle').textContent = fileName;
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

function closeClearancePreview(event) {
    const modal = document.getElementById('clearancePreview');
    if (event && event.target !== modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('previewBody').innerHTML = '';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeClearancePreview();
});
</script>
</body>
</html>
