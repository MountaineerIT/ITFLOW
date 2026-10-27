<?php

/*
 * Mountaineer IT - database update M006
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Repeats by weekday (functions/mit_recurring.php): "the third Wednesday" or "the last Friday" of
 * the month, for recurring tickets and repeating calendar events. Stored the RFC 5545 BYDAY way
 * ("3WE", "-1FR"); empty (NULL) is ITFlow's own same-date-each-month, which is also what stock
 * ITFlow does with these rows.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (!mitColumnExists($mysqli, 'recurring_tickets', 'recurring_ticket_by_day')) {
    mysqli_query($mysqli, "ALTER TABLE `recurring_tickets` ADD `recurring_ticket_by_day` varchar(5) NULL DEFAULT NULL AFTER `recurring_ticket_frequency`");
}
if (!mitColumnExists($mysqli, 'calendar_events', 'event_repeat_by_day')) {
    mysqli_query($mysqli, "ALTER TABLE `calendar_events` ADD `event_repeat_by_day` varchar(5) NULL DEFAULT NULL AFTER `event_repeat_interval`");
}
