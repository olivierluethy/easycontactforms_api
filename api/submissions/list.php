<?php
// GET /submissions?project_id=<public_id>[&form_id=<public_id>][&unread=1][&limit=N]
//
// Newest first. Ownership is proven by require_project()/require_form() before
// any submission row is touched, so the query below can never reach across
// accounts.

require_once __DIR__ . '/../lib/submissions.php';

require_method('GET');

$user    = current_user();
$pdo     = db();
$project = require_project($user, $_GET['project_id'] ?? null);

$where  = ['s.project_id = ?'];
$params = [$project['id']];

if (!empty($_GET['form_id'])) {
    $form = require_form($user, $_GET['form_id']);
    // A form the user owns, but in a different project, must not widen the
    // result set — treat the mismatch exactly like an unknown identifier.
    if ($form['project_id'] !== $project['id']) {
        not_found();
    }
    $where[]  = 's.form_id = ?';
    $params[] = $form['id'];
}

if (isset($_GET['unread']) && $_GET['unread'] !== '0' && $_GET['unread'] !== '') {
    $where[] = 's.is_read = 0';
}

$limit = (int)($_GET['limit'] ?? 500);
$limit = max(1, min($limit, 1000));

$stmt = $pdo->prepare(
    submission_select_sql()
    . ' WHERE ' . implode(' AND ', $where)
    . ' ORDER BY s.created_at DESC, s.id DESC'
    . ' LIMIT ' . $limit
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$values = fetch_submission_values($pdo, array_map(static fn ($r) => (int)$r['id'], $rows));

json_ok(array_map(
    static fn (array $row) => submission_payload($row, $values[(int)$row['id']] ?? []),
    $rows
));
