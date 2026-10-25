<?php

/*
 * Mountaineer IT - steps around ITFlow's own database updates. A file of its own, required by admin/database_updates.php itself
 * (as well as from mit_common.php): scripts/update_cli.php loads functions.php, then pulls the new files, then runs the updates -
 * so a function added to mit_common.php in the same pull would not be loaded yet. A new file is.
 */

if (!function_exists('mitBeforeItflowUpdate')) {

/**
 * Just before ITFlow's database update $version runs (one line in admin/database_updates.php, and mitRestoreItflowUpdates).
 * 2.9.4 adds products.product_cost and product_vendor_id - the names our M007 / M010 gave the same things, so its ALTER would
 * stop with "Duplicate column". Ours are renamed aside (mit_old_*) first; M040 copies them into ITFlow's and drops them.
 * Only when ITFlow's step has not run yet (no product_track_stock, added by the same ALTER).
 */
function mitBeforeItflowUpdate($version) {
    global $mysqli;
    if ((string) $version !== '2.9.4') {
        return;
    }
    $has = function ($column) use ($mysqli) {
        $result = mysqli_query($mysqli, "SHOW COLUMNS FROM `products` LIKE '$column'");
        return $result && mysqli_num_rows($result) > 0;
    };
    if ($has('product_track_stock')) {
        return;
    }
    if ($has('product_cost') && !$has('mit_old_product_cost')) {
        mysqli_query($mysqli, "ALTER TABLE `products` CHANGE `product_cost` `mit_old_product_cost` decimal(15,2) NULL DEFAULT NULL");
    }
    if ($has('product_vendor_id') && !$has('mit_old_product_vendor_id')) {
        mysqli_query($mysqli, "ALTER TABLE `products` CHANGE `product_vendor_id` `mit_old_product_vendor_id` int(11) NULL DEFAULT NULL");
    }
}

}
