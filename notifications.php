<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/file_feedback.php';
requireLogin();
fileFeedbackEnsureSchema($conn);

$userId = (int) $_SESSION['user_id'];
$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'sub-admin', 'chairperson', 'faculty'], true)) {
    redirect(BASE_URL . '/index.php?error=unauthorized');
}
$csrfToken = fileFeedbackCsrfToken();
$message = $_SESSION['feedback_message'] ?? '';
$error = $_SESSION['feedback_error'] ?? '';
unset($_SESSION['feedback_message'], $_SESSION['feedback_error']);

$markRead = $conn->prepare('UPDATE file_feedback_notifications SET is_read = 1 WHERE recipient_id = ? AND is_read = 0');
if (!$markRead) {
    throw new RuntimeException('Unable to update notification status: ' . $conn->error);
}
$markRead->bind_param('i', $userId);
if (!$markRead->execute()) {
    throw new RuntimeException('Unable to mark notifications as read: ' . $markRead->error);
}
$markRead->close();

$stmt = $conn->prepare("
    SELECT n.id AS notification_id, n.event_type, n.created_at AS notification_created_at,
           ff.id AS feedback_id, ff.file_id, ff.created_by, ff.parent_feedback_id,
           ff.message, ff.entry_type, ff.is_completed, parent.message AS parent_message,
           ff.created_at AS feedback_created_at, ff.edited_at, ff.completed_at,
           fi.original_name, f.id AS folder_id, f.folder_name,
           COALESCE(author.full_name, 'Deleted User') AS author_name,
           COALESCE(author.role, 'former user') AS author_role,
           COALESCE(faculty.full_name, 'Faculty member') AS faculty_name,
           COALESCE(parent_author.full_name, 'Deleted User') AS parent_author_name
    FROM file_feedback_notifications n
    JOIN file_feedback ff ON ff.id = n.feedback_id
    JOIN files fi ON fi.id = ff.file_id
    JOIN folders f ON f.id = fi.folder_id
    LEFT JOIN users author ON author.id = ff.created_by
    LEFT JOIN users faculty ON faculty.id = ff.faculty_id
    LEFT JOIN file_feedback parent ON parent.id = ff.parent_feedback_id
    LEFT JOIN users parent_author ON parent_author.id = parent.created_by
    WHERE n.recipient_id = ?
      AND (
          n.event_type NOT IN ('task_completed', 'reply')
          OR n.recipient_id = CASE
              WHEN n.event_type = 'reply' THEN parent.created_by
              ELSE ff.created_by
          END
      )
    ORDER BY n.created_at DESC, n.id DESC
");
if (!$stmt) {
    throw new RuntimeException('Unable to load notifications: ' . $conn->error);
}
$stmt->bind_param('i', $userId);
if (!$stmt->execute()) {
    throw new RuntimeException('Unable to load notifications: ' . $stmt->error);
}
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if ($role !== 'faculty') {
    $conversationGroups = [];
    foreach ($items as $index => $item) {
        if (!in_array($item['event_type'], ['task_completed', 'reply'], true)) {
            continue;
        }
        $rootFeedbackId = $item['event_type'] === 'reply'
            ? (int) ($item['parent_feedback_id'] ?? 0)
            : (int) $item['feedback_id'];
        if ($rootFeedbackId > 0) {
            $conversationGroups[$rootFeedbackId][$item['event_type']][] = $index;
        }
    }

    $mergedItems = [];
    $consumedIndexes = [];
    foreach ($conversationGroups as $rootFeedbackId => $group) {
        if (empty($group['reply'])) {
            continue;
        }
        $threadIndex = $group['task_completed'][0] ?? $group['reply'][0];
        $conversation = $items[$threadIndex];
        $conversation['combined_conversation'] = true;
        $conversation['root_feedback_id'] = (int) $rootFeedbackId;
        $conversation['thread_task_completed'] = !empty($group['task_completed']);
        $conversation['conversation_replies'] = [];
        $latestReplyTimestamp = '';
        foreach ($group['reply'] as $replyIndex) {
            $reply = $items[$replyIndex];
            $conversation['conversation_replies'][] = $reply;
            $consumedIndexes[$replyIndex] = true;
            if ($reply['notification_created_at'] > $latestReplyTimestamp) {
                $latestReplyTimestamp = $reply['notification_created_at'];
            }
        }
        if (empty($group['task_completed'])) {
            $conversation['message'] = $conversation['parent_message'] ?? $conversation['message'];
        }
        if ($latestReplyTimestamp !== '') {
            $conversation['notification_created_at'] = max($conversation['notification_created_at'], $latestReplyTimestamp);
        }
        $mergedItems[$threadIndex] = $conversation;
        $consumedIndexes[$threadIndex] = true;
        foreach ($group['task_completed'] ?? [] as $taskIndex) {
            $consumedIndexes[$taskIndex] = true;
        }
    }

    if ($mergedItems) {
        foreach ($items as $index => $item) {
            if (!isset($consumedIndexes[$index])) {
                $mergedItems[$index] = $item;
            }
        }
        uasort($mergedItems, static fn(array $a, array $b): int => strcmp($b['notification_created_at'], $a['notification_created_at']));
        $items = array_values($mergedItems);
    }
}

$dashboardUrl = dashboardUrlForRole($role);
$roleLabel = match ($role) {
    'admin' => 'Admin',
    'sub-admin' => 'Admin',
    'chairperson' => 'Chairperson',
    default => 'Faculty',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — CollTrace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #fff7ed; color: #1c1917; font-family: Inter,Segoe UI,Arial,sans-serif; min-height:100vh; }
        header { display:flex;align-items:center;justify-content:space-between;gap:16px;height:62px;padding:0 28px;background:linear-gradient(120deg,#1c1917 0%,#0d0b0a 100%);color:#fff;border-bottom:2px solid #f97316;box-shadow:0 2px 16px #00000030; }
        .header-left,.header-right,.brand { display:flex;align-items:center; }
        .header-left,.header-right { gap:12px; }
        .brand { gap:10px;color:#fff;text-decoration:none;font-size:21px;font-weight:800;letter-spacing:-.4px; }
        .brand img { width:30px;height:30px;object-fit:contain;flex:none; }
        .brand-accent { color:#f97316; }
        .role-badge { padding:4px 12px;border-radius:20px;background:#f97316;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase; }
        .header-link { padding:7px 14px;border:1px solid rgba(255,255,255,.25);border-radius:7px;color:#fff;text-decoration:none;font-size:13px;transition:background .15s; }
        .header-link:hover { background:rgba(255,255,255,.1); }
        .header-link:focus-visible { outline:3px solid #fdba74;outline-offset:2px; }
        .header-link.logout { border-color:rgba(255,255,255,.35); }
        main { max-width:900px;margin:32px auto;padding:0 20px 40px; }
        h1 { margin:0 0 8px;font-size:22px;font-weight:800; }
        .muted { color:#78716c;font-size:13px;line-height:1.6; }
        .notice { padding:12px 15px;margin:16px 0;border-radius:8px;background:#ecfdf5;color:#166534; }
        .notice.error { background:#fff1f2;color:#9f1239; }
        .notification-list { display:grid;gap:14px;margin-top:22px; }
        .notification-heading { display:flex;align-items:center;justify-content:space-between;gap:14px;margin-top:22px; }
        .clear-history-form { margin:0; }
        .clear-history-button { min-height:36px;padding:7px 11px;border:1px solid #ea580c;border-radius:7px;background:#f97316;color:#fff;font:600 12px Inter,Segoe UI,Arial,sans-serif;cursor:pointer; }
        .clear-history-button:hover { background:#ea580c;border-color:#c2410c; }
        .clear-history-button:focus-visible { outline:3px solid #fb923c;outline-offset:2px; }
        .clear-history-button:disabled { opacity:.55;cursor:not-allowed; }
        .notification-card { padding:18px 20px;border:1px solid #e7e5e4;border-radius:12px;background:#fff;box-shadow:0 3px 12px #0000000c; }
        .notification-card.conversation-card { cursor:pointer;transition:border-color .15s,box-shadow .15s; }
        .notification-card.conversation-card:hover { border-color:#fdba74;box-shadow:0 5px 18px #ea580c1c; }
        .notification-card.conversation-card:focus-visible { outline:3px solid #fdba74;outline-offset:3px; }
        .notification-top { display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap; }
        .type { padding:4px 9px;border-radius:999px;background:#ffedd5;color:#9a3412;font-size:11px;font-weight:700;text-transform:uppercase; }
        .type.task { background:#dbeafe;color:#1d4ed8; }
        .type.faculty-task { background:#ffedd5;color:#9a3412; }
        .type.complete { background:#dcfce7;color:#166534; }
        .type.complete.conversation-type { background:#ffedd5;color:#9a3412; }
        .notification-text { margin:14px 0;line-height:1.6;white-space:pre-wrap;overflow-wrap:anywhere; }
        .notification-text.completed { color:#78716c;text-decoration:line-through; }
        .notification-task-row { display:flex;align-items:center;justify-content:space-between;gap:20px; }
        .notification-task-row .notification-text { flex:1; }
        .meta { color:#78716c;font-size:12px; }
        .remark-edited { margin-top:-8px;color:#c2410c;font-size:11px;font-weight:700; }
        .notification-actions { display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:14px; }
        .notification-actions a { color:#c2410c;font-size:13px;font-weight:600; }
        .reply-context { margin:14px 0 10px;padding:10px 12px;border-left:3px solid #f97316;border-radius:0 7px 7px 0;background:#fff7ed; }
        .reply-context .notification-text { margin:6px 0 0;font-size:13px; }
        .reply-context > .meta,.conversation-preview > .meta { color:#c2410c;font-weight:700; }
        .conversation-preview { margin:10px 0;padding:9px 11px;border-left:3px solid #f97316;border-radius:0 7px 7px 0;background:#ffedd5; }
        .conversation-preview .notification-text { margin:5px 0 0;font-size:13px; }
        .task-completion-form { flex:none;margin:0; }
        .task-completion-button { display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:7px 12px;border:1px solid #fdba74;border-radius:8px;background:#fff7ed;color:#9a3412;font:700 13px Inter,Segoe UI,Arial,sans-serif;cursor:pointer;white-space:nowrap; }
        .task-completion-button:hover { border-color:#ea580c;background:#ffedd5; }
        .task-completion-button:focus-visible { outline:3px solid #fb923c;outline-offset:2px; }
        .task-check-icon { display:inline-flex;width:18px;height:18px;align-items:center;justify-content:center;color:#ea580c; }
        .task-completion-button.done { border-color:#fdba74;background:#fff7ed;color:#c2410c;cursor:default; }
        .task-completion-button.done .task-check-icon { color:#ea580c; }
        .task-response-form { display:grid;gap:9px;margin:12px 0 4px; }
        .task-response-form textarea { width:100%;min-height:68px;padding:10px;border:1px solid #d6d3d1;border-radius:8px;font:inherit;resize:vertical; }
        .task-response-form textarea:focus-visible { outline:3px solid #fed7aa;border-color:#ea580c; }
        .task-response-form .task-completion-button { justify-self:end; }
        .empty { margin-top:22px;padding:24px;border:1px dashed #d6d3d1;border-radius:10px;background:#fff;text-align:center;color:#78716c; }
        @media(max-width:600px) { header { height:58px;padding:0 14px;gap:7px; } .header-left,.header-right { gap:7px; } .brand { gap:7px;font-size:18px; } .brand img { width:26px;height:26px; } .role-badge { display:none; } .header-link { padding:6px 9px;font-size:12px; } main { margin:24px auto; } .notification-card { padding:15px; } }
    </style>
</head>
<body>
<header>
    <div class="header-left">
        <a class="brand" href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>"><img src="<?= htmlspecialchars(BASE_URL . '/ceit_logo.png', ENT_QUOTES, 'UTF-8') ?>" alt="CEIT Logo"><span>Coll<span class="brand-accent">Trace</span></span></a>
        <a class="header-link" href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>">← Dashboard</a>
    </div>
    <div class="header-right">
        <span class="role-badge"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
        <a class="header-link logout" href="<?= htmlspecialchars(BASE_URL . '/logout.php', ENT_QUOTES, 'UTF-8') ?>">Logout</a>
    </div>
</header>
<main>
    <h1>Notifications</h1>
    <div class="muted">File remarks, checklist updates, and task completion notices.</div>
    <?php if ($message): ?><div class="notice success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <div class="notification-heading">
        <div class="muted">Your notification history</div>
        <form class="clear-history-form" method="POST" action="<?= htmlspecialchars(BASE_URL . '/feedback_action.php', ENT_QUOTES, 'UTF-8') ?>" onsubmit="return confirm('Clear your notification history? This will not delete any files, remarks, or tasks.');">
            <input type="hidden" name="action" value="clear_history">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="clear-history-button"<?= !$items ? ' disabled' : '' ?>>Clear history</button>
        </form>
    </div>

    <?php if (!$items): ?>
        <div class="empty">No notifications yet.</div>
    <?php else: ?>
        <section class="notification-list" aria-label="Notifications">
            <?php foreach ($items as $item):
                $taskCompleted = $item['event_type'] === 'task_completed';
                $fileReplaced = $item['event_type'] === 'file_replaced';
                $facultyReply = $item['event_type'] === 'reply';
                $newFacultyFeedback = $item['event_type'] === 'new_feedback';
                $isTask = $item['entry_type'] === 'task';
                $folderUrl = match ($role) {
                    'faculty' => 'faculty/folder_submit.php',
                    'chairperson' => 'chairperson/folder_view.php',
                    default => 'admin/folder_view.php',
                };
            ?>
                <?php $combinedConversation = !empty($item['combined_conversation']); ?>
                <article class="notification-card">
                    <div class="notification-top">
                        <span class="type<?= $isTask ? ' task' : '' ?><?= $role === 'faculty' && $isTask && !$taskCompleted ? ' faculty-task' : '' ?><?= $taskCompleted || $fileReplaced ? ' complete' : '' ?><?= $combinedConversation ? ' conversation-type' : '' ?>">
                            <?= $combinedConversation ? (!empty($item['thread_task_completed']) ? 'Remark thread · task completed' : 'Remark thread') : ($taskCompleted ? 'Checklist completed' : ($fileReplaced ? 'File replaced' : ($facultyReply ? 'Faculty reply' : ($isTask ? 'Checklist task' : 'File remark')))) ?>
                        </span>
                        <?php if ($combinedConversation): ?>
                            <span class="meta"><?= htmlspecialchars($item['faculty_name'], ENT_QUOTES, 'UTF-8') ?> <?= !empty($item['thread_task_completed']) ? 'completed the task and replied.' : 'replied to the remark.' ?></span>
                        <?php elseif ($facultyReply): ?>
                            <span class="meta"><?= htmlspecialchars($item['faculty_name'], ENT_QUOTES, 'UTF-8') ?> replied to your remark.</span>
                        <?php elseif ($fileReplaced): ?>
                            <span class="meta"><?= htmlspecialchars($item['faculty_name'], ENT_QUOTES, 'UTF-8') ?> replaced the file after your remark.</span>
                        <?php elseif ($taskCompleted): ?>
                            <span class="meta"><?= htmlspecialchars($item['faculty_name'], ENT_QUOTES, 'UTF-8') ?> completed the task you assigned.</span>
                        <?php else: ?>
                            <span class="meta">From <?= htmlspecialchars($item['author_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ucfirst($item['author_role']), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($role === 'faculty' && $newFacultyFeedback && $isTask): ?>
                        <?php if ((int) $item['is_completed'] === 1): ?>
                            <div class="notification-task-row">
                                <div class="notification-text completed"><?= htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8') ?></div>
                                <button type="button" class="task-completion-button done" aria-label="Task completed" title="Task completed" disabled><svg class="task-check-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg></button>
                            </div>
                        <?php else: ?>
                            <form class="task-response-form" method="POST" action="<?= htmlspecialchars(BASE_URL . '/feedback_action.php', ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="complete_task">
                                <input type="hidden" name="feedback_id" value="<?= (int) $item['feedback_id'] ?>">
                                <input type="hidden" name="complete" value="1">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="notification-task-row">
                                    <div class="notification-text"><?= htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <button type="submit" class="task-completion-button"><svg class="task-check-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg> Reply &amp; mark done</button>
                                </div>
                                <textarea name="message" maxlength="2000" required placeholder="Reply to this remark before marking it done"></textarea>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="notification-text<?= $taskCompleted || (int) $item['is_completed'] === 1 ? ' completed' : '' ?>"><?= htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if (!empty($item['edited_at'])): ?>
                        <div class="meta remark-edited">Edited <?= htmlspecialchars(date('M d, Y g:i A', strtotime($item['edited_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($facultyReply && !$combinedConversation && !empty($item['parent_message'])): ?>
                        <div class="reply-context">
                            <span class="meta">Your original remark</span>
                            <div class="notification-text"><?= htmlspecialchars($item['parent_message'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($combinedConversation && !empty($item['conversation_replies'][0])): ?>
                        <div class="conversation-preview">
                            <span class="meta"><?= htmlspecialchars($item['faculty_name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <div class="notification-text"><?= htmlspecialchars($item['conversation_replies'][0]['message'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endif; ?>
                    <div class="meta">
                        File: <strong><?= htmlspecialchars($item['original_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        · Folder: <?= htmlspecialchars($item['folder_name'], ENT_QUOTES, 'UTF-8') ?>
                        · <?= htmlspecialchars(date('M d, Y g:i A', strtotime($item['notification_created_at'])), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="notification-actions">
                        <a href="<?= htmlspecialchars(BASE_URL . '/' . $folderUrl . '?id=' . (int) $item['folder_id'] . '#file-' . (int) $item['file_id'], ENT_QUOTES, 'UTF-8') ?>"><?= $combinedConversation ? 'Click to view the remark and reply' : 'Open file and remarks' ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
