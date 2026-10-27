<?php

/*
 * Mountaineer IT - database update M007
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Quote pricing and line images (functions/mit_quotes.php): our cost, how the customer price
 * comes from it (markup / margin % or a set price) and one image, on quote lines and on products.
 * All optional (NULL): stock ITFlow ignores them, and invoices are untouched.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

$mit_m007_columns = [
    'quote_items' => [
        'item_cost' => "decimal(15,2) NULL DEFAULT NULL AFTER `item_price`",
        'item_price_method' => "varchar(10) NULL DEFAULT NULL AFTER `item_cost`",
        'item_price_percent' => "decimal(7,2) NULL DEFAULT NULL AFTER `item_price_method`",
        'item_image' => "varchar(255) NULL DEFAULT NULL AFTER `item_price_percent`",
    ],
    'products' => [
        'product_cost' => "decimal(15,2) NULL DEFAULT NULL AFTER `product_price`",
        'product_price_method' => "varchar(10) NULL DEFAULT NULL AFTER `product_cost`",
        'product_price_percent' => "decimal(7,2) NULL DEFAULT NULL AFTER `product_price_method`",
        'product_image' => "varchar(255) NULL DEFAULT NULL AFTER `product_price_percent`",
    ],
];

foreach ($mit_m007_columns as $mit_table => $mit_columns) {
    foreach ($mit_columns as $mit_column => $mit_definition) {
        if (!mitColumnExists($mysqli, $mit_table, $mit_column)) {
            mysqli_query($mysqli, "ALTER TABLE `$mit_table` ADD `$mit_column` $mit_definition");
        }
    }
}
