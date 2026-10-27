<?php

/*
 * Mountaineer IT - database update M019
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * NinjaOne RMM (functions/mit_ninjaone.php): its organizations and which ITFlow client each goes to
 * (status mapped / ignored / unmapped), its devices and the ITFlow asset each is linked to, and the
 * history of sync runs with their report. Settings (instance, API keys, web address) are in mit_settings.
 * A device keeps its row when its asset is deleted (asset_id goes NULL), so the sync can tell "deleted
 * in ITFlow" from "never synced".
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ninja_orgs` (
    `org_id` int(11) NOT NULL,
    `name` varchar(200) NOT NULL DEFAULT '',
    `client_id` int(11) NULL DEFAULT NULL,
    `status` varchar(10) NOT NULL DEFAULT 'unmapped',
    `device_count` int(11) NOT NULL DEFAULT 0,
    `seen_at` datetime NULL DEFAULT NULL,
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `updated_at` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`org_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ninja_devices` (
    `device_id` int(11) NOT NULL,
    `uid` varchar(64) NULL DEFAULT NULL,
    `org_id` int(11) NOT NULL DEFAULT 0,
    `name` varchar(200) NOT NULL DEFAULT '',
    `node_class` varchar(50) NULL DEFAULT NULL,
    `asset_id` int(11) NULL DEFAULT NULL,
    `offline` tinyint(1) NULL DEFAULT NULL,
    `last_contact` datetime NULL DEFAULT NULL,
    `status_checked_at` datetime NULL DEFAULT NULL,
    `warranty_start` date NULL DEFAULT NULL,
    `warranty_end` date NULL DEFAULT NULL,
    `warranty_checked_at` datetime NULL DEFAULT NULL,
    `seen_at` datetime NULL DEFAULT NULL,
    `synced_at` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`device_id`),
    KEY `mit_ninja_devices_asset` (`asset_id`),
    CONSTRAINT `mit_ninja_devices_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ninja_runs` (
    `run_id` int(11) NOT NULL AUTO_INCREMENT,
    `started_at` datetime NOT NULL DEFAULT current_timestamp(),
    `finished_at` datetime NULL DEFAULT NULL,
    `trigger_kind` varchar(10) NOT NULL DEFAULT 'cron',
    `started_by` int(11) NOT NULL DEFAULT 0,
    `outcome` varchar(10) NOT NULL DEFAULT 'running',
    `created` int(11) NOT NULL DEFAULT 0,
    `updated` int(11) NOT NULL DEFAULT 0,
    `unchanged` int(11) NOT NULL DEFAULT 0,
    `problems` int(11) NOT NULL DEFAULT 0,
    `report` mediumtext NULL DEFAULT NULL,
    PRIMARY KEY (`run_id`),
    KEY `mit_ninja_runs_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
