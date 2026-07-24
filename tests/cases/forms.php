<?php
// Form endpoints: multiple forms per project, custom field sets, ownership.

declare(strict_types=1);

/** One owner with one project. */
function owner_with_project(string $email = 'forms@example.test'): array
{
    $token   = register_user($email);
    $project = http_post('/projects', ['project_name' => 'Widget Site'], $token)['data'];
    return ['token' => $token, 'project' => $project];
}

test('forms: a new project exposes exactly one default form', function () {
    $ctx = owner_with_project();

    $res = http_get('/forms?project_id=' . $ctx['project']['id'], $ctx['token']);
    assert_status(200, $res, 'list forms');
    assert_count_is(1, $res['data']);

    $form = $res['data'][0];
    assert_true($form['is_default'], 'the first form should be the default');
    assert_matches('/^[0-9a-f]{24}$/', (string)$form['form_token'], 'form token shape');
    assert_matches('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)$form['id'], 'form id is a v4 UUID');

    assert_count_is(3, $form['fields']);
    assert_same(['full_name', 'email', 'message'], array_column($form['fields'], 'key'));
});

test('forms: a project can hold several forms with different fields', function () {
    $ctx = owner_with_project('multi@example.test');

    // The WhatsApp Customizer case from the brief: one project, two forms.
    $short = http_post('/forms', [
        'project_id' => $ctx['project']['id'],
        'form_name'  => 'Quick enquiry',
        'fields'     => [
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ['key' => 'name',  'label' => 'Name',  'type' => 'text',  'required' => true],
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => false],
        ],
    ], $ctx['token']);
    assert_status(201, $short, 'create the second form');
    assert_count_is(3, $short['data']['fields']);
    assert_same(['email', 'name', 'phone'], array_column($short['data']['fields'], 'key'), 'field order is preserved');
    assert_same(false, $short['data']['fields'][2]['required'], 'phone was marked optional');
    assert_same(false, $short['data']['is_default'], 'only the first form is the default');

    $long = http_post('/forms', [
        'project_id' => $ctx['project']['id'],
        'form_name'  => 'Full brief',
        'fields'     => [
            ['key' => 'email',   'label' => 'Email',      'type' => 'email',    'required' => true],
            ['key' => 'company', 'label' => 'Company',    'type' => 'text',     'required' => false],
            ['key' => 'budget',  'label' => 'Budget',     'type' => 'text',     'required' => false],
            ['key' => 'brief',   'label' => 'Your brief', 'type' => 'textarea', 'required' => true],
        ],
    ], $ctx['token']);
    assert_status(201, $long, 'create the third form');

    // Each form has its own token, so each gets its own snippet.
    $list = http_get('/forms?project_id=' . $ctx['project']['id'], $ctx['token']);
    assert_count_is(3, $list['data']);
    $tokens = array_column($list['data'], 'form_token');
    assert_same(3, count(array_unique($tokens)), 'every form needs a distinct token');
});

test('forms: fields can be reordered, relabelled and made optional', function () {
    $ctx = owner_with_project('editor@example.test');
    $form = http_get('/forms?project_id=' . $ctx['project']['id'], $ctx['token'])['data'][0];

    $res = http_post('/forms/update', [
        'id'     => $form['id'],
        'fields' => [
            ['key' => 'email',     'label' => 'Your email',   'type' => 'email',    'required' => true],
            ['key' => 'message',   'label' => 'What\'s up?',  'type' => 'textarea', 'required' => true],
            ['key' => 'full_name', 'label' => 'Name',         'type' => 'text',     'required' => false],
        ],
    ], $ctx['token']);
    assert_status(200, $res, 'reorder fields');

    $fields = $res['data']['fields'];
    assert_same(['email', 'message', 'full_name'], array_column($fields, 'key'), 'new order should stick');
    assert_same('Your email', $fields[0]['label'], 'label change should stick');
    assert_same(false, $fields[2]['required'], 'full_name is now optional');
});

test('forms: removing a field drops it from the definition', function () {
    $ctx = owner_with_project('shrink@example.test');
    $form = http_get('/forms?project_id=' . $ctx['project']['id'], $ctx['token'])['data'][0];

    $res = http_post('/forms/update', [
        'id'     => $form['id'],
        'fields' => [
            ['key' => 'email',   'label' => 'Email',   'type' => 'email',    'required' => true],
            ['key' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true],
        ],
    ], $ctx['token']);
    assert_status(200, $res, 'remove a field');
    assert_count_is(2, $res['data']['fields']);
    assert_same(['email', 'message'], array_column($res['data']['fields'], 'key'));
});

