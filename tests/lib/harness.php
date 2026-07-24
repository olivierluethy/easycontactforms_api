<?php
// Dependency-free test harness for the EasyContactForm API.
//
// The API itself has no Composer dependencies and no autoloader, so the tests
// don't add any either. This file provides everything a test case needs:
// a throwaway database, a live server to talk to, HTTP helpers, and assertions.
//
// Endpoints terminate via exit() inside json_ok()/json_error(), so they can't be
// require()d in-process. Tests therefore drive a real `php -S` instance, which
// has the side benefit of exercising index.php routing and bootstrap.php's auth
// handling exactly as a browser would.
//
// Usage:  php tests/run.php [name-filter]

declare(strict_types=1);

const ECF_TEST_HOST = '127.0.0.1';
const ECF_TEST_PORT = 8123;

define('ECF_API_ROOT', dirname(__DIR__, 2));

/** Thrown by the assert_* helpers; caught by the runner and reported as a failure. */
final class EcfAssertionFailed extends RuntimeException
{
}

// ── Registry ──────────────────────────────────────────────────────────────

/** @var array<int, array{name:string, fn:callable}> */
$GLOBALS['ecf_tests'] = [];

/** Register a test case. Called from the files in tests/cases/. */
function test(string $name, callable $fn): void
{
    $GLOBALS['ecf_tests'][] = ['name' => $name, 'fn' => $fn];
}

// ── Environment + database ────────────────────────────────────────────────

/**
 * Point this process (and the server it spawns) at the test database.
 *
 * Credentials come from config/database.php as usual — only the database name
 * is overridden, so there is exactly one place where credentials live.
 */
function harness_configure_env(): void
{
    $name = getenv('ECF_DB_NAME') ?: 'easycontactforms_test';
    if (!str_ends_with($name, '_test')) {
        fwrite(STDERR, "Refusing to run: ECF_DB_NAME ('{$name}') must end in _test.\n");
        exit(1);
    }
    putenv('ECF_DB_NAME=' . $name);
    $_ENV['ECF_DB_NAME'] = $name;
}

function harness_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $cfg = require ECF_API_ROOT . '/config/database.php';
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']),
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

/** Drop every table, then re-apply the given .sql files in order. */
function harness_reset_db(array $sqlFiles = ['/database.sql']): void
{
    $pdo = harness_db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach ($sqlFiles as $file) {
        harness_run_sql_file(ECF_API_ROOT . $file);
    }
}

/** Execute every statement in a .sql file. */
function harness_run_sql_file(string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Could not read {$path}");
    }
    // Strip full-line comments, then split on statement terminators. The schema
    // files contain no stored programs, so a naive split is safe here.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            harness_db()->exec($statement);
        }
    }
}

// ── Test server ───────────────────────────────────────────────────────────

/** @var resource|null */
$GLOBALS['ecf_server'] = null;

function harness_base_url(): string
{
    return 'http://' . ECF_TEST_HOST . ':' . ECF_TEST_PORT;
}

function harness_start_server(): void
{
    if ($GLOBALS['ecf_server'] !== null) {
        return;
    }
    $cfg = require ECF_API_ROOT . '/config/database.php';

    // The child gets the resolved config explicitly. Without this it would read
    // config/database.local.php and connect to the developer's real database.
    $env = [
        'PATH'        => getenv('PATH') ?: '/usr/bin:/bin',
        'ECF_DB_HOST' => (string)$cfg['host'],
        'ECF_DB_PORT' => (string)$cfg['port'],
        'ECF_DB_NAME' => (string)$cfg['database'],
        'ECF_DB_USER' => (string)$cfg['username'],
        'ECF_DB_PASS' => (string)$cfg['password'],
    ];

    // `exec` makes the PHP server the shell's own process, so terminating the
    // handle kills the server instead of leaving an orphan holding the port.
    $cmd = sprintf(
        'exec php -S %s:%d -t %s %s',
        ECF_TEST_HOST,
        ECF_TEST_PORT,
        escapeshellarg(ECF_API_ROOT),
        escapeshellarg(ECF_API_ROOT . '/router.php')
    );

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ];

    $proc = proc_open($cmd, $descriptors, $pipes, ECF_API_ROOT, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start the PHP test server.');
    }
    $GLOBALS['ecf_server'] = $proc;
    register_shutdown_function('harness_stop_server');

    $deadline = microtime(true) + 10.0;
    while (microtime(true) < $deadline) {
        $conn = @fsockopen(ECF_TEST_HOST, ECF_TEST_PORT, $errno, $errstr, 0.2);
        if ($conn) {
            fclose($conn);
            return;
        }
        usleep(50_000);
    }
    throw new RuntimeException('Test server never started listening on port ' . ECF_TEST_PORT . '.');
}

