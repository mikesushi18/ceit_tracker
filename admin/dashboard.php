<?php
// admin/dashboard.php
require_once '../includes/config.php';
require_once '../includes/school_year_helper.php';
requireAdminDashboardAccess();

$active_sy = setupSchoolYears($conn);

// Profile photo
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL");
$_photo = $conn->query("SELECT profile_photo FROM users WHERE id = {$_SESSION['user_id']}")->fetch_assoc()['profile_photo'] ?? '';
$_photo_url = $_photo && file_exists(__DIR__ . '/../uploads/avatars/' . $_photo)
    ? BASE_URL . '/uploads/avatars/' . $_photo . '?v=' . filemtime(__DIR__ . '/../uploads/avatars/' . $_photo)
    : '';

// Ensure announcements table + attachment columns exist
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


// Fetch departments with stats
$departments = $conn->query("
    SELECT d.*,
        (SELECT COUNT(*) FROM folders f
         LEFT JOIN folder_groups fg ON fg.id = f.group_id
         WHERE f.department_id = d.id AND f.school_year_id = " . (int) ($active_sy['id'] ?? 0) . "
           AND (f.group_id IS NULL OR fg.school_year_id = " . (int) ($active_sy['id'] ?? 0) . ")) AS folder_count,
        (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.role = 'faculty') AS faculty_count,
        (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.role = 'chairperson') AS chair_count
    FROM departments d
    ORDER BY d.code
")->fetch_all(MYSQLI_ASSOC);

$department_metrics = [];
foreach ($departments as $department) {
    $department_metrics[(int) $department['id']] = [
        'required_folder_requirements' => 0,
        'completed_folder_requirements' => 0,
        'faculty' => [],
        'eligible_faculty_count' => 0,
        'started_faculty_count' => 0,
        'complete_faculty_count' => 0,
        'completion_percent' => 0,
        'semesters' => [],
    ];
}

$submission_rows = $conn->query("
    SELECT d.id AS department_id, f.id AS folder_id, u.id AS faculty_id,
        COALESCE(NULLIF(fg.semester, ''), NULLIF(f.semester, ''), 'Unspecified semester') AS semester_name,
        COUNT(DISTINCT s.subject_code) AS required_subjects,
        COUNT(DISTINCT CASE WHEN fi.id IS NOT NULL THEN s.subject_code END) AS submitted_subjects
    FROM departments d
    JOIN folders f ON f.department_id = d.id
    LEFT JOIN folder_groups fg ON fg.id = f.group_id
    JOIN users u ON u.department_id = d.id AND u.role IN ('faculty', 'chairperson') AND u.is_active = 1
    LEFT JOIN subjects s ON s.faculty_id = u.id AND s.department_id = d.id
    LEFT JOIN files fi ON fi.folder_id = f.id AND fi.uploaded_by = u.id AND fi.subject_code = s.subject_code
    WHERE f.school_year_id = " . (int) ($active_sy['id'] ?? 0) . "
      AND (f.group_id IS NULL OR fg.school_year_id = " . (int) ($active_sy['id'] ?? 0) . ")
    GROUP BY d.id, f.id, u.id, semester_name
")->fetch_all(MYSQLI_ASSOC);

$total_required_folder_requirements = 0;
$total_completed_folder_requirements = 0;
$eligible_faculty_count = 0;
$started_faculty_count = 0;
$complete_faculty_count = 0;
$semester_metrics = [];
foreach ($submission_rows as $row) {
    $department_id = (int) $row['department_id'];
    $faculty_id = (int) $row['faculty_id'];
    $folder_id = (int) $row['folder_id'];
    $required_subjects = (int) $row['required_subjects'];
    $submitted_subjects = min((int) $row['submitted_subjects'], $required_subjects);
    if ($required_subjects === 0) continue;

    $folder_is_complete = $submitted_subjects >= $required_subjects;
    $department_metrics[$department_id]['required_folder_requirements']++;
    $total_required_folder_requirements++;
    if ($folder_is_complete) {
        $department_metrics[$department_id]['completed_folder_requirements']++;
        $total_completed_folder_requirements++;
    }

    $semester_name = trim((string) $row['semester_name']) ?: 'Unspecified semester';
    if (!isset($semester_metrics[$semester_name])) {
        $semester_metrics[$semester_name] = [
            'required_folder_requirements' => 0,
            'completed_folder_requirements' => 0,
            'departments' => [],
        ];
    }
    if (!isset($semester_metrics[$semester_name]['departments'][$department_id])) {
        $semester_metrics[$semester_name]['departments'][$department_id] = ['required_folder_requirements' => 0, 'completed_folder_requirements' => 0];
    }
    $semester_metrics[$semester_name]['required_folder_requirements']++;
    $semester_metrics[$semester_name]['departments'][$department_id]['required_folder_requirements']++;
    if ($folder_is_complete) {
        $semester_metrics[$semester_name]['completed_folder_requirements']++;
        $semester_metrics[$semester_name]['departments'][$department_id]['completed_folder_requirements']++;
    }

    if (!isset($department_metrics[$department_id]['faculty'][$faculty_id])) {
        $department_metrics[$department_id]['faculty'][$faculty_id] = ['required_folders' => 0, 'completed_folders' => 0, 'has_started' => false];
    }
    $department_metrics[$department_id]['faculty'][$faculty_id]['required_folders']++;
    if ($folder_is_complete) {
        $department_metrics[$department_id]['faculty'][$faculty_id]['completed_folders']++;
    }
    if ($submitted_subjects > 0) {
        $department_metrics[$department_id]['faculty'][$faculty_id]['has_started'] = true;
    }
}

foreach ($department_metrics as &$metrics) {
    $metrics['completion_percent'] = $metrics['required_folder_requirements'] > 0
        ? (int) round($metrics['completed_folder_requirements'] / $metrics['required_folder_requirements'] * 100)
        : 0;
    foreach ($metrics['faculty'] as $faculty_progress) {
        $metrics['eligible_faculty_count']++;
        if ($faculty_progress['has_started']) {
            $metrics['started_faculty_count']++;
        }
        if ($faculty_progress['completed_folders'] === $faculty_progress['required_folders']) {
            $metrics['complete_faculty_count']++;
        }
    }
    $eligible_faculty_count += $metrics['eligible_faculty_count'];
    $started_faculty_count += $metrics['started_faculty_count'];
    $complete_faculty_count += $metrics['complete_faculty_count'];
}
unset($metrics);

$semester_names = array_keys($semester_metrics ?? []);
usort($semester_names, static function ($left, $right) {
    $semester_order = ['1st Semester' => 1, '2nd Semester' => 2, 'Summer' => 3];
    return ($semester_order[$left] ?? 99) <=> ($semester_order[$right] ?? 99) ?: strnatcasecmp($left, $right);
});
$ordered_semester_metrics = [];
foreach ($semester_names as $semester_name) {
    $semester = $semester_metrics[$semester_name];
    $semester['completion_percent'] = $semester['required_folder_requirements'] > 0
        ? (int) round($semester['completed_folder_requirements'] / $semester['required_folder_requirements'] * 100)
        : 0;
    $ordered_semester_metrics[$semester_name] = $semester;
}
$semester_metrics = $ordered_semester_metrics;

$overall_completion_percent = $total_required_folder_requirements > 0
    ? (int) round($total_completed_folder_requirements / $total_required_folder_requirements * 100)
    : 0;
$faculty_started_percent = $eligible_faculty_count > 0
    ? (int) round($started_faculty_count / $eligible_faculty_count * 100)
    : 0;
$faculty_completion_percent = $eligible_faculty_count > 0
    ? (int) round($complete_faculty_count / $eligible_faculty_count * 100)
    : 0;

// Fetch announcements
$ann_raw = $conn->query("
    SELECT a.*, u.full_name AS author, u.profile_photo AS author_photo
    FROM announcements a
    JOIN users u ON u.id = a.posted_by
    ORDER BY a.created_at DESC
    LIMIT 30
")->fetch_all(MYSQLI_ASSOC);
$ann_base = BASE_URL . '/uploads/announcements/';
$announcements = array_map(function($a) use ($ann_base) {
    $a['image_url'] = $a['image_file'] ? $ann_base . $a['image_file'] : null;
    $a['pdf_url']   = $a['pdf_file']   ? $ann_base . $a['pdf_file']   : null;
    $photo_path = __DIR__ . '/../uploads/avatars/' . ($a['author_photo'] ?? '');
    $a['author_photo_url'] = !empty($a['author_photo']) && file_exists($photo_path)
        ? BASE_URL . '/uploads/avatars/' . $a['author_photo'] . '?v=' . filemtime($photo_path)
        : null;
    return $a;
}, $ann_raw);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — CollTrace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --navy:   #1C1917;
            --navy2:  #231F1B;
            --red:    #F97316;
            --red2:   #EA580C;
            --bg:     #FFF7ED;
            --card:   #ffffff;
            --text:   #1e2235;
            --muted:  #6b7280;
            --border: #e5e7eb;
            --sidebar-w: 260px;
            --header-h: 62px;
        }

        body { font-family: 'Inter', 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: var(--bg); min-height: 100vh; }

        .loading-screen {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
            backdrop-filter: blur(6px);
            transition: opacity 0.6s ease, visibility 0.6s ease;
        }
        .loading-screen.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        .loading-logo {
            width: 110px;
            height: 110px;
            object-fit: contain;
            animation: spin-logo 1s linear infinite;
            filter: drop-shadow(0 10px 24px rgba(0,0,0,0.25));
        }
        @keyframes spin-logo {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .dashboard-shell {
            opacity: 0;
            transition: opacity 0.6s ease;
        }
        body.page-loaded .dashboard-shell {
            opacity: 1;
        }

        .page-transition {
            position: fixed;
            inset: 0;
            z-index: 10001;
            display: flex;
            align-items: center;
            justify-content: center;
            background-image: linear-gradient(135deg, rgba(249,115,22,0.82) 0%, rgba(28,25,23,0.88) 100%), url('../ceit_bg.jpg');
            background-size: cover;
            background-position: center;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        .page-transition.active {
            opacity: 1;
            visibility: visible;
        }
        .page-transition-spinner {
            width: 56px;
            height: 56px;
            border: 5px solid rgba(255,255,255,0.28);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin-circle 0.7s linear infinite;
        }
        @keyframes spin-circle {
            to { transform: rotate(360deg); }
        }

        /* ── HEADER ── */
        .header {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            height: var(--header-h);
            background: linear-gradient(120deg, var(--navy) 0%, #0d0b0a 100%);
            border-bottom: 2px solid var(--red);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 28px;
            box-shadow: 0 2px 16px rgba(0,0,0,0.35);
        }
        .brand { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -0.5px; }
        .brand-logo { width: 32px; height: 32px; object-fit: contain; flex-shrink: 0; } .brand-text { display: inline-flex; align-items: center; }
        .brand span { color: var(--red); }
        .header-right { display: flex; align-items: center; gap: 14px; }
        .header-right .name { color: #cdd3e0; font-size: 14px; font-weight: 500; }
        .role-badge {
            background: var(--red); color: #fff;
            padding: 4px 12px; border-radius: 20px;
            font-size: 11px; font-weight: 700; letter-spacing: 0.6px;
        }
        .btn-action-nav {
            background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.18);
            color: #e8eaf0; padding: 7px 16px; border-radius: 8px;
            font-size: 13px; font-weight: 500; cursor: pointer;
            text-decoration: none; transition: background 0.2s;
        }
        .btn-action-nav:hover { background: rgba(255,255,255,0.16); }
        .btn-logout {
            background: transparent; border: 1px solid rgba(255,255,255,0.35);
            color: #fff; padding: 7px 16px; border-radius: 8px;
            font-size: 13px; cursor: pointer; text-decoration: none;
            transition: background 0.2s;
        }
        .btn-logout:hover { background: rgba(255,255,255,0.1); }

        /* ── LAYOUT ── */
        .layout {
            display: flex;
            padding-top: var(--header-h);
            min-height: 100vh;
        }

        /* ── SIDEBAR ── */
        .sidebar {
            width: var(--sidebar-w);
            max-height: calc(100vh - var(--header-h));
            background: var(--navy2);
            position: fixed;
            top: var(--header-h);
            left: 0;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            padding: 0 0 24px 0;
            box-shadow: 2px 0 20px rgba(0,0,0,0.18);
        }
        .sidebar-account { display:flex;align-items:center;gap:11px;margin:14px 14px 4px;padding:12px;border:1px solid rgba(255,255,255,.08);border-radius:9px;background:rgba(255,255,255,.035);text-decoration:none;transition:background .15s,border-color .15s; }
        .sidebar-account:hover { border-color:rgba(249,115,22,.35);background:rgba(249,115,22,.08); }
        .sidebar-account-avatar { display:grid;width:42px;height:42px;flex:0 0 42px;place-items:center;overflow:hidden;border:2px solid rgba(249,115,22,.55);border-radius:50%;background:linear-gradient(135deg,#f97316,#ea580c);color:#fff;font-size:16px;font-weight:800; }
        .sidebar-account-avatar img { width:100%;height:100%;object-fit:cover; }
        .sidebar-account-info { min-width:0; }
        .sidebar-account-name { overflow:hidden;color:#f5f5f4;font-size:12px;font-weight:700;text-overflow:ellipsis;white-space:nowrap; }
        .sidebar-account-role { margin-top:3px;color:#9ca3af;font-size:10px;font-weight:600;letter-spacing:.35px;text-transform:uppercase; }
        .sidebar-section-label {
            font-size: 10px; font-weight: 700; letter-spacing: 1.5px;
            color: #5a6480; text-transform: uppercase;
            padding: 22px 20px 8px;
        }
        .dept-nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 13px 20px;
            color: #9ba3be;
            text-decoration: none;
            font-size: 13px; font-weight: 500;
            border-left: 3px solid transparent;
            transition: all 0.2s;
            cursor: pointer;
        }
        .dept-nav-item:hover {
            background: rgba(232,65,24,0.12);
            color: #fff;
            border-left-color: var(--red);
        }
        .dept-nav-item .dept-icon {
            width: 34px; height: 34px;
            background: rgba(255,255,255,0.06);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; font-weight: 800; color: #c9d0e4;
            flex-shrink: 0;
            transition: background 0.2s;
        }
        .dept-nav-item:hover .dept-icon {
            background: rgba(232,65,24,0.25);
            color: #fff;
        }
        .dept-nav-item .dept-info { flex: 1; min-width: 0; }
        .dept-nav-item .dept-code { font-weight: 700; font-size: 13px; color: #e2e6f0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dept-nav-item .dept-sub { font-size: 11px; color: #6b7590; margin-top: 1px; }
        .dept-nav-item .dept-count {
            background: rgba(255,255,255,0.08);
            color: #8d96b0; font-size: 11px; font-weight: 600;
            padding: 2px 8px; border-radius: 10px; flex-shrink: 0;
        }
        .sidebar-divider { border: none; border-top: 1px solid rgba(255,255,255,0.06); margin: 10px 16px; }

        .sidebar-bottom { padding: 0 14px; }
        .sidebar-nav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 14px; border-radius: 8px;
            color: #8a93ae; font-size: 13px; font-weight: 500;
            text-decoration: none; transition: all 0.2s;
        }
        .sidebar-nav-link:hover { background: rgba(255,255,255,0.07); color: #fff; }
        .sidebar-nav-link .nav-icon { font-size: 16px; }

        /* ── MAIN CONTENT ── */
        .main {
            margin-left: var(--sidebar-w);
            flex: 1;
            padding: 28px 30px;
            min-width: 0;
        }

        /* ── COMPLETION REPORT ── */
        .stats-row { display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px; }
        .stat-card {
            background:var(--card);border:1px solid var(--border);border-top:3px solid var(--metric-color,#f97316);
            border-radius:9px;padding:13px 16px;box-shadow:0 2px 8px rgba(0,0,0,.045);min-width:0;
        }
        .stat-card .num { color:var(--metric-color,#1e2235);font-size:27px;font-weight:800;line-height:1.1; }
        .stat-card .lbl { margin-top:5px;color:var(--text);font-size:12px;font-weight:750; }
        .stat-card .metric-note { margin-top:4px;color:var(--muted);font-size:10px;line-height:1.45; }
        .stat-meter { height:7px;margin-top:10px;overflow:hidden;border-radius:10px;background:#eeece9; }
        .stat-meter-fill { height:100%;border-radius:inherit;background:var(--metric-color,#f97316);transition:width .3s ease; }
        .report-panel { margin-bottom:12px;padding:15px 17px;border:1px solid var(--border);border-radius:9px;background:var(--card);box-shadow:0 2px 8px rgba(0,0,0,.045); }
        .report-panel-header { display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:0; }
        .report-panel-main { display:flex;align-items:center;gap:9px;min-width:0; }
        .report-panel-actions { display:flex;align-items:center;gap:7px;flex:none; }
        .report-panel-title { color:var(--text);font-size:14px;font-weight:750; }
        .report-panel-note { margin-top:3px;color:var(--muted);font-size:10px;line-height:1.45; }
        .report-disclosure { display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;flex:none;border:1px solid var(--border);border-radius:7px;background:#fff;color:#57534e;cursor:pointer;transition:background .15s,border-color .15s,color .15s; }
        .report-disclosure:hover { border-color:#a8a29e;background:#fafaf9;color:#1c1917; }
        .report-disclosure svg { width:16px;height:16px;transition:transform .16s ease; }
        .report-disclosure[aria-expanded="true"] svg { transform:rotate(180deg); }
        .report-disclosure:focus-visible { outline:3px solid rgba(249,115,22,.25);outline-offset:2px; }
        .btn-print-report { display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:32px;padding:0 10px;border:1px solid #fed7aa;border-radius:7px;background:#fff;color:#9a3412;font:inherit;font-size:11px;font-weight:700;cursor:pointer; }
        .btn-print-report:hover { border-color:#f97316;background:#fff7ed; }
        .btn-print-report svg { width:14px;height:14px; }
        .report-content[hidden] { display:none; }
        .report-content { margin-top:11px; }
        .report-list { display:grid;gap:0; }
        .report-row { display:grid;grid-template-columns:minmax(155px,1fr) minmax(140px,2fr) 104px;align-items:center;gap:14px;padding:10px 0;border-top:1px solid #f1f0ee; }
        .report-row:first-child { border-top:0; }
        .department-chart { display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:14px; }
        .department-chart-item { display:grid;grid-template-rows:auto 170px auto auto;gap:6px;min-width:0;text-align:center; }
        .department-chart-value { color:var(--completion-color,#c2410c);font-size:12px;font-weight:800; }
        .department-chart-plot { display:flex;align-items:flex-end;justify-content:center;border-bottom:1px solid #a8a29e;background:repeating-linear-gradient(to bottom,#e7e5e4 0 1px,transparent 1px 25%); }
        .department-chart-bar { width:min(46px,65%);height:var(--bar-height);border-radius:4px 4px 0 0;background:var(--completion-color,#f97316);transition:height .3s ease; }
        .department-chart-name { overflow-wrap:anywhere;color:#292524;font-size:11px;font-weight:700; }
        .department-chart-details { color:var(--muted);font-size:10px;line-height:1.4; }
        .report-name { min-width:0;color:#292524;font-size:12px;font-weight:700; }
        .report-code { display:block;margin-top:2px;color:var(--muted);font-size:10px;font-weight:500; }
        .report-track { height:7px;overflow:hidden;border-radius:10px;background:#eeece9; }
        .report-fill { height:100%;border-radius:inherit;background:var(--completion-color,#f97316);transition:width .3s ease; }
        .report-value { color:var(--completion-color,#c2410c);font-size:12px;font-weight:800;text-align:right;white-space:nowrap; }
        .semester-report + .semester-report { margin-top:14px;padding-top:14px;border-top:1px solid #f1f0ee; }
        .semester-report { display:grid;grid-template-columns:150px minmax(0,1fr);align-items:center;gap:20px; }
        .semester-pie-chart { width:150px;height:150px;max-width:100%;aspect-ratio:1;border:1px solid rgba(28,25,23,.08);border-radius:50%; }
        .semester-report-content { display:grid;gap:12px;min-width:0; }
        .semester-report-summary { display:grid;gap:4px;min-width:0; }
        .semester-report-name { color:#292524;font-size:12px;font-weight:750; }
        .semester-department-legend { display:grid;gap:7px; }
        .semester-department-item { display:grid;grid-template-columns:10px minmax(54px,.55fr) minmax(0,1fr);align-items:center;gap:8px;min-width:0;font-size:11px; }
        .semester-department-swatch { width:10px;height:10px;border-radius:2px; }
        .semester-department-code { color:#292524;font-weight:750; }
        .semester-department-value { color:var(--muted);text-align:right; }
        .dept-sub { display:flex;align-items:center;gap:7px;margin-top:4px;color:#a8b0c2;font-size:11px; }
        .dept-progress-track { height:4px;flex:1;overflow:hidden;border-radius:10px;background:rgba(255,255,255,.12); }
        .dept-progress-fill { height:100%;border-radius:inherit;background:var(--dept-progress-color,#f97316); }
        @media(max-width:1000px){.stats-row{grid-template-columns:repeat(2,minmax(0,1fr));}}
        @media(max-width:620px){.stats-row{grid-template-columns:1fr;gap:8px}.stat-card{padding:12px 14px}.stat-card .num{font-size:24px}.report-panel{padding:13px}.report-row{grid-template-columns:minmax(105px,1fr) minmax(70px,1.3fr) 48px;gap:8px;padding:9px 0}.report-value{font-size:11px}.report-code{font-size:9px}.report-panel-note{max-width:250px}.department-chart{grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 12px}.department-chart-item{grid-template-rows:auto 140px auto auto}.semester-report{grid-template-columns:100px minmax(0,1fr);gap:12px}.semester-pie-chart{width:100px;height:100px}.semester-department-item{grid-template-columns:9px minmax(40px,.45fr) minmax(0,1fr);gap:6px;font-size:10px}}

        /* ── SECTION ── */
        .section-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 18px;
        }
        .section-title {
            font-size: 17px; font-weight: 700; color: var(--text);
            display: flex; align-items: center; gap: 8px;
        }

        /* ── CREATE POST CARD ── */
        .create-post-card {
            background: var(--card); border-radius: 14px;
            box-shadow: 0 2px 14px rgba(0,0,0,0.07);
            padding: 22px 24px;
            margin-bottom: 20px;
            border: 1.5px solid var(--border);
        }
        .create-post-card h3 {
            font-size: 15px; font-weight: 700; color: var(--text);
            margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
        }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: var(--muted); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
        .form-control {
            width: 100%; padding: 10px 14px;
            border: 1.5px solid var(--border); border-radius: 8px;
            font-family: inherit; font-size: 14px; color: var(--text);
            background: #fafbfc;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }
        .form-control:focus { border-color: var(--red); box-shadow: 0 0 0 3px rgba(232,65,24,0.1); background: #fff; }
        textarea.form-control { resize: vertical; min-height: 90px; }
        .btn-post {
            background: var(--red); color: #fff; border: none;
            padding: 10px 24px; border-radius: 8px;
            font-size: 14px; font-weight: 700; cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-post:hover { background: var(--red2); }
        .btn-post:active { transform: scale(0.97); }
        .btn-post[hidden] { display:none; }

        /* ── ANNOUNCEMENT FEED ── */
        .announcements-feed { display: flex; flex-direction: column; gap: 14px; }
        .ann-card {
            background: var(--card); border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            padding: 18px 20px;
            border-left: 4px solid var(--red);
            transition: box-shadow 0.2s;
            animation: fadeSlideIn 0.3s ease;
        }
        @keyframes fadeSlideIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .ann-card:hover { box-shadow: 0 6px 22px rgba(0,0,0,0.1); }
        .ann-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
        .ann-actions { display:flex;align-items:center;gap:6px;flex:none; }
        .ann-title { font-size: 15px; font-weight: 700; color: var(--text); }
        .ann-body { font-size: 14px; color: #4b5568; line-height: 1.6; white-space: pre-wrap; }
        .ann-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 12px; }
        .ann-meta { font-size: 12px; color: var(--muted); display: flex; align-items: center; gap: 6px; }
        .ann-meta .author-avatar {
            width: 24px; height: 24px; border-radius: 50%;
            background: var(--red); color: #fff;
            font-size: 10px; font-weight: 700;
            display: inline-flex; align-items: center; justify-content: center;
            object-fit: cover;
        }
        .btn-delete-ann {
            display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;
            background:none;border:1px solid #fecaca;color:#ef4444;padding:0;border-radius:6px;
            cursor:pointer;transition:all .2s;
        }
        .btn-delete-ann:hover { background: #fef2f2; }
        .btn-edit-ann { display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;padding:0;border:1px solid #d6d3d1;border-radius:6px;background:#fff;color:#57534e;cursor:pointer;transition:all .2s; }
        .btn-edit-ann:hover { border-color:#a8a29e;background:#fafaf9;color:#1c1917; }
        .btn-print-ann { display:inline-flex; align-items:center; justify-content:center; margin-top:10px; background:#fff; color:#c2410c; border:1px solid #fed7aa; padding:8px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; transition:background .15s,border-color .15s; }
        .btn-print-ann:hover { background:#fff7ed; border-color:#f97316; }
        .empty-ann {
            text-align: center; padding: 48px 20px;
            background: var(--card); border-radius: 14px;
            color: var(--muted); font-size: 14px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .empty-ann .empty-icon { font-size: 40px; margin-bottom: 10px; }

        /* ── ATTACHMENT INPUTS ── */
        .attach-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
        .attach-btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;
            cursor: pointer; border: 1.5px dashed var(--border);
            background: #fafbfc; color: var(--muted);
            transition: all 0.2s; flex: 1; min-width: 160px;
        }
        .attach-btn:hover { border-color: var(--red); color: var(--red); background: #fff8f0; }
        .attach-btn.has-file { border-color: #22c55e; color: #166534; background: #f0fdf4; border-style: solid; }
        .attach-preview-img { max-width: 100%; max-height: 180px; border-radius: 8px; margin-top: 8px; display: none; object-fit: cover; border: 1.5px solid var(--border); }
        .attach-row .btn-clear-selected { align-self:center;width:30px;height:30px;padding:5px;border:0;border-radius:50%;background:transparent;color:var(--muted);cursor:pointer; }
        .attach-row .btn-clear-selected:hover { background:#fef2f2;color:#b91c1c; }
        .attach-row .btn-clear-selected svg { display:block;width:16px;height:16px; }
        .edit-file-controls { display:grid;gap:8px;margin:0 0 12px;padding:10px 12px;border:1px solid var(--border);border-radius:7px;color:var(--muted);font-size:12px; }
        .edit-file-controls[hidden] { display:none; }
        .edit-attachment-control { display:flex;align-items:center;justify-content:space-between;gap:12px; }
        .btn-remove-attachment { flex-shrink:0;padding:5px 9px;border:1px solid #fecaca;border-radius:5px;background:#fff;color:#b91c1c;font:inherit;font-weight:600;cursor:pointer; }
        .btn-remove-attachment:hover:not(:disabled), .btn-remove-attachment[aria-pressed="true"] { background:#fef2f2; }
        .btn-remove-attachment:disabled { opacity:.5;cursor:not-allowed; }
        /* ── ANNOUNCEMENT IMAGE / PDF ── */
        .ann-image { max-width: 100%; border-radius: 8px; margin-top: 10px; display: block; max-height: 300px; object-fit: cover; cursor: zoom-in; }
        .ann-image:hover { filter: brightness(0.98); }
        .ann-pdf-link {
            display: inline-flex; align-items: center; gap: 8px;
            margin-top: 10px; padding: 8px 16px; border-radius: 8px;
            background: #fff1f1; border: 1.5px solid #fecaca;
            color: #b91c1c; font-size: 13px; font-weight: 600;
            text-decoration: none; transition: background 0.2s;
        }
        .ann-pdf-link:hover { background: #ffe4e4; }

        /* ── TOAST ── */
        .toast {
            position: fixed; bottom: 28px; right: 28px; z-index: 9999;
            background: #1e2235; color: #fff;
            padding: 12px 22px; border-radius: 10px;
            font-size: 14px; font-weight: 500;
            box-shadow: 0 6px 24px rgba(0,0,0,0.2);
            transform: translateY(16px); opacity: 0;
            transition: all 0.3s; pointer-events: none;
        }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast.success { border-left: 4px solid #22c55e; }
        .toast.error   { border-left: 4px solid #ef4444; }

        /* ── IMAGE LIGHTBOX ── */
        .img-modal {
            position: fixed; inset: 0; z-index: 10000;
            background: rgba(10,12,24,0.82);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .img-modal.open { display: flex; }
        .img-modal-box {
            position: relative;
            width: min(1100px, 96vw);
            max-height: 92vh;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 24px 80px rgba(0,0,0,0.45);
        }
        .img-modal-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            background: rgba(28,25,23,0.65);
            color: #fff;
        }
        .img-modal-title {
            font-size: 12px;
            color: rgba(255,255,255,0.8);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .img-modal-close {
            background: rgba(255,255,255,0.14);
            border: 1px solid rgba(255,255,255,0.22);
            color: #fff;
            width: 34px;
            height: 34px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .img-modal-close:hover { background: rgba(255,255,255,0.22); }
        .img-modal-imgwrap {
            background: rgba(0,0,0,0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 14px;
        }
        .img-modal-img {
            width: 100%;
            height: auto;
            max-height: calc(92vh - 54px);
            object-fit: contain;
            display: block;
        }

        /* ============ Custom Scrollbar (CollTrace) ============ */
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d6d3d1; border-radius: 20px; border: 2px solid transparent; background-clip: padding-box; }
        ::-webkit-scrollbar-thumb:hover { background: #F97316; }
        * { scrollbar-width: thin; scrollbar-color: #d6d3d1 transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.18); }
        .sidebar::-webkit-scrollbar-thumb:hover { background: #F97316; }
        .sidebar { scrollbar-color: rgba(255,255,255,0.25) transparent; }
    </style>
</head>
<body>
<div id="loadingScreen" class="loading-screen" aria-hidden="true">
    <img class="loading-logo" src="../ceit_logo.png" alt="Loading CEIT logo">
</div>
<div id="pageTransition" class="page-transition" aria-hidden="true">
    <div class="page-transition-spinner"></div>
</div>

<div class="dashboard-shell">

<!-- HEADER -->
<div class="header">
    <a href="dashboard.php" class="brand" style="color:#fff;text-decoration:none"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><div class="brand-text">Coll<span>Trace</span></div></a>
    <div class="header-right">
        <a href="<?= BASE_URL ?>/profile.php" title="My Information" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;">
            <?php if ($_photo_url): ?>
                <img src="<?= $_photo_url ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:2px solid rgba(249,115,22,.7);">
            <?php else: ?>
                <span style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#F97316,#EA580C);color:#fff;font-size:13px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;"><?= mb_strtoupper(mb_substr($_SESSION['full_name'],0,1)) ?></span>
            <?php endif; ?>
            <span class="name"><?= sanitize($_SESSION['full_name']) ?></span>
        </a>
        <button type="button" id="coltrace-backup-status-trigger" class="backup-status-trigger" aria-label="Show backup status" title="Show backup status" style="display:none">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m8 11 4 4 4-4"/><path d="M5 18v2h14v-2"/></svg>
            <span class="backup-status-dot" aria-hidden="true"></span>
        </button>
        <span class="role-badge"><?= strtoupper(sanitize($_SESSION['role'])) ?></span>
        <a href="../logout.php" class="btn-logout">Logout</a>
    </div>
</div>

<div class="layout">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <a class="sidebar-account" href="<?= BASE_URL ?>/profile.php" title="My Account">
            <span class="sidebar-account-avatar">
                <?php if ($_photo_url): ?><img src="<?= htmlspecialchars($_photo_url, ENT_QUOTES, 'UTF-8') ?>" alt="">
                <?php else: ?><?= mb_strtoupper(mb_substr($_SESSION['full_name'] ?? 'A', 0, 1)) ?><?php endif; ?>
            </span>
            <span class="sidebar-account-info">
                <span class="sidebar-account-name"><?= sanitize($_SESSION['full_name'] ?? 'Administrator') ?></span>
                <span class="sidebar-account-role"><?= ($_SESSION['role'] ?? '') === 'sub-admin' ? 'Sub-admin' : 'Administrator' ?></span>
            </span>
        </a>

        <!-- SCHOOL YEAR BADGE -->
        <?php if ($active_sy): ?>
        <div style="margin: 16px 14px 4px;">
            <a href="school_year.php" style="
                display: flex; align-items: center; justify-content: space-between;
                background: linear-gradient(135deg, rgba(232,65,24,0.18), rgba(232,65,24,0.08));
                border: 1px solid rgba(232,65,24,0.3);
                border-radius: 10px; padding: 10px 14px;
                text-decoration: none; transition: all 0.2s;
            " onmouseover="this.style.borderColor='rgba(232,65,24,0.6)'" onmouseout="this.style.borderColor='rgba(232,65,24,0.3)'">
                <div>
                    <div style="font-size:10px;color:#8a93ae;text-transform:uppercase;letter-spacing:1px;font-weight:700;">Active School Year</div>
                    <div style="font-size:16px;font-weight:800;color:#fff;margin-top:2px;"><?= sanitize($active_sy['label']) ?></div>
                </div>
                <div style="display:flex;align-items:center;justify-content:center;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;color:#FB923C;"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg></div>
            </a>
        </div>
        <?php endif; ?>

        <a href="manage_folders.php" style="
            display: flex; align-items: center; justify-content: space-between;
            background: linear-gradient(135deg, rgba(249,115,22,0.22), rgba(249,115,22,0.1));
            border: 1.5px solid rgba(249,115,22,0.45);
            border-radius: 10px; padding: 10px 14px; margin: 10px 14px 4px;
            text-decoration: none; transition: all 0.2s;
        " onmouseover="this.style.borderColor='rgba(249,115,22,0.8)'" onmouseout="this.style.borderColor='rgba(249,115,22,0.45)'">
            <div style="display:flex;align-items:center;gap:10px;flex:1;">
                <div style="width:34px;height:34px;border-radius:8px;background:rgba(249,115,22,0.16);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;color:#FB923C;"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg>
                </div>
                <div>
                    <div style="font-size:10px;color:#FB923C;text-transform:uppercase;letter-spacing:1px;font-weight:700;">Create Folder</div>
                    <div style="font-size:12px;font-weight:600;color:#fff;margin-top:2px;">Apply to all departments</div>
                </div>
            </div>
            <div style="width:24px;height:24px;border-radius:6px;background:rgba(255,255,255,0.08);display:flex;align-items:center;justify-content:center;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;color:#FFF;"><path d="M12 5v14"/><path d="M5 10h14"/></svg>
            </div>
        </a>

        <div class="sidebar-bottom" style="margin-top:4px;">
            <a href="dashboard.php" class="sidebar-nav-link">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="5" rx="1"/><rect x="13" y="10" width="8" height="11" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/></svg></span> Dashboard
            </a>
        </div>

        <div class="sidebar-section-label">Departments</div>

        <?php foreach ($departments as $dept): ?>
        <?php $dept_metric = $department_metrics[(int) $dept['id']] ?? ['completion_percent' => 0]; ?>
        <a href="department.php?id=<?= $dept['id'] ?>" class="dept-nav-item">
            <div class="dept-icon"><?= mb_substr($dept['code'], 0, 1) ?></div>
            <div class="dept-info">
                <div class="dept-code"><?= sanitize($dept['code']) ?></div>
                <div class="dept-sub">
                    <span><?= $dept_metric['completion_percent'] ?>%</span>
                    <span class="dept-progress-track"><span class="dept-progress-fill" style="display:block;width:<?= $dept_metric['completion_percent'] ?>%;--dept-progress-color:<?= $dept_metric['completion_percent'] >= 100 ? '#4ade80' : ($dept_metric['completion_percent'] > 0 ? '#fbbf24' : '#9ca3af') ?>"></span></span>
                    <span>completion</span>
                </div>
            </div>
            <span class="dept-count">›</span>
        </a>
        <?php endforeach; ?>

        <hr class="sidebar-divider">

        <div class="sidebar-bottom">
            <a href="school_year.php" class="sidebar-nav-link">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg></span> School Years
            </a>
            <a href="backup_storage.php" class="sidebar-nav-link">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><path d="M20 16.2A4.5 4.5 0 0 0 18 7.5a6 6 0 0 0-11.5 1.8A3.5 3.5 0 0 0 7 16h3"/><path d="m12 12 0 9"/><path d="m8 17 4 4 4-4"/></svg></span> Backup Storage
            </a>
            <a href="manage_folders.php" class="sidebar-nav-link">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></span> Manage Folders
            </a>
            <a href="manage_users.php" class="sidebar-nav-link">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg></span> Manage Users
            </a>
            <a href="create_user.php" class="sidebar-nav-link">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><path d="M12 5v14"/><path d="M5 10h14"/></svg></span> Create Account
            </a>
            <a href="activity_log.php" class="sidebar-nav-link">
                <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10"/><path d="M7 12h5"/></svg></span> Activity Log
            </a>
            <?php if (in_array($_SESSION['role'], ['admin', 'sub-admin'], true)): ?>
                <a href="clearance_form.php" class="sidebar-nav-link">
                    <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h6"/></svg></span> Clearance Form
                </a>
                <a href="clearance_submissions.php" class="sidebar-nav-link">
                    <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg></span> Clearance Submission
                </a>
            <?php endif; ?>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="main">

        <!-- Submission percentage report -->
        <div class="stats-row">
            <div class="stat-card" style="--metric-color:#c2410c;">
                <div class="num"><?= $overall_completion_percent ?>%</div>
                <div class="lbl">Requirements Complete</div>
                <div class="metric-note"><?= number_format($total_completed_folder_requirements) ?>/<?= number_format($total_required_folder_requirements) ?> folders complete</div>
                <div class="stat-meter" role="progressbar" aria-label="Requirements complete" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $overall_completion_percent ?>"><div class="stat-meter-fill" style="width:<?= $overall_completion_percent ?>%"></div></div>
            </div>
            <div class="stat-card" style="--metric-color:#166534;">
                <div class="num"><?= $faculty_started_percent ?>%</div>
                <div class="lbl">Department Members Started</div>
                <div class="metric-note"><?= number_format($started_faculty_count) ?>/<?= number_format($eligible_faculty_count) ?> members started</div>
                <div class="stat-meter" role="progressbar" aria-label="Department members started" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $faculty_started_percent ?>"><div class="stat-meter-fill" style="width:<?= $faculty_started_percent ?>%"></div></div>
            </div>
            <div class="stat-card" style="--metric-color:#475569;">
                <div class="num"><?= $faculty_completion_percent ?>%</div>
                <div class="lbl">Department Members Finished</div>
                <div class="metric-note"><?= number_format($complete_faculty_count) ?>/<?= number_format($eligible_faculty_count) ?> members complete</div>
                <div class="stat-meter" role="progressbar" aria-label="Department members complete" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $faculty_completion_percent ?>"><div class="stat-meter-fill" style="width:<?= $faculty_completion_percent ?>%"></div></div>
            </div>
        </div>

        <section class="report-panel" aria-labelledby="departmentCompletionTitle">
            <div class="report-panel-header">
                <div class="report-panel-main">
                    <h2 class="report-panel-title" id="departmentCompletionTitle">Department Submission Overview</h2>
                </div>
                <div class="report-panel-actions">
                    <button type="button" class="btn-print-report" data-school-year="<?= htmlspecialchars($active_sy['label'] ?? '', ENT_QUOTES, 'UTF-8') ?>" onclick="printSubmissionReport(this)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg> Print Report</button>
                    <button type="button" class="report-disclosure" aria-label="Collapse department report" title="Collapse department report" aria-expanded="true" aria-controls="departmentCompletionContent"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
                </div>
            </div>
            <div class="report-panel-note">A folder is complete when all assigned subjects have a file.</div>
            <div class="report-content" id="departmentCompletionContent">
            <div class="department-chart">
                <?php foreach ($departments as $department):
                    $department_id = (int) $department['id'];
                    $metrics = $department_metrics[$department_id];
                    $completion = $metrics['completion_percent'];
                    $completion_color = $completion >= 100 ? '#15803d' : ($completion > 0 ? '#c2410c' : '#6b7280');
                ?>
                <div class="department-chart-item">
                    <div class="department-chart-value" style="--completion-color:<?= $completion_color ?>"><?= $completion ?>%</div>
                    <div class="department-chart-plot" role="progressbar" aria-label="<?= sanitize($department['code']) ?> completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $completion ?>"><div class="department-chart-bar" style="--completion-color:<?= $completion_color ?>;--bar-height:<?= $completion ?>%"></div></div>
                    <div class="department-chart-name"><?= sanitize($department['name']) ?></div>
                    <div class="department-chart-details"><?= sanitize($department['code']) ?><br><?= number_format($metrics['completed_folder_requirements']) ?>/<?= number_format($metrics['required_folder_requirements']) ?> folders</div>
                </div>
                <?php endforeach; ?>
            </div>
            </div>
        </section>

        <section class="report-panel" aria-labelledby="semesterCompletionTitle">
            <div class="report-panel-header">
                <div class="report-panel-main">
                    <h2 class="report-panel-title" id="semesterCompletionTitle">Semester Submission Reports</h2>
                </div>
                <div class="report-panel-actions">
                    <button type="button" class="report-disclosure" aria-label="Collapse semester reports" title="Collapse semester reports" aria-expanded="true" aria-controls="semesterCompletionContent"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>
                </div>
            </div>
            <div class="report-panel-note">Completed versus remaining folders for each semester.</div>
            <div class="report-content" id="semesterCompletionContent">
            <?php if (!$semester_metrics): ?>
                <div class="empty-ann" style="padding:20px;box-shadow:none;">No semester submission data for this school year yet.</div>
            <?php else: ?>
                <?php foreach ($semester_metrics as $semester_name => $semester):
                    $semester_remaining = $semester['required_folder_requirements'] - $semester['completed_folder_requirements'];
                    $department_pie_palette = ['#15803d', '#ea580c', '#2563eb', '#be185d', '#0891b2', '#a16207'];
                    $semester_department_data = [];
                    $department_pie_total = 0;
                    foreach ($departments as $department_index => $department) {
                        $department_id = (int) $department['id'];
                        $department_metrics_for_semester = $semester['departments'][$department_id] ?? ['completed_folder_requirements' => 0, 'required_folder_requirements' => 0];
                        $department_completed = (int) $department_metrics_for_semester['completed_folder_requirements'];
                        $department_required = (int) $department_metrics_for_semester['required_folder_requirements'];
                        $semester_department_data[] = [
                            'code' => $department['code'],
                            'completed' => $department_completed,
                            'required' => $department_required,
                            'percent' => $department_required > 0 ? (int) round($department_completed / $department_required * 100) : 0,
                            'color' => $department_pie_palette[$department_index % count($department_pie_palette)],
                        ];
                        $department_pie_total += $department_completed;
                    }
                    $pie_stops = [];
                    $pie_completed_so_far = 0;
                    foreach ($semester_department_data as $department_data) {
                        if ($department_data['completed'] === 0 || $department_pie_total === 0) continue;
                        $pie_start = $pie_completed_so_far / $department_pie_total * 100;
                        $pie_completed_so_far += $department_data['completed'];
                        $pie_end = $pie_completed_so_far / $department_pie_total * 100;
                        $pie_stops[] = $department_data['color'] . ' ' . number_format($pie_start, 2, '.', '') . '% ' . number_format($pie_end, 2, '.', '') . '%';
                    }
                    $pie_background = $pie_stops
                        ? 'conic-gradient(' . implode(', ', $pie_stops) . ')'
                        : 'conic-gradient(#e7e5e4 0 100%)';
                ?>
                <div class="semester-report">
                    <div class="semester-pie-chart" role="img" aria-label="Completed folders by department. <?= number_format($semester['completed_folder_requirements']) ?> complete, <?= number_format($semester_remaining) ?> remaining." title="Department slices show completed-folder counts" style="background:<?= htmlspecialchars($pie_background, ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="semester-report-content">
                        <div class="semester-report-summary">
                            <span class="semester-report-name"><?= sanitize($semester_name) ?></span>
                            <span class="report-panel-note"><?= number_format($semester['completed_folder_requirements']) ?> complete · <?= number_format($semester_remaining) ?> remaining of <?= number_format($semester['required_folder_requirements']) ?> folders</span>
                        </div>
                        <div class="semester-department-legend">
                            <?php foreach ($semester_department_data as $department_data): ?>
                            <div class="semester-department-item">
                                <span class="semester-department-swatch" style="background:<?= $department_data['color'] ?>"></span>
                                <span class="semester-department-code"><?= sanitize($department_data['code']) ?></span>
                                <span class="semester-department-value"><?= number_format($department_data['completed']) ?>/<?= number_format($department_data['required']) ?> · <?= $department_data['percent'] ?>%</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </section>

        <!-- Create Announcement -->
        <div class="section-header">
            <div class="section-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;color:var(--red);"><path d="M4 4h13a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2Z"/><path d="M8 8h8"/><path d="M8 12h5"/></svg> Announcements</div>
        </div>

        <div class="create-post-card">
            <h3 id="announcement-form-title"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;color:var(--red);"><path d="M12 3v14"/><path d="M5 10h14"/></svg> Post New Announcement</h3>
            <input type="hidden" id="ann-edit-id" value="">
            <div class="form-group">
                <label for="ann-title">Title</label>
                <input type="text" id="ann-title" class="form-control" placeholder="e.g. End of Semester Reminder…" maxlength="200">
            </div>
            <div class="form-group">
                <label for="ann-body">Message</label>
                <textarea id="ann-body" class="form-control" placeholder="Write your announcement here…" rows="4"></textarea>
            </div>

            <!-- Attachments -->
            <label style="display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">Attachments (optional)</label>
            <div class="edit-file-controls" id="ann-edit-files" hidden>
                <div class="edit-attachment-control">
                    <span id="ann-current-image">No current image</span>
                    <input type="checkbox" id="remove-current-image" hidden>
                    <button type="button" class="btn-remove-attachment" id="remove-image-button" aria-pressed="false" onclick="toggleAnnouncementAttachmentRemoval('image')">Remove image</button>
                </div>
                <div class="edit-attachment-control">
                    <span id="ann-current-pdf">No current PDF</span>
                    <input type="checkbox" id="remove-current-pdf" hidden>
                    <button type="button" class="btn-remove-attachment" id="remove-pdf-button" aria-pressed="false" onclick="toggleAnnouncementAttachmentRemoval('pdf')">Remove file</button>
                </div>
            </div>
            <div class="attach-row">
                <label class="attach-btn" id="img-label" onclick="document.getElementById('ann-image').click()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;"><rect x="3" y="4" width="18" height="14" rx="2"/><path d="m3 15 4-4a2 2 0 0 1 3 0l3 3"/><path d="m14 10 1-1a2 2 0 0 1 3 0l3 3"/><circle cx="8.5" cy="8.5" r="1.5"/></svg> <span id="img-label-text">Add Image</span>
                </label>
                <button type="button" class="btn-clear-selected" id="clear-selected-image" aria-label="Remove selected image" title="Remove selected image" hidden onclick="clearSelectedAnnouncementAttachment('image')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m18 6-12 12M6 6l12 12"/></svg></button>
                <label class="attach-btn" id="pdf-label" onclick="document.getElementById('ann-pdf').click()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-8"/><path d="M9 15l3-3 3 3"/></svg> <span id="pdf-label-text">Attach PDF / Memo</span>
                </label>
                <button type="button" class="btn-clear-selected" id="clear-selected-pdf" aria-label="Remove selected file" title="Remove selected file" hidden onclick="clearSelectedAnnouncementAttachment('pdf')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m18 6-12 12M6 6l12 12"/></svg></button>
            </div>
            <input type="file" id="ann-image" accept="image/*" style="display:none" onchange="previewImg(this)">
            <input type="file" id="ann-pdf"   accept=".pdf"    style="display:none" onchange="labelPdf(this)">
            <img id="ann-img-preview" class="attach-preview-img" src="" alt="Preview">

            <button class="btn-post" id="btn-post-ann" onclick="postAnnouncement()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 5 17 10"/><line x1="12" y1="5" x2="12" y2="15"/></svg> Post Announcement
            </button>
            <button type="button" class="btn-post" id="btn-cancel-ann-edit" onclick="cancelAnnouncementEdit()" hidden>Cancel edit</button>
        </div>

        <!-- Feed -->
        <div id="ann-feed">
            <?php if (empty($announcements)): ?>
            <div class="empty-ann" id="empty-state">
                <div class="empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:40px;height:40px;color:var(--red);"><path d="M4 4h13a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H8l-4 4V6a2 2 0 0 1 2-2Z"/><path d="M8 8h8"/><path d="M8 12h5"/></svg></div>
                <div>No announcements yet. Be the first to post one!</div>
            </div>
            <?php else: ?>
            <div class="announcements-feed" id="feed-list">
                <?php foreach ($announcements as $a): ?>
                <div class="ann-card" id="ann-<?= $a['id'] ?>">
                    <div class="ann-header">
                        <div class="ann-title"><?= sanitize($a['title']) ?></div>
                        <div class="ann-actions">
                            <button type="button" class="btn-edit-ann" aria-label="Edit announcement" title="Edit announcement" onclick="editAnnouncement(<?= $a['id'] ?>)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></button>
                            <button type="button" class="btn-delete-ann" aria-label="Delete announcement" title="Delete announcement" onclick="deleteAnnouncement(<?= $a['id'] ?>)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
                        </div>
                    </div>
                    <div class="ann-body"><?= sanitize($a['body']) ?></div>
                    <?php if ($a['image_url']): ?>
                    <img src="<?= $a['image_url'] ?>" class="ann-image" alt="Attachment">
                    <?php endif; ?>
                    <?php if ($a['pdf_url']): ?>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <a href="<?= $a['pdf_url'] ?>" download target="_blank" class="ann-pdf-link" data-file-name="<?= sanitize($a['pdf_original_name'] ?: basename($a['pdf_file'])) ?>">Download Attached PDF / Memo</a>
                        <button type="button" class="btn-print-ann" aria-label="Print announcement" title="Print announcement" onclick="printAnnouncement(<?= $a['id'] ?>)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 0-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 0-2 2h-2"/><path d="M6 14h12v8H6z"/></svg></button>
                    </div>
                    <?php endif; ?>
                    <div class="ann-footer">
                        <div class="ann-meta">
                            <?php if ($a['author_photo_url']): ?>
                            <img class="author-avatar" src="<?= $a['author_photo_url'] ?>" alt="">
                            <?php else: ?>
                            <span class="author-avatar"><?= mb_strtoupper(mb_substr($a['author'], 0, 1)) ?></span>
                            <?php endif; ?>
                            <?= sanitize($a['author']) ?> &nbsp;·&nbsp;
                            <?= date('M d, Y g:i A', strtotime($a['created_at'])) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </main>
</div>

<div class="toast" id="toast"></div>

<!-- Image lightbox modal -->
<div class="img-modal" id="imgModal" aria-hidden="true" onclick="closeImgModalOnBackdrop(event)">
    <div class="img-modal-box" role="dialog" aria-modal="true" aria-label="Image preview">
        <div class="img-modal-top">
            <div class="img-modal-title" id="imgModalTitle">Image preview</div>
            <button class="img-modal-close" type="button" onclick="closeImgModal()">✕</button>
        </div>
        <div class="img-modal-imgwrap">
            <img class="img-modal-img" id="imgModalImg" alt="Announcement image preview">
        </div>
    </div>
</div>

<script>
function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + type + ' show';
    setTimeout(() => t.className = 'toast', 3000);
}

function ensureFeedList() {
    let list = document.getElementById('feed-list');
    if (!list) {
        const feed = document.getElementById('ann-feed');
        feed.innerHTML = '<div class="announcements-feed" id="feed-list"></div>';
        list = document.getElementById('feed-list');
    }
    const empty = document.getElementById('empty-state');
    if (empty) empty.remove();
    return list;
}

function previewImg(input) {
    const prev  = document.getElementById('ann-img-preview');
    const label = document.getElementById('img-label');
    const txt   = document.getElementById('img-label-text');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { prev.src = e.target.result; prev.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
        txt.textContent = input.files[0].name;
        label.classList.add('has-file');
        document.getElementById('clear-selected-image').hidden = false;
    }
}
function labelPdf(input) {
    const label = document.getElementById('pdf-label');
    const txt   = document.getElementById('pdf-label-text');
    if (input.files && input.files[0]) {
        txt.textContent = input.files[0].name;
        label.classList.add('has-file');
        document.getElementById('clear-selected-pdf').hidden = false;
    }
}

function clearSelectedAnnouncementAttachment(type) {
    const isImage = type === 'image';
    const input = document.getElementById(isImage ? 'ann-image' : 'ann-pdf');
    const label = document.getElementById(isImage ? 'img-label' : 'pdf-label');
    const labelText = document.getElementById(isImage ? 'img-label-text' : 'pdf-label-text');
    const removeButton = document.getElementById(isImage ? 'clear-selected-image' : 'clear-selected-pdf');
    input.value = '';
    label.classList.remove('has-file');
    labelText.textContent = document.getElementById('ann-edit-id').value
        ? (isImage ? 'Replace Image' : 'Replace PDF / Memo')
        : (isImage ? 'Add Image' : 'Attach PDF / Memo');
    removeButton.hidden = true;
    if (isImage) {
        const preview = document.getElementById('ann-img-preview');
        preview.src = '';
        preview.style.display = 'none';
    }
}

function toggleAnnouncementAttachmentRemoval(type) {
    const checkbox = document.getElementById(type === 'image' ? 'remove-current-image' : 'remove-current-pdf');
    const button = document.getElementById(type === 'image' ? 'remove-image-button' : 'remove-pdf-button');
    checkbox.checked = !checkbox.checked;
    button.setAttribute('aria-pressed', String(checkbox.checked));
    button.textContent = checkbox.checked ? 'Undo removal' : (type === 'image' ? 'Remove image' : 'Remove file');
}

function editAnnouncement(id) {
    const card = document.getElementById('ann-' + id);
    if (!card) return;

    editingAnnouncementId = String(id);
    document.getElementById('ann-edit-id').value = editingAnnouncementId;
    document.getElementById('ann-title').value = card.querySelector('.ann-title')?.innerText.trim() || '';
    document.getElementById('ann-body').value = card.querySelector('.ann-body')?.innerText || '';

    const image = card.querySelector('.ann-image');
    const pdf = card.querySelector('.ann-pdf-link');
    document.getElementById('ann-current-image').textContent = image ? 'An image is attached. Select a replacement or remove it.' : 'No image is currently attached.';
    document.getElementById('ann-current-pdf').textContent = pdf ? `PDF attached: ${pdf.dataset.fileName || pdf.download || pdf.href.split('/').pop()}` : 'No PDF is currently attached.';
    document.getElementById('remove-current-image').checked = false;
    document.getElementById('remove-current-image').disabled = !image;
    document.getElementById('remove-image-button').disabled = !image;
    document.getElementById('remove-image-button').setAttribute('aria-pressed', 'false');
    document.getElementById('remove-image-button').textContent = 'Remove image';
    document.getElementById('remove-current-pdf').checked = false;
    document.getElementById('remove-current-pdf').disabled = !pdf;
    document.getElementById('remove-pdf-button').disabled = !pdf;
    document.getElementById('remove-pdf-button').setAttribute('aria-pressed', 'false');
    document.getElementById('remove-pdf-button').textContent = 'Remove file';
    document.getElementById('ann-edit-files').hidden = !image && !pdf;

    document.getElementById('img-label-text').textContent = 'Replace Image';
    document.getElementById('pdf-label-text').textContent = 'Replace PDF / Memo';
    document.getElementById('announcement-form-title').innerHTML = 'Edit Announcement';
    const submitButton = document.getElementById('btn-post-ann');
    submitButton.textContent = 'Save Changes';
    submitButton.disabled = false;
    document.getElementById('btn-cancel-ann-edit').hidden = false;
    document.querySelector('.create-post-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function cancelAnnouncementEdit() {
    editingAnnouncementId = null;
    document.getElementById('ann-edit-id').value = '';
    document.getElementById('ann-title').value = '';
    document.getElementById('ann-body').value = '';
    document.getElementById('ann-image').value = '';
    document.getElementById('ann-pdf').value = '';
    document.getElementById('clear-selected-image').hidden = true;
    document.getElementById('clear-selected-pdf').hidden = true;
    document.getElementById('ann-img-preview').src = '';
    document.getElementById('ann-img-preview').style.display = 'none';
    document.getElementById('img-label-text').textContent = 'Add Image';
    document.getElementById('pdf-label-text').textContent = 'Attach PDF / Memo';
    document.getElementById('img-label').classList.remove('has-file');
    document.getElementById('pdf-label').classList.remove('has-file');
    document.getElementById('ann-edit-files').hidden = true;
    document.getElementById('remove-current-image').checked = false;
    document.getElementById('remove-current-pdf').checked = false;
    document.getElementById('remove-image-button').setAttribute('aria-pressed', 'false');
    document.getElementById('remove-image-button').textContent = 'Remove image';
    document.getElementById('remove-pdf-button').setAttribute('aria-pressed', 'false');
    document.getElementById('remove-pdf-button').textContent = 'Remove file';
    document.getElementById('announcement-form-title').innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;color:var(--red);"><path d="M12 3v14"/><path d="M5 10h14"/></svg> Post New Announcement';
    document.getElementById('btn-post-ann').innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 5 17 10"/><line x1="12" y1="5" x2="12" y2="15"/></svg> Post Announcement';
    document.getElementById('btn-cancel-ann-edit').hidden = true;
}

let editingAnnouncementId = null;

async function postAnnouncement() {
    const title    = document.getElementById('ann-title').value.trim();
    const body     = document.getElementById('ann-body').value.trim();
    const imgFile  = document.getElementById('ann-image').files[0];
    const pdfFile  = document.getElementById('ann-pdf').files[0];
    if (!title || !body) { showToast('Please fill in both fields.', 'error'); return; }

    const btn = document.getElementById('btn-post-ann');
    const editId = document.getElementById('ann-edit-id').value;
    btn.disabled = true; btn.textContent = editId ? 'Saving…' : 'Posting…';

    const fd = new FormData();
    fd.append('action', editId ? 'update' : 'create');
    fd.append('title', title);
    fd.append('body', body);
    if (editId) {
        fd.append('id', editId);
        fd.append('remove_image', document.getElementById('remove-current-image').checked ? '1' : '0');
        fd.append('remove_pdf', document.getElementById('remove-current-pdf').checked ? '1' : '0');
    }
    if (imgFile) fd.append('image_file', imgFile);
    if (pdfFile) fd.append('pdf_file',   pdfFile);

    const res  = await fetch('announcements_handler.php', { method: 'POST', body: fd });
    const data = await res.json();

    btn.disabled = false;
    btn.innerHTML = editId ? 'Save Changes' : '<span>📤</span> Post Announcement';

    if (editId && data.success) {
        showToast('Announcement updated.', 'success');
        setTimeout(() => window.location.reload(), 350);
        return;
    }

    if (data.success) {
        // Reset form
        document.getElementById('ann-title').value = '';
        document.getElementById('ann-body').value  = '';
        document.getElementById('ann-image').value = '';
        document.getElementById('ann-pdf').value   = '';
        document.getElementById('clear-selected-image').hidden = true;
        document.getElementById('clear-selected-pdf').hidden = true;
        const prev = document.getElementById('ann-img-preview');
        prev.src = ''; prev.style.display = 'none';
        document.getElementById('img-label-text').textContent = 'Add Image';
        document.getElementById('pdf-label-text').textContent = 'Attach PDF / Memo';
        document.getElementById('img-label').classList.remove('has-file');
        document.getElementById('pdf-label').classList.remove('has-file');

        const list = ensureFeedList();
        const now  = new Date().toLocaleString('en-US', {month:'short',day:'2-digit',year:'numeric',hour:'numeric',minute:'2-digit',hour12:true});
        const card = document.createElement('div');
        card.className = 'ann-card';
        card.id = 'ann-' + data.id;
        let attachHtml = '';
        if (data.image_url) attachHtml += `<img src="${data.image_url}" class="ann-image" alt="Attachment">`;
        if (data.pdf_url)   attachHtml += `<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><a href="${data.pdf_url}" download target="_blank" class="ann-pdf-link">Download Attached PDF / Memo</a><button type="button" class="btn-print-ann" aria-label="Print announcement" title="Print announcement" onclick="printAnnouncement(${data.id})"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 0-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 0-2 2h-2"/><path d="M6 14h12v8H6z"/></svg></button></div>`;
        const authorAvatar = data.author_photo_url
            ? `<img class="author-avatar" src="${data.author_photo_url}" alt="">`
            : `<span class="author-avatar"><?= mb_strtoupper(mb_substr($_SESSION['full_name'], 0, 1)) ?></span>`;
        card.innerHTML = `
            <div class="ann-header">
                <div class="ann-title">${escHtml(title)}</div>
                <div class="ann-actions">
                    <button type="button" class="btn-edit-ann" aria-label="Edit announcement" title="Edit announcement" onclick="editAnnouncement(${data.id})"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></button>
                    <button type="button" class="btn-delete-ann" aria-label="Delete announcement" title="Delete announcement" onclick="deleteAnnouncement(${data.id})"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg></button>
                </div>
            </div>
            <div class="ann-body">${escHtml(body)}</div>
            ${attachHtml}
            <div class="ann-footer">
                <div class="ann-meta">
                    ${authorAvatar}
                    <?= sanitize($_SESSION['full_name']) ?> &nbsp;·&nbsp; ${now}
                </div>
            </div>`;
        const pdfLink = card.querySelector('.ann-pdf-link');
        if (pdfLink) {
            pdfLink.download = data.pdf_original_name || data.pdf_name || '';
            pdfLink.dataset.fileName = pdfLink.download;
        }
        list.prepend(card);
        showToast('Announcement posted!', 'success');
        // Show warnings if any attachments failed
        if (data.warnings && data.warnings.length) {
            setTimeout(() => showToast('⚠️ Attachment issue: ' + data.warnings.join(' | '), 'error'), 1200);
        }
    } else {
        showToast(data.error || 'Error posting.', 'error');
    }
}

async function deleteAnnouncement(id) {
    if (!confirm('Delete this announcement?')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    const res = await fetch('announcements_handler.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.success) {
        const card = document.getElementById('ann-' + id);
        if (card) { card.style.opacity = '0'; card.style.transform = 'scale(0.96)'; card.style.transition = '0.25s'; setTimeout(()=>card.remove(), 250); }
        showToast('Announcement deleted.', 'success');
    }
}

function printSubmissionReport(button) {
    const main = document.querySelector('.main');
    const stats = main?.querySelector('.stats-row');
    const reportPanels = Array.from(main?.querySelectorAll('.report-panel') || []).slice(0, 2);
    if (!stats || reportPanels.length !== 2) return;
    const printedBy = <?= json_encode((string) ($_SESSION['full_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const printFrame = document.createElement('iframe');
    printFrame.title = 'Submission report print view';
    printFrame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(printFrame);

    const printDocument = printFrame.contentDocument;
    const sealUrl = new URL('print-assets/cvsu-seal.jpeg', window.location.href).href;
    const bagongPilipinasUrl = new URL('print-assets/bagong-pilipinas.jpeg', window.location.href).href;
    const coltraceLogoUrl = new URL('../ceit_logo.png', window.location.href).href;
    printDocument.open();
    printDocument.write(`<!doctype html><html><head><title>CollTrace - Submission Report</title><style>
        @page{size:A4;margin:13mm 15mm}
        *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}
        body{font-family:Arial,sans-serif;color:#1c1917;margin:0}
        .institution-header{display:grid;grid-template-columns:110px 1fr 110px;align-items:center;gap:10px;text-align:center}
        .institution-logo{width:100px;height:92px;object-fit:contain;justify-self:center}
        .university-copy{font-size:12px;line-height:1.25}
        .university-copy strong{display:block;font-size:20px;line-height:1.15}
        .university-copy .campus{display:block;font-size:14px;font-weight:700}
        .university-copy .website{font-style:italic}
        .college-name{margin:10px 0;font-size:17px;text-align:center}
        .header-rule{height:2px;margin:0 0 14px;border:0;background:#111}
        .coltrace-brand{display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:11px;font-weight:700;opacity:.7}
        .coltrace-brand img{width:22px;height:22px;object-fit:contain}
        h1{margin:0 0 4px;font-size:21px}
        .print-meta{margin:0 0 14px;color:#57534e;font-size:11px}
        .stats-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:12px}
        .stat-card{padding:10px;border:1px solid #d6d3d1;border-top:3px solid var(--metric-color,#f97316);border-radius:6px;break-inside:avoid}
        .stat-card .num{color:var(--metric-color,#1e2235);font-size:22px;font-weight:800}
        .stat-card .lbl{margin-top:4px;font-size:11px;font-weight:700}
        .stat-card .metric-note{margin-top:3px;color:#57534e;font-size:9px;line-height:1.4}
        .stat-meter{height:6px;margin-top:7px;overflow:hidden;border-radius:8px;background:#eeece9}
        .stat-meter-fill{height:100%;border-radius:inherit;background:var(--metric-color,#f97316)}
        .report-panel{margin:0 0 10px;padding:10px 12px;border:1px solid #d6d3d1;border-radius:6px;break-inside:auto}
        .report-panel-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
        .report-panel-title{margin:0;font-size:13px}
        .report-panel-note{margin:3px 0 8px;color:#57534e;font-size:9px;line-height:1.4}
        .report-disclosure,.btn-print-report{display:none!important}
        .department-chart{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:9px}
        .department-chart-item{display:grid;grid-template-rows:auto 100px auto auto;gap:4px;min-width:0;text-align:center;break-inside:avoid}
        .department-chart-value{color:var(--completion-color,#c2410c);font-size:10px;font-weight:800}
        .department-chart-plot{display:flex;align-items:flex-end;justify-content:center;border-bottom:1px solid #a8a29e;background:repeating-linear-gradient(to bottom,#e7e5e4 0 1px,transparent 1px 25%)}
        .department-chart-bar{width:28px;height:var(--bar-height);background:var(--completion-color,#f97316)}
        .department-chart-name{font-size:9px;font-weight:700;overflow-wrap:anywhere}
        .department-chart-details{color:#57534e;font-size:8px;line-height:1.35}
        .semester-report{display:grid;grid-template-columns:110px minmax(0,1fr);align-items:center;gap:14px}
        .semester-report+.semester-report{margin-top:10px;padding-top:10px;border-top:1px solid #e7e5e4}
        .semester-pie-chart{width:110px;height:110px;border:1px solid rgba(28,25,23,.12);border-radius:50%}
        .semester-report-content{display:grid;gap:8px;min-width:0}
        .semester-report-summary{display:grid;gap:3px}
        .semester-report-name{font-size:11px;font-weight:750}
        .semester-department-legend{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px 12px}
        .semester-department-item{display:grid;grid-template-columns:9px minmax(36px,.55fr) minmax(0,1fr);align-items:center;gap:5px;font-size:9px}
        .semester-department-swatch{width:9px;height:9px;border-radius:2px}
        .semester-department-code{font-weight:700}
        .semester-department-value{color:#57534e;text-align:right}
        .print-footer{margin-top:14px;padding-top:8px;border-top:1px solid #a8a29e;color:#57534e;font-size:10px;text-align:left}
        @media(max-width:650px){.institution-header{grid-template-columns:60px 1fr 60px;gap:4px}.institution-logo{width:56px;height:62px}.university-copy{font-size:9px}.university-copy strong{font-size:14px}.university-copy .campus{font-size:10px}.college-name{font-size:13px}.department-chart{grid-template-columns:repeat(2,minmax(0,1fr))}}
    </style></head><body>`);
    printDocument.write('<header class="institution-header"><img class="institution-logo" src="' + sealUrl + '" alt="Cavite State University seal"><div class="university-copy">Republic of the Philippines<strong>CAVITE STATE UNIVERSITY</strong><span class="campus">Don Severino de las Alas Campus</span>Indang, Cavite<br>(046) 483-9250<br><span class="website">www.cvsu.edu.ph</span></div><img class="institution-logo" src="' + bagongPilipinasUrl + '" alt="Bagong Pilipinas"></header><h2 class="college-name">COLLEGE OF ENGINEERING AND INFORMATION TECHNOLOGY</h2><hr class="header-rule"><div class="coltrace-brand"><img src="' + coltraceLogoUrl + '" alt="CollTrace logo"><span>CollTrace - Submission Report</span></div><h1>Submission Report</h1><p class="print-meta"></p><main class="print-report-content"></main><footer class="print-footer"></footer></body></html>');

    const schoolYear = button.dataset.schoolYear;
    const printMeta = printDocument.querySelector('.print-meta');
    printMeta.textContent = (schoolYear ? 'School Year ' + schoolYear + ' · ' : '') + 'Printed ' + new Date().toLocaleDateString();
    printDocument.querySelector('.print-footer').textContent = 'Printed by: ' + printedBy;

    const reportContent = printDocument.querySelector('.print-report-content');
    const statsClone = stats.cloneNode(true);
    reportContent.appendChild(statsClone);
    reportPanels.forEach((panel) => {
        const panelClone = panel.cloneNode(true);
        panelClone.querySelectorAll('.report-content').forEach((content) => { content.hidden = false; });
        panelClone.querySelectorAll('.report-disclosure,.btn-print-report').forEach((control) => control.remove());
        reportContent.appendChild(panelClone);
    });

    printDocument.close();
    printFrame.contentWindow.addEventListener('afterprint', () => printFrame.remove(), { once: true });
    setTimeout(() => {
        printFrame.contentWindow.focus();
        printFrame.contentWindow.print();
    }, 350);
}

function printAnnouncement(id) {
    const card = document.getElementById('ann-' + id);
    if (!card) return;
    const printedBy = <?= json_encode((string) ($_SESSION['full_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const printFrame = document.createElement('iframe');
    printFrame.title = 'Announcement print view';
    printFrame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
    document.body.appendChild(printFrame);
    const printDocument = printFrame.contentDocument;
    const sealUrl = new URL('print-assets/cvsu-seal.jpeg', window.location.href).href;
    const bagongPilipinasUrl = new URL('print-assets/bagong-pilipinas.jpeg', window.location.href).href;
    const coltraceLogoUrl = new URL('../ceit_logo.png', window.location.href).href;
    printDocument.open();
    printDocument.write(`<!doctype html><html><head><title>CollTrace - Announcement</title><style>
        @page{size:A4;margin:0}
        *{box-sizing:border-box}
        body{font-family:Arial,sans-serif;color:#111;max-width:1000px;margin:0 auto;padding:14mm 16mm}
        .institution-header{display:grid;grid-template-columns:150px 1fr 150px;align-items:center;gap:12px;text-align:center}
        .institution-logo{width:140px;height:120px;object-fit:contain;justify-self:center}
        .university-copy{font-size:16px;line-height:1.25}
        .university-copy strong{display:block;font-size:26px;line-height:1.15}
        .university-copy .campus{display:block;font-size:18px;font-weight:700}
        .university-copy .website{font-style:italic}
        .college-name{margin:16px 0 12px;font-size:23px;line-height:1.2;text-align:center}
        .header-rule{height:3px;margin:0 0 24px;border:0;background:#111}
        .coltrace-brand{display:flex;align-items:center;gap:6px;margin:0 0 28px;font-size:12px;font-weight:700;opacity:.7}
        .coltrace-brand img{width:24px;height:24px;object-fit:contain;margin:0}
        h1{font-size:26px;margin:0 0 12px;padding-bottom:12px;border-bottom:1px solid #d6d3d1}
        .body{font-size:15px;line-height:1.65;white-space:pre-wrap;margin:20px 0}
        .attachment-name{margin-top:20px;font-size:12px;color:#444;overflow-wrap:anywhere}
        .print-footer{margin-top:30px;padding-top:8px;border-top:1px solid #a8a29e;color:#57534e;font-size:10px;text-align:left}
        img{max-width:100%;max-height:420px;object-fit:contain;margin:12px 0}
        a{color:#c2410c}
        @media(max-width:650px){.institution-header{grid-template-columns:72px 1fr 72px;gap:5px}.institution-logo{width:70px;height:78px}.university-copy{font-size:11px}.university-copy strong{font-size:16px}.university-copy .campus{font-size:12px}.college-name{font-size:16px}}
    </style></head><body>`);
    printDocument.write('<header class="institution-header"><img class="institution-logo" src="' + sealUrl + '" alt="Cavite State University seal"><div class="university-copy">Republic of the Philippines<strong>CAVITE STATE UNIVERSITY</strong><span class="campus">Don Severino de las Alas Campus</span>Indang, Cavite<br>(046) 483-9250<br><span class="website">www.cvsu.edu.ph</span></div><img class="institution-logo" src="' + bagongPilipinasUrl + '" alt="Bagong Pilipinas"></header><h2 class="college-name">COLLEGE OF ENGINEERING AND INFORMATION TECHNOLOGY</h2><hr class="header-rule">');
    const logoStyle = printDocument.createElement('style');
    logoStyle.textContent = '.institution-header{grid-template-columns:220px minmax(0,1fr) 220px}.institution-logo{height:auto;object-fit:contain}.institution-logo:first-of-type{width:140px;height:120px}.institution-logo:last-of-type{width:220px;height:147px}@media(max-width:650px){.institution-header{grid-template-columns:120px minmax(0,1fr) 120px}.institution-logo:first-of-type{width:70px;height:78px}.institution-logo:last-of-type{width:120px;height:80px}}';
    printDocument.head.appendChild(logoStyle);
    printDocument.write('<div class="coltrace-brand"><img src="' + coltraceLogoUrl + '" alt="CollTrace logo"><span>CollTrace - Announcement</span></div>');
    printDocument.write('<h1>' + (card.querySelector('.ann-title')?.textContent || 'Announcement') + '</h1>');
    printDocument.write('<div class="body">' + (card.querySelector('.ann-body')?.innerHTML || '') + '</div>');
    const image = card.querySelector('.ann-image');
    if (image) printDocument.write('<img src="' + image.src + '" alt="Announcement attachment">');
    const pdf = card.querySelector('.ann-pdf-link');
    if (pdf) {
        const fileName = pdf.dataset.fileName || pdf.download || pdf.href.split('/').pop();
        printDocument.write('<p class="attachment-name">' + escHtml(fileName) + '</p>');
    }
    printDocument.write('<footer class="print-footer"></footer></body></html>');
    printDocument.querySelector('.print-footer').textContent = 'Printed by: ' + printedBy + ' · ' + new Date().toLocaleString();
    printDocument.close();
    printFrame.contentWindow.addEventListener('afterprint', () => printFrame.remove(), { once: true });
    setTimeout(() => {
        printFrame.contentWindow.focus();
        printFrame.contentWindow.print();
    }, 300);
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;').replace(/\n/g,'<br>');
}

// ── Announcement image lightbox (works for dynamically inserted cards too) ──
function openImgModal(src, title='Announcement image') {
    const modal = document.getElementById('imgModal');
    const img   = document.getElementById('imgModalImg');
    const ttl   = document.getElementById('imgModalTitle');
    img.src = src;
    ttl.textContent = title;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}
function closeImgModal() {
    const modal = document.getElementById('imgModal');
    const img   = document.getElementById('imgModalImg');
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    // clear after animation frame so it doesn't flash
    setTimeout(() => { img.src = ''; }, 50);
}
function closeImgModalOnBackdrop(e) {
    if (e.target === document.getElementById('imgModal')) closeImgModal();
}
document.addEventListener('click', (e) => {
    const t = e.target;
    if (t && t.classList && t.classList.contains('ann-image')) {
        openImgModal(t.src, t.alt || 'Announcement image');
    }
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const modal = document.getElementById('imgModal');
        if (modal && modal.classList.contains('open')) closeImgModal();
    }
});
</script>
<script>
(() => {
    const chevronIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
    const reportStorageKey = 'admin-collapsed-submission-reports';
    let collapsedReports = {};

    try {
        collapsedReports = JSON.parse(localStorage.getItem(reportStorageKey) || '{}');
    } catch (error) {
        collapsedReports = {};
    }

    document.querySelectorAll('.report-disclosure').forEach((button) => {
        const reportId = button.getAttribute('aria-controls');
        const content = document.getElementById(reportId);
        if (!content) return;

        const setCollapsed = (collapsed) => {
            content.hidden = collapsed;
            button.setAttribute('aria-expanded', String(!collapsed));
            const reportName = reportId === 'departmentCompletionContent' ? 'department report' : 'semester reports';
            button.setAttribute('aria-label', `${collapsed ? 'Expand' : 'Collapse'} ${reportName}`);
            button.title = `${collapsed ? 'Expand' : 'Collapse'} ${reportName}`;
            button.innerHTML = chevronIcon;
            collapsedReports[reportId] = collapsed;
            try {
                localStorage.setItem(reportStorageKey, JSON.stringify(collapsedReports));
            } catch (error) {
                // Keep the in-page report toggle working if storage is unavailable.
            }
        };

        setCollapsed(collapsedReports[reportId] === true);
        button.addEventListener('click', () => setCollapsed(!content.hidden));
    });
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const loader = document.getElementById('loadingScreen');
    if (loader) {
        setTimeout(() => {
            loader.classList.add('hidden');
            document.body.classList.add('page-loaded');
        }, 900);
    } else {
        document.body.classList.add('page-loaded');
    }
});
</script>
<script>
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
