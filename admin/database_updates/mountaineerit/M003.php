<?php

/*
 * Mountaineer IT - database update M003
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Our own settings, kept out of ITFlow's settings table: first the Microsoft 365 app that
 * ITFlow creates in the tenant (functions/mit_m365.php) - its ids, its certificate and key.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_settings` (
    `setting_name` varchar(100) NOT NULL,
    `setting_value` mediumtext NOT NULL,
    `setting_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`setting_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
