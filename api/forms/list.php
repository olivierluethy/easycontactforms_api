<?php
// /forms
//   GET  ?project_id=<public_id> — every form in the project, with its fields.
//   POST — create a form in a project. Body: { project_id, form_name, fields[] }
//
// A project can hold as many forms as the customer needs; each one gets its own
// token and therefore its own embed snippet.

require_once __DIR__ . '/../lib/forms.php';

$user   = current_user();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$pdo    = db();

if ($method === 'GET') {
    $project = require_project($user, $_GET['project_id'] ?? null);

    $stmt = $pdo->prepare(
        'SELECT f.*,
                (SELECT COUNT(*) FROM submissions s WHERE s.form_id = f.id)                   AS submission_count,
                (SELECT COUNT(*) FROM submissions s WHERE s.form_id = f.id AND s.is_read = 0) AS unread_count
           FROM forms f
          WHERE f.project_id = ?
          ORDER BY f.is_default DESC, f.created_at ASC, f.id ASC'
    );
    $stmt->execute([$project['id']]);

    $forms = array_map(
        static fn (array $form) => form_payload(
            $form,
            fetch_form_fields(db(), (int)$form['id']),
            (int)$form['submission_count'],
            (int)$form['unread_count']
        ),
        $stmt->fetchAll()
    );

    json_ok($forms);
}

if ($method === 'POST') {
    $body    = json_body();
    $project = require_project($user, $body['project_id'] ?? null);

    $name = trim((string)($body['form_name'] ?? ''));
    if ($name === '') {
        json_error('Form name is required.');
    }
    if (mb_strlen($name) > 150) {
        json_error('Form name must be 150 characters or fewer.');
    }

    // A brand-new form starts from the classic three fields unless the builder
    // sent its own set, so "Add form" produces something usable immediately.
    $fields = array_key_exists('fields', $body)
        ? validate_field_definitions($body['fields'])
        : default_field_definitions();

    $count = $pdo->prepare('SELECT COUNT(*) FROM forms WHERE project_id = ?');
    $count->execute([$project['id']]);
    if ((int)$count->fetchColumn() >= 50) {
        json_error('A project can have at most 50 forms.');
    }

    $pdo->beginTransaction();
    try {
        $formId = create_form($pdo, $project['id'], $name, $fields, false);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('Could not create the form. Please try again.', 500);
    }

    $stmt = $pdo->prepare('SELECT * FROM forms WHERE id = ?');
    $stmt->execute([$formId]);

    json_ok(form_payload($stmt->fetch(), fetch_form_fields($pdo, $formId), 0, 0), 201);
}

json_error('Method not allowed.', 405);
