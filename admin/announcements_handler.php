<?php
// admin/announcements_handler.php
require_once '../includes/config.php';
requireAdminDashboardAccess();

header('Content-Type: application/json');

// Ensure announcements table exists + new attachment columns
$conn->query("CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    posted_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (posted_by) REFERENCES users(id)
)");
$conn->query("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS image_file VARCHAR(255) NULL");
$conn->query("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS pdf_file   VARCHAR(255) NULL");
$conn->query("ALTER TABLE announcements ADD COLUMN IF NOT EXISTS pdf_original_name VARCHAR(255) NULL");
$conn->query("CREATE TABLE IF NOT EXISTS announcement_edits (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT NOT NULL,
    edited_by INT NULL,
    edited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    previous_title VARCHAR(200) NOT NULL,
    previous_body TEXT NOT NULL,
    previous_image_file VARCHAR(255) NULL,
    previous_pdf_file VARCHAR(255) NULL,
    previous_pdf_original_name VARCHAR(255) NULL,
    INDEX idx_announcement_edits_announcement (announcement_id, edited_at),
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
    FOREIGN KEY (edited_by) REFERENCES users(id) ON DELETE SET NULL
)");

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Helpers ──────────────────────────────────────────────────────────────────
function uploadAnnFile(string $field, array $allowed_exts, string $subdir): array {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['file' => null, 'original_name' => null, 'err' => null]; // nothing selected — OK
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $codes = [1=>'File too large (server limit)',2=>'File too large (form limit)',3=>'Partial upload',4=>'No file',6=>'No temp dir',7=>'Cannot write to disk',8=>'Extension stopped upload'];
        return ['file' => null, 'original_name' => null, 'err' => $codes[$file['error']] ?? 'Upload error ' . $file['error']];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts)) {
        return ['file' => null, 'original_name' => null, 'err' => 'Invalid file type .' . $ext . '. Allowed: ' . implode(', ', $allowed_exts)];
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['file' => null, 'original_name' => null, 'err' => 'File exceeds 10 MB limit.'];
    }
    $dir = dirname(__DIR__) . '/uploads/' . $subdir . '/';
    $name = uniqid('ann_') . '_' . time() . '.' . $ext;
    $dest = $dir . $name;
    if (!storageMoveUploadedFile($file['tmp_name'], $dest)) {
        return ['file' => null, 'original_name' => null, 'err' => 'Could not save file. Check server write permissions for uploads/' . $subdir . '/'];
    }
    $original_name = basename(str_replace('\\', '/', (string) $file['name']));
    return ['file' => $name, 'original_name' => $original_name, 'err' => null];
}

