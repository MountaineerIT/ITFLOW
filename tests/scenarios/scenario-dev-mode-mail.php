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
    // Log forwarding: never from a copy of production (logForwardOn / logForwardSend)
    $forward = ['enabled' => true, 'host' => 'logs.example.invalid', 'port' => 514, 'protocol' => 'udp', 'tls_verify' => false, 'audit' => true, 'app_level' => '',
        'format' => 'rfc5424', 'facility' => 16];
    scenarioCheck('log forwarding is off', logForwardOn($forward), false);
    $why = '';
    scenarioCheck('a log line is not sent', logForwardSend($forward, ['zz dev line'], $why), false);
    scenarioCheck('... and says why', strpos($why, 'Development mode') !== false, true);

    // Custom actions: ITFlow's handler file is not run
    $handler = getcwd() . '/functions/custom/custom_action_handler.php';
    $marker = sys_get_temp_dir() . "/zz-dev-custom-action-$tag";
    if (!file_exists($handler)) {
        @mkdir(dirname($handler), 0755, true);
        file_put_contents($handler, '<?php file_put_contents(' . var_export($marker, true) . ', "ran");');
        try {
            triggerCustomAction('ticket_create', 1);
            scenarioCheck('custom actions are not run', file_exists($marker), false);
        } finally {
            unlink($handler);
            @rmdir(dirname($handler));
            @unlink($marker);
        }
    }

    // The mail parser and AI triage jobs stop at once: a client's email stays unread, a new emailed ticket is not triaged
    $mock_mail = '/srv/mockms/state.json';
    if (is_writable($mock_mail) && function_exists('mitM365GraphMailboxReady') && mitM365GraphMailboxReady()) {
        $edit = function (callable $change) use ($mock_mail) {
            $lock = fopen("$mock_mail.lock", 'c');
            flock($lock, LOCK_EX);
            $s = json_decode((string) file_get_contents($mock_mail), true);
            if (is_array($s)) {
                file_put_contents("$mock_mail.tmp", json_encode($change($s), JSON_PRETTY_PRINT));
                rename("$mock_mail.tmp", $mock_mail);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        };
        $id = 'M-ZZDEV-' . bin2hex(random_bytes(4));
        $edit(function ($s) use ($id, $tag) {
            $s['mail'][] = ['id' => $id, 'folder' => 'inbox', 'isRead' => false, 'flag' => 'notFlagged', 'received' => gmdate('Y-m-d\TH:i:s\Z'),
                'mime' => "From: Zed <zz-dev@example.invalid>\r\nTo: service@mountaineerit.test\r\nSubject: $tag parser\r\nDate: " . date('r')
                    . "\r\nMessage-ID: <$id@zz>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain\r\n\r\nhello\r\n"];
            return $s;
        });
        $out = (string) shell_exec('cd ' . escapeshellarg(getcwd() . '/cron') . ' && sudo -u www-data php ticket_email_parser.php 2>&1');
        $unread = false;
        foreach (json_decode((string) file_get_contents($mock_mail), true)['mail'] ?? [] as $m) {
            if ($m['id'] === $id) {
                $unread = empty($m['isRead']);
            }
        }
        // (development mode also stops the parser marking mail read, so what counts is that no ticket was made from it)
        $made = scenarioColumn("SELECT ticket_id FROM tickets WHERE ticket_subject = '" . escapeSql("$tag parser") . "'");
        foreach ($made as $made_id) {
            $GLOBALS['scenario_tickets'][] = intval($made_id);
        }
        scenarioCheck("the mail parser takes nothing in ($out)", count($made), 0);
        scenarioCheck('... and the email is still unread', $unread, true);
        $edit(function ($s) use ($id) {
            $s['mail'] = array_values(array_filter($s['mail'] ?? [], function ($m) use ($id) { return $m['id'] !== $id; }));
            return $s;
        });
    }
    $ai_mock = 'http://127.0.0.1/ai-mock/index.php';
    $triage_before = scenarioValue("SELECT config_ticket_ai_triage FROM settings WHERE company_id = 1");
    mysqli_query($mysqli, "INSERT INTO ai_providers SET ai_provider_name = 'ZZ dev stand-in $tag', ai_provider_kind = 'custom', ai_provider_api_url = '$ai_mock/v1/chat/completions', ai_provider_api_key = 'mock', ai_provider_redact = 1");
    $provider_id = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO ai_models SET ai_model_name = 'mock-chat', ai_model_use_case = 'Tickets', ai_model_ai_provider_id = $provider_id");
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_ai_triage = 1 WHERE company_id = 1");
    [$client_id, $contact_id] = scenarioMakeClient("$tag triage");
    $ticket_id = scenarioMakeTicket($client_id, $contact_id, ['ticket_source' => 'Email', 'ticket_subject' => "$tag triage"]);
    try {
        $out = (string) shell_exec('cd ' . escapeshellarg(getcwd() . '/cron') . ' && sudo -u www-data php ai_triage.php 2>&1');
        scenarioCheck("AI triage leaves a new ticket alone ($out)", scenarioValue("SELECT COUNT(*) FROM ai_ticket_triage WHERE ai_triage_ticket_id = $ticket_id"), '0');
        // Control: with development mode off the same job does take it (triaged, or failed against the stand-in - either way a row)
        mitConfigSet('config_mit_dev_mode', 0);
        shell_exec('cd ' . escapeshellarg(getcwd() . '/cron') . ' && sudo -u www-data php ai_triage.php 2>&1');
        scenarioCheck('control: off, the job takes it', scenarioValue("SELECT COUNT(*) FROM ai_ticket_triage WHERE ai_triage_ticket_id = $ticket_id"), '1');
    } finally {
        mysqli_query($mysqli, "DELETE FROM ai_ticket_triage WHERE ai_triage_ticket_id = $ticket_id");
        mysqli_query($mysqli, "DELETE FROM ai_models WHERE ai_model_ai_provider_id = $provider_id");
        mysqli_query($mysqli, "DELETE FROM ai_providers WHERE ai_provider_id = $provider_id");
        mysqli_query($mysqli, "UPDATE settings SET config_ticket_ai_triage = " . intval($triage_before) . " WHERE company_id = 1");
        scenarioCleanup();
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
