<?php

/*
 * Mountaineer IT - database update M033
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Usage connectors (functions/mit_usage_connectors.php): which ITFlow client a Bitwarden organization (with its API key,
 * encrypted) or an Ingram Micro Cloud Marketplace customer belongs to, and how its last run went. NinjaOne uses the NinjaOne
 * sync's own mapping (mit_ninja_orgs) and needs no rows here. The counts themselves go to mit_usage_counts (M032).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_usage_links` (
    `link_id` int(11) NOT NULL AUTO_INCREMENT,
    `connector` varchar(20) NOT NULL,
    `client_id` int(11) NOT NULL,
    `external_id` varchar(100) NOT NULL,
    `external_name` varchar(255) NOT NULL DEFAULT '',
    `secret` text NULL DEFAULT NULL,
    `last_run_at` datetime NULL DEFAULT NULL,
    `last_status` varchar(10) NULL DEFAULT NULL,
    `last_message` varchar(500) NULL DEFAULT NULL,
    `created_by` int(11) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`link_id`),
    UNIQUE KEY `mit_usage_links_external` (`connector`, `external_id`),
    KEY `mit_usage_links_client` (`client_id`),
    CONSTRAINT `mit_usage_links_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
