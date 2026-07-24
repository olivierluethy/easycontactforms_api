<?php
// POST /forms/update — rename a form and/or replace its field set.
// Body: { id, form_name?, fields? }
//
// Fields are matched on their key, so renaming a label or reordering fields
// keeps each field's identity intact and stays consistent with the keys already
// recorded against past submissions.

require_once __DIR__ . '/../lib/forms.php';

require_method('POST');

$user = current_user();
$body = json_body();
$form = require_form($user, $body['id'] ?? null);
$pdo  = db();

$didSomething = false;

if (array_key_exists('form_name', $body)) {
    $name = trim((string)$body['form_name']);
    if ($name === '') {
        json_error('Form name is required.');
    }
    if (mb_strlen($name) > 150) {
        json_error('Form name must be 150 characters or fewer.');
    }
    $pdo->prepare('UPDATE forms SET form_name = ? WHERE id = ?')->execute([$name, $form['id']]);
    $didSomething = true;
}

if (array_key_exists('fields', $body)) {
    $fields = validate_field_definitions($body['fields']);

    $pdo->beginTransaction();
    try {
        replace_form_fields($pdo, $form['id'], $fields);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('Could not save the fields. Please try again.', 500);
    }
    $didSomething = true;
}

if (!$didSomething) {
    json_error('Nothing to update.');
}

$stmt = $pdo->prepare(
    'SELECT f.*,
            (SELECT COUNT(*) FROM submissions s WHERE s.form_id = f.id)                   AS submission_count,
            (SELECT COUNT(*) FROM submissions s WHERE s.form_id = f.id AND s.is_read = 0) AS unread_count
       FROM forms f WHERE f.id = ?'
);
$stmt->execute([$form['id']]);
$updated = $stmt->fetch();

json_ok(form_payload(
    $updated,
    fetch_form_fields($pdo, $form['id']),
    (int)$updated['submission_count'],
    (int)$updated['unread_count']
));
