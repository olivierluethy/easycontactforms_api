<?php
// Project endpoints: identifiers, ownership, counts and updates.

declare(strict_types=1);

/** Two accounts, each with one project. Returns tokens and project payloads. */
function two_owners_with_projects(): array
{
    $alice = register_user('alice@example.test');
    $bob   = register_user('bob@example.test');

    $aliceProject = http_post('/projects', ['project_name' => 'Alice Landing'], $alice);
    assert_status(201, $aliceProject, 'create alice project');

    $bobProject = http_post('/projects', ['project_name' => 'Bob Landing'], $bob);
    assert_status(201, $bobProject, 'create bob project');

    return [
        'alice' => $alice,
        'bob'   => $bob,
        'aliceProject' => $aliceProject['data'],
        'bobProject'   => $bobProject['data'],
    ];
}

test('projects: creating a project returns a UUID and never an integer id', function () {
    $token = register_user('creator@example.test');

    $res = http_post('/projects', ['project_name' => 'Acme Landing'], $token);
    assert_status(201, $res, 'create project');

    $project = $res['data'];
    assert_matches(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        (string)$project['id'],
        'the project id exposed to clients must be a v4 UUID'
    );
    assert_matches('/^[0-9a-f]{24}$/', (string)$project['project_token'], 'project token shape');

    // The internal primary key must not appear anywhere in the response.
    assert_true(
        !preg_match('/"(project_id|user_id)"/', $res['raw']),
        'response must not expose internal keys: ' . $res['raw']
    );
});

test('projects: a new project comes with a default form', function () {
    $token = register_user('creator2@example.test');
    $project = http_post('/projects', ['project_name' => 'Acme'], $token)['data'];

    assert_same(1, $project['forms_count'], 'a new project should have exactly one form');
    assert_same(0, $project['submission_count']);
    assert_same(0, $project['unread_count']);
    assert_same(null, $project['last_submission_at']);
});

test('projects: the list only ever contains your own projects', function () {
    $ctx = two_owners_with_projects();

    $list = http_get('/projects', $ctx['alice']);
    assert_status(200, $list, 'list projects');
    assert_count_is(1, $list['data'], 'alice should see exactly her own project');
    assert_same('Alice Landing', $list['data'][0]['project_name']);
});

test('projects: reading another owner\'s project returns 404', function () {
    $ctx = two_owners_with_projects();
    $victimId = $ctx['bobProject']['id'];

    assert_not_found(http_get('/projects/get?id=' . $victimId, $ctx['alice']), 'get another owner\'s project');
});

test('projects: renaming another owner\'s project returns 404 and changes nothing', function () {
    $ctx = two_owners_with_projects();
    $victimId = $ctx['bobProject']['id'];

    $attack = http_post('/projects/update', ['id' => $victimId, 'project_name' => 'Pwned'], $ctx['alice']);
    assert_not_found($attack, 'rename another owner\'s project');

    // Bob's project must be untouched.
    $bobView = http_get('/projects/get?id=' . $victimId, $ctx['bob']);
    assert_status(200, $bobView, 'bob reads his own project');
    assert_same('Bob Landing', $bobView['data']['project_name'], 'the name must be unchanged');
});

test('projects: deleting another owner\'s project returns 404 and changes nothing', function () {
    $ctx = two_owners_with_projects();
    $victimId = $ctx['bobProject']['id'];

    assert_not_found(http_post('/projects/delete', ['id' => $victimId], $ctx['alice']), 'delete another owner\'s project');

    $stillThere = http_get('/projects/get?id=' . $victimId, $ctx['bob']);
    assert_status(200, $stillThere, 'bob\'s project should still exist');
});

test('projects: marking another owner\'s project read returns 404', function () {
    $ctx = two_owners_with_projects();

    assert_not_found(
        http_post('/projects/mark-read', ['id' => $ctx['bobProject']['id']], $ctx['alice']),
        'mark another owner\'s project read'
    );
});

