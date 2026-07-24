<?php
// POST /projects/update — update a project the authenticated user owns.
// Body: { id, project_name?, website_url?, logo_url?, reply_from_email? }
//
// Only the keys present in the body are touched, so the settings panel and the
// inline rename can both use this endpoint without clobbering each other.

require_once __DIR__ . '/../lib/projects.php';

require_method('POST');

$user    = current_user();
$body    = json_body();
$project = require_project($user, $body['id'] ?? null);

$updates = [];
$values  = [];

if (array_key_exists('project_name', $body)) {
    $updates[] = 'project_name = ?';
    $values[]  = validate_project_name($body['project_name']);
}
if (array_key_exists('website_url', $body)) {
    $updates[] = 'website_url = ?';
    $values[]  = validate_website_url($body['website_url']);
}
if (array_key_exists('logo_url', $body)) {
    $updates[] = 'logo_url = ?';
    $values[]  = validate_logo_url($body['logo_url']);
}
if (array_key_exists('reply_from_email', $body)) {
    $updates[] = 'reply_from_email = ?';
    $values[]  = validate_reply_from_email($body['reply_from_email']);
}

if ($updates === []) {
    json_error('Nothing to update.');
}

$values[] = $project['id'];
db()->prepare('UPDATE projects SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($values);

// Re-read rather than echoing the input, so the client always sees exactly what
// was stored (normalized URLs included).
json_ok(project_payload(project_with_counts(db(), (int)$user['id'], $project['public_id'])));
