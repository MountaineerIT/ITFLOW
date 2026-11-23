<?php

/*
 * Mountaineer IT - procurement part 3: what our build adds to ITFlow 26.12's Purchasing (functions/purchase_orders.php).
 * Required from functions/mit_procurement.php. Hooks, one guarded line each in ITFlow's files:
 *
 * - Receiving (poReceive, after each receipt row - mitProcAfterReceive): a ticket part is never put on the shelf
 *   (mitProcReceiveNoShelf), its ticket gets an internal note and the assets made are linked to the ticket and its part;
 *   an invoice line's product that ITFlow had already taken off the shelf is given back and the line loses its product;
 *   what was done is kept in mit_po_receipt_extras (M068) so poUndoReceipt can undo it (mitProcUndoReceiptExtras).
 *   "Fill in each asset's details" on agent/receiving.php opens ITFlow's asset window for each new asset in turn.
 * - Order status: each invoice line (mitProcInvoiceLineStatus via mitPaperLineExtras), the invoice (mitProcInvoiceSummary)
 *   and a Purchase orders card on the project page (mitProcProjectCard in agent/project.php).
 * - A PO's lines on a project (mit_po_item_projects): "Link to a project" on ITFlow's PO page.
 */

/**
 * A ticket moved to another client (mitTicketClientMoved): its parts still waiting to be ordered, and PO lines for them not yet
 * fully received, belong to the new client now - so client access, receiving's assets and To Order follow the ticket
 * (as its ticket parts do, MIT_PART_CLIENT_SQL). What was already received stays where it went.
 */
