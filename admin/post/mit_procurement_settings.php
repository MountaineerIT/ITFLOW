<?php

/*
 * Mountaineer IT - purchase order settings (functions/mit_procurement.php), from Admin > Settings > Procurement.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_edit_procurement_settings'])) {

    validateCSRFToken();

    $prefix = trim((string) ($_POST['mit_po_prefix'] ?? ''));
    $next = intval($_POST['mit_po_next'] ?? 0);
    $over = trim(str_replace([',', '$'], '', (string) ($_POST['mit_po_approval_over'] ?? '')));
    if ($prefix === '' || $next < 1 || !is_numeric($over) || (float) $over < 0) {
        flashAlert('Fill in a prefix, a next number of 1 or more and an approval amount of 0 or more.', 'error');
        redirect();
    }
    if ($next < mitPoNextNumber()) {
        flashAlert('The next PO number can only go up - ' . intval(mitPoNextNumber()) . ' or more.', 'error');
        redirect();
    }

    mitSetSetting('procurement_po_prefix', mb_substr($prefix, 0, 20));
    mitSetSetting('procurement_po_next', $next);
    mitSetSetting('procurement_approval_over', round((float) $over, 2));
    mitSetSetting('procurement_approval_on', empty($_POST['mit_po_approval_on']) ? '0' : '1');
    mitSetSetting('procurement_ship_to', trim((string) ($_POST['mit_po_ship_to'] ?? '')));

    logAudit("Settings", "Edit", "$session_name edited purchase order settings");

    flashAlert("Purchase order settings saved");

    redirect();

}
