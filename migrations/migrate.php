<?php
// Migration runner.
//
//   php migrations/migrate.php           apply every pending migration
//   php migrations/migrate.php --status  list applied / pending, change nothing
//
// Targets whichever database config/database.php resolves to, so set
// ECF_DB_NAME (or edit config/database.local.php) before running it against
// something other than your default.
//
// Migrations are guarded internally and recorded in schema_migrations, so
// running this twice is safe and the second run does nothing.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/ids.php';
require __DIR__ . '/lib/schema.php';

$cfg = require __DIR__ . '/../config/database.php';

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['database'], $cfg['charset']),
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Could not connect to '{$cfg['database']}': {$e->getMessage()}\n");
    exit(1);
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `schema_migrations` (
      `version`    VARCHAR(100) NOT NULL,
      `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`version`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob(__DIR__ . '/[0-9][0-9][0-9]_*.php') ?: [];
sort($files);

$statusOnly = in_array('--status', $argv, true);

echo "Database: {$cfg['database']}\n\n";

$ran = 0;
foreach ($files as $file) {
    $version = basename($file, '.php');

    if (in_array($version, $applied, true)) {
        echo "  [applied] {$version}\n";
        continue;
    }
    if ($statusOnly) {
        echo "  [pending] {$version}\n";
        continue;
    }

    echo "  [running] {$version}\n";
    $migration = require $file;

    $log = static function (string $message): void {
        echo "      {$message}\n";
    };

    try {
        // DDL in MySQL commits implicitly, so a transaction cannot make this
        // atomic. Idempotent guards are what makes a partial run recoverable:
        // fix the cause and run again.
        $migration($pdo, $log);
        $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
        echo "  [done]    {$version}\n";
        $ran++;
    } catch (Throwable $e) {
        fwrite(STDERR, "\n  FAILED {$version}: {$e->getMessage()}\n");
        fwrite(STDERR, "  Nothing was recorded — fix the cause and run again.\n");
        exit(1);
    }
}

echo "\n";
if ($statusOnly) {
    echo "Status only — nothing changed.\n";
} elseif ($ran === 0) {
    echo "Already up to date.\n";
} else {
    echo "Applied {$ran} migration(s).\n";
}
