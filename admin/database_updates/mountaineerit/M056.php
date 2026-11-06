<?php

/*
 * Mountaineer IT - database update M056
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Recurring tickets made ahead (functions/mit_recurring_lead.php): how the days ahead count - 'days' before the run day, or 'month' = before
 * the 1st of the run day's month (site visits all made a few days before the month starts). Our own table from M055.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (!mitColumnExists($mysqli, 'mit_recurring_ticket_plan', 'lead_mode')) {
    mysqli_query($mysqli, "ALTER TABLE `mit_recurring_ticket_plan` ADD `lead_mode` varchar(10) NOT NULL DEFAULT 'days' AFTER `lead_days`");
}
