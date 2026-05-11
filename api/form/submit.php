<?php
// POST /form/submit — PUBLIC endpoint hit by the embeddable widget from any
// third-party landing page. Validates the project_token, validates the payload,
// honors the honeypot, and stores the submission.

require_method('POST');

$body = json_body();

$projectToken = trim((string)($body['project_token'] ?? ''));
$fullName     = trim((string)($body['full_name']     ?? ''));
$email        = trim((string)($body['email']         ?? ''));
$message      = trim((string)($body['message']       ?? ''));
$honeypot     = (string)($body['website']            ?? '');

// Silent bot rejection — a real browser user never fills this hidden field.
if ($honeypot !== '') {
    json_ok(['received' => true]);
}

if ($projectToken === '') {
    json_error('Missing project token.');
}

$proj = db()->prepare('SELECT id FROM projects WHERE project_token = ? LIMIT 1');
$proj->execute([$projectToken]);
$row = $proj->fetch();
if (!$row) {
    json_error('Unknown project. Please check the projectId in your embed snippet.', 404);
}
$projectId = (int)$row['id'];

if ($fullName === '') {
    json_error('Please enter your full name.');
}
if (mb_strlen($fullName) > 150) {
    json_error('Full name must be 150 characters or fewer.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Please enter a valid email address.');
}
if (mb_strlen($email) > 190) {
    json_error('Email must be 190 characters or fewer.');
}
if ($message === '') {
    json_error('Please enter a message.');
}
if (mb_strlen($message) > 5000) {
    json_error('Message must be 5000 characters or fewer.');
}

$ip = client_ip();

$insert = db()->prepare(
    'INSERT INTO submissions (project_id, full_name, email, message, ip_address)
     VALUES (?, ?, ?, ?, ?)'
);
$insert->execute([$projectId, $fullName, $email, $message, $ip]);

json_ok(['received' => true], 201);
