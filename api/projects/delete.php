<?php
// POST /projects/delete — delete a project owned by the authenticated user.
// Cascades to submissions via the FK constraint.

require_method('POST');

$user = current_user();
$body = json_body();
$id   = (int)($body['id'] ?? 0);

if ($id <= 0) {
    json_error('A valid project id is required.');
}

$stmt = db()->prepare('DELETE FROM projects WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $user['id']]);

if ($stmt->rowCount() === 0) {
    json_error('Project not found.', 404);
}

json_ok(['deleted' => $id]);
