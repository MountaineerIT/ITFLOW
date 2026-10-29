<?php
/*
 * Customer Thermometer (functions/mit_ct.php) against the test install's stand-in, which behaves like the real API: getBlastResults /
 * getComments give at most 200 (the newest) within fromDate / toDate, and sendEmail answers 0 when the account is out of credits.
 * 205 responses over a few days all come back (mitCtFetchRange - a week read in one call would lose 5); a refused send stops the run
 * (the rest wait) and "Send the refused ones again" (mitCtRetryFailed) makes it due again.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitCtReady') || !mitCtReady() || !defined('MIT_CT_API_BASE')) {
    echo "  skip - M036 not run or no Customer Thermometer stand-in\n";
    exit(0);
}
$mock = function ($path) {
    $response = mitHttp('GET', MIT_CT_API_BASE . $path, [], null, 30);
    return json_decode((string) $response['body'], true);
};
if (!$mock('/_reset')) {
    echo "  skip - the stand-in does not answer\n";
    exit(0);
}
$keys = ['ct_api_key', 'ct_enabled', 'ct_thermometer_id', 'ct_list_id', 'ct_boards', 'ct_start', 'ct_last_poll', 'ct_last_run', 'ct_last_result', 'ct_last_sample', 'ct_last_sample_at'];
$saved = [];
foreach ($keys as $key) {
    $saved[$key] = mitSetting($key);
}
$stamp = date('His');
try {
    mitSetSetting('ct_api_key', mitEncryptSecret('ct-key'));
    mitSetSetting('ct_thermometer_id', '101');
    mitSetSetting('ct_list_id', '7');

    echo "205 responses over three days\n";
    // Surveys already sent (no ticket), and their responses: 120 two days ago, 60 yesterday, 25 today
    $blasts = [];
    for ($i = 0; $i < 205; $i++) {
        $blast = 900000 + $i;
        $blasts[] = $blast;
        mysqli_query($mysqli, "INSERT INTO mit_ct_surveys SET ticket_id = NULL, ticket_ref = 'ZZCT-$stamp-$i', client_id = 0, contact_id = 0, tech_user_id = 0,
            tech_name = 'Scenario', email = 'zz-ct@example.invalid', status = 'sent', blast_id = '$blast', sent_at = NOW() - INTERVAL 3 DAY");
        $days = $i < 120 ? 2 : ($i < 180 ? 1 : 0);
        $mock('/_respond?' . http_build_query(['blast' => $blast, 'temperature' => $i % 4 + 1, 'comment' => $i % 50 === 0 ? "Comment $i" : '',
            'date' => date('Y-m-d', strtotime("-$days days")) . ' 10:' . sprintf('%02d', $i % 60) . ':00']));
    }
    $in = implode(',', $blasts);
    $new = mitCtFetchResponses();
    scenarioCheck('every response read', $new, 205);
    scenarioCheck('every survey has its temperature', intval(scenarioValue("SELECT COUNT(*) FROM mit_ct_surveys WHERE blast_id IN ($in) AND temperature = (blast_id - 900000) % 4 + 1")), 205);
    scenarioCheck('comments too (getComments, joined by blast)', scenarioValue("SELECT comment FROM mit_ct_surveys WHERE blast_id = '900100'"), 'Comment 100');
    scenarioCheck('the response day kept', scenarioValue("SELECT DATE(responded_at) FROM mit_ct_surveys WHERE blast_id = '900000'"), date('Y-m-d', strtotime('-2 days')));
    scenarioCheck('read again: nothing new', mitCtFetchResponses(), 0);

    echo "a refused send\n";
    [$client_id, $contact_id] = scenarioMakeClient("ZZ CT $stamp");
    $tickets = [];
    for ($i = 0; $i < 3; $i++) {
        $tickets[] = $t = scenarioMakeTicket($client_id, $contact_id);
        ticketResolve(ticketRow($t));
    }
    $board = mitBoardOf($tickets[0]);
    mitSetSetting('ct_boards', (string) $board);
    mitSetSetting('ct_start', date('Y-m-d'));
    mitSetSetting('ct_enabled', '1');
    mitSetSetting('ct_last_poll', date('Y-m-d H:i:s'));
    $ours = implode(',', $tickets);
    $rows = function ($status) use ($ours) {
        return intval(scenarioValue("SELECT COUNT(*) FROM mit_ct_surveys WHERE ticket_id IN ($ours) AND status = '$status'"));
    };
    $mock('/_credits?on=0');
    $result = mitCtRun();
    scenarioCheck('the run says it was refused', strpos($result, 'refused by Customer Thermometer') !== false, true);
    scenarioCheck('one kept as refused, the run stopped there', [$rows('failed'), $rows('sent')], [1, 0]);
    scenarioCheck('the rest still due', count(array_intersect(array_column(mitCtDueTickets(500), 'ticket_id'), array_map('strval', $tickets))), 2);
    scenarioCheck('counted for the admin page', mitCtFailedCount() >= 1, true);

    $mock('/_credits?on=1');
    scenarioCheck('send the refused ones again', mitCtRetryFailed() >= 1, true);
    mitCtRun();
    scenarioCheck('all three sent', [$rows('sent'), $rows('failed')], [3, 0]);
    $sent = array_column(($mock('/_state') ?: ['sent' => []])['sent'], 'custom2');
    scenarioCheck('... each once', count(array_filter($sent, fn($ref) => in_array($ref, array_map(fn($t) => ticketReference(ticketRow($t)), $tickets), true))), 3);
} finally {
    mysqli_query($mysqli, "DELETE FROM mit_ct_surveys WHERE ticket_ref LIKE 'ZZCT-$stamp-%'");
    foreach ($saved as $key => $value) {
        $value === null ? mitDeleteSetting($key) : mitSetSetting($key, $value);
    }
    $mock('/_reset');
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
