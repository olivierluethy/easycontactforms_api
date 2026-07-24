<?php
// Test entry point.
//
//   php tests/run.php              run everything
//   php tests/run.php projects     run only cases whose name contains "projects"
//
// Always targets ECF_DB_NAME (default: easycontactforms_test) and refuses to
// run against a database whose name doesn't end in _test.

declare(strict_types=1);

require __DIR__ . '/lib/harness.php';

harness_configure_env();
harness_start_server();

foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $case) {
    require $case;
}

exit(harness_run($argv[1] ?? null));
