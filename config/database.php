<?php
// Database connection settings. Resolved in this order, first match wins:
//
//   1. Environment variables (ECF_DB_HOST, ECF_DB_PORT, ECF_DB_NAME,
//      ECF_DB_USER, ECF_DB_PASS).
//   2. config/database.local.php — an untracked file returning the same array
//      shape. Copy database.local.php.example to get started locally.
//   3. The production defaults below.
//
// The production credentials are the committed defaults on purpose: deploying
// the repo as-is connects without any post-deploy edit. That is a deliberate
// trade — the password is in this repo's history, so treat the repo as
// sensitive, keep it private, and rotate the password if it is ever cloned
// somewhere it should not be.
//
// Local development overrides all of this through config/database.local.php,
// which is gitignored and therefore never overwritten by a deploy.

$defaults = [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'easycontactforms',
    'username' => 'OlivierL',
    'password' => '__REDACTED__',
    'charset'  => 'utf8mb4',
];

$local = [];
$localFile = __DIR__ . '/database.local.php';
if (is_file($localFile)) {
    $loaded = require $localFile;
    if (is_array($loaded)) {
        $local = $loaded;
    }
}

// Environment variables win over the local file so a single deployment can be
// re-pointed (e.g. at the test database) without editing any file on disk.
$env = array_filter([
    'host'     => getenv('ECF_DB_HOST') ?: null,
    'port'     => getenv('ECF_DB_PORT') ? (int)getenv('ECF_DB_PORT') : null,
    'database' => getenv('ECF_DB_NAME') ?: null,
    'username' => getenv('ECF_DB_USER') ?: null,
    // An empty password is legitimate, so distinguish "unset" from "empty".
    'password' => getenv('ECF_DB_PASS') === false ? null : getenv('ECF_DB_PASS'),
], static fn ($v) => $v !== null);

return array_merge($defaults, $local, $env);
