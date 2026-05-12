<?php
// POST /projects/rename — update the project_name on a project owned by the
// authenticated user. Body: { id, project_name }.

require_method('POST');

$user = current_user();
$body = json_body();

$id   = (int)($body['id'] ?? 0);
$name = trim((string)($body['project_name'] ?? ''));

if ($id <= 0) {
    json_error('A valid project id is required.');
}
if ($name === '') {
    json_error('Project name is required.');
}
if (mb_strlen($name) > 150) {
    json_error('Project name must be 150 characters or fewer.');
}

$stmt = db()->prepare('UPDATE projects SET project_name = ? WHERE id = ? AND user_id = ?');
$stmt->execute([$name, $id, $user['id']]);

if ($stmt->rowCount() === 0) {
    // Either the project doesn't exist for this user, or the name was already
    // identical. Disambiguate so the dashboard can give a useful message.
    $check = db()->prepare('SELECT id FROM projects WHERE id = ? AND user_id = ? LIMIT 1');
    $check->execute([$id, $user['id']]);
    if (!$check->fetch()) {
        json_error('Project not found.', 404);
    }
}

json_ok([
    'id'           => $id,
    'project_name' => $name,
]);
