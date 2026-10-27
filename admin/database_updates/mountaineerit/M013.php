<?php

/*
 * Mountaineer IT - database update M013
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Timesheet options (functions/mit_timesheets.php): charge codes for some roles only, a required
 * note, a daily limit and filling in closure days; reminder dates on timesheets; and each person's
 * timesheet details (work schedule, approver, employee ID, pay type). Company settings are rows in
 * mit_settings and personal ones in mit_user_preferences - no change needed for those.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

foreach ([
    'code_roles' => "varchar(255) NULL DEFAULT NULL",
    'code_note_required' => "tinyint(1) NOT NULL DEFAULT 0",
    'code_daily_max' => "int(11) NULL DEFAULT NULL",
    'code_closure_fill' => "tinyint(1) NOT NULL DEFAULT 0",
] as $mit_column => $mit_definition) {
    if (!mitColumnExists($mysqli, 'mit_charge_codes', $mit_column)) {
        mysqli_query($mysqli, "ALTER TABLE `mit_charge_codes` ADD `$mit_column` $mit_definition");
    }
}

foreach (['reminded_due_at', 'reminded_late_at'] as $mit_column) {
    if (!mitColumnExists($mysqli, 'mit_timesheets', $mit_column)) {
        mysqli_query($mysqli, "ALTER TABLE `mit_timesheets` ADD `$mit_column` datetime NULL DEFAULT NULL");
    }
}

// schedule: minutes Monday..Sunday ("480,480,480,480,480,0,0"), NULL = the company's
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_timesheet_people` (
    `user_id` int(11) NOT NULL,
    `schedule` varchar(100) NULL DEFAULT NULL,
    `approver_id` int(11) NOT NULL DEFAULT 0,
    `employee_id` varchar(50) NULL DEFAULT NULL,
    `pay_type` varchar(20) NOT NULL DEFAULT 'hourly',
    `updated_at` datetime NULL DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
