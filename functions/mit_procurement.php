<?php

/*
 * Mountaineer IT - procurement: items waiting to be ordered, purchase orders (with approval over a
 * set amount), tracking and receiving. Database update M009; pages agent/mit_procurement.php (to
 * order), agent/mit_purchase_orders.php, agent/mit_purchase_order.php, agent/mit_receiving.php;
 * handlers agent/post/mit_procurement.php; settings card admin/includes/mit_procurement_card.php.
 *
 * Permission: the "module_procurement" module (Admin > Roles) - View (1) sees it all, Edit (2) adds
 * items, makes POs, adds tracking and receives, Full (3) approves and cancels. Admins are Full.
 *
 * A PO goes draft -> pending (awaiting approval, when its total is over the approval amount) ->
 * approved -> ordered -> partial -> received; or cancelled, which puts its items back on the list.
 */

const MIT_PO_STATUSES = [
    'draft' => ['Draft', 'secondary'],
    'pending' => ['Awaiting approval', 'warning'],
    'approved' => ['Approved', 'info'],
    'ordered' => ['Ordered', 'primary'],
    'partial' => ['Partly received', 'warning'],
    'received' => ['Received', 'success'],
    'cancelled' => ['Cancelled', 'dark'],
];
const MIT_PO_OPEN_STATUSES = ['draft', 'pending', 'approved', 'ordered', 'partial'];

// Carriers and where their tracking pages are ({n} = the tracking number)
const MIT_CARRIERS = [
    'ups' => ['UPS', 'https://www.ups.com/track?tracknum={n}'],
    'fedex' => ['FedEx', 'https://www.fedex.com/fedextrack/?trknbr={n}'],
    'usps' => ['USPS', 'https://tools.usps.com/go/TrackConfirmAction?tLabels={n}'],
    'dhl' => ['DHL', 'https://www.dhl.com/us-en/home/tracking/tracking-express.html?tracking-id={n}'],
    'amazon' => ['Amazon', ''],
    'other' => ['Other', ''],
];

/** Are the procurement tables there yet? */
function mitProcReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_purchase_orders'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Is procurement in use: database update M009 run, and switched on (Admin > Settings > Modules, with invoicing on)? */
function mitProcOn() {
    return mitProcReady() && mitModuleOn('procurement');
}

/** The signed-in agent's procurement level: 0 none (or switched off), 1 view, 2 edit, 3 full (approves). */
function mitProcLevel() {
    // Asked once per row on some pages (ticket parts, invoice lines) - the same answer all request
    static $level = null;
    if ($level === null) {
        $level = mitProcOn() ? intval(lookupUserPermission('module_procurement')) : 0;
    }
    return $level;
}

/** The PO settings: prefix, next (the lowest next number), approval_over (float, 0 = every PO), ship_to (our address, text). */
function mitProcSettings() {
    static $settings = null;
    if ($settings === null) {
        $settings = [
            'prefix' => (string) mitSetting('procurement_po_prefix', 'PO-'),
            'next' => max(1, intval(mitSetting('procurement_po_next', '1'))),
            'approval_over' => max(0, (float) mitSetting('procurement_approval_over', '500')),
            // Approval on at all (1/0) - who approves comes from roles (Full Procurement access) and admins
            'approval_on' => mitSetting('procurement_approval_on', '1') !== '0',
            'ship_to' => (string) mitSetting('procurement_ship_to', mitProcCompanyAddress()),
        ];
    }
    return $settings;
}

function mitProcCompanyAddress() {
    global $mysqli;
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_name, company_address, company_city, company_state, company_zip FROM companies WHERE company_id = 1"));
    if (!$row) {
        return '';
    }
    return trim($row['company_name'] . "\n" . $row['company_address'] . "\n" . trim($row['company_city'] . ', ' . $row['company_state'] . ' ' . $row['company_zip'], ', '));
}

function mitPoBadge($status) {
    [$label, $color] = MIT_PO_STATUSES[$status] ?? [ucfirst((string) $status), 'secondary'];
    return '<span class="badge rounded-pill text-bg-' . $color . '">' . escapeHtml($label) . '</span>';
}

function mitPoReference(array $po) {
    return $po['po_prefix'] . $po['po_number'];
}

function mitTrackingUrl($carrier, $number) {
    $pattern = MIT_CARRIERS[$carrier][1] ?? '';
    return $pattern === '' ? '' : str_replace('{n}', rawurlencode(trim((string) $number)), $pattern);
}

