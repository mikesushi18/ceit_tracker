<?php
// profile.php — My Information (all roles)
require_once 'includes/config.php';
require_once 'includes/otp_helper.php';

if (!isLoggedIn()) { redirect(BASE_URL . '/index.php'); }

// Ensure profile_photo column exists
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL");

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];

// Dashboard back link per role
$back = match($role) {
    'admin'       => BASE_URL . '/admin/dashboard.php',
    'sub-admin'   => BASE_URL . '/admin/dashboard.php',
    'chairperson' => BASE_URL . '/chairperson/dashboard.php',
    default       => BASE_URL . '/faculty/dashboard.php',
};

$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_email_change') {
    $newEmail = strtolower(trim($_POST['new_email'] ?? ''));
    if (empty($user_id)) {
        $err = 'Invalid account.';
    } elseif (!isAllowedEmail($newEmail)) {
        $err = 'Please enter an allowed email address.';
    } elseif ($newEmail === strtolower((string) ($_SESSION['email'] ?? ''))) {
        $err = 'The new email must be different from your current email.';
    } else {
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $stmt->bind_param('si', $newEmail, $user_id);
        $stmt->execute();
        $emailTaken = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $currentUser = $conn->query("SELECT id, full_name, email FROM users WHERE id = $user_id")->fetch_assoc();
        if ($emailTaken) {
            $err = 'That email address is already linked to another account.';
        } elseif (!$currentUser || empty($currentUser['email'])) {
            $err = 'Your account has no current email address for verification.';
        } else {
            [$sent, $sendErr] = requestOtp($conn, $currentUser, 'email_change');
            if ($sent) {
                $_SESSION['email_change_new'] = $newEmail;
                $_SESSION['email_change_step'] = 'verify';
                $msg = 'An OTP was sent to your current email address.';
            } else {
                $err = $sendErr;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_email_change') {
    $code = trim($_POST['email_change_otp'] ?? '');
    $newEmail = $_SESSION['email_change_new'] ?? '';
    if (!$newEmail || ($_SESSION['email_change_step'] ?? '') !== 'verify') {
        $err = 'Request an email change OTP first.';
    } else {
        [$verified, $verifyError] = verifyOtp($conn, $user_id, $code, 'email_change');
        if ($verified) {
            $stmt = $conn->prepare('UPDATE users SET email = ? WHERE id = ?');
            $stmt->bind_param('si', $newEmail, $user_id);
            if ($stmt->execute()) {
                $_SESSION['email'] = $newEmail;
                $msg = 'Email address updated successfully.';
                unset($_SESSION['email_change_new'], $_SESSION['email_change_step']);
            } else {
                $err = 'Could not update the email address.';
            }
            $stmt->close();
        } else {
            $err = $verifyError;
        }
    }
}

// Handle photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo'])) {
    $file = $_FILES['photo'];
    $allowed_types = ['image/jpeg','image/png','image/gif','image/webp'];
    $max_size = 2 * 1024 * 1024;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $err = 'Upload failed. Please try again.';
    } elseif (!in_array($file['type'], $allowed_types)) {
        $err = 'Only JPG, PNG, GIF, or WEBP images are allowed.';
    } elseif ($file['size'] > $max_size) {
        $err = 'Image must be under 2MB.';
    } else {
        $ext   = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $dir   = __DIR__ . '/uploads/avatars/';
        $fname = 'user_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest  = $dir . $fname;
        $previous_photo = $conn->query("SELECT profile_photo FROM users WHERE id = $user_id")->fetch_assoc()['profile_photo'] ?? null;
        if (storageMoveUploadedFile($file['tmp_name'], $dest)) {
            $stmt = $conn->prepare('UPDATE users SET profile_photo = ? WHERE id = ?');
            $stmt->bind_param('si', $fname, $user_id);
            if ($stmt->execute()) {
                $_SESSION['profile_photo'] = $fname;
                if ($previous_photo && $previous_photo !== $fname) {
                    storageDelete($dir . basename($previous_photo));
                }
                $msg = 'Profile photo updated successfully!';
            } else {
                storageDelete($dest);
                $err = 'Could not update the profile photo record.';
            }
            $stmt->close();
        } else {
            $err = 'Could not save the image. Check folder permissions.';
        }
    }

    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_err'] = $err;
    header('Location: profile.php');
    exit;
}