// ── CREATE ───────────────────────────────────────────────────────────────────
if ($action === 'create') {
    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body']  ?? '');
    if (!$title || !$body) {
        echo json_encode(['success' => false, 'error' => 'Title and body required.']);
        exit;
    }

    $uid      = $_SESSION['user_id'];
    $img_res  = uploadAnnFile('image_file', ['jpg','jpeg','png','gif','webp'], 'announcements');
    $pdf_res  = uploadAnnFile('pdf_file',   ['pdf'], 'announcements');
    $img_name = $img_res['file'];
    $pdf_name = $pdf_res['file'];
    $pdf_original_name = $pdf_res['original_name'];

    // Collect non-fatal upload warnings
    $warnings = array_filter([$img_res['err'], $pdf_res['err']]);

    $stmt = $conn->prepare("INSERT INTO announcements (title, body, posted_by, image_file, pdf_file, pdf_original_name) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssisss', $title, $body, $uid, $img_name, $pdf_name, $pdf_original_name);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();

    logActivity($conn, $uid, 'post_announcement', "Posted: $title");

    $base = BASE_URL . '/uploads/announcements/';
    $author = $conn->query("SELECT full_name, profile_photo FROM users WHERE id = $uid")->fetch_assoc();
    $author_photo_path = dirname(__DIR__) . '/uploads/avatars/' . ($author['profile_photo'] ?? '');
    $author_photo_url = !empty($author['profile_photo']) && storageExists($author_photo_path)
        ? BASE_URL . '/uploads/avatars/' . $author['profile_photo'] . '?v=' . (storageModified($author_photo_path) ?: 0)
        : null;
    echo json_encode([
        'success'    => true,
        'id'         => $id,
        'image_url'  => $img_name ? $base . $img_name : null,
        'pdf_url'    => $pdf_name ? $base . $pdf_name : null,
        'pdf_name'   => $pdf_name ? basename($pdf_name) : null,
        'pdf_original_name' => $pdf_original_name,
        'author_photo_url' => $author_photo_url,
        'warnings'   => array_values($warnings),
    ]);

// ── UPDATE ───────────────────────────────────────────────────────────────────
} elseif ($action === 'update') {
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    if ($id < 1 || !$title || !$body) {
        echo json_encode(['success' => false, 'error' => 'Title and body required.']);
        exit;
    }

    $row = $conn->query("SELECT title, body, image_file, pdf_file, pdf_original_name FROM announcements WHERE id = $id LIMIT 1")->fetch_assoc();
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Announcement not found.']);
        exit;
    }

    $img_res = uploadAnnFile('image_file', ['jpg','jpeg','png','gif','webp'], 'announcements');
    $pdf_res = uploadAnnFile('pdf_file', ['pdf'], 'announcements');
    $upload_errors = array_filter([$img_res['err'], $pdf_res['err']]);
    if ($upload_errors) {
        foreach ([$img_res['file'], $pdf_res['file']] as $uploaded_file) {
            if ($uploaded_file && storageExists(dirname(__DIR__) . '/uploads/announcements/' . $uploaded_file)) {
                storageDelete(dirname(__DIR__) . '/uploads/announcements/' . $uploaded_file);
            }
        }
        echo json_encode(['success' => false, 'error' => implode(' ', $upload_errors)]);
        exit;
    }

    $remove_image = !empty($_POST['remove_image']);
    $remove_pdf = !empty($_POST['remove_pdf']);
    $image_name = $img_res['file'] ?? ($remove_image ? null : $row['image_file']);
    $pdf_name = $pdf_res['file'] ?? ($remove_pdf ? null : $row['pdf_file']);
    $pdf_original_name = $pdf_res['original_name'] ?? ($remove_pdf ? null : $row['pdf_original_name']);
    $has_changes = $title !== $row['title']
        || $body !== $row['body']
        || $image_name !== $row['image_file']
        || $pdf_name !== $row['pdf_file']
        || $pdf_original_name !== $row['pdf_original_name'];

    $conn->begin_transaction();
    if ($has_changes) {
        $history_stmt = $conn->prepare("INSERT INTO announcement_edits (announcement_id, edited_by, previous_title, previous_body, previous_image_file, previous_pdf_file, previous_pdf_original_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$history_stmt) {
            $conn->rollback();
            foreach ([$img_res['file'], $pdf_res['file']] as $uploaded_file) {
                if ($uploaded_file && storageExists(dirname(__DIR__) . '/uploads/announcements/' . $uploaded_file)) {
                    storageDelete(dirname(__DIR__) . '/uploads/announcements/' . $uploaded_file);
                }
            }
            echo json_encode(['success' => false, 'error' => 'Could not save announcement history.']);
            exit;
        }
        $editor_id = (int) $_SESSION['user_id'];
        $history_stmt->bind_param('iisssss', $id, $editor_id, $row['title'], $row['body'], $row['image_file'], $row['pdf_file'], $row['pdf_original_name']);
        if (!$history_stmt->execute()) {
            $history_stmt->close();
            $conn->rollback();
            foreach ([$img_res['file'], $pdf_res['file']] as $uploaded_file) {
                if ($uploaded_file && storageExists(dirname(__DIR__) . '/uploads/announcements/' . $uploaded_file)) {
                    storageDelete(dirname(__DIR__) . '/uploads/announcements/' . $uploaded_file);
                }
            }
            echo json_encode(['success' => false, 'error' => 'Could not save announcement history.']);
            exit;
        }
        $history_stmt->close();
    }

    $stmt = $conn->prepare("UPDATE announcements SET title = ?, body = ?, image_file = ?, pdf_file = ?, pdf_original_name = ? WHERE id = ?");
    $stmt->bind_param('sssssi', $title, $body, $image_name, $pdf_name, $pdf_original_name, $id);
    if (!$stmt->execute()) {
        $stmt->close();
        $conn->rollback();
        foreach ([$img_res['file'], $pdf_res['file']] as $uploaded_file) {
            if ($uploaded_file && storageExists(dirname(__DIR__) . '/uploads/announcements/' . $uploaded_file)) {
                storageDelete(dirname(__DIR__) . '/uploads/announcements/' . $uploaded_file);
            }
        }
        echo json_encode(['success' => false, 'error' => 'Could not update announcement.']);
        exit;
    }
    $stmt->close();
    $conn->commit();

    logActivity($conn, $_SESSION['user_id'], 'edit_announcement', "Edited: $title");
    echo json_encode(['success' => true]);

// ── DELETE ───────────────────────────────────────────────────────────────────
} elseif ($action === 'delete') {
    $id  = intval($_POST['id'] ?? 0);
    $row = $conn->query("SELECT image_file, pdf_file FROM announcements WHERE id = $id")->fetch_assoc();
    if ($row) {
        $base_path = dirname(__DIR__) . '/uploads/announcements/';
        if ($row['image_file'] && storageExists($base_path . $row['image_file'])) storageDelete($base_path . $row['image_file']);
        if ($row['pdf_file']   && storageExists($base_path . $row['pdf_file']))   storageDelete($base_path . $row['pdf_file']);
        $history_rows = $conn->query("SELECT previous_image_file, previous_pdf_file FROM announcement_edits WHERE announcement_id = $id");
        if ($history_rows) {
            foreach ($history_rows->fetch_all(MYSQLI_ASSOC) as $history_row) {
                foreach ([$history_row['previous_image_file'], $history_row['previous_pdf_file']] as $historical_file) {
                    if ($historical_file && storageExists($base_path . $historical_file)) storageDelete($base_path . $historical_file);
                }
            }
        }
    }
    $conn->query("DELETE FROM announcements WHERE id = $id");
    logActivity($conn, $_SESSION['user_id'], 'delete_announcement', "Deleted ID: $id");
    echo json_encode(['success' => true]);

// ── LIST ─────────────────────────────────────────────────────────────────────
} elseif ($action === 'list') {
    $rows = $conn->query("
        SELECT a.*, u.full_name AS author, u.profile_photo AS author_photo
        FROM announcements a
        JOIN users u ON u.id = a.posted_by
        ORDER BY a.created_at DESC
        LIMIT 30
    ")->fetch_all(MYSQLI_ASSOC);

    $base = BASE_URL . '/uploads/announcements/';
    foreach ($rows as &$r) {
        $r['image_url'] = $r['image_file'] ? $base . $r['image_file'] : null;
        $r['pdf_url']   = $r['pdf_file']   ? $base . $r['pdf_file']   : null;
        $photo_path = dirname(__DIR__) . '/uploads/avatars/' . ($r['author_photo'] ?? '');
        $r['author_photo_url'] = !empty($r['author_photo']) && storageExists($photo_path)
            ? BASE_URL . '/uploads/avatars/' . $r['author_photo'] . '?v=' . (storageModified($photo_path) ?: 0)
            : null;
    }
    echo json_encode($rows);

} else {
    echo json_encode(['error' => 'Unknown action']);
}
