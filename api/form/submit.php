<?php
// POST /form/submit — PUBLIC endpoint hit by the embeddable widget from any
// third-party landing page.
//
// Three payload shapes are accepted, and all three must keep working:
//
//   1. { form_token, fields: { … } }      one specific form
//   2. { project_token, fields: { … } }   the project's default form
//   3. { project_token, full_name, email, message }
//                                         the shape every widget deployed
//                                         before custom forms existed sends.
//                                         Values sit at the top level.
//
// Shape 3 is not a deprecation path with an end date: snippets are pasted into
// customer sites and stay there for years, so it is supported indefinitely.

require_once __DIR__ . '/../lib/forms.php';

require_method('POST');

$body = json_body();
$pdo  = db();

$formToken    = trim((string)($body['form_token'] ?? ''));
$projectToken = trim((string)($body['project_token'] ?? ''));

if ($formToken === '' && $projectToken === '') {
    json_error('Missing project token.');
}

// ── Resolve the target form ────────────────────────────────────────────────
if ($formToken !== '') {
    $stmt = $pdo->prepare('SELECT * FROM forms WHERE form_token = ? LIMIT 1');
    $stmt->execute([$formToken]);
    $form = $stmt->fetch();
    if (!$form) {
        json_error('Unknown form. Please check the formId in your embed snippet.', 404);
    }
    $projectId = (int)$form['project_id'];
} else {
    $stmt = $pdo->prepare('SELECT id FROM projects WHERE project_token = ? LIMIT 1');
    $stmt->execute([$projectToken]);
    $project = $stmt->fetch();
    if (!$project) {
        json_error('Unknown project. Please check the projectId in your embed snippet.', 404);
    }
    $projectId = (int)$project['id'];

    $form = default_form_for_project($pdo, $projectId);
    if (!$form) {
        json_error('This project has no form yet.', 404);
    }
}

$definitions = fetch_form_fields($pdo, (int)$form['id']);
if ($definitions === []) {
    json_error('This form has no fields yet.', 409);
}

// ── Locate the submitted values ────────────────────────────────────────────
// Newer widgets nest them under `fields`; the original ones put them at the top
// level alongside the token.
$usesNestedFields = isset($body['fields']) && is_array($body['fields']);

if ($usesNestedFields) {
    $submitted = $body['fields'];
} else {
    $submitted = $body;
    unset($submitted['project_token'], $submitted['form_token'], $submitted['fields']);
}

// ── Honeypot ───────────────────────────────────────────────────────────────
// A real visitor never fills the hidden `website` input. `website` is also a
// perfectly reasonable name for a real field, though, so it only counts as a
// honeypot when this form has no field of that name — and when the values are
// nested, the top-level key cannot be a field value at all.
$formHasWebsiteField = in_array('website', array_column($definitions, 'key'), true);
$honeypot = '';
if ($usesNestedFields || !$formHasWebsiteField) {
    $honeypot = trim((string)($body['website'] ?? ''));
}
if (!$usesNestedFields) {
    unset($submitted['website']);
    if ($formHasWebsiteField) {
        // Not a honeypot here — put the real value back.
        $submitted['website'] = $body['website'] ?? '';
    }
}

if ($honeypot !== '') {
    // Answer as though it worked, so a bot learns nothing.
    json_ok(['received' => true]);
}

// ── Validate and store ─────────────────────────────────────────────────────
$values = validate_submitted_values($definitions, $submitted);
if ($values === []) {
    json_error('Please fill in the form before sending.');
}

$ip = client_ip();

$pdo->beginTransaction();
try {
    $insert = $pdo->prepare(
        'INSERT INTO submissions (public_id, project_id, form_id, ip_address) VALUES (?, ?, ?, ?)'
    );
    $insert->execute([uuid4(), $projectId, (int)$form['id'], $ip]);
    $submissionId = (int)$pdo->lastInsertId();

    $insertValue = $pdo->prepare(
        'INSERT INTO submission_values (submission_id, field_key, field_label, field_type, value, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($values as $value) {
        $insertValue->execute([
            $submissionId,
            $value['key'],
            $value['label'],
            $value['type'],
            $value['value'],
            $value['order'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error('Could not save your message. Please try again.', 500);
}

// ─────────────────────────────────────────────────────────────────────────
// FUTURE (Phase 3 — automated reply service): this is the hook point.
//
// A new submission is the trigger for the planned auto-responder. It would
// read the project's reply_from_email (already stored on `projects`), evaluate
// the customer's reply rules, and queue an outbound message — ideally by
// writing to a queue table here and letting a separate worker send it, so a
// slow or failing mail server never delays the visitor's response.
//
// Nothing about the current schema needs to change to add it.
// ─────────────────────────────────────────────────────────────────────────

json_ok(['received' => true], 201);
