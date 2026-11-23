<?php

/*
 * Mountaineer IT - procurement on top of ITFlow 26.12's own Purchasing (functions/purchase_orders.php: To Order,
 * purchase orders, Receiving - order_items, purchase_orders, po_items, po_receipts, po_tracking; history in the audit
 * log). The user chose ITFlow's (Nov 22); M068 moved our rows into its tables with the same ids. What stays ours:
 *
 *  - where an item to order came from - a ticket part, a quote line, an invoice line - and its project
 *    (mit_order_item_links, keyed by order_item_id; a PO line's project in mit_po_item_projects);
 *  - ticket parts, billing them, To Bill, quote / invoice -> To Order (functions/mit_procurement_parts.php, mit_to_bill.php);
 *  - extras on ITFlow's receiving and its undo (mit_po_receipt_extras), the approval rule "not your own PO - made or
 *    shaped", the total the approver saw, our notification switches, a PO line's project
 *    (functions/mit_procurement_extras.php) - one guarded line each in ITFlow's files.
 *
 * Switch and permission are ITFlow's: moduleOn('purchasing') and the role's Procurement level (poLevel()).
 */

/** Is ITFlow's Purchasing there (database 3.9.3) with our link tables (M068)? */
function mitProcReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = function_exists('poReady') && poReady() && mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_order_item_links'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Is Purchasing in use: ready and switched on (Admin > Settings > Modules)? */
function mitProcOn() {
    return mitProcReady() && function_exists('moduleOn') && moduleOn('purchasing');
}

/** The signed-in agent's Procurement level - ITFlow's (0 none or switched off, 1 view, 2 edit, 3 full). */
function mitProcLevel() {
    return mitProcReady() && function_exists('poLevel') ? poLevel() : 0;
}

/** A PO's number as shown - PO-12 (ITFlow's poNumber, from a row of purchase_orders or one with po_prefix / po_number). */
function mitPoReference(array $po) {
    return $po['po_prefix'] . $po['po_number'];
}

/** Where a PO opens - ITFlow's page. */
function mitPoUrl($po_id) {
    return 'purchase_order.php?po_id=' . intval($po_id);
}

/** Money in the company currency. */
function mitMoney($amount) {
    global $currency_format, $session_company_currency;
    // Outside a page (cron, scripts) there is no formatter - ITFlow's own billingMoney then
    if (!$currency_format instanceof NumberFormatter) {
        return billingMoney((float) $amount, $session_company_currency ?: '');
    }
    return numfmt_format_currency($currency_format, (float) $amount, $session_company_currency);
}

/** A quantity without needless decimals: 6, 2.5 */
function mitQty($qty) {
    return rtrim(rtrim(number_format((float) $qty, 2, '.', ''), '0'), '.');
}

/** Who the signed-in agent is, unescaped (the session keeps it SQL-escaped). */
function mitProcWho() {
    global $session_name;
    return stripslashes((string) $session_name);
}

/** A database lock around receiving or billing (two clicks at once wait for each other); released when the request ends anyway. */
function mitProcLock($what) {
    if (!mitLock('proc_' . preg_replace('/[^a-z]/', '', $what))) {
        flashAlert('Someone else is doing this right now - try again in a moment.', 'error');
        redirect();
    }
}

function mitProcUnlock($what) {
    mitUnlock('proc_' . preg_replace('/[^a-z]/', '', $what));
}

/*
 * ---------- items to order: ITFlow's order_items + where they came from ----------
 */

/**
 * Put an item on ITFlow's To Order list, with where it came from. $item: name, quantity, unit_cost, vendor_id, client_id,
 * project_id, product_id, description, note, source (ticket | quote | invoice | manual), invoice_id, invoice_item_id, ticket_id.
 * A direct row like ITFlow's orderItemSave writes (source 'manual' - ITFlow knows manual / reorder), without its checks that
 * don't fit here (a ticket part's client is the ticket's; a part may have no cost). Returns the order_item_id, or 0.
 */
