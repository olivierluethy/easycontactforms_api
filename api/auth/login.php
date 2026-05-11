<?php
// POST /auth/login — verify credentials and return the user's existing bearer token.
// Body: { email, password }. Returns { id, email, token }.

require_method('POST');

$body     = json_body();
$email    = trim((string)($body['email'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($email === '' || $password === '') {
    json_error('Email and password are required.');
}

$stmt = db()->prepare('SELECT id, email, password_hash, api_token FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    json_error('Invalid email or password.', 401);
}

json_ok([
    'id'    => (int)$user['id'],
    'email' => $user['email'],
    'token' => $user['api_token'],
]);
