<?php
// The public widget endpoints: /form/config and /form/submit.
//
// The most important cases here are the backward-compatibility ones. Snippets
// already pasted into customer sites send the old flat payload and cannot be
// updated by us, so if these break, live contact forms break.

declare(strict_types=1);

/** An owner with a project and its default form. */
function public_form_fixture(string $email = 'public@example.test'): array
{
    $token   = register_user($email);
    $project = http_post('/projects', ['project_name' => 'Acme Landing'], $token)['data'];
    $form    = http_get('/forms?project_id=' . $project['id'], $token)['data'][0];

    return compact('token', 'project', 'form');
}

// ── Backward compatibility ────────────────────────────────────────────────

test('public: the legacy flat payload still submits successfully', function () {
    $ctx = public_form_fixture();

    // Byte for byte what the deployed embed.js and @easycontact/react 0.2.1 send.
    $res = http_post('/form/submit', [
        'project_token' => $ctx['project']['project_token'],
        'full_name'     => 'Ada Lovelace',
        'email'         => 'ada@example.test',
        'message'       => 'Sent by an embed that predates custom forms.',
        'website'       => '',
    ]);
    assert_status(201, $res, 'legacy submit');
    assert_same(true, $res['data']['received']);

    // It landed on the default form, with the values mapped onto its fields.
    $listed = http_get('/submissions?project_id=' . $ctx['project']['id'], $ctx['token']);
    assert_count_is(1, $listed['data']);

    $submission = $listed['data'][0];
    assert_same($ctx['form']['id'], $submission['form_id'], 'legacy submissions go to the default form');
    assert_same(['full_name', 'email', 'message'], array_column($submission['values'], 'key'));
    assert_same('Ada Lovelace', $submission['values'][0]['value']);
    assert_same('ada@example.test', $submission['values'][1]['value']);
    assert_same('Sent by an embed that predates custom forms.', $submission['values'][2]['value']);
    assert_same(false, $submission['is_read'], 'a new submission arrives unread');
});

test('public: a legacy submission raises the project\'s unread count', function () {
    $ctx = public_form_fixture('unreadcount@example.test');

    http_post('/form/submit', [
        'project_token' => $ctx['project']['project_token'],
        'full_name'     => 'Grace Hopper',
        'email'         => 'grace@example.test',
        'message'       => 'Hello',
    ]);

    $project = http_get('/projects/get?id=' . $ctx['project']['id'], $ctx['token'])['data'];
    assert_same(1, $project['submission_count']);
    assert_same(1, $project['unread_count'], 'new submissions must show up as new');
    assert_true($project['last_submission_at'] !== null, 'last activity should be stamped');
});

test('public: the legacy honeypot still silently swallows bots', function () {
    $ctx = public_form_fixture('honeypot@example.test');

    $res = http_post('/form/submit', [
        'project_token' => $ctx['project']['project_token'],
        'full_name'     => 'Spam Bot',
        'email'         => 'bot@example.test',
        'message'       => 'Buy things',
        'website'       => 'http://spam.example',
    ]);
    // The bot is told it worked, so it learns nothing.
    assert_status(200, $res, 'honeypot response');
    assert_same(true, $res['data']['received']);

    $listed = http_get('/submissions?project_id=' . $ctx['project']['id'], $ctx['token']);
    assert_count_is(0, $listed['data'], 'nothing should have been stored');
});

test('public: legacy validation messages are unchanged', function () {
    $ctx = public_form_fixture('legacyvalidation@example.test');
    $projectToken = $ctx['project']['project_token'];

    $missingName = http_post('/form/submit', [
        'project_token' => $projectToken,
        'email'         => 'a@example.test',
        'message'       => 'x',
    ]);
    assert_status(400, $missingName, 'missing name');

    $badEmail = http_post('/form/submit', [
        'project_token' => $projectToken,
        'full_name'     => 'A',
        'email'         => 'not-an-email',
        'message'       => 'x',
    ]);
    assert_status(400, $badEmail, 'invalid email');

    $unknownProject = http_post('/form/submit', [
        'project_token' => 'ffffffffffffffffffffffff',
        'full_name'     => 'A',
        'email'         => 'a@example.test',
        'message'       => 'x',
    ]);
    assert_status(404, $unknownProject, 'unknown project token');
});

test('public: /form/config keeps its original response shape', function () {
    $ctx = public_form_fixture('legacyconfig@example.test');

    $res = http_get('/form/config?project_token=' . $ctx['project']['project_token']);
    assert_status(200, $res, 'legacy config');
    assert_same('Acme Landing', $res['data']['project_name']);
    // The original endpoint returned a flat array of field keys. Anything built
    // against that must keep working.
    assert_same(['full_name', 'email', 'message'], $res['data']['fields']);
});

