<?php

/*
 * Mountaineer IT - database update M046
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * ITFlow installs made from its db.sql in November 2025 (production among them) have a `company_id` int NOT NULL with no default on
 * `contract_templates` - in that db.sql for a few days, never in ITFlow's database updates, gone from ITFlow's schema since. ITFlow's
 * own Add contract template leaves it out, so on those installs it fails ("Field 'company_id' doesn't have a default value"). The
 * column is not ITFlow's any more and nothing reads it: it keeps its values and gets DEFAULT 0, so ITFlow's inserts work - with or
 * without our code. Installs without the column are left alone. Safe to run twice.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (mitColumnExists($mysqli, 'contract_templates', 'company_id')) {
    $mit_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contract_templates' AND COLUMN_NAME = 'company_id'"));
    if ($mit_row && $mit_row['IS_NULLABLE'] === 'NO' && $mit_row['COLUMN_DEFAULT'] === null) {
        mysqli_query($mysqli, "ALTER TABLE `contract_templates` MODIFY `company_id` int(11) NOT NULL DEFAULT 0");
    }
}
