<?php
/*
 * The daily purchasing tidy-up (mitProcTidy, cron/mit_procurement_tidy.php) clears our links to invoices / invoice lines that were
 * deleted (Nov 24 - production's first consistency check on 26.12.1.1 warned of 2 such links in mit_order_item_links and the old
 * mit_procurement_items, carried over by M068). A link to an invoice that is still there is left alone, and the consistency check's
 * Leftovers warning no longer names them.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitProcTidy') || !mitProcReady() || !mitTicketPartsReady()) {
    echo "  skip - purchasing is not set up here\n";
    exit(0);
}
$missing = intval(scenarioValue("SELECT COALESCE(MAX(invoice_id), 0) + 100000 FROM invoices"));
$missing_line = intval(scenarioValue("SELECT COALESCE(MAX(item_id), 0) + 100000 FROM invoice_items"));
// An invoice with one line that stays
$real = intval(billingInvoiceCreate(scenarioClient(), date('Y-m-d'), 0, 'ZZ tidy links', 'Scenario'));
$error = '';
$real_line = intval(billingItemSave('invoice', $real, ['name' => 'ZZ tidy links line', 'description' => '', 'qty' => '1', 'price' => '1', 'tax_id' => 0], 0, $error));
require_once 'functions/mit_consistency.php';
// Three items to order, already cancelled (never on To Order) - our links hang off them (foreign key)
$items = [];
for ($i = 0; $i < 3; $i++) {
    mysqli_query($mysqli, "INSERT INTO order_items SET order_item_name = 'ZZ tidy links', order_item_quantity = 1, order_item_status = 'cancelled'");
    $items[] = intval(mysqli_insert_id($mysqli));
}
$old = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_procurement_items'")) > 0;
$old_id = 0;
try {
    mysqli_query($mysqli, "INSERT INTO mit_order_item_links SET order_item_id = {$items[0]}, source = 'invoice', invoice_id = $missing, invoice_item_id = $missing_line");
    mysqli_query($mysqli, "INSERT INTO mit_order_item_links SET order_item_id = {$items[1]}, source = 'invoice', invoice_id = $real, invoice_item_id = $missing_line");
    if ($real_line) {
        mysqli_query($mysqli, "INSERT INTO mit_order_item_links SET order_item_id = {$items[2]}, source = 'invoice', invoice_id = $real, invoice_item_id = $real_line");
    }
    if ($old) {
        mysqli_query($mysqli, "INSERT INTO mit_procurement_items SET name = 'ZZ tidy links', quantity = 1, source = 'invoice', status = 'cancelled', invoice_id = $missing, invoice_item_id = $missing_line");
        $old_id = mysqli_insert_id($mysqli);
    }
    $link = function ($id) {
        return array_map('intval', array_values(mysqli_fetch_assoc(mysqli_query($GLOBALS['mysqli'], "SELECT invoice_id, invoice_item_id FROM mit_order_item_links WHERE order_item_id = $id")) ?: []));
    };

    $log = mitProcTidy();
    scenarioCheck('the tidy-up says what it cleared', (bool) preg_grep('/Cleared \d+ link/', $log), true);
    scenarioCheck('a link to a deleted invoice: both cleared', $link($items[0]), [0, 0]);
    scenarioCheck('a deleted line on an invoice still there: the line cleared, the invoice kept', $link($items[1]), [$real, 0]);
    if ($real_line) {
        scenarioCheck('a link to a line still there: left alone', $link($items[2]), [$real, $real_line]);
    }
    if ($old_id) {
        scenarioCheck('the old table (kept one release) too', array_map('intval', array_values(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_id, invoice_item_id FROM mit_procurement_items WHERE item_id = $old_id")))), [0, 0]);
    }
    $warned = [];
    mitConsistencyOrphans(function ($area, $level, $text, $lines = []) use (&$warned) {
        $warned = array_merge($warned, (array) $lines);
    });
    scenarioCheck('the consistency check names no invoice link of ours', (bool) preg_grep('/^(mit_order_item_links|mit_procurement_items)\.invoice/', $warned), false);
    scenarioCheck('run again: nothing more to clear', (bool) preg_grep('/Cleared \d+ link/', mitProcTidy()), false);
} finally {
    mysqli_query($mysqli, "DELETE FROM order_items WHERE order_item_id IN (" . implode(',', $items ?: [0]) . ")"); // the links go with them
    mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = $real");
    mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = $real");
    mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = $real");
    if ($old_id) {
        mysqli_query($mysqli, "DELETE FROM mit_procurement_items WHERE item_id = $old_id");
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