/** A posted money / quantity value ("1,200", "$40"), rounded to cents, or null when blank or not a number. */
function mitProcNumber($value) {
    return mitPostedNumber($value); // functions/mit_quotes.php - the same reading
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

/*
 * ---------- reading ----------
 */

/** A PO with its vendor, or null. */
function mitPoRow($po_id) {
    global $mysqli;
    $po_id = intval($po_id);
    if (!$po_id || !mitProcReady()) {
        return null;
    }
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT mit_purchase_orders.po_id, mit_purchase_orders.po_prefix, mit_purchase_orders.po_number, mit_purchase_orders.vendor_id, mit_purchase_orders.status, mit_purchase_orders.order_date, mit_purchase_orders.vendor_order_number, mit_purchase_orders.ship_to, mit_purchase_orders.ship_to_address, mit_purchase_orders.note, mit_purchase_orders.shipping, mit_purchase_orders.total, mit_purchase_orders.created_by, mit_purchase_orders.submitted_at, mit_purchase_orders.approved_by, mit_purchase_orders.approved_at, mit_purchase_orders.approval_note, mit_purchase_orders.ordered_by, mit_purchase_orders.ordered_at, mit_purchase_orders.received_at, mit_purchase_orders.cancelled_at, mit_purchase_orders.expense_id, mit_purchase_orders.created_at, mit_purchase_orders.updated_at, vendor_name, vendor_email, vendor_contact_name, vendor_contact_email, vendor_phone, vendor_account_number,
            creator.user_name AS created_by_name, approver.user_name AS approved_by_name
        FROM mit_purchase_orders
        LEFT JOIN vendors ON vendors.vendor_id = mit_purchase_orders.vendor_id
        LEFT JOIN users creator ON creator.user_id = mit_purchase_orders.created_by
        LEFT JOIN users approver ON approver.user_id = mit_purchase_orders.approved_by
        WHERE po_id = $po_id")) ?: null;
}

/** A PO's lines, with who each is for. */
function mitPoLines($po_id) {
    global $mysqli;
    $po_id = intval($po_id);
    $result = mysqli_query($mysqli, "SELECT mit_po_lines.line_id, mit_po_lines.po_id, mit_po_lines.proc_item_id, mit_po_lines.client_id, mit_po_lines.project_id, mit_po_lines.name, mit_po_lines.description, mit_po_lines.quantity, mit_po_lines.unit_cost, mit_po_lines.received_qty, mit_po_lines.sort, client_name, project_prefix, project_number, project_name,
            proc.invoice_id, proc.ticket_id, invoice_prefix, invoice_number
        FROM mit_po_lines
        LEFT JOIN clients ON clients.client_id = mit_po_lines.client_id
        LEFT JOIN projects ON projects.project_id = mit_po_lines.project_id
        LEFT JOIN mit_procurement_items proc ON proc.item_id = mit_po_lines.proc_item_id
        LEFT JOIN invoices ON invoices.invoice_id = proc.invoice_id
        WHERE po_id = $po_id ORDER BY sort, line_id");
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

/** SQL limiting POs ($po_column) to those whose every client the agent can see ('' when unrestricted). */
function mitPoScopeSql($po_column) {
    $scope = clientScopeSql('l.client_id');
    return $scope === '' ? '' : " AND $po_column NOT IN (SELECT l.po_id FROM mit_po_lines l WHERE l.client_id > 0 AND NOT (1 = 1 $scope))";
}

/** Can the signed-in agent see every client on this PO? */
function mitPoAccessible($po_id) {
    global $mysqli;
    $result = mysqli_query($mysqli, "SELECT DISTINCT client_id FROM mit_po_lines WHERE po_id = " . intval($po_id) . " AND client_id > 0");
    while ($row = mysqli_fetch_assoc($result)) {
        if (!hasClientAccess($row['client_id'])) {
            return false;
        }
    }
    return true;
}

/** The PO for a request, after the permission and client checks - or the request stops. */
function mitPoForRequest($po_id, $level) {
    enforceUserPermission('module_procurement', $level);
    $po = mitPoRow($po_id);
    if (!$po || !mitPoAccessible($po['po_id'])) {
        flashAlert('That purchase order does not exist, or it is for a client you cannot see.', 'error');
        redirect('mit_purchase_orders.php');
    }
    return $po;
}

/** What "for" a line or item is, as HTML links: client · project · invoice / ticket. */
function mitProcForHtml(array $row) {
    $parts = [];
    if (intval($row['client_id'] ?? 0)) {
        $parts[] = '<a href="client_overview.php?client_id=' . intval($row['client_id']) . '">' . escapeHtml($row['client_name'] ?? 'Client') . '</a>';
    }
    if (intval($row['project_id'] ?? 0) && !empty($row['project_name'])) {
        $parts[] = '<a href="project.php?project_id=' . intval($row['project_id']) . '" title="' . escapeHtml($row['project_name']) . '">' . escapeHtml($row['project_prefix'] . $row['project_number']) . '</a>';
    }
    if (intval($row['invoice_id'] ?? 0) && !empty($row['invoice_number']) && lookupUserPermission('module_sales') >= 1) {
        $parts[] = '<a href="invoice.php?invoice_id=' . intval($row['invoice_id']) . '">' . escapeHtml($row['invoice_prefix'] . $row['invoice_number']) . '</a>';
    }
    if (intval($row['ticket_id'] ?? 0)) {
        $parts[] = '<a href="ticket.php?ticket_id=' . intval($row['ticket_id']) . '">Ticket</a>';
    }
    return $parts ? implode(' · ', $parts) : '<span class="text-muted">Our stock</span>';
}

/*
 * ---------- items to order ----------
 */

/** Add an item to the list. $item: name, quantity, unit_cost, vendor_id, client_id, project_id, product_id, note, source, invoice_id, invoice_item_id, ticket_id */
function mitProcAddItem(array $item) {
    global $mysqli, $session_user_id;
    $name = trim((string) ($item['name'] ?? ''));
    $qty = (float) ($item['quantity'] ?? 0);
    if ($name === '' || $qty <= 0) {
        return 0;
    }
    $cost = isset($item['unit_cost']) && $item['unit_cost'] !== '' && $item['unit_cost'] !== null ? round((float) $item['unit_cost'], 2) : 'NULL';
    $source = in_array($item['source'] ?? '', ['manual', 'quote', 'invoice', 'ticket'], true) ? $item['source'] : 'manual';
    mysqli_query($mysqli, "INSERT INTO mit_procurement_items SET source = '$source',
        client_id = " . intval($item['client_id'] ?? 0) . ", project_id = " . intval($item['project_id'] ?? 0) . ",
        invoice_id = " . intval($item['invoice_id'] ?? 0) . ", invoice_item_id = " . intval($item['invoice_item_id'] ?? 0) . ",
        ticket_id = " . intval($item['ticket_id'] ?? 0) . ", product_id = " . intval($item['product_id'] ?? 0) . ",
        vendor_id = " . intval($item['vendor_id'] ?? 0) . ", name = '" . escapeSql(mb_substr($name, 0, 255)) . "',
        description = " . (trim((string) ($item['description'] ?? '')) === '' ? 'NULL' : "'" . escapeSql($item['description']) . "'") . ",
        quantity = " . round($qty, 2) . ", unit_cost = $cost,
        note = " . (trim((string) ($item['note'] ?? '')) === '' ? 'NULL' : "'" . escapeSql(mb_substr($item['note'], 0, 255)) . "'") . ",
        created_by = " . intval($session_user_id));
    return mysqli_insert_id($mysqli);
}

/** Items waiting to be ordered that the agent can see, with who they are for. */
function mitProcWaitingItems($vendor_id = 0, $client_id = 0) {
    global $mysqli;
    $where = "proc.status = 'waiting'" . clientScopeSql('proc.client_id');
    if ($vendor_id > 0) {
        $where .= " AND proc.vendor_id = " . intval($vendor_id);
    } elseif ($vendor_id === -1) {
        $where .= " AND proc.vendor_id = 0";
    }
    if ($client_id > 0) {
        $where .= " AND proc.client_id = " . intval($client_id);
    } elseif ($client_id === -1) {
        $where .= " AND proc.client_id = 0";
    }
    $result = mysqli_query($mysqli, "SELECT proc.item_id, proc.source, proc.client_id, proc.project_id, proc.invoice_id, proc.invoice_item_id, proc.ticket_id, proc.product_id, proc.vendor_id, proc.name, proc.description, proc.quantity, proc.unit_cost, proc.status, proc.note, proc.created_by, proc.created_at, proc.updated_at, client_name, vendor_name, project_prefix, project_number, project_name, invoice_prefix, invoice_number
        FROM mit_procurement_items proc
        LEFT JOIN clients ON clients.client_id = proc.client_id
        LEFT JOIN vendors ON vendors.vendor_id = proc.vendor_id
        LEFT JOIN projects ON projects.project_id = proc.project_id
        LEFT JOIN invoices ON invoices.invoice_id = proc.invoice_id
        WHERE $where
        ORDER BY proc.client_id = 0, client_name, proc.invoice_id, proc.item_id");
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

/** Waiting items by id that the agent can see. */
function mitProcItemsByIds(array $ids) {
    global $mysqli;
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $result = mysqli_query($mysqli, "SELECT proc.item_id, proc.source, proc.client_id, proc.project_id, proc.invoice_id, proc.invoice_item_id, proc.ticket_id, proc.product_id, proc.vendor_id, proc.name, proc.description, proc.quantity, proc.unit_cost, proc.status, proc.note, proc.created_by, proc.created_at, proc.updated_at FROM mit_procurement_items proc WHERE item_id IN (" . implode(',', $ids) . ") AND status = 'waiting'" . clientScopeSql('proc.client_id'));
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

/*
 * ---------- purchase orders ----------
 */

function mitPoLog($po_id, $description) {
    global $mysqli, $session_user_id;
    mysqli_query($mysqli, "INSERT INTO mit_po_history SET po_id = " . intval($po_id) . ", user_id = " . intval($session_user_id) . ", description = '" . escapeSql(mb_substr($description, 0, 500)) . "'");
}

/** The next PO number: the setting, or past the highest used. */
function mitPoNextNumber() {
    global $mysqli;
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(po_number), 0) + 1 FROM mit_purchase_orders"));
    return max(intval($row[0]), mitProcSettings()['next']);
}

/** Who the signed-in agent is, unescaped (the session keeps it SQL-escaped). */
function mitProcWho() {
    global $session_name;
    return stripslashes((string) $session_name);
}

/**
 * A draft PO from waiting items, all from $vendor_id. Returns the new po_id, or 0. Under one lock with mitPoAddItems, so
 * two buyers at once can't put the same waiting items on two POs (the items are read again inside it).
 */
function mitPoCreate(array $item_ids, $vendor_id) {
    if (!mitLock('proc_order')) {
        return 0;
    }
    try {
        return mitPoCreateNow($item_ids, $vendor_id);
    } finally {
        mitUnlock('proc_order');
    }
}

function mitPoCreateNow(array $item_ids, $vendor_id) {
    global $mysqli, $session_user_id;
    $items = mitProcItemsByIds($item_ids);
    $vendor_id = intval($vendor_id);
    if (!$items || !$vendor_id) {
        return 0;
    }
    $settings = mitProcSettings();
    // Ships to the maker's office (functions/mit_offices.php) - the main one is the Ship to setting
    $ship_office = function_exists('mitOfficeIdForUser') ? mitOfficeIdForUser($session_user_id) : 0;
    $ship_to = $ship_office ? 'office:' . $ship_office : 'office';
    $ship_to_address = $ship_office ? mitOfficeShipTo($ship_office) : $settings['ship_to'];
    $clients = array_unique(array_filter(array_map('intval', array_column($items, 'client_id'))));
    $projects = array_unique(array_filter(array_map('intval', array_column($items, 'project_id'))));
    // The number is unique (database update M015): two POs made at the same moment - the second takes the next one
    $po_id = 0;
    for ($attempt = 0; $attempt < 5 && !$po_id; $attempt++) {
        $number = mitPoNextNumber();
        try {
            mysqli_query($mysqli, "INSERT INTO mit_purchase_orders SET po_prefix = '" . escapeSql($settings['prefix']) . "', po_number = $number,
                vendor_id = $vendor_id, status = 'draft', ship_to = '$ship_to', ship_to_address = '" . escapeSql($ship_to_address) . "',
                created_by = " . intval($session_user_id));
            $po_id = mysqli_insert_id($mysqli);
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() !== 1062) {  // anything but "duplicate number" is a real failure
                throw $e;
            }
        }
    }
    if (!$po_id) {
        return 0;
    }
    mitSetSetting('procurement_po_next', $number + 1);
    $sort = 0;
    foreach ($items as $item) {
        mysqli_query($mysqli, "INSERT INTO mit_po_lines SET po_id = $po_id, proc_item_id = " . intval($item['item_id']) . ",
            client_id = " . intval($item['client_id']) . ", project_id = " . intval($item['project_id']) . ",
            name = '" . escapeSql($item['name']) . "', description = " . ($item['description'] === null ? 'NULL' : "'" . escapeSql($item['description']) . "'") . ",
            quantity = " . (float) $item['quantity'] . ", unit_cost = " . (float) $item['unit_cost'] . ", sort = " . ($sort++));
        mysqli_query($mysqli, "UPDATE mit_procurement_items SET status = 'ordered', vendor_id = $vendor_id WHERE item_id = " . intval($item['item_id']));
    }
    mitPoRecalc($po_id);
    mitPoLog($po_id, mitProcWho() . ' created the PO with ' . count($items) . ' item' . (count($items) === 1 ? '' : 's'));
    logAudit('Purchase Order', 'Create', escapeSql(mitProcWho() . ' created purchase order ' . $settings['prefix'] . $number), count($clients) === 1 ? reset($clients) : 0, $po_id);
    return $po_id;
}

/** Add waiting items to a draft PO (under the same lock as mitPoCreate). */
function mitPoAddItems($po_id, array $item_ids) {
    if (!mitLock('proc_order')) {
        return 0;
    }
    try {
        return mitPoAddItemsNow($po_id, $item_ids);
    } finally {
        mitUnlock('proc_order');
    }
}

function mitPoAddItemsNow($po_id, array $item_ids) {
    global $mysqli;
    $po = mitPoRow($po_id);
    if (!$po || $po['status'] !== 'draft') {
        return 0;
    }
    $added = 0;
    $sort = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(sort), 0) + 1 FROM mit_po_lines WHERE po_id = " . intval($po_id)))[0]);
    foreach (mitProcItemsByIds($item_ids) as $item) {
        mysqli_query($mysqli, "INSERT INTO mit_po_lines SET po_id = " . intval($po_id) . ", proc_item_id = " . intval($item['item_id']) . ",
            client_id = " . intval($item['client_id']) . ", project_id = " . intval($item['project_id']) . ",
            name = '" . escapeSql($item['name']) . "', description = " . ($item['description'] === null ? 'NULL' : "'" . escapeSql($item['description']) . "'") . ",
            quantity = " . (float) $item['quantity'] . ", unit_cost = " . (float) $item['unit_cost'] . ", sort = " . ($sort++));
        mysqli_query($mysqli, "UPDATE mit_procurement_items SET status = 'ordered', vendor_id = " . intval($po['vendor_id']) . " WHERE item_id = " . intval($item['item_id']));
        $added++;
    }
    if ($added) {
        mitPoRecalc($po_id);
        mitPoLog($po_id, mitProcWho() . " added $added item" . ($added === 1 ? '' : 's'));
    }
    return $added;
}

/**
 * Totals, and - once ordered - the status from what has arrived. Items whose line is all in are
 * marked received.
 */
function mitPoRecalc($po_id) {
    global $mysqli;
    $po_id = intval($po_id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COALESCE(SUM(quantity * unit_cost), 0) AS lines_total, COALESCE(SUM(quantity), 0) AS qty,
            COALESCE(SUM(LEAST(received_qty, quantity)), 0) AS received FROM mit_po_lines WHERE po_id = $po_id"));
    mysqli_query($mysqli, "UPDATE mit_purchase_orders SET total = ROUND(" . (float) $row['lines_total'] . " + shipping, 2) WHERE po_id = $po_id");
    $po = mitPoRow($po_id);
    if ($po && in_array($po['status'], ['ordered', 'partial', 'received'], true)) {
        $status = (float) $row['received'] <= 0 ? 'ordered' : ((float) $row['received'] + 0.001 >= (float) $row['qty'] ? 'received' : 'partial');
        if ($status !== $po['status']) {
            mysqli_query($mysqli, "UPDATE mit_purchase_orders SET status = '$status', received_at = " . ($status === 'received' ? 'NOW()' : 'NULL') . " WHERE po_id = $po_id");
        }
        mysqli_query($mysqli, "UPDATE mit_procurement_items proc JOIN mit_po_lines l ON l.proc_item_id = proc.item_id
            SET proc.status = IF(l.received_qty + 0.001 >= l.quantity, 'received', 'ordered') WHERE l.po_id = $po_id AND proc.status IN ('ordered', 'received')");
    }
}

/** Does a PO of this total need approving? (Never, with approval switched off.) */
function mitPoNeedsApproval($total) {
    $settings = mitProcSettings();
    if (!$settings['approval_on']) {
        return false;
    }
    $over = $settings['approval_over'];
    return $over <= 0 || (float) $total > $over;
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

/**
 * Who shaped this PO - made it, added items, changed quantities or costs, or sent it for approval: user ids, from its
 * history. Approving one of these is approving their own PO, whoever created the draft.
 */
function mitPoShapers(array $po) {
    global $mysqli;
    $ids = [intval($po['created_by']) => true];
    $result = mysqli_query($mysqli, "SELECT user_id, description FROM mit_po_history WHERE po_id = " . intval($po['po_id']));
    while ($row = mysqli_fetch_assoc($result)) {
        if (preg_match('/ (created the PO|added \d+ items?$|sent it for approval|submitted it|changed the quantities and costs|took .+ off the PO)/', $row['description'])) {
            $ids[intval($row['user_id'])] = true;
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
 * May the signed-in agent approve this PO? Full Procurement access (admins always have it), and not their own PO (made or
 * shaped by them) - unless they are an admin (the history says self-approved) or nobody else can approve it, so a PO is never stuck.
 */
function mitPoMayApprove(array $po) {
    global $session_user_id, $session_is_admin;
    if (mitProcLevel() < 3) {
        return false;
    }
    if (!empty($session_is_admin) || !mitPoIsOwn($po)) {
        return true;
    }
    return !array_diff(array_keys(mitPoApproversFor($po)), [intval($session_user_id)]);
}

/** Who else can approve this PO, as "Ann, Bob" - for the "waiting for approval" note. */
function mitPoApproverNames(array $po) {
    $names = [];
    $shapers = mitPoShapers($po);
    foreach (mitPoApproversFor($po) as $user_id => $approver) {
        if (!in_array($user_id, $shapers, true)) {
            $names[] = $approver['user_name'];
        }
    }
    return implode(', ', $names);
}

/** Agents who can approve POs: Full on Procurement, or admins. user_id => row */
function mitPoApprovers() {
    global $mysqli;
    static $approvers = null;
    if ($approvers !== null) {
        return $approvers;
    }
    $result = mysqli_query($mysqli, "SELECT users.user_id, user_name, user_email FROM users
        JOIN user_roles ON role_id = users.user_role_id
        LEFT JOIN user_role_permissions urp ON urp.user_role_id = role_id
            AND urp.module_id = (SELECT module_id FROM modules WHERE module_name = 'module_procurement' LIMIT 1)
        WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
        AND (role_is_admin = 1 OR urp.user_role_permission_level >= 3)");
    $approvers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $approvers[intval($row['user_id'])] = $row;
    }
    return $approvers;
}

/** The approvers who can open this PO: those of mitPoApprovers() with access to every client on its lines. user_id => row */
function mitPoApproversFor(array $po) {
    global $mysqli;
    $clients = array_filter(array_map('intval', array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT DISTINCT client_id FROM mit_po_lines
        WHERE po_id = " . intval($po['po_id'])), MYSQLI_NUM), 0)));
    return array_filter(mitPoApprovers(), function ($user_id) use ($clients) {
        foreach ($clients as $client_id) {
            if (function_exists('mitAgentCanSeeClient') && !mitAgentCanSeeClient($user_id, $client_id)) {
                return false;
            }
        }
        return true;
    }, ARRAY_FILTER_USE_KEY);
}

/** Tell the approvers (app notification and email) that a PO is waiting for them. */
function mitPoNotifyApprovers(array $po) {
    global $mysqli, $session_user_id, $config_base_url, $config_smtp_provider, $config_ticket_from_email, $config_ticket_from_name;
    $reference = mitPoReference($po);
    $text = "Purchase order $reference (" . ($po['vendor_name'] ?? 'vendor') . ', ' . mitMoney($po['total']) . ') is waiting for your approval - from ' . mitProcWho();
    $mails = [];
    foreach (mitPoApproversFor($po) as $user_id => $approver) {
        if ($user_id === intval($session_user_id)) {
            continue;
        }
        if (mitNotifyOn('po_app')) {
            mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Purchase Order', notification = '" . escapeSql($text) . "',
                notification_action = '/agent/mit_purchase_order.php?po_id=" . intval($po['po_id']) . "', notification_user_id = $user_id, notification_entity_id = " . intval($po['po_id']));
        }
        if (!empty($config_smtp_provider) && mitNotifyOn('po_email') && filter_var($approver['user_email'], FILTER_VALIDATE_EMAIL)) {
            $link = "https://$config_base_url/agent/mit_purchase_order.php?po_id=" . intval($po['po_id']);
            $mails[] = ['from' => escapeSql($config_ticket_from_email), 'from_name' => escapeSql($config_ticket_from_name),
                'recipient' => escapeSql($approver['user_email']), 'recipient_name' => escapeSql($approver['user_name']),
                'subject' => escapeSql("PO $reference needs your approval"),
                'body' => mitMailBody('Hello ' . escapeHtml($approver['user_name']) . ',<br><br>' . escapeHtml($text) . '.<br><br><a href="' . escapeHtml($link) . '">Open ' . escapeHtml($reference) . ' in ITFlow</a>')];
        }
    }
    if ($mails) {
        addToMailQueue($mails);
    }
}

/**
 * Submit a draft: straight to approved when it is under the approval amount and every line has a cost, else to the
 * approvers (a line with no cost would make a PO look free and skip approval). Returns the new status.
 */
function mitPoSubmit(array $po) {
    global $mysqli, $session_user_id;
    $po_id = intval($po['po_id']);
    mitPoRecalc($po_id);
    $po = mitPoRow($po_id);
    $uncosted = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_po_lines WHERE po_id = $po_id AND unit_cost <= 0"))[0]);
    if (mitPoNeedsApproval($po['total']) || ($uncosted && mitProcSettings()['approval_on'])) {
        mysqli_query($mysqli, "UPDATE mit_purchase_orders SET status = 'pending', submitted_at = NOW(), approved_by = 0, approved_at = NULL, approval_note = NULL WHERE po_id = $po_id");
        mitPoLog($po_id, mitProcWho() . ' sent it for approval (' . mitMoney($po['total']) . ($uncosted ? ", $uncosted line(s) with no cost" : '') . ')');
        mitPoNotifyApprovers($po);
        return 'pending';
    }
    $why = mitProcSettings()['approval_on'] ? 'Under the approval amount' : 'Approval is switched off';
    mysqli_query($mysqli, "UPDATE mit_purchase_orders SET status = 'approved', submitted_at = NOW(), approved_by = " . intval($session_user_id) . ", approved_at = NOW(),
        approval_note = '$why' WHERE po_id = $po_id");
    mitPoLog($po_id, mitProcWho() . ' submitted it - ' . strtolower($why) . ', so it is approved');
    return 'approved';
}

/** An approver's decision on a pending PO; the creator is told. */
function mitPoDecide(array $po, $approve, $note) {
    global $mysqli, $session_user_id;
    $po_id = intval($po['po_id']);
    $note = trim((string) $note);
    $note_sql = $note === '' ? 'NULL' : "'" . escapeSql(mb_substr($note, 0, 255)) . "'";
    if ($approve) {
        mysqli_query($mysqli, "UPDATE mit_purchase_orders SET status = 'approved', approved_by = " . intval($session_user_id) . ", approved_at = NOW(), approval_note = $note_sql WHERE po_id = $po_id");
        $own = mitPoIsOwn($po);
        mitPoLog($po_id, mitProcWho() . ($own ? ' approved their own PO (self-approved)' : ' approved it') . ($note !== '' ? " - $note" : ''));
    } else {
        mysqli_query($mysqli, "UPDATE mit_purchase_orders SET status = 'draft', approved_by = 0, approved_at = NULL, approval_note = $note_sql WHERE po_id = $po_id");
        mitPoLog($po_id, mitProcWho() . ' sent it back to draft' . ($note !== '' ? " - $note" : ''));
    }
    if (intval($po['created_by']) && intval($po['created_by']) !== intval($session_user_id)) {
        $text = 'Purchase order ' . mitPoReference($po) . ($approve ? ' was approved' : ' was sent back') . ' by ' . mitProcWho() . ($note !== '' ? ": $note" : '');
        mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Purchase Order', notification = '" . escapeSql($text) . "',
            notification_action = '/agent/mit_purchase_order.php?po_id=$po_id', notification_user_id = " . intval($po['created_by']) . ", notification_entity_id = $po_id");
    }
    logAudit('Purchase Order', 'Edit', escapeSql(mitProcWho() . ($approve ? ' approved ' : ' rejected ') . mitPoReference($po)), 0, $po_id);
}

/** Mark an approved PO as ordered with the vendor. */
function mitPoMarkOrdered(array $po, $order_date, $vendor_order_number) {
    global $mysqli, $session_user_id;
    $po_id = intval($po['po_id']);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $order_date) ? $order_date : date('Y-m-d');
    $vendor_order_number = trim((string) $vendor_order_number);
    mysqli_query($mysqli, "UPDATE mit_purchase_orders SET status = 'ordered', order_date = '$date', ordered_by = " . intval($session_user_id) . ", ordered_at = NOW()"
        . ($vendor_order_number !== '' ? ", vendor_order_number = '" . escapeSql(mb_substr($vendor_order_number, 0, 100)) . "'" : '') . " WHERE po_id = $po_id");
    mitPoLog($po_id, mitProcWho() . ' marked it ordered' . ($vendor_order_number !== '' ? " - vendor order # $vendor_order_number" : ''));
    logAudit('Purchase Order', 'Edit', escapeSql(mitProcWho() . ' ordered ' . mitPoReference($po)), 0, $po_id);
}

/** Cancel a PO (nothing received yet): its items go back on the list to order. */
function mitPoCancel(array $po) {
    global $mysqli;
    $po_id = intval($po['po_id']);
    mysqli_query($mysqli, "UPDATE mit_procurement_items proc JOIN mit_po_lines l ON l.proc_item_id = proc.item_id SET proc.status = 'waiting' WHERE l.po_id = $po_id");
    mysqli_query($mysqli, "UPDATE mit_purchase_orders SET status = 'cancelled', cancelled_at = NOW() WHERE po_id = $po_id");
    mitPoLog($po_id, mitProcWho() . ' cancelled it - its items are back on the list to order');
    logAudit('Purchase Order', 'Edit', escapeSql(mitProcWho() . ' cancelled ' . mitPoReference($po)), 0, $po_id);
}

/** Take a line off a draft PO; its item goes back on the list. */
function mitPoRemoveLine(array $po, $line_id) {
    global $mysqli;
    $po_id = intval($po['po_id']);
    $line = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT line_id, po_id, proc_item_id, client_id, project_id, name, description, quantity, unit_cost, received_qty, sort FROM mit_po_lines WHERE line_id = " . intval($line_id) . " AND po_id = $po_id"));
    if (!$line) {
        return false;
    }
    mysqli_query($mysqli, "UPDATE mit_procurement_items SET status = 'waiting' WHERE item_id = " . intval($line['proc_item_id']));
    mysqli_query($mysqli, "DELETE FROM mit_po_lines WHERE line_id = " . intval($line_id));
    mitPoRecalc($po_id);
    mitPoLog($po_id, mitProcWho() . ' took ' . $line['name'] . ' off the PO (back on the list to order)');
    return true;
}

/**
 * Record what arrived: $received = [line_id => ['qty' => n, 'serials' => text, 'asset_type' => ...]],
 * for any number of POs (the receiving page). $options: assets / stock / notes - what else to do
 * (functions/mit_procurement_extras.php). Returns [lines received, POs touched, extras done].
 */
function mitPoReceive(array $received, array $options = []) {
    global $mysqli, $session_user_id;
    $count = 0;
    $touched = [];
    $extras = ['assets' => 0, 'stock' => 0, 'notes' => 0, 'capped' => 0, 'asset_ids' => []];
    foreach ($received as $line_id => $in) {
        $qty = round((float) ($in['qty'] ?? 0), 2);
        if ($qty <= 0) {
            continue;
        }
        $line = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT l.line_id, l.po_id, l.proc_item_id, l.client_id, l.project_id, l.name, l.description, l.quantity, l.unit_cost, l.received_qty, l.sort, po.status, po.po_prefix, po.po_number FROM mit_po_lines l JOIN mit_purchase_orders po ON po.po_id = l.po_id WHERE l.line_id = " . intval($line_id)));
        if (!$line || !in_array($line['status'], ['ordered', 'partial'], true) || !mitPoAccessible($line['po_id'])) {
            continue;
        }
        $qty = min($qty, (float) $line['quantity'] - (float) $line['received_qty']);
        if ($qty <= 0) {
            continue;
        }
        $serials = trim(preg_replace('/\s*[\r\n,;]+\s*/', "\n", (string) ($in['serials'] ?? '')));
        mysqli_query($mysqli, "INSERT INTO mit_po_receipts SET po_id = " . intval($line['po_id']) . ", line_id = " . intval($line_id) . ", quantity = $qty,
            serials = " . ($serials === '' ? 'NULL' : "'" . escapeSql($serials) . "'") . ", received_by = " . intval($session_user_id));
        $receipt_id = mysqli_insert_id($mysqli);
        mysqli_query($mysqli, "UPDATE mit_po_lines SET received_qty = received_qty + $qty WHERE line_id = " . intval($line_id));
        foreach (mitProcAfterReceive($line, $qty, $serials, $receipt_id, $options, $in) as $key => $done) {
            $extras[$key] = is_array($done) ? array_merge($extras[$key], $done) : $extras[$key] + $done;
        }
        mitPoLog($line['po_id'], mitProcWho() . ' received ' . mitQty($qty) . ' × ' . $line['name'] . ($serials !== '' ? ' (serials: ' . str_replace("\n", ', ', $serials) . ')' : ''));
        $touched[intval($line['po_id'])] = true;
        $count++;
    }
    foreach (array_keys($touched) as $po_id) {
        mitPoRecalc($po_id);
    }
    return [$count, count($touched), $extras];
}

/** Lines still to arrive on ordered POs the agent can see, by PO. */
function mitPoAwaitingLines() {
    global $mysqli;
    $result = mysqli_query($mysqli, "SELECT l.line_id, l.po_id, l.proc_item_id, l.client_id, l.project_id, l.name, l.description, l.quantity, l.unit_cost, l.received_qty, l.sort, po.po_prefix, po.po_number, po.vendor_order_number, po.status, po.order_date, vendor_name,
            client_name, project_prefix, project_number, project_name
        FROM mit_po_lines l
        JOIN mit_purchase_orders po ON po.po_id = l.po_id
        LEFT JOIN vendors ON vendors.vendor_id = po.vendor_id
        LEFT JOIN clients ON clients.client_id = l.client_id
        LEFT JOIN projects ON projects.project_id = l.project_id
        WHERE po.status IN ('ordered', 'partial') AND l.received_qty < l.quantity" . mitPoScopeSql('po.po_id') . "
        ORDER BY po.order_date, po.po_id, l.sort, l.line_id");
    $by_po = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $by_po[intval($row['po_id'])][] = $row;
    }
    return $by_po;
}

/** The PO's tracking, newest first. */
function mitPoTracking($po_id) {
    global $mysqli;
    return mysqli_fetch_all(mysqli_query($mysqli, "SELECT tracking_id, po_id, carrier, tracking_number, note, eta, delivered, created_at FROM mit_po_tracking WHERE po_id = " . intval($po_id) . " ORDER BY tracking_id DESC"), MYSQLI_ASSOC);
}

/** The tracking numbers of several POs, po_id => [rows] (the receiving page). */
function mitPoTrackingFor(array $po_ids) {
    global $mysqli;
    $po_ids = array_values(array_filter(array_map('intval', $po_ids)));
    $by_po = [];
    if ($po_ids) {
        $result = mysqli_query($mysqli, "SELECT tracking_id, po_id, carrier, tracking_number, note, eta, delivered, created_at FROM mit_po_tracking WHERE po_id IN (" . implode(',', $po_ids) . ") ORDER BY tracking_id DESC");
        while ($row = mysqli_fetch_assoc($result)) {
            $by_po[intval($row['po_id'])][] = $row;
        }
    }
    return $by_po;
}

/** A tracking number as a link to the carrier's page (or plain text), escaped. */
function mitTrackingHtml(array $tracking) {
    $label = (MIT_CARRIERS[$tracking['carrier']][0] ?? 'Tracking') . ' ' . $tracking['tracking_number'];
    $url = mitTrackingUrl($tracking['carrier'], $tracking['tracking_number']);
    return $url !== '' ? '<a href="' . escapeHtml($url) . '" target="_blank" rel="noopener">' . escapeHtml($label) . ' <i class="fas fa-external-link-alt small"></i></a>' : escapeHtml($label);
}

/*
 * ---------- the PDF and emailing it ----------
 */

/** The PO as a PDF: 'I' shows it in the browser, a path saves it there. */
function mitPoPdf(array $po, $dest = 'I') {
    global $mysqli;
    $company = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_name, company_address, company_city, company_state, company_zip, company_phone, company_phone_country_code, company_email, company_logo FROM companies WHERE company_id = 1"));
    $lines = mitPoLines($po['po_id']);
    require_once __DIR__ . '/../libs/TCPDF/tcpdf.php';
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 10);
    $h = function ($value) {
        return escapeHtml((string) $value);
    };
    $logo = '';
    if (!empty($company['company_logo']) && is_file(__DIR__ . '/../uploads/settings/' . $company['company_logo'])) {
        // The file's own path, as ITFlow's PDFs now use (functions/billing_pdf.php) - a web-root path needed the web
        // server's document root, so it went missing when made from cron or with another document root
        $logo = '<img src="' . $h(realpath(__DIR__ . '/../uploads/settings/' . basename($company['company_logo']))) . '" width="110">';
    }
    $approved = in_array($po['status'], ['approved', 'ordered', 'partial', 'received'], true);
    $html = '<table width="100%" cellpadding="2"><tr><td width="50%">' . $logo . '<br><strong>' . $h($company['company_name']) . '</strong><br>'
        . $h($company['company_address']) . '<br>' . $h(trim($company['company_city'] . ', ' . $company['company_state'] . ' ' . $company['company_zip'], ', ')) . '<br>'
        . $h(formatPhoneNumber($company['company_phone'], $company['company_phone_country_code'])) . '<br>' . $h($company['company_email']) . '</td>'
        . '<td width="50%" align="right"><span style="font-size:18pt;font-weight:bold;">PURCHASE ORDER</span><br><span style="font-size:13pt;">' . $h(mitPoReference($po)) . '</span><br>'
        . ($approved ? '' : '<span style="color:#b02a37;font-weight:bold;">' . $h(strtoupper(MIT_PO_STATUSES[$po['status']][0] ?? 'Draft')) . ' - NOT YET APPROVED</span><br>')
        . 'Date: ' . $h($po['order_date'] ?: date('Y-m-d')) . '</td></tr></table><br>'
        . '<table width="100%" cellpadding="4"><tr><td width="50%" style="background-color:#f1f3f5;"><strong>Vendor</strong><br>' . $h($po['vendor_name'])
        . ($po['vendor_account_number'] ? '<br>Account: ' . $h($po['vendor_account_number']) : '') . ($po['vendor_order_number'] ? '<br>Your order #: ' . $h($po['vendor_order_number']) : '') . '</td>'
        . '<td width="50%" style="background-color:#f1f3f5;"><strong>Ship to</strong><br>' . nl2br($h($po['ship_to_address'])) . '</td></tr></table><br><br>'
        . '<table width="100%" cellpadding="5" border="0"><tr style="background-color:#e9ecef;font-weight:bold;"><td width="55%">Item</td><td width="12%" align="center">Qty</td><td width="16%" align="right">Unit cost</td><td width="17%" align="right">Amount</td></tr>';
    foreach ($lines as $line) {
        $html .= '<tr><td width="55%">' . $h($line['name']) . ($line['description'] ? '<br><span style="font-size:8.5pt;color:#555;">' . nl2br($h($line['description'])) . '</span>' : '') . '</td>'
            . '<td width="12%" align="center">' . $h(mitQty($line['quantity'])) . '</td><td width="16%" align="right">' . $h(mitMoney($line['unit_cost'])) . '</td>'
            . '<td width="17%" align="right">' . $h(mitMoney($line['quantity'] * $line['unit_cost'])) . '</td></tr>';
    }
    if ((float) $po['shipping'] > 0) {
        $html .= '<tr><td colspan="3" align="right">Shipping</td><td align="right">' . $h(mitMoney($po['shipping'])) . '</td></tr>';
    }
    $html .= '<tr><td colspan="3" align="right"><strong>Total</strong></td><td align="right"><strong>' . $h(mitMoney($po['total'])) . '</strong></td></tr></table>';
    if (trim((string) $po['note']) !== '') {
        $html .= '<br><br><strong>Notes</strong><br>' . nl2br($h($po['note']));
    }
    $html .= '<br><br><span style="font-size:8.5pt;color:#555;">Please quote ' . $h(mitPoReference($po)) . ' on your invoice and packing slip.</span>';
    $pdf->writeHTML($html, true, false, true, false, '');
    if ($dest === 'I') {
        $pdf->Output(mitPoReference($po) . '.pdf', 'I');
        exit;
    }
    $pdf->Output($dest, 'F');
    return is_file($dest);
}

/** Email the PO PDF to the vendor (or $to). Returns '' or what went wrong. */
function mitPoEmail(array $po, $to) {
    global $config_smtp_provider, $config_ticket_from_email, $config_ticket_from_name, $session_company_name;
    if (empty($config_smtp_provider)) {
        return 'Email is not set up (Admin > Settings > Mail).';
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'That is not an email address.';
    }
    $dir = __DIR__ . '/../uploads/mit_po';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return 'The PDF folder (uploads/mit_po) could not be made - check the folder permissions.';
    }
    // PDFs from earlier emails have long been sent (the mail queue retries for hours, not weeks) - clear them out,
    // so vendor pricing does not sit in uploads/ for good
    foreach (glob("$dir/*.pdf") ?: [] as $old_pdf) {
        if (filemtime($old_pdf) < time() - 30 * 86400) {
            @unlink($old_pdf);
        }
    }
    // An unguessable name - uploads are served as files
    $name = bin2hex(random_bytes(16)) . '.pdf';
    if (!mitPoPdf($po, "$dir/$name")) {
        return 'The PDF could not be made.';
    }
    $reference = mitPoReference($po);
    $company = (string) $session_company_name;
    $body = 'Hello,<br><br>Please find attached purchase order <strong>' . escapeHtml($reference) . '</strong> from ' . escapeHtml($company) . '.<br><br>'
        . 'Please quote ' . escapeHtml($reference) . ' on your invoice and packing slip, and reply with your order number and tracking when it ships.<br><br>Thank you,<br>'
        . escapeHtml(mitProcWho()) . '<br>' . escapeHtml($company);
    addToMailQueue([[
        'from' => escapeSql($config_ticket_from_email), 'from_name' => escapeSql($config_ticket_from_name),
        'recipient' => escapeSql($to), 'recipient_name' => escapeSql($po['vendor_name']),
        'subject' => escapeSql("Purchase order $reference - $company"), 'body' => mitMailBody($body),
        'attachments' => [['path' => "uploads/mit_po/$name", 'name' => "$reference.pdf"]],
    ]]);
    mitPoLog($po['po_id'], mitProcWho() . " emailed the PO to $to");
    return '';
}

require_once __DIR__ . '/mit_procurement_parts.php';
require_once __DIR__ . '/mit_procurement_extras.php';
require_once __DIR__ . '/mit_to_bill.php';