// ── Custom forms ──────────────────────────────────────────────────────────

test('public: /form/config describes a custom form in full', function () {
    $ctx = public_form_fixture('customconfig@example.test');

    $form = http_post('/forms', [
        'project_id' => $ctx['project']['id'],
        'form_name'  => 'Quick enquiry',
        'fields'     => [
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ['key' => 'name',  'label' => 'Name',  'type' => 'text',  'required' => true],
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => false],
        ],
    ], $ctx['token'])['data'];

    $res = http_get('/form/config?form_token=' . $form['form_token']);
    assert_status(200, $res, 'config by form token');
    assert_same('Quick enquiry', $res['data']['form_name']);
    assert_same(['email', 'name', 'phone'], $res['data']['fields'], 'legacy key array reflects the real fields');

    $rich = $res['data']['form']['fields'];
    assert_count_is(3, $rich);
    assert_same('Phone', $rich[2]['label']);
    assert_same('phone', $rich[2]['type']);
    assert_same(false, $rich[2]['required'], 'the widget needs to know what is optional');

    // Dashboard identifiers must not leak through a public endpoint.
    assert_true(!str_contains($res['raw'], $form['id']), 'the form public_id must not appear in a public response');
    assert_true(!str_contains($res['raw'], $ctx['project']['id']), 'the project public_id must not appear either');
});

test('public: a custom form accepts a nested payload', function () {
    $ctx = public_form_fixture('customsubmit@example.test');

    $form = http_post('/forms', [
        'project_id' => $ctx['project']['id'],
        'form_name'  => 'Quick enquiry',
        'fields'     => [
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ['key' => 'name',  'label' => 'Name',  'type' => 'text',  'required' => true],
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => false],
        ],
    ], $ctx['token'])['data'];

    $res = http_post('/form/submit', [
        'form_token' => $form['form_token'],
        'fields'     => [
            'email' => 'buyer@example.test',
            'name'  => 'Grace Hopper',
            'phone' => '+41 44 123 45 67',
        ],
    ]);
    assert_status(201, $res, 'custom submit');

    $listed = http_get('/submissions?project_id=' . $ctx['project']['id'] . '&form_id=' . $form['id'], $ctx['token']);
    assert_count_is(1, $listed['data']);
    assert_same(['email', 'name', 'phone'], array_column($listed['data'][0]['values'], 'key'), 'stored in field order');
    assert_same('+41 44 123 45 67', $listed['data'][0]['values'][2]['value']);
    assert_same('Quick enquiry', $listed['data'][0]['form_name']);
});

test('public: optional fields may be omitted, required ones may not', function () {
    $ctx = public_form_fixture('required@example.test');

    $form = http_post('/forms', [
        'project_id' => $ctx['project']['id'],
        'form_name'  => 'Enquiry',
        'fields'     => [
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone', 'required' => false],
        ],
    ], $ctx['token'])['data'];

    $withoutOptional = http_post('/form/submit', [
        'form_token' => $form['form_token'],
        'fields'     => ['email' => 'a@example.test'],
    ]);
    assert_status(201, $withoutOptional, 'omitting an optional field is fine');

    $listed = http_get('/submissions?project_id=' . $ctx['project']['id'] . '&form_id=' . $form['id'], $ctx['token']);
    assert_count_is(1, $listed['data'][0]['values'], 'an empty optional field is not stored');
    assert_same('email', $listed['data'][0]['values'][0]['key']);

    $missingRequired = http_post('/form/submit', [
        'form_token' => $form['form_token'],
        'fields'     => ['phone' => '0441234567'],
    ]);
    assert_status(400, $missingRequired, 'omitting a required field must fail');
    assert_contains('Email', (string)$missingRequired['error'], 'the message should name the field');
});

test('public: values are validated per field type', function () {
    $ctx = public_form_fixture('types@example.test');

    $form = http_post('/forms', [
        'project_id' => $ctx['project']['id'],
        'form_name'  => 'Typed',
        'fields'     => [
            ['key' => 'email', 'label' => 'Email',  'type' => 'email',    'required' => true],
            ['key' => 'phone', 'label' => 'Phone',  'type' => 'phone',    'required' => true],
            ['key' => 'note',  'label' => 'Note',   'type' => 'textarea', 'required' => true],
        ],
    ], $ctx['token'])['data'];

    $submit = static fn (array $fields) => http_post('/form/submit', ['form_token' => $form['form_token'], 'fields' => $fields]);

    assert_status(400, $submit(['email' => 'nope', 'phone' => '0441234567', 'note' => 'x']), 'invalid email');
    assert_status(400, $submit(['email' => 'a@b.co', 'phone' => 'call me maybe', 'note' => 'x']), 'invalid phone');
    assert_status(400, $submit(['email' => 'a@b.co', 'phone' => '0441234567', 'note' => str_repeat('x', 5001)]), 'over-long textarea');
    assert_status(201, $submit(['email' => 'a@b.co', 'phone' => '+41 (0)44 123 45 67', 'note' => 'Fine']), 'valid values');
});

