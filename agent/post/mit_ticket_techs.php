<?php

/*
 * Mountaineer IT - additional techs on a ticket (functions/mit_ticket_techs.php,
 * agent/modals/ticket/mit_ticket_tech.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_save_ticket_tech'])) {

    validateCSRFToken();

    $row = ticketForRequest($_POST['ticket_id'] ?? 0, 2);
    if (!ticketIsOpen($row)) {
        flashAlert("Ticket <strong>" . escapeHtml(ticketReference($row)) . "</strong> is resolved or closed - reopen it to add agents.", 'error');
        redirect();
    }

    $mode = $_POST['tech_mode'] ?? 'lead';
    $start = $end = '';
    if ($mode === 'own') {
        $start = ticketScheduleValue($_POST['scheduled_date_time'] ?? '');
        $minutes = max(15, min(24 * 60, intval($_POST['schedule_minutes'] ?? CALENDAR_TICKET_MINUTES)));
        $end = $start !== '' ? date('Y-m-d H:i:s', strtotime($start) + $minutes * 60) : '';
    }

    [$ok, $message, $conflicts] = mitTicketTechSave($row, intval($_POST['user_id'] ?? 0), $mode, $start, $end);
    if (!$ok) {
        flashAlert(escapeHtml($message), 'error');
        redirect();
    }

    flashAlert(escapeHtml($message));
    if ($conflicts) {
        flashAlert("They already have work scheduled at that time: <br>" . implode(", <br>", array_map('escapeHtml', $conflicts)), 'warning');
    }

    redirect();

}

if (isset($_GET['mit_remove_ticket_tech'])) {

    validateCSRFToken();

    $row = ticketForRequest($_GET['mit_remove_ticket_tech'], 2);

    $name = mitTicketTechRemove($row, intval($_GET['user_id'] ?? 0));
    if ($name === false) {
        flashAlert('That agent is not on this ticket.', 'error');
        redirect();
    }

    flashAlert(escapeHtml($name) . ' removed from the ticket', 'error');

    redirect();

}