function mitProcTicketClientMoved($ticket_id, $client_id) {
    global $mysqli;
    if (!mitProcReady()) {
        return;
    }
    $ticket_id = intval($ticket_id);
    $client_id = intval($client_id);
    $items = "SELECT order_item_id FROM mit_order_item_links WHERE ticket_id = $ticket_id";
    mysqli_query($mysqli, "UPDATE po_items SET po_item_client_id = $client_id
        WHERE po_item_order_item_id > 0 AND po_item_order_item_id IN ($items) AND po_item_received < po_item_quantity");
    mysqli_query($mysqli, "UPDATE order_items SET order_item_client_id = $client_id WHERE order_item_id IN ($items) AND order_item_status NOT IN ('received', 'cancelled')");
    // ITFlow takes the ticket out of its project and unlinks its assets on a client change: the parts and orders follow
    mysqli_query($mysqli, "UPDATE mit_order_item_links SET project_id = 0 WHERE ticket_id = $ticket_id
        AND project_id > 0 AND project_id NOT IN (SELECT project_id FROM projects WHERE project_client_id = $client_id)");
    mysqli_query($mysqli, "UPDATE mit_po_item_projects pp JOIN po_items l ON l.po_item_id = pp.po_item_id SET pp.project_id = 0
        WHERE l.po_item_order_item_id > 0 AND l.po_item_order_item_id IN ($items) AND l.po_item_received < l.po_item_quantity
            AND pp.project_id NOT IN (SELECT project_id FROM projects WHERE project_client_id = $client_id)");
    if (mitTicketPartsReady()) {
        mysqli_query($mysqli, "UPDATE mit_ticket_parts SET asset_id = 0 WHERE ticket_id = $ticket_id AND asset_id > 0
            AND asset_id NOT IN (SELECT asset_id FROM assets WHERE asset_client_id = $client_id)");
    }
}

/*
 * ---------- receiving: hooks in ITFlow's poReceive ----------
 */

/**
 * Hook in poReceive's "our own stock goes on the shelf" condition: true = not on the shelf. A ticket part (its item came from
 * a ticket) goes to the ticket, even one without a client - it was bought for that work, not for stock.
 */
function mitProcReceiveNoShelf(array $line) {
    $link = mitProcItemLink($line['po_item_order_item_id'] ?? 0);
    return $link && ($link['source'] === 'ticket' || intval($link['ticket_id']) > 0);
}

/**
 * Hook in poReceive right after each receipt row is written (inside its transaction). $line: ITFlow's line row (po_items +
 * po_prefix / po_number), $serials: the serials typed, $asset_ids: the assets ITFlow made from it. Does what our build adds:
 *  - a ticket part: the assets are linked to the ticket and the part points at its first one, the ticket gets an internal note
 *    (Support edit - as ITFlow's reply form);
 *  - ordered for an invoice line: the assets say so (asset_invoice_id - the invoice's Hardware box counts them); the stock ITFlow
 *    took off the shelf for that line when it was added goes back and the line loses its item_product_id - ITFlow decides by
 *    item_product_id alone (deleting the line or the invoice returns its quantity, a quantity change moves stock), so without
 *    that it would put the same stock back twice. The item keeps the product (order_item_product_id), which is how a later item
 *    for the rest of the line still finds it (mitProcInvoiceLineProducts). Quote lines invoiced are treated the same.
 * What it did is kept in mit_po_receipt_extras so ITFlow's undo can undo it (mitProcUndoReceiptExtras).
 */
function mitProcAfterReceive(array $line, $qty, array $serials, $receipt_id, array $asset_ids) {
    global $mysqli, $session_user_id;
    $receipt_id = intval($receipt_id);
    if (!$receipt_id || !mitProcReady()) {
        return;
    }
    $link = mitProcItemLink($line['po_item_order_item_id'] ?? 0);
    $product_id = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT order_item_product_id FROM order_items WHERE order_item_id = " . intval($line['po_item_order_item_id'] ?? 0)))[0] ?? 0)
        ?: intval($line['po_item_product_id'] ?? 0);
    $reference = mitPoReference($line);
    $extras = ['return_product_id' => 0, 'return_qty' => 0, 'return_invoice_id' => 0, 'return_item_id' => 0, 'cleared_item_id' => 0, 'note_reply_id' => 0];
    $asset_ids = array_values(array_filter(array_map('intval', $asset_ids)));
    if ($asset_ids) {
        $GLOBALS['mit_po_received_assets'] = array_merge($GLOBALS['mit_po_received_assets'] ?? [], $asset_ids);
    }

    $ticket_id = intval($link['ticket_id'] ?? 0);
    if ($ticket_id && !mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM tickets WHERE ticket_id = $ticket_id"))) {
        $ticket_id = 0;
    }
    if ($ticket_id && $asset_ids) {
        foreach ($asset_ids as $asset_id) {
            mysqli_query($mysqli, "INSERT IGNORE INTO ticket_assets SET ticket_id = $ticket_id, asset_id = $asset_id");
        }
        if (mitTicketPartsReady()) {
            mysqli_query($mysqli, "UPDATE mit_ticket_parts SET asset_id = " . $asset_ids[0] . " WHERE proc_item_id = " . intval($line['po_item_order_item_id']) . " AND asset_id = 0");
        }
    }

    if ($link && in_array($link['source'], ['invoice', 'quote'], true) && intval($link['invoice_item_id'])) {
        $invoice_item_id = intval($link['invoice_item_id']);
        $invoice_id = intval($link['invoice_id']);
        if ($asset_ids && mitColumnReady('assets', 'asset_invoice_id')) {
            mysqli_query($mysqli, "UPDATE assets SET asset_invoice_id = $invoice_id, asset_invoice_item_id = $invoice_item_id WHERE asset_id IN (" . implode(',', $asset_ids) . ")");
        }
        if ($product_id && mitProductTracksStock($product_id)) {
            $invoice_line = mysqli_fetch_row(mysqli_query($mysqli, "SELECT item_product_id FROM invoice_items WHERE item_id = $invoice_item_id"));
            // Only what ITFlow's stock record shows is still taken: by this line, and by the invoice for this product (a cancelled
            // invoice was given its stock back by ITFlow, and a line converted from a quote as "to order" never took any)
            $taken = min(
                -(float) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(stock_qty), 0) FROM product_stock WHERE stock_item_id = $invoice_item_id AND stock_invoice_id = $invoice_id AND stock_product_id = $product_id"))[0] ?? 0),
                -(float) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(stock_qty), 0) FROM product_stock WHERE stock_invoice_id = $invoice_id AND stock_product_id = $product_id"))[0] ?? 0)
            );
            $back = round(min(round((float) $qty, 2), $taken), 2);
            if ($invoice_line && in_array(intval($invoice_line[0]), [0, $product_id], true) && $back > 0) {
                mitStockMove($product_id, $back, 'returned', "Ordered on $reference for the invoice instead of taken from stock",
                    ['invoice_id' => $invoice_id, 'item_id' => $invoice_item_id]);
                $extras['return_product_id'] = $product_id;
                $extras['return_qty'] = $back;
                $extras['return_invoice_id'] = $invoice_id;
                $extras['return_item_id'] = $invoice_item_id;
            }
            if ($invoice_line && intval($invoice_line[0]) === $product_id) {
                mysqli_query($mysqli, "UPDATE invoice_items SET item_product_id = 0 WHERE item_id = $invoice_item_id");
                $extras['cleared_item_id'] = $invoice_item_id;
                $extras['return_product_id'] = $product_id;
            }
        }
    }

    // A ticket part: say so on the ticket
    if ($ticket_id && lookupUserPermission('module_support') >= 2) {
        $text = 'Received ' . mitQty($qty) . ' × ' . $line['po_item_name'] . " on $reference" . ($serials ? ' (serials: ' . implode(', ', $serials) . ')' : '')
            . ($asset_ids ? ' - ' . count($asset_ids) . ' asset' . (count($asset_ids) === 1 ? '' : 's') . ' added to the ticket' : '') . '.';
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . escapeSql(escapeHtml($text)) . "',
            ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = " . intval($session_user_id) . ", ticket_reply_ticket_id = $ticket_id");
        $extras['note_reply_id'] = intval(mysqli_insert_id($mysqli));
    }

    mysqli_query($mysqli, "INSERT INTO mit_po_receipt_extras SET po_receipt_id = $receipt_id, tracked = 1, stock_product_id = 0, stock_qty = 0,
        return_product_id = {$extras['return_product_id']}, return_qty = " . round((float) $extras['return_qty'], 2) . ",
        return_invoice_id = {$extras['return_invoice_id']}, return_item_id = {$extras['return_item_id']},
        cleared_item_id = {$extras['cleared_item_id']}, note_reply_id = {$extras['note_reply_id']}
        ON DUPLICATE KEY UPDATE po_receipt_id = po_receipt_id");
}

/**
 * Hook in poUndoReceipt, inside its transaction before the receipt row goes: undo what mitProcAfterReceive did - stock an
 * invoice line was given back is taken for that line again (Sales edit), the line gets its product back, ticket parts no longer
 * point at the assets ITFlow deletes, and the ticket gets a note. Throws to refuse (ITFlow rolls back and shows why).
 * Receipts from before M062 (tracked 0) knew nothing of stock - ITFlow undoes their shelf stock (M068 found it); the ticket note only.
 */
function mitProcUndoReceiptExtras(array $receipt) {
    global $mysqli;
    if (!mitProcReady()) {
        return;
    }
    $receipt_id = intval($receipt['po_receipt_id']);
    $extras = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_po_receipt_extras WHERE po_receipt_id = $receipt_id"));
    $reference = mitPoReference($receipt);
    $qty = (float) $receipt['po_receipt_quantity'];
    if ($extras && (float) $extras['return_qty'] > 0 && lookupUserPermission('module_sales') < 2) {
        throw new RuntimeException('undoing it takes stock for the invoice again - that needs Sales edit access');
    }
    $asset_ids = array_values(array_filter(array_map('intval', explode(',', (string) $receipt['po_receipt_asset_ids']))));
    if ($asset_ids && mitTicketPartsReady()) {
        mysqli_query($mysqli, "UPDATE mit_ticket_parts SET asset_id = 0 WHERE asset_id IN (" . implode(',', $asset_ids) . ")");
    }
    if ($extras && (float) $extras['return_qty'] > 0) {
        mitStockMove(intval($extras['return_product_id']), -(float) $extras['return_qty'], 'returned', "Receipt on $reference undone - taken for the invoice again",
            ['invoice_id' => intval($extras['return_invoice_id']), 'item_id' => intval($extras['return_item_id'])]);
    }
    if ($extras && intval($extras['cleared_item_id']) && intval($extras['return_product_id'])) {
        mysqli_query($mysqli, "UPDATE invoice_items SET item_product_id = " . intval($extras['return_product_id']) . " WHERE item_id = " . intval($extras['cleared_item_id']) . " AND item_product_id = 0");
    }
    $order_item_id = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT po_item_order_item_id FROM po_items WHERE po_item_id = " . intval($receipt['po_receipt_item_id'])))[0] ?? 0);
    $link = mitProcItemLink($order_item_id);
    $ticket_id = intval($link['ticket_id'] ?? 0);
    $noted = $extras ? (intval($extras['tracked']) === 1 ? intval($extras['note_reply_id']) > 0 : true) : false;
    if ($ticket_id && $noted && mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM tickets WHERE ticket_id = $ticket_id"))) {
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . escapeSql(escapeHtml('Receipt of ' . mitQty($qty) . ' × ' . $receipt['po_item_name'] . " on $reference undone - it is waiting to arrive again.")) . "',
            ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = " . intval($GLOBALS['session_user_id'] ?? 0) . ", ticket_reply_ticket_id = $ticket_id");
    }
}

/**
 * "Fill in each asset's details" on ITFlow's Receiving (one line in agent/receiving.php, before its script): a tick in the
 * form's footer (Support edit), and - after receiving came back with ?mit_review_assets=1,2,3 - ITFlow's own asset window
 * for each new asset in turn. The address drops each one as its window opens, so saving (which comes back here) carries on
 * with the rest; Cancel skips it.
 */
function mitProcReceivingExtras() {
    if (lookupUserPermission('module_support') < 2) {
        return '';
    }
    $review = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['mit_review_assets'] ?? '')))));
    $html = '<script>
