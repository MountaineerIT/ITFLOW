<?php
/*
 * Cron health: a job that fails shows in mitCronProblems, /mit_health.php answers 503 naming it, the watcher job emails the admins
 * once (not again on the next run), and emails again when it is fixed. Puts the job's status and the watcher's state back.
 */
require __DIR__ . '/bootstrap.php';
$job = 'mit_project_tickets';
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT cron_job_last_status, cron_job_last_error, cron_job_last_error_at FROM cron_jobs WHERE cron_job_name = '$job'"));
if (!$row) {
    echo "  skip - no $job job\n";
    exit(0);
}
$state_before = mitSetting('cron_alert_state', '');
$mails = function () use ($mysqli) {
    return mysqli_fetch_all(mysqli_query($mysqli, "SELECT email_id, email_recipient, email_subject, email_content FROM email_queue WHERE email_subject LIKE 'ITFlow cron%' AND email_id > " . intval($GLOBALS['zz_from'] ?? 0)), MYSQLI_ASSOC);
};
$GLOBALS['zz_from'] = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(email_id), 0) FROM email_queue"))[0]);
$admins = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM users JOIN user_roles ON role_id = user_role_id WHERE role_is_admin = 1 AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL"))[0]);
// Each step in its own run (mitCronProblems is read once per request), as cron runs it
$watch = function () {
    return shell_exec('cd ' . escapeshellarg(getcwd() . '/cron') . ' && sudo -u www-data php mit_cron_watch.php 2>&1');
};
try {
    $watch(); // settle what is there now
    $GLOBALS['zz_from'] = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(email_id), 0) FROM email_queue"))[0]);
    mysqli_query($mysqli, "UPDATE cron_jobs SET cron_job_last_status = 'Failed', cron_job_last_error = 'ZZ scenario failure', cron_job_last_error_at = NOW() WHERE cron_job_name = '$job'");
    $key = mitCronHealthKey();
    $out = shell_exec('curl -s -w "\n%{http_code}" -H "Host: ' . $config_base_url . '" ' . escapeshellarg("http://127.0.0.1/mit_health.php?key=$key"));
    scenarioCheck('health address 503', substr(trim($out), -3), '503');
    scenarioCheck('health address names the job', stripos($out, 'Project') !== false || stripos($out, $job) !== false, true);
    $watch();
    $m = $mails();
    scenarioCheck('watcher: one email per admin', count($m), $admins);
    scenarioCheck('... about the failure', $m && strpos($m[0]['email_content'], 'ZZ scenario failure') !== false || ($m && stripos($m[0]['email_content'], 'project') !== false), true);
    $watch();
    scenarioCheck('watcher: not again on the next run', count($mails()), $admins);
    mysqli_query($mysqli, "UPDATE cron_jobs SET cron_job_last_status = 'Completed' WHERE cron_job_name = '$job'");
    $watch();
    $m = $mails();
    scenarioCheck('watcher: fixed - emailed again', count($m), 2 * $admins);
    scenarioCheck('... saying it is fixed', $m && (strpos(end($m)['email_content'], 'Fixed') !== false || strpos(end($m)['email_content'], 'All clear') !== false), true);
} finally {
    mysqli_query($mysqli, "UPDATE cron_jobs SET cron_job_last_status = '" . escapeSql($row['cron_job_last_status']) . "', cron_job_last_error = " . ($row['cron_job_last_error'] === null ? 'NULL' : "'" . escapeSql($row['cron_job_last_error']) . "'") . ",
        cron_job_last_error_at = " . ($row['cron_job_last_error_at'] === null ? 'NULL' : "'" . $row['cron_job_last_error_at'] . "'") . " WHERE cron_job_name = '$job'");
    mitSetSetting('cron_alert_state', $state_before);
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE 'ITFlow cron%' AND email_id > " . intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT 0"))[0]) . " AND email_status = 0 AND email_queued_at > NOW() - INTERVAL 10 MINUTE");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
