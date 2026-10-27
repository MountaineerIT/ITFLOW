<?php

/*
 * Mountaineer IT - procurement part 2 (functions/mit_procurement_parts.php): an invoice's lines to
 * procurement, and parts on tickets (add, change, remove, bill).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

mitModuleGuard('procurement', ['mit_part', 'mit_proc_']);

if (isset($_POST['mit_proc_from_invoice'])) {

    validateCSRFToken();
    enforceUserPermission('module_procurement', 2);
    enforceUserPermission('module_sales'); // invoices are Sales'

    $invoice_id = intval($_POST['invoice_id'] ?? 0);
    $invoice = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_id, invoice_client_id FROM invoices WHERE invoice_id = $invoice_id"));
    if (!$invoice || !hasClientAccess($invoice['invoice_client_id'])) {
        flashAlert('That invoice does not exist, or it is for a client you cannot see.', 'error');
        redirect();
    }
    $client_id = intval($invoice['invoice_client_id']);
    $project_id = intval($_POST['project_id'] ?? 0);
    if ($project_id && intval(getFieldById('projects', $project_id, 'project_client_id')) !== $client_id) {
        $project_id = 0;
    }
    $queued = mitProcInvoiceQueued($invoice_id);
    $sent = 0;
    foreach ((array) ($_POST['lines'] ?? []) as $item_id => $line) {
        if (empty($line['send'])) {
            continue;
        }
        $item = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT item_id, item_name, item_description, item_quantity, item_product_id FROM invoice_items WHERE item_id = " . intval($item_id) . " AND item_invoice_id = $invoice_id"));
        if (!$item) {
            continue;
        }
        $item = mitProcInvoiceLineProducts([$item])[0];
        $left = (float) $item['item_quantity'] - ($queued[intval($item_id)] ?? 0);
        $qty = min($left, round((float) str_replace(',', '', (string) ($line['qty'] ?? '0')), 2));
        if ($qty <= 0) {
            continue;
        }
        $cost = trim(str_replace([',', '$'], '', (string) ($line['cost'] ?? '')));
        $vendor_id = intval($line['vendor_id'] ?? 0);
        mitProcAddItem([
            'source' => 'invoice', 'invoice_id' => $invoice_id, 'invoice_item_id' => intval($item_id), 'client_id' => $client_id, 'project_id' => $project_id,
            'product_id' => intval($item['item_product_id']), 'vendor_id' => isset(mitProcVendors()[$vendor_id]) ? $vendor_id : 0,
            'name' => $item['item_name'], 'description' => $item['item_description'], 'quantity' => $qty, 'unit_cost' => is_numeric($cost) && (float) $cost >= 0 ? $cost : null,
        ]);
        $sent++;
    }
    if (!$sent) {
        flashAlert('Nothing was ticked, or it has all been sent already.', 'error');
        redirect();
    }
    logAudit('Procurement', 'Create', escapeSql(mitProcWho() . " sent $sent line(s) of an invoice to procurement"), $client_id, $invoice_id);
    flashAlert("$sent line" . ($sent === 1 ? '' : 's') . ' sent to procurement - see Procurement > To order');
    redirect();
}

if (isset($_POST['mit_part_save'])) {

    validateCSRFToken();

    $ticket = ticketForRequest($_POST['ticket_id'] ?? 0, 2);
    if (!empty($ticket['ticket_closed_at'])) {
        flashAlert('The ticket is closed.', 'error');
        redirect();
    }
    [$ok, $message] = mitTicketPartSave($ticket, $_POST, intval($_POST['part_id'] ?? 0));
    flashAlert(escapeHtml($message), $ok ? (str_contains($message, 'Stock for') ? 'warning' : 'success') : 'error');
    redirect();
}

if (isset($_GET['mit_part_remove'])) {

    validateCSRFToken();

    $ticket = ticketForRequest($_GET['ticket_id'] ?? 0, 2);
    if (!empty($ticket['ticket_closed_at'])) {
        flashAlert('The ticket is closed.', 'error');
        redirect();
    }
    [$ok, $message] = mitTicketPartRemove($ticket, $_GET['mit_part_remove']);
    flashAlert(escapeHtml($message), $ok ? 'success' : 'error');
    redirect();
}

if (isset($_POST['mit_parts_bill'])) {

    validateCSRFToken();
    enforceUserPermission('module_sales', 2);

    $ticket = ticketForRequest($_POST['ticket_id'] ?? 0, 2);
    // One billing at a time, so a double-click cannot bill the same parts twice
    mitProcLock('bill');
    [$invoice_id, $message] = mitTicketPartsBill($ticket, (array) ($_POST['part_ids'] ?? []), (array) ($_POST['prices'] ?? []),
        $_POST['invoice_id'] ?? 0, $_POST['date'] ?? '', $_POST['category'] ?? 0);
    mitProcUnlock('bill');
    if (!$invoice_id) {
        flashAlert(escapeHtml($message), 'error');
        redirect();
    }
    flashAlert($message);
    redirect("invoice.php?invoice_id=$invoice_id");
}
