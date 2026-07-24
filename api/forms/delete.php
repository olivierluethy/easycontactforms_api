<?php
// POST /forms/delete — delete a form and everything submitted through it.
// Body: { id }
//
// A project must always keep at least one form: its project_token has to point
// somewhere, or the embeds already deployed for that project would break.

require_once __DIR__ . '/../lib/forms.php';

require_method('POST');

$user = current_user();
$body = json_body();
$form = require_form($user, $body['id'] ?? null);
$pdo  = db();

$count = $pdo->prepare('SELECT COUNT(*) FROM forms WHERE project_id = ?');
$count->execute([$form['project_id']]);
if ((int)$count->fetchColumn() <= 1) {
    json_error('This is the project\'s only form. Add another form before deleting this one.', 409);
}

$pdo->beginTransaction();
try {
    // Submissions and their values cascade away via the foreign keys.
    $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$form['id']]);

    // If the default form was the one deleted, promote the oldest survivor so a
    // legacy project_token submission still has somewhere to land.
    if ($form['is_default'] === 1) {
        $pdo->prepare(
            'UPDATE forms SET is_default = 1
              WHERE project_id = ?
              ORDER BY created_at ASC, id ASC
              LIMIT 1'
        )->execute([$form['project_id']]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Could not delete the form. Please try again.', 500);
}

json_ok(['deleted' => $form['public_id']]);
