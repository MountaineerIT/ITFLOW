<?php

/*
 * Mountaineer IT - health address for an outside uptime monitor (functions/mit_cron_health.php).
 * GET /mit_health.php?key=<cron_health_key> answers 200 "OK" when cron is running and every
 * switched-on job is keeping up, else 503 with one line per problem (no error details). Point a
 * monitor (Uptime Kuma, UptimeRobot...) at it every 5 minutes: it is the only thing that can tell
 * anyone cron itself has stopped. The key is on Admin > Maintenance > Cron.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/inc_set_timezone.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$key = (string) mitSetting('cron_health_key', '');
// The key in an X-Health-Key header keeps it out of web server logs; ?key= still works for monitors that cannot send headers
$given = (string) ($_SERVER['HTTP_X_HEALTH_KEY'] ?? ($_GET['key'] ?? ''));
if ($key === '' || !hash_equals($key, $given)) {
    http_response_code(403);
    exit("Forbidden\n");
}

try {
    $problems = mitCronProblems();
} catch (Throwable $e) {
    http_response_code(503);
    exit("PROBLEM\n- The health check could not read the database\n");
}

if (!$problems) {
    exit("OK\n");
}
http_response_code(503);
echo "PROBLEM\n";
foreach ($problems as $problem) {
    echo "- {$problem['label']}: {$problem['kind']}\n";
}
