<?php

/*
 * Mountaineer IT - database update M018
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * California time records (functions/mit_ts_california.php) for people on California non-exempt rules:
 *   - mit_ts_work_periods: each stretch of work in a day, start and end to the minute - the gaps between
 *     them are meal periods (Labor Code 1174 / the IWC wage orders: start and end of each work period
 *     and meal period);
 *   - mit_ts_days: the person's answers about a day's meal periods and rest breaks;
 *   - mit_ts_changes: every change to those times - who, when, before and after, why - and when the
 *     person acknowledged a change someone else made;
 *   - mit_ts_attestations: what the person signed when submitting a period, word for word, with a copy of
 *     the times they signed for.
 * No foreign keys on purpose: time records are kept for years (at least 3) even if the login is deleted.
 * mit_timesheet_people.ca_rules: 1 on, 0 off, NULL = by pay type (on for hourly). mit_settings ts_ca_start: the first
 * day the rules apply (the day this update ran).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ts_work_periods` (
    `period_id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `work_date` date NOT NULL,
    `start_at` datetime NOT NULL,
    `end_at` datetime NOT NULL,
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`period_id`),
    KEY `mit_ts_work_periods_user_date` (`user_id`, `work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// meal1 / meal2: '' | chose (the person chose to skip, shorten or delay it - or waived it) | not_provided (premium owed)
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ts_days` (
    `user_id` int(11) NOT NULL,
    `work_date` date NOT NULL,
    `meal1` varchar(20) NULL DEFAULT NULL,
    `meal2` varchar(20) NULL DEFAULT NULL,
    `rest_missed` tinyint(1) NOT NULL DEFAULT 0,
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`user_id`, `work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ts_changes` (
    `change_id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `work_date` date NOT NULL,
    `changed_by` int(11) NOT NULL,
    `changed_by_name` varchar(200) NOT NULL DEFAULT '',
    `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
    `before_text` varchar(500) NOT NULL DEFAULT '',
    `after_text` varchar(500) NOT NULL DEFAULT '',
    `reason` varchar(255) NULL DEFAULT NULL,
    `acknowledged_at` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`change_id`),
    KEY `mit_ts_changes_user_date` (`user_id`, `work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ts_attestations` (
    `attest_id` int(11) NOT NULL AUTO_INCREMENT,
    `sheet_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL,
    `period_start` date NOT NULL,
    `period_end` date NOT NULL,
    `attested_at` datetime NOT NULL DEFAULT current_timestamp(),
    `signature_name` varchar(200) NOT NULL,
    `ip` varchar(64) NOT NULL DEFAULT '',
    `user_agent` varchar(255) NOT NULL DEFAULT '',
    `text_version` varchar(20) NOT NULL,
    `statement` text NOT NULL,
    `breaks_ok` tinyint(1) NOT NULL DEFAULT 0,
    `exceptions` text NULL DEFAULT NULL,
    `snapshot` mediumtext NOT NULL,
    `superseded_at` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`attest_id`),
    KEY `mit_ts_attestations_sheet` (`sheet_id`),
    KEY `mit_ts_attestations_user` (`user_id`, `period_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

if (!mitColumnExists($mysqli, 'mit_timesheet_people', 'ca_rules')) {
    mysqli_query($mysqli, "ALTER TABLE `mit_timesheet_people` ADD `ca_rules` tinyint(1) NULL DEFAULT NULL");
}

// The rules apply from today on: days before keep their hours as they were (charge codes, no times typed)
mysqli_query($mysqli, "INSERT IGNORE INTO mit_settings SET setting_name = 'ts_ca_start', setting_value = CURDATE()");
