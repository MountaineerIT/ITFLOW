<?php

/*
 * Mountaineer IT - Your account > Outlook calendar (functions/mit_outlook.php).
 * Microsoft sends the person back to agent/user/mit_outlook_callback.php.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_outlook_connect'])) {

    validateCSRFToken();

    if (!mitOutlookAvailable()) {
        flashAlert("Outlook is not set up yet - an administrator needs to set up Microsoft 365 under Admin > Settings > Mail.", 'error');
        redirect('mit_outlook.php');
    }
    $_SESSION['mit_outlook_state'] = bin2hex(random_bytes(32));
    $_SESSION['mit_outlook_state_expires'] = time() + 600;
    redirect(mitOutlookAuthorizeUrl($_SESSION['mit_outlook_state']));
}

if (isset($_POST['mit_outlook_sync'])) {

    validateCSRFToken();

    try {
        $summary = mitOutlookSync($session_user_id, true);
        if ($summary === 'Already syncing') {
            flashAlert('Outlook is syncing in the background right now - try again in a moment.', 'warning');
        } else {
            flashAlert("Outlook calendar synced - " . escapeHtml(strtolower($summary)) . ".");
        }
    } catch (Throwable $e) {
        flashAlert("Outlook sync failed: " . escapeHtml($e->getMessage()), 'error');
    }
    redirect('mit_outlook.php');
}

if (isset($_POST['mit_outlook_disconnect'])) {

    validateCSRFToken();

    try {
        $left = mitOutlookDisconnect($session_user_id);
    } catch (Throwable $e) {
        flashAlert(escapeHtml($e->getMessage()), 'error');
        redirect('mit_outlook.php');
    }
    logAudit("User Account", "Edit", "$session_name disconnected their Outlook calendar");
    flashAlert($left ? "Outlook disconnected. $left scheduled ticket" . ($left === 1 ? '' : 's') . " could not be taken out of Outlook - delete them there (they have the ITFlow category)." : "Outlook disconnected and ITFlow's bookings taken out of it.", $left ? 'warning' : 'success');
    redirect('mit_outlook.php');
}
