<?php
// chairperson/submission_status.php
require_once '../includes/config.php';
require_once '../includes/school_year_helper.php';
requireRole('chairperson');

$dept_id = $_SESSION['department_id'];
if (!$dept_id) { session_destroy(); redirect(BASE_URL . '/index.php'); }

// Profile photo (match dashboard)
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL");
$conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS group_id INT NULL");
$conn->query("ALTER TABLE folders ADD COLUMN IF NOT EXISTS subfolder_position INT DEFAULT 0");
$conn->query("CREATE TABLE IF NOT EXISTS folder_groups (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, semester VARCHAR(100) DEFAULT 'End of Semester', description TEXT, status ENUM('active','pending') DEFAULT 'active', school_year_id INT, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$conn->query("ALTER TABLE folder_groups ADD COLUMN IF NOT EXISTS status ENUM('active','pending') DEFAULT 'active'");
$_photo = $conn->query("SELECT profile_photo FROM users WHERE id = {$_SESSION['user_id']}")->fetch_assoc()['profile_photo'] ?? '';
$_photo_url = $_photo && storageExists(__DIR__ . '/../uploads/avatars/' . $_photo)
    ? BASE_URL . '/uploads/avatars/' . $_photo . '?v=' . (storageModified(__DIR__ . '/../uploads/avatars/' . $_photo) ?: 0)
    : '';

$dept = $conn->query("SELECT * FROM departments WHERE id = $dept_id")->fetch_assoc();
$active_sy        = setupSchoolYears($conn);
$viewing_sy_id = (int) ($active_sy['id'] ?? 0);
$viewing_sy = $active_sy;

$faculty = $conn->query("
    SELECT id, full_name, username, profile_photo, role FROM users
    WHERE department_id = $dept_id AND role IN ('faculty', 'chairperson') AND is_active = 1
    ORDER BY full_name ASC
")->fetch_all(MYSQLI_ASSOC);
foreach ($faculty as &$faculty_member) {
    $photo_path = __DIR__ . '/../uploads/avatars/' . ($faculty_member['profile_photo'] ?? '');
    $faculty_member['profile_photo_url'] = !empty($faculty_member['profile_photo']) && storageExists($photo_path)
        ? BASE_URL . '/uploads/avatars/' . $faculty_member['profile_photo'] . '?v=' . (storageModified($photo_path) ?: 0)
        : null;
}
unset($faculty_member);

$folders = $conn->query("
        SELECT f.id, f.folder_name, f.semester FROM folders f
        LEFT JOIN folder_groups fg ON fg.id = f.group_id
        WHERE f.department_id = $dept_id AND f.school_year_id = $viewing_sy_id
            AND (f.group_id IS NULL OR (fg.status = 'active' AND fg.school_year_id = $viewing_sy_id))
    ORDER BY f.subfolder_position ASC, f.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

$total_folders = count($folders);

// A folder is complete for a faculty member only when every assigned subject has a file.
$faculty_subjects = [];
foreach ($faculty as $faculty_member) {
    $faculty_subjects[(int) $faculty_member['id']] = [];
}
$subject_rows = $conn->query("SELECT faculty_id, subject_code FROM subjects WHERE department_id = $dept_id ORDER BY subject_code")
    ->fetch_all(MYSQLI_ASSOC);
foreach ($subject_rows as $subject_row) {
    $faculty_subjects[(int) $subject_row['faculty_id']][] = $subject_row['subject_code'];
}

// Build subject-aware submission matrix with full file details.
$matrix = [];
$faculty_totals = [];
$faculty_possible = [];
$file_data = []; // [faculty_id][folder_id] = array of file rows
$folder_complete_faculty = array_fill_keys(array_column($folders, 'id'), 0);
foreach ($faculty as $f) {
    $faculty_totals[$f['id']] = 0;
    $faculty_possible[$f['id']] = count($faculty_subjects[$f['id']]) * $total_folders;
    $file_data[$f['id']] = [];
    foreach ($folders as $fo) {
        $files_result = $conn->query("
            SELECT id, original_name, file_size, file_type, uploaded_at, subject_code
            FROM files
            WHERE folder_id={$fo['id']} AND uploaded_by={$f['id']}
            ORDER BY uploaded_at ASC
        ")->fetch_all(MYSQLI_ASSOC);
        $files_by_subject = [];
        foreach ($files_result as $file) {
            $files_by_subject[$file['subject_code']][] = $file;
        }
        $assigned_subject_codes = $faculty_subjects[$f['id']];
        $submitted_subject_codes = array_values(array_filter(
            $assigned_subject_codes,
            static fn($subject_code) => !empty($files_by_subject[$subject_code])
        ));
        $pending_subject_codes = array_values(array_diff($assigned_subject_codes, $submitted_subject_codes));
        $submitted_subject_count = count($submitted_subject_codes);
        $subject_count = count($assigned_subject_codes);
        $is_complete = $subject_count > 0 && $submitted_subject_count === $subject_count;
        $cnt = count($files_result);
        $names = implode('||', array_column($files_result, 'original_name'));
        $matrix[$f['id']][$fo['id']] = [
            'count' => $cnt,
            'names' => $names,
            'files_by_subject' => $files_by_subject,
            'submitted_subject_codes' => $submitted_subject_codes,
            'pending_subject_codes' => $pending_subject_codes,
            'submitted_subject_count' => $submitted_subject_count,
            'subject_count' => $subject_count,
            'is_complete' => $is_complete,
        ];
        $file_data[$f['id']][$fo['id']] = $files_result;
        $faculty_totals[$f['id']] += $submitted_subject_count;
        if ($is_complete) $folder_complete_faculty[$fo['id']]++;
    }
}

$fully_submitted = count(array_filter($faculty_totals, fn($t, $faculty_id) => $faculty_possible[$faculty_id] > 0 && $t >= $faculty_possible[$faculty_id], ARRAY_FILTER_USE_BOTH));
$partial_count   = count(array_filter($faculty_totals, fn($t, $faculty_id) => $t > 0 && $t < $faculty_possible[$faculty_id], ARRAY_FILTER_USE_BOTH));
$not_started     = count(array_filter($faculty_totals, fn($t) => $t === 0));
$total_possible  = array_sum($faculty_possible);
$completion_pct  = $total_possible > 0 ? round(array_sum($faculty_totals) / $total_possible * 100) : 0;

$avatar_colors = ['#F97316','#3b82f6','#8b5cf6','#ec4899','#14b8a6','#f59e0b','#10b981','#6366f1'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submission Status — CollTrace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        svg{width:1em;height:1em;display:inline-block;vertical-align:-0.125em;}
        :root{--navy:#1C1917;--navy2:#231F1B;--red:#F97316;--bg:#FFF7ED;--card:#fff;--text:#1e2235;--muted:#6b7280;--border:#e5e7eb;--green:#22c55e;--yellow:#f59e0b;--sidebar-w:270px;--header-h:62px;}
        body{font-family:'Inter',sans-serif;background:var(--bg);min-height:100vh;}

        .header{position:fixed;top:0;left:0;right:0;z-index:100;height:var(--header-h);background:linear-gradient(120deg, var(--navy) 0%, #0d0b0a 100%);border-bottom:2px solid var(--red);display:flex;align-items:center;justify-content:space-between;padding:0 28px;box-shadow:0 2px 16px rgba(0,0,0,.35);}
        .brand{display:flex;align-items:center;gap:10px;font-size:22px;font-weight:800;color:#fff;}.brand-logo{width:30px;height:30px;object-fit:contain;flex-shrink:0;}.brand-text{color:#fff;}.brand-text .accent{color:var(--red);}
        .header-right{display:flex;align-items:center;gap:14px;}
        .role-badge{background:var(--red);color:#fff;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;}
        a.hbtn{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;padding:7px 16px;border-radius:8px;font-size:13px;text-decoration:none;transition:background .2s;}
        a.hbtn:hover{background:rgba(255,255,255,.22);}
        a.hbtn-out{background:transparent;border:1px solid rgba(255,255,255,.35);color:#fff;padding:7px 16px;border-radius:8px;font-size:13px;text-decoration:none;}

        .layout{display:flex;padding-top:var(--header-h);min-height:100vh;}

        /* ── SIDEBAR (match chairperson dashboard) ── */
        .sidebar{width:var(--sidebar-w);min-height:calc(100vh - var(--header-h));background:var(--navy2);position:fixed;top:var(--header-h);left:0;bottom:0;overflow-y:auto;display:flex;flex-direction:column;box-shadow:2px 0 20px rgba(0,0,0,.18);}
        .account-section{padding:22px 18px 16px;border-bottom:1px solid rgba(255,255,255,.07);}
        .account-avatar{width:54px;height:54px;border-radius:50%;background:linear-gradient(135deg,#F97316,#FB923C);color:#fff;font-size:22px;font-weight:800;display:flex;align-items:center;justify-content:center;margin-bottom:12px;box-shadow:0 4px 12px rgba(249,115,22,.35);}
        .account-name{font-size:14px;font-weight:700;color:#e8eaf0;margin-bottom:3px;}
        .account-role{font-size:11px;color:#7a84a0;margin-bottom:2px;}
        .account-dept{font-size:11px;color:#5a8aff;font-weight:600;}
        .sidebar-section-label{font-size:10px;font-weight:700;letter-spacing:1.5px;color:#5a6480;text-transform:uppercase;padding:18px 18px 8px;}
        .sidebar-link-nav{display:flex;align-items:center;gap:11px;padding:11px 18px;color:#9ba3be;text-decoration:none;font-size:13px;font-weight:500;border-left:3px solid transparent;transition:all .2s;}
        .sidebar-link-nav:hover{background:rgba(232,65,24,.1);color:#fff;border-left-color:var(--red);}
        .sidebar-link-nav.active-nav{background:rgba(232,65,24,.15);color:#fff;border-left-color:var(--red);}
        .sidebar-folder-item{display:flex;align-items:center;gap:11px;padding:11px 18px;color:#9ba3be;text-decoration:none;font-size:13px;font-weight:500;border-left:3px solid transparent;transition:all .2s;}
        .sidebar-folder-item:hover{background:rgba(232,65,24,.1);color:#fff;border-left-color:var(--red);}
        .folder-pill{margin-left:auto;font-size:10px;font-weight:700;padding:2px 7px;border-radius:8px;flex-shrink:0;}
        .pill-done{background:rgba(74,222,128,0.15);color:#4ade80;}
        .pill-partial{background:rgba(251,191,36,0.15);color:#fbbf24;}
        .pill-empty{background:rgba(156,163,175,0.15);color:#9ca3af;}
        .sidebar-logout{margin-top:auto;padding:14px 18px;border-top:1px solid rgba(255,255,255,.07);}
        .sidebar-logout a{display:flex;align-items:center;gap:9px;color:#f87171;font-size:13px;font-weight:500;text-decoration:none;padding:9px 12px;border-radius:8px;transition:background .2s;}
        .sidebar-logout a:hover{background:rgba(248,113,113,.12);}

        
        .sidebar-logout a.sb-info-link { color: #c4cadd; }
        .sidebar-logout a.sb-info-link:hover { background: rgba(249,115,22,0.12); color: #fff; }
        /* MAIN */
        .main{margin-left:var(--sidebar-w);flex:1;padding:28px 30px;min-width:0;}
        .page-title{font-size:22px;font-weight:800;color:var(--text);margin-bottom:4px;display:flex;align-items:center;gap:8px;}
        .page-sub{color:var(--muted);font-size:13px;margin-bottom:22px;}
        .page-title svg, .sidebar-link-nav svg, .sidebar-folder-item svg, .sidebar-logout svg, .bar-title svg, .fbtn svg, .empty-state svg, .fac-header svg, .folder-status-icon svg, .btn-view-folder svg, .modal-close svg, .modal-section-label svg, .mfi-icon svg {
            width: 1em; height: 1em; color: var(--red); flex-shrink: 0;
            display: inline-block; vertical-align: -0.12em; line-height: 1;
        }
        .sidebar-link-nav svg { width: 16px; height: 16px; }

        /* SY TABS */
        .sy-tabs{display:flex;align-items:center;background:var(--card);border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.07);padding:5px;margin-bottom:22px;flex-wrap:wrap;border:1.5px solid var(--border);}
        .sy-tab{display:inline-flex;align-items:center;gap:7px;padding:7px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:var(--muted);transition:all .2s;}
        .sy-tab:hover{background:#f0f2f8;color:var(--text);}
        .sy-tab.on{background:var(--navy);color:#fff;box-shadow:0 2px 8px rgba(26,26,46,.3);}
        .sy-dot{width:7px;height:7px;border-radius:50%;display:inline-block;}

        /* SUMMARY */
        .sum-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
        .sum-card{background:var(--card);border-radius:12px;padding:16px 18px;box-shadow:0 2px 10px rgba(0,0,0,.06);text-align:center;border-top:4px solid var(--border);}
        .sum-card.c-navy{border-top-color:var(--navy);}
        .sum-card.c-green{border-top-color:#22c55e;}
        .sum-card.c-yellow{border-top-color:#f59e0b;}
        .sum-card.c-red{border-top-color:#ef4444;}
        .sum-card .n{font-size:30px;font-weight:800;color:var(--text);}
        .sum-card .l{font-size:12px;color:var(--muted);margin-top:3px;font-weight:500;}

        /* OVERALL BAR */
        .overall-wrap{background:var(--card);border-radius:14px;padding:20px 24px;box-shadow:0 2px 10px rgba(0,0,0,.06);margin-bottom:22px;}
        .bar-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
        .bar-title{font-size:15px;font-weight:700;color:var(--text);}
        .bar-pct{font-size:24px;font-weight:800;color:var(--navy);}
        .bar-track{height:14px;background:#e5e7eb;border-radius:99px;overflow:hidden;}
        .bar-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#F97316,#FB923C);}

        /* FILTER ROW */
        .filter-row{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
        .fbtn{padding:7px 16px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;border:1.5px solid var(--border);background:var(--card);color:var(--muted);transition:all .2s;}
        .fbtn:hover,.fbtn.on{border-color:var(--navy);color:var(--navy);background:rgba(26,26,46,.06);}
        .fbtn.g.on{border-color:#16a34a;color:#166534;background:#f0fff4;}
        .fbtn.y.on{border-color:#d97706;color:#92400e;background:#fffbeb;}
        .fbtn.r.on{border-color:#dc2626;color:#991b1b;background:#fff5f5;}
        .search-box{padding:7px 14px;border:1.5px solid var(--border);border-radius:20px;font-size:13px;outline:none;font-family:inherit;min-width:200px;transition:border-color .2s;margin-left:auto;}
        .search-box:focus{border-color:var(--red);}

        /* FACULTY ACCORDION LIST */
        .fac-list{display:flex;flex-direction:column;gap:10px;}

        .fac-item{background:var(--card);border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,.07);border:1.5px solid var(--border);overflow:hidden;transition:box-shadow .2s;}
        .fac-item:hover{box-shadow:0 4px 20px rgba(0,0,0,.1);}

        /* CLICKABLE HEADER */
        .fac-header{display:flex;align-items:center;gap:14px;padding:16px 20px;cursor:pointer;user-select:none;transition:background .15s;}
        .fac-header:hover{background:#fafbfc;}
        .fac-avatar{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:800;color:#fff;flex-shrink:0;}
        .fac-info{flex:1;min-width:0;}
        .fac-name{font-size:15px;font-weight:700;color:var(--text);}
        .fac-meta{font-size:12px;color:var(--muted);margin-top:2px;}
        .fac-right{display:flex;align-items:center;gap:12px;flex-shrink:0;}
        .fac-badge{font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;white-space:nowrap;}
        .b-full{background:#dcfce7;color:#166534;}
        .b-partial{background:#fef3c7;color:#92400e;}
        .b-none{background:#fee2e2;color:#991b1b;}
        .fac-prog-mini{width:80px;}
        .mini-track{height:6px;background:#e5e7eb;border-radius:99px;overflow:hidden;margin-bottom:3px;}
        .mini-fill{height:100%;border-radius:99px;}
        .mini-label{font-size:11px;color:var(--muted);font-weight:600;text-align:right;}
        .chevron{font-size:18px;color:var(--muted);transition:transform .25s;flex-shrink:0;}
        .fac-item.open .chevron{transform:rotate(180deg);}

        /* EXPANDABLE BODY */
        .fac-body{max-height:0;overflow:hidden;transition:max-height .35s cubic-bezier(.4,0,.2,1);}
        .fac-body-inner{border-top:1px solid var(--border);padding:16px 20px;display:flex;flex-direction:column;gap:8px;}
        .fac-subject-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:4px;}
        .fac-subject-toolbar span{color:var(--muted);font-size:12px;font-weight:600;}
        .faculty-subject-toggle{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:34px;padding:0 11px;border:1px solid var(--border);border-radius:7px;background:#fff;color:var(--text);font:inherit;font-size:11px;font-weight:700;cursor:pointer;transition:background .15s,border-color .15s;}
        .faculty-subject-toggle:hover{border-color:#a8a29e;background:#fafaf9;}
        .faculty-subject-toggle svg{width:14px;height:14px;transition:transform .15s;}
        .faculty-subject-toggle[aria-expanded="true"] svg{transform:rotate(180deg);}

        .folder-row{display:flex;align-items:center;gap:14px;padding:11px 14px;border-radius:10px;background:#fafbfc;border:1.5px solid var(--border);transition:border-color .2s;}
        .folder-row:hover{border-color:#c5cad4;}
        .folder-row.done{border-color:#bbf7d0;background:#f0fff4;}
        .folder-row.pending{border-color:#fde68a;background:#fffbeb;}
        .folder-status-icon{font-size:22px;flex-shrink:0;}
        .folder-info{flex:1;min-width:0;}
        .folder-row-name{font-size:14px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .folder-row-sem{font-size:11px;color:var(--muted);margin-top:2px;}
        .folder-file-list{font-size:11px;color:#059669;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .folder-pending-subjects{font-size:11px;color:#92400e;margin-top:4px;line-height:1.45;}
        .folder-subjects{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
        .folder-subjects[hidden]{display:none;}
        .folder-subject-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border:1px solid #fde68a;border-radius:6px;background:#fffbeb;color:#92400e;font-size:10px;font-weight:700;}
        .folder-subject-chip.submitted{border-color:#bbf7d0;background:#f0fdf4;color:#166534;}
        .folder-subject-chip svg{width:12px;height:12px;}
        .folder-row-status{text-align:right;flex-shrink:0;}
        .tag-done{color:#166534;font-size:12px;font-weight:700;}
        .tag-pending{color:#b45309;font-size:12px;font-weight:700;}
        .file-cnt{font-size:11px;color:var(--muted);margin-top:1px;}
        .btn-view-folder{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;flex:none;background:var(--navy);color:#fff;border:none;border-radius:8px;cursor:pointer;text-decoration:none;transition:background .2s;}
        .btn-view-folder:hover{background:#2d2d4e;}
        .btn-view-folder svg{width:17px;height:17px;color:#fff;}
        .btn-view-folder:focus-visible,.btn-preview:focus-visible{outline:3px solid rgba(249,115,22,.35);outline-offset:2px;}

        .empty-state{text-align:center;padding:60px 20px;color:var(--muted);}
        .empty-state .ei{font-size:48px;margin-bottom:12px;}

        /* ── FOLDER DETAIL MODAL ── */
        .modal-overlay{position:fixed;inset:0;z-index:500;background:rgba(10,12,24,.72);display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .25s;}
        .modal-overlay.open{opacity:1;pointer-events:all;}
        .modal-box{background:var(--card);border-radius:18px;width:100%;max-width:640px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 24px 80px rgba(0,0,0,.35);transform:scale(.95);transition:transform .25s;overflow:hidden;}
        .modal-overlay.open .modal-box{transform:scale(1);}
        .modal-hdr{background:var(--navy);color:#fff;padding:18px 24px;display:flex;align-items:flex-start;justify-content:space-between;flex-shrink:0;gap:12px;}
        .modal-hdr-info{flex:1;min-width:0;}
        .modal-hdr-title{font-size:16px;font-weight:800;margin-bottom:3px;}
        .modal-hdr-sub{font-size:12px;color:#8a93ae;}
        .modal-close{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;width:32px;height:32px;border-radius:8px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .2s;}
        .modal-close:hover{background:rgba(255,255,255,.22);}
        .modal-body{flex:1;overflow-y:auto;padding:20px 24px;display:flex;flex-direction:column;gap:10px;}

        /* faculty context strip */
        .modal-fac-strip{display:flex;align-items:center;gap:12px;background:#f8f9fb;border-radius:10px;padding:12px 14px;border:1.5px solid var(--border);}
        .modal-fac-av{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:800;color:#fff;flex-shrink:0;}
        .modal-fac-name{font-size:14px;font-weight:700;color:var(--text);}
        .modal-fac-meta{font-size:12px;color:var(--muted);}
        .modal-status-badge{margin-left:auto;font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;}

        /* file list */
        .modal-file-item{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:10px;background:#f0fff4;border:1.5px solid #bbf7d0;}
        .mfi-icon{font-size:24px;flex-shrink:0;}
        .mfi-info{flex:1;min-width:0;}
        .mfi-name{font-size:13px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .mfi-meta{font-size:11px;color:var(--muted);margin-top:2px;}
        .btn-preview{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;flex:none;background:var(--navy);color:#fff;border:none;border-radius:8px;cursor:pointer;text-decoration:none;transition:background .2s;}
        .btn-preview:hover{background:#2d2d4e;}
        .btn-preview svg{width:17px;height:17px;color:#fff;}

        /* pending folder */
        .modal-pending-item{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:10px;background:#fffbeb;border:1.5px solid #fde68a;}
        .modal-pending-item .mfi-name{color:#92400e;}
        .modal-section-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-top:4px;}
        .modal-subject-heading{font-size:12px;font-weight:800;color:var(--text);margin:8px 0 2px;}

        /* ============ Custom Scrollbar (CollTrace) ============ */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d6d3d1; border-radius: 20px; border: 2px solid transparent; background-clip: padding-box; }
        ::-webkit-scrollbar-thumb:hover { background: #F97316; }
        * { scrollbar-width: thin; scrollbar-color: #d6d3d1 transparent; }
    
        .loading-screen {
            position: fixed; inset: 0; z-index: 9999;
            display: flex; align-items: center; justify-content: center;
            background: #000; backdrop-filter: blur(6px);
            transition: opacity 0.6s ease, visibility 0.6s ease;
        }
        .loading-screen.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
        .loading-logo {
            width: 110px; height: 110px; object-fit: contain;
            animation: spin-logo 1s linear infinite;
            filter: drop-shadow(0 10px 24px rgba(0,0,0,0.25));
        }
        @keyframes spin-logo { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>
</head>
<body>
<div id="loadingScreen" class="loading-screen" aria-hidden="true">
    <img class="loading-logo" src="../ceit_logo.png" alt="Loading CEIT logo">
</div>


<div class="header">
    <a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><span class="brand-text">Coll<span class="accent">Trace</span></span></a>
    <div class="header-right">
        <span class="dept-badge" style="background:rgba(255,255,255,0.13);color:#e8eaf0;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;"><?= sanitize($dept['code']) ?></span>
        <span class="role-badge">CHAIRPERSON</span>
        <a href="../logout.php" class="hbtn-out">Logout</a>
    </div>
</div>

<div class="layout">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="account-section">
            <a href="<?= BASE_URL ?>/profile.php" style="display:block;width:fit-content;margin-bottom:12px;position:relative;" title="My Information">
                <?php if ($_photo_url): ?>
                    <img src="<?= $_photo_url ?>" alt="Profile" style="width:54px;height:54px;border-radius:50%;object-fit:cover;border:3px solid rgba(249,115,22,.6);display:block;">
                <?php else: ?>
                    <div class="account-avatar"><?= mb_strtoupper(mb_substr($_SESSION['full_name'],0,1)) ?></div>
                <?php endif; ?>
                <span style="position:absolute;bottom:0;right:0;width:18px;height:18px;background:#F97316;border-radius:50%;border:2px solid #231F1B;display:flex;align-items:center;justify-content:center;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:10px;height:10px;color:#fff;display:block;"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>
            </a>
            <div class="account-name"><?= sanitize($_SESSION['full_name']) ?></div>
            <div class="account-role">Department Chairperson</div>
            <div class="account-dept"><?= sanitize($dept['name']) ?></div>
        </div>

        <div class="sidebar-section-label">Navigation</div>
        <a href="dashboard.php" class="sidebar-link-nav"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14h3"/><path d="M7 10h3"/><path d="M13 18V8h3v10"/><path d="M17 13v5"/></svg></span> Dashboard</a>
        <a href="submission_status.php" class="sidebar-link-nav active-nav"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg></span> Submission Status</a>
        <a href="assign_faculty.php" class="sidebar-link-nav"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg></span> Assign Faculty</a>
        <a href="assign_subjects.php" class="sidebar-link-nav"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/><path d="M12 7v6"/><path d="M9 10h6"/></svg></span> Assign Subjects</a>
        <a href="subjects.php" class="sidebar-link-nav"><span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg></span> Department Subjects</a>

        <div class="sidebar-section-label">Department Folders</div>
        <?php if (empty($folders)): ?>
            <div style="padding: 12px 18px; font-size: 12px; color: #5a6480;">No folders yet.</div>
        <?php else: ?>
            <?php
                $faculty_count = count($faculty);
                foreach ($folders as $folder):
                    $sub_raw = $folder_complete_faculty[$folder['id']] ?? 0;
                    if ($sub_raw >= $faculty_count && $faculty_count > 0) $pillClass = 'pill-done';
                    elseif ($sub_raw > 0) $pillClass = 'pill-partial';
                    else $pillClass = 'pill-empty';
                    $pillLabel = $sub_raw . '/' . $faculty_count;
            ?>
            <a href="folder_view.php?id=<?= $folder['id'] ?>" class="sidebar-folder-item">
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></span>
                <span style="flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= sanitize($folder['folder_name']) ?></span>
                <span class="folder-pill <?= $pillClass ?>"><?= $pillLabel ?></span>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="sidebar-logout">
            <a href="<?= BASE_URL ?>/profile.php" class="sb-info-link" style="margin-bottom:6px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> My Information</a>
</div>
    </aside>

    <!-- MAIN -->
    <main class="main">
        <div class="page-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg> Department Submission Status</div>



        <!-- SUMMARY -->
        <div class="sum-row">
            <div class="sum-card c-navy"><div class="n"><?= count($faculty) ?></div><div class="l">Department Members</div></div>
            <div class="sum-card c-green"><div class="n"><?= $fully_submitted ?></div><div class="l">Fully Submitted</div></div>
            <div class="sum-card c-yellow"><div class="n"><?= $partial_count ?></div><div class="l">Partially Submitted</div></div>
            <div class="sum-card c-red"><div class="n"><?= $not_started ?></div><div class="l">Not Yet Started</div></div>
        </div>

        <!-- OVERALL BAR -->
        <div class="overall-wrap">
            <div class="bar-hdr">
                <div>
                    <div class="bar-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14h3"/><path d="M7 10h3"/><path d="M13 18V8h3v10"/><path d="M17 13v5"/></svg> Overall Completion — SY <?= sanitize($viewing_sy['label']) ?></div>
                    <div style="font-size:12px;color:var(--muted);margin-top:2px;"><?= array_sum($faculty_totals) ?> of <?= $total_possible ?> subject-folder submissions received</div>
                </div>
                <div class="bar-pct"><?= $completion_pct ?>%</div>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:<?= $completion_pct ?>%"></div></div>
        </div>

        <?php if (empty($faculty)): ?>
        <div class="empty-state"><div class="ei"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/></svg></div><p>No department members yet.</p></div>
        <?php elseif (empty($folders)): ?>
        <div class="empty-state"><div class="ei"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></div><p>No folders created for SY <?= sanitize($viewing_sy['label']) ?> yet.</p></div>
        <?php else: ?>

        <!-- FILTER + SEARCH -->
        <div class="filter-row">
            <button class="fbtn on"     id="f-all"     onclick="setFilter('all',this)">All (<?= count($faculty) ?>)</button>
            <button class="fbtn g"      id="f-full"    onclick="setFilter('full',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Complete (<?= $fully_submitted ?>)</button>
            <button class="fbtn y"      id="f-partial" onclick="setFilter('partial',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Partial (<?= $partial_count ?>)</button>
            <button class="fbtn r"      id="f-none"    onclick="setFilter('none',this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Not Started (<?= $not_started ?>)</button>
            <input type="text" class="search-box" placeholder="Search faculty…" oninput="doSearch(this.value)">
        </div>

        <!-- FACULTY ACCORDION LIST -->
        <div class="fac-list" id="facList">
        <?php
        $ci = 0;
        foreach ($faculty as $fac):
            $sub     = $faculty_totals[$fac['id']];
            $possible = $faculty_possible[$fac['id']];
            $pct     = $possible > 0 ? round($sub/$possible*100) : 0;
            $color   = $avatar_colors[$ci % count($avatar_colors)]; $ci++;
            if ($sub >= $possible && $possible > 0) { $status='full';    $badgeClass='b-full';    $badgeLabel='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Fully Submitted'; $barColor='#22c55e'; }
            elseif ($sub > 0)                                  { $status='partial'; $badgeClass='b-partial'; $badgeLabel='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Partial';         $barColor='#f59e0b'; }
            else                                               { $status='none';   $badgeClass='b-none';    $badgeLabel='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg> Not Started';     $barColor='#ef4444'; }
        ?>
        <?php $faculty_photo_path = __DIR__ . '/../uploads/avatars/' . ($fac['profile_photo'] ?? ''); ?>
        <div class="fac-item" data-status="<?= $status ?>" data-name="<?= strtolower(sanitize($fac['full_name'])) ?>" id="fi-<?= $fac['id'] ?>">

            <!-- CLICKABLE HEADER -->
            <div class="fac-header" onclick="toggleFac(<?= $fac['id'] ?>)">
                <?php if (!empty($fac['profile_photo']) && storageExists($faculty_photo_path)): ?>
                <img class="fac-avatar" src="<?= BASE_URL . '/uploads/avatars/' . $fac['profile_photo'] . '?v=' . (storageModified($faculty_photo_path) ?: 0) ?>" alt="">
                <?php else: ?>
                <div class="fac-avatar" style="background:<?= $color ?>"><?= mb_strtoupper(mb_substr($fac['full_name'],0,1)) ?></div>
                <?php endif; ?>
                <div class="fac-info">
                    <div class="fac-name"><?= sanitize($fac['full_name']) ?></div>
                    <div class="fac-meta">@<?= sanitize($fac['username']) ?> &nbsp;·&nbsp; <?= $sub ?>/<?= $possible ?> subject-folder submissions</div>
                </div>
                <div class="fac-right">
                    <span class="fac-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
                    <div class="fac-prog-mini">
                        <div class="mini-track"><div class="mini-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div></div>
                        <div class="mini-label"><?= $pct ?>%</div>
                    </div>
                    <span class="chevron">⌄</span>
                </div>
            </div>

            <!-- EXPANDABLE FOLDER DETAILS -->
            <div class="fac-body" id="fb-<?= $fac['id'] ?>">
                <div class="fac-body-inner">
                    <div class="fac-subject-toolbar">
                        <span>Folder and subject submissions</span>
                        <button type="button" class="faculty-subject-toggle" aria-expanded="false" onclick="toggleFacultySubjects(this)"><span>See all</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
                    </div>
                    <?php foreach ($folders as $fo):
                        $info = $matrix[$fac['id']][$fo['id']];
                        $cnt  = $info['count'];
                        $names = $info['names'];
                        $isDone = $info['is_complete'];
                    ?>
                    <div class="folder-row <?= $isDone?'done':'pending' ?>">
                        <div class="folder-status-icon"><?= $isDone ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>' ?></div>
                        <div class="folder-info">
                            <div class="folder-row-name"><?= sanitize($fo['folder_name']) ?></div>
                            <div class="folder-row-sem"><?= sanitize($fo['semester']) ?></div>
                            <?php if ($cnt > 0 && $names): ?>
                            <div class="folder-file-list"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M13 2v7h7"/><path d="M10 13h4"/><path d="M10 17h4"/></svg> <?= sanitize(str_replace('||', ', ', $names)) ?></div>
                            <?php endif; ?>
                            <?php if ($info['subject_count'] > 0): ?>
                            <div class="folder-pending-subjects"><?= $info['submitted_subject_count'] ?>/<?= $info['subject_count'] ?> subjects submitted<?= $info['pending_subject_codes'] ? ' · Missing: ' . sanitize(implode(', ', $info['pending_subject_codes'])) : '' ?></div>
                            <?php elseif (!$isDone && $info['subject_count'] === 0): ?>
                            <div class="folder-pending-subjects">No subjects assigned</div>
                            <?php endif; ?>
                            <div class="folder-subjects" hidden>
                                <?php foreach ($faculty_subjects[$fac['id']] as $subject_code):
                                    $subject_submitted = in_array($subject_code, $info['submitted_subject_codes'], true);
                                ?>
                                <span class="folder-subject-chip<?= $subject_submitted ? ' submitted' : '' ?>">
                                    <?php if ($subject_submitted): ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg><?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><?php endif; ?>
                                    <?= sanitize($subject_code) ?> · <?= $subject_submitted ? 'Submitted' : 'Pending' ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="folder-row-status">
                            <?php if ($isDone): ?>
                                <div class="tag-done">Complete</div>
                                <div class="file-cnt"><?= $cnt ?> file<?= $cnt>1?'s':'' ?></div>
                            <?php else: ?>
                                <div class="tag-pending">Pending</div>
                                <div class="file-cnt"><?= $info['submitted_subject_count'] ?>/<?= $info['subject_count'] ?> subjects</div>
                            <?php endif; ?>
                        </div>
                        <button class="btn-view-folder" aria-label="View <?= sanitize($fo['folder_name']) ?> submissions for <?= sanitize($fac['full_name']) ?>" title="View submissions" onclick="event.stopPropagation();openModal(<?= $fac['id'] ?>,<?= $fo['id'] ?>)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- ── FOLDER DETAIL MODAL ── -->
<div class="modal-overlay" id="detailModal" onclick="closeOnBackdrop(event)">
    <div class="modal-box">
        <div class="modal-hdr">
            <div class="modal-hdr-info">
                <div class="modal-hdr-title" id="mTitle">Folder Details</div>
                <div class="modal-hdr-sub" id="mSub"></div>
            </div>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <div class="modal-body" id="mBody"></div>
    </div>
</div>

<script>
// ── Pre-loaded data from PHP ──
const BASE_URL = '<?= BASE_URL ?>';
const FOLDERS  = <?= json_encode(array_column($folders, null, 'id')) ?>;
const FACULTY  = <?= json_encode(array_column($faculty, null, 'id')) ?>;
const FILE_DATA = <?= json_encode($file_data) ?>;
const SUBJECTS_BY_FACULTY = <?= json_encode($faculty_subjects) ?>;
const AVATAR_COLORS = <?= json_encode($avatar_colors) ?>;
const FAC_LIST_ORDER = <?= json_encode(array_column($faculty,'id')) ?>;

function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
    return (bytes/1048576).toFixed(1) + ' MB';
}
function fileIcon(mime) {
    if (mime.includes('pdf'))   return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h4"/></svg>';
    if (mime.includes('image')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10.5" r="1.5"/><path d="m21 15-5-5-6 6"/></svg>';
    if (mime.includes('word') || mime.includes('document')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>';
    if (mime.includes('sheet') || mime.includes('excel'))   return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3h16a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/></svg>';
    if (mime.includes('presentation') || mime.includes('powerpoint')) return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M8 18h8"/><path d="M8 7h5"/><path d="M8 11h8"/></svg>';
    if (mime.includes('zip'))   return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h6l4 4v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z"/><path d="M8 10h4"/><path d="M8 14h4"/><path d="M8 18h4"/></svg>';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M13 2v7h7"/><path d="M10 13h4"/><path d="M10 17h4"/></svg>';
}
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function openModal(facId, folderId) {
    const fac    = FACULTY[facId];
    const folder = FOLDERS[folderId];
    const files  = (FILE_DATA[facId] && FILE_DATA[facId][folderId]) ? FILE_DATA[facId][folderId] : [];
    const assignedSubjects = SUBJECTS_BY_FACULTY[facId] || [];
    const missingSubjects = assignedSubjects.filter(subjectCode => !files.some(file => file.subject_code === subjectCode));
    const submittedSubjectCount = assignedSubjects.length - missingSubjects.length;
    const complete = assignedSubjects.length > 0 && missingSubjects.length === 0;
    const facIdx = FAC_LIST_ORDER.indexOf(parseInt(facId));
    const avColor = AVATAR_COLORS[facIdx % AVATAR_COLORS.length];
    // Header
    document.getElementById('mTitle').textContent = folder.folder_name;
    document.getElementById('mSub').textContent   = folder.semester;

    // Status badge
    const badgeHtml = complete
        ? `<span class="modal-status-badge" style="background:#dcfce7;color:#166534;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Complete</span>`
        : `<span class="modal-status-badge" style="background:#fef3c7;color:#92400e;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg> Pending · ${submittedSubjectCount}/${assignedSubjects.length} subjects</span>`;

    // Faculty strip
    const facultyAvatar = fac.profile_photo_url
        ? `<img class="modal-fac-av" src="${fac.profile_photo_url}" alt="">`
        : `<div class="modal-fac-av" style="background:${avColor}">${esc(fac.full_name.charAt(0).toUpperCase())}</div>`;
    const facStrip = `
        <div class="modal-fac-strip">
            ${facultyAvatar}
            <div>
                <div class="modal-fac-name">${esc(fac.full_name)}</div>
                <div class="modal-fac-meta">${fac.role === 'chairperson' ? 'Chairperson' : 'Faculty'} · @${esc(fac.username)}</div>
            </div>
            ${badgeHtml}
        </div>`;

    let body = facStrip;

    body += `<div class="modal-section-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M13 2v7h7"/><path d="M10 13h4"/><path d="M10 17h4"/></svg> Subject submissions</div>`;
    assignedSubjects.forEach(subjectCode => {
        const subjectFiles = files.filter(file => file.subject_code === subjectCode);
        body += `<div class="modal-subject-heading">${esc(subjectCode)} · ${subjectFiles.length ? 'Submitted' : 'Pending'}</div>`;
        if (!subjectFiles.length) {
            body += `<div class="modal-pending-item"><div class="mfi-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div><div class="mfi-info"><div class="mfi-name">No file submitted for ${esc(subjectCode)}</div></div></div>`;
            return;
        }
        subjectFiles.forEach(f => {
            const icon = fileIcon(f.file_type);
            const date = new Date(f.uploaded_at).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
            body += `<div class="modal-file-item"><div class="mfi-icon">${icon}</div><div class="mfi-info"><div class="mfi-name" title="${esc(f.original_name)}">${esc(f.original_name)}</div><div class="mfi-meta">${formatSize(f.file_size)} &nbsp;·&nbsp; Uploaded ${date}</div></div><a href="${BASE_URL}/includes/file_viewer.php?id=${f.id}" target="_blank" class="btn-preview" aria-label="View ${esc(f.original_name)}" title="View file"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></a></div>`;
        });
    });
    if (!assignedSubjects.length) body += `<div class="modal-pending-item"><div class="mfi-info"><div class="mfi-name">No subjects assigned</div></div></div>`;

    document.getElementById('mBody').innerHTML = body;
    document.getElementById('detailModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('detailModal').classList.remove('open');
    document.body.style.overflow = '';
}
function closeOnBackdrop(e) { if (e.target===document.getElementById('detailModal')) closeModal(); }
document.addEventListener('keydown', e => { if (e.key==='Escape') closeModal(); });

// ── Accordion ──
function toggleFac(id) {
    const item = document.getElementById('fi-'+id);
    const body = document.getElementById('fb-'+id);
    const isOpen = item.classList.contains('open');
    document.querySelectorAll('.fac-item.open').forEach(el => {
        el.classList.remove('open');
        el.querySelector('.fac-body').style.maxHeight = '0';
    });
    if (!isOpen) {
        item.classList.add('open');
        body.style.maxHeight = body.scrollHeight + 'px';
    }
}

function toggleFacultySubjects(button) {
    const expanded = button.getAttribute('aria-expanded') === 'true';
    const facultyBody = button.closest('.fac-body-inner');
    if (!facultyBody) return;
    facultyBody.querySelectorAll('.folder-subjects').forEach(subjectList => {
        subjectList.hidden = expanded;
    });
    button.setAttribute('aria-expanded', String(!expanded));
    button.querySelector('span').textContent = expanded ? 'See all' : 'Show less';
    const facultyItem = button.closest('.fac-item');
    const body = facultyItem?.querySelector('.fac-body');
    if (body && facultyItem.classList.contains('open')) body.style.maxHeight = body.scrollHeight + 'px';
}

// ── Filter ──
let currentFilter = 'all';
function setFilter(status, btn) {
    currentFilter = status;
    document.querySelectorAll('.fbtn').forEach(b => b.classList.remove('on'));
    btn.classList.add('on');
    applyFilters();
}
function doSearch(val) { applyFilters(val.toLowerCase().trim()); }
function applyFilters(search='') {
    document.querySelectorAll('.fac-item').forEach(el => {
        const matchStatus = currentFilter==='all' || el.dataset.status===currentFilter;
        const matchSearch = !search || el.dataset.name.includes(search);
        el.style.display = (matchStatus && matchSearch) ? '' : 'none';
    });
}
</script>
<script>
// Keep the sidebar's scroll position when navigating to another page (e.g. clicking a folder)
(function() {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;
    const KEY = 'coltrace_sidebar_scroll';
    const saved = sessionStorage.getItem(KEY);
    if (saved !== null) sidebar.scrollTop = parseInt(saved, 10);
    sidebar.addEventListener('scroll', () => sessionStorage.setItem(KEY, sidebar.scrollTop));
})();
</script>

<script>
window.addEventListener('load', () => {
    const loader = document.getElementById('loadingScreen');
    if (loader) { setTimeout(() => { loader.classList.add('hidden'); }, 900); }
});
(function () {
    const loader = document.getElementById('loadingScreen');
    if (!loader) return;
    document.addEventListener('click', function (e) {
        const link = e.target.closest('a[href]');
        if (!link) return;
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
        if (link.target === '_blank' || link.hasAttribute('download')) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
        e.preventDefault();
        loader.classList.remove('hidden');
        setTimeout(() => { window.location.href = href; }, 300);
    });
    window.addEventListener('pageshow', () => loader.classList.add('hidden'));
})();
</script>
</body>
</html>
