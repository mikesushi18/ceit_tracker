<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/file_feedback.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $tableExists = $conn->query("SHOW TABLES LIKE 'file_feedback_notifications'");
    if (!$tableExists) {
        throw new RuntimeException('Unable to check notification storage: ' . $conn->error);
    }
    $unreadCount = 0;
    if ($tableExists->num_rows > 0) {
        $unreadCount = fileFeedbackUnreadCount($conn, (int) $_SESSION['user_id']);
    }
    echo json_encode(['unread_count' => $unreadCount]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => $exception->getMessage()]);
}
