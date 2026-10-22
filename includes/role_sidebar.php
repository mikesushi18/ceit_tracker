<?php

function injectRoleSidebar(string $html): string
{
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['admin', 'sub-admin', 'chairperson', 'faculty'], true)) {
        return $html;
    }
    $bodyEnd = strripos($html, '</body>');
    if ($bodyEnd === false) {
        return $html;
    }

    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $sidebarExcludedPages = [
        '/chairperson/assign_subjects.php',
        '/faculty/clearance_form.php',
        '/faculty/clearance_submission.php',
        '/faculty/subjects.php',
        '/faculty/delegate_upload.php',
        '/notifications.php',
        '/admin/department.php',
        '/admin/folder_view.php',
        '/admin/school_year.php',
        '/admin/manage_folders.php',
        '/admin/manage_users.php',
        '/admin/create_user.php',
        '/admin/edit_user.php',
        '/admin/activity_log.php',
        '/admin/clearance_form.php',
        '/admin/clearance_submissions.php',
    ];
    $skipInjectedSidebar = false;
    foreach ($sidebarExcludedPages as $pagePath) {
        if (str_ends_with($scriptPath, $pagePath)) {
            $skipInjectedSidebar = true;
            break;
        }
    }

    global $conn;
    $notificationCount = 0;
    if (isset($conn) && $conn instanceof mysqli) {
        $notificationTable = $conn->query("SHOW TABLES LIKE 'file_feedback_notifications'");
        if ($notificationTable && $notificationTable->num_rows > 0) {
            $notificationStmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM file_feedback_notifications n
                JOIN file_feedback ff ON ff.id = n.feedback_id
                LEFT JOIN file_feedback parent ON parent.id = ff.parent_feedback_id
                WHERE n.recipient_id = ? AND n.is_read = 0
                  AND (
                      n.event_type NOT IN ('task_completed', 'reply')
                      OR n.recipient_id = CASE
                          WHEN n.event_type = 'reply' THEN parent.created_by
                          ELSE ff.created_by
                      END
                  )
            ");
            if ($notificationStmt) {
                $notificationStmt->bind_param('i', $_SESSION['user_id']);
                if ($notificationStmt->execute()) {
                    $notificationCount = (int) ($notificationStmt->get_result()->fetch_assoc()['total'] ?? 0);
                } else {
                    error_log('Unable to load unread file feedback notification count: ' . $notificationStmt->error);
                }
                $notificationStmt->close();
            } else {
                error_log('Unable to prepare unread file feedback notification count: ' . $conn->error);
            }
        } elseif (!$notificationTable) {
            error_log('Unable to check file feedback notification table: ' . $conn->error);
        } else {
            $notificationTable->free();
        }
    } else {
        error_log('Database connection unavailable while injecting role notifications.');
    }
    $navigationStyle = '<style>
        .header, header:not(.header) { box-sizing:border-box!important;height:62px!important;min-height:62px!important;padding:0 28px!important;font-family:Inter,"Segoe UI",Arial,sans-serif!important; }
        .header *, header:not(.header) * { font-family:Inter,"Segoe UI",Arial,sans-serif!important; }
        .header .brand, header:not(.header) .brand { display:inline-flex!important;align-items:center!important;gap:10px!important;font-size:22px!important;font-weight:800!important;line-height:1!important; }
        .header .brand img, header:not(.header) .brand img { width:32px!important;height:32px!important;object-fit:contain!important;flex:0 0 32px!important; }
        .header .header-left,.header .header-right,header:not(.header) .header-left,header:not(.header) .header-right { display:flex!important;align-items:center!important;gap:12px!important; }
        .header .btn-back,.header .hbtn,.header .hbtn-back,.header .header-link,
        header:not(.header) .btn-back,header:not(.header) .hbtn,header:not(.header) .hbtn-back,header:not(.header) .header-link {
            box-sizing:border-box!important;min-height:36px!important;padding:0 14px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;border-radius:8px!important;font-size:13px!important;font-weight:600!important;line-height:1!important;white-space:nowrap!important;
        }
        .header .btn-logout,.header .hbtn-out,.header .header-link.logout,
        header:not(.header) .btn-logout,header:not(.header) .hbtn-out,header:not(.header) .header-link.logout {
            box-sizing:border-box!important;min-height:36px!important;padding:0 14px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;border-radius:8px!important;font-size:13px!important;font-weight:600!important;line-height:1!important;white-space:nowrap!important;
        }
        @media(max-width:700px) {
            .header,header:not(.header) { height:58px!important;min-height:58px!important;padding:0 14px!important; }
            .header .brand,header:not(.header) .brand { gap:7px!important;font-size:18px!important; }
            .header .brand img,header:not(.header) .brand img { width:28px!important;height:28px!important;flex-basis:28px!important; }
            .header .header-left,.header .header-right,header:not(.header) .header-left,header:not(.header) .header-right { gap:7px!important; }
            .header .btn-back,.header .hbtn,.header .hbtn-back,.header .header-link,
            header:not(.header) .btn-back,header:not(.header) .hbtn,header:not(.header) .hbtn-back,header:not(.header) .header-link,
            .header .btn-logout,.header .hbtn-out,.header .header-link.logout,
            header:not(.header) .btn-logout,header:not(.header) .hbtn-out,header:not(.header) .header-link.logout { min-height:34px!important;padding:0 10px!important;font-size:12px!important; }
        }
    </style>';
    $feedbackStyle = '<style>
        .msg.success,.feedback-alert:not(.error),.message.success,.status.ok,.notice.success,.toast.success {
            box-sizing:border-box!important;border:1px solid #fed7aa!important;border-left:4px solid #f97316!important;
            border-radius:9px!important;background:#fff7ed!important;color:#9a3412!important;
            box-shadow:0 3px 12px rgba(124,45,18,.08)!important;
        }
        .msg.success svg,.feedback-alert:not(.error) svg,.message.success svg,.status.ok svg,.notice.success svg { color:#ea580c!important; }
        .page-title,.title,.main > h1,.container > h1,main > h1 { margin-bottom:14px!important;gap:12px!important; }
    </style>';
    $notificationButton = '<a id="coltrace-feedback-notifications" class="coltrace-notification-button" href="' . htmlspecialchars(BASE_URL . '/notifications.php', ENT_QUOTES, 'UTF-8') . '" aria-label="Notifications' . ($notificationCount > 0 ? ': ' . $notificationCount . ' unread' : '') . '" title="Notifications">' .
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>' .
        '<span id="coltrace-feedback-notification-count" class="coltrace-notification-count"' . ($notificationCount > 0 ? '' : ' hidden') . '>' . ($notificationCount > 0 ? $notificationCount : '') . '</span>' .
        '</a><style>.coltrace-notification-button{position:relative;display:inline-flex;width:36px;height:36px;flex:0 0 36px;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,.28);border-radius:9px;background:rgba(255,255,255,.08);color:#fff;text-decoration:none;transition:background .15s,border-color .15s}.coltrace-notification-button:hover{background:rgba(255,255,255,.16);border-color:rgba(255,255,255,.55)}.coltrace-notification-button:focus-visible{outline:3px solid #fdba74;outline-offset:2px}.coltrace-notification-button svg{width:19px;height:19px}.coltrace-notification-count{position:absolute;top:-6px;right:-7px;min-width:18px;height:18px;padding:0 4px;border:2px solid #292524;border-radius:999px;background:#dc2626;color:#fff;text-align:center;font:700 10px/14px Inter,Segoe UI,Arial,sans-serif}.coltrace-notification-count[hidden]{display:none}</style>';
    $globalSidebar = '';
    if (!$skipInjectedSidebar && !preg_match('~<aside\b[^>]*\bclass\s*=\s*(["\'])[^"\']*\bsidebar\b~i', $html)) {
        $roleName = 'Faculty Instructor';
        $items = [];
        $folderLinks = [];
        if (in_array($role, ['admin', 'sub-admin'], true)) {
            $roleName = $role === 'sub-admin' ? 'Sub-admin' : 'Administrator';
            $items[] = ['Dashboard', '/admin/dashboard.php'];
            if (isset($conn) && $conn instanceof mysqli) {
                $departmentResult = $conn->query('SELECT id, code FROM departments ORDER BY code');
                if ($departmentResult) {
                    while ($department = $departmentResult->fetch_assoc()) {
                        $items[] = [(string) $department['code'], '/admin/department.php?id=' . (int) $department['id']];
                    }
                    $departmentResult->free();
                } else {
                    error_log('Unable to load departments for the shared sidebar: ' . $conn->error);
                }
            } else {
                error_log('Database connection unavailable while loading shared sidebar departments.');
            }
            $items = array_merge($items, [
                ['School Years', '/admin/school_year.php'],
                ['Backup Storage', '/admin/backup_storage.php'],
                ['Manage Folders', '/admin/manage_folders.php'],
                ['Manage Users', '/admin/manage_users.php'],
                ['Create Account', '/admin/create_user.php'],
                ['Activity Log', '/admin/activity_log.php'],
                ['Clearance Form', '/admin/clearance_form.php'],
                ['Clearance Submission', '/admin/clearance_submissions.php'],
            ]);
        } elseif ($role === 'chairperson') {
            $roleName = 'Department Chairperson';
            $items = [
                ['Dashboard', '/chairperson/dashboard.php'],
                ['Submission Status', '/chairperson/submission_status.php'],
                ['Assign Faculty', '/chairperson/assign_faculty.php'],
                ['Assign Subjects', '/chairperson/assign_subjects.php'],
                ['Department Subjects', '/chairperson/subjects.php'],
            ];
            $departmentId = (int) ($_SESSION['department_id'] ?? 0);
            if ($departmentId > 0 && isset($conn) && $conn instanceof mysqli) {
                $groupTable = $conn->query("SHOW TABLES LIKE 'folder_groups'");
                if (!$groupTable) {
                    error_log('Unable to check folder groups for the shared chairperson sidebar: ' . $conn->error);
                } else {
                    $hasFolderGroups = $groupTable->num_rows > 0;
                    $groupTable->free();
                    $sql = "SELECT f.id, f.folder_name FROM folders f "
                        . ($hasFolderGroups ? "LEFT JOIN folder_groups fg ON fg.id = f.group_id " : "")
                        . "WHERE f.department_id = ? AND f.school_year_id = "
                        . "(SELECT id FROM school_years WHERE status = 'active' LIMIT 1) "
                        . ($hasFolderGroups ? "AND (f.group_id IS NULL OR (fg.status = 'active' AND fg.school_year_id = f.school_year_id)) " : "")
                        . "ORDER BY f.subfolder_position ASC, f.created_at ASC";
                    $folderStmt = $conn->prepare($sql);
                    if ($folderStmt) {
                        $folderStmt->bind_param('i', $departmentId);
                        if ($folderStmt->execute()) {
                            $folderLinks = $folderStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        } else {
                            error_log('Unable to load chairperson folders for the shared sidebar: ' . $folderStmt->error);
                        }
                        $folderStmt->close();
                    } else {
                        error_log('Unable to prepare chairperson folders for the shared sidebar: ' . $conn->error);
                    }
                }
            }
        } else {
            $items = [
                ['Dashboard', '/faculty/dashboard.php'],
                ['Clearance Form', '/faculty/clearance_form.php'],
                ['Submit Clearance', '/faculty/clearance_submission.php'],
                ['My Subjects', '/faculty/subjects.php'],
            ];
            $departmentId = (int) ($_SESSION['department_id'] ?? 0);
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            if ($departmentId > 0 && $userId > 0 && isset($conn) && $conn instanceof mysqli) {
                $subjectTable = $conn->query("SHOW TABLES LIKE 'subjects'");
                if (!$subjectTable) {
                    error_log('Unable to check faculty subjects for the shared sidebar: ' . $conn->error);
                } else {
                    $hasSubjects = $subjectTable->num_rows > 0;
                    $subjectTable->free();
                    if ($hasSubjects) {
                        $subjectStmt = $conn->prepare('SELECT COUNT(*) AS total FROM subjects WHERE faculty_id = ? AND department_id = ?');
                        if ($subjectStmt) {
                            $subjectStmt->bind_param('ii', $userId, $departmentId);
                            if ($subjectStmt->execute()) {
                                $hasAssignedSubjects = (int) ($subjectStmt->get_result()->fetch_assoc()['total'] ?? 0) > 0;
                            } else {
                                $hasAssignedSubjects = false;
                                error_log('Unable to load faculty subject count for the shared sidebar: ' . $subjectStmt->error);
                            }
                            $subjectStmt->close();
                        } else {
                            $hasAssignedSubjects = false;
                            error_log('Unable to prepare faculty subject count for the shared sidebar: ' . $conn->error);
                        }
                        if ($hasAssignedSubjects) {
                            $groupTable = $conn->query("SHOW TABLES LIKE 'folder_groups'");
                            if (!$groupTable) {
                                error_log('Unable to check folder groups for the shared faculty sidebar: ' . $conn->error);
                            } else {
                                $hasFolderGroups = $groupTable->num_rows > 0;
                                $groupTable->free();
                                $sql = "SELECT f.id, f.folder_name FROM folders f "
                                    . ($hasFolderGroups ? "LEFT JOIN folder_groups fg ON fg.id = f.group_id " : "")
                                    . "WHERE f.department_id = ? AND f.school_year_id = "
                                    . "(SELECT id FROM school_years WHERE status = 'active' LIMIT 1) "
                                    . ($hasFolderGroups ? "AND (f.group_id IS NULL OR fg.status = 'active') " : "")
                                    . "ORDER BY f.subfolder_position ASC, f.created_at DESC";
                                $folderStmt = $conn->prepare($sql);
                                if ($folderStmt) {
                                    $folderStmt->bind_param('i', $departmentId);
                                    if ($folderStmt->execute()) {
                                        $folderLinks = $folderStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                    } else {
                                        error_log('Unable to load faculty folders for the shared sidebar: ' . $folderStmt->error);
                                    }
                                    $folderStmt->close();
                                } else {
                                    error_log('Unable to prepare faculty folders for the shared sidebar: ' . $conn->error);
                                }
                            }
                        }
                    }
                }
            }
        }

        $sidebarLinks = '';
        foreach ($items as [$label, $path]) {
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $safeHref = htmlspecialchars(BASE_URL . $path, ENT_QUOTES, 'UTF-8');
            $initial = htmlspecialchars(mb_strtoupper(mb_substr($label, 0, 1)), ENT_QUOTES, 'UTF-8');
            $sidebarLinks .= '<a class="coltrace-global-sidebar-link" href="' . $safeHref . '" title="' . $safeLabel . '"><span class="coltrace-global-sidebar-icon" aria-hidden="true">' . $initial . '</span><span class="coltrace-global-sidebar-label">' . $safeLabel . '</span></a>';
        }
        $folderSection = '';
        if (in_array($role, ['chairperson', 'faculty'], true)) {
            $folderLabel = $role === 'chairperson' ? 'Department Folders' : 'My Folders';
            $folderSection = '<div class="coltrace-global-sidebar-section">' . $folderLabel . '</div>';
            if (!$folderLinks) {
                $folderSection .= '<div class="coltrace-global-sidebar-empty">No folders yet.</div>';
            } else {
                foreach ($folderLinks as $folderLink) {
                    $folderName = htmlspecialchars((string) $folderLink['folder_name'], ENT_QUOTES, 'UTF-8');
                    $folderUrl = BASE_URL . ($role === 'chairperson' ? '/chairperson/folder_view.php?id=' : '/faculty/folder_submit.php?id=') . (int) $folderLink['id'];
                    $folderSection .= '<a class="coltrace-global-sidebar-link" href="' . htmlspecialchars($folderUrl, ENT_QUOTES, 'UTF-8') . '" title="' . $folderName . '"><span class="coltrace-global-sidebar-icon" aria-hidden="true">F</span><span class="coltrace-global-sidebar-label">' . $folderName . '</span></a>';
                }
            }
        }
        $safeName = htmlspecialchars((string) ($_SESSION['full_name'] ?? $roleName), ENT_QUOTES, 'UTF-8');
        $profileUrl = htmlspecialchars(BASE_URL . '/profile.php', ENT_QUOTES, 'UTF-8');
        $dashboardLink = htmlspecialchars(BASE_URL . $items[0][1], ENT_QUOTES, 'UTF-8');
        $globalSidebar = '<style>
            body{margin-left:252px!important}
            #coltrace-global-sidebar{position:fixed;z-index:1100;inset:0 auto 0 0;box-sizing:border-box;width:252px;overflow-y:auto;background:#231f1b;color:#f5f5f4;font:14px/1.4 Inter,"Segoe UI",Arial,sans-serif;box-shadow:4px 0 18px rgba(0,0,0,.16)}
            #coltrace-global-sidebar .coltrace-global-sidebar-brand{display:block;padding:20px 18px 16px;color:#fff;font-size:20px;font-weight:800;text-decoration:none;border-bottom:1px solid rgba(255,255,255,.1)}
            #coltrace-global-sidebar .coltrace-global-sidebar-brand span{color:#f97316}
            #coltrace-global-sidebar .coltrace-global-sidebar-account{display:block;padding:16px 18px;color:#e7e5e4;text-decoration:none;border-bottom:1px solid rgba(255,255,255,.08)}
            #coltrace-global-sidebar .coltrace-global-sidebar-name{display:block;overflow:hidden;font-size:13px;font-weight:700;text-overflow:ellipsis;white-space:nowrap}
            #coltrace-global-sidebar .coltrace-global-sidebar-role{display:block;margin-top:3px;color:#a8a29e;font-size:11px}
            #coltrace-global-sidebar nav{padding:12px 10px}
            #coltrace-global-sidebar .coltrace-global-sidebar-section{padding:14px 18px 6px;color:#a8a29e;font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase}
            #coltrace-global-sidebar .coltrace-global-sidebar-empty{padding:7px 18px;color:#a8a29e;font-size:12px}
            #coltrace-global-sidebar .coltrace-global-sidebar-section~.coltrace-global-sidebar-link{margin:3px 10px}
            #coltrace-global-sidebar .coltrace-global-sidebar-link{display:flex;min-height:42px;box-sizing:border-box;align-items:center;gap:11px;margin:3px 0;padding:7px 9px;border-radius:8px;color:#d6d3d1;font-size:13px;font-weight:600;text-decoration:none;transition:background .15s,color .15s}
            #coltrace-global-sidebar .coltrace-global-sidebar-link:hover,#coltrace-global-sidebar .coltrace-global-sidebar-link:focus-visible{background:rgba(249,115,22,.16);color:#fff;outline:none}
            #coltrace-global-sidebar .coltrace-global-sidebar-icon{display:flex;width:28px;height:28px;flex:0 0 28px;align-items:center;justify-content:center;border-radius:7px;background:rgba(255,255,255,.08);color:#fb923c;font-size:12px;font-weight:800}
            @media(max-width:700px){body{margin-left:62px!important}#coltrace-global-sidebar{width:62px}#coltrace-global-sidebar .coltrace-global-sidebar-brand{overflow:hidden;padding:17px 0;text-align:center;font-size:0}#coltrace-global-sidebar .coltrace-global-sidebar-brand:after{content:"C";font-size:20px}#coltrace-global-sidebar .coltrace-global-sidebar-account{overflow:hidden;padding:14px 0;text-align:center}#coltrace-global-sidebar .coltrace-global-sidebar-name,#coltrace-global-sidebar .coltrace-global-sidebar-role,#coltrace-global-sidebar .coltrace-global-sidebar-label,#coltrace-global-sidebar .coltrace-global-sidebar-section,#coltrace-global-sidebar .coltrace-global-sidebar-empty{display:none}#coltrace-global-sidebar nav{padding:10px 8px}#coltrace-global-sidebar .coltrace-global-sidebar-link{justify-content:center;padding:7px 0}.header,header:not(.header){left:62px!important;width:calc(100% - 62px)!important}}
            @media(min-width:701px){.header,header:not(.header){left:252px!important;width:calc(100% - 252px)!important}}
        </style><aside id="coltrace-global-sidebar" aria-label="Main navigation"><a class="coltrace-global-sidebar-brand" href="' . $dashboardLink . '">Coll<span>Trace</span></a><a class="coltrace-global-sidebar-account" href="' . $profileUrl . '"><span class="coltrace-global-sidebar-name">' . $safeName . '</span><span class="coltrace-global-sidebar-role">' . htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8') . '</span></a><nav aria-label="Role navigation">' . $sidebarLinks . $folderSection . '</nav></aside>';
    }
    $notificationStatusUrl = json_encode(BASE_URL . '/notification_status.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $notificationScript = '<script>(()=>{const url=' . $notificationStatusUrl . ';const badge=document.getElementById("coltrace-feedback-notification-count");const link=document.getElementById("coltrace-feedback-notifications");const headerRight=document.querySelector(".header-right,.header-actions");if(headerRight){const department=headerRight.querySelector(".dept-badge");const role=headerRight.querySelector(".role-badge");const logout=Array.from(headerRight.querySelectorAll("a")).find(item=>item.href.includes("logout.php"));const anchor=department||role||logout;if(anchor)headerRight.insertBefore(link,anchor);else headerRight.appendChild(link);Array.from(headerRight.querySelectorAll("a")).filter(item=>item!==link&&item.href.includes("notifications.php")).forEach(item=>item.remove())}else{const header=document.querySelector("header,.header");if(header)header.appendChild(link)}async function refresh(){try{const response=await fetch(url,{credentials:"same-origin",cache:"no-store"});if(!response.ok)throw new Error("HTTP "+response.status);const data=await response.json();const count=Number(data.unread_count||0);badge.textContent=count>99?"99+":(count>0?String(count):"");badge.hidden=count<=0;link.setAttribute("aria-label",count>0?"Notifications: "+count+" unread":"Notifications");link.title=count>0?"Notifications ("+count+" unread)":"Notifications"}catch(error){console.error("Unable to refresh notifications:",error)}}refresh();window.setInterval(refresh,5000)})()</script>';
    if ($role === 'chairperson') {
        return substr($html, 0, $bodyEnd) . $feedbackStyle . $navigationStyle . $globalSidebar . $notificationButton . $notificationScript . substr($html, $bodyEnd);
    }

    if ($role === 'faculty') {
        return substr($html, 0, $bodyEnd) . $feedbackStyle . $navigationStyle . $globalSidebar . $notificationButton . $notificationScript . substr($html, $bodyEnd);
    }

    if (empty($_SESSION['backup_csrf_token'])) {
        $_SESSION['backup_csrf_token'] = bin2hex(random_bytes(32));
    }
    $statusUrl = json_encode(BASE_URL . '/admin/backup_status.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $workerUrl = json_encode(BASE_URL . '/admin/backup_worker.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $controlUrl = json_encode(BASE_URL . '/admin/backup_control.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $downloadPrefix = json_encode(BASE_URL . '/admin/backup_download.php?file=', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $csrfToken = json_encode($_SESSION['backup_csrf_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $banner = <<<HTML
<div id="coltrace-backup-status" role="status" aria-live="polite" style="display:none;position:fixed;z-index:10000;top:12px;left:50%;transform:translateX(-50%);width:min(520px,calc(100% - 24px));padding:12px 16px;border:1px solid #fdba74;border-radius:10px;background:#fff7ed;color:#431407;box-shadow:0 8px 28px #0003;font:14px/1.4 Inter,Segoe UI,Arial,sans-serif">
    <button type="button" id="coltrace-backup-dismiss" aria-label="Minimize backup status" title="Minimize" style="float:right;border:0;background:transparent;color:#7c2d12;font-size:20px;line-height:18px;cursor:pointer">−</button>
    <strong id="coltrace-backup-title">Backup</strong>
    <div id="coltrace-backup-stage" style="margin-top:4px;color:#57534e"></div>
    <div style="height:7px;margin-top:9px;overflow:hidden;border-radius:6px;background:#fed7aa">
        <div id="coltrace-backup-progress" style="width:0;height:100%;background:#ea580c;transition:width .3s"></div>
    </div>
    <div id="coltrace-backup-percent" style="margin-top:4px;font-size:12px;color:#78716c"></div>
    <div id="coltrace-backup-download-status" style="margin-top:5px;font-size:12px;color:#57534e"></div>
    <div id="coltrace-backup-job-controls" class="coltrace-backup-controls" style="display:none">
        <button type="button" id="coltrace-backup-pause" class="coltrace-backup-control">
            <svg id="coltrace-backup-pause-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 5v14"/><path d="M16 5v14"/></svg>
            <span id="coltrace-backup-pause-label">Pause backup</span>
        </button>
        <button type="button" id="coltrace-backup-cancel" class="coltrace-backup-control coltrace-backup-control-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6"/><path d="m15 9-6 6"/></svg>
            <span id="coltrace-backup-cancel-label">Cancel backup</span>
        </button>
    </div>
    <div id="coltrace-backup-download-controls" class="coltrace-backup-controls" style="display:none">
        <button type="button" id="coltrace-backup-download" class="coltrace-backup-control coltrace-backup-control-primary" style="display:none">Download PDF ZIP</button>
        <button type="button" id="coltrace-backup-download-pause" class="coltrace-backup-control" style="display:none">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 5v14"/><path d="M16 5v14"/></svg>
            <span id="coltrace-backup-download-pause-label">Pause download</span>
        </button>
        <button type="button" id="coltrace-backup-cancel-download" class="coltrace-backup-control coltrace-backup-control-danger" style="display:none">Cancel download</button>
    </div>
</div>
<style>
.coltrace-backup-controls{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}
.coltrace-backup-control{display:inline-flex;min-height:32px;align-items:center;justify-content:center;gap:7px;padding:6px 10px;border:1px solid #d6d3d1;border-radius:6px;background:#fff;color:#44403c;font:600 12px/1.2 Inter,Segoe UI,Arial,sans-serif;cursor:pointer}
.coltrace-backup-control:hover{background:#fafaf9;border-color:#a8a29e}
.coltrace-backup-control svg{width:14px;height:14px;flex:none}
.coltrace-backup-control-primary{border-color:#292524;background:#292524;color:#fff}
.coltrace-backup-control-primary:hover{background:#44403c;border-color:#44403c}
.coltrace-backup-control-danger{border-color:#fecaca;color:#b91c1c}
.coltrace-backup-control-danger:hover{background:#fef2f2;border-color:#fca5a5}
.coltrace-backup-control:disabled{cursor:wait;opacity:.6}
.backup-status-trigger{position:relative;display:none;width:34px;height:34px;flex:0 0 34px;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,.2);border-radius:8px;background:rgba(255,255,255,.07);color:#f5f5f4;cursor:pointer;transition:background .15s,border-color .15s}
.backup-status-trigger:hover{border-color:rgba(249,115,22,.65);background:rgba(249,115,22,.14)}
.backup-status-trigger svg{width:18px;height:18px}
.backup-status-dot{position:absolute;top:5px;right:5px;width:7px;height:7px;border:1px solid #1c1917;border-radius:50%;background:#f97316}
.backup-status-trigger:focus-visible{outline:3px solid rgba(249,115,22,.4);outline-offset:2px}
</style>
<script>
(() => {
    const statusUrl = {$statusUrl};
    const workerUrl = {$workerUrl};
    const controlUrl = {$controlUrl};
    const downloadPrefix = {$downloadPrefix};
    const csrfToken = {$csrfToken};
    const currentUserId = {$userId};
    const banner = document.getElementById('coltrace-backup-status');
    const statusTrigger = document.getElementById('coltrace-backup-status-trigger');
    const title = document.getElementById('coltrace-backup-title');
    const stage = document.getElementById('coltrace-backup-stage');
    const progress = document.getElementById('coltrace-backup-progress');
    const percent = document.getElementById('coltrace-backup-percent');
    const downloadStatus = document.getElementById('coltrace-backup-download-status');
    const jobControls = document.getElementById('coltrace-backup-job-controls');
    const pauseBackupButton = document.getElementById('coltrace-backup-pause');
    const pauseBackupIcon = document.getElementById('coltrace-backup-pause-icon');
    const pauseBackupLabel = document.getElementById('coltrace-backup-pause-label');
    const cancelBackupButton = document.getElementById('coltrace-backup-cancel');
    const cancelBackupLabel = document.getElementById('coltrace-backup-cancel-label');
    const downloadControls = document.getElementById('coltrace-backup-download-controls');
    const downloadButton = document.getElementById('coltrace-backup-download');
    const pauseDownloadButton = document.getElementById('coltrace-backup-download-pause');
    const pauseDownloadLabel = document.getElementById('coltrace-backup-download-pause-label');
    const cancelDownloadButton = document.getElementById('coltrace-backup-cancel-download');
    const dismissedKey = 'coltrace-backup-dismissed';
    const knownStatesKey = 'coltrace-backup-known-states';
    const suppressedAutoDownloadPrefix = 'coltrace-backup-manual-download:';
    const workerRequests = new Set();
    let activeDownloadController = null;
    let activeWritable = null;
    let downloadPaused = false;
    let resumeDownload = null;

    document.getElementById('coltrace-backup-dismiss').addEventListener('click', () => {
        localStorage.setItem(dismissedKey, banner.dataset.jobId || '');
        banner.style.display = 'none';
        if (statusTrigger && !['queued', 'running'].includes(banner.dataset.jobStatus) && !activeDownloadController) {
            statusTrigger.style.display = 'none';
        }
    });
    if (statusTrigger) {
        statusTrigger.addEventListener('click', () => {
            localStorage.removeItem(dismissedKey);
            banner.style.display = 'block';
            pollBackupJobs();
        });
    }

    async function controlBackup(action) {
        const jobId = banner.dataset.jobId;
        if (!jobId) return;
        if (action === 'pause' || action === 'cancel') {
            localStorage.setItem(suppressedAutoDownloadPrefix + jobId, 'true');
            if (activeDownloadController) activeDownloadController.abort();
            if (resumeDownload) {
                resumeDownload();
                resumeDownload = null;
            }
            if (activeWritable) {
                activeWritable.abort().catch(error => console.error('Unable to stop ZIP download:', error));
            }
        }
        const controls = [pauseBackupButton, cancelBackupButton];
        controls.forEach(button => { button.disabled = true; });
        try {
            const response = await fetch(controlUrl + '?job=' + encodeURIComponent(jobId), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'X-Backup-CSRF': csrfToken, 'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action})
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Unable to update this backup.');
            await pollBackupJobs();
        } catch (error) {
            stage.textContent = error.message || 'Unable to update this backup.';
        } finally {
            controls.forEach(button => { button.disabled = false; });
        }
    }

    pauseBackupButton.addEventListener('click', () => {
        const resume = pauseBackupButton.dataset.paused === 'true';
        controlBackup(resume ? 'resume' : 'pause');
    });
    cancelBackupButton.addEventListener('click', () => {
        if (window.confirm('Cancel this backup?')) controlBackup('cancel');
    });

    function waitForDownloadResume() {
        if (!downloadPaused || !activeDownloadController || activeDownloadController.signal.aborted) return Promise.resolve();
        return new Promise(resolve => { resumeDownload = resolve; });
    }

    pauseDownloadButton.addEventListener('click', () => {
        downloadPaused = !downloadPaused;
        pauseDownloadLabel.textContent = downloadPaused ? 'Resume download' : 'Pause download';
        pauseDownloadButton.setAttribute('aria-pressed', String(downloadPaused));
        if (!downloadPaused && resumeDownload) {
            resumeDownload();
            resumeDownload = null;
        }
    });

    async function downloadArchive(name) {
        if (activeDownloadController) return;
        const controller = new AbortController();
        activeDownloadController = controller;
        let writable = null;
        const chunks = [];
        downloadPaused = false;
        pauseDownloadLabel.textContent = 'Pause download';
        pauseDownloadButton.setAttribute('aria-pressed', 'false');
        downloadControls.style.display = 'flex';
        pauseDownloadButton.style.display = 'inline-flex';
        cancelDownloadButton.style.display = 'inline-flex';
        downloadStatus.textContent = 'Starting download…';
        downloadButton.disabled = true;
        downloadButton.textContent = 'Downloading…';
        try {
            if (typeof window.showSaveFilePicker === 'function') {
                try {
                    const handle = await window.showSaveFilePicker({suggestedName: name});
                    if (controller.signal.aborted) return;
                    writable = await handle.createWritable();
                    activeWritable = writable;
                } catch (error) {
                    if (error.name !== 'SecurityError' && error.name !== 'NotSupportedError') throw error;
                }
            }
            const response = await fetch(downloadPrefix + encodeURIComponent(name), {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal
            });
            if (!response.ok || !response.body) {
                throw new Error('Unable to download the ZIP archive (HTTP ' + response.status + ').');
            }
            const total = Number(response.headers.get('Content-Length') || 0);
            let received = 0;
            const reader = response.body.getReader();
            while (true) {
                await waitForDownloadResume();
                const {done, value} = await reader.read();
                if (done) break;
                await waitForDownloadResume();
                received += value.byteLength;
                if (writable) {
                    await writable.write(value);
                } else {
                    chunks.push(value);
                }
                downloadStatus.textContent = total
                    ? 'Downloading ZIP: ' + Math.floor(received / total * 100) + '%'
                    : 'Downloaded ' + (received / 1048576).toFixed(1) + ' MB';
            }
            if (writable) {
                await writable.close();
                activeWritable = null;
            } else {
                const objectUrl = URL.createObjectURL(new Blob(chunks, {type: 'application/zip'}));
                const link = document.createElement('a');
                link.href = objectUrl;
                link.download = name;
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
            }
            downloadStatus.textContent = 'ZIP download completed.';
        } catch (error) {
            if (writable) {
                try {
                    await writable.abort();
                } catch (abortError) {
                    console.error('Unable to discard the cancelled ZIP download:', abortError);
                }
            }
            if (error.name === 'AbortError') {
                downloadStatus.textContent = 'ZIP download cancelled.';
            } else {
                downloadStatus.textContent = error.message || 'ZIP download failed.';
                console.error('Unable to download backup ZIP:', error);
            }
        } finally {
            activeWritable = null;
            activeDownloadController = null;
            downloadPaused = false;
            pauseDownloadButton.style.display = 'none';
            cancelDownloadButton.style.display = 'none';
            downloadControls.style.display = downloadButton.dataset.archive ? 'flex' : 'none';
            if (resumeDownload) {
                resumeDownload();
                resumeDownload = null;
            }
            downloadButton.disabled = false;
            downloadButton.textContent = 'Download PDF ZIP';
        }
    }

    cancelDownloadButton.addEventListener('click', () => {
        if (activeDownloadController) activeDownloadController.abort();
        if (resumeDownload) {
            resumeDownload();
            resumeDownload = null;
        }
        if (activeWritable) {
            activeWritable.abort().catch(error => console.error('Unable to cancel ZIP download:', error));
        }
    });
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-backup-download]');
        if (button) downloadArchive(button.dataset.backupDownload);
    });
    downloadButton.addEventListener('click', () => {
        if (downloadButton.dataset.archive) downloadArchive(downloadButton.dataset.archive);
    });

    async function pollBackupJobs() {
        try {
            const response = await fetch(statusUrl, {credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok) return;
            const data = await response.json();
            const jobs = data.jobs || [];
            const current = jobs.find(job => ['queued', 'running'].includes(job.status)) || jobs[0];
            if (!current) {
                banner.style.display = 'none';
                if (statusTrigger) statusTrigger.style.display = 'none';
                return;
            }

            const dismissed = localStorage.getItem(dismissedKey) === current.id;
            banner.dataset.jobId = current.id;
            banner.dataset.jobStatus = current.status;
            banner.style.display = dismissed ? 'none' : 'block';
            const taskName = current.destination === 'local' ? 'Local PDF archive' :
                (current.destination === 'both' ? 'School year backup' : 'Google Drive backup');
            const jobActive = ['queued', 'running'].includes(current.status);
            const canControlJob = Number(current.user_id) === currentUserId;
            jobControls.style.display = jobActive && canControlJob ? 'flex' : 'none';
            const paused = Boolean(current.paused);
            const pauseRequested = Boolean(current.pause_requested);
            const cancelRequested = Boolean(current.cancel_requested);
            title.textContent = cancelRequested ? taskName + ' cancelling' :
                (pauseRequested ? taskName + ' pausing' :
                    (paused ? taskName + ' paused' :
                        (current.status === 'failed' ? taskName + ' failed' :
                            (current.status === 'cancelled' ? taskName + ' cancelled' :
                                (current.status === 'completed' ? taskName + ' completed' : taskName + ' in progress')))));
            stage.textContent = cancelRequested ? 'Stopping after the current operation. No ZIP will be offered.' :
                (paused ? 'Paused. Resume to continue or Cancel to stop.' :
                    (pauseRequested ? 'Pausing after the current operation.' :
                        (current.status === 'cancelled' ? 'Cancelled. Start a new manual backup to try again.' :
                            (current.status === 'failed' ? (current.error || 'The backup could not be completed.') :
                                (current.stage || current.reason || 'Backup queued')))));
            pauseBackupButton.dataset.paused = String(paused);
            pauseBackupLabel.textContent = paused ? 'Resume backup' : (pauseRequested ? 'Pausing…' : 'Pause backup');
            pauseBackupButton.disabled = pauseRequested || cancelRequested;
            pauseBackupButton.title = paused ? 'Resume backup' : 'Pause after the current step';
            pauseBackupIcon.innerHTML = paused ? '<path d="m8 5 12 7-12 7z"/>' : '<path d="M8 5v14"/><path d="M16 5v14"/>';
            cancelBackupButton.disabled = cancelRequested;
            cancelBackupLabel.textContent = cancelRequested ? 'Cancelling…' : 'Cancel backup';
            const amount = Math.max(0, Math.min(100, Number(current.progress || 0)));
            progress.style.width = amount + '%';
            percent.textContent = current.status === 'completed' ? '100% complete' :
                (current.status === 'cancelled' ? 'Cancelled' :
                    (current.status === 'failed' ? 'Please retry from Backup Storage.' : amount + '% complete'));
            progress.style.background = current.status === 'failed' ? '#be123c' : (current.status === 'cancelled' ? '#a8a29e' : '#ea580c');
            const localArchive = current.status === 'completed' ? current.manifest?.local_archives?.[0] : '';
            if (statusTrigger) {
                const jobActive = ['queued', 'running'].includes(current.status);
                statusTrigger.style.display = jobActive || !dismissed || Boolean(activeDownloadController) ? 'inline-flex' : 'none';
                statusTrigger.title = localArchive ? 'Show completed backup and download' :
                    (current.status === 'completed' ? 'Show completed backup status' : 'Show backup status');
                statusTrigger.setAttribute('aria-label', statusTrigger.title);
            }
            downloadButton.dataset.archive = localArchive || '';
            downloadButton.style.display = localArchive ? 'inline-block' : 'none';
            downloadControls.style.display = localArchive ? 'flex' : 'none';
            pauseDownloadButton.style.display = activeDownloadController ? 'inline-flex' : 'none';
            cancelDownloadButton.style.display = activeDownloadController ? 'inline-flex' : 'none';
            if (current.status === 'cancelled' && !activeDownloadController) {
                downloadStatus.textContent = '';
            }

            const knownStates = JSON.parse(localStorage.getItem(knownStatesKey) || '{}');
            for (const job of jobs) {
                if (job.status === 'completed' && ['local', 'both'].includes(job.destination) &&
                    ['queued', 'running'].includes(knownStates[job.id]) &&
                    Number(job.user_id) === currentUserId &&
                    localStorage.getItem(suppressedAutoDownloadPrefix + job.id) !== 'true' &&
                    job.manifest?.local_archives?.[0]) {
                    downloadArchive(job.manifest.local_archives[0]);
                }
                knownStates[job.id] = job.status;
            }
            localStorage.setItem(knownStatesKey, JSON.stringify(knownStates));

            const queuedJob = jobs.find(job => job.status === 'queued' && !job.paused && !job.cancel_requested);
            if (queuedJob && !workerRequests.has(queuedJob.id)) {
                workerRequests.add(queuedJob.id);
                fetch(workerUrl + '?job=' + encodeURIComponent(queuedJob.id), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'X-Backup-CSRF': csrfToken}
                }).catch(error => {
                    console.error('Unable to start backup worker:', error);
                }).finally(() => workerRequests.delete(queuedJob.id));
            }
        } catch (error) {
            console.error('Unable to refresh backup status:', error);
        }
    }

    pollBackupJobs();
    window.setInterval(pollBackupJobs, 3000);
})();
</script>
HTML;
    return substr($html, 0, $bodyEnd) . $feedbackStyle . $navigationStyle . $globalSidebar . $notificationButton . $banner . $notificationScript . substr($html, $bodyEnd);
}
