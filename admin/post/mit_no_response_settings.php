<?php

/*
 * Mountaineer IT - the "Messages when resolving" card under Admin > Settings > Ticket (admin/includes/mit_no_response_card.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_edit_no_response'])) {

    validateCSRFToken();

    $clean = function ($field) {
        return mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST[$field] ?? ''))), 0, 5000);
    };
    mitSetSetting('no_response_message', $clean('mit_no_response_message'));

    logAudit("Settings", "Edit", "$session_name edited the Resolve - no response message");

    flashAlert("Message saved");

    redirect();

}
