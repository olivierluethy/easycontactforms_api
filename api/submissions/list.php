<?php
// GET /submissions?project_id=N — return up to 500 submissions for a project
// owned by the authenticated user, newest first.

require_method('GET');

$user      = current_user();
$projectId = (int)($_GET['project_id'] ?? 0);

if ($projectId <= 0) {
    json_error('project_id query parameter is required.');
}

$owner = db()->prepare('SELECT id FROM projects WHERE id = ? AND user_id = ? LIMIT 1');
$owner->execute([$projectId, $user['id']]);
if (!$owner->fetch()) {
    json_error('Project not found.', 404);
}

$stmt = db()->prepare(
    'SELECT id, project_id, full_name, email, message, ip_address, created_at
     FROM submissions
     WHERE project_id = ?
     ORDER BY created_at DESC, id DESC
     LIMIT 500'
);
$stmt->execute([$projectId]);
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['id']         = (int)$r['id'];
    $r['project_id'] = (int)$r['project_id'];
}

json_ok($rows);
