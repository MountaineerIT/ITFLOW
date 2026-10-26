<?php
/*
 * Mountaineer IT - does our data still agree with ITFlow's? (functions/mit_consistency.php) Read only - safe on production.
 *
 *   php scripts/mit_consistency_check.php          every check; exit 1 when one fails
 *   php scripts/mit_consistency_check.php --all    also list every example (not just the first 10 per finding)
 *
 * Run after every upstream merge, before promoting, and whenever something looks off. The cron job mit_consistency runs it daily.
 */

if (php_sapi_name() !== 'cli') {
    die("Run this from the command line.\n");
}
chdir(dirname(__DIR__));
require_once 'config.php';
require_once 'includes/inc_set_timezone.php';
require_once 'functions.php';
require_once 'includes/load_global_settings.php';
require_once 'includes/database_version.php';
require_once 'functions/mit_consistency.php';

$findings = mitConsistencyChecks();
echo 'Consistency check - ' . date('Y-m-d H:i') . ', ITFlow database ' . (defined('CURRENT_DATABASE_VERSION') ? CURRENT_DATABASE_VERSION : '?') . "\n\n";
foreach ($findings as $f) {
    echo ($f['level'] === 'fail' ? 'FAIL' : 'WARN') . "  [{$f['area']}] {$f['text']}" . ($f['count'] ? " ({$f['count']})" : '') . "\n";
    foreach ($f['items'] as $item) {
        echo "        - $item\n";
    }
    if ($f['count'] > count($f['items'])) {
        echo '        ... and ' . ($f['count'] - count($f['items'])) . " more\n";
    }
}
$fails = count(array_filter($findings, function ($f) {
    return $f['level'] === 'fail';
}));
echo ($findings ? "\n" : '') . ($fails ? "$fails check" . ($fails === 1 ? '' : 's') . ' failed.' : ($findings ? 'No failures - ' . count($findings) . ' warning' . (count($findings) === 1 ? '' : 's') . '.' : 'Everything agrees.')) . "\n";
exit($fails ? 1 : 0);
