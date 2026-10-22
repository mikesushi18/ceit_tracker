<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/file_feedback.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['user_id'])) {
    http_response_code(405);
    exit('A signed-in user must submit this request.');
}

fileFeedbackEnsureSchema($conn);
$role = (string) ($_SESSION['role'] ?? '');
$userId = (int) $_SESSION['user_id'];
$fileId = (int) ($_POST['file_id'] ?? 0);
$redirectUrl = match ($role) {
    'admin', 'sub-admin' => BASE_URL . '/admin/folder_view.php',
    'chairperson' => BASE_URL . '/chairperson/folder_view.php',
    'faculty' => BASE_URL . '/notifications.php',
    default => BASE_URL . '/index.php',
};
$csrfToken = (string) ($_SESSION['file_feedback_csrf_token'] ?? '');
if ($csrfToken === '' || !hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
    $_SESSION['feedback_error'] = 'Your request could not be verified. Please try again.';
    header('Location: ' . $redirectUrl);
    exit;
}

$action = (string) ($_POST['action'] ?? '');

try {
    $transactionStarted = false;
    if ($action === 'clear_history') {
        $redirectUrl = BASE_URL . '/notifications.php';
        if (!in_array($role, ['admin', 'sub-admin', 'chairperson', 'faculty'], true)) {
            throw new RuntimeException('This action is not available for your account.');
        }
        $stmt = $conn->prepare('DELETE FROM file_feedback_notifications WHERE recipient_id = ?');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare notification history cleanup: ' . $conn->error);
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to clear notification history: ' . $stmt->error);
        }
        $clearedCount = $stmt->affected_rows;
        $stmt->close();
        $_SESSION['feedback_message'] = $clearedCount > 0
            ? 'Notification history cleared.'
            : 'There was no notification history to clear.';
    } elseif (in_array($role, ['admin', 'sub-admin', 'chairperson'], true)) {
        $stmt = $conn->prepare("
            SELECT fi.id, fi.original_name, fi.uploaded_by, f.id AS folder_id, f.folder_name, f.department_id, u.role AS recipient_role
            FROM files fi
            JOIN folders f ON f.id = fi.folder_id
            JOIN users u ON u.id = fi.uploaded_by
            WHERE fi.id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new RuntimeException('Unable to verify the selected faculty file: ' . $conn->error);
        }
        $stmt->bind_param('i', $fileId);
        $stmt->execute();
        $file = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$file || $file['recipient_role'] !== 'faculty') {
            throw new RuntimeException('The selected file does not belong to a faculty member.');
        }
        if ($role === 'chairperson' && (int) $file['department_id'] !== (int) ($_SESSION['department_id'] ?? 0)) {
            throw new RuntimeException('You can only comment on files in your department.');
        }
        $redirectUrl .= '?id=' . (int) $file['folder_id'];

        if (!in_array($action, ['create', 'edit'], true)) {
            throw new RuntimeException('This action is not available for your account.');
        }
        $message = trim((string) ($_POST['message'] ?? ''));
        $entryType = 'task';
        if ($message === '' || mb_strlen($message) > 2000) {
            throw new RuntimeException('Enter a remark of up to 2,000 characters.');
        }
        $facultyId = (int) $file['uploaded_by'];
        if ($action === 'edit') {
            $feedbackId = (int) ($_POST['feedback_id'] ?? 0);
            $conn->begin_transaction();
            $transactionStarted = true;
            $ownerCheck = $conn->prepare("
                SELECT id
                FROM file_feedback
                WHERE id = ? AND file_id = ? AND faculty_id = ? AND created_by = ?
                  AND parent_feedback_id IS NULL
                FOR UPDATE
            ");
            if (!$ownerCheck) {
                throw new RuntimeException('Unable to verify comment ownership: ' . $conn->error);
            }
            $ownerCheck->bind_param('iiii', $feedbackId, $fileId, $facultyId, $userId);
            if (!$ownerCheck->execute()) {
                throw new RuntimeException('Unable to verify comment ownership: ' . $ownerCheck->error);
            }
            $ownsFeedback = $ownerCheck->get_result()->num_rows === 1;
            $ownerCheck->close();
            if (!$ownsFeedback) {
                throw new RuntimeException('You can only edit your own comment on this file.');
            }
            $update = $conn->prepare("
                UPDATE file_feedback
                SET message = ?, is_completed = 0, completed_at = NULL,
                    edited_at = CURRENT_TIMESTAMP, created_at = CURRENT_TIMESTAMP
                WHERE id = ? AND file_id = ? AND faculty_id = ? AND created_by = ?
                  AND parent_feedback_id IS NULL
            ");
            if (!$update) {
                throw new RuntimeException('Unable to prepare the comment update: ' . $conn->error);
            }
            $update->bind_param('siiii', $message, $feedbackId, $fileId, $facultyId, $userId);
            if (!$update->execute()) {
                throw new RuntimeException('Unable to update your comment. Refresh and try again.');
            }
            $update->close();

            $notify = $conn->prepare("
                UPDATE file_feedback_notifications
                SET is_read = 0, created_at = CURRENT_TIMESTAMP
                WHERE feedback_id = ? AND recipient_id = ? AND event_type = 'new_feedback'
            ");
            if (!$notify) {
                throw new RuntimeException('Unable to prepare the updated comment notification: ' . $conn->error);
            }
            $notify->bind_param('ii', $feedbackId, $facultyId);
            if (!$notify->execute()) {
                throw new RuntimeException('Unable to notify faculty about the updated comment: ' . $notify->error);
            }
            $notify->close();
            fileFeedbackNotify($conn, $feedbackId, [$facultyId], 'new_feedback');

            $clearCompletionNotice = $conn->prepare("DELETE FROM file_feedback_notifications WHERE feedback_id = ? AND event_type = 'task_completed'");
            if (!$clearCompletionNotice) {
                throw new RuntimeException('Unable to refresh task completion notifications: ' . $conn->error);
            }
            $clearCompletionNotice->bind_param('i', $feedbackId);
            if (!$clearCompletionNotice->execute()) {
                throw new RuntimeException('Unable to refresh task completion notifications: ' . $clearCompletionNotice->error);
            }
            $clearCompletionNotice->close();
            $conn->commit();
            $transactionStarted = false;
            logActivity(
                $conn,
                $userId,
                'EDIT_REMARK',
                "Updated remark for '{$file['original_name']}' in folder '{$file['folder_name']}' for faculty member ID $facultyId"
            );
            $_SESSION['feedback_message'] = 'Comment updated and sent to faculty.';
        } else {
        $insert = $conn->prepare('INSERT INTO file_feedback (file_id, faculty_id, created_by, message, entry_type) VALUES (?, ?, ?, ?, ?)');
        if (!$insert) {
            throw new RuntimeException('Unable to prepare the faculty feedback: ' . $conn->error);
        }
        $conn->begin_transaction();
        $transactionStarted = true;
        $insert->bind_param('iiiss', $fileId, $facultyId, $userId, $message, $entryType);
        if (!$insert->execute()) {
            throw new RuntimeException('Unable to send the comment or task: ' . $insert->error);
        }
        $feedbackId = (int) $insert->insert_id;
        $insert->close();
        fileFeedbackNotify($conn, $feedbackId, [$facultyId], 'new_feedback');
        $conn->commit();
        $transactionStarted = false;
        logActivity(
            $conn,
            $userId,
            'CREATE_REMARK',
            "Created a remark/checklist item for '{$file['original_name']}' in folder '{$file['folder_name']}' for faculty member ID $facultyId"
        );
        $_SESSION['feedback_message'] = 'Checklist task sent to faculty.';
        }
    } elseif ($role === 'faculty' && $action === 'complete_task') {
        if ((string) ($_POST['complete'] ?? '') !== '1') {
            throw new RuntimeException('Check the task before submitting its completion.');
        }
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 2000) {
            throw new RuntimeException('A reply is required before marking this task done.');
        }
        $feedbackId = (int) ($_POST['feedback_id'] ?? 0);
        $stmt = $conn->prepare("
            SELECT ff.id, ff.file_id, ff.created_by
            FROM file_feedback ff
            JOIN files fi ON fi.id = ff.file_id
            JOIN folders f ON f.id = fi.folder_id
            WHERE ff.id = ? AND ff.faculty_id = ? AND ff.entry_type = 'task' AND ff.is_completed = 0
            FOR UPDATE
        ");
        if (!$stmt) {
            throw new RuntimeException('Unable to load the task before updating it: ' . $conn->error);
        }
        $conn->begin_transaction();
        $transactionStarted = true;
        $stmt->bind_param('ii', $feedbackId, $userId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to load the task: ' . $stmt->error);
        }
        $task = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$task) {
            throw new RuntimeException('This task was not found, or it has already been completed.');
        }

        $stmt = $conn->prepare("UPDATE file_feedback SET is_completed = 1, completed_at = CURRENT_TIMESTAMP WHERE id = ? AND faculty_id = ? AND is_completed = 0");
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the task update: ' . $conn->error);
        }
        $stmt->bind_param('ii', $feedbackId, $userId);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            throw new RuntimeException('Unable to update this task. Please refresh and try again.');
        }
        $stmt->close();

        $reply = $conn->prepare("
            INSERT INTO file_feedback (file_id, faculty_id, created_by, parent_feedback_id, message, entry_type)
            VALUES (?, ?, ?, ?, ?, 'comment')
        ");
        if (!$reply) {
            throw new RuntimeException('Unable to prepare your reply: ' . $conn->error);
        }
        $fileId = (int) $task['file_id'];
        $reply->bind_param('iiiis', $fileId, $userId, $userId, $feedbackId, $message);
        if (!$reply->execute()) {
            throw new RuntimeException('Unable to save your reply: ' . $reply->error);
        }
        $replyId = (int) $reply->insert_id;
        $reply->close();
        if (!empty($task['created_by'])) {
            fileFeedbackNotify($conn, $replyId, [(int) $task['created_by']], 'reply');
        }

        if (!empty($task['created_by'])) {
            fileFeedbackNotify($conn, $feedbackId, [(int) $task['created_by']], 'task_completed');
        }
        $conn->commit();
        $transactionStarted = false;
        logActivity(
            $conn,
            $userId,
            'COMPLETE_REMARK_TASK',
            "Completed remark/checklist task ID $feedbackId and replied for file ID $fileId"
        );
        $_SESSION['feedback_message'] = 'Your reply was sent and the task was marked as complete.';
    } else {
        throw new RuntimeException('This action is not available for your account.');
    }
} catch (Throwable $exception) {
    if (!empty($transactionStarted)) {
        $conn->rollback();
    }
    $_SESSION['feedback_error'] = $exception->getMessage();
}

header('Location: ' . $redirectUrl);
exit;
