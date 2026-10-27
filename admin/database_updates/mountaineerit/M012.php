<?php

/*
 * Mountaineer IT - database update M012
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Timesheets (functions/mit_timesheets.php): charge codes (PTO, Holiday, Admin, Lost time ...), the
 * hours each person puts against them per day, and one timesheet per person per pay period (submit,
 * approve). Approving copies that period's ticket time in as entries, so payroll does not move if a
 * ticket reply is changed later. Plus a "Timesheets" permission module. ITFlow's tables are not changed.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_charge_codes` (
    `code_id` int(11) NOT NULL AUTO_INCREMENT,
    `code_name` varchar(100) NOT NULL,
    `code_kind` varchar(20) NOT NULL DEFAULT 'work',
    `code_paid` tinyint(1) NOT NULL DEFAULT 1,
    `code_color` varchar(20) NULL DEFAULT NULL,
    `code_sort` int(11) NOT NULL DEFAULT 0,
    `code_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `code_archived_at` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`code_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// A starting list - only into an empty table, so a second run (or codes the admin removed) is left alone.
// One INSERT, so a run that stops part way never leaves half a list behind.
if (intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_charge_codes"))[0]) === 0) {
    $mit_m012_codes = [
        ['Admin', 'work', 1, '#0d6efd'], ['Training', 'work', 1, '#6610f2'], ['Travel', 'work', 1, '#20c997'],
        ['Lost time', 'lost', 1, '#dc3545'], ['PTO', 'leave', 1, '#fd7e14'], ['Vacation', 'leave', 1, '#ffc107'],
        ['Holiday', 'leave', 1, '#198754'], ['Sick leave', 'leave', 1, '#d63384'], ['Unpaid leave', 'leave', 0, '#6c757d'],
    ];
    $mit_m012_rows = [];
    foreach ($mit_m012_codes as $mit_i => [$mit_name, $mit_kind, $mit_paid, $mit_color]) {
        $mit_m012_rows[] = "('$mit_name', '$mit_kind', $mit_paid, '$mit_color', " . ($mit_i + 1) . ")";
    }
    mysqli_query($mysqli, "INSERT INTO mit_charge_codes (code_name, code_kind, code_paid, code_color, code_sort) VALUES " . implode(', ', $mit_m012_rows));
}

// One row per person, day and code; source 'ticket_billable' / 'ticket_other' (code 0) are the ticket time
// copied in when the period is approved
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_time_entries` (
    `entry_id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `entry_date` date NOT NULL,
    `code_id` int(11) NOT NULL DEFAULT 0,
    `source` varchar(20) NOT NULL DEFAULT 'manual',
    `minutes` int(11) NOT NULL DEFAULT 0,
    `note` varchar(255) NULL DEFAULT NULL,
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NULL DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`entry_id`),
    UNIQUE KEY `mit_time_entries_cell` (`user_id`, `entry_date`, `code_id`, `source`),
    KEY `mit_time_entries_date` (`entry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_timesheets` (
    `sheet_id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `period_start` date NOT NULL,
    `period_end` date NOT NULL,
    `status` varchar(20) NOT NULL DEFAULT 'open',
    `submitted_at` datetime NULL DEFAULT NULL,
    `decided_by` int(11) NOT NULL DEFAULT 0,
    `decided_at` datetime NULL DEFAULT NULL,
    `decision_note` varchar(255) NULL DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NULL DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`sheet_id`),
    UNIQUE KEY `mit_timesheets_period` (`user_id`, `period_start`),
    KEY `mit_timesheets_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// The Timesheets permission - View sees everyone's and the payroll report, Edit corrects them, Full approves
$mit_m012_module = mysqli_query($mysqli, "SELECT module_id FROM modules WHERE module_name = 'module_timesheets'");
if (mysqli_num_rows($mit_m012_module) === 0) {
    mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_timesheets', module_description = 'Everyone keeps their own timesheet. View sees everyone''s and the payroll report, Edit corrects them, Full approves'");
}
