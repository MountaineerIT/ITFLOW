<?php
/*
 * Deleting a client for good (26.12.1 - ITFlow's handler and the API each had a shorter list and left rows behind): clientDeleteForGood
 * (functions/client.php, used by Clients > Delete and api/v1/clients/delete.php) removes the client's records with what hangs off them
 * - ticket watchers / history / attachments / tasks, invoice lines / payments / history, quote and recurring lines, document versions,
 * DNS records, rack places, links between records, its access rules and SLA choice - and the company's own records that only named the
 * client (an expense) stay with no client. Another client's records are untouched.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('clientDeleteForGood')) {
    echo "  skip - no clientDeleteForGood here\n";
    exit(0);
}

// A row with only the columns that matter - every other NOT NULL column without a default gets a plain value
$columns = [];
$ins = function ($table, array $set) use ($mysqli, &$columns) {
    if (!isset($columns[$table])) {
        $columns[$table] = mysqli_fetch_all(mysqli_query($mysqli, "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'"), MYSQLI_ASSOC);
    }
    if (!$columns[$table]) {
        return 0;
    }
    $set = array_intersect_key($set, array_flip(array_column($columns[$table], 'COLUMN_NAME'))); // names an older / newer table lacks
    foreach ($columns[$table] as $c) {
        if (array_key_exists($c['COLUMN_NAME'], $set) || $c['IS_NULLABLE'] === 'YES' || $c['COLUMN_DEFAULT'] !== null || strpos($c['EXTRA'], 'auto_increment') !== false) {
            continue;
        }
        $set[$c['COLUMN_NAME']] = in_array($c['DATA_TYPE'], ['datetime', 'timestamp'], true) ? date('Y-m-d H:i:s') : ($c['DATA_TYPE'] === 'date' ? date('Y-m-d')
            : (preg_match('/int|decimal|float|double/', $c['DATA_TYPE']) ? 0 : ($c['DATA_TYPE'] === 'enum' ? null : 'zz')));
        if ($set[$c['COLUMN_NAME']] === null) {
            unset($set[$c['COLUMN_NAME']]);
        }
    }
    $parts = [];
    foreach ($set as $k => $v) {
        $parts[] = "`$k` = " . (is_int($v) || is_float($v) ? $v : "'" . escapeSql((string) $v) . "'");
    }
    mysqli_query($mysqli, "INSERT INTO `$table` SET " . implode(', ', $parts));
    return mysqli_insert_id($mysqli);
};
$has = fn($table) => (bool) mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE '$table'"));
$count = fn($sql) => intval(scenarioValue($sql));

$stamp = date('His');
[$client, $contact] = scenarioMakeClient("ZZ Delete me $stamp");
[$other, $other_contact] = scenarioMakeClient("ZZ Keep me $stamp");
$ticket = scenarioMakeTicket($client, $contact);
$other_ticket = scenarioMakeTicket($other, $other_contact);
$made = [];
try {
    // Hanging off the ticket
    $made['ticket_watchers'] = $ins('ticket_watchers', ['watcher_ticket_id' => $ticket, 'watcher_email' => 'zz@example.invalid']);
    $made['ticket_history'] = $ins('ticket_history', ['ticket_history_ticket_id' => $ticket, 'ticket_history_description' => 'zz']);
    $made['ticket_attachments'] = $ins('ticket_attachments', ['ticket_attachment_ticket_id' => $ticket, 'ticket_attachment_name' => 'zz.txt', 'ticket_attachment_reference_name' => 'zz.txt']);
    $made['tasks'] = $ins('tasks', ['task_ticket_id' => $ticket, 'task_name' => 'zz']);
    $made['ticket_replies'] = $ins('ticket_replies', ['ticket_reply_ticket_id' => $ticket, 'ticket_reply' => 'zz', 'ticket_reply_type' => 'Internal', 'ticket_reply_by' => 1]);
    $other_watcher = $ins('ticket_watchers', ['watcher_ticket_id' => $other_ticket, 'watcher_email' => 'zz@example.invalid']);
    // Money
    $invoice = $ins('invoices', ['invoice_client_id' => $client, 'invoice_number' => 900000 + intval($stamp), 'invoice_status' => 'Draft', 'invoice_date' => date('Y-m-d'), 'invoice_due' => date('Y-m-d')]);
    $made['invoice_items'] = $ins('invoice_items', ['item_invoice_id' => $invoice, 'item_name' => 'zz']);
    $made['history'] = $ins('history', ['history_invoice_id' => $invoice, 'history_description' => 'zz', 'history_status' => 'Draft']);
    $quote = $ins('quotes', ['quote_client_id' => $client, 'quote_number' => 900000 + intval($stamp), 'quote_date' => date('Y-m-d')]);
    $quote_item = $ins('quote_items', ['item_quote_id' => $quote, 'item_name' => 'zz']);
    $recurring = $ins('recurring_invoices', ['recurring_invoice_client_id' => $client]);
    $recurring_item = $ins('recurring_invoice_items', ['item_recurring_invoice_id' => $recurring, 'item_name' => 'zz']);
    $expense = $ins('expenses', ['expense_client_id' => $client, 'expense_amount' => 1, 'expense_date' => date('Y-m-d'), 'expense_description' => 'zz']);
    // Documentation
    $document = $ins('documents', ['document_client_id' => $client, 'document_name' => 'zz', 'document_content' => 'zz']);
    $made['document_versions'] = $has('document_versions') ? $ins('document_versions', ['document_version_document_id' => $document, 'document_version_name' => 'zz', 'document_version_content' => 'zz']) : 0;
    $domain = $ins('domains', ['domain_client_id' => $client, 'domain_name' => "zz$stamp.example"]);
    $made['records'] = $has('records') ? $ins('records', ['record_domain_id' => $domain, 'record_name' => 'zz', 'record_type' => 'A']) : 0;
    $asset = $ins('assets', ['asset_client_id' => $client, 'asset_name' => 'zz']);
    $rack = $ins('racks', ['rack_client_id' => $client, 'rack_name' => 'zz']);
    $made['rack_units'] = $has('rack_units') ? $ins('rack_units', ['unit_rack_id' => $rack, 'unit_asset_id' => $asset]) : 0;
    $service = $ins('services', ['service_client_id' => $client, 'service_name' => 'zz']);
    $link = $has('service_assets') ? $ins('service_assets', ['service_id' => $service, 'asset_id' => $asset]) : 0;
    // ITFlow 26.12.1's phone systems: a call of theirs goes with them, their phone system domain stays with no client (as ITFlow's own delete did)
    $domain_row = $has('phone_domains') ? $ins('phone_domains', ['phone_domain_system_id' => 0, 'phone_domain_name' => "zz$stamp.pbx", 'phone_domain_client_id' => $client]) : 0;
    $call = $has('phone_calls') ? $ins('phone_calls', ['phone_call_system_id' => 0, 'phone_call_uuid' => "zz-$stamp", 'phone_call_started_at' => date('Y-m-d H:i:s'), 'phone_call_client_id' => $client]) : 0;
    // Settings for the client
    $ins('user_client_permissions', ['user_id' => 1, 'client_id' => $client, 'permission_type' => 'allow']);

    clientDeleteForGood($client);

    echo "the client and what was only theirs\n";
    scenarioCheck('the client is gone', $count("SELECT COUNT(*) FROM clients WHERE client_id = $client"), 0);
    scenarioCheck('its ticket, contact, invoice, quote, recurring invoice, document, domain, asset, rack, service', $count("SELECT
        (SELECT COUNT(*) FROM tickets WHERE ticket_id = $ticket) + (SELECT COUNT(*) FROM contacts WHERE contact_id = $contact)
        + (SELECT COUNT(*) FROM invoices WHERE invoice_id = $invoice) + (SELECT COUNT(*) FROM quotes WHERE quote_id = $quote)
        + (SELECT COUNT(*) FROM recurring_invoices WHERE recurring_invoice_id = $recurring) + (SELECT COUNT(*) FROM documents WHERE document_id = $document)
        + (SELECT COUNT(*) FROM domains WHERE domain_id = $domain) + (SELECT COUNT(*) FROM assets WHERE asset_id = $asset)
        + (SELECT COUNT(*) FROM racks WHERE rack_id = $rack) + (SELECT COUNT(*) FROM services WHERE service_id = $service)"), 0);
    $pk = ['ticket_watchers' => 'watcher_id', 'ticket_history' => 'ticket_history_id', 'ticket_attachments' => 'ticket_attachment_id', 'tasks' => 'task_id',
        'ticket_replies' => 'ticket_reply_id', 'invoice_items' => 'item_id', 'history' => 'history_id', 'document_versions' => 'document_version_id',
        'records' => 'record_id', 'rack_units' => 'unit_id'];
    foreach ($made as $table => $id) {
        if ($id) {
            scenarioCheck("... and what hung off them: $table", $count("SELECT COUNT(*) FROM `$table` WHERE `{$pk[$table]}` = $id"), 0);
        }
    }
    scenarioCheck('... quote and recurring invoice lines', $count("SELECT (SELECT COUNT(*) FROM quote_items WHERE item_id = $quote_item) + (SELECT COUNT(*) FROM recurring_invoice_items WHERE item_id = $recurring_item)"), 0);
    if ($link) {
        scenarioCheck('... the link between their service and asset', $count("SELECT COUNT(*) FROM service_assets WHERE service_id = $service"), 0);
    }
    scenarioCheck('... the access rules naming them', $count("SELECT COUNT(*) FROM user_client_permissions WHERE client_id = $client"), 0);
    echo "the company's own records stay\n";
    if ($call) {
        scenarioCheck('their call is gone', $count("SELECT COUNT(*) FROM phone_calls WHERE phone_call_id = $call"), 0);
    }
    if ($domain_row) {
        scenarioCheck('their phone system domain stays, linked to no client', scenarioValue("SELECT phone_domain_client_id FROM phone_domains WHERE phone_domain_id = $domain_row"), '0');
    }
    scenarioCheck('the expense is kept, with no client', scenarioValue("SELECT expense_client_id FROM expenses WHERE expense_id = $expense"), '0');
    echo "another client\n";
    scenarioCheck('their ticket and its watcher are untouched', $count("SELECT (SELECT COUNT(*) FROM tickets WHERE ticket_id = $other_ticket) + (SELECT COUNT(*) FROM ticket_watchers WHERE watcher_id = $other_watcher)"), 2);
} finally {
    if (!empty($domain_row)) {
        mysqli_query($mysqli, "DELETE FROM phone_domains WHERE phone_domain_id = " . intval($domain_row));
    }
    if (!empty($expense)) {
        mysqli_query($mysqli, "DELETE FROM expenses WHERE expense_id = " . intval($expense));
    }
    clientDeleteForGood($client);
    clientDeleteForGood($other);
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