if (isset($_SESSION['flash_msg'])) { $msg = $_SESSION['flash_msg']; unset($_SESSION['flash_msg']); }
if (isset($_SESSION['flash_err'])) { $err = $_SESSION['flash_err']; unset($_SESSION['flash_err']); }

// Re-fetch user data
$user = $conn->query("
    SELECT u.*, d.name AS dept_name
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.id = $user_id
")->fetch_assoc();
$active_sy = $conn->query("SELECT label FROM school_years WHERE status = 'active' LIMIT 1")->fetch_assoc();
$admin_departments = [];
if (in_array($role, ['admin', 'sub-admin'], true)) {
    $admin_departments = $conn->query("SELECT d.*, (SELECT COUNT(*) FROM folders f WHERE f.department_id = d.id) AS folder_count, (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.role = 'faculty') AS faculty_count FROM departments d ORDER BY d.code")->fetch_all(MYSQLI_ASSOC);
}

$photo_url = '';
if (!empty($user['profile_photo'])) {
    $photo_path = __DIR__ . '/uploads/avatars/' . $user['profile_photo'];
    if (storageExists($photo_path)) {
        $photo_url = BASE_URL . '/uploads/avatars/' . $user['profile_photo'] . '?v=' . (storageModified($photo_path) ?: 0);
    }
}

$role_labels = [
    'admin'       => 'Administrator',
    'sub-admin'   => 'Sub-admin',
    'chairperson' => 'Department Chairperson',
    'faculty'     => 'Faculty',
];

// Sidebar nav links per role — 'icon' holds raw SVG markup (same line-icon
// style used across the dashboards), not emoji.
$icon_dashboard = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>';
$icon_users     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
$icon_folder    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg>';
$icon_announce  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>';
$icon_clipboard = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6"/><path d="M9 16h6"/></svg>';
$icon_assign     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg>';

$nav_links = match($role) {
    'admin', 'sub-admin' => [
        ['href' => BASE_URL . '/admin/dashboard.php',      'icon' => $icon_dashboard, 'label' => 'Dashboard'],
        ['href' => BASE_URL . '/admin/manage_users.php',   'icon' => $icon_users,     'label' => 'Manage Users'],
        ['href' => BASE_URL . '/admin/manage_folders.php', 'icon' => $icon_folder,    'label' => 'Manage Folders'],
        ['href' => BASE_URL . '/admin/school_year.php',    'icon' => $icon_clipboard, 'label' => 'School Years'],
        ['href' => BASE_URL . '/admin/clearance_form.php', 'icon' => $icon_clipboard, 'label' => 'Clearance Forms'],
        ['href' => BASE_URL . '/admin/clearance_submissions.php', 'icon' => $icon_clipboard, 'label' => 'Clearance Submissions'],
        ['href' => BASE_URL . '/admin/dashboard.php#ann-feed', 'icon' => $icon_announce, 'label' => 'Announcements'],
    ],
    'chairperson' => [
        ['href' => BASE_URL . '/chairperson/dashboard.php',         'icon' => $icon_dashboard, 'label' => 'Dashboard'],
        ['href' => BASE_URL . '/chairperson/submission_status.php', 'icon' => $icon_clipboard, 'label' => 'Submission Status'],
        ['href' => BASE_URL . '/chairperson/assign_faculty.php',    'icon' => $icon_assign,    'label' => 'Assign Faculty'],
    ],
    default => [
        ['href' => BASE_URL . '/faculty/dashboard.php', 'icon' => $icon_dashboard, 'label' => 'Dashboard'],
        ['href' => BASE_URL . '/faculty/clearance_form.php', 'icon' => $icon_clipboard, 'label' => 'Clearance Form'],
        ['href' => BASE_URL . '/faculty/clearance_submission.php', 'icon' => $icon_clipboard, 'label' => 'Clearance Submission'],
    ],
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Information — CollTrace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --navy: #1C1917;  --navy2: #231F1B;
            --red:  #F97316;  --red2:  #EA580C;
            --bg:   #FFF7ED;  --card:  #fff;
            --text: #1e2235;  --muted: #6b7280;
            --border: #e5e7eb;
            --sidebar-w: 270px; --header-h: 62px;
        }
        body { font-family: 'Inter', sans-serif; background: var(--bg); min-height: 100vh; }

        /* HEADER */
        .header {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            height: var(--header-h); background: linear-gradient(120deg, var(--navy) 0%, #0d0b0a 100%);
            border-bottom: 2px solid var(--red);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 28px; box-shadow: 0 2px 16px rgba(0,0,0,.35);
        }
        .brand { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -0.5px; }
        .brand-logo { width: 30px; height: 30px; object-fit: contain; flex-shrink: 0; }
        .brand-text { display: inline-flex; align-items: center; }
        .brand span { color: var(--red); }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .header-right { display: flex; align-items: center; gap: 14px; }
        .role-badge { background: var(--red); color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        a.hbtn-back { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2); color: #fff; padding: 7px 16px; border-radius: 8px; font-size: 13px; text-decoration: none; transition: background .2s; }
        a.hbtn-back:hover { background: rgba(255,255,255,.22); }
        a.hbtn-back:focus-visible { outline: 3px solid #fdba74; outline-offset: 2px; }
        a.hbtn-out { background: transparent; border: 1px solid rgba(255,255,255,.35); color: #fff; padding: 7px 16px; border-radius: 8px; font-size: 13px; text-decoration: none; }

        /* LAYOUT */
        .layout { display: flex; padding-top: var(--header-h); min-height: 100vh; }

        /* SIDEBAR */
        .sidebar {
            width: var(--sidebar-w); background: var(--navy2);
            position: fixed; top: var(--header-h); left: 0; bottom: 0;
            overflow-y: auto; display: flex; flex-direction: column;
            box-shadow: 2px 0 20px rgba(0,0,0,.18);
        }
        .acc-section { padding: 26px 20px 20px; border-bottom: 1px solid rgba(255,255,255,.07); }
        .acc-avatar {
            width: 54px; height: 54px; border-radius: 50%;
            background: linear-gradient(135deg, #F97316, #FB923C);
            color: #fff; font-size: 22px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 16px; box-shadow: 0 4px 12px rgba(232,65,24,.35);
            overflow: hidden;
        }
        .acc-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .profile-avatar-link { display: block; width: fit-content; margin-bottom: 0; position: relative; }
        .profile-avatar-edit { position: absolute; right: -1px; bottom: -1px; width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; background: var(--red); border: 2px solid var(--navy2); border-radius: 50%; color: #fff; }
        .acc-name { font-size: 15px; line-height: 1.35; font-weight: 700; color: #e8eaf0; margin-bottom: 7px; }
        .acc-role { font-size: 12px; line-height: 1.4; color: #9ba3be; margin-bottom: 5px; }
        .acc-dept { font-size: 12px; line-height: 1.45; color: #7da3ff; font-weight: 600; }
        .acc-school-year { margin-top: 8px; background: rgba(232,65,24,.15); border: 1px solid rgba(232,65,24,.3); border-radius: 6px; padding: 5px 10px; display: inline-flex; align-items: center; gap: 6px; }
        .acc-school-year .label { font-size: 10px; color: #8a93ae; font-weight: 700; text-transform: uppercase; }
        .acc-school-year .value { font-size: 12px; font-weight: 700; color: #fff; }
        .sb-label { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; color: #5a6480; text-transform: uppercase; padding: 22px 20px 10px; }
        .sb-link {
            display: flex; align-items: center; gap: 10px;
            padding: 13px 20px; color: #9ba3be; text-decoration: none;
            font-size: 13px; font-weight: 500;
            border-left: 3px solid transparent; transition: all .2s;
        }
        .sb-link:hover { background: rgba(249,115,22,.1); color: #fff; border-left-color: var(--red); }
        .sb-link.active { background: rgba(249,115,22,.15); color: #fff; border-left-color: var(--red); }
        .sb-icon { display: inline-flex; width: 15px; height: 15px; color: var(--red); flex-shrink: 0; }
        .sb-icon svg { width: 15px; height: 15px; display: block; }
        .sb-logout a svg { width: 15px; height: 15px; flex-shrink: 0; color: var(--red); }
        .alert-ok svg, .alert-err svg { width: 16px; height: 16px; flex-shrink: 0; }
        .sb-logout { margin-top: auto; padding: 14px 18px; border-top: 1px solid rgba(255,255,255,.07); }
        .sb-logout a { display: flex; align-items: center; gap: 9px; color: #f87171; font-size: 13px; font-weight: 500; text-decoration: none; padding: 9px 12px; border-radius: 8px; transition: background .2s; }
        .sb-logout a:hover { background: rgba(248,113,113,.12); }
        .sb-logout a.sb-info-link { color: #c4cadd; margin-bottom: 6px; }
        .sb-logout a.sb-info-link:hover { background: rgba(249,115,22,.1); color: #fff; }
        .admin-sy-card, .admin-create-card { display: flex; align-items: center; justify-content: space-between; margin: 16px 14px 4px; padding: 10px 14px; border-radius: 10px; text-decoration: none; transition: border-color .2s; }
        .admin-sy-card { background: linear-gradient(135deg, rgba(232,65,24,.18), rgba(232,65,24,.08)); border: 1px solid rgba(232,65,24,.3); }
        .admin-create-card { margin-top: 10px; background: linear-gradient(135deg, rgba(249,115,22,.22), rgba(249,115,22,.1)); border: 1.5px solid rgba(249,115,22,.45); }
        .admin-sy-card:hover, .admin-create-card:hover { border-color: rgba(249,115,22,.8); }
        .admin-card-label { font-size: 10px; color: #8a93ae; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }
        .admin-card-value { font-size: 16px; font-weight: 800; color: #fff; margin-top: 2px; }
        .admin-create-card .admin-card-label { color: #FB923C; }
        .admin-create-card .admin-card-value { font-size: 12px; font-weight: 600; }
        .admin-card-icon { width: 34px; height: 34px; border-radius: 8px; background: rgba(249,115,22,.16); display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: #FB923C; }
        .admin-dept-link { display: flex; align-items: center; gap: 12px; padding: 13px 20px; color: #9ba3be; text-decoration: none; font-size: 13px; font-weight: 500; border-left: 3px solid transparent; transition: all .2s; }
        .admin-dept-link:hover { background: rgba(232,65,24,.12); color: #fff; border-left-color: var(--red); }
        .admin-dept-icon { width: 34px; height: 34px; background: rgba(255,255,255,.06); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 800; color: #c9d0e4; flex-shrink: 0; }
        .admin-dept-info { flex: 1; min-width: 0; }
        .admin-dept-code { font-weight: 700; font-size: 13px; color: #e2e6f0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .admin-dept-sub { font-size: 11px; color: #6b7590; margin-top: 1px; }
        .admin-dept-arrow { background: rgba(255,255,255,.08); color: #8d96b0; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
        .admin-divider { border: none; border-top: 1px solid rgba(255,255,255,.06); margin: 10px 16px; }
        .admin-bottom { padding: 0 14px; }
        .admin-bottom a { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: 8px; color: #8a93ae; font-size: 13px; font-weight: 500; text-decoration: none; transition: all .2s; }
        .admin-bottom a:hover { background: rgba(255,255,255,.07); color: #fff; }
        .admin-bottom svg { width: 16px; height: 16px; color: #8a93ae; flex-shrink: 0; }
        .admin-sidebar { max-height: calc(100vh - var(--header-h)); padding: 0 0 24px 0; bottom: auto; }
        .sidebar-section-label { font-size: 10px; font-weight: 700; letter-spacing: 1.5px; color: #5a6480; text-transform: uppercase; padding: 22px 20px 8px; }
        .admin-sy-card { margin: 16px 14px 4px; padding: 10px 14px; border: 1px solid rgba(232,65,24,.3); border-radius: 10px; }
        .admin-create-card { margin: 10px 14px 4px; padding: 10px 14px; border: 1.5px solid rgba(249,115,22,.45); border-radius: 10px; }
        .admin-dept-link { gap: 12px; padding: 13px 20px; border-left: 3px solid transparent; }
        .admin-dept-icon { width: 34px; height: 34px; border-radius: 8px; }
        .admin-dept-arrow { padding: 2px 8px; border-radius: 10px; }
        .admin-divider { margin: 10px 16px; }
        .admin-bottom { padding: 0 14px; }
        .admin-bottom a { gap: 10px; padding: 11px 14px; border-radius: 8px; }
        .sidebar::-webkit-scrollbar { width: 10px; height: 10px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.18); border: 2px solid transparent; border-radius: 20px; background-clip: padding-box; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: var(--red); }
        .sidebar { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.25) transparent; }

        /* MAIN */
        .main { margin-left: var(--sidebar-w); flex: 1; padding: 32px 36px; min-width: 0; display: flex; justify-content: center; }
        .container { width: 100%; max-width: 620px; }

        .page-title { font-size: 22px; font-weight: 800; color: var(--text); margin-bottom: 4px; }
        .page-sub { font-size: 13px; color: var(--muted); margin-bottom: 24px; }

        /* ALERTS */
        .alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 9px; }
        .alert-ok  { background: #f0fdf4; border: 1.5px solid #bbf7d0; color: #166534; }
        .alert-err { background: #fff5f5; border: 1.5px solid #fecaca; color: #b91c1c; }

        /* PROFILE CARD */
        .profile-card { background: var(--card); border-radius: 18px; box-shadow: 0 4px 24px rgba(0,0,0,.08); overflow: hidden; }

        .photo-section {
            background-image: linear-gradient(120deg, rgba(0,0,0,.90), rgba(28,25,23,.68)), url('ceit_bg.png');
            background-size: 100% 100%;
            background-position: center;
            padding: 36px 28px 28px;
            display: flex; align-items: flex-end; gap: 22px;
        }
        .photo-wrap { position: relative; flex-shrink: 0; }
        .profile-photo { width: 90px; height: 90px; border-radius: 50%; border: 3.5px solid rgba(249,115,22,.7); object-fit: cover; display: block; }
        .photo-letter {
            width: 90px; height: 90px; border-radius: 50%;
            background: linear-gradient(135deg, #F97316, #EA580C);
            color: #fff; font-size: 34px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            border: 3.5px solid rgba(249,115,22,.7);
            box-shadow: 0 4px 16px rgba(249,115,22,.4);
        }
        .photo-overlay {
            position: absolute; inset: 0; border-radius: 50%;
            background: rgba(0,0,0,.45); color: #fff;
            display: flex; align-items: center; justify-content: center;
            opacity: 0; transition: opacity .2s; cursor: pointer;
        }
        .photo-wrap:hover .photo-overlay { opacity: 1; }
        .photo-name { font-size: 20px; font-weight: 800; color: #fff; margin-bottom: 4px; }
        .photo-role { font-size: 13px; color: #a8a29e; }
        .photo-dept { font-size: 13px; color: #FB923C; font-weight: 600; margin-top: 2px; }

        .upload-section { padding: 20px 28px 22px; border-bottom: 1px solid var(--border); background: #fffbf7; }
        .upload-label { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .7px; margin-bottom: 10px; display: block; }
        .upload-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .file-input-btn { display: inline-flex; align-items: center; gap: 7px; background: var(--navy); color: #fff; padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background .2s; }
        .file-input-btn:hover { background: #2d2520; }
        #photoInput { display: none; }
        #fileName { font-size: 13px; color: var(--muted); }
        .btn-save-photo { background: linear-gradient(135deg, #F97316, #EA580C); color: #fff; border: none; padding: 9px 20px; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; font-family: inherit; box-shadow: 0 3px 10px rgba(249,115,22,.35); transition: all .2s; display: none; }
        .btn-save-photo:hover { transform: translateY(-1px); }
        .upload-hint { font-size: 11px; color: #c4b9b0; margin-top: 8px; }

        .info-section { padding: 24px 28px 28px; }
        .security-row {
            display: flex; align-items: center; justify-content: space-between; gap: 20px;
            flex-wrap: wrap;
            background: var(--bg); border: 1px solid var(--border); border-radius: 12px;
            padding: 16px 18px;
        }
        .security-row-label { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 2px; }
        .security-row-sub { font-size: 12.5px; color: var(--muted); }
        .btn-change-pw {
            display: inline-flex; align-items: center; gap: 7px; flex-shrink: 0;
            background: linear-gradient(135deg, var(--red), var(--red2)); color: #fff;
            padding: 10px 18px; border-radius: 9px; font-size: 13px; font-weight: 700;
            border: none; font-family: inherit; cursor: pointer; text-decoration: none; box-shadow: 0 4px 12px rgba(249,115,22,.3);
            transition: transform .15s, box-shadow .15s;
        }
        .btn-change-pw:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(249,115,22,.4); }
        .info-title { font-size: 13px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .7px; margin-bottom: 16px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .info-item { display: flex; flex-direction: column; gap: 4px; }
        .info-item.full { grid-column: 1 / -1; }
        .info-key { font-size: 11px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .7px; }
        .info-val { font-size: 14px; font-weight: 600; color: var(--text); background: #fafaf9; border: 1.5px solid var(--border); border-radius: 8px; padding: 10px 13px; }
        .info-val.role-pill { display: inline-flex; align-items: center; gap: 6px; width: fit-content; color: var(--red2); background: #FFF7ED; border-color: #fed7aa; font-weight: 700; }
        .email-change-box { margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--border); }
        .email-change-row { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
        .email-change-row .form-control { flex: 1; min-width: 0; width: 100%; padding: 10px 13px; border: 1.5px solid var(--border); border-radius: 8px; font: inherit; color: var(--text); background: #fafaf9; outline: none; }
        .email-change-row .form-control:focus { border-color: var(--red); background: #fff; }
        .email-verify-form { margin-top: 16px; }
        @media (max-width: 600px) {
            .header { padding: 0 12px; }
            .header-left, .header-right { gap: 8px; }
            .brand { gap: 7px; font-size: 18px; }
            .brand-logo { width: 26px; height: 26px; }
            .role-badge { display: none; }
            a.hbtn-back { padding: 6px 8px; font-size: 11px; }
            a.hbtn-out { padding: 6px 9px; font-size: 12px; }
            .email-change-row { align-items: stretch; flex-direction: column; }
            .email-change-row .btn-change-pw { justify-content: center; }
        }
    
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
    <img class="loading-logo" src="ceit_logo.png" alt="Loading CEIT logo">
</div>


<div class="header">
    <div class="header-left">
        <a href="<?= htmlspecialchars($back, ENT_QUOTES, 'UTF-8') ?>" class="brand" style="color:#fff;text-decoration:none"><img src="ceit_logo.png" alt="CEIT Logo" class="brand-logo"><div class="brand-text">Coll<span>Trace</span></div></a>
        <a href="<?= htmlspecialchars($back, ENT_QUOTES, 'UTF-8') ?>" class="hbtn-back">← Dashboard</a>
    </div>
    <div class="header-right">
        <span class="role-badge"><?= strtoupper($role) ?></span>
        <a href="logout.php" class="hbtn-out">Logout</a>
    </div>
</div>

<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar <?= in_array($role, ['admin', 'sub-admin'], true) ? 'admin-sidebar' : '' ?>">
        <?php if (in_array($role, ['admin', 'sub-admin'], true)): ?>
        <?php if ($active_sy): ?>
        <a href="<?= BASE_URL ?>/admin/school_year.php" class="admin-sy-card">
            <div><div class="admin-card-label">Active School Year</div><div class="admin-card-value"><?= sanitize($active_sy['label']) ?></div></div>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;color:#FB923C;"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/admin/manage_folders.php" class="admin-create-card">
            <div style="display:flex;align-items:center;gap:10px;flex:1;"><span class="admin-card-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg></span><span><span class="admin-card-label" style="display:block;">Create Folder</span><span class="admin-card-value" style="display:block;margin-top:2px;">Apply to all departments</span></span></div>
            <span class="admin-card-icon" style="width:24px;height:24px;background:rgba(255,255,255,.08);color:#fff;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><path d="M12 5v14"/><path d="M5 12h14"/></svg></span>
        </a>
        <div class="sidebar-section-label">Departments</div>
        <?php foreach ($admin_departments as $department): ?>
        <a href="<?= BASE_URL ?>/admin/department.php?id=<?= $department['id'] ?>" class="admin-dept-link">
            <div class="admin-dept-icon"><?= mb_substr($department['code'], 0, 1) ?></div>
            <div class="admin-dept-info"><div class="admin-dept-code"><?= sanitize($department['code']) ?></div><div class="admin-dept-sub"><?= $department['folder_count'] ?> folders · <?= $department['faculty_count'] ?> faculty</div></div>
            <span class="admin-dept-arrow">›</span>
        </a>
        <?php endforeach; ?>
        <hr class="admin-divider">
        <div class="admin-bottom">
            <a href="<?= BASE_URL ?>/admin/school_year.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>School Years</a>
            <a href="<?= BASE_URL ?>/admin/manage_folders.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h6l2 2h10v8a2 2 0 0 1-2 2H3z"/><path d="M3 7v10a2 2 0 0 0 2 2h14"/></svg>Manage Folders</a>
            <a href="<?= BASE_URL ?>/admin/manage_users.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6"/><path d="M23 11h-6"/></svg>Manage Users</a>
            <a href="<?= BASE_URL ?>/admin/create_user.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg>Create Account</a>
            <a href="<?= BASE_URL ?>/admin/activity_log.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10"/><path d="M7 12h5"/></svg>Activity Log</a>
            <a href="<?= BASE_URL ?>/admin/clearance_form.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h6"/></svg>Clearance Form</a>
            <a href="<?= BASE_URL ?>/admin/clearance_submissions.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg>Clearance Submission</a>
        </div>
        <?php else: ?>
        <div class="acc-section">
            <a href="<?= BASE_URL ?>/profile.php" class="profile-avatar-link" title="My Information">
            <div class="acc-avatar">
                <?php if ($photo_url): ?>
                    <img src="<?= $photo_url ?>" alt="Profile">
                <?php else: ?>
                    <?= mb_strtoupper(mb_substr($user['full_name'], 0, 1)) ?>
                <?php endif; ?>
            </div>
            <span class="profile-avatar-edit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="10" height="10"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>
            </a>
            <div class="acc-name"><?= sanitize($user['full_name']) ?></div>
            <div class="acc-role"><?= $role_labels[$role] ?? $role ?></div>
            <?php if (!empty($user['dept_name'])): ?>
            <div class="acc-dept"><?= sanitize($user['dept_name']) ?></div>
            <?php endif; ?>
            <?php if ($active_sy): ?><div class="acc-school-year"><span class="label">SY</span><span class="value"><?= sanitize($active_sy['label']) ?></span></div><?php endif; ?>
        </div>

        <div class="sb-label">Navigation</div>
        <?php foreach ($nav_links as $link): ?>
        <a href="<?= $link['href'] ?>" class="sb-link">
            <span class="sb-icon"><?= $link['icon'] ?></span> <?= $link['label'] ?>
        </a>
        <?php endforeach; ?>

        <div class="sb-logout">
            <a href="<?= BASE_URL ?>/profile.php" class="sb-info-link" style="color:#fff;background:rgba(249,115,22,.15);"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> My Information</a>
            <a href="<?= BASE_URL ?>/logout.php" class="sb-logout-link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M21 19V5a2 2 0 0 0-2-2h-6"/></svg> Logout</a>
        </div>
        <?php endif; ?>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main">
    <div class="container">
        <div class="page-title">My Information</div>

        <?php if ($msg): ?>
        <div class="alert alert-ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><path d="M20 6 9 17l-5-5"/></svg> <?= $msg ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
        <div class="alert alert-err"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> <?= $err ?></div>
        <?php endif; ?>

        <div class="profile-card">

            <!-- PHOTO HEADER -->
            <div class="photo-section">
                <div class="photo-wrap" onclick="document.getElementById('photoInput').click()" title="Click to change photo">
                    <?php if ($photo_url): ?>
                        <img src="<?= $photo_url ?>" class="profile-photo" alt="Profile Photo">
                    <?php else: ?>
                        <div class="photo-letter"><?= mb_strtoupper(mb_substr($user['full_name'], 0, 1)) ?></div>
                    <?php endif; ?>
                    <div class="photo-overlay"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="24" height="24"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg></div>
                </div>
                <div>
                    <div class="photo-name"><?= sanitize($user['full_name']) ?></div>
                    <div class="photo-role"><?= $role_labels[$role] ?? $role ?></div>
                    <?php if ($user['dept_name']): ?>
                    <div class="photo-dept"><?= sanitize($user['dept_name']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- PHOTO UPLOAD -->
            <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Save this profile photo?')">
                <div class="upload-section">
                    <span class="upload-label">Change Profile Photo</span>
                    <div class="upload-row">
                        <label class="file-input-btn" for="photoInput"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg> Choose Image</label>
                        <input type="file" name="photo" id="photoInput" accept="image/jpeg,image/png,image/gif,image/webp" onchange="onFileChange(this)">
                        <span id="fileName">No file chosen</span>
                        <button type="submit" class="btn-save-photo" id="saveBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="vertical-align:-2px;margin-right:4px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/></svg>Save Photo</button>
                    </div>
                </div>
            </form>

            <!-- USER INFO (READ-ONLY) -->
            <div class="info-section">
                <div class="info-title">Account Information</div>
                <div class="info-grid">
                    <div class="info-item full">
                        <span class="info-key">Full Name</span>
                        <span class="info-val"><?= sanitize($user['full_name']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-key">Username</span>
                        <span class="info-val">@<?= sanitize($user['username']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-key">Email</span>
                        <span class="info-val"><?= $user['email'] ? sanitize($user['email']) : 'Not set' ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-key">Role</span>
                        <span class="info-val role-pill"><?= $role_labels[$role] ?? sanitize($role) ?></span>
                    </div>
                    <?php if ($user['dept_name']): ?>
                    <div class="info-item full">
                        <span class="info-key">Department</span>
                        <span class="info-val"><?= sanitize($user['dept_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <span class="info-key">Account Status</span>
                        <span class="info-val" style="color:#15803d;background:#f0fdf4;border-color:#bbf7d0;">● Active</span>
                    </div>
                    <div class="info-item">
                        <span class="info-key">Member Since</span>
                        <span class="info-val"><?= isset($user['created_at']) ? date('F d, Y', strtotime($user['created_at'])) : 'N/A' ?></span>
                    </div>
                </div>
                <div class="email-change-box">
                    <form method="POST" onsubmit="return confirm('Send an OTP to your current email address?')">
                        <input type="hidden" name="action" value="request_email_change">
                        <label class="info-key" for="newEmail">Change Email Address</label>
                        <div class="email-change-row">
                            <input class="form-control" type="email" id="newEmail" name="new_email" placeholder="New email address" required>
                            <button class="btn-change-pw" type="submit">Send OTP</button>
                        </div>
                    </form>
                    <?php if (($_SESSION['email_change_step'] ?? '') === 'verify'): ?>
                    <form method="POST" class="email-verify-form" onsubmit="return confirm('Verify and save this new email address?')">
                        <input type="hidden" name="action" value="verify_email_change">
                        <label class="info-key" for="emailChangeOtp">OTP Code</label>
                        <div class="email-change-row">
                            <input class="form-control" type="text" id="emailChangeOtp" name="email_change_otp" inputmode="numeric" maxlength="<?= OTP_LENGTH ?>" required>
                            <button class="btn-change-pw" type="submit">Verify Email</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SECURITY -->
            <div class="info-section" style="border-top: 1px solid var(--border); padding-top: 24px;">
                <div class="info-title">Security</div>
                <div class="security-row">
                    <div>
                        <div class="security-row-label">Password</div>
                        <div class="security-row-sub">Change your password using an OTP sent to your linked email.</div>
                    </div>
                    <a href="<?= BASE_URL ?>/forgot_password.php" class="btn-change-pw">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="15" height="15"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Change Password
                    </a>
                </div>
            </div>

        </div><!-- /.profile-card -->
    </div>
    </main>

</div>

<script>
function onFileChange(input) {
    const name = input.files[0] ? input.files[0].name : 'No file chosen';
    document.getElementById('fileName').textContent = name;
    document.getElementById('saveBtn').style.display = input.files[0] ? 'inline-flex' : 'none';
}
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
