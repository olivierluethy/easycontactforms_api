<?php
// Proves the harness itself works: server up, routing resolves, test database
// reachable and rebuilt between cases.

declare(strict_types=1);

test('smoke: unknown route returns 404', function () {
    $res = http_get('/no/such/route');
    assert_status(404, $res, 'unknown route');
});

test('smoke: register then /auth/me round-trips', function () {
    $token = register_user('smoke@example.test');

    $me = http_get('/auth/me', $token);
    assert_status(200, $me, '/auth/me');
    assert_same('smoke@example.test', $me['data']['email'], 'me returns the right email');
});

test('smoke: /auth/me without a token is 401', function () {
    $res = http_get('/auth/me');
    assert_status(401, $res, 'unauthenticated /auth/me');
});

test('smoke: database is reset between cases', function () {
    // The case above registered a user; the reset must have wiped it.
    $count = (int)harness_db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    assert_same(0, $count, 'users table should be empty at the start of each case');
});
