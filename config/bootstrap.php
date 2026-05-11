<?php
// Bootstrap: shared helpers for every API endpoint.
// Provides the PDO singleton, JSON response helpers, CORS headers, request parsing,
// and current_user() which validates the bearer token on protected routes.

declare(strict_types=1);

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
        json_error('Database connection failed: ' . $e->getMessage(), 500);
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

function gen_token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
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
