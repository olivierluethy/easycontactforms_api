<?php
// Submission endpoints: listing, filtering, read state and ownership.
//
// Submissions are seeded directly through the database here so these cases stay
// independent of the public submit endpoint, which has its own test file.

declare(strict_types=1);

/**
 * Insert a submission against a form, identified by its public id.
 *
 * @param array<string,string> $values field_key => value
 */
function seed_submission(string $formPublicId, array $values, string $createdAt = '2026-05-01 09:00:00', bool $read = false): string
{
    $pdo = harness_db();

    $form = $pdo->prepare('SELECT id, project_id FROM forms WHERE public_id = ?');
    $form->execute([$formPublicId]);
    $form = $form->fetch();
    if (!$form) {
        throw new RuntimeException("No form with public_id {$formPublicId}");
    }

    $fields = $pdo->prepare('SELECT field_key, label, field_type, sort_order FROM form_fields WHERE form_id = ? ORDER BY sort_order');
    $fields->execute([$form['id']]);
    $definitions = [];
    foreach ($fields->fetchAll() as $row) {
        $definitions[$row['field_key']] = $row;
    }

    $publicId = sprintf(
        '%s-%s-4%s-%s%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        substr(bin2hex(random_bytes(2)), 1),
        ['8', '9', 'a', 'b'][random_int(0, 3)],
        substr(bin2hex(random_bytes(2)), 1),
        bin2hex(random_bytes(6))
    );

    $insert = $pdo->prepare(
        'INSERT INTO submissions (public_id, project_id, form_id, is_read, read_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([$publicId, $form['project_id'], $form['id'], $read ? 1 : 0, $read ? $createdAt : null, $createdAt]);
    $submissionId = (int)$pdo->lastInsertId();

    $insertValue = $pdo->prepare(
        'INSERT INTO submission_values (submission_id, field_key, field_label, field_type, value, sort_order)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($values as $key => $value) {
        $definition = $definitions[$key] ?? ['label' => ucfirst($key), 'field_type' => 'text', 'sort_order' => 99];
        $insertValue->execute([
            $submissionId,
            $key,
            $definition['label'],
            $definition['field_type'],
            $value,
            $definition['sort_order'],
        ]);
    }

    return $publicId;
}

/** An owner with a project, its default form, and three seeded submissions. */
function owner_with_submissions(string $email = 'subs@example.test'): array
{
    $token   = register_user($email);
    $project = http_post('/projects', ['project_name' => 'Inbox Test'], $token)['data'];
    $form    = http_get('/forms?project_id=' . $project['id'], $token)['data'][0];

    $first = seed_submission($form['id'], [
        'full_name' => 'Ada Lovelace',
        'email'     => 'ada@example.test',
        'message'   => 'First message',
    ], '2026-05-01 09:15:00', true);

    $second = seed_submission($form['id'], [
        'full_name' => 'Grace Hopper',
        'email'     => 'grace@example.test',
        'message'   => 'Second message',
    ], '2026-05-02 14:30:00');

    $third = seed_submission($form['id'], [
        'full_name' => 'Alan Turing',
        'email'     => 'alan@example.test',
        'message'   => 'Third message',
    ], '2026-05-03 08:00:00');

    return compact('token', 'project', 'form', 'first', 'second', 'third');
}

test('submissions: listed newest first with their values', function () {
    $ctx = owner_with_submissions();

    $res = http_get('/submissions?project_id=' . $ctx['project']['id'], $ctx['token']);
    assert_status(200, $res, 'list submissions');
    assert_count_is(3, $res['data']);

    assert_same($ctx['third'], $res['data'][0]['id'], 'newest submission comes first');
    assert_same($ctx['first'], $res['data'][2]['id'], 'oldest submission comes last');

    $newest = $res['data'][0];
    assert_same(['full_name', 'email', 'message'], array_column($newest['values'], 'key'), 'values in field order');
    assert_same('Alan Turing', $newest['values'][0]['value']);
    assert_same('Full name', $newest['values'][0]['label'], 'the label snapshot travels with the value');
    assert_same('textarea', $newest['values'][2]['type'], 'the type snapshot travels with the value');
    assert_same($ctx['form']['id'], $newest['form_id'], 'a submission names the form it came from');
    assert_same('Contact form', $newest['form_name']);
});

test('submissions: reply_to is derived from the email field', function () {
    $ctx = owner_with_submissions('replyto@example.test');

    $newest = http_get('/submissions?project_id=' . $ctx['project']['id'], $ctx['token'])['data'][0];
    assert_same('alan@example.test', $newest['reply_to'], 'the Reply button needs an address');
});

test('submissions: reply_to is null when no field holds an address', function () {
    $token   = register_user('noemail@example.test');
    $project = http_post('/projects', ['project_name' => 'No Email'], $token)['data'];

    $form = http_post('/forms', [
        'project_id' => $project['id'],
        'form_name'  => 'Feedback',
        'fields'     => [['key' => 'note', 'label' => 'Note', 'type' => 'textarea', 'required' => true]],
    ], $token)['data'];

    seed_submission($form['id'], ['note' => 'Anonymous feedback']);

    $res = http_get('/submissions?project_id=' . $project['id'] . '&form_id=' . $form['id'], $token);
    assert_same(null, $res['data'][0]['reply_to'], 'no email field means nothing to reply to');
});

test('submissions: can be filtered by form and by unread', function () {
    $ctx = owner_with_submissions('filters@example.test');

    // A second form in the same project, with its own submission.
    $other = http_post('/forms', [
        'project_id' => $ctx['project']['id'],
        'form_name'  => 'Newsletter',
        'fields'     => [['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]],
    ], $ctx['token'])['data'];
    seed_submission($other['id'], ['email' => 'sub@example.test'], '2026-05-04 10:00:00');

    $all = http_get('/submissions?project_id=' . $ctx['project']['id'], $ctx['token']);
    assert_count_is(4, $all['data'], 'the project sees both forms\' submissions');

    $byForm = http_get('/submissions?project_id=' . $ctx['project']['id'] . '&form_id=' . $other['id'], $ctx['token']);
    assert_count_is(1, $byForm['data'], 'filtering by form narrows the list');
    assert_same('Newsletter', $byForm['data'][0]['form_name']);

    $unread = http_get('/submissions?project_id=' . $ctx['project']['id'] . '&unread=1', $ctx['token']);
    assert_count_is(3, $unread['data'], 'one of the four was seeded as already read');
});

test('submissions: opening one marks it read and clears the project badge', function () {
    $ctx = owner_with_submissions('markread@example.test');

    $before = http_get('/projects/get?id=' . $ctx['project']['id'], $ctx['token'])['data'];
    assert_same(3, $before['submission_count']);
    assert_same(2, $before['unread_count'], 'two of the three were seeded unread');

    $opened = http_get('/submissions/get?id=' . $ctx['second'], $ctx['token']);
    assert_status(200, $opened, 'open a submission');
    assert_same(true, $opened['data']['is_read'], 'opening marks it read');
    assert_true($opened['data']['read_at'] !== null, 'read_at should be stamped');

    $after = http_get('/projects/get?id=' . $ctx['project']['id'], $ctx['token'])['data'];
    assert_same(1, $after['unread_count'], 'the badge should drop by one');
});

test('submissions: a submission can be peeked at without marking it read', function () {
    $ctx = owner_with_submissions('peek@example.test');

    $peek = http_get('/submissions/get?id=' . $ctx['second'] . '&mark_read=0', $ctx['token']);
    assert_status(200, $peek, 'peek');
    assert_same(false, $peek['data']['is_read'], 'peeking must not mark it read');

    $project = http_get('/projects/get?id=' . $ctx['project']['id'], $ctx['token'])['data'];
    assert_same(2, $project['unread_count'], 'the badge should be unchanged');
});

test('submissions: mark-read accepts a batch, and can mark unread again', function () {
    $ctx = owner_with_submissions('batch@example.test');

    $res = http_post('/submissions/mark-read', ['ids' => [$ctx['second'], $ctx['third']]], $ctx['token']);
    assert_status(200, $res, 'batch mark read');
    assert_same(2, $res['data']['updated']);

    $project = http_get('/projects/get?id=' . $ctx['project']['id'], $ctx['token'])['data'];
    assert_same(0, $project['unread_count'], 'nothing should be unread now');

    $undo = http_post('/submissions/mark-read', ['id' => $ctx['third'], 'is_read' => false], $ctx['token']);
    assert_status(200, $undo, 'mark unread');

    $after = http_get('/projects/get?id=' . $ctx['project']['id'], $ctx['token'])['data'];
    assert_same(1, $after['unread_count'], 'marking unread should bring the badge back');
});

test('submissions: mark all as read clears a whole project', function () {
    $ctx = owner_with_submissions('markall@example.test');

    $res = http_post('/projects/mark-read', ['id' => $ctx['project']['id']], $ctx['token']);
    assert_status(200, $res, 'mark all read');
    assert_same(2, $res['data']['marked_read'], 'only the unread ones are counted');
    assert_same(0, $res['data']['project']['unread_count']);

    $unread = http_get('/submissions?project_id=' . $ctx['project']['id'] . '&unread=1', $ctx['token']);
    assert_count_is(0, $unread['data']);
});

test('submissions: mark all as read can be scoped to one form', function () {
    $ctx = owner_with_submissions('markform@example.test');

    $other = http_post('/forms', [
        'project_id' => $ctx['project']['id'],
        'form_name'  => 'Newsletter',
        'fields'     => [['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]],
    ], $ctx['token'])['data'];
    seed_submission($other['id'], ['email' => 'sub@example.test'], '2026-05-04 10:00:00');

    $res = http_post('/projects/mark-read', ['id' => $ctx['project']['id'], 'form_id' => $other['id']], $ctx['token']);
    assert_status(200, $res, 'mark one form read');
    assert_same(1, $res['data']['marked_read'], 'only the newsletter submission was cleared');
    assert_same(2, $res['data']['project']['unread_count'], 'the contact form\'s two stay unread');
});

test('submissions: another owner\'s submissions are invisible and untouchable', function () {
    $alice = owner_with_submissions('alice-subs@example.test');
    $bob   = owner_with_submissions('bob-subs@example.test');

    assert_not_found(
        http_get('/submissions?project_id=' . $bob['project']['id'], $alice['token']),
        'list another owner\'s submissions'
    );
    assert_not_found(
        http_get('/submissions/get?id=' . $bob['second'], $alice['token']),
        'read another owner\'s submission'
    );
    assert_not_found(
        http_post('/submissions/mark-read', ['id' => $bob['second']], $alice['token']),
        'mark another owner\'s submission read'
    );

    // Bob's unread count is exactly as it was.
    $bobProject = http_get('/projects/get?id=' . $bob['project']['id'], $bob['token'])['data'];
    assert_same(2, $bobProject['unread_count'], 'bob\'s read state must be untouched');
});

test('submissions: a batch containing a foreign id fails as a whole', function () {
    $alice = owner_with_submissions('alice-batch@example.test');
    $bob   = owner_with_submissions('bob-batch@example.test');

    // Mixing in somebody else's id must fail outright. Skipping it silently
    // would turn this endpoint into an existence oracle.
    $res = http_post('/submissions/mark-read', ['ids' => [$alice['second'], $bob['second']]], $alice['token']);
    assert_not_found($res, 'mixed batch');

    $aliceProject = http_get('/projects/get?id=' . $alice['project']['id'], $alice['token'])['data'];
    assert_same(2, $aliceProject['unread_count'], 'a rejected batch must not partially apply');
});

test('submissions: a form from another project cannot widen the filter', function () {
    $token = register_user('crossproject@example.test');

    $projectA = http_post('/projects', ['project_name' => 'A'], $token)['data'];
    $projectB = http_post('/projects', ['project_name' => 'B'], $token)['data'];
    $formB    = http_get('/forms?project_id=' . $projectB['id'], $token)['data'][0];

    // Both belong to the same user, so this is not an ownership failure — but
    // project A must not serve project B's form.
    assert_not_found(
        http_get('/submissions?project_id=' . $projectA['id'] . '&form_id=' . $formB['id'], $token),
        'form from a different project'
    );
});

test('submissions: guessable identifiers are rejected', function () {
    $ctx = owner_with_submissions('guesssubs@example.test');

    foreach (['1', '2', '0', '', 'abc'] as $guess) {
        assert_not_found(http_get('/submissions/get?id=' . urlencode($guess), $ctx['token']), "get id '{$guess}'");
        assert_not_found(http_post('/submissions/mark-read', ['id' => $guess], $ctx['token']), "mark id '{$guess}'");
        assert_not_found(http_get('/submissions?project_id=' . urlencode($guess), $ctx['token']), "list project '{$guess}'");
    }
});

test('submissions: every endpoint requires authentication', function () {
    $ctx = owner_with_submissions('subsauth@example.test');

    assert_status(401, http_get('/submissions?project_id=' . $ctx['project']['id']), 'list without a token');
    assert_status(401, http_get('/submissions/get?id=' . $ctx['second']), 'get without a token');
    assert_status(401, http_post('/submissions/mark-read', ['id' => $ctx['second']]), 'mark without a token');
});
