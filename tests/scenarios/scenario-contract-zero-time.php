<?php
/*
 * A billable ticket with no time to bill on a contract with prepaid hours (functions/contract_hours.php contractHoursDraw, 26.12.1.5 - the user
 * found them stuck in To Bill at $0.00): none logged, or minutes that round to 0 h. ITFlow's draw skipped them, so they waited for good. Now a
 * block covering the day it was done covers it with nothing drawn (a use of 0 h marked full) and it leaves To Bill; the contract lists it;
 * a ticket put back stays out of later draws; time added later sends it back to bill; with no block covering its day it stays to bill.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('contractHoursReady') || !contractHoursReady()) {
    echo "  skip - ITFlow's prepaid hours (3.4.4) not here\n";
    exit(0);
}
[$client_id, $contact_id] = scenarioMakeClient('ZZ Zero time Co ' . time());
[$other_id, $other_contact] = scenarioMakeClient('ZZ No contract Co ' . time());
$contract_id = 0;
// Time rounded to the nearest 15 minutes, as in the user's To Bill (read before billingTimeRules keeps it for the request)
$was = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_time_increment, config_time_rounding, config_time_minimum FROM settings WHERE company_id = 1"));
mysqli_query($mysqli, "UPDATE settings SET config_time_increment = 15, config_time_rounding = 'nearest', config_time_minimum = 0 WHERE company_id = 1");
try {
    [$f, $error] = contractFromInput(['name' => 'ZZ Zero time managed', 'type' => 'managed', 'term_months' => 12, 'notice_days' => 30, 'client_id' => $client_id, 'status' => 'Active',
        'start_date' => date('Y-m-01', strtotime('-2 months')), 'end_date' => date('Y-m-d', strtotime('+10 months')), 'monthly_hours' => '10', 'hours_alert' => ''], 'contract');
    scenarioCheck('contract saves', $error, '');
    $contract_id = contractCreate($f);
    contractHoursSync($contract_id);
    $today = date('Y-m-d');
    $make = function ($client, $contact, $subject, $worked) use ($mysqli, $today) {
        $id = scenarioMakeTicket($client, $contact, ['ticket_subject' => $subject, 'ticket_billable' => 1, 'ticket_status' => 4, 'ticket_resolved_at' => "$today 09:00:00"]);
        if ($worked !== null) {
            mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'work', ticket_reply_type = 'Internal', ticket_reply_time_worked = '$worked', ticket_reply_by = 1, ticket_reply_ticket_id = $id");
        }
        return $id;
    };
    $five = $make($client_id, $contact_id, 'ZZ five minutes', '00:05:00');
    $none = $make($client_id, $contact_id, 'ZZ no time', null);
    $real = $make($client_id, $contact_id, 'ZZ real work', '01:00:00');
    $elsewhere = $make($other_id, $other_contact, 'ZZ no contract, no time', '00:05:00');
    scenarioCheck('to start, all four are to bill (5 min rounds to 0 h)', [isset(billingUnbilledTickets($client_id)[$five]), isset(billingUnbilledTickets($client_id)[$none]), billingUnbilledTickets($client_id)[$five]['hours'] ?? null],
        [true, true, 0.0]);

    $draw = contractHoursDraw($client_id, [], 'Scenario');
    $unbilled = billingUnbilledTickets($client_id);
    scenarioCheck('the 5-minute ticket leaves To Bill', isset($unbilled[$five]), false);
    scenarioCheck('the ticket with no time leaves To Bill', isset($unbilled[$none]), false);
    scenarioCheck('the real one is drawn as before (1 h)', [isset($unbilled[$real]), round($draw['hours'], 2)], [false, 1.0]);
    scenarioCheck('... nothing drawn for the zero ones', [floatval(scenarioValue("SELECT SUM(contract_block_use_hours) FROM contract_block_uses WHERE contract_block_use_ticket_id IN ($five, $none)")),
        intval(scenarioValue("SELECT COUNT(*) FROM contract_block_uses WHERE contract_block_use_ticket_id IN ($five, $none) AND contract_block_use_full = 1"))], [0.0, 2]);
    scenarioCheck('their history says covered, nothing drawn', intval(scenarioValue("SELECT COUNT(*) FROM ticket_history WHERE ticket_history_ticket_id = $five AND ticket_history_description LIKE 'Covered by prepaid hours%nothing drawn'")), 1);
    scenarioCheck('the contract lists them', count(array_intersect([$five, $none, $real], array_map('intval', array_column(contractHoursUses($contract_id), 'ticket_id')))), 3);
    $kept = null;
    scenarioCheck('the ticket page sees it covered', [count(contractHoursTicketUses($five, $kept)), $kept], [1, false]);
    scenarioCheck('hours left on the month untouched by them (10 - 1)', round(contractHoursSummary(contractBlocks($contract_id))['left'], 2), 9.0);
    scenarioCheck('a client with no contract keeps its 0 h ticket to bill', isset(billingUnbilledTickets($other_id)[$elsewhere]), true);

    // Put back - in To Bill again, and the next draw leaves it there
    scenarioCheck('put back', contractHoursPutBack($none, 'Scenario'), 0.0);
    contractHoursDraw($client_id, [], 'Scenario');
    scenarioCheck('... stays to bill after the next draw', isset(billingUnbilledTickets($client_id)[$none]), true);
    contractHoursDraw($client_id, [$none], 'Scenario');
    scenarioCheck('... drawn by name, covered again', isset(billingUnbilledTickets($client_id)[$none]), false);

    // Time added later (reopened and worked on): back to bill, then drawn as usual
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'more', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:30:00', ticket_reply_by = 1, ticket_reply_ticket_id = $five");
    contractHoursDraw($client_id, [], 'Scenario');
    scenarioCheck('time added: drawn as usual (0.5 h), out of To Bill', [isset(billingUnbilledTickets($client_id)[$five]),
        floatval(scenarioValue("SELECT SUM(contract_block_use_hours) FROM contract_block_uses WHERE contract_block_use_ticket_id = $five"))], [false, 0.5]);

    // Done before any block - no cover
    $early = scenarioMakeTicket($client_id, $contact_id, ['ticket_subject' => 'ZZ before the contract', 'ticket_billable' => 1, 'ticket_status' => 4,
        'ticket_resolved_at' => date('Y-m-d', strtotime('-2 years')) . ' 09:00:00']);
    contractHoursDraw($client_id, [], 'Scenario');
    scenarioCheck('done before any block: still to bill', isset(billingUnbilledTickets($client_id)[$early]), true);
} finally {
    mysqli_query($mysqli, "UPDATE settings SET config_time_increment = " . intval($was['config_time_increment']) . ", config_time_rounding = '" . escapeSql($was['config_time_rounding']) . "', config_time_minimum = " . intval($was['config_time_minimum']) . " WHERE company_id = 1");
    if ($contract_id) {
        mysqli_query($mysqli, "DELETE u FROM contract_block_uses u JOIN contract_blocks b ON b.contract_block_id = u.contract_block_use_block_id WHERE b.contract_block_contract_id = $contract_id");
        mysqli_query($mysqli, "DELETE FROM contract_blocks WHERE contract_block_contract_id = $contract_id");
        mysqli_query($mysqli, "DELETE FROM contracts WHERE contract_id = $contract_id");
    }
    mysqli_query($mysqli, "DELETE FROM notifications WHERE notification LIKE '%ZZ Zero time Co%'");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
