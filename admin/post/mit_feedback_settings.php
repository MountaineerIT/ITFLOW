<?php

/*
 * Mountaineer IT - the Bugs & ideas switch (functions/mit_feedback.php), from Admin > Debug.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_feedback_settings'])) {

    validateCSRFToken();

    $on = !empty($_POST['mit_feedback_enabled']);
    mitSetSetting('feedback_enabled', $on ? '1' : '0');

    logAudit("Settings", "Edit", "$session_name turned Bugs & ideas " . ($on ? 'on' : 'off'));

    flashAlert("Bugs & ideas turned " . ($on ? 'on' : 'off'));

    redirect();

}
