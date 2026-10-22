<?php
require_once '../includes/config.php';
require_once '../includes/school_year_helper.php';
requireRole('faculty');

$user_id = (int) $_SESSION['user_id'];
$department_id = (int) ($_SESSION['department_id'] ?? 0);
if (!$department_id) redirect(BASE_URL . '/index.php');

$department = $conn->query("SELECT id, code, name FROM departments WHERE id = $department_id")->fetch_assoc();
if (!$department) redirect(BASE_URL . '/index.php');

$active_school_year = setupSchoolYears($conn);
$active_school_year_id = (int) ($active_school_year['id'] ?? 0);
$conn->query("CREATE TABLE IF NOT EXISTS subjects (
	id INT AUTO_INCREMENT PRIMARY KEY,
	department_id INT NOT NULL,
	faculty_id INT NOT NULL,
	subject_code VARCHAR(100) NOT NULL,
	subject_description VARCHAR(255) NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY unique_faculty_subject (faculty_id, subject_code),
	FOREIGN KEY (department_id) REFERENCES departments(id),
	FOREIGN KEY (faculty_id) REFERENCES users(id)
)");
$conn->query("ALTER TABLE folder_assignments ADD COLUMN IF NOT EXISTS has_subject_scope TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("CREATE TABLE IF NOT EXISTS folder_assignment_subjects (
	assignment_id INT NOT NULL,
	subject_id INT NOT NULL,
	PRIMARY KEY (assignment_id, subject_id),
	FOREIGN KEY (assignment_id) REFERENCES folder_assignments(id) ON DELETE CASCADE,
	FOREIGN KEY (subject_id) REFERENCES department_subjects(id) ON DELETE CASCADE
)");

$subject_rows = $conn->query("
	SELECT ds.id AS subject_id, ds.subject_code, ds.subject_description,
		f.id AS folder_id, f.folder_name, f.subfolder_position,
		fg.name AS group_name, fg.deadline,
		COUNT(fi.id) AS file_count, MAX(fi.uploaded_at) AS last_uploaded_at
	FROM subjects s
	JOIN department_subjects ds ON ds.department_id = s.department_id AND ds.subject_code = s.subject_code
	JOIN folders f ON f.department_id = s.department_id
	LEFT JOIN folder_groups fg ON fg.id = f.group_id
	LEFT JOIN files fi
		ON fi.folder_id = f.id AND fi.uploaded_by = $user_id AND fi.subject_code = ds.subject_code
	WHERE s.faculty_id = $user_id AND s.department_id = $department_id
		AND f.department_id = $department_id AND f.school_year_id = $active_school_year_id
		AND (f.group_id IS NULL OR fg.status = 'active')
	GROUP BY ds.id, ds.subject_code, ds.subject_description, f.id, f.folder_name,
		f.subfolder_position, fg.id, fg.name, fg.deadline
	ORDER BY ds.subject_code, fg.name, f.subfolder_position, f.folder_name
")->fetch_all(MYSQLI_ASSOC);

$subjects = [];
$assigned_subjects = $conn->query("SELECT ds.id AS subject_id, ds.subject_code, ds.subject_description FROM subjects s JOIN department_subjects ds ON ds.department_id = s.department_id AND ds.subject_code = s.subject_code WHERE s.faculty_id = $user_id AND s.department_id = $department_id ORDER BY ds.subject_code")->fetch_all(MYSQLI_ASSOC);
foreach ($assigned_subjects as $assigned_subject) {
	$subjects[(int) $assigned_subject['subject_id']] = [
		'subject_code' => $assigned_subject['subject_code'],
		'subject_description' => $assigned_subject['subject_description'],
		'requirements' => [],
	];
}
foreach ($subject_rows as $row) {
	$subject_id = (int) $row['subject_id'];
	if (!isset($subjects[$subject_id])) {
		$subjects[$subject_id] = [
			'subject_code' => $row['subject_code'],
			'subject_description' => $row['subject_description'],
			'requirements' => [],
		];
	}
	$subjects[$subject_id]['requirements'][] = $row;
}

$total_requirements = 0;
$completed_requirements = 0;
foreach ($subjects as $subject) {
	$total_requirements += count($subject['requirements']);
	foreach ($subject['requirements'] as $requirement) {
		if ((int) $requirement['file_count'] > 0) $completed_requirements++;
	}
}
$completion_percent = $total_requirements ? (int) round($completed_requirements / $total_requirements * 100) : 0;

function subjectProgressColor($percentage) {
	$percentage = max(0, min(100, (int) $percentage));
	$red = [220, 38, 38];
	$orange = [249, 115, 22];
	$green = [22, 163, 74];
	$start = $percentage <= 50 ? $red : $orange;
	$end = $percentage <= 50 ? $orange : $green;
	$progress = $percentage <= 50 ? $percentage / 50 : ($percentage - 50) / 50;
	$channels = array_map(static fn($from, $to) => (int) round($from + ($to - $from) * $progress), $start, $end);
	return sprintf('#%02x%02x%02x', $channels[0], $channels[1], $channels[2]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>My Subjects — CollTrace</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<style>
		* { box-sizing: border-box; margin: 0; padding: 0; }
		:root { --ink:#1c1917; --muted:#78716c; --line:#e7e5e4; --orange:#f97316; --orange-dark:#c2410c; --page:#fff7ed; }
		body { min-height:100vh; background:var(--page); color:var(--ink); font-family:'Inter','Segoe UI',sans-serif; }
		.header { position:fixed; inset:0 0 auto; z-index:10; height:62px; display:flex; align-items:center; justify-content:space-between; gap:16px; padding:0 28px; background:#1c1917; border-bottom:2px solid var(--orange); box-shadow:0 2px 16px rgba(0,0,0,.25); color:#fff; }
		.header-left,.header-right,.brand { display:flex; align-items:center; }
		.header-left,.header-right { gap:14px; }
		.brand { gap:10px; color:#fff; font-size:21px; font-weight:800; text-decoration:none; }
		.brand-logo { width:30px; height:30px; object-fit:contain; }
		.brand-accent { color:var(--orange); }
		.role-badge { padding:4px 12px; border-radius:20px; background:var(--orange); color:#fff; font-size:11px; font-weight:700; }
		.header-link { padding:7px 14px; border:1px solid rgba(255,255,255,.25); border-radius:7px; color:#fff; font-size:13px; text-decoration:none; }
		.header-link:hover { background:rgba(255,255,255,.1); }
		.container { width:min(1120px,calc(100% - 40px)); margin:0 auto; padding:94px 0 48px; }
		.breadcrumb { margin-bottom:7px; color:var(--muted); font-size:12px; }
		.breadcrumb a { color:#57534e; text-decoration:none; }
		h1 { font-size:23px; line-height:1.3; font-weight:800; }
		.subtitle { margin-top:5px; color:var(--muted); font-size:13px; line-height:1.5; }
		.overview { display:flex; align-items:center; gap:20px; margin:22px 0; padding:16px 18px; border:1px solid var(--line); border-radius:10px; background:#fff; }
		.overview-stats { display:flex; gap:20px; flex:none; }
		.stat strong { display:block; font-size:18px; }
		.stat span { color:var(--muted); font-size:11px; }
		.progress-track { height:8px; flex:1; overflow:hidden; border-radius:8px; background:#f1f0ee; }
		.progress-value { height:100%; border-radius:inherit; background:var(--progress-color); transition:width .5s ease,background-color .5s ease; }
		.overall-percent { min-width:42px; color:var(--progress-color); font-size:12px; font-weight:800; text-align:right; transition:color .5s ease; }
		.subject-section { margin-top:26px; }
		.subject-heading { display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:10px; }
		.subject-heading h2 { font-size:16px; }
		.subject-heading span { color:var(--muted); font-size:11px; }
		.subject-list { display:grid; gap:10px; }
		.subject-panel { overflow:hidden; margin-bottom:14px; border:1px solid var(--line); border-radius:10px; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,.04); }
		.subject-panel-header { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:15px 18px; border-bottom:1px solid var(--line); background:linear-gradient(90deg,#fff7ed,#fff 60%); }
		.subject-panel-title { min-width:0; }
		.subject-code { display:block; color:var(--subject-progress-color); font-size:13px; font-weight:800; transition:color .5s ease; }
		.subject-description { margin-top:3px; color:var(--muted); font-size:12px; }
		.subject-progress { flex:none; color:var(--subject-progress-color); font-size:12px; font-weight:700; transition:color .5s ease; }
		.subject-toggle { display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:34px; flex:none; padding:0 11px; border:1px solid #d6d3d1; border-radius:7px; background:#fff; color:#292524; font:inherit; font-size:11px; font-weight:700; cursor:pointer; transition:background .15s,border-color .15s; }
		.subject-toggle:hover { border-color:var(--orange); background:#fff7ed; }
		.subject-toggle svg { width:14px; height:14px; transition:transform .15s; }
		.subject-toggle[aria-expanded="true"] svg { transform:rotate(180deg); }
		.subject-requirements[hidden] { display:none; }
		.requirement-row { display:grid; grid-template-columns:minmax(0,1fr) auto auto; align-items:center; gap:16px; min-height:48px; padding:8px 18px; border-bottom:1px solid #f3f1ef; }
		.requirement-row:last-child { border-bottom:0; }
		.requirement-name { min-width:0; font-size:12px; font-weight:600; }
		.requirement-group { display:block; margin-top:3px; color:var(--muted); font-size:10px; font-weight:400; }
		.status { min-width:86px; padding:4px 9px; border-radius:20px; font-size:10px; font-weight:700; text-align:center; }
		.status.done { background:#edfff3; color:#166534; }
		.status.pending { background:#fff8e1; color:#92400e; }
		.open-folder { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border:1px solid #d6d3d1; border-radius:7px; background:#fff; color:#292524; text-decoration:none; transition:background .15s,border-color .15s,color .15s; }
		.open-folder:hover { border-color:var(--orange); background:#fff7ed; }
		.open-folder svg { width:16px; height:16px; }
		.empty { padding:34px 20px; border:1px dashed #d6d3d1; border-radius:10px; background:#fff; color:var(--muted); font-size:13px; text-align:center; }
		.see-all-wrap { display:flex; justify-content:center; margin:14px 0 4px; }
		.see-all { display:inline-flex; align-items:center; gap:8px; min-height:38px; padding:0 14px; border:1px solid #fed7aa; border-radius:7px; background:#fff; color:var(--orange-dark); font:inherit; font-size:12px; font-weight:700; cursor:pointer; transition:background .15s,border-color .15s; }
		.see-all:hover { border-color:var(--orange); background:#fff7ed; }
		.see-all svg { width:15px; height:15px; transition:transform .15s; }
		.see-all[aria-expanded="true"] svg { transform:rotate(180deg); }
		@media(max-width:700px) { .header { height:58px; padding:0 14px; } .header-left,.header-right { gap:7px; } .brand { gap:7px; font-size:18px; } .brand-logo { width:26px; height:26px; } .header-link { padding:6px 9px; font-size:12px; } .role-badge { display:none; } .container { width:calc(100% - 28px); padding-top:82px; } .overview { flex-wrap:wrap; gap:12px; } .overview-stats { width:100%; justify-content:space-between; } }
		@media(max-width:520px) { .subject-panel-header { align-items:flex-start; flex-direction:column; gap:10px; } .subject-panel-controls { width:100%; justify-content:space-between; } .requirement-row { grid-template-columns:minmax(0,1fr) auto; gap:8px 12px; padding:10px 12px; } .status { min-width:76px; } .open-folder { grid-column:2; justify-self:end; } }
	</style>
</head>
<body>
<header class="header">
	<div class="header-left">
		<a class="brand" href="dashboard.php"><img src="../ceit_logo.png" alt="CEIT Logo" class="brand-logo"><span>Coll<span class="brand-accent">Trace</span></span></a>
		<a href="dashboard.php" class="header-link">← Dashboard</a>
	</div>
	<div class="header-right"><span class="role-badge">FACULTY</span><a href="../logout.php" class="header-link">Logout</a></div>
</header>
<main class="container">
	<div class="breadcrumb"><a href="dashboard.php">Dashboard</a> / My Subjects</div>
	<h1>My Subjects</h1>
	<p class="subtitle"><?= sanitize($department['code']) ?> · <?= sanitize($active_school_year['label'] ?? 'Current school year') ?> · Requirements assigned by your chairperson</p>

	<?php if ($subjects): ?>
	<section class="overview" aria-label="Submission overview">
		<div class="overview-stats">
			<div class="stat"><strong><?= count($subjects) ?></strong><span>Subjects</span></div>
			<div class="stat"><strong><?= $completed_requirements ?>/<?= $total_requirements ?></strong><span>Documents submitted</span></div>
		</div>
		<div class="progress-track" role="progressbar" aria-label="Overall document completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $completion_percent ?>"><div class="progress-value" style="--progress-color:<?= subjectProgressColor($completion_percent) ?>;width:<?= $completion_percent ?>%"></div></div>
		<div class="overall-percent" style="--progress-color:<?= subjectProgressColor($completion_percent) ?>"><?= $completion_percent ?>%</div>
	</section>

	<div class="subject-section">
		<div class="subject-heading"><h2>Subjects and document requirements</h2><span>Each assigned folder is one required document</span></div>
		<div class="subject-list" id="subjectList">
		<?php foreach ($subjects as $subject_id => $subject):
			$requirement_count = count($subject['requirements']);
			$subject_done = count(array_filter($subject['requirements'], static fn($row) => (int) $row['file_count'] > 0));
			$subject_percent = $requirement_count ? (int) round($subject_done / $requirement_count * 100) : 0;
			$requirements_id = 'subject-requirements-' . (int) $subject_id;
		?>
		<section class="subject-panel" aria-label="<?= sanitize($subject['subject_code']) ?> requirements" style="--subject-progress-color:<?= subjectProgressColor($subject_percent) ?>">
			<div class="subject-panel-header">
				<div class="subject-panel-title"><strong class="subject-code"><?= sanitize($subject['subject_code']) ?></strong><p class="subject-description"><?= sanitize($subject['subject_description']) ?></p></div>
				<div class="subject-panel-controls" style="display:flex;align-items:center;gap:12px;">
					<div class="subject-progress"><?= $subject_done ?> of <?= $requirement_count ?> documents submitted</div>
					<?php if ($requirement_count): ?><button type="button" class="subject-toggle" aria-expanded="false" aria-controls="<?= $requirements_id ?>" onclick="toggleSubjectRequirements(this)"><span>See all</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button><?php endif; ?>
				</div>
			</div>
			<div class="subject-requirements" id="<?= $requirements_id ?>" <?= $requirement_count ? 'hidden' : '' ?>>
			<?php if (!$subject['requirements']): ?>
			<div class="empty">No folders have been assigned for this subject in the current school year yet.</div>
			<?php endif; ?>
			<?php foreach ($subject['requirements'] as $requirement):
				$is_submitted = (int) $requirement['file_count'] > 0;
				$is_closed = !empty($requirement['deadline']) && strtotime($requirement['deadline']) < time();
			?>
			<div class="requirement-row">
				<div class="requirement-name"><?= sanitize($requirement['folder_name']) ?>
					<?php if (!empty($requirement['group_name'])): ?><span class="requirement-group"><?= sanitize($requirement['group_name']) ?></span><?php endif; ?>
				</div>
				<span class="status <?= $is_submitted ? 'done' : 'pending' ?>"><?= $is_submitted ? 'Submitted' : ($is_closed ? 'Closed' : 'Pending') ?></span>
				<a class="open-folder" href="folder_submit.php?id=<?= (int) $requirement['folder_id'] ?>" aria-label="<?= $is_submitted ? 'View files' : 'Submit files' ?> for <?= sanitize($requirement['folder_name']) ?>" title="<?= $is_submitted ? 'View files' : 'Submit files' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php if ($is_submitted): ?><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><?php else: ?><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M5 20h14"/><?php endif; ?></svg></a>
			</div>
			<?php endforeach; ?>
			</div>
		</section>
		<?php endforeach; ?>
		</div>
	</div>
	<?php else: ?>
	<div class="empty">No subjects have been assigned to your folders for this school year yet. Contact your chairperson to be assigned subjects and document folders.</div>
	<?php endif; ?>
</main>
<script>
function toggleSubjectRequirements(button) {
	const requirements = document.getElementById(button.getAttribute('aria-controls'));
	if (!requirements) return;
	const expanded = button.getAttribute('aria-expanded') === 'true';
	requirements.hidden = expanded;
	button.setAttribute('aria-expanded', String(!expanded));
	button.querySelector('span').textContent = expanded ? 'See all' : 'Show less';
}
</script>
</body>
</html>
