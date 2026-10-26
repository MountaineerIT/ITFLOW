<?php
/*
 * Procurement end to end on real rows (functions/mit_procurement*.php), the parts the stock scenario does not cover:
 *  - approval rules: under the limit = approved on submit; over it, or a line with no cost = pending;
 *  - self-approval: a non-admin Full approver may not approve a PO they shaped while an admin can; the admin's is logged self-approved;
 *  - receiving: partial then the rest, serials, one asset per unit (names #n, serial, PO reference, vendor, the ticket linked),
 *    the ticket part pointed at its asset, internal note; stock for our own items at the PO's cost;
 *  - vendor bill -> one ITFlow expense (amount, vendor, account, category, client), the PO's stock moves tied to it, not twice;
 *  - cancel -> items waiting again; PO PDF and email (attachment in the queue);
 *  - ticket parts: stock taken / moved / returned exactly once, to-order item follows the part, billing with tax onto a new draft
 *    (ITFlow's totals), not billed twice, Unbilled count, a cancelled order is not billable;
 *  - invoice line order status and the project card's numbers.
 */
require __DIR__ . '/bootstrap.php';
$root = getcwd();
$client_id = scenarioClient();
$account = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT account_id FROM accounts ORDER BY account_id LIMIT 1"))[0] ?? 0);
$vendor = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_id LIMIT 1"))[0] ?? 0);
$category = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT category_id FROM categories WHERE category_type = 'Expense' AND category_archived_at IS NULL ORDER BY category_id LIMIT 1"))[0] ?? 0);
$admin_id = $session_user_id;
$made = ['products' => [], 'items' => [], 'pos' => [], 'tickets' => [], 'invoices' => [], 'expenses' => [], 'assets' => [], 'taxes' => [], 'projects' => [], 'mail' => []];
$q1 = function ($sql) use ($mysqli) {
    $row = mysqli_fetch_row(mysqli_query($mysqli, $sql));
    return $row ? $row[0] : null;
};
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
    $id = mitPoCreate($item_ids, $vendor);
    $made['pos'][] = $id;
    return $id;
};
$as = function ($user_id, $role_id, $admin) {
    $GLOBALS['session_user_id'] = $user_id;
    $GLOBALS['session_user_role'] = $role_id;
    $GLOBALS['session_is_admin'] = $admin;
    $GLOBALS['session_name'] = $admin ? 'Scenario admin' : 'Scenario buyer';
};
$po_log = function ($po_id) use ($mysqli) {
    return implode("\n", array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT description FROM mit_po_history WHERE po_id = " . intval($po_id) . " ORDER BY history_id"), MYSQLI_ASSOC), 'description'));
};
try {
    mitSetSetting('procurement_approval_on', '1');
    mitSetSetting('procurement_approval_over', '500');

    // 1. Approval rules
    $a = $po([$item(['name' => 'ZZ scen cable', 'quantity' => 2, 'unit_cost' => 100, 'client_id' => $client_id])]);
    scenarioCheck('200 under 500: approved on submit', mitPoSubmit(mitPoRow($a)), 'approved');
    $b = $po([$item(['name' => 'ZZ scen free thing', 'quantity' => 1, 'unit_cost' => null, 'client_id' => $client_id])]);
    scenarioCheck('a line with no cost: pending', mitPoSubmit(mitPoRow($b)), 'pending');
    $c = $po([$item(['name' => 'ZZ scen server', 'quantity' => 6, 'unit_cost' => 100, 'client_id' => $client_id])]);
    scenarioCheck('600 over 500: pending', mitPoSubmit(mitPoRow($c)), 'pending');
    scenarioCheck('PO total from lines', (float) mitPoRow($c)['total'], 600.0);

    // 2. Self-approval: a non-admin Full approver (role with Procurement 3) made it - another approver (the admin) exists
    $full_role = intval($q1("SELECT user_role_id FROM user_role_permissions urp JOIN modules m ON m.module_id = urp.module_id JOIN user_roles r ON r.role_id = urp.user_role_id
        WHERE m.module_name = 'module_procurement' AND urp.user_role_permission_level = 3 AND r.role_is_admin = 0 LIMIT 1"));
    $buyer = intval($q1("SELECT user_id FROM users WHERE user_type = 1 AND user_id != $admin_id ORDER BY user_id LIMIT 1"));
    if ($full_role && $buyer) {
        $as($buyer, $full_role, false);
        $d = $po([$item(['name' => 'ZZ scen own PO', 'quantity' => 7, 'unit_cost' => 100, 'client_id' => $client_id])]);
        mitPoSubmit(mitPoRow($d));
        scenarioCheck('non-admin buyer may not approve their own PO', mitPoMayApprove(mitPoRow($d)), false);
        $as($admin_id, 0, true);
        scenarioCheck('another (the admin) may', mitPoMayApprove(mitPoRow($d)), true);
        mitPoDecide(mitPoRow($d), true, '');
        scenarioCheck('approved', mitPoRow($d)['status'], 'approved');
        $as($admin_id, 0, true);
        $e = $po([$item(['name' => 'ZZ scen admin own', 'quantity' => 9, 'unit_cost' => 100, 'client_id' => $client_id])]);
        mitPoSubmit(mitPoRow($e));
        scenarioCheck('an admin may approve their own', mitPoMayApprove(mitPoRow($e)), true);
        mitPoDecide(mitPoRow($e), true, '');
        scenarioCheck('... logged as self-approved', strpos($po_log($e), 'self-approved') !== false, true);
    } else {
        echo "  skip self-approval (no non-admin Full Procurement role)\n";
    }
    $as($admin_id, 0, true);

    // 3. Receiving for a ticket part: assets per unit, serials, ticket link, internal note
    $ticket_number = intval($q1("SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets"));
    mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = $ticket_number, ticket_subject = 'ZZ scenario procurement', ticket_details = 'x',
        ticket_priority = 'Low', ticket_status = 1, ticket_client_id = $client_id, ticket_created_by = $admin_id");
    $tid = $made['tickets'][] = mysqli_insert_id($mysqli);
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $tid"));
    [$ok, $msg, $part_id] = mitTicketPartSave($ticket, ['name' => 'ZZ scen laptop', 'quantity' => '3', 'unit_price' => '900', 'unit_cost' => '700', 'source' => 'order', 'vendor_id' => $vendor]);
    scenarioCheck('to-order part added', $ok, true);
    $part = mitTicketParts($tid)[$part_id];
    $made['items'][] = intval($part['proc_item_id']);
    scenarioCheck('its item waits to be ordered', $part['proc_status'], 'waiting');
    // Changing the part while waiting: the item follows
    mitTicketPartSave($ticket, ['name' => 'ZZ scen laptop', 'quantity' => '2', 'unit_price' => '900', 'unit_cost' => '700', 'source' => 'order', 'vendor_id' => $vendor], $part_id);
    scenarioCheck('item follows the part (qty 2)', (float) $q1("SELECT quantity FROM mit_procurement_items WHERE item_id = " . intval($part['proc_item_id'])), 2.0);
    $f = $po([intval($part['proc_item_id'])]);
    mitPoSubmit(mitPoRow($f)); // 1400 - pending
    mitPoDecide(mitPoRow($f), true, '');
    [$ok, $msg] = mitTicketPartSave($ticket, ['name' => 'ZZ scen laptop', 'quantity' => '5', 'unit_price' => '900', 'source' => 'order'], $part_id);
    scenarioCheck('part on a PO: quantity change refused', $ok, false);
    mitPoMarkOrdered(mitPoRow($f), date('Y-m-d'), 'V-1');
    $line = mitPoLines($f)[0];
    [$n] = mitPoReceive([$line['line_id'] => ['qty' => 1, 'serials' => 'SN-A', 'asset_type' => 'Laptop']], ['assets' => 1, 'notes' => 1]);
    scenarioCheck('partial: PO partial', mitPoRow($f)['status'], 'partial');
    [$n, $pos, $extras] = mitPoReceive([$line['line_id'] => ['qty' => 5, 'serials' => "SN-B", 'asset_type' => 'Laptop']], ['assets' => 1, 'notes' => 1]);
    scenarioCheck('rest: capped at what was left (1)', (float) $q1("SELECT received_qty FROM mit_po_lines WHERE line_id = " . intval($line['line_id'])), 2.0);
    scenarioCheck('PO received', mitPoRow($f)['status'], 'received');
    scenarioCheck('item received', $q1("SELECT status FROM mit_procurement_items WHERE item_id = " . intval($part['proc_item_id'])), 'received');
    $assets = mysqli_fetch_all(mysqli_query($mysqli, "SELECT asset_id, asset_name, asset_serial, asset_purchase_reference, asset_vendor_id, asset_client_id FROM assets
        WHERE asset_purchase_reference = '" . escapeSql(mitPoReference(mitPoRow($f))) . "' ORDER BY asset_id"), MYSQLI_ASSOC);
    $made['assets'] = array_merge($made['assets'], array_column($assets, 'asset_id'));
    scenarioCheck('one asset per unit received (2)', count($assets), 2);
    scenarioCheck('serials on the assets', array_column($assets, 'asset_serial'), ['SN-A', 'SN-B']);
    scenarioCheck('assets are the client\'s, from the vendor', array_unique(array_map('intval', array_column($assets, 'asset_client_id'))) === [$client_id] && intval($assets[0]['asset_vendor_id'] ?? 0) === $vendor, true);
    scenarioCheck('assets linked to the ticket', intval($q1("SELECT COUNT(*) FROM ticket_assets WHERE ticket_id = $tid")), 2);
    scenarioCheck('part points at its asset', intval(mitTicketParts($tid)[$part_id]['asset_id']), intval($assets[0]['asset_id'] ?? -1));
    scenarioCheck('internal notes on the ticket (2 receipts)', intval($q1("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tid AND ticket_reply LIKE 'Received %'")), 2);
    [$ok] = mitTicketPartRemove($ticket, $part_id + 0) ;
    // (put it back for billing below)
    if ($ok) {
        [$ok, $msg, $part_id] = mitTicketPartSave($ticket, ['name' => 'ZZ scen laptop', 'quantity' => '2', 'unit_price' => '900', 'unit_cost' => '700', 'source' => 'other']);
    }

    // 4. Stock for our own items at the PO's cost; vendor bill
    $pid = $product('ZZ scen shelf mouse', 0);
    $g = $po([$item(['name' => 'ZZ scen shelf mouse', 'quantity' => 4, 'unit_cost' => 12.5, 'client_id' => 0, 'product_id' => $pid])]);
    mitPoSubmit(mitPoRow($g));
    mitPoMarkOrdered(mitPoRow($g), date('Y-m-d'), '');
    mitPoReceive([mitPoLines($g)[0]['line_id'] => ['qty' => 4]], ['stock' => 1]);
    scenarioCheck('own stock: 4 on the shelf', productOnHand($pid), 4.0);
    scenarioCheck('... at the PO cost', (float) $q1("SELECT stock_unit_cost FROM product_stock WHERE stock_product_id = $pid AND stock_type = 'received' ORDER BY stock_id DESC LIMIT 1"), 12.5);
    [$expense_id, $message] = mitPoRecordBill(mitPoRow($g), ['amount' => '55.00', 'date' => date('Y-m-d'), 'category' => $category, 'account' => $account, 'reference' => 'VB-9']);
    if ($expense_id) {
        $made['expenses'][] = $expense_id;
    }
    scenarioCheck("vendor bill recorded ($message)", $expense_id > 0, true);
    $exp = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM expenses WHERE expense_id = " . intval($expense_id)));
    scenarioCheck('expense: amount, vendor, account, reference', [(float) ($exp['expense_amount'] ?? 0), intval($exp['expense_vendor_id'] ?? 0), intval($exp['expense_account_id'] ?? 0), $exp['expense_reference'] ?? ''],
        [55.0, $vendor, $account, 'VB-9']);
    scenarioCheck('PO points at the expense', intval(mitPoRow($g)['expense_id']), intval($expense_id));
    scenarioCheck('stock received on the PO tied to the bill', intval($q1("SELECT stock_expense_id FROM product_stock WHERE stock_product_id = $pid AND stock_type = 'received' LIMIT 1")), intval($expense_id));
    [$again] = mitPoRecordBill(mitPoRow($g), ['amount' => '55.00', 'date' => date('Y-m-d'), 'category' => $category, 'account' => $account]);
    scenarioCheck('vendor bill not recorded twice', $again, 0);

    // 5. Cancel: items back on the list; PDF; email
    $h_item = $item(['name' => 'ZZ scen cancel me', 'quantity' => 1, 'unit_cost' => 10, 'client_id' => $client_id]);
    $h = $po([$h_item]);
    mitPoCancel(mitPoRow($h));
    scenarioCheck('cancelled PO: its item waits again', $q1("SELECT status FROM mit_procurement_items WHERE item_id = $h_item"), 'waiting');
    $pdf = sys_get_temp_dir() . '/zz-scen-po.pdf';
    scenarioCheck('PO PDF made', mitPoPdf(mitPoRow($a), $pdf) && filesize($pdf) > 1000, true);
    @unlink($pdf);
    $GLOBALS['config_smtp_provider'] = $GLOBALS['config_smtp_provider'] ?: 'smtp';
    $before = intval($q1("SELECT COALESCE(MAX(email_id), 0) FROM email_queue"));
    $err = mitPoEmail(mitPoRow($a), 'vendor-scenario@example.invalid');
    $mail = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM email_queue WHERE email_id > $before AND email_recipient = 'vendor-scenario@example.invalid'"));
    scenarioCheck("PO emailed ($err)", (bool) $mail, true);
    if ($mail) {
        $made['mail'][] = $mail['email_id'];
        $att = json_decode((string) ($mail['email_attachments'] ?? ''), true);
        scenarioCheck('... with the PDF attached', is_array($att) && is_file($root . '/' . ($att[0]['path'] ?? 'x')), true);
        if (is_array($att)) {
            @unlink($root . '/' . $att[0]['path']);
        }
    }

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
    // A part from a product that did not track stock then; tracking turned on later - removing it must not add stock never taken
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
    // A cancelled order is not billable
    [$ok, $msg, $p3] = mitTicketPartSave($ticket, ['name' => 'ZZ scen dock', 'quantity' => '1', 'unit_price' => '150', 'source' => 'order']);
    $made['items'][] = intval(mitTicketParts($tid)[$p3]['proc_item_id']);
    mysqli_query($mysqli, "UPDATE mit_procurement_items SET status = 'cancelled' WHERE item_id = " . intval(mitTicketParts($tid)[$p3]['proc_item_id']));
    [$inv3] = mitTicketPartsBill($ticket, [$p3], [], 0, date('Y-m-d'), 0);
    scenarioCheck('a cancelled order is not billed', $inv3, 0);

    // 8. Invoice line order status: line sent to procurement, half received
    $line_inv = mysqli_fetch_row(mysqli_query($mysqli, "SELECT item_id FROM invoice_items WHERE item_invoice_id = " . intval($inv) . " ORDER BY item_order LIMIT 1"))[0];
    $line_inv2 = mysqli_fetch_row(mysqli_query($mysqli, "SELECT item_id FROM invoice_items WHERE item_invoice_id = " . intval($inv) . " ORDER BY item_order DESC LIMIT 1"))[0];
    $i_item = $item(['source' => 'invoice', 'invoice_id' => $inv, 'invoice_item_id' => $line_inv, 'name' => 'ZZ scen ram', 'quantity' => 2, 'unit_cost' => 30, 'client_id' => $client_id]);
    $item(['source' => 'invoice', 'invoice_id' => $inv, 'invoice_item_id' => $line_inv2, 'name' => 'ZZ scen fan', 'quantity' => 1, 'unit_cost' => 9, 'client_id' => $client_id]);
    $k = $po([$i_item]);
    mitPoSubmit(mitPoRow($k));
    mitPoMarkOrdered(mitPoRow($k), date('Y-m-d'), '');
    mitPoReceive([mitPoLines($k)[0]['line_id'] => ['qty' => 1]], []);
    $status = mitProcInvoiceStatus($inv)[intval($line_inv)] ?? [];
    scenarioCheck('line status: 1 of 2 received', [(float) ($status['qty'] ?? 0), (float) ($status['received'] ?? 0)], [2.0, 1.0]);
    scenarioCheck('line status badge: 1 of 2 received', strpos(mitProcInvoiceLineStatus($inv, $line_inv), '1 of 2 received') !== false, true);
    scenarioCheck('other line: waiting to order', strpos(mitProcInvoiceLineStatus($inv, $line_inv2), 'Waiting to order') !== false, true);
    scenarioCheck('invoice summary: 1 of 3 received', strpos(mitProcInvoiceSummary($inv), '1 of 3 received') !== false, true);

    // 9. Project card: cost of POs + waiting items, received of ordered
    mysqli_query($mysqli, "INSERT INTO projects SET project_prefix = 'PRJ-', project_number = 9999, project_name = 'ZZ scen project', project_description = '', project_due = CURDATE(), project_client_id = $client_id");
    $prj = $made['projects'][] = mysqli_insert_id($mysqli);
    $item(['name' => 'ZZ scen proj waiting', 'quantity' => 2, 'unit_cost' => 25, 'client_id' => $client_id, 'project_id' => $prj]);
    $m = $po([$item(['name' => 'ZZ scen proj ordered', 'quantity' => 3, 'unit_cost' => 100, 'client_id' => $client_id, 'project_id' => $prj])]);
    mitPoSubmit(mitPoRow($m));
    mitPoMarkOrdered(mitPoRow($m), date('Y-m-d'), '');
    mitPoReceive([mitPoLines($m)[0]['line_id'] => ['qty' => 1]], []);
    $card = preg_replace('/\s+/', ' ', strip_tags(str_replace('</div>', ' </div>', mitProcProjectCard($prj))));
    scenarioCheck('project card: hardware cost 350', strpos($card, 'Hardware cost $350.00') !== false, true);
    scenarioCheck('project card: received 1 of 3, 1 still to order', strpos($card, 'Received 1 of 3') !== false && strpos($card, 'Still to order 1 item') !== false, true);
    if (strpos($card, 'Hardware cost $350.00') === false) {
        echo "       card: $card\n";
    }
} finally {
    $as($admin_id, 0, true);
    foreach ($made['pos'] as $id) {
        if ($id) {
            mysqli_query($mysqli, "DELETE FROM mit_purchase_orders WHERE po_id = " . intval($id));
        }
    }
    foreach (array_filter($made['items']) as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_procurement_items WHERE item_id = " . intval($id));
    }
    mysqli_query($mysqli, "DELETE FROM mit_procurement_items WHERE name LIKE 'ZZ scen%'");
    foreach ($made['assets'] as $id) {
        mysqli_query($mysqli, "DELETE FROM ticket_assets WHERE asset_id = " . intval($id));
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
    foreach ($made['expenses'] as $id) {
        mysqli_query($mysqli, "UPDATE product_stock SET stock_expense_id = NULL WHERE stock_expense_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM expenses WHERE expense_id = " . intval($id));
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
    foreach ($made['mail'] as $id) {
        mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_id = " . intval($id));
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