function mitProcAddItem(array $item) {
    global $mysqli, $session_user_id;
    if (!mitProcReady()) {
        return 0;
    }
    $name = trim((string) ($item['name'] ?? ''));
    $qty = (float) ($item['quantity'] ?? 0);
    if ($name === '' || $qty <= 0) {
        return 0;
    }
    $cost = isset($item['unit_cost']) && $item['unit_cost'] !== '' && $item['unit_cost'] !== null ? round((float) $item['unit_cost'], 2) : 'NULL';
    $source = in_array($item['source'] ?? '', ['manual', 'quote', 'invoice', 'ticket'], true) ? $item['source'] : 'manual';
    $description = trim((string) ($item['description'] ?? ''));
    $note = trim((string) ($item['note'] ?? ''));
    mysqli_query($mysqli, "INSERT INTO order_items SET order_item_name = '" . escapeSql(mb_substr($name, 0, 255)) . "',
        order_item_description = " . ($description === '' ? 'NULL' : "'" . escapeSql($description) . "'") . ",
        order_item_quantity = " . round($qty, 2) . ", order_item_cost = $cost, order_item_status = 'waiting', order_item_source = 'manual',
        order_item_note = " . ($note === '' ? 'NULL' : "'" . escapeSql(mb_substr($note, 0, 255)) . "'") . ",
        order_item_product_id = " . intval($item['product_id'] ?? 0) . ", order_item_vendor_id = " . intval($item['vendor_id'] ?? 0) . ",
        order_item_client_id = " . intval($item['client_id'] ?? 0) . ", order_item_created_by = " . intval($session_user_id));
    $order_item_id = intval(mysqli_insert_id($mysqli));
    if ($order_item_id) {
        mysqli_query($mysqli, "INSERT INTO mit_order_item_links SET order_item_id = $order_item_id, source = '$source',
            ticket_id = " . intval($item['ticket_id'] ?? 0) . ", project_id = " . intval($item['project_id'] ?? 0) . ",
            invoice_id = " . intval($item['invoice_id'] ?? 0) . ", invoice_item_id = " . intval($item['invoice_item_id'] ?? 0));
    }
    return $order_item_id;
}

/** Where an order item came from - its mit_order_item_links row (source, ticket_id, project_id, invoice_id, invoice_item_id), or null. */
function mitProcItemLink($order_item_id) {
    global $mysqli;
    $order_item_id = intval($order_item_id);
    if (!$order_item_id || !mitProcReady()) {
        return null;
    }
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT source, ticket_id, project_id, invoice_id, invoice_item_id FROM mit_order_item_links WHERE order_item_id = $order_item_id")) ?: null;
}

/*
 * ---------- approval, on ITFlow's purchase orders ----------
 */

/**
 * Who shaped this PO - made it, added items, changed lines, quantities, costs or shipping, took lines off, or sent it for
 * approval: user ids, from its history in ITFlow's audit log (type 'Purchase Order', entity = po_id; our older PO history
 * was moved there by M068). Approving one of these is approving their own PO, whoever created the draft.
 */
function mitPoShapers(array $po) {
    global $mysqli;
    $po_id = intval($po['po_id']);
    $ids = [intval($po['po_created_by'] ?? 0) => true];
    $result = mysqli_query($mysqli, "SELECT log_user_id, log_action, log_description FROM logs WHERE log_type = 'Purchase Order' AND log_entity_id = $po_id AND log_user_id > 0");
    while ($row = mysqli_fetch_assoc($result)) {
        $text = (string) $row['log_description'];
        if (stripos($text, 'tracking') !== false || stripos($text, 'asset') !== false) {
            continue;
        }
        if ($row['log_action'] === 'Create'
            || preg_match('/ (created |added \d|changed |took .+ off|sent .+ for approval|sent it for approval|submitted it)/', $text)) {
            $ids[intval($row['log_user_id'])] = true;
        }
    }
    unset($ids[0]);
    return array_keys($ids);
}

/** Is this PO the signed-in agent's own - did they make or shape it? */
function mitPoIsOwn(array $po) {
    global $session_user_id;
    return in_array(intval($session_user_id), mitPoShapers($po), true);
}

/**
 * Hook in ITFlow's poMayApprove (functions/purchase_orders.php), after its level and status checks: null = let ITFlow
 * decide, else the answer. Not an admin and the PO is theirs (made or shaped - mitPoShapers): only when nobody else can
 * approve it, so a PO is never stuck.
 */
function mitPoMayApproveOwn(array $po) {
    global $session_user_id, $session_is_admin;
    if (!empty($session_is_admin) || !mitProcReady() || !mitPoIsOwn($po)) {
        return null;
    }
    return !array_diff(array_keys(poApprovers($po)), [intval($session_user_id)]);
}

/** The approve window's hidden field: the total the approver saw (one line in agent/modals/purchasing/po_decide.php). */
function mitPoSeenTotalField(array $po) {
    return '<input type="hidden" name="mit_seen_total" value="' . escapeHtml(number_format((float) $po['po_total'], 2, '.', '')) . '">';
}

/**
 * Hook at the start of ITFlow's poDecide approve: '' = fine, else why it is refused - the PO's total changed since the
 * approver saw it (the window posts mit_seen_total; a caller that posts none is not checked).
 */
function mitPoSeenTotalProblem(array $po) {
    if (!isset($_POST['mit_seen_total'])) {
        return '';
    }
    $seen = round((float) str_replace(',', '', (string) $_POST['mit_seen_total']), 2);
    $now = round((float) (poRow($po['po_id'])['po_total'] ?? $po['po_total']), 2);
    if (abs($seen - $now) < 0.005) {
        return '';
    }
    return mitPoReference($po) . ' changed since you opened it - it is ' . mitMoney($now) . ' now, not ' . mitMoney($seen) . '. Look again before approving.';
}

/**
 * Hook in ITFlow's poTell: our switches for the approvers' notices (Admin > Settings > Notifications - po_app / po_email),
 * which are poTell's calls with $email on. Returns [bell, email].
 */
function mitPoTellSwitches($email) {
    if (!$email || !function_exists('mitNotifyOn')) {
        return [true, $email];
    }
    return [mitNotifyOn('po_app'), mitNotifyOn('po_email')];
}

require_once __DIR__ . '/mit_procurement_parts.php';
require_once __DIR__ . '/mit_procurement_extras.php';
require_once __DIR__ . '/mit_to_bill.php';
