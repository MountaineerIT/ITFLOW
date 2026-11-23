<?php
/*
 * Client Profitability's labor costs are ITFlow's own since 26.12 (functions/labor_cost.php, built from ours). Ours on top
 * (functions/mit_profit.php):
 * - the report opens on Invoiced income (laborProfitBasis with no ?basis=), Paid still picked by hand;
 * - parts cost of invoice lines billed from ticket parts (no product on the line, so ITFlow's product-cost sum misses them): each billed
 *   part's own cost x quantity (mitProfitPartsAdd, hooked into laborProfitAdd) - on invoices dated in the period, not on a Draft, and a part
 *   whose line does carry a product is not counted twice.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('laborProfitAdd') || !laborCostReady() || !function_exists('mitTicketPartsReady') || !mitTicketPartsReady()) {
    echo "  FAIL ITFlow's labor costs or our ticket parts table are not here\n";
    exit(1);
}
[$client_id, $contact_id] = scenarioMakeClient('ZZ Scenario profit ' . date('His'));
$made = ['invoices' => [], 'products' => []];
$today = date('Y-m-d');
$period = ['start' => date('Y-m-d', strtotime('-30 days')), 'end' => $today];
$invoice = function ($status, $date) use ($mysqli, $client_id, &$made) {
    $n = 910000 + count($made['invoices']) + intval(date('s')) * 50;
    mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = 'ZZP-', invoice_number = $n, invoice_scope = 'scenario', invoice_date = '$date', invoice_due = '$date',
        invoice_amount = 0, invoice_currency_code = 'USD', invoice_status = '$status', invoice_url_key = 'scenario', invoice_client_id = $client_id, invoice_category_id = 0");
    return $made['invoices'][] = mysqli_insert_id($mysqli);
};
$line = function ($invoice_id, $name, $qty, $price, $product_id = 0) use ($mysqli) {
    $total = $qty * $price;
    mysqli_query($mysqli, "INSERT INTO invoice_items SET item_name = '$name', item_quantity = $qty, item_price = $price, item_subtotal = $total, item_total = $total,
        item_invoice_id = $invoice_id, item_product_id = $product_id");
    return mysqli_insert_id($mysqli);
};
$ticket = scenarioMakeTicket($client_id, $contact_id);
$part = function ($invoice_id, $item_id, $qty, $cost, $product_id = 0) use ($mysqli, $ticket, $client_id) {
    mysqli_query($mysqli, "INSERT INTO mit_ticket_parts SET ticket_id = $ticket, client_id = $client_id, product_id = $product_id, name = 'ZZ part', quantity = $qty, unit_cost = $cost,
        unit_price = 99, source = 'stock', invoice_id = $invoice_id, invoice_item_id = $item_id, billed_at = NOW()");
};
$run = function () use ($period, $client_id) {
    $clients = [];
    $client = function ($row) use (&$clients) {
        $id = intval($row['client_id']);
        $clients[$id] = $clients[$id] ?? ['client_id' => $id, 'income' => 0.0, 'costs' => 0.0];
        return $id;
    };
    laborProfitAdd($clients, $client, $period, false);
    return $clients[$client_id] ?? [];
};
try {
    unset($_GET['basis']);
    scenarioCheck('the report opens on Invoiced', laborProfitBasis(), 'invoiced');
    $_GET['basis'] = 'paid';
    scenarioCheck('Paid can still be picked', laborProfitBasis(), 'paid');
    $_GET['basis'] = 'invoiced';

    mysqli_query($mysqli, "INSERT INTO products SET product_name = 'ZZ profit product', product_price = 50, product_cost = 10, product_currency_code = 'USD', product_category_id = 0");
    $product = $made['products'][] = mysqli_insert_id($mysqli);

    $sent = $invoice('Sent', date('Y-m-d', strtotime('-5 days')));
    $line($sent, 'ZZ product line', 2, 50, $product);                        // ITFlow's: 2 x 10 = 20
    $part($sent, $line($sent, 'ZZ parts line', 3, 99), 3, 15);               // ours: 3 x 15 = 45
    $with_product = $line($sent, 'ZZ part from a product', 1, 50, $product); // ITFlow's: 1 x 10 = 10 (the part's 7 not added again)
    $part($sent, $with_product, 1, 7, $product);
    $draft = $invoice('Draft', date('Y-m-d', strtotime('-5 days')));
    $part($draft, $line($draft, 'ZZ draft parts', 1, 99), 1, 500);           // left out
    $old = $invoice('Sent', date('Y-m-d', strtotime('-60 days')));
    $part($old, $line($old, 'ZZ old parts', 1, 99), 1, 300);                 // before the period

    try {
        $row = $run();
    } catch (Throwable $e) {
        scenarioCheck('laborProfitAdd runs with our parts hook (' . $e->getMessage() . ')', false, true);
        $row = [];
    }
    scenarioCheck('parts = products (30) + ticket parts on lines with no product (45)', $row['parts'] ?? null, 75.0);
    scenarioCheck('income invoiced = the Sent invoice\'s lines', $row['income'] ?? null, 447.0);
} finally {
    unset($_GET['basis']);
    $ids = implode(',', $made['invoices']) ?: '0';
    mysqli_query($mysqli, "DELETE FROM mit_ticket_parts WHERE invoice_id IN ($ids)");
    mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id IN ($ids)");
    mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id IN ($ids)");
    foreach ($made['products'] as $id) {
        mysqli_query($mysqli, "DELETE FROM products WHERE product_id = $id");
    }
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
