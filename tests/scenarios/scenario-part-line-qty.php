<?php
/*
 * An invoice line billed from ticket parts keeps the parts' quantity (ITFlow 26.11.8 edits a line's quantity where it sits - the
 * stock and the part did not follow): mitPartLineQtyProblem refuses another quantity (the hooks in agent/ajax.php billing_item_set and
 * agent/post/billing.php billing_item_save call it), other lines are left alone, and the consistency check reports a line that already
 * differs.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitPartLineQtyProblem') || !mitTicketPartsReady()) {
    echo "  skip - parts not ready\n";
    exit(0);
}
require_once $root . '/functions/mit_consistency.php';
$stamp = date('His');
[$client_id, $contact_id] = scenarioMakeClient("ZZ Part line $stamp");
$ticket = scenarioMakeTicket($client_id, $contact_id);
$invoice = 0;
try {
    $n = intval(scenarioValue("SELECT COALESCE(MAX(invoice_number), 0) + 1 FROM invoices"));
    mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = 'INV-', invoice_number = $n, invoice_scope = 'scenario', invoice_date = CURDATE(), invoice_due = CURDATE(),
        invoice_amount = 0, invoice_currency_code = 'USD', invoice_status = 'Draft', invoice_url_key = 'scenario', invoice_client_id = $client_id, invoice_category_id = 0");
    $invoice = mysqli_insert_id($mysqli);
    $line = function ($name, $qty) use ($mysqli, $invoice) {
        mysqli_query($mysqli, "INSERT INTO invoice_items SET item_name = '$name', item_description = '', item_quantity = $qty, item_price = 5, item_subtotal = " . ($qty * 5) . ",
            item_tax = 0, item_total = " . ($qty * 5) . ", item_order = 1, item_tax_id = 0, item_product_id = 0, item_invoice_id = $invoice");
        return mysqli_insert_id($mysqli);
    };
    $part_line = $line('ZZ part line cable', 3);
    $plain = $line('ZZ part line labour', 1);
    foreach ([2, 1] as $q) {
        mysqli_query($mysqli, "INSERT INTO mit_ticket_parts SET ticket_id = $ticket, client_id = $client_id, product_id = 0, asset_id = 0, name = 'ZZ part line cable', description = '',
            quantity = $q, unit_cost = 3, unit_price = 5, tax_id = 0, source = 'stock', invoice_id = $invoice, invoice_item_id = $part_line, billed_at = NOW(), created_by = 1");
    }

    echo "the quantity of a parts line\n";
    scenarioCheck('the same quantity (3 = 2 + 1) is fine', mitPartLineQtyProblem('invoice', $part_line, '3'), '');
    scenarioCheck('... typed as 3.00', mitPartLineQtyProblem('invoice', $part_line, '3.00'), '');
    scenarioCheck('another quantity is refused', strpos(mitPartLineQtyProblem('invoice', $part_line, '5'), 'billed from parts') !== false, true);
    scenarioCheck('a line with no parts is left alone', mitPartLineQtyProblem('invoice', $plain, '9'), '');
    scenarioCheck('a new line (no id) is left alone', mitPartLineQtyProblem('invoice', 0, '9'), '');
    scenarioCheck('quotes are left alone', mitPartLineQtyProblem('quote', $part_line, '9'), '');

    echo "the consistency check\n";
    $parts_findings = function () {
        $out = [];
        mitConsistencyParts(function ($area, $level, $text, array $items = []) use (&$out) {
            foreach ($items as $i) {
                $out[] = $i;
            }
        });
        return implode("\n", $out);
    };
    scenarioCheck('in step: not reported', strpos($parts_findings(), "line $part_line ") === false, true);
    mysqli_query($mysqli, "UPDATE invoice_items SET item_quantity = 5 WHERE item_id = $part_line");
    scenarioCheck('a line that differs from its parts is reported', strpos($parts_findings(), "line $part_line ZZ part line cable: line 5, parts 3") !== false, true);
} finally {
    mysqli_query($mysqli, "DELETE FROM mit_ticket_parts WHERE ticket_id = $ticket");
    if ($invoice) {
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = $invoice");
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = $invoice");
    }
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
