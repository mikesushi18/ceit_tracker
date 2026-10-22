<?php
function getFolderSubjectProgress(mysqli $conn, int $departmentId, array $folderIds): array {
    $folderIds = array_values(array_unique(array_filter(array_map('intval', $folderIds), static fn($id) => $id > 0)));
    if (!$folderIds) return [];

    $folderIdList = implode(',', $folderIds);
    $result = $conn->query("
        SELECT progress.folder_id,
            SUM(CASE WHEN progress.subject_count > 0 THEN 1 ELSE 0 END) AS assigned_faculty_count,
            SUM(CASE WHEN progress.subject_count > 0 AND progress.submitted_subject_count >= progress.subject_count THEN 1 ELSE 0 END) AS complete_faculty_count,
            SUM(CASE WHEN progress.submitted_subject_count > 0 AND progress.submitted_subject_count < progress.subject_count THEN 1 ELSE 0 END) AS partial_faculty_count,
            SUM(CASE WHEN progress.submitted_subject_count > 0 THEN 1 ELSE 0 END) AS started_faculty_count
        FROM (
            SELECT f.id AS folder_id, u.id AS faculty_id,
                COUNT(DISTINCT s.subject_code) AS subject_count,
                COUNT(DISTINCT CASE WHEN fi.id IS NOT NULL THEN s.subject_code END) AS submitted_subject_count
            FROM folders f
            JOIN users u ON u.department_id = f.department_id AND u.role IN ('faculty', 'chairperson') AND u.is_active = 1
            LEFT JOIN subjects s ON s.faculty_id = u.id AND s.department_id = u.department_id
            LEFT JOIN files fi ON fi.folder_id = f.id AND fi.uploaded_by = u.id AND fi.subject_code = s.subject_code
            WHERE f.department_id = $departmentId AND f.id IN ($folderIdList)
            GROUP BY f.id, u.id
        ) AS progress
        GROUP BY progress.folder_id
    ");

    if (!$result) return [];

    $progressByFolder = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $progressByFolder[(int) $row['folder_id']] = [
            'assigned_faculty_count' => (int) $row['assigned_faculty_count'],
            'complete_faculty_count' => (int) $row['complete_faculty_count'],
            'partial_faculty_count' => (int) $row['partial_faculty_count'],
            'started_faculty_count' => (int) $row['started_faculty_count'],
        ];
    }

    return $progressByFolder;
}