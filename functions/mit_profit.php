<?php

/*
 * Mountaineer IT - Client Profitability on ITFlow's own labor costs (26.12: functions/labor_cost.php, Admin > Labor Costs, built from
 * ours - our rates and default were copied into user_cost_rates / config_labor_cost_default by M067). What stays ours:
 *  - the report opens on Invoiced income (the user's choice; ITFlow's opens on Paid) - mitProfitDefaultBasis, one guarded line in
 *    laborProfitBasis;
 *  - parts cost of invoice lines billed from ticket parts (functions/mit_procurement_parts.php): those lines carry no product, so
 *    ITFlow's product-cost sum misses them - mitProfitPartsAdd adds each billed part's own cost (unit_cost x quantity), one guarded
 *    line after ITFlow's parts query in laborProfitAdd.
 */

/** The basis Client Profitability opens on when none is picked. */
function mitProfitDefaultBasis() {
    return 'invoiced';
}

/** Adds the cost of billed ticket parts on invoices dated in the period (lines with no product - ITFlow's sum has the others). */
function mitProfitPartsAdd(array &$clients, callable $client, $period, $scope) {
    global $mysqli;
    if (!function_exists('mitTicketPartsReady') || !mitTicketPartsReady() || !defined('LABOR_PROFIT_INVOICE_STATUSES')) {
        return;
    }
    $sql = mysqli_query($mysqli, "SELECT clients.client_id, client_name, client_abbreviation, SUM(part.unit_cost * part.quantity) AS v
        FROM mit_ticket_parts part
        JOIN invoice_items ON item_id = part.invoice_item_id
        JOIN invoices ON invoice_items.item_invoice_id = invoices.invoice_id
        JOIN clients ON invoices.invoice_client_id = clients.client_id
        WHERE item_archived_at IS NULL AND item_product_id = 0 AND part.unit_cost > 0
        AND invoices.invoice_status IN (" . LABOR_PROFIT_INVOICE_STATUSES . ") AND " . reportRangeSql('invoices.invoice_date', $period) . clientScopeSql('clients.client_id') . "
        GROUP BY clients.client_id, client_name, client_abbreviation");
    while ($sql && ($row = mysqli_fetch_assoc($sql))) {
        $id = $client($row);
        $clients[$id]['parts'] = ($clients[$id]['parts'] ?? 0.0) + floatval($row['v']);
    }
}
