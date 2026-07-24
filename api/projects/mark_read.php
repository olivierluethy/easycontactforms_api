<?php
// POST /projects/mark-read — mark every submission in a project as read.
// Body: { id, form_id? }
//
// With form_id, only that form's submissions are marked, which is what the
// "Mark all as read" button does while a single form is being viewed.

require_once __DIR__ . '/../lib/projects.php';

require_method('POST');

$user    = current_user();
$body    = json_body();
$project = require_project($user, $body['id'] ?? null);

$sql    = 'UPDATE submissions SET is_read = 1, read_at = NOW() WHERE project_id = ? AND is_read = 0';
$params = [$project['id']];

if (!empty($body['form_id'])) {
    $form = require_form($user, $body['form_id']);
    if ($form['project_id'] !== $project['id']) {
        not_found();
    }
    $sql     .= ' AND form_id = ?';
    $params[] = $form['id'];
}

$stmt = db()->prepare($sql);
$stmt->execute($params);

json_ok([
    'marked_read' => $stmt->rowCount(),
    'project'     => project_payload(project_with_counts(db(), (int)$user['id'], $project['public_id'])),
]);
