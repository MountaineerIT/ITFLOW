<?php
/*
 * Client profitability costs (functions/mit_profit.php, 26.11.3.4, M057) through the same steps as ITFlow's Client Profitability report:
 * - labor: each agent's hours at their rate on the day (a rate change keeps older time at the old rate; the first rate covers earlier
 *   time), an agent with no rate uses the company default, and with no default either their hours are counted as uncosted;
 * - parts: a billed ticket part's own cost, else the product's cost, on invoices dated in the period (a Draft one left out);
 * - income invoiced (lines before tax less the discount) or paid (ITFlow's payments), profit = net - labor - parts, margin;
 * - break-even hours for flat monthly agreements: the recurring fee less parts / costs a month, over the agents' cost per hour.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitProfitReady') || !mitProfitReady()) {
    echo "  skip - database update M057 not run\n";
    exit(0);
}
[$client_id, $contact_id] = scenarioMakeClient('ZZ Scenario profit ' . date('His'));
$lead = intval($session_user_id);
$tech = intval(scenarioValue("SELECT user_id FROM users WHERE user_email = 'tech@test.test'"));
$old_default = mitSetting('profit_default_cost', '');
$made = ['rates' => [], 'invoices' => [], 'products' => [], 'parts' => [], 'payments' => []];
$today = date('Y-m-d');
$ago10 = date('Y-m-d', strtotime('-10 days'));
$ago20 = date('Y-m-d', strtotime('-20 days'));
$period = ['start' => date('Y-m-d', strtotime('-30 days')), 'end' => $today];

$run = function ($basis) use ($period) {
    $_GET['basis'] = $basis;
    // As ITFlow's report: its rows, its closure, net, then our columns and totals
    $clients = [];
    $client = function ($row) use (&$clients) {
        $id = intval($row['client_id']);
        if (!isset($clients[$id])) {
            $clients[$id] = ['client_id' => $id, 'client' => $row['client_name'], 'abbr' => $row['client_abbreviation'] ?? '',
                'income' => 0.0, 'costs' => 0.0, 'seconds' => 0, 'billable_seconds' => 0, 'tickets' => 0, 'mrr' => 0.0];
        }
        return $id;
    };
    if ($basis === 'paid') {
        global $mysqli;
        $sql = mysqli_query($mysqli, "SELECT client_id, client_name, client_abbreviation, SUM(payment_amount) AS v FROM payments JOIN invoices ON invoice_id = payment_invoice_id
            JOIN clients ON client_id = invoice_client_id WHERE " . reportRangeSql('payment_date', $period) . " GROUP BY client_id, client_name, client_abbreviation");
        while ($row = mysqli_fetch_assoc($sql)) {
            $clients[$client($row)]['income'] = (float) $row['v'];
        }
    }
    mitProfitAdd($clients, $client, $period, true);
    foreach ($clients as &$row) {
        $row['net'] = $row['income'] - $row['costs'];
    }
    unset($row);
    $columns = mitProfitColumns(['client' => [], 'income' => [], 'net' => [], 'mrr' => []], $clients);
    $totals = mitProfitTotals(['income' => array_sum(array_column($clients, 'income'))], $clients);
    return [$clients, $columns, $totals];
};

try {
    // Rates: the lead 50 / h from 20 days ago, 80 / h from today; the tech none; no company default
    mitSetSetting('profit_default_cost', '');
    scenarioCheck('a rate is saved', mitProfitRateSave($lead, $ago20, '50'), '');
    scenarioCheck('a later rate is saved', mitProfitRateSave($lead, $today, '$80.00'), '');
    scenarioCheck('a bad cost is refused', mitProfitRateSave($lead, $today, '-5') !== '', true);
    scenarioCheck('a bad date is refused', mitProfitRateSave($lead, 'soon', '5') !== '', true);
    scenarioCheck('the same day replaces', mitProfitRateSave($lead, $today, '80'), '');
    foreach (scenarioColumn("SELECT rate_id FROM mit_agent_cost_rates WHERE user_id = $lead AND effective_from IN ('$ago20', '$today')") as $id) {
        $made['rates'][] = intval($id);
    }
    scenarioCheck('two rate rows', count($made['rates']), 2);
    $rates = mitProfitRates();
    scenarioCheck('rate 10 days ago = the older one', mitProfitRateOn($rates, $lead, $ago10, null), 50.0);
    scenarioCheck('rate today = the new one', mitProfitRateOn($rates, $lead, $today, null), 80.0);
    scenarioCheck('the first rate covers earlier days', mitProfitRateOn($rates, $lead, '2001-01-01', null), 50.0);
    scenarioCheck('no rate and no default = null', mitProfitRateOn($rates, 999999, $today, null), null);

    // Time: the lead 2 h ten days ago and 1 h today, the tech 30 min today
    $ticket = scenarioMakeTicket($client_id, $contact_id, ['ticket_billable' => 1]);
    $GLOBALS['scenario_tickets'][] = $ticket;
    foreach ([[$lead, "$ago10 10:00:00", '02:00:00'], [$lead, "$today 09:00:00", '01:00:00'], [$tech ?: $lead + 100000, "$today 11:00:00", '00:30:00']] as [$by, $at, $time]) {
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'zz', ticket_reply_type = 'Internal', ticket_reply_by = $by, ticket_reply_ticket_id = $ticket,
            ticket_reply_time_worked = '$time', ticket_reply_created_at = '$at'");
    }

    // Sold: a Sent invoice today - 2 x a product that costs 70 (subtotal 200) and a ticket part line (2 x cost 3, subtotal 10), 10 off; a Draft one left out
    mysqli_query($mysqli, "INSERT INTO products SET product_name = 'ZZ scen profit switch', product_price = 100, product_type = 'product', product_cost = 70,
        product_currency_code = 'USD', product_tax_id = 0, product_category_id = 0");
    $product = $made['products'][] = mysqli_insert_id($mysqli);
    $invoice = function ($status, $discount) use ($mysqli, $client_id, &$made) {
        $n = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(invoice_number), 0) + 1 FROM invoices"))[0]);
        mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = 'INV-', invoice_number = $n, invoice_scope = 'scenario', invoice_date = CURDATE(), invoice_due = CURDATE(),
            invoice_amount = 0, invoice_discount_amount = $discount, invoice_currency_code = 'USD', invoice_status = '$status', invoice_url_key = 'scenario',
            invoice_client_id = $client_id, invoice_category_id = 0");
        return $made['invoices'][] = mysqli_insert_id($mysqli);
    };
    $line = function ($invoice_id, $name, $qty, $price, $product_id) use ($mysqli) {
        $sub = $qty * $price;
        mysqli_query($mysqli, "INSERT INTO invoice_items SET item_name = '$name', item_description = '', item_quantity = $qty, item_price = $price, item_subtotal = $sub,
            item_tax = " . ($sub * 0.1) . ", item_total = " . ($sub * 1.1) . ", item_order = 1, item_tax_id = 0, item_product_id = $product_id, item_invoice_id = $invoice_id");
        return mysqli_insert_id($mysqli);
    };
    $sent = $invoice('Sent', 10);
    $line($sent, 'ZZ scen profit switch', 2, 100, $product);
    $part_line = $line($sent, 'ZZ scen profit cable', 2, 5, 0);
    mysqli_query($mysqli, "INSERT INTO mit_ticket_parts SET ticket_id = $ticket, client_id = $client_id, product_id = 0, asset_id = 0, name = 'ZZ scen profit cable', description = '',
        quantity = 2, unit_cost = 3, unit_price = 5, tax_id = 0, source = 'stock', invoice_id = $sent, invoice_item_id = $part_line, billed_at = NOW(), created_by = $lead");
    $made['parts'][] = mysqli_insert_id($mysqli);
    $draft = $invoice('Draft', 0);
    $line($draft, 'ZZ scen profit switch', 5, 100, $product);
    mysqli_query($mysqli, "INSERT INTO payments SET payment_date = CURDATE(), payment_amount = 50, payment_currency_code = 'USD', payment_method = 'Check', payment_reference = 'zz',
        payment_account_id = 0, payment_invoice_id = $sent");
    $made['payments'][] = mysqli_insert_id($mysqli);

    // Invoiced
    [$clients, $columns, $totals] = $run('invoiced');
    $row = $clients[$client_id] ?? null;
    scenarioCheck('the client is in the report', (bool) $row, true);
    scenarioCheck('invoiced income = lines before tax less the discount (Draft left out)', round($row['income'], 2), 200.0);
    scenarioCheck('labor = 2 h x 50 + 1 h x 80', $row['labor'], 180.0);
    scenarioCheck("the tech's 30 min with no rate is uncosted", $row['uncosted_seconds'], $tech ? 1800 : 1800);
    scenarioCheck('parts = 2 x 70 (product) + 2 x 3 (the part\'s own cost)', $row['parts'], 146.0);
    scenarioCheck('profit = 200 - 180 - 146', $row['profit'], -126.0);
    scenarioCheck('margin = profit / income', $row['margin'], -63.0);
    scenarioCheck('our columns sit after Net', array_slice(array_keys($columns), 0, 7), ['client', 'income', 'net', 'labor', 'parts', 'profit', 'margin']);
    scenarioCheck('totals carry the uncosted time', $totals['uncosted_seconds'] >= 1800, true);

    // A company default covers the tech
    mitSetSetting('profit_default_cost', '40');
    [$clients] = $run('invoiced');
    scenarioCheck('with a default of 40 the tech\'s 30 min costs 20', [$clients[$client_id]['labor'], $clients[$client_id]['uncosted_seconds']], [200.0, 0]);

    // Paid: ITFlow's money in
    [$clients] = $run('paid');
    scenarioCheck('paid basis keeps ITFlow\'s payments as income', round($clients[$client_id]['income'], 2), 50.0);
    scenarioCheck('paid basis: same costs', [$clients[$client_id]['labor'], $clients[$client_id]['parts']], [200.0, 146.0]);

    // Break-even hours for flat monthly agreements, on fixed numbers: a 30-day period, 10 h at 60 / h = 600 labor
    $be = [
        'a' => ['seconds' => 36000, 'labor' => 600.0, 'costs' => 0, 'parts' => 0, 'mrr' => 300],
        'b' => ['seconds' => 36000, 'labor' => 600.0, 'costs' => 0, 'parts' => 0, 'mrr' => 1200],
        'c' => ['seconds' => 3600, 'labor' => 60.0, 'costs' => 0, 'parts' => 0, 'mrr' => 0],
        'd' => ['seconds' => 0, 'labor' => 0.0, 'costs' => 0, 'parts' => 0, 'mrr' => 120],
        'e' => ['seconds' => 0, 'labor' => 0.0, 'costs' => 50, 'parts' => 48.563, 'mrr' => 400],
    ];
    mitProfitBreakEven($be, ['start' => '2026-01-01', 'end' => '2026-01-30']);
    scenarioCheck('hours a month over a 30-day period', $be['a']['hours_month'], 10.15);
    scenarioCheck('$300 / month at 60 / h pays for 5 h - 10 h used is over', [$be['a']['break_even'], $be['a']['over_break_even']], [5.0, true]);
    scenarioCheck('$1,200 / month pays for 20 h - under', [$be['b']['break_even'], $be['b']['over_break_even']], [20.0, false]);
    scenarioCheck('no recurring fee - no break-even', $be['c']['break_even'], null);
    scenarioCheck('no time of their own - the blended rate of everyone (1,260 / 21 h = 60)', $be['d']['break_even'], 2.0);
    scenarioCheck('parts and direct costs a month come off the fee first ((400 - 100) / 60)', $be['e']['break_even'], 5.0);

    // Removing a rate
    scenarioCheck('a rate row is removed', mitProfitRateDelete($made['rates'][1]), $lead);
    scenarioCheck('then today uses the older rate', mitProfitRateOn(mitProfitRates(), $lead, $today, null), 50.0);
} finally {
    unset($_GET['basis']);
    mitSetSetting('profit_default_cost', (string) $old_default);
    $ids = function ($list) {
        return implode(',', array_map('intval', $list)) ?: '0';
    };
    mysqli_query($mysqli, "DELETE FROM mit_agent_cost_rates WHERE rate_id IN (" . $ids($made['rates']) . ")");
    mysqli_query($mysqli, "DELETE FROM payments WHERE payment_id IN (" . $ids($made['payments']) . ")");
    mysqli_query($mysqli, "DELETE FROM mit_ticket_parts WHERE part_id IN (" . $ids($made['parts']) . ")");
    mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id IN (" . $ids($made['invoices']) . ")");
    mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id IN (" . $ids($made['invoices']) . ")");
    mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id IN (" . $ids($made['invoices']) . ")");
    mysqli_query($mysqli, "DELETE FROM products WHERE product_id IN (" . $ids($made['products']) . ")");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
