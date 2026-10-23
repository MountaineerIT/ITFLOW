<?php

/*
 * Mountaineer IT - the event quick look's "Assigned to" (functions/mit_event_agents.php mitEventQuickAssign): the agents on
 * a calendar entry, the rest of its people left as they are. Same rights as ITFlow's Edit event (a client's entry needs
 * access to that client).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_event_assign'])) {

    validateCSRFToken();

    $event = calendarEventForRequest($_POST['event_id'] ?? 0);
    [$added, $removed, $sent] = mitEventSetAgents($event, (array) ($_POST['agents'] ?? []));
    $words = mitEventAgentsText($added, $removed);

    if ($words !== '') {
        logAudit("Calendar Event", "Edit", escapeSql("$session_name $words: the event " . $event['event_title']), intval($event['event_client_id']), intval($event['event_id']));
    }

    flashAlert("<strong>" . escapeHtml($event['event_title']) . "</strong> " . ($words !== '' ? escapeHtml($words) : 'unchanged') . calendarSentText($sent, ''));

    redirect();

}
