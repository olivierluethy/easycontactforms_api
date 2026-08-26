<?php
// Bootstrap: shared helpers for every API endpoint.
// Provides the PDO singleton, JSON response helpers, CORS headers, request parsing,
// current_user() which validates the bearer token on protected routes, and the
// require_*() resource lookups that enforce ownership.

declare(strict_types=1);

// uuid4() / gen_token() / is_uuid(). Kept in their own file so the CLI
// migration runner can use them without pulling in the header sending below.
require_once __DIR__ . '/ids.php';

// Permissive CORS — the /form/submit endpoint is invoked by browsers on arbitrary
// third-party landing pages, so the API stays open. Tighten in production if desired.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $cfg = require __DIR__ . '/database.php';
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['database'],
        $cfg['charset']
    );
    try {
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Never surface the raw driver message to the client: it leaks the DB
        // username, host and database name (issue #1), which is exactly the
        // information an attacker needs to target the server. Log the detail
        // server-side and return a generic error to the caller.
        error_log('Database connection failed: ' . $e->getMessage());
        json_error('Database connection failed.', 500);
    }
    return $pdo;
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function json_ok($data = null, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function require_method(string $method): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        json_error('Method not allowed.', 405);
    }
}

function bearer_token(): ?string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $auth = $v;
                break;
            }
        }
    }
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return $m[1];
    }
    return null;
}

function current_user(): array
{
    $token = bearer_token();
    if (!$token) {
        json_error('Missing or invalid Authorization header.', 401);
    }
    $stmt = db()->prepare('SELECT id, email, api_token FROM users WHERE api_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) {
        json_error('Invalid token.', 401);
    }
    return $user;
}

// ── Resource lookup with ownership enforcement ──────────────────────────────
//
// Every dashboard endpoint resolves its target through one of these. They take
// the *public* identifier, join all the way up to users.id, and fail with an
// identical 404 whether the row is missing or simply belongs to somebody else.
//
// Returning 404 rather than 403 is deliberate: a 403 would confirm that the
// identifier exists, which is exactly the fact an attacker is fishing for.
// The error string is the same in both cases for the same reason.
//
// Scoping is never left to the caller's WHERE clause — that is the mistake
// these helpers exist to make impossible.

function not_found(): never
{
    json_error('Not found.', 404);
}

/**
 * Resolve a project the authenticated user owns.
 *
 * @return array{id:int, public_id:string, user_id:int, project_name:string, project_token:string,
 *               website_url:?string, logo_url:?string, reply_from_email:?string, created_at:string}
 */
function require_project(array $user, mixed $publicId): array
{
    // Reject anything that isn't a UUID before touching the database. This also
    // means a leftover client sending the old sequential integer id gets the
    // same 404 as any other bad identifier.
    if (!is_uuid($publicId)) {
        not_found();
    }
    $stmt = db()->prepare('SELECT * FROM projects WHERE public_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$publicId, $user['id']]);
    $project = $stmt->fetch();
    if (!$project) {
        not_found();
    }
    $project['id']      = (int)$project['id'];
    $project['user_id'] = (int)$project['user_id'];
    return $project;
}

/**
 * Resolve a form the authenticated user owns, via its project.
 *
 * @return array{id:int, public_id:string, project_id:int, form_name:string, form_token:string,
 *               is_default:int, created_at:string}
 */
function require_form(array $user, mixed $publicId): array
{
    if (!is_uuid($publicId)) {
        not_found();
    }
    $stmt = db()->prepare(
        'SELECT f.* FROM forms f
           JOIN projects p ON p.id = f.project_id
          WHERE f.public_id = ? AND p.user_id = ? LIMIT 1'
    );
    $stmt->execute([$publicId, $user['id']]);
    $form = $stmt->fetch();
    if (!$form) {
        not_found();
    }
    $form['id']         = (int)$form['id'];
    $form['project_id'] = (int)$form['project_id'];
    $form['is_default'] = (int)$form['is_default'];
    return $form;
}

/**
 * Resolve a submission the authenticated user owns, via its project.
 *
 * @return array{id:int, public_id:string, project_id:int, form_id:int, is_read:int,
 *               read_at:?string, ip_address:?string, created_at:string}
 */
function require_submission(array $user, mixed $publicId): array
{
    if (!is_uuid($publicId)) {
        not_found();
    }
    $stmt = db()->prepare(
        'SELECT s.* FROM submissions s
           JOIN projects p ON p.id = s.project_id
          WHERE s.public_id = ? AND p.user_id = ? LIMIT 1'
    );
    $stmt->execute([$publicId, $user['id']]);
    $submission = $stmt->fetch();
    if (!$submission) {
        not_found();
    }
    $submission['id']         = (int)$submission['id'];
    $submission['project_id'] = (int)$submission['project_id'];
    $submission['form_id']    = (int)$submission['form_id'];
    $submission['is_read']    = (int)$submission['is_read'];
    return $submission;
}

/** Read a parameter from the JSON body, falling back to the query string. */
function param(string $key, ?array $body = null): mixed
{
    $body ??= json_body();
    return $body[$key] ?? $_GET[$key] ?? null;
}

function client_ip(): ?string
{
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd !== '') {
        $first = trim(explode(',', $fwd)[0]);
        if ($first !== '') {
            return substr($first, 0, 45);
        }
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    return $remote ? substr($remote, 0, 45) : null;
}
