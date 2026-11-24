<?php

/*
 * Mountaineer IT - database update M068
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Purchasing moves to ITFlow 26.12's own (functions/purchase_orders.php - built from ours; the user chose ITFlow's, Nov 22).
 * Our procurement rows are copied into ITFlow's tables with the SAME ids, so ticket parts (mit_ticket_parts.proc_item_id),
 * history text and links still point right:
 *   mit_procurement_items -> order_items   (our sources ticket / quote / invoice are ITFlow's 'manual'; the real source and the
 *                                           ticket / project / invoice / invoice line go to mit_order_item_links)
 *   mit_purchase_orders   -> purchase_orders (ship_to 'office' = the main office 0, 'office:<id>' = that office, 'other' = NULL
 *                                           with the address; the company's currency)
 *   mit_po_lines          -> po_items      (a line's project -> mit_po_item_projects)
 *   mit_po_receipts       -> po_receipts   (the stock move it made looked up in product_stock; what our receiving did beyond
 *                                           ITFlow's - returns to an invoice line, cleared lines, the ticket note - kept in
 *                                           mit_po_receipt_extras for undoing it)
 *   mit_po_tracking       -> po_tracking   (its note / delivered tick have no place in ITFlow's)
 *   mit_po_history        -> logs          (ITFlow keeps a PO's history in the audit log, type 'Purchase Order')
 *   config_po_next_number past the highest number copied.
 * New tables of ours: mit_order_item_links, mit_po_item_projects, mit_po_receipt_extras (FK ITFlow's rows ON DELETE CASCADE).
 * Our old mit_procurement_items / mit_purchase_orders / mit_po_* tables are left as they were (dropped one release on).
 * Safe to run twice: rows already copied are skipped (INSERT IGNORE on the kept ids), the history once (marker m068_history).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

$m068_table = function ($table) use ($mysqli) {
    $table = mysqli_real_escape_string($mysqli, $table);
    $result = mysqli_query($mysqli, "SHOW TABLES LIKE '$table'");
    return $result && mysqli_num_rows($result) > 0;
};

if ($m068_table('order_items') && $m068_table('purchase_orders') && $m068_table('po_items') && $m068_table('po_receipts')) {

    mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_order_item_links` (
        `order_item_id` int(11) NOT NULL,
        `source` varchar(20) NOT NULL DEFAULT 'manual',
        `ticket_id` int(11) NOT NULL DEFAULT 0,
        `project_id` int(11) NOT NULL DEFAULT 0,
        `invoice_id` int(11) NOT NULL DEFAULT 0,
        `invoice_item_id` int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`order_item_id`),
        KEY `mit_order_item_links_ticket` (`ticket_id`),
        KEY `mit_order_item_links_invoice` (`invoice_id`, `invoice_item_id`),
        CONSTRAINT `mit_order_item_links_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`order_item_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_po_item_projects` (
        `po_item_id` int(11) NOT NULL,
        `project_id` int(11) NOT NULL,
        PRIMARY KEY (`po_item_id`),
        KEY `mit_po_item_projects_project` (`project_id`),
        CONSTRAINT `mit_po_item_projects_item` FOREIGN KEY (`po_item_id`) REFERENCES `po_items` (`po_item_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_po_receipt_extras` (
        `po_receipt_id` int(11) NOT NULL,
        `tracked` tinyint(1) NOT NULL DEFAULT 1,
        `stock_product_id` int(11) NOT NULL DEFAULT 0,
        `stock_qty` decimal(15,2) NOT NULL DEFAULT 0.00,
        `return_product_id` int(11) NOT NULL DEFAULT 0,
        `return_qty` decimal(15,2) NOT NULL DEFAULT 0.00,
        `return_invoice_id` int(11) NOT NULL DEFAULT 0,
        `return_item_id` int(11) NOT NULL DEFAULT 0,
        `cleared_item_id` int(11) NOT NULL DEFAULT 0,
        `note_reply_id` int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (`po_receipt_id`),
        CONSTRAINT `mit_po_receipt_extras_receipt` FOREIGN KEY (`po_receipt_id`) REFERENCES `po_receipts` (`po_receipt_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Items to order
    if ($m068_table('mit_procurement_items')) {
        mysqli_query($mysqli, "INSERT IGNORE INTO order_items (order_item_id, order_item_name, order_item_description, order_item_quantity, order_item_cost,
                order_item_status, order_item_source, order_item_note, order_item_product_id, order_item_vendor_id, order_item_client_id,
                order_item_created_by, order_item_created_at, order_item_updated_at)
            SELECT item_id, name, description, quantity, unit_cost,
                IF(status IN ('waiting', 'ordered', 'received', 'cancelled'), status, IF(status = 'partial', 'ordered', 'waiting')),
                IF(source = 'reorder', 'reorder', 'manual'), note, IFNULL(product_id, 0), IFNULL(vendor_id, 0), IFNULL(client_id, 0),
                IFNULL(created_by, 0), created_at, updated_at
            FROM mit_procurement_items");
        mysqli_query($mysqli, "INSERT IGNORE INTO mit_order_item_links (order_item_id, source, ticket_id, project_id, invoice_id, invoice_item_id)
            SELECT p.item_id, p.source, IFNULL(p.ticket_id, 0), IFNULL(p.project_id, 0), IFNULL(p.invoice_id, 0), IFNULL(p.invoice_item_id, 0)
            FROM mit_procurement_items p JOIN order_items o ON o.order_item_id = p.item_id
            WHERE p.source NOT IN ('manual', 'reorder') OR IFNULL(p.ticket_id, 0) > 0 OR IFNULL(p.project_id, 0) > 0 OR IFNULL(p.invoice_id, 0) > 0");
    }

    // Purchase orders
    if ($m068_table('mit_purchase_orders')) {
        $currency = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT company_currency FROM companies WHERE company_id = 1"))[0] ?? 'USD');
        $currency = mysqli_real_escape_string($mysqli, $currency !== '' ? $currency : 'USD');
        mysqli_query($mysqli, "INSERT IGNORE INTO purchase_orders (po_id, po_prefix, po_number, po_status, po_vendor_id, po_currency_code, po_ship_to_office_id,
                po_ship_to, po_note, po_shipping, po_total, po_vendor_reference, po_order_date, po_created_by, po_submitted_at, po_approved_by, po_approved_at,
                po_approval_note, po_ordered_by, po_ordered_at, po_received_at, po_cancelled_at, po_expense_id, po_created_at, po_updated_at)
            SELECT po_id, po_prefix, po_number, status, IFNULL(vendor_id, 0), '$currency',
                CASE WHEN ship_to = 'office' THEN 0 WHEN ship_to LIKE 'office:%' THEN CAST(SUBSTRING(ship_to, 8) AS UNSIGNED) ELSE NULL END,
                ship_to_address, note, IFNULL(shipping, 0), IFNULL(total, 0), vendor_order_number, order_date, IFNULL(created_by, 0), submitted_at,
                IFNULL(approved_by, 0), approved_at, approval_note, IFNULL(ordered_by, 0), ordered_at, received_at, cancelled_at, IFNULL(expense_id, 0),
                created_at, updated_at
            FROM mit_purchase_orders");
    }

    // Lines
    if ($m068_table('mit_po_lines')) {
        mysqli_query($mysqli, "INSERT IGNORE INTO po_items (po_item_id, po_item_po_id, po_item_order_item_id, po_item_product_id, po_item_client_id, po_item_name,
                po_item_description, po_item_quantity, po_item_cost, po_item_received, po_item_order)
            SELECT l.line_id, l.po_id, IFNULL(l.proc_item_id, 0), IFNULL(p.product_id, 0), IFNULL(l.client_id, 0), l.name, l.description, l.quantity,
                IFNULL(l.unit_cost, 0), IFNULL(l.received_qty, 0), IFNULL(l.sort, 0)
            FROM mit_po_lines l JOIN purchase_orders po ON po.po_id = l.po_id
            LEFT JOIN mit_procurement_items p ON p.item_id = l.proc_item_id");
        mysqli_query($mysqli, "INSERT IGNORE INTO mit_po_item_projects (po_item_id, project_id)
            SELECT l.line_id, l.project_id FROM mit_po_lines l JOIN po_items i ON i.po_item_id = l.line_id WHERE IFNULL(l.project_id, 0) > 0");
    }

    // Receipts - and the stock move each made, for ITFlow's undo
    if ($m068_table('mit_po_receipts')) {
        $extras = mitColumnExists($mysqli, 'mit_po_receipts', 'tracked');
        $sql = mysqli_query($mysqli, "SELECT r.*, po.po_prefix, po.po_number FROM mit_po_receipts r JOIN purchase_orders po ON po.po_id = r.po_id
            WHERE NOT EXISTS (SELECT 1 FROM po_receipts x WHERE x.po_receipt_id = r.receipt_id)");
        while ($row = mysqli_fetch_assoc($sql)) {
            $stock_id = 0;
            if ($extras && intval($row['stock_product_id']) && floatval($row['stock_qty']) > 0) {
                $note = mysqli_real_escape_string($mysqli, 'Received on ' . $row['po_prefix'] . $row['po_number']);
                $found = mysqli_fetch_row(mysqli_query($mysqli, "SELECT stock_id FROM product_stock WHERE stock_type = 'received' AND stock_note = '$note'
                    AND stock_product_id = " . intval($row['stock_product_id']) . " AND ABS(stock_qty - " . floatval($row['stock_qty']) . ") < 0.005
                    ORDER BY ABS(TIMESTAMPDIFF(SECOND, stock_created_at, '" . mysqli_real_escape_string($mysqli, (string) $row['received_at']) . "')) LIMIT 1"));
                $stock_id = intval($found[0] ?? 0);
            }
            $text = function ($value) use ($mysqli) {
                return $value === null ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'";
            };
            mysqli_query($mysqli, "INSERT IGNORE INTO po_receipts SET po_receipt_id = " . intval($row['receipt_id']) . ", po_receipt_po_id = " . intval($row['po_id']) . ",
                po_receipt_item_id = " . intval($row['line_id']) . ", po_receipt_quantity = " . floatval($row['quantity']) . ", po_receipt_serials = " . $text($row['serials']) . ",
                po_receipt_asset_ids = " . $text($row['asset_ids'] ?? null) . ", po_receipt_stock_id = $stock_id, po_receipt_by = " . intval($row['received_by']) . ",
                po_receipt_at = " . $text($row['received_at']));
            if ($extras) {
                mysqli_query($mysqli, "INSERT IGNORE INTO mit_po_receipt_extras SET po_receipt_id = " . intval($row['receipt_id']) . ", tracked = " . intval($row['tracked']) . ",
                    stock_product_id = " . intval($row['stock_product_id']) . ", stock_qty = " . floatval($row['stock_qty']) . ",
                    return_product_id = " . intval($row['return_product_id']) . ", return_qty = " . floatval($row['return_qty']) . ",
                    return_invoice_id = " . intval($row['return_invoice_id']) . ", return_item_id = " . intval($row['return_item_id']) . ",
                    cleared_item_id = " . intval($row['cleared_item_id']) . ", note_reply_id = " . intval($row['note_reply_id']));
            }
        }
    }

    // Tracking numbers
    if ($m068_table('mit_po_tracking') && $m068_table('po_tracking')) {
        mysqli_query($mysqli, "INSERT IGNORE INTO po_tracking (po_tracking_id, po_tracking_po_id, po_tracking_carrier, po_tracking_number, po_tracking_eta, po_tracking_created_at)
            SELECT t.tracking_id, t.po_id, IFNULL(t.carrier, ''), t.tracking_number, t.eta, t.created_at FROM mit_po_tracking t JOIN purchase_orders po ON po.po_id = t.po_id");
    }

    // History -> ITFlow's audit log, once
    $marker = mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM mit_settings WHERE setting_name = 'm068_history'"));
    if (!$marker && $m068_table('mit_po_history')) {
        mysqli_query($mysqli, "INSERT INTO logs (log_type, log_action, log_description, log_ip, log_user_agent, log_created_at, log_client_id, log_user_id, log_entity_id)
            SELECT 'Purchase Order', 'Edit', h.description, '', 'Mountaineer IT (moved from our purchase order history)', h.created_at, 0, IFNULL(h.user_id, 0), h.po_id
            FROM mit_po_history h JOIN purchase_orders po ON po.po_id = h.po_id ORDER BY h.history_id");
        mysqli_query($mysqli, "INSERT INTO mit_settings SET setting_name = 'm068_history', setting_value = NOW() ON DUPLICATE KEY UPDATE setting_value = setting_value");
    }

    // The next number past every one copied
    if (mitColumnExists($mysqli, 'settings', 'config_po_next_number')) {
        $ours = mysqli_fetch_row(mysqli_query($mysqli, "SELECT setting_value FROM mit_settings WHERE setting_name = 'procurement_po_next'"));
        $highest = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT MAX(po_number) FROM purchase_orders"))[0] ?? 0);
        $next = max($highest + 1, intval($ours[0] ?? 0));
        mysqli_query($mysqli, "UPDATE settings SET config_po_next_number = GREATEST(config_po_next_number, $next) WHERE company_id = 1");
    }
}
