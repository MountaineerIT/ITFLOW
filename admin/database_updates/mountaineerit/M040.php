<?php

/*
 * Mountaineer IT - database update M040
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * ITFlow 2.9.4 (26.11) gave products its own product_cost and product_vendor_id - the names, and meaning, of the columns our
 * M007 / M010 had added. mitBeforeItflowUpdate (functions/mit_common.php) renamed ours to mit_old_* just before 2.9.4 ran; here
 * their values go into ITFlow's columns (NOT NULL, 0 = none) and ours are dropped. From now on quote pricing, procurement and
 * receiving use ITFlow's columns. Nothing to do on a database that never had ours, or once done.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (mitColumnExists($mysqli, 'products', 'mit_old_product_cost')) {
    if (mitColumnExists($mysqli, 'products', 'product_cost')) {
        mysqli_query($mysqli, "UPDATE `products` SET `product_cost` = `mit_old_product_cost` WHERE `mit_old_product_cost` IS NOT NULL");
    }
    mysqli_query($mysqli, "ALTER TABLE `products` DROP COLUMN `mit_old_product_cost`");
}

if (mitColumnExists($mysqli, 'products', 'mit_old_product_vendor_id')) {
    if (mitColumnExists($mysqli, 'products', 'product_vendor_id')) {
        mysqli_query($mysqli, "UPDATE `products` SET `product_vendor_id` = `mit_old_product_vendor_id` WHERE `mit_old_product_vendor_id` IS NOT NULL");
    }
    mysqli_query($mysqli, "ALTER TABLE `products` DROP COLUMN `mit_old_product_vendor_id`");
}
