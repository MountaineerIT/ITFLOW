<?php

/*
 * Mountaineer IT - procurement part 3 (database update M011): what else happens when items arrive,
 * the vendor's bill as an expense, and where order status shows. Required from functions/mit_procurement.php.
 *
 * - Receiving (agent/mit_receiving.php): a client's items can become assets (one per unit, with its
 *   serial; linked to the ticket when the item came from a ticket part), stock items go into the
 *   product's stock, and a ticket part's ticket gets a note.
 * - "Record vendor bill" on a PO makes an ITFlow expense (vendor, amount, date, their invoice #) and
 *   links it to the PO.
 * - Order status: each invoice line (mitProcInvoiceLineStatus in agent/invoice.php) and a Purchase
 *   orders card on the project page (mitProcProjectCard in agent/project.php).
 */

/** An asset type for an item, from its name - the receiving page offers it as the default. */
function mitProcGuessAssetType($name) {
    $name = mb_strtolower((string) $name);
    $guesses = [
        'Laptop' => ['laptop', 'notebook', 'latitude', 'thinkpad', 'macbook', 'elitebook', 'probook'],
        'Desktop' => ['desktop', 'optiplex', 'workstation', 'thinkcentre', 'imac', 'mini pc', 'tower'],
        'Server' => ['server', 'poweredge', 'proliant', 'nas', 'synology', 'qnap'],
        'Display' => ['monitor', 'display', 'tv', 'screen'],
        'Switch' => ['switch'],
        'Firewall/Router' => ['firewall', 'router', 'gateway', 'fortigate', 'sonicwall', 'meraki mx', 'udm'],
        'Access Point' => ['access point', 'wap', 'ap', 'wifi', 'wi-fi'],
        'Printer' => ['printer', 'copier', 'mfp', 'laserjet', 'officejet'],
        'Mobile Phone' => ['iphone', 'galaxy', 'pixel', 'mobile phone', 'smartphone'],
        'Tablet' => ['ipad', 'tablet', 'surface go'],
        'Phone' => ['phone', 'yealink', 'polycom', 'poly', 'handset'],
        'Camera' => ['camera', 'nvr', 'webcam'],
    ];
    foreach ($guesses as $type => $words) {
        foreach ($words as $word) {
            // Whole words only (plurals too) - "nas" is not in "Panasonic", "ap" not in "laptop"
            if (preg_match('/(?<![a-z0-9])' . preg_quote($word, '/') . '(?:s|es)?(?![a-z0-9])/u', $name)) {
                return $type;
            }
        }
    }
    return 'Other';
}

/**
 * After a line's receipt is recorded (mitPoReceive): the extras that were ticked. Returns counts
 * (assets, stock, notes, capped - set when a line had more than the 50 assets made at a time).
 * An invoice line made from a tangible product had its stock taken by ITFlow when it was added; ordered
 * for the client instead, that stock is put back as the line arrives, so the shelf count stays right.
 */
/**
 * A ticket moved to another client (mitTicketClientMoved): its parts still waiting to be ordered, and PO lines for them not yet
 * fully received, belong to the new client now - so client access, receiving's assets and the To order list follow the ticket
 * (as its ticket parts do, MIT_PART_CLIENT_SQL). What was already received stays where it went.
 */
