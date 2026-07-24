<?php
// /projects
//   GET  — list the authenticated user's projects with their counts.
//   POST — create a project (plus its default form) and return it.
//
// The listing is always scoped by user_id in the query itself; there is no code
// path that fetches projects and filters them afterwards.

require_once __DIR__ . '/../lib/projects.php';
require_once __DIR__ . '/../lib/forms.php';

$user   = current_user();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$pdo    = db();

if ($method === 'GET') {
    $stmt = $pdo->prepare(
        project_select_sql() . ' WHERE p.user_id = ? ORDER BY last_submission_at DESC, p.created_at DESC'
    );
    $stmt->execute([$user['id']]);

    json_ok(array_map('project_payload', $stmt->fetchAll()));
}

if ($method === 'POST') {
    $body = json_body();

    $name       = validate_project_name($body['project_name'] ?? '');
    $websiteUrl = validate_website_url($body['website_url'] ?? '');
    $logoUrl    = validate_logo_url($body['logo_url'] ?? '');
    $replyFrom  = validate_reply_from_email($body['reply_from_email'] ?? '');

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO projects (public_id, user_id, project_name, project_token, website_url, logo_url, reply_from_email)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([uuid4(), $user['id'], $name, gen_token(12), $websiteUrl, $logoUrl, $replyFrom]);
        $projectId = (int)$pdo->lastInsertId();

        // Every project starts with one form, so the embed snippet works the
        // moment the project exists.
        create_form($pdo, $projectId, 'Contact form', default_field_definitions(), true);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('Could not create the project. Please try again.', 500);
    }

    $stmt = $pdo->prepare(project_select_sql() . ' WHERE p.id = ?');
    $stmt->execute([$projectId]);

    json_ok(project_payload($stmt->fetch()), 201);
}

json_error('Method not allowed.', 405);
