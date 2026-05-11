<?php
// Router for PHP's built-in dev server (`php -S localhost:8000 router.php`).
// The built-in server ignores .htaccess, so this file replicates the rewrite:
// real files pass through, everything else is dispatched by index.php.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false; // let the built-in server serve the real file as-is
}

require __DIR__ . '/index.php';