function mitProcTicketClientMoved($ticket_id, $client_id) {
    global $mysqli;
    if (!mitProcReady()) {
        return;
    }
    $ticket_id = intval($ticket_id);
    $client_id = intval($client_id);
    mysqli_query($mysqli, "UPDATE mit_po_lines l JOIN mit_procurement_items i ON i.item_id = l.proc_item_id SET l.client_id = $client_id
        WHERE i.ticket_id = $ticket_id AND l.received_qty < l.quantity");
    mysqli_query($mysqli, "UPDATE mit_procurement_items SET client_id = $client_id WHERE ticket_id = $ticket_id AND status NOT IN ('received', 'cancelled')");
    // ITFlow takes the ticket out of its project and unlinks its assets on a client change: the parts and orders follow
    $not_theirs = "project_id > 0 AND project_id NOT IN (SELECT project_id FROM projects WHERE project_client_id = $client_id)";
    mysqli_query($mysqli, "UPDATE mit_procurement_items SET project_id = 0 WHERE ticket_id = $ticket_id AND $not_theirs");
    mysqli_query($mysqli, "UPDATE mit_po_lines l JOIN mit_procurement_items i ON i.item_id = l.proc_item_id SET l.project_id = 0
        WHERE i.ticket_id = $ticket_id AND l.received_qty < l.quantity AND l.project_id > 0 AND l.project_id NOT IN (SELECT project_id FROM projects WHERE project_client_id = $client_id)");
    if (mitTicketPartsReady()) {
        mysqli_query($mysqli, "UPDATE mit_ticket_parts SET asset_id = 0 WHERE ticket_id = $ticket_id AND asset_id > 0
            AND asset_id NOT IN (SELECT asset_id FROM assets WHERE asset_client_id = $client_id)");
    }
}

/**
 * Receiving's extras the signed-in agent's role may do: assets and ticket notes need Support edit (as ITFlow's asset and reply
 * forms do), adding stock needs Sales edit (as ITFlow's product stock form). Returns the options with the others taken out.
 */
function mitProcReceiveOptions(array $options) {
    if (lookupUserPermission('module_support') < 2) {
        unset($options['assets'], $options['review'], $options['notes']);
    }
    if (lookupUserPermission('module_sales') < 2) {
        unset($options['stock']);
    }
    return $options;
}

function mitProcAfterReceive(array $line, $qty, $serials, $receipt_id, array $options, array $in) {
    global $mysqli, $session_user_id, $asset_types_array;
    $done = ['assets' => 0, 'stock' => 0, 'notes' => 0, 'capped' => 0, 'asset_ids' => []];
    $proc = intval($line['proc_item_id']) ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT item_id, source, ticket_id, product_id, invoice_id, invoice_item_id FROM mit_procurement_items WHERE item_id = " . intval($line['proc_item_id']))) : null;
    $po_reference = $line['po_prefix'] . $line['po_number'];
    $client_id = intval($line['client_id']);

    // A client's hardware: one asset per unit, each with its serial
    if (!empty($options['assets']) && $client_id && floor($qty) == $qty) {
        $types = is_array($asset_types_array ?? null) ? array_keys($asset_types_array) : ['Other'];
        $type = ($in['asset_type'] ?? '') === 'none' || in_array($in['asset_type'] ?? '', $types, true) ? $in['asset_type'] : mitProcGuessAssetType($line['name']);
        if ($type !== 'none') {
            $serial_list = $serials === '' ? [] : array_values(array_filter(array_map('trim', explode("\n", $serials))));
            $po = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT vendor_id, order_date FROM mit_purchase_orders WHERE po_id = " . intval($line['po_id'])));
            $ticket_id = intval($proc['ticket_id'] ?? 0);
            // A ticket moved to another client since the part was ordered: the asset is the ticket's client's
            if ($ticket_id && intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'))) {
                $client_id = intval(getFieldById('tickets', $ticket_id, 'ticket_client_id'));
            }
            $asset_ids = [];
            $done['capped'] = intval($qty) > 50 ? 1 : 0;
            // Bought on an invoice line: say so on the asset (ITFlow 26.11's invoice Hardware box then counts it as on the client's assets)
            $sale = '';
            if ($proc && intval($proc['invoice_id']) && mitColumnReady('assets', 'asset_invoice_id')) {
                $sale = ", asset_invoice_id = " . intval($proc['invoice_id']) . ", asset_invoice_item_id = " . intval($proc['invoice_item_id']) . ", asset_product_id = " . intval($proc['product_id']);
            }
            for ($i = 0; $i < min(50, intval($qty)); $i++) {
                $serial = $serial_list[$i] ?? '';
                // Several of the same: "Laptop #1", "Laptop #2" so they can be told apart until they are renamed
                $asset_name = mb_substr($line['name'], 0, 190) . (intval($qty) > 1 ? ' #' . ($i + 1) : '');
                mysqli_query($mysqli, "INSERT INTO assets SET asset_type = '" . escapeSql($type) . "', asset_name = '" . escapeSql($asset_name) . "',
                    asset_make = '', asset_model = '" . escapeSql(mb_substr($line['name'], 0, 200)) . "', asset_serial = " . ($serial === '' ? 'NULL' : "'" . escapeSql(mb_substr($serial, 0, 200)) . "'") . ",
                    asset_status = 'Ready to Deploy', asset_purchase_reference = '" . escapeSql($po_reference) . "',
                    asset_purchase_date = " . ($po && $po['order_date'] ? "'" . escapeSql($po['order_date']) . "'" : 'CURDATE()') . ",
                    asset_vendor_id = " . intval($po['vendor_id'] ?? 0) . ", asset_client_id = $client_id,
                    asset_notes = '" . escapeSql("Received on $po_reference") . "'$sale");
                $asset_id = mysqli_insert_id($mysqli);
                $asset_ids[] = $asset_id;
                if ($ticket_id) {
                    mysqli_query($mysqli, "INSERT IGNORE INTO ticket_assets SET ticket_id = $ticket_id, asset_id = $asset_id");
                }
            }
            if ($asset_ids) {
                mysqli_query($mysqli, "UPDATE mit_po_receipts SET asset_ids = '" . implode(',', $asset_ids) . "' WHERE receipt_id = " . intval($receipt_id));
                if ($ticket_id) {
                    // The ticket part it was for points at its (first) asset
                    mysqli_query($mysqli, "UPDATE mit_ticket_parts SET asset_id = " . $asset_ids[0] . " WHERE proc_item_id = " . intval($proc['item_id']) . " AND asset_id = 0");
                }
                $client_name = getFieldById('clients', $client_id, 'client_name');
                mitPoLog($line['po_id'], mitProcWho() . ' added ' . count($asset_ids) . ' asset' . (count($asset_ids) === 1 ? '' : 's') . " ($type) for $client_name");
                logAudit('Asset', 'Create', escapeSql(mitProcWho() . ' added ' . count($asset_ids) . " $type asset(s) received on $po_reference"), $client_id, $asset_ids[0]);
                $done['assets'] += count($asset_ids);
                $done['asset_ids'] = $asset_ids;
            }
        }
    }

    // Our stock: into the product's stock (not a ticket's part - that goes to the ticket, even one without a client)
    if (!empty($options['stock']) && !$client_id && $proc && $proc['source'] !== 'ticket' && intval($proc['product_id']) && mitProductTracksStock(intval($proc['product_id']))) {
        mitStockMove(intval($proc['product_id']), round($qty, 2), 'received', "Received on $po_reference", ['unit_cost' => $line['unit_cost'] ?? null]);
        logAudit('Product', 'Edit', escapeSql(mitProcWho() . ' added ' . mitQty($qty) . " to stock, received on $po_reference"), 0, intval($proc['product_id']));
        $done['stock'] += round($qty, 2);
    }

    /*
     * Ordered for an invoice line ITFlow had already taken from stock: that stock goes back, and the line loses its
     * item_product_id. ITFlow decides by item_product_id alone (agent/post/invoice.php: deleting the line or the
     * invoice returns its quantity to stock, a quantity change moves stock), so without that it would put the same
     * stock back a second time. The cost: once part of a line has arrived, ITFlow no longer moves stock for that line
     * at all. The item keeps the product (product_id, set when it was sent and never edited), which is how a later
     * item for the rest of the line still finds it (mitProcInvoiceLineProducts). A deleted line had its stock returned
     * by ITFlow already - nothing to do then.
     */
    // Quote lines invoiced (ITFlow 26.11's quoteConvert keeps the product, so it takes stock too) are treated the same
    if ($proc && in_array($proc['source'], ['invoice', 'quote'], true) && intval($proc['invoice_item_id']) && mitProductTracksStock(intval($proc['product_id']))) {
        $invoice_item_id = intval($proc['invoice_item_id']);
        $product_id = intval($proc['product_id']);
        $invoice_id = intval($proc['invoice_id']);
        $invoice_line = mysqli_fetch_row(mysqli_query($mysqli, "SELECT item_product_id FROM invoice_items WHERE item_id = $invoice_item_id"));
        // Only what ITFlow's stock record shows is still taken: by this line, and by the invoice for this product (a cancelled
        // invoice was given its stock back by ITFlow, and a line converted from a quote as "to order" never took any)
        $taken = function_exists('productStockMove') ? min(
            -(float) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(stock_qty), 0) FROM product_stock WHERE stock_item_id = $invoice_item_id AND stock_invoice_id = $invoice_id AND stock_product_id = $product_id"))[0] ?? 0),
            -(float) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(stock_qty), 0) FROM product_stock WHERE stock_invoice_id = $invoice_id AND stock_product_id = $product_id"))[0] ?? 0)
        ) : round($qty, 2);
        $back = round(min(round($qty, 2), $taken), 2);
        if ($invoice_line && in_array(intval($invoice_line[0]), [0, $product_id], true) && $back > 0) {
            mitStockMove($product_id, $back, 'returned', "Ordered on $po_reference for the invoice instead of taken from stock",
                ['invoice_id' => $invoice_id, 'item_id' => $invoice_item_id]);
        }
        if ($invoice_line && intval($invoice_line[0]) === $product_id) {
            mysqli_query($mysqli, "UPDATE invoice_items SET item_product_id = 0 WHERE item_id = $invoice_item_id");
        }
    }

    // A ticket part: say so on the ticket
    if (!empty($options['notes']) && $proc && intval($proc['ticket_id'])) {
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . escapeSql(escapeHtml('Received ' . mitQty($qty) . ' × ' . $line['name'] . " on $po_reference" . ($serials !== '' ? ' (serials: ' . str_replace("\n", ', ', $serials) . ')' : '') . '.')) . "',
            ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = " . intval($session_user_id) . ", ticket_reply_ticket_id = " . intval($proc['ticket_id']));
        $done['notes']++;
    }
    return $done;
}

/*
 * ---------- the vendor's bill ----------
 */

/** The PO's expense (the vendor's bill), or null. */
function mitPoExpense(array $po) {
    global $mysqli;
    if (!intval($po['expense_id'])) {
        return null;
    }
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT expense_id, expense_amount, expense_date, expense_reference, expense_archived_at FROM expenses WHERE expense_id = " . intval($po['expense_id']))) ?: null;
}

/** Record the vendor's bill as an expense. Returns [expense_id, message]. One at a time per PO, on the PO as it is now. */
function mitPoRecordBill(array $po, array $post) {
    $lock = 'po_bill_' . intval($po['po_id']);
    if (!mitLock($lock)) {
        return [0, 'Someone else is recording this bill right now - try again.'];
    }
    try {
        return mitPoRecordBillNow(mitPoRow($po['po_id']) ?: $po, $post);
    } finally {
        mitUnlock($lock);
    }
}

function mitPoRecordBillNow(array $po, array $post) {
    global $mysqli, $session_company_currency;
    if (mitPoExpense($po)) {
        return [0, 'This PO already has its vendor bill recorded.'];
    }
    $amount = trim(str_replace([',', '$'], '', (string) ($post['amount'] ?? '')));
    $date = (string) ($post['date'] ?? '');
    if (!is_numeric($amount) || (float) $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [0, 'Enter the amount and the date of the bill.'];
    }
    $category = intval($post['category'] ?? 0);
    $account = intval($post['account'] ?? 0);
    $reference = trim((string) ($post['reference'] ?? ''));
    $description = trim((string) ($post['description'] ?? '')) ?: mitPoReference($po) . ' - ' . $po['vendor_name'];
    // One client on the PO: the expense is theirs, as ITFlow's own expenses can be
    $clients = mysqli_fetch_all(mysqli_query($mysqli, "SELECT DISTINCT client_id FROM mit_po_lines WHERE po_id = " . intval($po['po_id']) . " AND client_id > 0"), MYSQLI_ASSOC);
    $client_id = count($clients) === 1 ? intval($clients[0]['client_id']) : 0;
    $reference = mb_substr($reference !== '' ? $reference : mitPoReference($po), 0, 200);
    if (function_exists('expenseSave')) {
        // Through ITFlow's own expense rules (account and category checked, the account's currency, who made it)
        $expense_id = expenseSave(['date' => $date, 'amount' => $amount, 'vendor' => intval($po['vendor_id']), 'category' => $category, 'account' => $account,
            'client_id' => $client_id, 'reference' => $reference, 'description' => $description], 0, $error);
        if (!$expense_id) {
            return [0, "Not recorded - $error."];
        }
        // The stock this PO put on the shelf was delivered on this bill, as ITFlow's own Receive stock form records it
        mysqli_query($mysqli, "UPDATE product_stock SET stock_expense_id = $expense_id WHERE stock_type = 'received' AND stock_expense_id IS NULL
            AND stock_note = '" . escapeSql('Received on ' . mitPoReference($po)) . "'");
    } else {
        mysqli_query($mysqli, "INSERT INTO expenses SET expense_date = '$date', expense_amount = " . round((float) $amount, 2) . ", expense_currency_code = '" . escapeSql($session_company_currency) . "',
            expense_account_id = " . ($account ?: 'NULL') . ", expense_vendor_id = " . intval($po['vendor_id']) . ", expense_client_id = $client_id, expense_category_id = $category,
            expense_description = '" . escapeSql($description) . "', expense_reference = '" . escapeSql($reference) . "'");
        $expense_id = mysqli_insert_id($mysqli);
    }
    mysqli_query($mysqli, "UPDATE mit_purchase_orders SET expense_id = $expense_id WHERE po_id = " . intval($po['po_id']));
    mitPoLog($po['po_id'], mitProcWho() . ' recorded the vendor bill - ' . mitMoney($amount) . ($reference !== '' ? " (their invoice $reference)" : '') . ' as an expense');
    logAudit('Expense', 'Create', escapeSql(mitProcWho() . ' recorded the vendor bill for ' . mitPoReference($po) . ' as an expense'), $client_id, $expense_id);
    return [$expense_id, 'Vendor bill recorded as an expense'];
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
        $parts = mitTicketPartsReady() ? " OR proc.item_id IN (SELECT proc_item_id FROM mit_ticket_parts WHERE invoice_id = $invoice_id AND proc_item_id > 0)" : '';
        $result = mysqli_query($mysqli, "SELECT proc.item_id, proc.status, proc.quantity,
                IF(proc.invoice_id = $invoice_id, proc.invoice_item_id, (SELECT invoice_item_id FROM mit_ticket_parts WHERE proc_item_id = proc.item_id LIMIT 1)) AS line_id,
                l.received_qty, po.po_id, po.po_prefix, po.po_number
            FROM mit_procurement_items proc
            LEFT JOIN mit_po_lines l ON l.proc_item_id = proc.item_id AND l.po_id IN (SELECT po_id FROM mit_purchase_orders WHERE status <> 'cancelled')
            LEFT JOIN mit_purchase_orders po ON po.po_id = l.po_id
            WHERE proc.status <> 'cancelled' AND (proc.invoice_id = $invoice_id $parts)");
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
        $links[] = '<a href="mit_purchase_order.php?po_id=' . $po_id . '">' . escapeHtml($reference) . '</a>';
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
    $pos = mysqli_fetch_all(mysqli_query($mysqli, "SELECT po.po_id, po.po_prefix, po.po_number, po.status, po.vendor_order_number, vendor_name,
            SUM(l.quantity * l.unit_cost) AS cost, SUM(l.quantity) AS qty, SUM(LEAST(l.received_qty, l.quantity)) AS received,
            (SELECT GROUP_CONCAT(CONCAT(carrier, ':', tracking_number) SEPARATOR '|') FROM mit_po_tracking t WHERE t.po_id = po.po_id) AS tracking
        FROM mit_po_lines l
        JOIN mit_purchase_orders po ON po.po_id = l.po_id
        LEFT JOIN vendors ON vendors.vendor_id = po.vendor_id
        WHERE l.project_id = $project_id AND po.status <> 'cancelled'" . mitPoScopeSql('po.po_id') . "
        GROUP BY po.po_id ORDER BY po.po_id DESC"), MYSQLI_ASSOC);
    $waiting = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS n, COALESCE(SUM(quantity * COALESCE(unit_cost, 0)), 0) AS cost FROM mit_procurement_items proc
        WHERE project_id = $project_id AND status = 'waiting'" . clientScopeSql('proc.client_id')));
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
            $tracking = [];
            foreach (array_filter(explode('|', (string) $po['tracking'])) as $pair) {
                [$carrier, $number] = array_pad(explode(':', $pair, 2), 2, '');
                $tracking[] = mitTrackingHtml(['carrier' => $carrier, 'tracking_number' => $number]);
            }
            $html .= '<tr class="border-bottom"><td class="ps-3"><a href="mit_purchase_order.php?po_id=' . intval($po['po_id']) . '">' . escapeHtml($po['po_prefix'] . $po['po_number']) . '</a></td>'
                . '<td>' . escapeHtml($po['vendor_name']) . '</td><td class="font-monospace small">' . escapeHtml($po['vendor_order_number'] ?: '-') . '</td>'
                . '<td class="small">' . ($tracking ? implode('<br>', $tracking) : '<span class="text-muted">-</span>') . '</td>'
                . '<td class="pe-3">' . mitPoBadge($po['status']) . ' <span class="small text-muted">' . mitQty($po['received']) . '/' . mitQty($po['qty']) . '</span></td></tr>';
        }
        $html .= '</tbody></table></div>';
    }
    return $html . '</div>';
}
