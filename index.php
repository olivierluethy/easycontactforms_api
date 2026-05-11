<?php
// Front controller: strips the deployment subpath, then dispatches to the
// corresponding endpoint file under api/. Works whether the backend is mounted
// at the domain root, /backend, or /backend/api.

require __DIR__ . '/config/bootstrap.php';

$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = trim($uri, '/');

// Remove the script directory prefix (e.g. "easycontact/backend") so routes are
// matched relative to the API itself.
$scriptDir = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($scriptDir !== '' && strpos($path, $scriptDir) === 0) {
    $path = ltrim(substr($path, strlen($scriptDir)), '/');
}
// Strip optional leading "backend/" or "api/" segments.
$path = preg_replace('#^(backend/)?(api/)?#', '', $path) ?? $path;
$path = trim($path, '/');

$routes = [
    'auth/register'    => 'api/auth/register.php',
    'auth/login'       => 'api/auth/login.php',
    'auth/me'          => 'api/auth/me.php',
    'projects'         => 'api/projects/list.php',
    'projects/delete'  => 'api/projects/delete.php',
    'submissions'      => 'api/submissions/list.php',
    'form/submit'      => 'api/form/submit.php',
    'form/config'      => 'api/form/config.php',
];

if (!isset($routes[$path])) {
    json_error('Route not found: ' . $path, 404);
}

require __DIR__ . '/' . $routes[$path];
