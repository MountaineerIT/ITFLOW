<?php

/*
 * Mountaineer IT - procurement (functions/mit_procurement.php): items to order, purchase orders,
 * approval, tracking and receiving. View needs Procurement 1, changes 2, approving / cancelling 3.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

mitModuleGuard('procurement', ['mit_po_', 'mit_proc_']);

// ---------- items to order ----------

if (isset($_POST['mit_proc_save_item'])) {

    validateCSRFToken();
    enforceUserPermission('module_procurement', 2);

    $item_id = intval($_POST['item_id'] ?? 0);
    $client_id = intval($_POST['client_id'] ?? 0);
    if ($client_id && !hasClientAccess($client_id)) {
        flashAlert('You do not have access to that client.', 'error');
        redirect();
    }
    $quantity = mitProcNumber($_POST['quantity'] ?? '');
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '' || !$quantity || $quantity <= 0) {
        flashAlert('An item needs a name and a quantity.', 'error');
        redirect();
    }
    $cost = mitProcNumber($_POST['unit_cost'] ?? '');
    if ($cost !== null && $cost < 0) {
        flashAlert('A cost cannot be below zero.', 'error');
        redirect();
    }
    $fields = [
        'name' => $name, 'quantity' => $quantity, 'unit_cost' => $cost, 'vendor_id' => intval($_POST['vendor_id'] ?? 0),
        'client_id' => $client_id, 'project_id' => intval($_POST['project_id'] ?? 0), 'note' => (string) ($_POST['note'] ?? ''),
        'product_id' => intval($_POST['product_id'] ?? 0),
    ];
    // Only vendors this agent may use, and a product that exists
    if (!isset(mitProcVendors()[$fields['vendor_id']])) {
        $fields['vendor_id'] = 0;
    }
    if ($fields['product_id'] && !getFieldById('products', $fields['product_id'], 'product_id')) {
        $fields['product_id'] = 0;
    }

    if ($item_id) {
        $item = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT proc.item_id, proc.source, proc.client_id, proc.project_id, proc.invoice_id, proc.invoice_item_id, proc.ticket_id, proc.product_id, proc.vendor_id, proc.name, proc.description, proc.quantity, proc.unit_cost, proc.status, proc.note, proc.created_by, proc.created_at, proc.updated_at FROM mit_procurement_items proc WHERE item_id = $item_id AND status = 'waiting'" . clientScopeSql('proc.client_id')));
        if (!$item) {
            flashAlert('That item is no longer waiting to be ordered.', 'error');
            redirect();
        }
        // An item from an invoice or ticket stays with that client; a project must be the client's own
        if ($item['source'] !== 'manual') {
            $client_id = intval($item['client_id']);
        }
        if ($fields['project_id'] && intval(getFieldById('projects', $fields['project_id'], 'project_client_id')) !== $client_id) {
            $fields['project_id'] = 0;
            $project_dropped = true;
        }
        // Ordered for an invoice line: not more than the line, less what else is on the list or ordered for it
        if (in_array($item['source'], ['invoice', 'quote'], true) && intval($item['invoice_item_id'])) {
            $line_qty = mysqli_fetch_row(mysqli_query($mysqli, "SELECT item_quantity FROM invoice_items WHERE item_id = " . intval($item['invoice_item_id'])));
            $others = (mitProcInvoiceQueued($item['invoice_id'])[intval($item['invoice_item_id'])] ?? 0) - (float) $item['quantity'];
            if ($line_qty && $quantity > round((float) $line_qty[0] - $others, 2)) {
                flashAlert('At most ' . mitQty(max(0, (float) $line_qty[0] - $others)) . ' - that is what is left of the invoice line.', 'error');
                redirect();
            }
        }
        mysqli_query($mysqli, "UPDATE mit_procurement_items SET name = '" . escapeSql(mb_substr($name, 0, 255)) . "', quantity = $quantity,
            unit_cost = " . ($cost === null ? 'NULL' : $cost) . ", vendor_id = " . $fields['vendor_id'] . ", client_id = $client_id, project_id = " . $fields['project_id'] . ",
            note = " . (trim($fields['note']) === '' ? 'NULL' : "'" . escapeSql(mb_substr($fields['note'], 0, 255)) . "'") . " WHERE item_id = $item_id");
        // A ticket's part follows its item
        mysqli_query($mysqli, "UPDATE mit_ticket_parts SET quantity = $quantity WHERE proc_item_id = $item_id AND invoice_id = 0");
        logAudit('Procurement', 'Edit', escapeSql(mitProcWho() . " changed $name on the list to order"), $client_id);
        flashAlert('Item <strong>' . escapeHtml($name) . '</strong> saved' . (!empty($project_dropped) ? ' - without the project, which belongs to another client' : ''), !empty($project_dropped) ? 'warning' : 'success');
    } else {
        if ($fields['project_id'] && intval(getFieldById('projects', $fields['project_id'], 'project_client_id')) !== $client_id) {
            $fields['project_id'] = 0;
            $project_dropped = true;
        }
        mitProcAddItem($fields);
        logAudit('Procurement', 'Create', escapeSql(mitProcWho() . " added $name to the list to order"), $client_id);
        flashAlert('Item <strong>' . escapeHtml($name) . '</strong> added to the list to order' . (!empty($project_dropped) ? ' - without the project, which belongs to another client' : ''), !empty($project_dropped) ? 'warning' : 'success');
    }
    redirect();
}

if (isset($_POST['mit_po_create']) || isset($_POST['mit_po_add_items']) || isset($_POST['mit_proc_set_vendor']) || isset($_POST['mit_proc_cancel_items'])) {

    validateCSRFToken();
    enforceUserPermission('module_procurement', 2);

    $ids = array_map('intval', (array) ($_POST['item_ids'] ?? []));
    $items = mitProcItemsByIds($ids);
    if (!$items) {
        flashAlert('Tick the items first.', 'error');
        redirect();
    }
    $item_ids = array_map('intval', array_column($items, 'item_id'));
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    $vendor_ok = $vendor_id && mysqli_num_rows(mysqli_query($mysqli, "SELECT vendor_id FROM vendors WHERE vendor_id = $vendor_id AND vendor_client_id = 0 AND vendor_archived_at IS NULL")) === 1;

    if (isset($_POST['mit_po_create'])) {
        if (!$vendor_ok) {
            flashAlert('Pick who to order from.', 'error');
            redirect();
        }
        $po_id = mitPoCreate($item_ids, $vendor_id);
        if (!$po_id) {
            flashAlert('The purchase order was not created - someone else is ordering right now, or the items are no longer waiting. Try again.', 'error');
            redirect();
        }
        flashAlert('Draft purchase order created - check the quantities and costs, then submit it');
        redirect("mit_purchase_order.php?po_id=$po_id");
    }

    if (isset($_POST['mit_po_add_items'])) {
        $po = mitPoRow($_POST['po_id'] ?? 0);
        if (!$po || $po['status'] !== 'draft' || !mitPoAccessible($po['po_id'])) {
            flashAlert('That PO is no longer a draft.', 'error');
            redirect();
        }
        $added = mitPoAddItems($po['po_id'], $item_ids);
        flashAlert("$added item" . ($added === 1 ? '' : 's') . ' added to ' . escapeHtml(mitPoReference($po)));
        redirect('mit_purchase_order.php?po_id=' . intval($po['po_id']));
    }

    if (isset($_POST['mit_proc_set_vendor'])) {
        if (!$vendor_ok) {
            flashAlert('Pick a vendor.', 'error');
            redirect();
        }
        // Still waiting: an item put on a PO meanwhile keeps the PO's vendor
        mysqli_query($mysqli, "UPDATE mit_procurement_items SET vendor_id = $vendor_id WHERE item_id IN (" . implode(',', $item_ids) . ") AND status = 'waiting'");
        logAudit('Procurement', 'Edit', escapeSql(mitProcWho() . ' set the vendor of ' . count($item_ids) . ' item(s) to order'));
        flashAlert(count($item_ids) . ' item' . (count($item_ids) === 1 ? '' : 's') . ' set to that vendor');
        redirect();
    }

    // Not needed - only items still waiting (one put on a PO meanwhile stays on it)
    mysqli_query($mysqli, "UPDATE mit_procurement_items SET status = 'cancelled' WHERE item_id IN (" . implode(',', $item_ids) . ") AND status = 'waiting'");
    $cancelled = max(0, mysqli_affected_rows($mysqli));
    logAudit('Procurement', 'Edit', escapeSql(mitProcWho() . " took $cancelled item(s) off the list to order"));
    flashAlert("$cancelled item" . ($cancelled === 1 ? '' : 's') . ' taken off the list', 'error');
    redirect();
}

// ---------- a purchase order ----------

if (isset($_POST['mit_po_save_lines'])) {

    validateCSRFToken();
    $po = mitPoForRequest($_POST['po_id'] ?? 0, 2);
    if ($po['status'] !== 'draft') {
        flashAlert('Only a draft PO can be changed - put it back to draft first.', 'error');
        redirect();
    }
    foreach ((array) ($_POST['lines'] ?? []) as $line_id => $line) {
        $qty = mitProcNumber($line['qty'] ?? '');
        $cost = mitProcNumber($line['cost'] ?? '');
        if ($qty === null || $qty <= 0 || $cost === null || $cost < 0) {
            continue;
        }
        mysqli_query($mysqli, "UPDATE mit_po_lines SET quantity = $qty, unit_cost = $cost WHERE line_id = " . intval($line_id) . " AND po_id = " . intval($po['po_id']));
        // Its item (what the invoice / ticket asked for) shows what is ordered
        mysqli_query($mysqli, "UPDATE mit_procurement_items proc JOIN mit_po_lines l ON l.proc_item_id = proc.item_id AND proc.item_id > 0
            SET proc.quantity = $qty WHERE l.line_id = " . intval($line_id) . " AND l.po_id = " . intval($po['po_id']));
        // A ticket's part follows the quantity ordered for it
        mysqli_query($mysqli, "UPDATE mit_ticket_parts parts JOIN mit_po_lines l ON l.proc_item_id = parts.proc_item_id AND parts.proc_item_id > 0
            SET parts.quantity = $qty WHERE l.line_id = " . intval($line_id) . " AND l.po_id = " . intval($po['po_id']) . " AND parts.invoice_id = 0");
    }
    $shipping = mitProcNumber($_POST['shipping'] ?? '');
    mysqli_query($mysqli, "UPDATE mit_purchase_orders SET shipping = " . max(0, (float) $shipping) . " WHERE po_id = " . intval($po['po_id']));
    mitPoRecalc($po['po_id']);
    mitPoLog($po['po_id'], mitProcWho() . ' changed the quantities and costs');
    flashAlert('Quantities and costs saved');
    redirect();
}

if (isset($_GET['mit_po_remove_line'])) {

    validateCSRFToken();
    $po = mitPoForRequest($_GET['po_id'] ?? 0, 2);
    if ($po['status'] !== 'draft' || !mitPoRemoveLine($po, $_GET['mit_po_remove_line'])) {
        flashAlert('That line could not be taken off.', 'error');
    } else {
        flashAlert('Taken off the PO - it is back on the list to order', 'error');
    }
    redirect();
}

if (isset($_GET['mit_po_submit'])) {

    validateCSRFToken();
    $po = mitPoForRequest($_GET['mit_po_submit'], 2);
    if ($po['status'] !== 'draft' || !mitPoLines($po['po_id'])) {
        flashAlert('Only a draft PO with items can be submitted.', 'error');
        redirect();
    }
    $status = mitPoSubmit($po);
    flashAlert($status === 'pending' ? 'Sent for approval - approvers have been told'
        : 'Submitted - ' . (mitProcSettings()['approval_on'] ? 'under the approval amount' : 'approval is switched off') . ', so it is approved and can be ordered');
    redirect();
}

if (isset($_POST['mit_po_decide'])) {

    validateCSRFToken();
    $po = mitPoForRequest($_POST['po_id'] ?? 0, 3);
    if ($po['status'] !== 'pending') {
        flashAlert('That PO is not waiting for approval.', 'error');
        redirect();
    }
    if (!mitPoMayApprove($po)) {
        flashAlert('You cannot approve this PO - someone else approves a PO you made or changed.', 'error');
        redirect();
    }
    $approve = ($_POST['decision'] ?? '') === 'approve';
    // Approve what was on screen: refused when the PO was changed and sent again while the window was open
    if ($approve && round((float) ($_POST['total'] ?? -1), 2) !== round((float) $po['total'], 2)) {
        flashAlert('This PO changed while you were looking at it (now ' . mitMoney($po['total']) . ') - open it again and check before approving.', 'error');
        redirect();
    }
    mitPoDecide($po, $approve, $_POST['note'] ?? '');
    flashAlert($approve ? 'Approved - it can be ordered' : 'Sent back to draft', $approve ? 'success' : 'error');
    redirect();
}

if (isset($_GET['mit_po_back_to_draft'])) {

    validateCSRFToken();
    $po = mitPoForRequest($_GET['mit_po_back_to_draft'], 2);
    if (!in_array($po['status'], ['pending', 'approved'], true)) {
        flashAlert('Only a PO not yet ordered can go back to draft.', 'error');
        redirect();
    }
    mysqli_query($mysqli, "UPDATE mit_purchase_orders SET status = 'draft', approved_by = 0, approved_at = NULL, approval_note = NULL WHERE po_id = " . intval($po['po_id']));
    mitPoLog($po['po_id'], mitProcWho() . ' put it back to draft' . ($po['status'] === 'approved' ? ' (it will need approving again if over the amount)' : ''));
    flashAlert('Back to draft');
    redirect();
}

if (isset($_POST['mit_po_ordered'])) {

    validateCSRFToken();
    $po = mitPoForRequest($_POST['po_id'] ?? 0, 2);
    if ($po['status'] !== 'approved') {
        flashAlert('Only an approved PO can be ordered.', 'error');
        redirect();
    }
    mitPoMarkOrdered($po, $_POST['order_date'] ?? '', $_POST['vendor_order_number'] ?? '');
    flashAlert('Marked ordered - it is on the Receiving list');
    redirect();
}

if (isset($_POST['mit_po_bill'])) {

    validateCSRFToken();
    enforceUserPermission('module_financial', 2);
    $po = mitPoForRequest($_POST['po_id'] ?? 0, 2);
    if (!in_array($po['status'], ['ordered', 'partial', 'received'], true)) {
        flashAlert('Record the bill once the PO is ordered.', 'error');
        redirect();
    }
    [$expense_id, $message] = mitPoRecordBill($po, $_POST);
    flashAlert(escapeHtml($message), $expense_id ? 'success' : 'error');
    redirect();
}

if (isset($_POST['mit_po_email'])) {

    validateCSRFToken();
    $po = mitPoForRequest($_POST['po_id'] ?? 0, 2);
    if (!in_array($po['status'], ['approved', 'ordered', 'partial'], true)) {
        flashAlert('Only an approved PO can be sent to the vendor.', 'error');
        redirect();
    }
    $error = mitPoEmail($po, trim((string) ($_POST['email'] ?? '')));
    flashAlert($error === '' ? 'The PO is on its way to the vendor' : escapeHtml($error), $error === '' ? 'success' : 'error');
    redirect();
}

if (isset($_POST['mit_po_details'])) {

    validateCSRFToken();
    $po = mitPoForRequest($_POST['po_id'] ?? 0, 2);
    if ($po['status'] === 'cancelled') {
        flashAlert('A cancelled PO cannot be changed.', 'error');
        redirect();
    }
    $set = [];
    $vendor_id = intval($_POST['vendor_id'] ?? 0);
    if ($po['status'] === 'draft' && $vendor_id && mysqli_num_rows(mysqli_query($mysqli, "SELECT vendor_id FROM vendors WHERE vendor_id = $vendor_id AND vendor_client_id = 0")) === 1) {
        $set[] = "vendor_id = $vendor_id";
    }
    $order_date = (string) ($_POST['order_date'] ?? '');
    $set[] = 'order_date = ' . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $order_date) ? "'$order_date'" : 'NULL');
    foreach (['vendor_order_number' => 100, 'ship_to_address' => 2000, 'note' => 5000] as $field => $max) {
        $value = trim((string) ($_POST[$field] ?? ''));
        $set[] = "$field = " . ($value === '' ? 'NULL' : "'" . escapeSql(mb_substr($value, 0, $max)) . "'");
    }
    mysqli_query($mysqli, "UPDATE mit_purchase_orders SET " . implode(', ', $set) . " WHERE po_id = " . intval($po['po_id']));
    mitPoLog($po['po_id'], mitProcWho() . ' changed the details');
    flashAlert('Details saved');
    redirect();
}

if (isset($_POST['mit_po_tracking_add'])) {

    validateCSRFToken();
    $po = mitPoForRequest($_POST['po_id'] ?? 0, 2);
    $number = trim((string) ($_POST['tracking_number'] ?? ''));
    $carrier = isset(MIT_CARRIERS[$_POST['carrier'] ?? '']) ? $_POST['carrier'] : 'other';
    if ($number === '') {
        flashAlert('Enter the tracking number.', 'error');
        redirect();
    }
    $eta = (string) ($_POST['eta'] ?? '');
    $note = trim((string) ($_POST['note'] ?? ''));
    mysqli_query($mysqli, "INSERT INTO mit_po_tracking SET po_id = " . intval($po['po_id']) . ", carrier = '$carrier', tracking_number = '" . escapeSql(mb_substr($number, 0, 100)) . "',
        note = " . ($note === '' ? 'NULL' : "'" . escapeSql(mb_substr($note, 0, 255)) . "'") . ", eta = " . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $eta) ? "'$eta'" : 'NULL'));
    mitPoLog($po['po_id'], mitProcWho() . ' added tracking - ' . MIT_CARRIERS[$carrier][0] . " $number");
    logAudit('Purchase Order', 'Edit', escapeSql(mitProcWho() . ' added tracking to ' . mitPoReference($po)), 0, intval($po['po_id']));
    flashAlert('Tracking added');
    redirect();
}

if (isset($_GET['mit_po_tracking_delivered']) || isset($_GET['mit_po_tracking_delete'])) {

    validateCSRFToken();
    $tracking_id = intval($_GET['mit_po_tracking_delivered'] ?? $_GET['mit_po_tracking_delete']);
    $tracking = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT tracking_id, po_id, carrier, tracking_number FROM mit_po_tracking WHERE tracking_id = $tracking_id"));
    if (!$tracking) {
        flashAlert('That tracking number is gone.', 'error');
        redirect();
    }
    $po = mitPoForRequest($tracking['po_id'], 2);
    if (isset($_GET['mit_po_tracking_delivered'])) {
        mysqli_query($mysqli, "UPDATE mit_po_tracking SET delivered = 1 WHERE tracking_id = $tracking_id");
        mitPoLog($po['po_id'], mitProcWho() . ' marked ' . $tracking['tracking_number'] . ' delivered');
        flashAlert('Marked delivered');
    } else {
        mysqli_query($mysqli, "DELETE FROM mit_po_tracking WHERE tracking_id = $tracking_id");
        mitPoLog($po['po_id'], mitProcWho() . ' removed tracking ' . $tracking['tracking_number']);
        logAudit('Purchase Order', 'Edit', escapeSql(mitProcWho() . ' removed tracking from ' . mitPoReference($po)), 0, intval($po['po_id']));
        flashAlert('Tracking removed', 'error');
    }
    redirect();
}

if (isset($_GET['mit_po_cancel'])) {

    validateCSRFToken();
    $po = mitPoForRequest($_GET['mit_po_cancel'], 3);
    $received = (float) mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(received_qty), 0) FROM mit_po_lines WHERE po_id = " . intval($po['po_id'])))[0];
    if (in_array($po['status'], ['received', 'cancelled'], true) || $received > 0) {
        flashAlert('A PO with items already received cannot be cancelled.', 'error');
        redirect();
    }
    mitPoCancel($po);
    flashAlert('PO cancelled - its items are back on the list to order', 'error');
    redirect();
}

if (isset($_GET['mit_po_delete'])) {

    validateCSRFToken();
    $po = mitPoForRequest($_GET['mit_po_delete'], 2);
    if ($po['status'] !== 'draft') {
        flashAlert('Only a draft PO can be deleted - cancel it instead.', 'error');
        redirect();
    }
    $po_id = intval($po['po_id']);
    mysqli_query($mysqli, "UPDATE mit_procurement_items proc JOIN mit_po_lines l ON l.proc_item_id = proc.item_id SET proc.status = 'waiting' WHERE l.po_id = $po_id");
    mysqli_query($mysqli, "DELETE FROM mit_purchase_orders WHERE po_id = $po_id");
    logAudit('Purchase Order', 'Delete', escapeSql(mitProcWho() . ' deleted draft ' . mitPoReference($po)));
    flashAlert('Draft ' . escapeHtml(mitPoReference($po)) . ' deleted - its items are back on the list', 'error');
    redirect('mit_procurement.php');
}

if (isset($_GET['mit_po_pdf'])) {

    $po = mitPoForRequest($_GET['mit_po_pdf'], 1);
    mitPoPdf($po);
}

// ---------- receiving ----------

if (isset($_POST['mit_po_receive'])) {

    validateCSRFToken();
    enforceUserPermission('module_procurement', 2);

    // One receive at a time, so a double-click cannot receive (and make assets or stock for) the same items twice
    mitProcLock('receive');
    [$lines, $pos, $extras] = mitPoReceive((array) ($_POST['received'] ?? []), mitProcReceiveOptions((array) ($_POST['options'] ?? [])));
    mitProcUnlock('receive');
    if (!$lines) {
        flashAlert('Nothing was ticked as received.', 'error');
        redirect();
    }
    $also = [];
    if ($extras['assets']) {
        $also[] = $extras['assets'] . ' asset' . ($extras['assets'] === 1 ? '' : 's') . ' added';
    }
    if ($extras['stock']) {
        $also[] = $extras['stock'] . ' into stock';
    }
    if ($extras['notes']) {
        $also[] = $extras['notes'] . ' ticket note' . ($extras['notes'] === 1 ? '' : 's');
    }
    if (!empty($extras['capped'])) {
        $also[] = 'assets stop at 50 per line - add the rest by hand';
    }
    logAudit('Purchase Order', 'Edit', escapeSql(mitProcWho() . " received $lines line(s) on $pos PO(s)"));
    flashAlert("Received $lines line" . ($lines === 1 ? '' : 's') . " on $pos PO" . ($pos === 1 ? '' : 's') . ($also ? ' - ' . implode(', ', $also) : ''));
    // "Fill in each asset's details": back to Receiving, which opens ITFlow's asset window for each new asset in turn
    if (!empty($_POST['options']['review']) && $extras['asset_ids']) {
        $back = (string) ($_SERVER['HTTP_REFERER'] ?? 'mit_receiving.php');
        $back = preg_replace('/([?&])mit_review_(assets|total)=[^&]*&?/', '$1', $back);
        redirect(rtrim($back, '?&') . (strpos(rtrim($back, '?&'), '?') === false ? '?' : '&') . 'mit_review_assets=' . implode(',', array_map('intval', $extras['asset_ids']))
            . '&mit_review_total=' . count($extras['asset_ids']));
    }
    redirect();
}
