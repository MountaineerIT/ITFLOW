<?php

/*
 * Mountaineer IT - offices (functions/mit_offices.php), from Admin > Settings > Company Details.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_office_save'])) {

    validateCSRFToken();

    $is_new = !intval($_POST['office_id'] ?? 0);
    [$office_id, $error] = mitOfficeSave($_POST);
    if (!$office_id) {
        flashAlert($error, 'error');
        redirect();
    }
    $name = mitOffice($office_id)['name'] ?? '';
    logAudit("Settings", $is_new ? "Create" : "Edit", stripslashes($session_name) . ($is_new ? " added the office " : " edited the office ") . $name);
    flashAlert("Office " . escapeHtml($name) . " saved");
    redirect('settings_company.php#section-mit-offices');

}

if (isset($_GET['mit_office_remove'])) {

    validateCSRFToken();

    $office_id = intval($_GET['mit_office_remove']);
    $name = mitOffice($office_id)['name'] ?? '';
    if ($office_id && mitOfficeRemove($office_id)) {
        logAudit("Settings", "Delete", stripslashes($session_name) . " removed the office $name");
        flashAlert("Office " . escapeHtml($name) . " removed - its people are in the main office now");
    }
    redirect('settings_company.php#section-mit-offices');

}
