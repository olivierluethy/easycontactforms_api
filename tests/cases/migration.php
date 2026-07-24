<?php
// Migration v1 → v2.
//
// These cases build a real v1 database from the frozen fixture, fill it with
// the kind of data a live install has, and then run the real migration runner
// as a subprocess — the same command an operator types.

declare(strict_types=1);

/** Rebuild the test database at v1 and seed it. Returns the seeded ids. */
function seed_v1_database(): array
{
    harness_reset_db(['/tests/fixtures/schema_v1.sql']);
    $pdo = harness_db();

    $pdo->exec("INSERT INTO users (id, email, password_hash, api_token)
                VALUES (1, 'owner@example.test', 'x', 'token-owner'),
                       (2, 'other@example.test', 'x', 'token-other')");

    $pdo->exec("INSERT INTO projects (id, user_id, project_name, project_token)
                VALUES (1, 1, 'Acme Landing', 'aaaaaaaaaaaaaaaaaaaaaaaa'),
                       (2, 1, 'Empty Project', 'bbbbbbbbbbbbbbbbbbbbbbbb'),
                       (3, 2, 'Other User Site', 'cccccccccccccccccccccccc')");

    $longMessage = str_repeat("Paragraph of a very long message.\n\n", 40);
    $stmt = $pdo->prepare('INSERT INTO submissions (id, project_id, full_name, email, message, ip_address, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([1, 1, 'Ada Lovelace', 'ada@example.test', 'First message', '10.0.0.1', '2026-05-01 09:15:00']);
    $stmt->execute([2, 1, 'Grace Hopper', 'grace@example.test', $longMessage, '10.0.0.2', '2026-05-02 14:30:00']);
    $stmt->execute([3, 3, 'Alan Turing', 'alan@example.test', 'Message on another account', null, '2026-05-03 08:00:00']);

    return ['long_message' => $longMessage];
}

/** Run the real migration runner as a subprocess. Returns [exitCode, output]. */
function run_migrator(): array
{
    $cfg = require ECF_API_ROOT . '/config/database.php';
    $cmd = sprintf(
        'ECF_DB_HOST=%s ECF_DB_PORT=%s ECF_DB_NAME=%s ECF_DB_USER=%s ECF_DB_PASS=%s php %s 2>&1',
        escapeshellarg((string)$cfg['host']),
        escapeshellarg((string)$cfg['port']),
        escapeshellarg((string)$cfg['database']),
        escapeshellarg((string)$cfg['username']),
        escapeshellarg((string)$cfg['password']),
        escapeshellarg(ECF_API_ROOT . '/migrations/migrate.php')
    );
    exec($cmd, $output, $code);
    return [$code, implode("\n", $output)];
}

test('migration: converts a v1 database and preserves every submission', function () {
    $seed = seed_v1_database();

    [$code, $output] = run_migrator();
    assert_same(0, $code, "migrate.php should succeed:\n" . $output);

    $pdo = harness_db();

    // Every project got exactly one default form.
    $forms = $pdo->query('SELECT project_id, COUNT(*) c, SUM(is_default) d FROM forms GROUP BY project_id')->fetchAll();
    assert_count_is(3, $forms, 'every project should have a form');
    foreach ($forms as $row) {
        assert_same(1, (int)$row['c'], "project {$row['project_id']} should have exactly one form");
        assert_same(1, (int)$row['d'], "project {$row['project_id']}'s form should be the default");
    }

    // The default form has the three legacy fields, in order.
    $fields = $pdo->query(
        'SELECT ff.field_key, ff.label, ff.field_type, ff.is_required, ff.sort_order
           FROM form_fields ff JOIN forms f ON f.id = ff.form_id
          WHERE f.project_id = 1 ORDER BY ff.sort_order'
    )->fetchAll();
    assert_count_is(3, $fields, 'default form should have three fields');
    assert_same('full_name', $fields[0]['field_key']);
    assert_same('text', $fields[0]['field_type']);
    assert_same('email', $fields[1]['field_key']);
    assert_same('email', $fields[1]['field_type']);
    assert_same('message', $fields[2]['field_key']);
    assert_same('textarea', $fields[2]['field_type']);
    foreach ($fields as $f) {
        assert_same(1, (int)$f['is_required'], 'legacy fields were all mandatory');
    }

    // Every submission is attached to its own project's default form.
    $orphans = (int)$pdo->query('SELECT COUNT(*) FROM submissions WHERE form_id IS NULL')->fetchColumn();
    assert_same(0, $orphans, 'no submission may be left without a form');

    $mismatched = (int)$pdo->query(
        'SELECT COUNT(*) FROM submissions s JOIN forms f ON f.id = s.form_id WHERE f.project_id <> s.project_id'
    )->fetchColumn();
    assert_same(0, $mismatched, 'a submission must never be attached to another project\'s form');

    // Values were copied verbatim, including the very long message.
    $values = $pdo->query(
        "SELECT field_key, field_label, field_type, value, sort_order
           FROM submission_values WHERE submission_id = 1 ORDER BY sort_order"
    )->fetchAll();
    assert_count_is(3, $values, 'submission 1 should have three values');
    assert_same('full_name', $values[0]['field_key']);
    assert_same('Ada Lovelace', $values[0]['value']);
    assert_same('Full name', $values[0]['field_label']);
    assert_same('email', $values[1]['field_key']);
    assert_same('ada@example.test', $values[1]['value']);
    assert_same('message', $values[2]['field_key']);
    assert_same('First message', $values[2]['value']);

    $long = $pdo->query("SELECT value FROM submission_values WHERE submission_id = 2 AND field_key = 'message'")->fetchColumn();
    assert_same($seed['long_message'], $long, 'a long message must survive the copy byte for byte');

    // The legacy columns are still there, untouched, as a recovery copy.
    $legacy = $pdo->query('SELECT full_name, email, message FROM submissions WHERE id = 1')->fetch();
    assert_same('Ada Lovelace', $legacy['full_name'], 'legacy columns must be preserved');
});

test('migration: generates unguessable version-4 public_ids', function () {
    seed_v1_database();
    [$code, $output] = run_migrator();
    assert_same(0, $code, $output);

    $pdo = harness_db();

    foreach (['projects', 'submissions', 'forms'] as $table) {
        $ids = $pdo->query("SELECT public_id FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        assert_true(count($ids) > 0, "{$table} should have rows");
        assert_same(count($ids), count(array_unique($ids)), "{$table}.public_id must be unique");

        foreach ($ids as $id) {
            // Version nibble 4 and variant nibble 8/9/a/b: a random UUID, not
            // MySQL's UUID(), which is version 1 and derived from clock + MAC.
            assert_matches(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                (string)$id,
                "{$table}.public_id must be a version-4 UUID"
            );
        }
    }

    // Neighbouring rows must not share a predictable prefix. Version 1 UUIDs
    // generated back to back typically differ only in the last few characters
    // of the time-low field, which is exactly what we are guarding against.
    $projectIds = $pdo->query('SELECT public_id FROM projects ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(
        substr($projectIds[0], 0, 8) !== substr($projectIds[1], 0, 8),
        'consecutive public_ids share a prefix — are these really random?'
    );
});

test('migration: form tokens are unique and unguessable', function () {
    seed_v1_database();
    [$code, $output] = run_migrator();
    assert_same(0, $code, $output);

    $tokens = harness_db()->query('SELECT form_token FROM forms')->fetchAll(PDO::FETCH_COLUMN);
    assert_count_is(3, $tokens);
    assert_same(3, count(array_unique($tokens)), 'form tokens must be unique');
    foreach ($tokens as $token) {
        assert_matches('/^[0-9a-f]{24}$/', (string)$token, 'form_token should be 12 random bytes of hex');
    }
});

test('migration: pre-existing submissions start out read', function () {
    seed_v1_database();
    [$code, $output] = run_migrator();
    assert_same(0, $code, $output);

    $unread = (int)harness_db()->query('SELECT COUNT(*) FROM submissions WHERE is_read = 0')->fetchColumn();
    assert_same(0, $unread, 'migrated submissions should not appear as new');

    $withoutReadAt = (int)harness_db()->query('SELECT COUNT(*) FROM submissions WHERE read_at IS NULL')->fetchColumn();
    assert_same(0, $withoutReadAt, 'read_at should be stamped when marking as read');
});

test('migration: running twice is a no-op', function () {
    seed_v1_database();

    [$code] = run_migrator();
    assert_same(0, $code, 'first run should succeed');

    $pdo = harness_db();
    $before = [
        'forms'   => $pdo->query('SELECT COUNT(*) FROM forms')->fetchColumn(),
        'fields'  => $pdo->query('SELECT COUNT(*) FROM form_fields')->fetchColumn(),
        'values'  => $pdo->query('SELECT COUNT(*) FROM submission_values')->fetchColumn(),
        'ids'     => $pdo->query('SELECT GROUP_CONCAT(public_id ORDER BY id) FROM projects')->fetchColumn(),
    ];

    [$code2, $output2] = run_migrator();
    assert_same(0, $code2, "second run should succeed:\n" . $output2);
    assert_contains('Already up to date', $output2, 'second run should report nothing to do');

    $after = [
        'forms'   => $pdo->query('SELECT COUNT(*) FROM forms')->fetchColumn(),
        'fields'  => $pdo->query('SELECT COUNT(*) FROM form_fields')->fetchColumn(),
        'values'  => $pdo->query('SELECT COUNT(*) FROM submission_values')->fetchColumn(),
        'ids'     => $pdo->query('SELECT GROUP_CONCAT(public_id ORDER BY id) FROM projects')->fetchColumn(),
    ];

    assert_same($before, $after, 'a second migration run must change nothing');
});

test('migration: migrated schema matches a fresh install', function () {
    // A v1 database that has been migrated and a database created straight from
    // database.sql must end up structurally identical — otherwise the two kinds
    // of install drift apart and queries that work on one break on the other.
    seed_v1_database();
    [$code, $output] = run_migrator();
    assert_same(0, $code, $output);

    $describe = static function (PDO $pdo): array {
        $schema = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $columns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
            $schema[$table]['columns'] = array_map(
                static fn ($c) => strtolower($c['Field'] . ' ' . $c['Type'] . ' ' . $c['Null'] . ' ' . ($c['Default'] ?? '')),
                $columns
            );
            sort($schema[$table]['columns']);

            $indexes = $pdo->query("SHOW INDEX FROM `{$table}`")->fetchAll();
            $schema[$table]['indexes'] = array_values(array_unique(array_map(
                static fn ($i) => $i['Key_name'] . ':' . $i['Column_name'] . ':' . $i['Non_unique'],
                $indexes
            )));
            sort($schema[$table]['indexes']);
        }
        ksort($schema);
        return $schema;
    };

    $migrated = $describe(harness_db());

    harness_reset_db(['/database.sql']);
    $fresh = $describe(harness_db());

    foreach ($fresh as $table => $definition) {
        assert_true(isset($migrated[$table]), "migrated database is missing table '{$table}'");
        assert_same($definition['columns'], $migrated[$table]['columns'], "columns differ on '{$table}'");
        assert_same($definition['indexes'], $migrated[$table]['indexes'], "indexes differ on '{$table}'");
    }
    assert_same(array_keys($fresh), array_keys($migrated), 'the two installs should have the same tables');
});
