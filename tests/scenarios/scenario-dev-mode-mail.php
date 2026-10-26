<?php
/*
 * Development mode end to end: switched on in config.php (as Admin > Backup does), a queued email to a real-looking address goes
 * out through the mail queue job to the test inbox only, the real recipient named in the subject; with no test inbox nothing is
 * sent; integrations refuse writes (mitDevAllows / mitHttp) and Stripe refuses calls. Reads what the Microsoft 365 stand-in
 * (/srv/mockms/state.json) received. Puts config.php back as it was.
 */
require __DIR__ . '/bootstrap.php';
$state_file = '/srv/mockms/state.json';
if (!is_readable($state_file) || !mitM365GraphSendReady()) {
    echo "  skip - no Microsoft 365 stand-in sending mail here\n";
    exit(0);
}
$config = dirname(__DIR__) . '/config.php';
$config = getcwd() . '/config.php';
$config_before = file_get_contents($config);
$sent = function () use ($state_file) {
    $s = json_decode((string) file_get_contents($state_file), true);
    return $s['sent'] ?? [];
};
$tag = 'ZZ dev ' . bin2hex(random_bytes(4));
// Other queued mail is put aside for the run (the job sends everything queued)
mysqli_query($mysqli, "UPDATE email_queue SET email_status = 9 WHERE email_status = 0");
$queue = function ($subject) use ($mysqli) {
    addToMailQueue([['from' => 'service@mountaineerit.test', 'from_name' => 'ZZ', 'recipient' => 'real.customer@example.invalid', 'recipient_name' => 'Real Customer',
        'subject' => $subject, 'body' => 'zz']]);
};
$run = function () {
    return shell_exec('cd ' . escapeshellarg(getcwd() . '/cron') . ' && sudo -u www-data php mail_queue.php --no-mx-validation 2>&1');
};
try {
    // On, with a test inbox
    scenarioCheck('switched on in config.php', mitConfigSet('config_mit_dev_mode', 1) . mitConfigSet('config_mit_dev_mail_to', 'test-inbox@example.invalid'), '');
    $queue("$tag one");
    $out = $run();
    $mine = array_values(array_filter($sent(), function ($m) use ($tag) { return strpos($m, "$tag one") !== false; }));
    scenarioCheck('sent once through Graph', count($mine), 1);
    $mime = $mine[0] ?? '';
    scenarioCheck('to the test inbox', (bool) preg_match('/^To: .*test-inbox@example\.invalid/mi', $mime), true);
    scenarioCheck('not to the real recipient', (bool) preg_match('/^(To|Cc|Bcc): .*real\.customer@example\.invalid/mi', $mime), false);
    scenarioCheck('real recipient named in the subject', (bool) preg_match('/^Subject: .*DEV to real\.customer@example\.invalid/mi', $mime), true);
    scenarioCheck('queue row marked sent', intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT email_status FROM email_queue WHERE email_subject = '$tag one'"))[0] ?? -1), 3);

    // On, no test inbox: nothing goes out
    mitConfigSet('config_mit_dev_mail_to', '');
    $queue("$tag two");
    $run();
    scenarioCheck('no test inbox: nothing sent', count(array_filter($sent(), function ($m) use ($tag) { return strpos($m, "$tag two") !== false; })), 0);

    // Integrations: writes refused, reads and sign-in tokens allowed
    $GLOBALS['config_mit_dev_mode'] = 1;
    scenarioCheck('POST to an API refused', mitDevAllows('POST', 'https://api.example.com/v2/devices'), false);
    scenarioCheck('PATCH refused', mitDevAllows('PATCH', 'https://graph.microsoft.com/v1.0/me/events/1'), false);
    scenarioCheck('GET allowed', mitDevAllows('GET', 'https://graph.microsoft.com/v1.0/me/calendarView'), true);
    scenarioCheck('token POST allowed', mitDevAllows('POST', 'https://login.microsoftonline.com/x/oauth2/v2.0/token'), true);
    if (is_file(getcwd() . '/includes/stripe_init.php')) {
        require_once getcwd() . '/includes/stripe_init.php'; // ITFlow's own Stripe start, with our hook
        $blocked = false;
        try {
            \Stripe\Stripe::setApiKey('sk_test_zz');
            \Stripe\Customer::create(['email' => 'zz@example.invalid']);
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            $blocked = strpos($e->getMessage(), 'Development mode') !== false;
        } catch (Throwable $e) {
            $blocked = false;
        }
        scenarioCheck('Stripe call refused', $blocked, true);
    }
    // Off again: mail goes to the real recipient
    mitConfigSet('config_mit_dev_mode', 0);
    $queue("$tag three");
    $run();
    $mine = array_values(array_filter($sent(), function ($m) use ($tag) { return strpos($m, "$tag three") !== false; }));
    scenarioCheck('off: to the real recipient', (bool) preg_match('/^To: .*real\.customer@example\.invalid/mi', $mine[0] ?? ''), true);
} finally {
    file_put_contents($config, $config_before, LOCK_EX);
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '" . escapeSql($tag) . "%'");
    mysqli_query($mysqli, "UPDATE email_queue SET email_status = 0 WHERE email_status = 9");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
