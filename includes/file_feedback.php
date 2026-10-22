<?php

function fileFeedbackEnsureSchema(mysqli $conn): void
{
    $result = $conn->query("
        CREATE TABLE IF NOT EXISTS file_feedback (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            file_id INT NOT NULL,
            faculty_id INT NOT NULL,
            created_by INT NULL,
            parent_feedback_id BIGINT NULL,
            message TEXT NOT NULL,
            entry_type ENUM('comment', 'task') NOT NULL DEFAULT 'comment',
            is_completed TINYINT(1) NOT NULL DEFAULT 0,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            INDEX idx_file_feedback_faculty (faculty_id, is_read, created_at),
            INDEX idx_file_feedback_file (file_id, created_at),
            INDEX idx_file_feedback_parent (parent_feedback_id),
            CONSTRAINT fk_file_feedback_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE,
            CONSTRAINT fk_file_feedback_faculty FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_file_feedback_author FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_file_feedback_parent FOREIGN KEY (parent_feedback_id) REFERENCES file_feedback(id) ON DELETE CASCADE
        )
    ");
    if (!$result) {
        throw new RuntimeException('Unable to initialize file comments and tasks: ' . $conn->error);
    }

    $parentColumn = $conn->query("SHOW COLUMNS FROM file_feedback LIKE 'parent_feedback_id'");
    if (!$parentColumn) {
        throw new RuntimeException('Unable to check file remark reply support: ' . $conn->error);
    }
    $hasParentColumn = $parentColumn->num_rows > 0;
    $parentColumn->free();
    if (!$hasParentColumn) {
        $altered = $conn->query("
            ALTER TABLE file_feedback
            ADD COLUMN parent_feedback_id BIGINT NULL AFTER created_by,
            ADD INDEX idx_file_feedback_parent (parent_feedback_id),
            ADD CONSTRAINT fk_file_feedback_parent
                FOREIGN KEY (parent_feedback_id) REFERENCES file_feedback(id) ON DELETE CASCADE
        ");
        if (!$altered) {
            throw new RuntimeException('Unable to enable replies to file remarks: ' . $conn->error);
        }
    }

    $feedbackIdColumn = $conn->query("SHOW COLUMNS FROM file_feedback LIKE 'id'");
    if (!$feedbackIdColumn || $feedbackIdColumn->num_rows !== 1) {
        throw new RuntimeException('Unable to verify the file remark ID column: ' . $conn->error);
    }
    $feedbackIdDefinition = $feedbackIdColumn->fetch_assoc();
    $feedbackIdColumn->free();
    if (stripos((string) $feedbackIdDefinition['Extra'], 'auto_increment') === false) {
        $zeroFeedback = $conn->query('SELECT 1 FROM file_feedback WHERE id = 0 LIMIT 1');
        if (!$zeroFeedback) {
            throw new RuntimeException('Unable to check file remark IDs: ' . $conn->error);
        }
        if ($zeroFeedback->num_rows > 0) {
            $zeroFeedback->free();
            $nextIdResult = $conn->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM file_feedback');
            if (!$nextIdResult) {
                throw new RuntimeException('Unable to choose a new file remark ID: ' . $conn->error);
            }
            $nextId = (int) $nextIdResult->fetch_assoc()['next_id'];
            $nextIdResult->free();

            $notificationTable = $conn->query("SHOW TABLES LIKE 'file_feedback_notifications'");
            if (!$notificationTable) {
                throw new RuntimeException('Unable to check references for the legacy remark: ' . $conn->error);
            }
            $hasNotificationTable = $notificationTable->num_rows > 0;
            $notificationTable->free();
            if ($hasNotificationTable) {
                $updateReferences = $conn->prepare('UPDATE file_feedback_notifications SET feedback_id = ? WHERE feedback_id = 0');
                if (!$updateReferences) {
                    throw new RuntimeException('Unable to update notification references for the legacy remark: ' . $conn->error);
                }
                $updateReferences->bind_param('i', $nextId);
                if (!$updateReferences->execute()) {
                    $error = $updateReferences->error;
                    $updateReferences->close();
                    throw new RuntimeException('Unable to update notification references for the legacy remark: ' . $error);
                }
                $updateReferences->close();
            }

            $updateParentReferences = $conn->prepare('UPDATE file_feedback SET parent_feedback_id = ? WHERE parent_feedback_id = 0');
            if (!$updateParentReferences) {
                throw new RuntimeException('Unable to update reply references for the legacy remark: ' . $conn->error);
            }
            $updateParentReferences->bind_param('i', $nextId);
            if (!$updateParentReferences->execute()) {
                $error = $updateParentReferences->error;
                $updateParentReferences->close();
                throw new RuntimeException('Unable to update reply references for the legacy remark: ' . $error);
            }
            $updateParentReferences->close();

            $updateFeedbackId = $conn->prepare('UPDATE file_feedback SET id = ? WHERE id = 0');
            if (!$updateFeedbackId) {
                throw new RuntimeException('Unable to repair the legacy remark ID: ' . $conn->error);
            }
            $updateFeedbackId->bind_param('i', $nextId);
            if (!$updateFeedbackId->execute()) {
                $error = $updateFeedbackId->error;
                $updateFeedbackId->close();
                throw new RuntimeException('Unable to repair the legacy remark ID: ' . $error);
            }
            $updateFeedbackId->close();
        } else {
            $zeroFeedback->free();
        }

        if (!$conn->query('ALTER TABLE file_feedback MODIFY id BIGINT NOT NULL AUTO_INCREMENT')) {
            throw new RuntimeException('Unable to enable automatic file remark IDs: ' . $conn->error);
        }
    }

    $editedAtColumn = $conn->query("SHOW COLUMNS FROM file_feedback LIKE 'edited_at'");
    if (!$editedAtColumn) {
        throw new RuntimeException('Unable to check file remark edit history: ' . $conn->error);
    }
    $hasEditedAtColumn = $editedAtColumn->num_rows > 0;
    $editedAtColumn->free();
    if (!$hasEditedAtColumn && !$conn->query('ALTER TABLE file_feedback ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL AFTER created_at')) {
        throw new RuntimeException('Unable to enable file remark edit history: ' . $conn->error);
    }

    $notifications = $conn->query("
        CREATE TABLE IF NOT EXISTS file_feedback_notifications (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            feedback_id BIGINT NOT NULL,
            recipient_id INT NOT NULL,
            event_type ENUM('new_feedback', 'task_completed', 'file_replaced', 'reply') NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_feedback_recipient_event (feedback_id, recipient_id, event_type),
            INDEX idx_feedback_notifications_recipient (recipient_id, is_read, created_at),
            CONSTRAINT fk_feedback_notification_feedback FOREIGN KEY (feedback_id) REFERENCES file_feedback(id) ON DELETE CASCADE,
            CONSTRAINT fk_feedback_notification_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    if (!$notifications) {
        throw new RuntimeException('Unable to initialize file feedback notifications: ' . $conn->error);
    }

    $notificationIdColumn = $conn->query("SHOW COLUMNS FROM file_feedback_notifications LIKE 'id'");
    if (!$notificationIdColumn || $notificationIdColumn->num_rows !== 1) {
        throw new RuntimeException('Unable to verify the notification ID column: ' . $conn->error);
    }
    $notificationIdDefinition = $notificationIdColumn->fetch_assoc();
    $notificationIdColumn->free();

    if (stripos((string) $notificationIdDefinition['Extra'], 'auto_increment') === false) {
        do {
            $duplicateIdResult = $conn->query('SELECT id FROM file_feedback_notifications GROUP BY id HAVING id = 0 OR COUNT(*) > 1 LIMIT 1');
            if (!$duplicateIdResult) {
                throw new RuntimeException('Unable to check notification IDs: ' . $conn->error);
            }
            $duplicateId = $duplicateIdResult->fetch_assoc();
            $duplicateIdResult->free();
            if (!$duplicateId) {
                break;
            }

            $nextIdResult = $conn->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM file_feedback_notifications');
            if (!$nextIdResult) {
                throw new RuntimeException('Unable to choose a new notification ID: ' . $conn->error);
            }
            $nextId = (int) $nextIdResult->fetch_assoc()['next_id'];
            $nextIdResult->free();
            $oldId = (int) $duplicateId['id'];
            $repairId = $conn->prepare('UPDATE file_feedback_notifications SET id = ? WHERE id = ? LIMIT 1');
            if (!$repairId) {
                throw new RuntimeException('Unable to prepare notification ID repair: ' . $conn->error);
            }
            $repairId->bind_param('ii', $nextId, $oldId);
            if (!$repairId->execute()) {
                $error = $repairId->error;
                $repairId->close();
                throw new RuntimeException('Unable to repair a notification ID: ' . $error);
            }
            $repairId->close();
        } while (true);

        $primaryKey = $conn->query("SHOW INDEX FROM file_feedback_notifications WHERE Key_name = 'PRIMARY'");
        if (!$primaryKey) {
            throw new RuntimeException('Unable to check the notification primary key: ' . $conn->error);
        }
        $hasPrimaryKey = $primaryKey->num_rows > 0;
        $primaryKey->free();
        if (!$hasPrimaryKey && !$conn->query('ALTER TABLE file_feedback_notifications ADD PRIMARY KEY (id)')) {
            throw new RuntimeException('Unable to add the notification primary key: ' . $conn->error);
        }
        if (!$conn->query('ALTER TABLE file_feedback_notifications MODIFY id BIGINT NOT NULL AUTO_INCREMENT')) {
            throw new RuntimeException('Unable to enable automatic notification IDs: ' . $conn->error);
        }
    }

    $duplicateNotifications = $conn->query("
        DELETE older
        FROM file_feedback_notifications older
        INNER JOIN file_feedback_notifications newer
            ON newer.feedback_id = older.feedback_id
            AND newer.recipient_id = older.recipient_id
            AND newer.event_type = older.event_type
            AND newer.id > older.id
    ");
    if (!$duplicateNotifications) {
        throw new RuntimeException('Unable to remove duplicate notifications: ' . $conn->error);
    }

    $uniqueNotificationIndex = $conn->query("SHOW INDEX FROM file_feedback_notifications WHERE Key_name = 'unique_feedback_recipient_event'");
    if (!$uniqueNotificationIndex) {
        throw new RuntimeException('Unable to check notification de-duplication: ' . $conn->error);
    }
    $hasUniqueNotificationIndex = $uniqueNotificationIndex->num_rows > 0;
    $uniqueNotificationIndex->free();
    if (!$hasUniqueNotificationIndex && !$conn->query('ALTER TABLE file_feedback_notifications ADD UNIQUE KEY unique_feedback_recipient_event (feedback_id, recipient_id, event_type)')) {
        throw new RuntimeException('Unable to enforce one notification per remark event: ' . $conn->error);
    }

    $eventTypeColumn = $conn->query("SHOW COLUMNS FROM file_feedback_notifications LIKE 'event_type'");
    if (!$eventTypeColumn) {
        throw new RuntimeException('Unable to check feedback notification types: ' . $conn->error);
    }
    $eventTypeDefinition = $eventTypeColumn->fetch_assoc();
    $eventTypeColumn->free();
    $eventType = (string) ($eventTypeDefinition['Type'] ?? '');
    if (!$eventTypeDefinition || !str_contains($eventType, "'file_replaced'") || !str_contains($eventType, "'reply'")) {
        $altered = $conn->query("ALTER TABLE file_feedback_notifications MODIFY event_type ENUM('new_feedback', 'task_completed', 'file_replaced', 'reply') NOT NULL");
        if (!$altered) {
            throw new RuntimeException('Unable to enable file replacement notifications: ' . $conn->error);
        }
    }
}

function fileFeedbackCsrfToken(): string
{
    if (empty($_SESSION['file_feedback_csrf_token'])) {
        $_SESSION['file_feedback_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['file_feedback_csrf_token'];
}

function fileFeedbackUnreadCount(mysqli $conn, int $facultyId): int
{
    $stmt = $conn->prepare("
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
    if (!$stmt) {
        throw new RuntimeException('Unable to load unread file feedback: ' . $conn->error);
    }
    $stmt->bind_param('i', $facultyId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to load unread file feedback: ' . $error);
    }
    $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

function fileFeedbackNotify(mysqli $conn, int $feedbackId, array $recipientIds, string $eventType): void
{
    if (!in_array($eventType, ['new_feedback', 'task_completed', 'file_replaced', 'reply'], true)) {
        throw new InvalidArgumentException('Invalid feedback notification type.');
    }
    if ($feedbackId <= 0) {
        throw new InvalidArgumentException('A valid file remark is required for a notification.');
    }
    $recipientIds = array_values(array_unique(array_filter(array_map('intval', $recipientIds), static fn(int $id): bool => $id > 0)));
    if (!$recipientIds) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO file_feedback_notifications (feedback_id, recipient_id, event_type)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE is_read = 0, created_at = CURRENT_TIMESTAMP
    ");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare feedback notification: ' . $conn->error);
    }
    foreach ($recipientIds as $recipientId) {
        $stmt->bind_param('iis', $feedbackId, $recipientId, $eventType);
        if (!$stmt->execute()) {
            $message = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Unable to save feedback notification: ' . $message);
        }
    }
    $stmt->close();
}

function fileFeedbackNotifyFileReplaced(mysqli $conn, int $fileId): void
{
    $stmt = $conn->prepare("
        SELECT id, created_by
        FROM file_feedback
        WHERE file_id = ? AND entry_type = 'comment' AND created_by IS NOT NULL
    ");
    if (!$stmt) {
        throw new RuntimeException('Unable to find remarks for the replaced file: ' . $conn->error);
    }
    $stmt->bind_param('i', $fileId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to find remarks for the replaced file: ' . $error);
    }
    $remarks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($remarks as $remark) {
        fileFeedbackNotify($conn, (int) $remark['id'], [(int) $remark['created_by']], 'file_replaced');
    }
}

function fileFeedbackForFiles(mysqli $conn, array $fileIds): array
{
    $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), static fn(int $id): bool => $id > 0)));
    if (!$fileIds) {
        return [];
    }
    $idList = implode(',', $fileIds);
    $result = $conn->query("
        SELECT ff.*, COALESCE(u.full_name, 'Deleted User') AS author_name
        FROM file_feedback ff
        LEFT JOIN users u ON u.id = ff.created_by
        WHERE ff.file_id IN ($idList)
        ORDER BY ff.created_at ASC, ff.id ASC
    ");
    if (!$result) {
        throw new RuntimeException('Unable to load file comments and tasks: ' . $conn->error);
    }
    $feedbackByFile = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $feedback) {
        $feedbackByFile[(int) $feedback['file_id']][] = $feedback;
    }
    return $feedbackByFile;
}

function renderFileFeedbackButton(int $fileId, array $items, int $userId, bool $allowCreate = true): void
{
    if (!$allowCreate) {
        return;
    }
    $hasOwnFeedback = false;
    foreach ($items as $item) {
        if (
            (int) ($item['created_by'] ?? 0) === $userId
            && empty($item['parent_feedback_id'])
            && !($item['entry_type'] === 'task' && (int) ($item['is_completed'] ?? 0) === 1)
        ) {
            $hasOwnFeedback = true;
            break;
        }
    }
    ?>
    <?php if ($hasOwnFeedback): ?>
        <button type="button" class="btn-comment btn-comment-edit" aria-label="Edit your comment for this file" title="Edit comment"
            onclick="openFileFeedback(<?= $fileId ?>)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            <span>Edit comment</span>
        </button>
    <?php else: ?>
        <button type="button" class="btn-comment" aria-label="Add a remark for this file" title="Add remark"
            onclick="openFileFeedback(<?= $fileId ?>)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.5 8.5 0 0 1-12.9 7.3L3 20l1.2-4.1A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8 11h8M8 15h5"/></svg>
        </button>
    <?php endif; ?>
    <?php
}

function renderFileFeedbackPanel(array $items, int $fileId, string $csrfToken, bool $allowCreate = true): void
{
    if (!$allowCreate) {
        return;
    }
    $completedTaskIds = [];
    foreach ($items as $item) {
        if (empty($item['parent_feedback_id']) && $item['entry_type'] === 'task' && (int) ($item['is_completed'] ?? 0) === 1) {
            $completedTaskIds[(int) $item['id']] = true;
        }
    }
    $visibleItems = array_values(array_filter(
        $items,
        static fn(array $item): bool => !isset($completedTaskIds[(int) ($item['id'] ?? 0)])
            && !isset($completedTaskIds[(int) ($item['parent_feedback_id'] ?? 0)])
    ));
    $ownFeedback = [];
    foreach ($visibleItems as $item) {
        if ((int) ($item['created_by'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0) && empty($item['parent_feedback_id'])) {
            $ownFeedback[] = $item;
        }
    }
    ?>
    <div class="feedback-modal" id="feedback-<?= $fileId ?>" hidden onclick="closeFileFeedbackOnBackdrop(event)">
        <section class="feedback-modal-box" role="dialog" aria-modal="true" aria-labelledby="feedback-title-<?= $fileId ?>">
            <div class="feedback-modal-header">
                <div>
                    <h2 id="feedback-title-<?= $fileId ?>">File remarks</h2>
                    <p>Review existing remarks or send a checklist task to faculty.</p>
                </div>
                <button type="button" class="feedback-modal-close" aria-label="Close remarks" onclick="closeFileFeedback(<?= $fileId ?>)">×</button>
            </div>
            <div class="feedback-modal-content">
                <?php
                $repliesByParent = [];
                foreach ($visibleItems as $item) {
                    if (!empty($item['parent_feedback_id'])) {
                        $repliesByParent[(int) $item['parent_feedback_id']][] = $item;
                    }
                }
                foreach ($visibleItems as $item):
                    if (!empty($item['parent_feedback_id'])) {
                        continue;
                    }
                ?>
                    <div class="file-feedback-thread">
                    <div class="file-feedback-item">
                        <span class="file-feedback-type"><?= $item['entry_type'] === 'task' ? 'Task' : 'Comment' ?></span>
                        <span><?= htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8') ?></span>
                        <small>By <?= htmlspecialchars($item['author_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(date('M d, Y g:i A', strtotime($item['created_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                        <?php if (!empty($item['edited_at'])): ?>
                            <small class="file-feedback-edited">Edited <?= htmlspecialchars(date('M d, Y g:i A', strtotime($item['edited_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                        <?php endif; ?>
                        <?php if ((int) ($item['created_by'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0)): ?>
                            <button type="button" class="feedback-edit-toggle" aria-expanded="false" aria-controls="edit-feedback-<?= (int) $item['id'] ?>" onclick="toggleFeedbackEdit(<?= (int) $item['id'] ?>, this)">Edit comment</button>
                            <form class="file-feedback-form feedback-edit-form" id="edit-feedback-<?= (int) $item['id'] ?>" method="POST" action="<?= htmlspecialchars(BASE_URL . '/feedback_action.php', ENT_QUOTES, 'UTF-8') ?>" hidden>
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="file_id" value="<?= $fileId ?>">
                                <input type="hidden" name="feedback_id" value="<?= (int) $item['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <textarea name="message" maxlength="2000" required><?= htmlspecialchars($item['message'], ENT_QUOTES, 'UTF-8') ?></textarea>
                                <button type="submit">Save comment</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($item['entry_type'] === 'task'): ?>
                            <small class="file-feedback-status">
                                <?= (int) $item['is_completed'] === 1
                                    ? 'Completed by faculty' . (!empty($item['completed_at']) ? ' · ' . htmlspecialchars(date('M d, Y g:i A', strtotime($item['completed_at'])), ENT_QUOTES, 'UTF-8') : '')
                                    : 'Waiting for faculty' ?>
                            </small>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($repliesByParent[(int) $item['id']] ?? [] as $reply): ?>
                        <div class="file-feedback-reply">
                            <span class="file-feedback-type">Faculty reply</span>
                            <span><?= htmlspecialchars($reply['message'], ENT_QUOTES, 'UTF-8') ?></span>
                            <small>By <?= htmlspecialchars($reply['author_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(date('M d, Y g:i A', strtotime($reply['created_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$visibleItems): ?><p class="file-feedback-empty">No active remarks or tasks for this file.</p><?php endif; ?>
            <?php if (!$ownFeedback): ?>
            <form class="file-feedback-form" method="POST" action="<?= htmlspecialchars(BASE_URL . '/feedback_action.php', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="entry_type" value="task">
                <input type="hidden" name="file_id" value="<?= $fileId ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <textarea name="message" maxlength="2000" required placeholder="Write the remark or checklist task for the faculty member"></textarea>
                <button type="submit">Send Remark</button>
            </form>
            <?php endif; ?>
            </div>
        </section>
    </div>
    <?php
}

function renderFileFeedbackScripts(): void
{
    ?>
    <style>
    .file-feedback-target-highlight { animation:file-feedback-highlight 2.8s ease-out; }
    tr.file-feedback-target-highlight > td { animation:file-feedback-highlight 2.8s ease-out; }
    @keyframes file-feedback-highlight {
        0%,45% { background-color:#ffedd5!important;box-shadow:0 0 0 2px rgba(249,115,22,.28); }
        100% { background-color:transparent;box-shadow:none; }
    }
    </style>
    <script>
    let activeFeedbackModal = null;
    let previousFeedbackFocus = null;
    function openFileFeedback(fileId) {
        const modal = document.getElementById('feedback-' + fileId);
        if (!modal) return;
        previousFeedbackFocus = document.activeElement;
        activeFeedbackModal = modal;
        modal.hidden = false;
        requestAnimationFrame(() => {
            modal.classList.add('open');
            modal.querySelector('.feedback-modal-close').focus();
        });
        document.body.style.overflow = 'hidden';
    }
    function closeFileFeedback(fileId) {
        const modal = document.getElementById('feedback-' + fileId);
        if (!modal) return;
        modal.classList.remove('open');
        window.setTimeout(() => { modal.hidden = true; }, 200);
        document.body.style.overflow = '';
        activeFeedbackModal = null;
        if (previousFeedbackFocus) previousFeedbackFocus.focus();
    }
    function closeFileFeedbackOnBackdrop(event) {
        if (event.target === event.currentTarget && activeFeedbackModal) {
            closeFileFeedback(activeFeedbackModal.id.replace('feedback-', ''));
        }
    }
    function toggleFeedbackEdit(feedbackId, button) {
        const form = document.getElementById('edit-feedback-' + feedbackId);
        if (!form) return;
        form.hidden = !form.hidden;
        button.setAttribute('aria-expanded', String(!form.hidden));
        if (!form.hidden) form.querySelector('textarea').focus();
    }
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && activeFeedbackModal) {
            closeFileFeedback(activeFeedbackModal.id.replace('feedback-', ''));
        }
    });
    window.addEventListener('load', () => {
        const match = window.location.hash.match(/^#feedback-(\d+)$/);
        if (!match) return;
        const fileId = Number(match[1]);
        const fileRow = document.getElementById('file-' + fileId);
        if (fileRow) {
            fileRow.closest('tbody[hidden]')?.removeAttribute('hidden');
            fileRow.classList.add('file-feedback-target-highlight');
            fileRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            window.setTimeout(() => fileRow.classList.remove('file-feedback-target-highlight'), 3000);
        }
        window.setTimeout(() => openFileFeedback(fileId), fileRow ? 250 : 0);
    });
    </script>
    <?php
}