test('forms: field definitions are validated', function () {
    $ctx = owner_with_project('badfields@example.test');
    $projectId = $ctx['project']['id'];

    $create = static fn (array $fields) => http_post(
        '/forms',
        ['project_id' => $projectId, 'form_name' => 'Test', 'fields' => $fields],
        $ctx['token']
    );

    assert_status(400, $create([]), 'a form needs at least one field');
    assert_status(400, $create([['key' => 'Bad Key', 'label' => 'X', 'type' => 'text']]), 'keys must be snake_case');
    assert_status(400, $create([['key' => '1st', 'label' => 'X', 'type' => 'text']]), 'keys must start with a letter');
    assert_status(400, $create([['key' => 'ok', 'label' => '', 'type' => 'text']]), 'a field needs a label');
    assert_status(400, $create([['key' => 'ok', 'label' => 'X', 'type' => 'password']]), 'unknown field type');
    assert_status(400, $create([
        ['key' => 'dup', 'label' => 'One', 'type' => 'text'],
        ['key' => 'dup', 'label' => 'Two', 'type' => 'text'],
    ]), 'duplicate keys');

    // The four supported types are all accepted.
    assert_status(201, $create([
        ['key' => 'a', 'label' => 'A', 'type' => 'text'],
        ['key' => 'b', 'label' => 'B', 'type' => 'email'],
        ['key' => 'c', 'label' => 'C', 'type' => 'phone'],
        ['key' => 'd', 'label' => 'D', 'type' => 'textarea'],
    ]), 'all four field types');
});

test('forms: a project\'s last form cannot be deleted', function () {
    $ctx = owner_with_project('lastform@example.test');
    $form = http_get('/forms?project_id=' . $ctx['project']['id'], $ctx['token'])['data'][0];

    // Deleting it would leave the project's token pointing at nothing, breaking
    // any embed already live on the customer's site.
    $res = http_post('/forms/delete', ['id' => $form['id']], $ctx['token']);
    assert_status(409, $res, 'deleting the only form');

    $still = http_get('/forms?project_id=' . $ctx['project']['id'], $ctx['token']);
    assert_count_is(1, $still['data'], 'the form must still be there');
});

test('forms: deleting the default form promotes another one', function () {
    $ctx = owner_with_project('promote@example.test');
    $original = http_get('/forms?project_id=' . $ctx['project']['id'], $ctx['token'])['data'][0];

    http_post('/forms', ['project_id' => $ctx['project']['id'], 'form_name' => 'Second'], $ctx['token']);

    $res = http_post('/forms/delete', ['id' => $original['id']], $ctx['token']);
    assert_status(200, $res, 'delete the default form');

    $remaining = http_get('/forms?project_id=' . $ctx['project']['id'], $ctx['token'])['data'];
    assert_count_is(1, $remaining);
    assert_true($remaining[0]['is_default'], 'the survivor must become the default');
});

test('forms: another owner\'s forms are invisible and untouchable', function () {
    $alice = owner_with_project('alice-forms@example.test');
    $bob   = owner_with_project('bob-forms@example.test');

    $bobForm = http_get('/forms?project_id=' . $bob['project']['id'], $bob['token'])['data'][0];

    assert_not_found(
        http_get('/forms?project_id=' . $bob['project']['id'], $alice['token']),
        'list another owner\'s forms'
    );
    assert_not_found(
        http_post('/forms', ['project_id' => $bob['project']['id'], 'form_name' => 'Injected'], $alice['token']),
        'create a form in another owner\'s project'
    );
    assert_not_found(
        http_post('/forms/update', ['id' => $bobForm['id'], 'form_name' => 'Pwned'], $alice['token']),
        'rename another owner\'s form'
    );
    assert_not_found(
        http_post('/forms/delete', ['id' => $bobForm['id']], $alice['token']),
        'delete another owner\'s form'
    );

    // Bob's form is exactly as he left it.
    $bobView = http_get('/forms?project_id=' . $bob['project']['id'], $bob['token'])['data'][0];
    assert_same($bobForm['form_name'], $bobView['form_name'], 'the form name must be unchanged');
    assert_count_is(3, $bobView['fields'], 'the fields must be unchanged');
});

test('forms: guessable form identifiers are rejected', function () {
    $ctx = owner_with_project('guessforms@example.test');

    foreach (['1', '2', '0', '', 'abc'] as $guess) {
        assert_not_found(http_post('/forms/update', ['id' => $guess, 'form_name' => 'x'], $ctx['token']), "update id '{$guess}'");
        assert_not_found(http_post('/forms/delete', ['id' => $guess], $ctx['token']), "delete id '{$guess}'");
        assert_not_found(http_get('/forms?project_id=' . urlencode($guess), $ctx['token']), "list project_id '{$guess}'");
    }
});

test('forms: every endpoint requires authentication', function () {
    $ctx = owner_with_project('formauth@example.test');
    $form = http_get('/forms?project_id=' . $ctx['project']['id'], $ctx['token'])['data'][0];

    assert_status(401, http_get('/forms?project_id=' . $ctx['project']['id']), 'list without a token');
    assert_status(401, http_post('/forms', ['project_id' => $ctx['project']['id'], 'form_name' => 'x']), 'create without a token');
    assert_status(401, http_post('/forms/update', ['id' => $form['id'], 'form_name' => 'x']), 'update without a token');
    assert_status(401, http_post('/forms/delete', ['id' => $form['id']]), 'delete without a token');
});