function harness_stop_server(): void
{
    if ($GLOBALS['ecf_server'] === null) {
        return;
    }
    proc_terminate($GLOBALS['ecf_server'], SIGTERM);
    proc_close($GLOBALS['ecf_server']);
    $GLOBALS['ecf_server'] = null;
}

// ── HTTP helpers ──────────────────────────────────────────────────────────

/**
 * @param array<string,mixed>|null $body
 * @return array{status:int, json:array|null, data:mixed, error:string|null, raw:string}
 */
function http_request(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    $ch = curl_init(harness_base_url() . $path);
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw    = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($raw, true);
    $json = is_array($json) ? $json : null;

    return [
        'status' => $status,
        'json'   => $json,
        'data'   => $json['data'] ?? null,
        'error'  => $json['error'] ?? null,
        'raw'    => $raw,
    ];
}

function http_get(string $path, ?string $token = null): array
{
    return http_request('GET', $path, null, $token);
}

function http_post(string $path, array $body = [], ?string $token = null): array
{
    return http_request('POST', $path, $body, $token);
}

/** Register an account and return its bearer token. */
function register_user(string $email, string $password = 'secret123'): string
{
    $res = http_post('/auth/register', ['email' => $email, 'password' => $password]);
    assert_status(201, $res, "register {$email}");
    return $res['data']['token'];
}

// ── Assertions ────────────────────────────────────────────────────────────

function fail(string $message): void
{
    throw new EcfAssertionFailed($message);
}

function assert_true(bool $condition, string $message = 'Expected true'): void
{
    if (!$condition) {
        fail($message);
    }
}

function assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        fail(sprintf(
            "%s\n  expected: %s\n  actual:   %s",
            $message !== '' ? $message : 'Values differ',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_status(int $expected, array $res, string $context = ''): void
{
    if ($res['status'] !== $expected) {
        fail(sprintf(
            "%s: expected HTTP %d, got %d\n  body: %s",
            $context !== '' ? $context : 'Request',
            $expected,
            $res['status'],
            $res['raw']
        ));
    }
}

/**
 * Assert the generic 404 the API uses for both unknown and non-owned resources.
 * The two must be indistinguishable, or the response leaks existence.
 */
function assert_not_found(array $res, string $context = ''): void
{
    assert_status(404, $res, $context);
    assert_true(($res['json']['success'] ?? true) === false, $context . ': expected success:false');
}

function assert_count_is(int $expected, $countable, string $message = 'Wrong count'): void
{
    $actual = is_array($countable) ? count($countable) : -1;
    if ($actual !== $expected) {
        fail(sprintf("%s\n  expected: %d\n  actual:   %d\n  value: %s", $message, $expected, $actual, var_export($countable, true)));
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        fail(sprintf("%s\n  '%s' not found in: %s", $message !== '' ? $message : 'Substring missing', $needle, $haystack));
    }
}

function assert_matches(string $pattern, string $subject, string $message = ''): void
{
    if (!preg_match($pattern, $subject)) {
        fail(sprintf("%s\n  %s does not match %s", $message !== '' ? $message : 'Pattern mismatch', var_export($subject, true), $pattern));
    }
}

// ── Runner ────────────────────────────────────────────────────────────────

/**
 * Run every registered test with a freshly rebuilt database.
 * Returns a process exit code.
 */
function harness_run(?string $filter = null, array $sqlFiles = ['/database.sql']): int
{
    $passed = 0;
    $failures = [];

    foreach ($GLOBALS['ecf_tests'] as $case) {
        if ($filter !== null && !str_contains($case['name'], $filter)) {
            continue;
        }
        harness_reset_db($sqlFiles);
        try {
            ($case['fn'])();
            $passed++;
            fwrite(STDOUT, "  \033[32m✓\033[0m {$case['name']}\n");
        } catch (Throwable $e) {
            $failures[] = ['name' => $case['name'], 'error' => $e];
            fwrite(STDOUT, "  \033[31m✗\033[0m {$case['name']}\n");
        }
    }

    fwrite(STDOUT, "\n");
    foreach ($failures as $f) {
        $e = $f['error'];
        $label = $e instanceof EcfAssertionFailed ? 'FAILED' : 'ERROR (' . get_class($e) . ')';
        fwrite(STDOUT, "\033[31m{$label}\033[0m  {$f['name']}\n");
        fwrite(STDOUT, "  " . str_replace("\n", "\n  ", $e->getMessage()) . "\n");
        if (!$e instanceof EcfAssertionFailed) {
            fwrite(STDOUT, "  at {$e->getFile()}:{$e->getLine()}\n");
        }
        fwrite(STDOUT, "\n");
    }

    $total = $passed + count($failures);
    if ($failures) {
        fwrite(STDOUT, sprintf("\033[31m%d of %d failed\033[0m\n", count($failures), $total));
        return 1;
    }
    fwrite(STDOUT, sprintf("\033[32mAll %d tests passed\033[0m\n", $total));
    return 0;
}