test('public: a project token submits to the default form even with nested fields', function () {
    $ctx = public_form_fixture('nestedlegacy@example.test');

    $res = http_post('/form/submit', [
        'project_token' => $ctx['project']['project_token'],
        'fields'        => [
            'full_name' => 'Alan Turing',
            'email'     => 'alan@example.test',
            'message'   => 'Nested payload, project token.',
        ],
    ]);
    assert_status(201, $res, 'nested payload with a project token');

    $listed = http_get('/submissions?project_id=' . $ctx['project']['id'], $ctx['token']);
    assert_count_is(1, $listed['data']);
    assert_same('Alan Turing', $listed['data'][0]['values'][0]['value']);
});

test('public: submissions cannot be aimed at another project', function () {
    $alice = public_form_fixture('alice-public@example.test');
    $bob   = public_form_fixture('bob-public@example.test');

    // Bob's form token names Bob's project — nothing in the payload can change
    // where a submission is filed.
    $res = http_post('/form/submit', [
        'form_token'    => $bob['form']['form_token'],
        'project_token' => $alice['project']['project_token'],
        'fields'        => ['full_name' => 'X', 'email' => 'x@example.test', 'message' => 'x'],
    ]);
    assert_status(201, $res, 'submit with both tokens');

    assert_count_is(0, http_get('/submissions?project_id=' . $alice['project']['id'], $alice['token'])['data'], 'alice must receive nothing');
    assert_count_is(1, http_get('/submissions?project_id=' . $bob['project']['id'], $bob['token'])['data'], 'bob receives it');
});

test('public: a form field named "website" is stored, not treated as a honeypot', function () {
    $ctx = public_form_fixture('websitefield@example.test');

    // "Website" is a perfectly ordinary thing to ask for on a contact form, so
    // it must not collide with the hidden anti-bot input.
    $form = http_post('/forms', [
        'project_id' => $ctx['project']['id'],
        'form_name'  => 'Agency brief',
        'fields'     => [
            ['key' => 'email',   'label' => 'Email',        'type' => 'email', 'required' => true],
            ['key' => 'website', 'label' => 'Your website', 'type' => 'text',  'required' => true],
        ],
    ], $ctx['token'])['data'];

    $res = http_post('/form/submit', [
        'form_token' => $form['form_token'],
        'fields'     => ['email' => 'a@example.test', 'website' => 'https://client.example'],
    ]);
    assert_status(201, $res, 'submit with a real website field');

    $listed = http_get('/submissions?project_id=' . $ctx['project']['id'] . '&form_id=' . $form['id'], $ctx['token']);
    assert_count_is(1, $listed['data'], 'the submission must not be swallowed as spam');
    assert_same('https://client.example', $listed['data'][0]['values'][1]['value']);
});

test('public: endpoints need no authentication', function () {
    $ctx = public_form_fixture('noauth@example.test');

    // These are called by browsers on sites we do not control; requiring a
    // token would break every embed.
    assert_status(200, http_get('/form/config?project_token=' . $ctx['project']['project_token']), 'config without a token');
    assert_status(201, http_post('/form/submit', [
        'project_token' => $ctx['project']['project_token'],
        'full_name'     => 'Anon',
        'email'         => 'anon@example.test',
        'message'       => 'Hi',
    ]), 'submit without a token');
});

test('public: an unknown form token is rejected', function () {
    assert_status(404, http_get('/form/config?form_token=ffffffffffffffffffffffff'), 'unknown form token');
    assert_status(404, http_post('/form/submit', [
        'form_token' => 'ffffffffffffffffffffffff',
        'fields'     => ['email' => 'a@example.test'],
    ]), 'submit to an unknown form');
});

test('public: a very long message is stored intact', function () {
    $ctx = public_form_fixture('longmessage@example.test');

    // The message that used to break the submissions table layout.
    $long = trim(str_repeat("This is a paragraph of a very long message that a visitor pasted in.\n\n", 60));

    $res = http_post('/form/submit', [
        'project_token' => $ctx['project']['project_token'],
        'full_name'     => 'Verbose Visitor',
        'email'         => 'verbose@example.test',
        'message'       => $long,
    ]);
    assert_status(201, $res, 'long message submit');

    $listed = http_get('/submissions?project_id=' . $ctx['project']['id'], $ctx['token']);
    assert_same($long, $listed['data'][0]['values'][2]['value'], 'the full text must be preserved');
});
