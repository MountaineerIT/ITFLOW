<?php

/*
 * Mountaineer IT - project tickets kept apart (functions/mit_projects.php), from Admin > Settings > Project.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_edit_project_tickets'])) {

    validateCSRFToken();

    $color = strtolower(trim((string) ($_POST['mit_project_ticket_color'] ?? '')));
    mitSetSetting('project_tickets_apart', empty($_POST['mit_project_tickets_apart']) ? '0' : '1');
    mitSetSetting('project_ticket_color', preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : '#8e44ad');

    // Their SLAs go (or come back) now rather than at the next cron run
    mitProjectTicketsSweep();

    logAudit("Settings", "Edit", "$session_name edited Project ticket settings");

    flashAlert("Project ticket settings updated");

    redirect();

}
