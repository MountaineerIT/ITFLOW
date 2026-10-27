<?php
/* Mountaineer IT - run every scenario (tests/scenarios/scenario-*.php) against the test install: php tests/scenarios/run.php [install]. Exit 1 on a failure. */
$failed = 0;
foreach (glob(__DIR__ . '/scenario-*.php') as $file) {
    echo basename($file) . "\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($argv[1] ?? (getenv('ITFLOW_ROOT') ?: '/var/www/itflow')), $code);
    $failed += $code ? 1 : 0;
}
echo $failed ? "$failed scenario file(s) failed\n" : "All scenarios passed\n";
exit($failed ? 1 : 0);
