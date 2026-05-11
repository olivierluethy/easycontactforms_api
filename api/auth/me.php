<?php
// GET /auth/me — return the authenticated user's identity for the dashboard
// to validate a cached token on startup.

require_method('GET');
$user = current_user();

json_ok([
    'id'    => (int)$user['id'],
    'email' => $user['email'],
]);
