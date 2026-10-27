<?php

/*
 * Mountaineer IT - database update M011
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Procurement part 3 (functions/mit_procurement_extras.php): the assets made when items are received.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (!mitColumnExists($mysqli, 'mit_po_receipts', 'asset_ids')) {
    mysqli_query($mysqli, "ALTER TABLE `mit_po_receipts` ADD `asset_ids` varchar(1000) NULL DEFAULT NULL");
}
