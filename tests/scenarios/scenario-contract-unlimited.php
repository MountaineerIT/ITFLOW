<?php
/*
 * Unlimited support hours on a contract (functions/mit_contract_unlimited.php, 26.11.8.8): the Unlimited switch stores the big number in
 * ITFlow's own hours each month (and no alert), ITFlow's draw then covers every billable done ticket in full - after hours too, nothing over -
 * and leaves non-billable / open ones alone; it reads "Unlimited" everywhere hours are printed, a past month's block holds nothing "left", the
 * signed contract says "Unlimited support hours each month", switching it off puts a number back, and a template takes the switch too.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitContractUnlimitedInput') || !function_exists('contractHoursReady') || !contractHoursReady()) {
    echo "  skip - unlimited contracts or ITFlow's prepaid hours (3.4.4) not here\n";
    exit(0);
}
[$client_id, $contact_id] = scenarioMakeClient('ZZ Unlimited Co ' . time());
$contract_id = 0;
$template_id = 0;
$month = date('Y-m');
$base = ['name' => 'ZZ Unlimited managed', 'type' => 'managed', 'term_months' => 12, 'notice_days' => 30, 'client_id' => $client_id, 'status' => 'Active',
    'start_date' => date('Y-m-01', strtotime('-2 months')), 'end_date' => date('Y-m-d', strtotime('+10 months')), 'monthly_hours' => '', 'hours_alert' => '5'];
try {
    // A number at or over 1,000 without the switch is still ITFlow's refusal
    [, $error] = contractFromInput(array_merge($base, ['monthly_hours' => '99999']), 'contract');
    scenarioCheck('99999 typed in the box is refused (ITFlow\'s check)', $error !== '', true);

    // The switch
    [$f, $error] = contractFromInput(array_merge($base, ['mit_unlimited_present' => '1', 'mit_unlimited_hours' => '1']), 'contract');
    scenarioCheck('Unlimited saves', $error, '');
    scenarioCheck('... as the big number in ITFlow\'s hours each month, no alert', [$f['monthly_hours'], $f['hours_alert']], [MIT_CONTRACT_UNLIMITED_HOURS, 0.0]);
    $contract_id = contractCreate($f);
    contractHoursSync($contract_id);
    $row = contractRow($contract_id);
    scenarioCheck('stored on the contract', [round(floatval($row['contract_monthly_hours'])), round(floatval($row['contract_hours_alert']))], [99999.0, 0.0]);
    scenarioCheck('this month\'s included block is unlimited', round(floatval(scenarioValue("SELECT contract_block_hours FROM contract_blocks WHERE contract_block_contract_id = $contract_id AND contract_block_month = '$month'"))), 99999.0);

    // Last month's block, with 3 h used - nothing "left" on it once the month is over
    $last = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
    mysqli_query($mysqli, "INSERT INTO contract_blocks SET contract_block_contract_id = $contract_id, contract_block_hours = 99999, contract_block_bought = '$last-01',
        contract_block_expires = '" . date('Y-m-t', strtotime("$last-01")) . "', contract_block_month = '$last', contract_block_note = 'Included in last month'");
    $last_block = mysqli_insert_id($mysqli);
    $old = scenarioMakeTicket($client_id, $contact_id, ['ticket_subject' => 'ZZ last month', 'ticket_billable' => 1, 'ticket_status' => 5,
        'ticket_resolved_at' => "$last-10 10:00:00", 'ticket_closed_at' => "$last-12 10:00:00"]);
    mysqli_query($mysqli, "INSERT INTO contract_block_uses SET contract_block_use_block_id = $last_block, contract_block_use_ticket_id = $old, contract_block_use_hours = 3, contract_block_use_full = 1");

    // This month: a big billable ticket done after hours, a non-billable one, an open billable one
    $today = date('Y-m-d');
    $make = function ($subject, array $set, $worked) use ($client_id, $contact_id, $mysqli) {
        $id = scenarioMakeTicket($client_id, $contact_id, ['ticket_subject' => $subject] + $set);
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'work', ticket_reply_type = 'Internal', ticket_reply_time_worked = '$worked', ticket_reply_by = 1, ticket_reply_ticket_id = $id");
        return $id;
    };
    $big = $make('ZZ big job', ['ticket_billable' => 1, 'ticket_status' => 4, 'ticket_created_at' => "$today 21:30:00", 'ticket_resolved_at' => "$today 23:45:00"], '50:00:00');
    $free = $make('ZZ not billable', ['ticket_billable' => 0, 'ticket_status' => 4, 'ticket_resolved_at' => "$today 09:00:00"], '02:00:00');
    $open = $make('ZZ still open', ['ticket_billable' => 1, 'ticket_status' => 2], '01:00:00');

    $draw = contractHoursDraw($client_id, [], 'Scenario');
    scenarioCheck('the big billable ticket is drawn in full - none over', [round($draw['hours'], 2), $draw['overage']], [50.0, []]);
    scenarioCheck('... and leaves Unbilled Time', isset(billingUnbilledTickets($client_id)[$big]), false);
    scenarioCheck('a non-billable ticket is not drawn', intval(scenarioValue("SELECT COUNT(*) FROM contract_block_uses WHERE contract_block_use_ticket_id = $free")), 0);
    scenarioCheck('an open ticket is not drawn yet', intval(scenarioValue("SELECT COUNT(*) FROM contract_block_uses WHERE contract_block_use_ticket_id = $open")), 0);
    scenarioCheck('no "ran out" notice', intval(scenarioValue("SELECT COUNT(*) FROM notifications WHERE notification LIKE '%ZZ Unlimited Co%ran out of prepaid hours%'")), 0);
    scenarioCheck('the ticket history says it was covered', intval(scenarioValue("SELECT COUNT(*) FROM ticket_history WHERE ticket_history_ticket_id = $big AND ticket_history_description LIKE '50 h drawn from prepaid hours%'")) > 0, true);

    // How it reads
    $blocks = contractBlocks($contract_id);
    $hours = contractHoursSummary($blocks);
    scenarioCheck('hours read "Unlimited"', [contractHoursText($row['contract_monthly_hours']), contractHoursWords($row['contract_monthly_hours']), contractHoursText($hours['left'])],
        ['Unlimited', 'Unlimited hours', 'Unlimited']);
    scenarioCheck('real hours read as before', [contractHoursText(7.5), contractHoursWords(1)], ['7.5 h', '1 hour']);
    scenarioCheck('last month\'s block holds nothing left - none "ran out unused"', [$blocks[$last_block]['left'], $hours['lapsed']], [0.0, 0.0]);
    $use = mitContractUnlimitedUse($blocks);
    scenarioCheck('used this month / last month', [$use['this'], $use['last'], $use['tickets']], [50.0, 3.0, 1]);
    $figures = (string) mitContractUnlimitedAgentFigures($row, $blocks);
    scenarioCheck('the contract page: Unlimited, 50 h used this month on 1 ticket, 3 h last month',
        [strpos($figures, 'Unlimited') !== false, strpos($figures, '50 h used this month on 1 ticket') !== false, strpos($figures, '3 h used last month') !== false], [true, true, true]);
    scenarioCheck('the portal: Unlimited support hours', strpos((string) mitContractUnlimitedPortalFigures($row, $blocks), 'Unlimited <span class="fs-6 fw-normal text-body-secondary">support hours') !== false, true);
    scenarioCheck('a contract with real hours keeps ITFlow\'s card', mitContractUnlimitedAgentFigures(['contract_monthly_hours' => 10] + $row, $blocks), null);
    $doc = contractDocumentHtml($row);
    scenarioCheck('the contract document says "Unlimited support hours each month"', [strpos($doc, 'Unlimited support hours each month') !== false, strpos($doc, '99999') === false, strpos($doc, '99,999') === false], [true, true, true]);
    $left = contractHoursLeftFor([$contract_id]);
    scenarioCheck('the contracts list reads Unlimited left', contractHoursText($left[$contract_id]['left'] ?? 0), 'Unlimited');

    // Switched off: a number of hours again
    [$f, $error] = contractFromInput(array_merge($base, ['mit_unlimited_present' => '1', 'monthly_hours' => '10']), 'contract');
    scenarioCheck('switched off, a number of hours again', [$error, $f['monthly_hours']], ['', 10.0]);
    [$f, $error] = contractFromInput(array_merge($base, ['monthly_hours' => '10']), 'contract');
    scenarioCheck('a form without the switch is left alone', [$error, $f['monthly_hours']], ['', 10.0]);

    // A template takes it too
    [$t, $error] = contractFromInput(['name' => 'ZZ Unlimited template', 'type' => 'managed', 'term_months' => 12, 'notice_days' => 30, 'monthly_hours' => '',
        'mit_unlimited_present' => '1', 'mit_unlimited_hours' => '1'], 'template');
    scenarioCheck('a template can be unlimited', [$error, $t['monthly_hours']], ['', MIT_CONTRACT_UNLIMITED_HOURS]);
} finally {
    if ($contract_id) {
        mysqli_query($mysqli, "DELETE u FROM contract_block_uses u JOIN contract_blocks b ON b.contract_block_id = u.contract_block_use_block_id WHERE b.contract_block_contract_id = $contract_id");
        mysqli_query($mysqli, "DELETE FROM contract_blocks WHERE contract_block_contract_id = $contract_id");
        mysqli_query($mysqli, "DELETE FROM contracts WHERE contract_id = $contract_id");
    }
    mysqli_query($mysqli, "DELETE FROM notifications WHERE notification LIKE '%ZZ Unlimited Co%'");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
