<?php

/*
 * Mountaineer IT - database update M025
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Unique purchase order numbers, for installs where M015 found a number used twice and left the key off (it was then
 * recorded as done, so it never tried again - and two POs made at the same moment could get the same number). Each
 * number used more than once keeps it on its oldest PO; the others get the next free numbers, noted in their history.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (!mitIndexExists($mysqli, 'mit_purchase_orders', 'mit_purchase_orders_number')) {
    $mit_next = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(po_number), 0) + 1 FROM mit_purchase_orders"))[0]);
    $mit_dupes = mysqli_query($mysqli, "SELECT po_id, po_number FROM mit_purchase_orders p
        WHERE EXISTS (SELECT 1 FROM mit_purchase_orders o WHERE o.po_number = p.po_number AND o.po_id < p.po_id) ORDER BY po_id");
    while ($mit_row = mysqli_fetch_assoc($mit_dupes)) {
        $mit_po_id = intval($mit_row['po_id']);
        mysqli_query($mysqli, "UPDATE mit_purchase_orders SET po_number = $mit_next WHERE po_id = $mit_po_id");
        mysqli_query($mysqli, "INSERT INTO mit_po_history SET po_id = $mit_po_id, user_id = 0,
            description = 'Renumbered from " . intval($mit_row['po_number']) . " to $mit_next - another PO had the same number (database update M025)'");
        $mit_next++;
    }
    mysqli_query($mysqli, "ALTER TABLE `mit_purchase_orders` ADD UNIQUE KEY `mit_purchase_orders_number` (`po_number`)");
    // The next PO number never goes back to one now in use
    $mit_setting = mysqli_fetch_row(mysqli_query($mysqli, "SELECT setting_value FROM mit_settings WHERE setting_name = 'procurement_po_next'"));
    if ($mit_setting && intval($mit_setting[0]) < $mit_next) {
        mysqli_query($mysqli, "UPDATE mit_settings SET setting_value = '$mit_next' WHERE setting_name = 'procurement_po_next'");
    }
}
