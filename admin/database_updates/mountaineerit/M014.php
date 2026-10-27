<?php

/*
 * Mountaineer IT - database update M014
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Leave balances (functions/mit_leave.php): leave banks such as PTO and Sick - which charge codes use
 * them up, how they are earned (per pay period, per hour worked, a yearly grant, or not at all), a
 * maximum, what happens at year end, a waiting period and whether they can go below zero; a ledger of
 * what was earned, granted, carried over and adjusted; a person's own rate; and hire dates.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

// method: none | period (rate each pay period) | worked (rate for every per_worked minutes worked) | yearly (rate each year)
// carryover_max: NULL keeps everything at year end, 0 loses it all, otherwise the most kept (minutes)
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_leave_banks` (
    `bank_id` int(11) NOT NULL AUTO_INCREMENT,
    `bank_name` varchar(100) NOT NULL,
    `bank_codes` varchar(255) NULL DEFAULT NULL,
    `bank_method` varchar(20) NOT NULL DEFAULT 'none',
    `bank_rate` int(11) NOT NULL DEFAULT 0,
    `bank_per_worked` int(11) NOT NULL DEFAULT 0,
    `bank_max` int(11) NULL DEFAULT NULL,
    `bank_carryover_max` int(11) NULL DEFAULT NULL,
    `bank_year_start` varchar(10) NOT NULL DEFAULT 'jan1',
    `bank_waiting_days` int(11) NOT NULL DEFAULT 0,
    `bank_allow_negative` tinyint(1) NOT NULL DEFAULT 0,
    `bank_sort` int(11) NOT NULL DEFAULT 0,
    `bank_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `bank_archived_at` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`bank_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// kind: accrual (ref = the pay period's first day) | grant / carryover (ref = the leave year's first day) | adjust | start
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_leave_ledger` (
    `ledger_id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `bank_id` int(11) NOT NULL,
    `entry_date` date NOT NULL,
    `minutes` int(11) NOT NULL,
    `kind` varchar(20) NOT NULL,
    `ref` varchar(20) NULL DEFAULT NULL,
    `note` varchar(255) NULL DEFAULT NULL,
    `created_by` int(11) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`ledger_id`),
    UNIQUE KEY `mit_leave_ledger_auto` (`user_id`, `bank_id`, `kind`, `ref`),
    KEY `mit_leave_ledger_user` (`user_id`, `bank_id`, `entry_date`),
    CONSTRAINT `mit_leave_ledger_bank` FOREIGN KEY (`bank_id`) REFERENCES `mit_leave_banks` (`bank_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// A person's own rate for a bank (NULL = the bank's), or left out of it
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_leave_rates` (
    `user_id` int(11) NOT NULL,
    `bank_id` int(11) NOT NULL,
    `rate` int(11) NULL DEFAULT NULL,
    `excluded` tinyint(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`user_id`, `bank_id`),
    CONSTRAINT `mit_leave_rates_bank` FOREIGN KEY (`bank_id`) REFERENCES `mit_leave_banks` (`bank_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

if (!mitColumnExists($mysqli, 'mit_timesheet_people', 'hire_date')) {
    mysqli_query($mysqli, "ALTER TABLE `mit_timesheet_people` ADD `hire_date` date NULL DEFAULT NULL");
}

// PTO and Sick to start with, earning nothing until their rules are set - only into an empty table
if (intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_leave_banks"))[0]) === 0) {
    $mit_m014_codes = function ($names) use ($mysqli) {
        $ids = [];
        $result = mysqli_query($mysqli, "SELECT code_id FROM mit_charge_codes WHERE code_name IN ('" . implode("','", $names) . "')");
        while ($row = mysqli_fetch_row($result)) {
            $ids[] = intval($row[0]);
        }
        return $ids ? "'" . implode(',', $ids) . "'" : 'NULL';
    };
    // One INSERT, so a run that stops part way never leaves one bank without the other
    mysqli_query($mysqli, "INSERT INTO mit_leave_banks (bank_name, bank_codes, bank_sort) VALUES
        ('PTO', " . $mit_m014_codes(['PTO', 'Vacation']) . ", 1), ('Sick', " . $mit_m014_codes(['Sick leave']) . ", 2)");
}
