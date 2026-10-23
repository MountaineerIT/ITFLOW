<?php

/*
 * Mountaineer IT - the Calendar card under Admin > Settings > Ticket (admin/includes/mit_calendar_card.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_edit_calendar_colors'])) {

    validateCSRFToken();

    $color = strtolower(trim((string) ($_POST['mit_service_ticket_color'] ?? '')));
    mitSetSetting('service_ticket_color', preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : '#0d6efd');

    logAudit("Settings", "Edit", "$session_name edited the calendar color of service tickets");

    flashAlert("Calendar settings updated");

    redirect();

}
