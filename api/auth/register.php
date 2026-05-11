<?php
// POST /auth/register — create a dashboard account.
// Body: { email, password }. Returns { id, email, token }.

require_method('POST');

$body     = json_body();
$email    = trim((string)($body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Please enter a valid email address.');
}
if (strlen($password) < 6) {
    json_error('Password must be at least 6 characters.');
}

$pdo = db();

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    json_error('An account with that email already exists.', 409);
}

$hash  = password_hash($password, PASSWORD_BCRYPT);
$token = gen_token(24);

$insert = $pdo->prepare('INSERT INTO users (email, password_hash, api_token) VALUES (?, ?, ?)');
$insert->execute([$email, $hash, $token]);

json_ok([
    'id'    => (int)$pdo->lastInsertId(),
    'email' => $email,
    'token' => $token,
], 201);