document.addEventListener("DOMContentLoaded", function () {
    var foot = document.querySelector("form[data-receiving] .card-footer");
    if (foot && !foot.querySelector("[data-mit-review]")) {
        var box = document.createElement("div");
        box.className = "form-check mb-0";
        box.title = "Opens the asset window for each new asset in turn - rename it, add make, model, status, assignment";
        box.innerHTML = \'<input class="form-check-input" type="checkbox" name="mit_review" value="1" id="mitReview" data-mit-review checked><label class="form-check-label" for="mitReview">Fill in each asset\\\'s details</label>\';
        foot.insertBefore(box, foot.querySelector("button[name=po_receive]"));
    }
});
</script>';
    if ($review) {
        $html .= '<script>
document.addEventListener("DOMContentLoaded", function () {
    var queue = ' . json_encode($review) . ';
    var total = Math.max(' . intval($_GET['mit_review_total'] ?? 0) . ', queue.length);
    function address(rest) {
        var url = new URL(location.href);
        if (rest.length) {
            url.searchParams.set("mit_review_assets", rest.join(","));
        } else {
            url.searchParams.delete("mit_review_assets");
            url.searchParams.delete("mit_review_total");
        }
        return url.pathname + url.search;
    }
    function next() {
        var id = queue.shift();
        if (!id) {
            history.replaceState(null, "", address([]));
            return;
        }
        history.replaceState(null, "", address(queue));
        var opener = document.createElement("a");
        opener.className = "ajax-modal d-none";
        opener.dataset.modalSize = "lg";
        opener.dataset.modalUrl = "modals/asset/asset_edit.php?id=" + id;
        document.body.appendChild(opener);
        opener.click();
        opener.remove();
        var watch = setInterval(function () {
            var modal = document.querySelector(".modal.show");
            if (!modal || !modal.querySelector("form")) return;
            clearInterval(watch);
            var title = modal.querySelector(".modal-title");
            if (title) title.insertAdjacentHTML("beforeend", " <span class=\"badge text-bg-light ms-2\" data-mit-review-step>New asset " + (total - queue.length) + " of " + total + "</span>");
            var saving = false;
            modal.querySelector("form").addEventListener("submit", function () { saving = true; });
            modal.addEventListener("hidden.bs.modal", function () { if (!saving) next(); }, { once: true });
        }, 100);
    }
    next();
});
</script>';
    }
    return $html;
}

