<?php
/*
 * Procurement end to end on real rows - ITFlow 26.12's Purchasing (functions/purchase_orders.php) with our extras on it
 * (functions/mit_procurement*.php), the parts the stock scenario does not cover:
 *  - approval: over the limit / a line with no cost needs approving (ITFlow's); "their own" = made OR shaped it (our hook in
 *    poMayApprove - a non-admin Full approver who added a line may not approve it while another can); the approve window's
 *    total must still be the PO's (our hook in poDecide); our switches po_app / po_email for the approvers' notices (poTell);
 *  - receiving a ticket part: partial then the rest, serials, one asset per unit (ITFlow's), the assets linked to the ticket,
 *    the part pointed at its asset, internal notes, what was done kept in mit_po_receipt_extras; a ticket part never goes on
 *    the shelf (a ticket without a client, a tracked product); our own stock does (ITFlow's);
 *  - cancel -> items waiting again (ITFlow's);
 *  - ticket parts: stock taken / moved / returned exactly once, the order item follows the part, billing with tax onto a new
 *    draft (ITFlow's totals), not billed twice, Unbilled count, a cancelled or removed order item is not billable;
 *  - invoice line order status and the project card's numbers.
 */
require __DIR__ . '/bootstrap.php';
$client_id = scenarioClient();
$vendor = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_id LIMIT 1"))[0] ?? 0);
$admin_id = $session_user_id;
$made = ['products' => [], 'items' => [], 'pos' => [], 'tickets' => [], 'invoices' => [], 'assets' => [], 'taxes' => [], 'projects' => []];
$q1 = function ($sql) use ($mysqli) {
    $row = mysqli_fetch_row(mysqli_query($mysqli, $sql));
    return $row ? $row[0] : null;
};
$saved_settings = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_po_approval, config_po_approval_over FROM settings WHERE company_id = 1"));
$saved_notify = [mitSetting('notify_po_app'), mitSetting('notify_po_email')];
// Before poSettings() first reads them (it keeps them for the request)
mysqli_query($mysqli, "UPDATE settings SET config_po_approval = 1, config_po_approval_over = 500 WHERE company_id = 1");
$product = function ($name, $stock, $track = 1) use ($mysqli, &$made) {
    mysqli_query($mysqli, "INSERT INTO products SET product_name = '" . escapeSql($name) . "', product_price = 100, product_track_stock = $track, product_type = 'product', product_cost = 60,
        product_currency_code = 'USD', product_tax_id = 0, product_category_id = 0");
    $id = $made['products'][] = mysqli_insert_id($mysqli);
    if ($stock) {
        productStockMove($id, $stock, 'received', ['note' => 'scenario']);
    }
    return $id;
};
$item = function (array $fields) use (&$made) {
    return $made['items'][] = mitProcAddItem($fields + ['vendor_id' => 0, 'project_id' => 0, 'product_id' => 0]);
};
$po = function (array $item_ids) use ($vendor, &$made) {
    $id = poCreate($vendor, $item_ids, $error);
    if (!$id) {
        echo "       poCreate: $error\n";
    }
    $made['pos'][] = $id;
    return $id;
};
// Send for approval when it needs it, else straight to ordered - as the PO page offers
$order = function ($po_id) {
    $row = poRow($po_id);
    if (poNeedsApproval($row)) {
        poSendForApproval($row, $error);
        poDecide(poRow($po_id), true, '', $error);
    }
    poMarkOrdered(poRow($po_id), date('Y-m-d'), '', $error);
    return poRow($po_id)['po_status'];
};
$line_of = function ($po_id, $n = 0) {
    return intval(poItems($po_id)[$n]['po_item_id'] ?? 0);
};
$as = function ($user_id, $role_id, $admin) {
    $GLOBALS['session_user_id'] = $user_id;
    $GLOBALS['session_user_role'] = $role_id;
    $GLOBALS['session_is_admin'] = $admin;
    $GLOBALS['session_name'] = $admin ? 'Scenario admin' : 'Scenario buyer';
    $GLOBALS['client_access_array'] = $GLOBALS['client_access_array'] ?? [];
    $GLOBALS['client_deny_array'] = $GLOBALS['client_deny_array'] ?? [];
};
try {
    scenarioCheck('Purchasing is on and ready', mitProcOn(), true);
    scenarioCheck('the scenario admin has Full Purchasing (poLevel keeps it for the run)', poLevel(), 3);

    // 1. Approval rules (ITFlow's)
    $a = $po([$item(['name' => 'ZZ scen cable', 'quantity' => 2, 'unit_cost' => 100, 'client_id' => $client_id])]);
    scenarioCheck('200 under 500: no approval needed', poNeedsApproval(poRow($a)), false);
    $b = $po([$item(['name' => 'ZZ scen free thing', 'quantity' => 1, 'unit_cost' => null, 'client_id' => $client_id])]);
    scenarioCheck('a line with no cost: needs approval', poNeedsApproval(poRow($b)), true);
    $c = $po([$item(['name' => 'ZZ scen server', 'quantity' => 6, 'unit_cost' => 100, 'client_id' => $client_id])]);
    scenarioCheck('600 over 500: needs approval', poNeedsApproval(poRow($c)), true);
    scenarioCheck('PO total from lines', (float) poRow($c)['po_total'], 600.0);
    scenarioCheck('the item went on the PO as ordered', $q1("SELECT order_item_status FROM order_items WHERE order_item_id = " . intval(poItems($c)[0]['po_item_order_item_id'])), 'ordered');

    // 2. Self-approval: shaping counts (our hook), the total seen (our hook), our notification switches
    $full_role = intval($q1("SELECT user_role_id FROM user_role_permissions urp JOIN modules m ON m.module_id = urp.module_id JOIN user_roles r ON r.role_id = urp.user_role_id
        WHERE m.module_name = 'module_procurement' AND urp.user_role_permission_level = 3 AND r.role_is_admin = 0 LIMIT 1"))
        // None on this install: any non-admin role - poLevel() keeps the Full level it read first in this run, so the buyer acts as Full
        ?: intval($q1("SELECT role_id FROM user_roles WHERE role_is_admin = 0 ORDER BY role_id LIMIT 1"));
    // Any other agent, acting with that role for the session (poApprovers still finds the admin as another approver)
    $buyer = $full_role ? intval($q1("SELECT user_id FROM users WHERE user_type = 1 AND user_id != $admin_id ORDER BY user_id LIMIT 1")) : 0;
    if ($buyer) {
        // The admin made it; the buyer added a line - their own now
        $d = $po([$item(['name' => 'ZZ scen own PO', 'quantity' => 7, 'unit_cost' => 100, 'client_id' => $client_id])]);
        $as($buyer, $full_role, false);
        poLineSave(poRow($d), ['name' => 'ZZ scen extra line', 'quantity' => '1', 'cost' => '50', 'client' => $client_id], 0, $error);
        mitSetSetting('notify_po_app', '0');
        mitSetSetting('notify_po_email', '0');
        $mail_before = intval($q1("SELECT COALESCE(MAX(email_id), 0) FROM email_queue"));
        poSendForApproval(poRow($d), $error);
        scenarioCheck('po_app off: no bell for the approvers', intval($q1("SELECT COUNT(*) FROM notifications WHERE notification_type = 'Purchase Order' AND notification_entity_id = $d")), 0);
        scenarioCheck('po_email off: no email', intval($q1("SELECT COUNT(*) FROM email_queue WHERE email_id > $mail_before AND email_subject LIKE '%" . escapeSql(poNumber(poRow($d))) . "%'")), 0);
        mitSetSetting('notify_po_app', '1');
        mitSetSetting('notify_po_email', '1');
        scenarioCheck('the buyer shaped it', in_array($buyer, mitPoShapers(poRow($d)), true), true);
        scenarioCheck('non-admin buyer may not approve a PO they shaped', poMayApprove(poRow($d)), false);
        $as($admin_id, 0, true);
        scenarioCheck('another (the admin) may', poMayApprove(poRow($d)), true);
        $_POST['mit_seen_total'] = '1.00';
        scenarioCheck('approve with an old total: refused', poDecide(poRow($d), true, '', $error), false);
        scenarioCheck('... saying why', strpos($error, 'changed since you opened it') !== false, true);
        $_POST['mit_seen_total'] = (string) poRow($d)['po_total'];
        scenarioCheck('approve with the total shown: done', poDecide(poRow($d), true, '', $error), true);
        unset($_POST['mit_seen_total']);
        scenarioCheck('approved', poRow($d)['po_status'], 'approved');
        // With the switches on, the approvers get the bell again
        $as($buyer, $full_role, false);
        $d2 = $po([$item(['name' => 'ZZ scen bell PO', 'quantity' => 8, 'unit_cost' => 100, 'client_id' => $client_id])]);
        poSendForApproval(poRow($d2), $error);
        scenarioCheck('po_app on: the admin is told', intval($q1("SELECT COUNT(*) FROM notifications WHERE notification_type = 'Purchase Order' AND notification_entity_id = $d2 AND notification_user_id = $admin_id")), 1);
        $as($admin_id, 0, true);
        mysqli_query($mysqli, "DELETE FROM notifications WHERE notification_type = 'Purchase Order' AND notification_entity_id IN ($d, $d2)");
    } else {
        echo "  skip self-approval (no non-admin Full Procurement role, or no second agent)\n";
    }
    $as($admin_id, 0, true);

    // 3. Receiving for a ticket part: assets per unit, serials, ticket link, internal note, extras kept
    $ticket_number = intval($q1("SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets"));
    mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = $ticket_number, ticket_subject = 'ZZ scenario procurement', ticket_details = 'x',
        ticket_priority = 'Low', ticket_status = 1, ticket_client_id = $client_id, ticket_created_by = $admin_id");
    $tid = $made['tickets'][] = mysqli_insert_id($mysqli);
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $tid"));
    [$ok, $msg, $part_id] = mitTicketPartSave($ticket, ['name' => 'ZZ scen laptop', 'quantity' => '3', 'unit_price' => '900', 'unit_cost' => '700', 'source' => 'order', 'vendor_id' => $vendor]);
    scenarioCheck('to-order part added', $ok, true);
    $part = mitTicketParts($tid)[$part_id];
    $made['items'][] = intval($part['proc_item_id']);
    scenarioCheck('its item waits on To Order', $part['proc_status'], 'waiting');
    scenarioCheck('... linked to the ticket', $q1("SELECT CONCAT(source, '/', ticket_id) FROM mit_order_item_links WHERE order_item_id = " . intval($part['proc_item_id'])), "ticket/$tid");
    mitTicketPartSave($ticket, ['name' => 'ZZ scen laptop', 'quantity' => '2', 'unit_price' => '900', 'unit_cost' => '700', 'source' => 'order', 'vendor_id' => $vendor], $part_id);
    scenarioCheck('item follows the part (qty 2)', (float) $q1("SELECT order_item_quantity FROM order_items WHERE order_item_id = " . intval($part['proc_item_id'])), 2.0);
    $f = $po([intval($part['proc_item_id'])]);
    poSendForApproval(poRow($f), $error); // 1400 - pending
    poDecide(poRow($f), true, '', $error);
    [$ok, $msg] = mitTicketPartSave($ticket, ['name' => 'ZZ scen laptop', 'quantity' => '5', 'unit_price' => '900', 'source' => 'order'], $part_id);
    scenarioCheck('part on a PO: quantity change refused', $ok, false);
    poMarkOrdered(poRow($f), date('Y-m-d'), 'V-1', $error);
    $line = $line_of($f);
    poReceive([$line => ['qty' => 1, 'serials' => 'SN-A', 'asset_type' => 'Laptop']]);
    scenarioCheck('partial: PO partial', poRow($f)['po_status'], 'partial');
    poReceive([$line => ['qty' => 5, 'serials' => "SN-B", 'asset_type' => 'Laptop']]);
    scenarioCheck('rest: capped at what was left (2 in)', (float) $q1("SELECT po_item_received FROM po_items WHERE po_item_id = $line"), 2.0);
    scenarioCheck('PO received', poRow($f)['po_status'], 'received');
    scenarioCheck('item received', $q1("SELECT order_item_status FROM order_items WHERE order_item_id = " . intval($part['proc_item_id'])), 'received');
    $assets = mysqli_fetch_all(mysqli_query($mysqli, "SELECT asset_id, asset_name, asset_serial, asset_purchase_reference, asset_vendor_id, asset_client_id FROM assets
        WHERE asset_purchase_reference = '" . escapeSql(poNumber(poRow($f))) . "' ORDER BY asset_id"), MYSQLI_ASSOC);
    $made['assets'] = array_merge($made['assets'], array_column($assets, 'asset_id'));
    scenarioCheck('one asset per unit received (2)', count($assets), 2);
    scenarioCheck('serials on the assets', array_column($assets, 'asset_serial'), ['SN-A', 'SN-B']);
    scenarioCheck('assets are the client\'s, from the vendor', array_unique(array_map('intval', array_column($assets, 'asset_client_id'))) === [$client_id] && intval($assets[0]['asset_vendor_id'] ?? 0) === $vendor, true);
    scenarioCheck('assets linked to the ticket', intval($q1("SELECT COUNT(*) FROM ticket_assets WHERE ticket_id = $tid")), 2);
    scenarioCheck('part points at its asset', intval(mitTicketParts($tid)[$part_id]['asset_id']), intval($assets[0]['asset_id'] ?? -1));
    scenarioCheck('internal notes on the ticket (2 receipts)', intval($q1("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tid AND ticket_reply LIKE 'Received %'")), 2);
    scenarioCheck('each receipt keeps its note (mit_po_receipt_extras)', intval($q1("SELECT COUNT(*) FROM mit_po_receipt_extras x JOIN po_receipts r ON r.po_receipt_id = x.po_receipt_id WHERE r.po_receipt_po_id = $f AND x.note_reply_id > 0")), 2);
    scenarioCheck('the PO page shows where the line came from', strpos(mitPoItemFor(poItems($f)[0]), 'ticket.php?ticket_id=' . $tid) !== false, true);
    [$ok] = mitTicketPartRemove($ticket, $part_id + 0);
    scenarioCheck('a part that arrived can come off', $ok, true);

    // A ticket part without a client, of a product that tracks stock: it goes to the ticket, not the shelf
    $ps = $product('ZZ scen spare psu', 0);
    $ticket_number = intval($q1("SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets"));
    mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = $ticket_number, ticket_subject = 'ZZ scenario internal', ticket_details = 'x',
        ticket_priority = 'Low', ticket_status = 1, ticket_client_id = 0, ticket_created_by = $admin_id");
    $tid0 = $made['tickets'][] = mysqli_insert_id($mysqli);
    $ticket0 = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $tid0"));
    [$ok, $msg, $p0] = mitTicketPartSave($ticket0, ['name' => 'ZZ scen spare psu', 'quantity' => '1', 'unit_price' => '50', 'unit_cost' => '30', 'source' => 'order', 'product_id' => $ps]);
    $made['items'][] = intval(mitTicketParts($tid0)[$p0]['proc_item_id']);
    $f0 = $po([intval(mitTicketParts($tid0)[$p0]['proc_item_id'])]);
    scenarioCheck('internal ticket part ordered', $order($f0), 'ordered');
    poReceive([$line_of($f0) => ['qty' => 1]]);
    scenarioCheck('... received: not put on the shelf', productOnHand($ps), 0.0);
    scenarioCheck('... the receipt took no stock', intval($q1("SELECT po_receipt_stock_id FROM po_receipts WHERE po_receipt_po_id = $f0")), 0);
    scenarioCheck('... noted on the ticket', intval($q1("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tid0 AND ticket_reply LIKE 'Received %'")), 1);

    // 4. Stock for our own items at the PO's cost (ITFlow's)
    $pid = $product('ZZ scen shelf mouse', 0);
    $g = $po([$item(['name' => 'ZZ scen shelf mouse', 'quantity' => 4, 'unit_cost' => 12.5, 'client_id' => 0, 'product_id' => $pid])]);
    $order($g);
    poReceive([$line_of($g) => ['qty' => 4]]);
    scenarioCheck('own stock: 4 on the shelf', productOnHand($pid), 4.0);
    scenarioCheck('... at the PO cost', (float) $q1("SELECT stock_unit_cost FROM product_stock WHERE stock_product_id = $pid AND stock_type = 'received' ORDER BY stock_id DESC LIMIT 1"), 12.5);

    // 5. Cancel: items back on To Order
    $h_item = $item(['name' => 'ZZ scen cancel me', 'quantity' => 1, 'unit_cost' => 10, 'client_id' => $client_id]);
    $h = $po([$h_item]);
    poCancel(poRow($h), $error);
    scenarioCheck('cancelled PO: its item waits again', $q1("SELECT order_item_status FROM order_items WHERE order_item_id = $h_item"), 'waiting');

    // 6. Ticket parts from stock: taken once, moved on change, returned on remove
    $pa = $product('ZZ scen ssd A', 10);
    $pb = $product('ZZ scen ssd B', 10);
    [$ok, $msg, $sp] = mitTicketPartSave($ticket, ['name' => 'ZZ scen ssd', 'quantity' => '3', 'unit_price' => '80', 'source' => 'stock', 'product_id' => $pa]);
    scenarioCheck('from stock: A 10 -> 7', productOnHand($pa), 7.0);
    mitTicketPartSave($ticket, ['name' => 'ZZ scen ssd', 'quantity' => '5', 'unit_price' => '80', 'source' => 'stock', 'product_id' => $pa], $sp);
    scenarioCheck('qty 3 -> 5: A 5', productOnHand($pa), 5.0);
    $moves = intval($q1("SELECT COUNT(*) FROM product_stock WHERE stock_product_id = $pa"));
    mitTicketPartSave($ticket, ['name' => 'ZZ scen ssd', 'quantity' => '5', 'unit_price' => '95', 'source' => 'stock', 'product_id' => $pa], $sp);
    scenarioCheck('price change: no stock move', intval($q1("SELECT COUNT(*) FROM product_stock WHERE stock_product_id = $pa")), $moves);
    mitTicketPartSave($ticket, ['name' => 'ZZ scen ssd', 'quantity' => '5', 'unit_price' => '95', 'source' => 'stock', 'product_id' => $pb], $sp);
    scenarioCheck('product A -> B: A back to 10', productOnHand($pa), 10.0);
    scenarioCheck('... B 5', productOnHand($pb), 5.0);
    mitTicketPartRemove($ticket, $sp);
    scenarioCheck('removed: B back to 10', productOnHand($pb), 10.0);
    $pc = $product('ZZ scen untracked cable', 0, 0);
    [$ok, $msg, $sc] = mitTicketPartSave($ticket, ['name' => 'ZZ scen untracked cable', 'quantity' => '2', 'unit_price' => '5', 'source' => 'stock', 'product_id' => $pc]);
    mysqli_query($mysqli, "UPDATE products SET product_track_stock = 1 WHERE product_id = $pc");
    mitTicketPartRemove($ticket, $sc);
    scenarioCheck('untracked when used, tracked when removed: no stock appears', productOnHand($pc), 0.0);

    // 7. Billing parts with tax onto a new draft
    mysqli_query($mysqli, "INSERT INTO taxes SET tax_name = 'ZZ scen tax', tax_percent = 8.25");
    $tax = $made['taxes'][] = mysqli_insert_id($mysqli);
    taxForget();
    [$ok, $msg, $p1] = mitTicketPartSave($ticket, ['name' => 'ZZ scen ram', 'quantity' => '2', 'unit_price' => '45.50', 'source' => 'other', 'tax_id' => $tax]);
    [$ok, $msg, $p2] = mitTicketPartSave($ticket, ['name' => 'ZZ scen fan', 'quantity' => '1', 'unit_price' => '19.99', 'source' => 'other', 'tax_id' => 0]);
    $unbilled_before = mitUnbilledPartsCount();
    [$inv, $msg] = mitTicketPartsBill($ticket, [$p1, $p2], [$p2 => '21.00'], 0, date('Y-m-d'), 0);
    if ($inv) {
        $made['invoices'][] = $inv;
    }
    scenarioCheck("parts billed ($msg)", $inv > 0, true);
    $lines = mysqli_fetch_all(mysqli_query($mysqli, "SELECT item_name, item_quantity, item_price, item_subtotal, item_tax, item_total, item_product_id FROM invoice_items WHERE item_invoice_id = " . intval($inv) . " ORDER BY item_order"), MYSQLI_ASSOC);
    scenarioCheck('two lines', count($lines), 2);
    scenarioCheck('ram: 2 x 45.50, tax 8.25% = 7.51', [(float) $lines[0]['item_subtotal'], (float) $lines[0]['item_tax'], (float) $lines[0]['item_total']], [91.0, 7.51, 98.51]);
    scenarioCheck('fan at the price typed in the window (21.00), no tax', [(float) $lines[1]['item_price'], (float) $lines[1]['item_tax']], [21.0, 0.0]);
    scenarioCheck('invoice amount = lines', (float) $q1("SELECT invoice_amount FROM invoices WHERE invoice_id = " . intval($inv)), 119.51);
    scenarioCheck('invoice is a Draft for the ticket\'s client', $q1("SELECT CONCAT(invoice_status, '/', invoice_client_id) FROM invoices WHERE invoice_id = " . intval($inv)), "Draft/$client_id");
    scenarioCheck('Unbilled count down by 2', mitUnbilledPartsCount(), $unbilled_before - 2);
    [$inv2] = mitTicketPartsBill($ticket, [$p1], [], 0, date('Y-m-d'), 0);
    scenarioCheck('a billed part is not billed again', $inv2, 0);
    // A cancelled order is not billable - nor one taken off To Order (ITFlow deletes the item)
    [$ok, $msg, $p3] = mitTicketPartSave($ticket, ['name' => 'ZZ scen dock', 'quantity' => '1', 'unit_price' => '150', 'source' => 'order']);
    $made['items'][] = intval(mitTicketParts($tid)[$p3]['proc_item_id']);
    mysqli_query($mysqli, "UPDATE order_items SET order_item_status = 'cancelled' WHERE order_item_id = " . intval(mitTicketParts($tid)[$p3]['proc_item_id']));
    [$inv3] = mitTicketPartsBill($ticket, [$p3], [], 0, date('Y-m-d'), 0);
    scenarioCheck('a cancelled order is not billed', $inv3, 0);
    [$ok, $msg, $p4] = mitTicketPartSave($ticket, ['name' => 'ZZ scen hub', 'quantity' => '1', 'unit_price' => '40', 'source' => 'order']);
    orderItemsRemove([intval(mitTicketParts($tid)[$p4]['proc_item_id'])]);
    scenarioCheck('taken off To Order: shows as not ordered', mitTicketParts($tid)[$p4]['proc_status'], 'cancelled');
    [$inv4] = mitTicketPartsBill($ticket, [$p4], [], 0, date('Y-m-d'), 0);
    scenarioCheck('... and is not billed', $inv4, 0);

    // 8. Invoice line order status: line sent to procurement, half received
    $line_inv = mysqli_fetch_row(mysqli_query($mysqli, "SELECT item_id FROM invoice_items WHERE item_invoice_id = " . intval($inv) . " ORDER BY item_order LIMIT 1"))[0];
    $line_inv2 = mysqli_fetch_row(mysqli_query($mysqli, "SELECT item_id FROM invoice_items WHERE item_invoice_id = " . intval($inv) . " ORDER BY item_order DESC LIMIT 1"))[0];
    $i_item = $item(['source' => 'invoice', 'invoice_id' => $inv, 'invoice_item_id' => $line_inv, 'name' => 'ZZ scen ram', 'quantity' => 2, 'unit_cost' => 30, 'client_id' => $client_id]);
    $item(['source' => 'invoice', 'invoice_id' => $inv, 'invoice_item_id' => $line_inv2, 'name' => 'ZZ scen fan', 'quantity' => 1, 'unit_cost' => 9, 'client_id' => $client_id]);
    $k = $po([$i_item]);
    $order($k);
    poReceive([$line_of($k) => ['qty' => 1]]);
    $status = mitProcInvoiceStatus($inv)[intval($line_inv)] ?? [];
    scenarioCheck('line status: 1 of 2 received', [(float) ($status['qty'] ?? 0), (float) ($status['received'] ?? 0)], [2.0, 1.0]);
    scenarioCheck('line status badge: 1 of 2 received', strpos(mitProcInvoiceLineStatus($inv, $line_inv), '1 of 2 received') !== false, true);
    scenarioCheck('... links to ITFlow\'s PO page', strpos(mitProcInvoiceLineStatus($inv, $line_inv), 'purchase_order.php?po_id=' . $k) !== false, true);
    scenarioCheck('other line: waiting to order', strpos(mitProcInvoiceLineStatus($inv, $line_inv2), 'Waiting to order') !== false, true);
    scenarioCheck('invoice summary: 1 of 3 received', strpos(mitProcInvoiceSummary($inv), '1 of 3 received') !== false, true);

    // 9. Project card: cost of POs + waiting items, received of ordered
    mysqli_query($mysqli, "INSERT INTO projects SET project_prefix = 'PRJ-', project_number = 9999, project_name = 'ZZ scen project', project_description = '', project_due = CURDATE(), project_client_id = $client_id");
    $prj = $made['projects'][] = mysqli_insert_id($mysqli);
    $item(['name' => 'ZZ scen proj waiting', 'quantity' => 2, 'unit_cost' => 25, 'client_id' => $client_id, 'project_id' => $prj]);
    $m = $po([$item(['name' => 'ZZ scen proj ordered', 'quantity' => 3, 'unit_cost' => 100, 'client_id' => $client_id, 'project_id' => $prj])]);
    $order($m);
    poReceive([$line_of($m) => ['qty' => 1]]);
    $card = preg_replace('/\s+/', ' ', strip_tags(str_replace('</div>', ' </div>', mitProcProjectCard($prj))));
    scenarioCheck('project card: hardware cost 350', strpos($card, 'Hardware cost $350.00') !== false, true);
    scenarioCheck('project card: received 1 of 3, 1 still to order', strpos($card, 'Received 1 of 3') !== false && strpos($card, 'Still to order 1 item') !== false, true);
    if (strpos($card, 'Hardware cost $350.00') === false) {
        echo "       card: $card\n";
    }
} finally {
    $as($admin_id, 0, true);
    unset($_POST['mit_seen_total']);
    mysqli_query($mysqli, "UPDATE settings SET config_po_approval = " . intval($saved_settings['config_po_approval']) . ", config_po_approval_over = " . floatval($saved_settings['config_po_approval_over']) . " WHERE company_id = 1");
    foreach (['notify_po_app' => $saved_notify[0], 'notify_po_email' => $saved_notify[1]] as $name => $value) {
        if ($value === null) {
            mysqli_query($mysqli, "DELETE FROM mit_settings WHERE setting_name = '$name'");
        } else {
            mitSetSetting($name, $value);
        }
    }
    foreach (array_filter($made['pos']) as $id) {
        mysqli_query($mysqli, "DELETE FROM notifications WHERE notification_type = 'Purchase Order' AND notification_entity_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM logs WHERE log_type = 'Purchase Order' AND log_entity_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM purchase_orders WHERE po_id = " . intval($id)); // lines, receipts, tracking go with it
    }
    foreach (array_filter($made['items']) as $id) {
        mysqli_query($mysqli, "DELETE FROM order_items WHERE order_item_id = " . intval($id));
    }
    mysqli_query($mysqli, "DELETE FROM order_items WHERE order_item_name LIKE 'ZZ scen%'");
    foreach ($made['assets'] as $id) {
        mysqli_query($mysqli, "DELETE FROM ticket_assets WHERE asset_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM asset_interfaces WHERE interface_asset_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM asset_history WHERE asset_history_asset_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM assets WHERE asset_id = " . intval($id));
    }
    foreach ($made['invoices'] as $id) {
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = " . intval($id));
    }
    foreach ($made['tickets'] as $id) {
        mysqli_query($mysqli, "DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM ticket_history WHERE ticket_history_ticket_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM tickets WHERE ticket_id = " . intval($id));
    }
    foreach ($made['products'] as $id) {
        mysqli_query($mysqli, "DELETE FROM product_stock WHERE stock_product_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM products WHERE product_id = " . intval($id));
    }
    foreach ($made['taxes'] as $id) {
        mysqli_query($mysqli, "DELETE FROM taxes WHERE tax_id = " . intval($id));
    }
    foreach ($made['projects'] as $id) {
        mysqli_query($mysqli, "DELETE FROM projects WHERE project_id = " . intval($id));
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
