<?php

/*
 * Mountaineer IT - usage billing (functions/mit_usage.php): the recurring invoice's Usage card, typing a count on the client's
 * Usage card, and overrides on Billing > Usage Review. Sales edit.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_usage_save_lines'])) {

    validateCSRFToken();
    enforceUserPermission('module_sales', 2);

    $recurring_invoice_id = intval($_POST['recurring_invoice_id'] ?? 0);
    $client_id = intval(getFieldById('recurring_invoices', $recurring_invoice_id, 'recurring_invoice_client_id'));
    enforceClientAccess();

    $problems = [];
    $saved = 0;
    foreach ((array) ($_POST['usage'] ?? []) as $item_id => $fields) {
        $item_id = intval($item_id);
        // Only this recurring invoice's own lines
        if (intval(getFieldById('recurring_invoice_items', $item_id, 'item_recurring_invoice_id')) !== $recurring_invoice_id) {
            continue;
        }
        $why = mitUsageSaveLine($item_id, (string) ($fields['source'] ?? ''), (string) ($fields['metric'] ?? ''), intval($fields['tag_id'] ?? 0), (string) ($fields['min'] ?? ''));
        if ($why !== '') {
            $problems[] = $why;
        } else {
            $saved++;
        }
    }
    logAudit('Recurring Invoice', 'Edit', escapeSql(stripslashes($session_name) . " changed the usage billing of recurring invoice $recurring_invoice_id"), $client_id);
    flashAlert($problems ? escapeHtml(implode(' ', $problems)) : 'Usage billing saved', $problems ? 'error' : 'success');
    redirect();
}

if (isset($_POST['mit_usage_count'])) {

    validateCSRFToken();
    enforceUserPermission('module_sales', 2);

    $client_id = intval($_POST['client_id'] ?? 0);
    enforceClientAccess();

    [$count_id, $why] = mitUsageRecordCount($client_id, (string) ($_POST['metric'] ?? ''), $_POST['quantity'] ?? '', (string) ($_POST['as_of'] ?? ''), 'manual',
        'Typed by ' . stripslashes($session_name));
    if ($why !== '') {
        flashAlert(escapeHtml($why), 'error');
    } else {
        logAudit('Client', 'Edit', escapeSql(stripslashes($session_name) . " entered a usage count (" . mitUsageCleanMetric($_POST['metric'] ?? '') . ")"), $client_id);
        flashAlert('Count saved');
    }
    redirect();
}

if (isset($_POST['mit_usage_override'])) {

    validateCSRFToken();
    enforceUserPermission('module_sales', 2);

    $why = mitUsageOverride(intval($_POST['applied_id'] ?? 0), $_POST['quantity'] ?? '', (string) ($_POST['reason'] ?? ''));
    flashAlert($why !== '' ? escapeHtml($why) : 'Quantity changed - approve the invoice when it is right.', $why !== '' ? 'error' : 'success');
    redirect();
}
