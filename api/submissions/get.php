<?php
// GET /submissions/get?id=<public_id>[&mark_read=0]
//
// Returns one submission in full. Opening a submission is what marks it read,
// so that happens here by default; pass mark_read=0 to peek without clearing
// the unread badge.

require_once __DIR__ . '/../lib/submissions.php';

require_method('GET');

$user       = current_user();
$pdo        = db();
$submission = require_submission($user, $_GET['id'] ?? null);

$markRead = !isset($_GET['mark_read']) || ($_GET['mark_read'] !== '0' && $_GET['mark_read'] !== 'false');

if ($markRead && $submission['is_read'] === 0) {
    $pdo->prepare('UPDATE submissions SET is_read = 1, read_at = NOW() WHERE id = ?')
        ->execute([$submission['id']]);
}

$stmt = $pdo->prepare(submission_select_sql() . ' WHERE s.id = ?');
$stmt->execute([$submission['id']]);
$row = $stmt->fetch();

$values = fetch_submission_values($pdo, [(int)$row['id']]);

json_ok(submission_payload($row, $values[(int)$row['id']] ?? []));
