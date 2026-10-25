<?php

/*
 * Mountaineer IT - database update M039
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * MSP Align, read only (functions/mit_align.php): the hourly copy of what MSP Align knows about ITFlow's clients, assets and
 * licenses, matched by the ITFlow ids MSP Align keeps (psa_id / psa_asset_id). One row per ITFlow record that MSP Align has;
 * rows go when the ITFlow record is deleted (CASCADE) or MSP Align no longer has it (the next sync). Settings in mit_settings align_*.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_align_clients` (
    `client_id` int(11) NOT NULL,
    `align_id` int(11) NOT NULL,
    `url` varchar(500) NOT NULL DEFAULT '',
    `health_score` int(11) NULL DEFAULT NULL,
    `health_band` varchar(40) NULL DEFAULT NULL,
    `alignment_score` int(11) NULL DEFAULT NULL,
    `alignment_band` varchar(40) NULL DEFAULT NULL,
    `devices_total` int(11) NULL DEFAULT NULL,
    `devices_attention` int(11) NULL DEFAULT NULL,
    `next_meeting_at` datetime NULL DEFAULT NULL,
    `next_meeting_title` varchar(255) NULL DEFAULT NULL,
    `synced_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`client_id`),
    KEY `mit_align_clients_align` (`align_id`),
    CONSTRAINT `mit_align_clients_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_align_devices` (
    `asset_id` int(11) NOT NULL,
    `align_id` int(11) NOT NULL,
    `url` varchar(500) NOT NULL DEFAULT '',
    `status` varchar(40) NOT NULL DEFAULT '',
    `status_label` varchar(80) NOT NULL DEFAULT '',
    `health` varchar(20) NOT NULL DEFAULT '',
    `warranty_end` date NULL DEFAULT NULL,
    `end_of_life` date NULL DEFAULT NULL,
    `replace_by` date NULL DEFAULT NULL,
    `planned` varchar(80) NULL DEFAULT NULL,
    `os_support_ends` date NULL DEFAULT NULL,
    `synced_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`asset_id`),
    CONSTRAINT `mit_align_devices_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_align_licenses` (
    `software_id` int(11) NOT NULL,
    `align_id` int(11) NOT NULL,
    `align_client_id` int(11) NOT NULL DEFAULT 0,
    `expire_date` date NULL DEFAULT NULL,
    `contract_end` date NULL DEFAULT NULL,
    `renegotiate_date` date NULL DEFAULT NULL,
    `auto_renew` tinyint(1) NOT NULL DEFAULT 0,
    `annual` decimal(15,2) NULL DEFAULT NULL,
    `billing_cycle` varchar(20) NULL DEFAULT NULL,
    `synced_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`software_id`),
    CONSTRAINT `mit_align_licenses_software` FOREIGN KEY (`software_id`) REFERENCES `software` (`software_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
