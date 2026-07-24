<?php
// Identifier generation. These are pure functions, so they run in-process
// rather than over HTTP.
//
// Ownership enforcement (require_project / require_form / require_submission)
// is covered by the IDOR cases in projects.php, forms.php and submissions.php,
// which exercise it the way real requests do.

declare(strict_types=1);

require_once ECF_API_ROOT . '/config/ids.php';

test('ids: uuid4 produces canonical version-4 UUIDs', function () {
    for ($i = 0; $i < 50; $i++) {
        assert_matches(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            uuid4(),
            'uuid4 must set the version and variant nibbles'
        );
    }
});

test('ids: uuid4 does not repeat', function () {
    $seen = [];
    for ($i = 0; $i < 1000; $i++) {
        $seen[uuid4()] = true;
    }
    assert_same(1000, count($seen), 'uuid4 collided, which should be impossible');
});

test('ids: consecutive uuid4 values share no prefix', function () {
    // The failure mode this guards against is somebody swapping uuid4() for
    // MySQL's UUID(), which is version 1: consecutive values differ only in the
    // low bits of a timestamp and are trivially enumerable.
    $collisions = 0;
    for ($i = 0; $i < 200; $i++) {
        if (substr(uuid4(), 0, 8) === substr(uuid4(), 0, 8)) {
            $collisions++;
        }
    }
    assert_same(0, $collisions, 'consecutive UUIDs shared a prefix — not random');
});

test('ids: gen_token returns the requested number of random bytes as hex', function () {
    assert_matches('/^[0-9a-f]{48}$/', gen_token(24), 'default token should be 24 bytes');
    assert_matches('/^[0-9a-f]{24}$/', gen_token(12), 'project/form tokens should be 12 bytes');

    $seen = [];
    for ($i = 0; $i < 500; $i++) {
        $seen[gen_token(12)] = true;
    }
    assert_same(500, count($seen), 'gen_token collided');
});

test('ids: is_uuid accepts v4 UUIDs and rejects everything else', function () {
    assert_true(is_uuid(uuid4()), 'a generated uuid should validate');
    assert_true(is_uuid('484BEF5A-570C-4966-81B1-80F5959B1AFB'), 'uppercase should validate');

    // Sequential integers are the whole reason this check exists.
    assert_true(!is_uuid('1'), 'an integer id must not validate');
    assert_true(!is_uuid(1), 'an integer must not validate');
    assert_true(!is_uuid(''), 'empty string must not validate');
    assert_true(!is_uuid(null), 'null must not validate');
    assert_true(!is_uuid([]), 'an array must not validate');
    assert_true(!is_uuid('484bef5a570c496681b180f5959b1afb'), 'unhyphenated must not validate');
    // Version 1 UUID (note the "1" in the version position) — must be rejected,
    // so a v1 value can never sneak in as an identifier.
    assert_true(!is_uuid('484bef5a-570c-1966-81b1-80f5959b1afb'), 'a version-1 UUID must not validate');
    assert_true(!is_uuid('484bef5a-570c-4966-c1b1-80f5959b1afb'), 'a bad variant nibble must not validate');
});
