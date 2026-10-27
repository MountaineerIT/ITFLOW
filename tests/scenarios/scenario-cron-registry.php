<?php
/*
 * Our cron jobs are ITFlow's to run (includes/cron_jobs.php cronJobRegistry - the list cron/cron.php dispatches from): every
 * cron/mit_*.php of ours is in it under its own name, with a schedule ITFlow knows, and every entry of ours points at a script
 * that is there. A merge that drops or breaks one of our entries fails here, not weeks later as a job that never ran.
 */
require __DIR__ . '/bootstrap.php';

require_once getcwd() . '/includes/cron_jobs.php';
$registry = cronJobRegistryByName();
$ours = array_map(function ($path) { return basename($path, '.php'); }, glob(getcwd() . '/cron/mit_*.php'));
scenarioCheck('we have cron jobs to look for', count($ours) > 5, true);
foreach ($ours as $name) {
    $job = $registry[$name] ?? null;
    scenarioCheck("$name is in ITFlow's cron list", $job !== null, true);
    if ($job) {
        scenarioCheck("$name runs its own script", $job['script'] ?? '', "$name.php");
        scenarioCheck("$name has a schedule ITFlow knows", in_array($job['schedule'] ?? '', ['Interval', 'Daily'], true), true);
    }
}
foreach ($registry as $name => $job) {
    if (strpos($name, 'mit_') === 0) {
        scenarioCheck("entry $name points at a script that is there", is_file(getcwd() . '/cron/' . ($job['script'] ?? '')), true);
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
