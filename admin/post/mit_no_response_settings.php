<?php

/*
 * Mountaineer IT - the "Messages to clients" card under Admin > Settings > Ticket (admin/includes/mit_no_response_card.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_edit_no_response'])) {

    validateCSRFToken();

    // The built-in wording sent back unchanged is kept as empty - it then follows any change to the built-in text
    $clean = function ($field, $default) {
        $text = mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST[$field] ?? ''))), 0, 5000);
        return $text === trim($default) ? '' : $text;
    };
    $said = [];
    if ((string) mitSetting('no_response_message', '') !== $clean('mit_no_response_message', MIT_NO_RESPONSE_DEFAULT)) {
        mitSetSetting('no_response_message', $clean('mit_no_response_message', MIT_NO_RESPONSE_DEFAULT));
        $said[] = 'Resolve - no response';
    }
    if (isset($_POST['mit_status_response_message']) && (string) mitSetting('status_response_message', '') !== $clean('mit_status_response_message', MIT_STATUS_RESPONSE_DEFAULT)) {
        mitSetSetting('status_response_message', $clean('mit_status_response_message', MIT_STATUS_RESPONSE_DEFAULT));
        $said[] = 'Ticket assigned';
    }

    if ($said) {
        logAudit("Settings", "Edit", "$session_name edited the " . implode(' and ', $said) . " message" . (count($said) > 1 ? 's' : ''));
    }

    flashAlert(count($said) > 1 ? "Messages saved" : ($said ? "Message saved" : "Nothing changed"));

    redirect();

}
