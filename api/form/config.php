<?php
// GET /form/config?project_token=… — PUBLIC bootstrap endpoint the widget can call
// to confirm a project token is valid before rendering the form.

require_method('GET');

$token = trim((string)($_GET['project_token'] ?? ''));
if ($token === '') {
    json_error('project_token query parameter is required.');
}

$stmt = db()->prepare('SELECT project_name FROM projects WHERE project_token = ? LIMIT 1');
$stmt->execute([$token]);
$row = $stmt->fetch();

if (!$row) {
    json_error('Unknown project token.', 404);
}

json_ok([
    'project_name' => $row['project_name'],
    'fields'       => ['full_name', 'email', 'message'],
]);
