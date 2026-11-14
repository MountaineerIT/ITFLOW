<?php

/*
 * Mountaineer IT - database update M062
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Undoing a receipt on a purchase order (the user's request - a wrong receive could not be taken back): each receipt now keeps what
 * else it did, so mitPoUndoReceipt() can put exactly that back. Columns on our own mit_po_receipts:
 *   tracked          1 = recorded from 26.11.8.3 on (older receipts don't know what they did to stock)
 *   stock_product_id / stock_qty    put on the shelf (functions/mit_procurement_extras.php mitProcAfterReceive)
 *   return_product_id / return_qty / return_invoice_id / return_item_id    stock an invoice line had taken, given back
 *   cleared_item_id  the invoice line whose item_product_id was cleared (put back on undo)
 *   note_reply_id    the internal note it left on the ticket
 * Safe to run twice.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

$mit_cols = [
    'tracked' => "tinyint(1) NOT NULL DEFAULT 0",
    'stock_product_id' => "int(11) NOT NULL DEFAULT 0",
    'stock_qty' => "decimal(15,2) NOT NULL DEFAULT 0",
    'return_product_id' => "int(11) NOT NULL DEFAULT 0",
    'return_qty' => "decimal(15,2) NOT NULL DEFAULT 0",
    'return_invoice_id' => "int(11) NOT NULL DEFAULT 0",
    'return_item_id' => "int(11) NOT NULL DEFAULT 0",
    'cleared_item_id' => "int(11) NOT NULL DEFAULT 0",
    'note_reply_id' => "int(11) NOT NULL DEFAULT 0",
];
foreach ($mit_cols as $mit_col => $mit_def) {
    if (!mitColumnExists($mysqli, 'mit_po_receipts', $mit_col)) {
        mysqli_query($mysqli, "ALTER TABLE `mit_po_receipts` ADD `$mit_col` $mit_def");
    }
}
unset($mit_cols, $mit_col, $mit_def);