test('projects: guessable identifiers are rejected everywhere', function () {
    $ctx = two_owners_with_projects();

    // Sequential integers are exactly what an attacker tries first. Every shape
    // of bad identifier has to come back as the same 404.
    foreach (['1', '2', '0', '-1', '99999', '', 'null', 'undefined', 'abc'] as $guess) {
        assert_not_found(http_get('/projects/get?id=' . urlencode($guess), $ctx['alice']), "get with id '{$guess}'");
        assert_not_found(http_post('/projects/delete', ['id' => $guess], $ctx['alice']), "delete with id '{$guess}'");
        assert_not_found(
            http_post('/projects/update', ['id' => $guess, 'project_name' => 'x'], $ctx['alice']),
            "update with id '{$guess}'"
        );
    }

    // And a well-formed UUID that simply doesn't exist behaves identically to
    // one that belongs to somebody else — no existence leak.
    $missing = http_get('/projects/get?id=11111111-1111-4111-8111-111111111111', $ctx['alice']);
    $foreign = http_get('/projects/get?id=' . $ctx['bobProject']['id'], $ctx['alice']);
    assert_same($missing['status'], $foreign['status'], 'unknown and non-owned must return the same status');
    assert_same($missing['error'], $foreign['error'], 'unknown and non-owned must return the same message');
});

test('projects: every endpoint requires authentication', function () {
    $ctx = two_owners_with_projects();
    $id = $ctx['aliceProject']['id'];

    assert_status(401, http_get('/projects'), 'list without a token');
    assert_status(401, http_get('/projects/get?id=' . $id), 'get without a token');
    assert_status(401, http_post('/projects/update', ['id' => $id, 'project_name' => 'x']), 'update without a token');
    assert_status(401, http_post('/projects/delete', ['id' => $id]), 'delete without a token');
    assert_status(401, http_post('/projects/mark-read', ['id' => $id]), 'mark-read without a token');
});

test('projects: update changes only the keys it is given', function () {
    $token = register_user('settings@example.test');
    $project = http_post('/projects', ['project_name' => 'Before'], $token)['data'];

    $renamed = http_post('/projects/update', ['id' => $project['id'], 'project_name' => 'After'], $token);
    assert_status(200, $renamed, 'rename');
    assert_same('After', $renamed['data']['project_name']);
    assert_same(null, $renamed['data']['website_url'], 'branding must be left alone');

    $branded = http_post('/projects/update', [
        'id'               => $project['id'],
        'website_url'      => 'acme.com',
        'reply_from_email' => 'hello@acme.com',
    ], $token);
    assert_status(200, $branded, 'set branding');
    assert_same('After', $branded['data']['project_name'], 'the name must survive a branding update');
    assert_same('https://acme.com', $branded['data']['website_url'], 'a bare domain should be normalized to https');
    assert_same('hello@acme.com', $branded['data']['reply_from_email']);
});

test('projects: update validates its input', function () {
    $token = register_user('validate@example.test');
    $project = http_post('/projects', ['project_name' => 'Valid'], $token)['data'];

    assert_status(400, http_post('/projects/update', ['id' => $project['id'], 'project_name' => '  '], $token), 'blank name');
    assert_status(400, http_post('/projects/update', ['id' => $project['id'], 'project_name' => str_repeat('x', 151)], $token), 'long name');
    assert_status(400, http_post('/projects/update', ['id' => $project['id'], 'reply_from_email' => 'not-an-email'], $token), 'bad email');
    assert_status(400, http_post('/projects/update', ['id' => $project['id']], $token), 'nothing to update');

    // Clearing an optional field is allowed and stores NULL.
    $cleared = http_post('/projects/update', ['id' => $project['id'], 'reply_from_email' => ''], $token);
    assert_status(200, $cleared, 'clearing reply-from');
    assert_same(null, $cleared['data']['reply_from_email']);
});

test('projects: the legacy /projects/rename route still works', function () {
    $token = register_user('legacy@example.test');
    $project = http_post('/projects', ['project_name' => 'Old Name'], $token)['data'];

    $res = http_post('/projects/rename', ['id' => $project['id'], 'project_name' => 'New Name'], $token);
    assert_status(200, $res, 'legacy rename route');
    assert_same('New Name', $res['data']['project_name']);
});

test('projects: deleting a project removes it from the list', function () {
    $token = register_user('deleter@example.test');
    $keep   = http_post('/projects', ['project_name' => 'Keep'], $token)['data'];
    $remove = http_post('/projects', ['project_name' => 'Remove'], $token)['data'];

    $res = http_post('/projects/delete', ['id' => $remove['id']], $token);
    assert_status(200, $res, 'delete own project');
    assert_same($remove['id'], $res['data']['deleted'], 'response echoes the public id');

    $list = http_get('/projects', $token);
    assert_count_is(1, $list['data']);
    assert_same($keep['id'], $list['data'][0]['id']);
});
