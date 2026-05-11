<?php
// /projects — GET lists the authenticated user's projects (with submission counts),
// POST creates a new project and returns it.

$user   = current_user();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $sql = 'SELECT p.id, p.project_name, p.project_token, p.created_at,
                   (SELECT COUNT(*) FROM submissions s WHERE s.project_id = p.id) AS submission_count
            FROM projects p
            WHERE p.user_id = ?
            ORDER BY p.created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id']               = (int)$r['id'];
        $r['submission_count'] = (int)$r['submission_count'];
    }
    json_ok($rows);
}

if ($method === 'POST') {
    $body = json_body();
    $name = trim((string)($body['project_name'] ?? ''));

    if ($name === '') {
        json_error('Project name is required.');
    }
    if (mb_strlen($name) > 150) {
        json_error('Project name must be 150 characters or fewer.');
    }

    $token = bin2hex(random_bytes(12));

    $stmt = db()->prepare('INSERT INTO projects (user_id, project_name, project_token) VALUES (?, ?, ?)');
    $stmt->execute([$user['id'], $name, $token]);

    json_ok([
        'id'               => (int)db()->lastInsertId(),
        'project_name'     => $name,
        'project_token'    => $token,
        'submission_count' => 0,
        'created_at'       => date('Y-m-d H:i:s'),
    ], 201);
}

json_error('Method not allowed.', 405);
