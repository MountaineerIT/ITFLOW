<?php
/*
 * ITFlow 3.5.0's contract terms against ours: a client's hourly rate and net terms come from its contract in force, else the defaults
 * (functions/contract_billing.php). Parts billed onto a new invoice (mitPartsBill - the To Bill page and the ticket's Bill parts) are due on
 * the contract's net terms; To Bill (mitToBillMerge) prices the client's time at the contract's rate and adds the parts beside it. A client
 * with no contract gets the defaults.
 */
require __DIR__ . '/bootstrap.php';

$made = ['contracts' => [], 'parts' => [], 'invoices' => []];
try {
    [$client_id, $contact_id] = scenarioMakeClient('ZZ scenario contract terms');
    [$plain_id] = scenarioMakeClient('ZZ scenario default terms');
    $start = date('Y-m-d', strtotime('-10 days'));
    mysqli_query($mysqli, "INSERT INTO contracts SET contract_name = 'ZZ scenario terms', contract_status = 'Active', contract_type = 'Fully Managed', contract_client_id = $client_id,
        contract_rate_standard = 150, contract_rate_after_hours = NULL, contract_net_terms = 15, contract_start_date = '$start', contract_term_months = 12, contract_auto_renew = 1,
        contract_notice_days = 0, contract_sla_low_id = 0, contract_sla_medium_id = 0, contract_sla_high_id = 0, contract_sla_urgent_id = 0, contract_template_id = 0,
        contract_contact_id = $contact_id, contract_owner_id = 0, contract_created_by = 1, contract_monthly_hours = 0, contract_hours_alert = 0, contract_created_at = NOW()");
    $made['contracts'][] = mysqli_insert_id($mysqli);
    contractTermsContracts($client_id, true);

    scenarioCheck("the contract's rate", floatval(contractClientRate($client_id)['rate']), 150.0);
    scenarioCheck("the contract's net terms", intval(contractNetTerms($client_id, date('Y-m-d'))), 15);
    scenarioCheck('no contract: the default net terms', intval(contractNetTerms($plain_id, date('Y-m-d'))), intval(billingDefaults()['net_terms']));

    // A resolved, billable ticket with an hour on it and two parts
    $ticket_id = scenarioMakeTicket($client_id, $contact_id, ['ticket_billable' => 1, 'ticket_status' => 4]);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() - INTERVAL 1 DAY, ticket_created_at = CONCAT(CURDATE() - INTERVAL 2 DAY, ' 10:00:00') WHERE ticket_id = $ticket_id");
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Done', ticket_reply_type = 'Internal', ticket_reply_time_worked = '01:00:00', ticket_reply_by = 1,
        ticket_reply_ticket_id = $ticket_id, ticket_reply_created_at = NOW() - INTERVAL 1 DAY");
    foreach ([['ZZ scenario cable', 2, 50]] as [$name, $qty, $price]) {
        mysqli_query($mysqli, "INSERT INTO mit_ticket_parts SET ticket_id = $ticket_id, client_id = $client_id, product_id = 0, asset_id = 0, name = '$name', description = '',
            quantity = $qty, unit_cost = 0, unit_price = $price, tax_id = 0, source = 'other', proc_item_id = 0, invoice_id = 0, invoice_item_id = 0, created_by = 1");
        $made['parts'][] = mysqli_insert_id($mysqli);
    }

    // To Bill: the time at the contract's rate, the parts beside it
    $rows = mitToBillMerge(billingUnbilledByClient($client_id), $client_id);
    $row = $rows[$client_id] ?? null;
    scenarioCheck('To Bill lists the client', $row !== null, true);
    if ($row) {
        scenarioCheck('with its parts', intval($row['mit_parts'] ?? 0), 1);
        scenarioCheck("the parts' value", floatval($row['mit_part_value'] ?? 0), 100.0);
    }
    scenarioCheck("the ticket's hour at the contract's rate", floatval(contractTicketRate(ticketRow($ticket_id))['rate']), 150.0);

    // Billing the parts onto a new invoice: due on the contract's terms
    $parts = mitTicketPartsWhere('parts.part_id IN (' . implode(',', $made['parts']) . ')');
    $billed = mitPartsBill($client_id, $parts, [], 0, ['date' => date('Y-m-d'), 'by' => 'Scenario', 'scope' => 'scenario']);
    scenarioCheck('billed onto a new invoice', intval($billed['invoice_id']) > 0, true);
    if (intval($billed['invoice_id'])) {
        $made['invoices'][] = intval($billed['invoice_id']);
        $invoice = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_date, invoice_due, invoice_amount FROM invoices WHERE invoice_id = " . intval($billed['invoice_id'])));
        scenarioCheck('due on the contract\'s 15 days', intval(round((strtotime($invoice['invoice_due']) - strtotime($invoice['invoice_date'])) / 86400)), 15);
        scenarioCheck('the amount is the parts', floatval($invoice['invoice_amount']), 100.0);
    }
} finally {
    foreach ($made['invoices'] as $id) {
        mysqli_query($mysqli, "UPDATE mit_ticket_parts SET invoice_id = 0, invoice_item_id = 0 WHERE invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = " . intval($id));
    }
    foreach ($made['parts'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_ticket_parts WHERE part_id = " . intval($id));
    }
    foreach ($made['contracts'] as $id) {
        mysqli_query($mysqli, "DELETE FROM contracts WHERE contract_id = " . intval($id));
    }
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
