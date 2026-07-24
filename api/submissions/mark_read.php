<?php
// POST /submissions/mark-read — mark one or more submissions read (or unread).
// Body: { id } or { ids: [...] }, plus an optional { is_read: false }.
//
// Every identifier is resolved through require_submission() individually, so a
// list containing somebody else's submission fails outright rather than
// quietly skipping it — silently ignoring it would let a caller probe which
// identifiers exist.

require_once __DIR__ . '/../lib/submissions.php';

require_method('POST');

$user = current_user();
$body = json_body();

$ids = [];
if (isset($body['ids']) && is_array($body['ids'])) {
    $ids = $body['ids'];
} elseif (isset($body['id'])) {
    $ids = [$body['id']];
}

if ($ids === []) {
    json_error('A submission id is required.');
}
if (count($ids) > 500) {
    json_error('You can mark at most 500 submissions at a time.');
}

$read = !array_key_exists('is_read', $body) || (bool)$body['is_read'];

$internalIds = array_map(
    static fn ($publicId) => require_submission($user, $publicId)['id'],
    $ids
);

$placeholders = implode(',', array_fill(0, count($internalIds), '?'));
$sql = $read
    ? "UPDATE submissions SET is_read = 1, read_at = NOW() WHERE id IN ({$placeholders})"
    : "UPDATE submissions SET is_read = 0, read_at = NULL WHERE id IN ({$placeholders})";

$stmt = db()->prepare($sql);
$stmt->execute($internalIds);

json_ok([
    'updated' => count($internalIds),
    'is_read' => $read,
]);