/**
 * Hook in ITFlow's po_receive handler (agent/post/purchase_order.php), after poReceive: "Fill in each asset's details" ticked
 * and assets made -> back to Receiving with them, which opens ITFlow's asset window for each (mitProcReceivingExtras).
 */
function mitProcReceiveReview(array $post) {
    $asset_ids = array_values(array_filter(array_map('intval', $GLOBALS['mit_po_received_assets'] ?? [])));
    if (empty($post['mit_review']) || !$asset_ids || lookupUserPermission('module_support') < 2) {
        return;
    }
    $po_id = intval($post['po_id'] ?? 0);
    redirect('receiving.php?' . ($po_id ? "po_id=$po_id&" : '') . 'mit_review_assets=' . implode(',', $asset_ids) . '&mit_review_total=' . count($asset_ids));
}

/*
 * ---------- where order status shows ----------
 */

/**
 * Procurement for an invoice's lines, invoice_item_id => ['qty', 'received', 'pos' => [po_id => ref]].
 * From items sent from the invoice (or its quote) and from ticket parts billed on it.
 */
function mitProcInvoiceStatus($invoice_id) {
    global $mysqli;
    static $cache = [];
    $invoice_id = intval($invoice_id);
    if (isset($cache[$invoice_id])) {
        return $cache[$invoice_id];
    }
    $status = [];
    if (mitProcReady()) {
        $parts = mitTicketPartsReady() ? " OR proc.order_item_id IN (SELECT proc_item_id FROM mit_ticket_parts WHERE invoice_id = $invoice_id AND proc_item_id > 0)" : '';
        $result = mysqli_query($mysqli, "SELECT proc.order_item_id, proc.order_item_status AS status, proc.order_item_quantity AS quantity,
                IF(links.invoice_id = $invoice_id, links.invoice_item_id, (SELECT invoice_item_id FROM mit_ticket_parts WHERE proc_item_id = proc.order_item_id LIMIT 1)) AS line_id,
                l.po_item_received AS received_qty, po.po_id, po.po_prefix, po.po_number
            FROM order_items proc
            LEFT JOIN mit_order_item_links links ON links.order_item_id = proc.order_item_id
            LEFT JOIN po_items l ON l.po_item_order_item_id = proc.order_item_id AND l.po_item_po_id IN (SELECT po_id FROM purchase_orders WHERE po_status <> 'cancelled')
            LEFT JOIN purchase_orders po ON po.po_id = l.po_item_po_id
            WHERE proc.order_item_status <> 'cancelled' AND (links.invoice_id = $invoice_id $parts)");
        while ($row = mysqli_fetch_assoc($result)) {
            $line_id = intval($row['line_id']);
            $entry = $status[$line_id] ?? ['qty' => 0, 'received' => 0, 'waiting' => 0, 'pos' => []];
            $entry['qty'] += (float) $row['quantity'];
            $entry['received'] += $row['status'] === 'received' ? (float) $row['quantity'] : min((float) $row['received_qty'], (float) $row['quantity']);
            if ($row['po_id']) {
                $entry['pos'][intval($row['po_id'])] = $row['po_prefix'] . $row['po_number'];
            } else {
                $entry['waiting'] += (float) $row['quantity'];
            }
            $status[$line_id] = $entry;
        }
    }
    return $cache[$invoice_id] = $status;
}

/** Under an invoice line's name: its order status (staff only, not printed). */
function mitProcInvoiceLineStatus($invoice_id, $item_id) {
    if (mitProcLevel() < 1) {
        return '';
    }
    $entry = mitProcInvoiceStatus($invoice_id)[intval($item_id)] ?? null;
    if (!$entry) {
        return '';
    }
    $links = [];
    foreach ($entry['pos'] as $po_id => $reference) {
        $links[] = '<a href="' . mitPoUrl($po_id) . '">' . escapeHtml($reference) . '</a>';
    }
    if ($entry['received'] + 0.001 >= $entry['qty']) {
        $badge = '<span class="badge rounded-pill text-bg-success">Received ' . mitQty($entry['received']) . '/' . mitQty($entry['qty']) . '</span>';
    } elseif ($entry['received'] > 0) {
        $badge = '<span class="badge rounded-pill text-bg-warning">' . mitQty($entry['received']) . ' of ' . mitQty($entry['qty']) . ' received</span>';
    } elseif ($entry['waiting'] >= $entry['qty']) {
        $badge = '<span class="badge rounded-pill text-bg-secondary">Waiting to order</span>';
    } else {
        $badge = '<span class="badge rounded-pill text-bg-primary">On order</span>';
    }
    return '<div class="small d-print-none mt-1">' . $badge . ' ' . implode(', ', $links) . '</div>';
}

/** Over an invoice's lines: "Hardware: 17 of 21 received" (staff only, not printed). */
function mitProcInvoiceSummary($invoice_id) {
    if (mitProcLevel() < 1) {
        return '';
    }
    $status = mitProcInvoiceStatus($invoice_id);
    if (!$status) {
        return '';
    }
    $qty = array_sum(array_column($status, 'qty'));
    $received = array_sum(array_column($status, 'received'));
    $done = $received + 0.001 >= $qty;
    return '<div class="alert ' . ($done ? 'alert-success' : 'alert-warning') . ' d-print-none py-2 mb-3"><i class="fas fa-fw fa-truck-loading me-2"></i>'
        . 'Ordered items: <strong>' . mitQty($received) . ' of ' . mitQty($qty) . ' received</strong>' . ($done ? '' : ' - see each line below') . '</div>';
}

/** The project page's Purchase orders card (agent/project.php, under the tabs). */
function mitProcProjectCard($project_id) {
    global $mysqli;
    if (mitProcLevel() < 1) {
        return '';
    }
    $project_id = intval($project_id);
    if (!mitProcReady()) {
        return '';
    }
    $pos = mysqli_fetch_all(mysqli_query($mysqli, "SELECT po.po_id, po.po_prefix, po.po_number, po.po_status AS status, po.po_vendor_reference AS vendor_order_number, vendor_name,
            SUM(l.po_item_quantity * l.po_item_cost) AS cost, SUM(l.po_item_quantity) AS qty, SUM(LEAST(l.po_item_received, l.po_item_quantity)) AS received
        FROM po_items l
        LEFT JOIN mit_po_item_projects pp ON pp.po_item_id = l.po_item_id
        LEFT JOIN mit_order_item_links links ON links.order_item_id = l.po_item_order_item_id AND l.po_item_order_item_id > 0
        JOIN purchase_orders po ON po.po_id = l.po_item_po_id
        LEFT JOIN vendors ON vendors.vendor_id = po.po_vendor_id
        WHERE " . MIT_PO_LINE_PROJECT_SQL . " = $project_id AND po.po_status <> 'cancelled'" . poScopeSql('po.po_id') . "
        GROUP BY po.po_id ORDER BY po.po_id DESC"), MYSQLI_ASSOC);
    $tracking = poTrackingFor(array_column($pos, 'po_id'));
    $waiting = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS n, COALESCE(SUM(order_item_quantity * COALESCE(order_item_cost, 0)), 0) AS cost FROM order_items proc
        JOIN mit_order_item_links links ON links.order_item_id = proc.order_item_id
        WHERE links.project_id = $project_id AND proc.order_item_status = 'waiting'" . clientScopeSql('proc.order_item_client_id')));
    if (!$pos && !intval($waiting['n'])) {
        return '';
    }
    $cost = array_sum(array_column($pos, 'cost')) + (float) $waiting['cost'];
    $qty = array_sum(array_column($pos, 'qty'));
    $received = array_sum(array_column($pos, 'received'));
    $html = '<div class="card mb-3"><div class="card-header"><h3 class="card-title mb-0"><i class="fas fa-fw fa-file-signature text-secondary me-2"></i>Purchase orders</h3></div>'
        . '<div class="card-body pb-0"><div class="row g-2 mb-3">'
        . '<div class="col-sm-4"><div class="border rounded p-2"><div class="small text-muted">Hardware cost</div><div class="fs-5 fw-semibold">' . mitMoney($cost) . '</div></div></div>'
        . '<div class="col-sm-4"><div class="border rounded p-2"><div class="small text-muted">Received</div><div class="fs-5 fw-semibold">' . mitQty($received) . ' of ' . mitQty($qty) . '</div></div></div>'
        . '<div class="col-sm-4"><div class="border rounded p-2"><div class="small text-muted">Still to order</div><div class="fs-5 fw-semibold">' . intval($waiting['n']) . ' item' . (intval($waiting['n']) === 1 ? '' : 's') . '</div></div></div>'
        . '</div></div>';
    if ($pos) {
        $html .= '<div class="table-responsive"><table class="table table-sm table-borderless mb-0 align-middle"><thead class="small text-muted border-bottom"><tr><th class="ps-3">PO</th><th>Vendor</th><th>Vendor order #</th><th>Tracking</th><th class="pe-3">Status</th></tr></thead><tbody>';
        foreach ($pos as $po) {
            $po_tracking = array_map('poTrackingHtml', $tracking[intval($po['po_id'])] ?? []);
            $html .= '<tr class="border-bottom"><td class="ps-3"><a href="' . mitPoUrl($po['po_id']) . '">' . escapeHtml($po['po_prefix'] . $po['po_number']) . '</a></td>'
                . '<td>' . escapeHtml($po['vendor_name']) . '</td><td class="font-monospace small">' . escapeHtml($po['vendor_order_number'] ?: '-') . '</td>'
                . '<td class="small">' . ($po_tracking ? implode('<br>', $po_tracking) : '<span class="text-muted">-</span>') . '</td>'
                . '<td class="pe-3">' . poStatusBadge($po['status']) . ' <span class="small text-muted">' . mitQty($po['received']) . '/' . mitQty($po['qty']) . '</span></td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    return $html . '</div>';
}

/*
 * ---------- a PO's lines on a project, after it was made (26.11.3.5, the user's request) ----------
 *
 * A line takes its project from its item when the PO is made, and nothing changed it after - so items ordered before the project
 * was set up never showed on it. "Link to a project" on ITFlow's PO page (mitPoProjectMenuItem -> modals/mit_procurement/po_project.php,
 * handler mit_po_set_project in agent/post/mit_procurement_parts.php; a line's project in mit_po_item_projects) puts the
 * ticked lines - and the items they came from - on a project, or takes them off, at any status but cancelled. A line for another
 * client can't go on a client's project; a line for nobody takes the project's client while nothing of it has been received
 * (what was received went to stock or became assets as it was).
 */

/**
 * A PO line's project: set on the line ("Link to a project" - mit_po_item_projects, 0 = taken off), else the one its item came
 * with (mit_order_item_links - a part for a project ticket, an invoice line sent with a project). Needs the joins pp and links.
 */
const MIT_PO_LINE_PROJECT_SQL = "COALESCE(pp.project_id, links.project_id, 0)";

/** Open projects the agent can reach, with their client's name - for the picker. [id => ['label', 'client', 'client_name']]. */
function mitPoProjectChoices() {
    global $mysqli;
    $choices = [];
    $result = mysqli_query($mysqli, "SELECT project_id, project_prefix, project_number, project_name, project_client_id, client_name FROM projects
        LEFT JOIN clients ON client_id = project_client_id
        WHERE project_archived_at IS NULL AND project_completed_at IS NULL" . clientScopeSql('project_client_id') . " ORDER BY client_name, project_number DESC");
    while ($row = mysqli_fetch_assoc($result)) {
        $choices[intval($row['project_id'])] = ['label' => $row['project_prefix'] . $row['project_number'] . ' ' . $row['project_name'],
            'client' => intval($row['project_client_id']), 'client_name' => (string) ($row['client_name'] ?? '')];
    }
    return $choices;
}

/**
 * Put lines of a PO on a project (0 = none). Returns ['changed' => n, 'problems' => [line name => why]].
 * $line_ids empty = every line.
 */
function mitPoSetProject(array $po, array $line_ids, $project_id) {
    global $mysqli;
    $po_id = intval($po['po_id']);
    $project_id = intval($project_id);
    $out = ['changed' => 0, 'problems' => []];
    if (($po['po_status'] ?? '') === 'cancelled') {
        $out['problems']['PO'] = 'it is cancelled';
        return $out;
    }
    $project = null;
    if ($project_id) {
        $choices = mitPoProjectChoices();
        if (!isset($choices[$project_id])) {
            $out['problems']['Project'] = 'that project is not open, or is for a client you cannot see';
            return $out;
        }
        $project = $choices[$project_id];
    }
    $line_ids = array_filter(array_map('intval', $line_ids));
    $names = [];
    foreach (mitPoLines($po_id) as $line) {
        $line_id = intval($line['po_item_id']);
        if ($line_ids && !in_array($line_id, $line_ids, true)) {
            continue;
        }
        $client_id = intval($line['po_item_client_id']);
        $new_client = $client_id;
        if ($project) {
            if ($project['client'] && $client_id && $client_id !== $project['client']) {
                $out['problems'][$line['po_item_name']] = 'it is for another client';
                continue;
            }
            if ($project['client'] && !$client_id) {
                if ((float) $line['po_item_received'] > 0) {
                    $out['problems'][$line['po_item_name']] = 'it was ordered for no client and some of it has been received already';
                    continue;
                }
                $new_client = $project['client'];
            }
            if (!$project['client'] && $client_id) {
                $out['problems'][$line['po_item_name']] = 'it is for a client and the project is not';
                continue;
            }
        }
        if (intval($line['project_id']) === $project_id && $new_client === $client_id) {
            continue;
        }
        // Kept even as 0 (off a project): it wins over the project its item came with (MIT_PO_LINE_PROJECT_SQL)
        mysqli_query($mysqli, "INSERT INTO mit_po_item_projects SET po_item_id = $line_id, project_id = $project_id ON DUPLICATE KEY UPDATE project_id = $project_id");
        if ($new_client !== $client_id) {
            mysqli_query($mysqli, "UPDATE po_items SET po_item_client_id = $new_client WHERE po_item_id = $line_id AND po_item_po_id = $po_id");
        }
        $order_item_id = intval($line['po_item_order_item_id']);
        if ($order_item_id) {
            mysqli_query($mysqli, "UPDATE order_items SET order_item_client_id = $new_client WHERE order_item_id = $order_item_id");
            mysqli_query($mysqli, "INSERT INTO mit_order_item_links SET order_item_id = $order_item_id, source = 'manual', project_id = $project_id
                ON DUPLICATE KEY UPDATE project_id = $project_id");
        }
        $names[] = $line['po_item_name'];
        $out['changed']++;
    }
    if ($names) {
        $what = $project ? 'put ' . implode(', ', $names) . ' on project ' . $project['label'] : 'took ' . implode(', ', $names) . ' off their project';
        // ITFlow's PO history (the audit log, as poLog writes it)
        logAudit('Purchase Order', 'Edit', escapeSql(mb_substr(mitProcWho() . ' ' . $what . ' (' . mitPoReference($po) . ')', 0, 1000)), $project ? $project['client'] : 0, $po_id);
    }
    return $out;
}

/** A PO's lines (ITFlow's po_items) with their client and project (mit_po_item_projects) - and where their item came from. */
function mitPoLines($po_id) {
    global $mysqli;
    if (!mitProcReady()) {
        return [];
    }
    return mysqli_fetch_all(mysqli_query($mysqli, "SELECT l.*, client_name, " . MIT_PO_LINE_PROJECT_SQL . " AS project_id, project_prefix, project_number, project_name,
            links.source, links.ticket_id, links.invoice_id, ticket_prefix, ticket_number, invoice_prefix, invoice_number
        FROM po_items l
        LEFT JOIN clients ON clients.client_id = l.po_item_client_id AND l.po_item_client_id > 0
        LEFT JOIN mit_po_item_projects pp ON pp.po_item_id = l.po_item_id
        LEFT JOIN mit_order_item_links links ON links.order_item_id = l.po_item_order_item_id AND l.po_item_order_item_id > 0
        LEFT JOIN projects ON projects.project_id = " . MIT_PO_LINE_PROJECT_SQL . "
        LEFT JOIN tickets ON tickets.ticket_id = links.ticket_id AND links.ticket_id > 0
        LEFT JOIN invoices ON invoices.invoice_id = links.invoice_id AND links.invoice_id > 0
        WHERE l.po_item_po_id = " . intval($po_id) . " ORDER BY l.po_item_order, l.po_item_id"), MYSQLI_ASSOC);
}

/** "Link to a project" in the ⋮ menu of ITFlow's PO page (one line in agent/purchase_order.php) - Procurement edit, any status but cancelled. */
function mitPoProjectMenuItem(array $po) {
    if (!mitProcReady() || mitProcLevel() < 2 || $po['po_status'] === 'cancelled') {
        return '';
    }
    return '<a class="dropdown-item ajax-modal" href="#" data-mit-po-project-btn data-modal-url="modals/mit_procurement/po_project.php?po_id=' . intval($po['po_id']) . '">'
        . '<i class="fas fa-fw fa-project-diagram text-secondary me-2"></i>Link to a Project</a>';
}

/**
 * Under a line's name on ITFlow's PO page (one line in agent/purchase_order.php): where its item came from - project (with a
 * link to change it), ticket, invoice. '' for a line with none of these and nothing to change.
 */
function mitPoItemFor(array $item) {
    static $lines = [], $statuses = [];
    if (!mitProcReady()) {
        return '';
    }
    $po_id = intval($item['po_item_po_id']);
    if (!isset($lines[$po_id])) {
        $lines[$po_id] = [];
        foreach (mitPoLines($po_id) as $row) {
            $lines[$po_id][intval($row['po_item_id'])] = $row;
        }
    }
    $row = $lines[$po_id][intval($item['po_item_id'])] ?? null;
    if (!$row) {
        return '';
    }
    $parts = [];
    if (intval($row['project_id']) && $row['project_name'] !== null) {
        $parts[] = '<span data-mit-po-line-project-name><i class="fas fa-fw fa-project-diagram me-1"></i><a class="text-reset" href="project.php?project_id=' . intval($row['project_id']) . '" title="' . escapeHtml($row['project_name']) . '">'
            . escapeHtml($row['project_prefix'] . $row['project_number']) . '</a></span>';
    }
    if (intval($row['ticket_id']) && $row['ticket_number'] !== null) {
        $parts[] = '<span><i class="fas fa-fw fa-life-ring me-1"></i><a class="text-reset" href="ticket.php?ticket_id=' . intval($row['ticket_id']) . '">' . escapeHtml($row['ticket_prefix'] . $row['ticket_number']) . '</a></span>';
    }
    if (intval($row['invoice_id']) && $row['invoice_number'] !== null && lookupUserPermission('module_sales') >= 1) {
        $parts[] = '<span><i class="fas fa-fw fa-file-invoice me-1"></i><a class="text-reset" href="invoice.php?invoice_id=' . intval($row['invoice_id']) . '">' . escapeHtml($row['invoice_prefix'] . $row['invoice_number']) . '</a></span>';
    }
    $status = $statuses[$po_id] ??= (string) (poRow($po_id)['po_status'] ?? '');
    if (mitProcLevel() >= 2 && $status !== 'cancelled') {
        $parts[] = '<a class="text-reset ajax-modal" href="#" data-mit-po-line-project data-modal-url="modals/mit_procurement/po_project.php?po_id=' . $po_id . '&line_id=' . intval($row['po_item_id']) . '">'
            . '<i class="fas fa-fw fa-pen me-1"></i>' . (intval($row['project_id']) ? 'Project' : 'Add to a project') . '</a>';
    }
    return implode(' ', $parts);
}
