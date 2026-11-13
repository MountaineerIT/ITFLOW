<?php

/*
 * Mountaineer IT - database update M060
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * ITFlow 26.11.7 (database 3.6.4) adds its own speed-up indexes on tickets. Three of ours (M030 / M041) now do the same job, and a
 * second copy of an index only slows every ticket save, so ours go where ITFlow's is there (the user's choice): mit_tickets_created
 * (= ITFlow's ticket_created_at), mit_tickets_resolved (= ticket_resolved_at), mit_tickets_unbilled (covered by ticket_billable - billable,
 * invoice, status, client, archived). Our other indexes stay. Safe to run twice; on an install without ITFlow's index ours is kept.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

foreach (['mit_tickets_created' => 'ticket_created_at', 'mit_tickets_resolved' => 'ticket_resolved_at', 'mit_tickets_unbilled' => 'ticket_billable'] as $mit_ours => $mit_theirs) {
    if (mitIndexExists($mysqli, 'tickets', $mit_ours) && mitIndexExists($mysqli, 'tickets', $mit_theirs)) {
        mysqli_query($mysqli, "ALTER TABLE `tickets` DROP INDEX `$mit_ours`");
    }
}
unset($mit_ours, $mit_theirs);
