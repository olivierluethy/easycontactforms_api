<?php
// GET /projects/get?id=<public_id> — one project owned by the authenticated user.
//
// The dashboard used to fetch the whole project list and pick the right one on
// the client. This endpoint replaces that: the server decides what the caller
// is allowed to see.

require_once __DIR__ . '/../lib/projects.php';

require_method('GET');

$user = current_user();

// require_project() enforces ownership and 404s on anything else; the second
// lookup only adds the aggregate counts.
$project = require_project($user, $_GET['id'] ?? null);
$row     = project_with_counts(db(), (int)$user['id'], $project['public_id']);

if (!$row) {
    not_found();
}

json_ok(project_payload($row));
