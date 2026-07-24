<?php
// GET /form/config?form_token=…   — the definition of one specific form
// GET /form/config?project_token=… — the definition of a project's default form
//
// PUBLIC: called by widgets running on arbitrary third-party sites. It is what
// lets the widget render whatever fields the customer configured instead of a
// hardcoded three.
//
// The response deliberately contains no public_id. A form token is meant to be
// visible in page source; the UUIDs used for dashboard addressing are not, and
// there is no reason to hand them out here.
//
// Response shape:
//   {
//     "project_name": "Acme",
//     "form_name":    "Contact form",
//     "fields":       ["full_name", "email", "message"],   // legacy: keys only
//     "form": { "form_name": "…", "fields": [ {key,label,type,required}, … ] }
//   }
//
// The flat `fields` array of keys is the shape the original endpoint returned.
// It is kept, unchanged, for anything already relying on it; the rich
// definitions live under `form`.

require_once __DIR__ . '/../lib/forms.php';

require_method('GET');

$formToken    = trim((string)($_GET['form_token'] ?? ''));
$projectToken = trim((string)($_GET['project_token'] ?? ''));

if ($formToken === '' && $projectToken === '') {
    json_error('A form_token or project_token query parameter is required.');
}

$pdo = db();

if ($formToken !== '') {
    $stmt = $pdo->prepare(
        'SELECT f.*, p.project_name
           FROM forms f
           JOIN projects p ON p.id = f.project_id
          WHERE f.form_token = ? LIMIT 1'
    );
    $stmt->execute([$formToken]);
    $form = $stmt->fetch();

    if (!$form) {
        json_error('Unknown form. Please check the formId in your embed snippet.', 404);
    }
} else {
    $stmt = $pdo->prepare('SELECT id, project_name FROM projects WHERE project_token = ? LIMIT 1');
    $stmt->execute([$projectToken]);
    $project = $stmt->fetch();

    if (!$project) {
        json_error('Unknown project token.', 404);
    }

    $form = default_form_for_project($pdo, (int)$project['id']);
    if (!$form) {
        json_error('This project has no form yet.', 404);
    }
    $form['project_name'] = $project['project_name'];
}

$fields = fetch_form_fields($pdo, (int)$form['id']);

json_ok([
    'project_name' => $form['project_name'],
    'form_name'    => $form['form_name'],
    'form_token'   => $form['form_token'],
    'fields'       => array_column($fields, 'key'),
    'form'         => [
        'form_name' => $form['form_name'],
        'fields'    => $fields,
    ],
]);
