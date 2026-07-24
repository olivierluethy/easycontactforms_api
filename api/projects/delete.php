<?php
// POST /projects/delete — delete a project the authenticated user owns.
// Body: { id }
//
// Forms, submissions and submission values all cascade away via foreign keys.

require_method('POST');

$user    = current_user();
$body    = json_body();
$project = require_project($user, $body['id'] ?? null);

// Ownership was already proven above; user_id stays in the WHERE clause as a
// second line of defence in case this query is ever copied elsewhere.
db()->prepare('DELETE FROM projects WHERE id = ? AND user_id = ?')
    ->execute([$project['id'], $user['id']]);

json_ok(['deleted' => $project['public_id']]);
