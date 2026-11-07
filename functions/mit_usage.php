<?php

/*
 * Mountaineer IT - usage billing. A recurring invoice's line (Managed workstations, Bitwarden users, Microsoft 365 Business
 * Premium ...) can take its quantity from a count each month instead of staying fixed. The price stays on ITFlow's line.
 *
 * - Usage lines (mit_usage_lines, M032): which recurring line, where its count comes from - typed (manual), reported through
 *   the API (api) or the client's contacts with a tag (tag) - and the usage name (metric) its counts are kept under for the
 *   client (tag lines: "tag:<id>"). Set on the recurring invoice's Usage card.
 * - Counts (mit_usage_counts): every count with its date and source. The API (api/v1/mit_usage/create.php, ITFlow's API keys)
 *   and step 5's connectors report them; people type them on the client's Usage card; cron job mit_usage records the tag counts
 *   daily.
 * - Snapshot day (mit_settings usage_snapshot_day, default 1): when a recurring invoice is generated (nightly, or Force), each
 *   usage line's quantity becomes the last count on or before the latest snapshot day (mitUsageApply - one guarded line each in
 *   cron/nightly_tasks.php and agent/post/recurring_invoice.php force_recurring). The invoice then stays a Draft, whatever the
 *   recurring invoice's auto-send says; the recurring invoice itself is not changed.
 * - Review (mit_usage_applied, Billing > Usage Review): last month's quantity, this month's, the change, flags (big change over
 *   usage_change_pct %, a count older than usage_stale_days before the snapshot, no count), overrides with a reason. Approving
 *   is the QuickBooks approval (functions/mit_qbo_billing.php), so nothing skips it.
 * Permission: Sales view sees it, Sales edit sets lines, types counts and overrides.
 */

require_once __DIR__ . '/mit_usage_connectors.php';

const MIT_USAGE_SOURCES = [
    'manual' => 'Typed each month',
    'api' => 'Reported through the API',
    'tag' => 'Contacts with a tag',
];
// Where a count came from (mit_usage_counts.count_source): typed, the API, the daily tag count, or a connector
const MIT_USAGE_COUNT_SOURCES = ['manual', 'api', 'tag', 'ninjaone', 'bitwarden', 'ingram'];
const MIT_USAGE_FLAGS = [
    'missing' => ['No count', 'text-bg-danger', 'No count on or before the snapshot day - the recurring quantity was kept.'],
    'stale' => ['Old count', 'text-bg-warning', 'The count is older than the snapshot day allows.'],
    'big_change' => ['Big change', 'text-bg-warning', 'Changed more than the review threshold since last month.'],
    'minimum' => ['Minimum', 'text-bg-info', 'The count was below the line\'s minimum - the minimum was billed.'],
];

/** Has M032 run? (one query per request) */
function mitUsageReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_usage_applied'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Company settings, with defaults. */
function mitUsageSettings() {
    return [
        'snapshot_day' => max(1, min(28, intval(mitSetting('usage_snapshot_day', 1)))),
        'change_pct' => max(0, min(1000, intval(mitSetting('usage_change_pct', 20)))),
        'stale_days' => max(0, min(90, intval(mitSetting('usage_stale_days', 7)))),
    ];
}

/** Sales view (1) sees usage; Sales edit (2) sets lines, types counts and overrides. */
function mitUsageCan($level = 2) {
    return lookupUserPermission('module_sales') >= $level;
}

/** A usage name as kept: trimmed, at most 100 characters, letters, digits and . : _ - + / ( ) and spaces. '' if nothing is left. */
function mitUsageCleanMetric($metric) {
    $metric = trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N} .:_\-+\/()]/u', '', (string) $metric)));
    return mb_substr($metric, 0, 100);
}

/** A usage name made from a line's name ("Managed Workstations" -> "managed workstations"). */
function mitUsageMetricFromName($name) {
    return mitUsageCleanMetric(mb_strtolower((string) $name)) ?: 'usage';
}

/** A recurring invoice's usage lines: recurring item id => line. */
function mitUsageLines($recurring_invoice_id) {
    global $mysqli;
    $lines = [];
    if (!mitUsageReady()) {
        return $lines;
    }
    $result = mysqli_query($mysqli, "SELECT * FROM mit_usage_lines WHERE recurring_invoice_id = " . intval($recurring_invoice_id));
    while ($row = mysqli_fetch_assoc($result)) {
        $lines[intval($row['recurring_item_id'])] = $row;
    }
    return $lines;
}

/**
 * Set how a recurring line is billed: $source '' = fixed (the usage line goes), else manual / api / tag. Returns '' or the
 * problem.
 */
function mitUsageSaveLine($recurring_item_id, $source, $metric, $tag_id, $min_qty) {
    global $mysqli, $session_user_id;
    $recurring_item_id = intval($recurring_item_id);
    $item = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT item_id, item_name, item_recurring_invoice_id FROM recurring_invoice_items WHERE item_id = $recurring_item_id"));
    if (!$item) {
        return 'That line no longer exists.';
    }
    if ($source === '' || !isset(MIT_USAGE_SOURCES[$source])) {
        mysqli_query($mysqli, "DELETE FROM mit_usage_lines WHERE recurring_item_id = $recurring_item_id");
        return '';
    }
    // ITFlow 26.11 counts seats on a line itself (Billed by users / devices) - one count per line, not both
    if (mitColumnReady('recurring_invoice_items', 'item_seat_source')
        && (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT item_seat_source FROM recurring_invoice_items WHERE item_id = $recurring_item_id"))[0] ?? '') !== '') {
        return '"' . $item['item_name'] . '" is already billed by ITFlow\'s seat count - take that off the line first, or use it instead.';
    }
    $tag_id = intval($tag_id);
    if ($source === 'tag') {
        if (!$tag_id || !mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM tags WHERE tag_id = $tag_id AND tag_type = 3"))) {
            return '"' . $item['item_name'] . '": pick the contact tag to count.';
        }
        $metric = "tag:$tag_id";
    } else {
        $tag_id = 0;
        $metric = mitUsageCleanMetric($metric) ?: mitUsageMetricFromName($item['item_name']);
        if (stripos($metric, 'tag:') === 0) {
            return '"' . $item['item_name'] . '": usage names starting with "tag:" are kept for tag counts - pick "Contacts with a tag" instead.';
        }
    }
    $min = trim((string) $min_qty) === '' ? 'NULL' : max(0, min(1e9, round(floatval($min_qty), 2)));
    $metric_sql = escapeSql($metric);
    $recurring_invoice_id = intval($item['item_recurring_invoice_id']);
    $by = intval($session_user_id);
    mysqli_query($mysqli, "INSERT INTO mit_usage_lines SET recurring_item_id = $recurring_item_id, recurring_invoice_id = $recurring_invoice_id, line_source = '$source',
            line_metric = '$metric_sql', line_tag_id = $tag_id, line_min_qty = $min, updated_by = $by, updated_at = NOW()
        ON DUPLICATE KEY UPDATE line_source = '$source', line_metric = '$metric_sql', line_tag_id = $tag_id, line_min_qty = $min, updated_by = $by, updated_at = NOW()");
    return '';
}

/** Contact tags (ITFlow tag type 3), for the tag picker: id => name. */
function mitUsageContactTags() {
    global $mysqli;
    $tags = [];
    $result = mysqli_query($mysqli, "SELECT tag_id, tag_name FROM tags WHERE tag_type = 3 AND tag_archived_at IS NULL ORDER BY tag_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $tags[intval($row['tag_id'])] = $row['tag_name'];
    }
    return $tags;
}

/**
 * Keep a count. $as_of 'Y-m-d' (default today; not more than a day ahead). Returns [count_id, ''] or [0, problem].
 * $source: one of MIT_USAGE_COUNT_SOURCES.
 */
function mitUsageRecordCount($client_id, $metric, $quantity, $as_of = '', $source = 'manual', $note = '') {
    global $mysqli, $session_user_id;
    $client_id = intval($client_id);
    $metric = mitUsageCleanMetric($metric);
    if (!$client_id || !mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM clients WHERE client_id = $client_id"))) {
        return [0, 'That client does not exist.'];
    }
    if ($metric === '') {
        return [0, 'The usage name is missing.'];
    }
    if ($source !== 'tag' && stripos($metric, 'tag:') === 0) {
        return [0, 'Usage names starting with "tag:" are kept for the daily tag counts.'];
    }
    if (!is_numeric($quantity) || floatval($quantity) < 0 || floatval($quantity) >= 1e9) {
        return [0, 'The quantity must be a number from 0 up.'];
    }
    $as_of = trim((string) $as_of) === '' ? date('Y-m-d') : (string) $as_of;
    $date = DateTime::createFromFormat('!Y-m-d', $as_of);
    if (!$date || $date->format('Y-m-d') !== $as_of || $as_of > date('Y-m-d', strtotime('+1 day')) || $as_of < '2000-01-01') {
        return [0, 'The date must be a real date (YYYY-MM-DD), not in the future.'];
    }
    $source = in_array($source, MIT_USAGE_COUNT_SOURCES, true) ? $source : 'manual';
    mysqli_query($mysqli, "INSERT INTO mit_usage_counts SET client_id = $client_id, metric = '" . escapeSql($metric) . "', quantity = " . round(floatval($quantity), 2) . ",
        as_of = '$as_of', count_source = '$source', count_note = '" . escapeSql(mb_substr((string) $note, 0, 255)) . "', reported_by = " . intval($session_user_id ?? 0));
    return [mysqli_insert_id($mysqli), ''];
}

/** How many of a client's contacts (not archived) carry a tag. */
function mitUsageTagCount($client_id, $tag_id) {
    global $mysqli;
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(DISTINCT contacts.contact_id) FROM contacts JOIN contact_tags ON contact_tags.contact_id = contacts.contact_id
        WHERE contact_client_id = " . intval($client_id) . " AND contact_tags.tag_id = " . intval($tag_id) . " AND contact_archived_at IS NULL"))[0]);
}

/** Record today's count for every tag line (of active recurring invoices; one client or all). Returns how many were recorded. */
function mitUsageRecordTagCounts($client_id = 0) {
    global $mysqli;
    if (!mitUsageReady()) {
        return 0;
    }
    $client_sql = $client_id ? ' AND recurring_invoice_client_id = ' . intval($client_id) : '';
    $result = mysqli_query($mysqli, "SELECT DISTINCT recurring_invoice_client_id AS client_id, line_tag_id, line_metric FROM mit_usage_lines
        JOIN recurring_invoices ON recurring_invoices.recurring_invoice_id = mit_usage_lines.recurring_invoice_id
        JOIN tags ON tags.tag_id = line_tag_id AND tags.tag_archived_at IS NULL
        WHERE line_source = 'tag' AND recurring_invoice_status = 1 AND recurring_invoice_archived_at IS NULL$client_sql");
    $recorded = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $client = intval($row['client_id']);
        $metric = escapeSql($row['line_metric']);
        $count = mitUsageTagCount($client, $row['line_tag_id']);
        // One tag count a day - a later run the same day brings it up to date
        $today = mysqli_fetch_row(mysqli_query($mysqli, "SELECT count_id FROM mit_usage_counts WHERE client_id = $client AND metric = '$metric' AND as_of = CURDATE() AND count_source = 'tag' LIMIT 1"));
        if ($today) {
            mysqli_query($mysqli, "UPDATE mit_usage_counts SET quantity = $count, reported_at = NOW() WHERE count_id = " . intval($today[0]));
        } else {
            mysqli_query($mysqli, "INSERT INTO mit_usage_counts SET client_id = $client, metric = '$metric', quantity = $count, as_of = CURDATE(), count_source = 'tag', reported_by = 0");
            $recorded++;
        }
    }
    return $recorded;
}

/** The latest snapshot day on or before a date (the snapshot day of that month, else of the month before). */
function mitUsageSnapshotDate($date) {
    $day = mitUsageSettings()['snapshot_day'];
    $time = strtotime($date);
    $this_month = date('Y-m-', $time) . sprintf('%02d', $day);
    return $this_month <= date('Y-m-d', $time) ? $this_month : date('Y-m-', strtotime(date('Y-m-01', $time) . ' -1 month')) . sprintf('%02d', $day);
}

/** The last count of a client's usage on or before a date, or null. */
function mitUsageCountAt($client_id, $metric, $date) {
    global $mysqli;
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_usage_counts WHERE client_id = " . intval($client_id) . " AND metric = '" . escapeSql($metric) . "'
        AND as_of <= '" . escapeSql($date) . "' ORDER BY as_of DESC, count_id DESC LIMIT 1")) ?: null;
}

/** Set an invoice line's quantity and work its subtotal, tax and total out again (tax at today's rate, as ITFlow does). */
function mitUsageSetItemQty($invoice_item_id, $qty) {
    global $mysqli;
    $invoice_item_id = intval($invoice_item_id);
    $item = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT item_price, item_tax_id FROM invoice_items WHERE item_id = $invoice_item_id"));
    if (!$item) {
        return;
    }
    $qty = round(floatval($qty), 2);
    if (function_exists('billingLineTax')) {
        // ITFlow 26.11's tax engine (multi-part and compound rates); billingRecalc puts the invoice right after
        [$subtotal, $tax] = billingLineTax($qty, floatval($item['item_price']), intval($item['item_tax_id']));
        mysqli_query($mysqli, "UPDATE invoice_items SET item_quantity = $qty, item_subtotal = $subtotal, item_tax = $tax, item_total = " . round($subtotal + $tax, 2) . " WHERE item_id = $invoice_item_id");
        return;
    }
    $subtotal = round($qty * floatval($item['item_price']), 2);
    $tax_percent = intval($item['item_tax_id']) ? floatval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT tax_percent FROM taxes WHERE tax_id = " . intval($item['item_tax_id'])))[0] ?? 0) : 0;
    $tax = round($subtotal * $tax_percent / 100, 2);
    mysqli_query($mysqli, "UPDATE invoice_items SET item_quantity = $qty, item_subtotal = $subtotal, item_tax = $tax, item_total = " . ($subtotal + $tax) . " WHERE item_id = $invoice_item_id");
}

/** An invoice's amount from its lines, less its discount. */
function mitUsageRecalcInvoice($invoice_id) {
    global $mysqli;
    $invoice_id = intval($invoice_id);
    if (function_exists('billingRecalc')) {
        billingRecalc('invoice', $invoice_id); // ITFlow's: taxes (discount before tax), total
        return;
    }
    mysqli_query($mysqli, "UPDATE invoices SET invoice_amount = (SELECT COALESCE(SUM(item_total), 0) FROM invoice_items WHERE item_invoice_id = $invoice_id AND item_archived_at IS NULL)
        - invoice_discount_amount WHERE invoice_id = $invoice_id");
}

/**
 * Right after ITFlow generated an invoice from a recurring invoice: set each usage line's quantity from its count at the
 * snapshot day. Returns true when the recurring invoice has usage lines (the invoice then stays a Draft for review).
 * ITFlow copies the recurring lines in item_id order, so the new invoice's lines (in item_id order) match them one for one.
 */
function mitUsageApply($invoice_id, $recurring_invoice_id) {
    global $mysqli;
    $lines = mitUsageLines($recurring_invoice_id);
    if (!$lines) {
        return false;
    }
    // Whatever happens, an invoice with usage lines stays a Draft for review (a failure is noted in its history)
    try {
        mitUsageApplyLines(intval($invoice_id), $recurring_invoice_id, $lines);
    } catch (Throwable $e) {
        error_log('Usage billing: invoice ' . intval($invoice_id) . ': ' . $e->getMessage());
        mysqli_query($mysqli, "INSERT INTO history SET history_status = 'Draft', history_description = '" . escapeSql(mb_substr('Usage quantities could not be set: '
            . $e->getMessage(), 0, 500)) . "', history_invoice_id = " . intval($invoice_id));
    }
    return true;
}

function mitUsageApplyLines($invoice_id, $recurring_invoice_id, array $lines) {
    global $mysqli;
    $invoice = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_client_id, invoice_date FROM invoices WHERE invoice_id = $invoice_id"));
    if (!$invoice) {
        return false;
    }
    $client_id = intval($invoice['invoice_client_id']);
    $settings = mitUsageSettings();
    $snapshot = mitUsageSnapshotDate($invoice['invoice_date']);
    if ($snapshot === date('Y-m-d')) {
        mitUsageRecordTagCounts($client_id);
    }
    $recurring_items = mysqli_fetch_all(mysqli_query($mysqli, "SELECT item_id, item_quantity FROM recurring_invoice_items
        WHERE item_recurring_invoice_id = " . intval($recurring_invoice_id) . " ORDER BY item_order ASC, item_id ASC"), MYSQLI_ASSOC); // the order ITFlow copies them in (billingItems)
    $invoice_items = mysqli_fetch_all(mysqli_query($mysqli, "SELECT item_id FROM invoice_items WHERE item_invoice_id = $invoice_id ORDER BY item_id ASC"), MYSQLI_ASSOC);
    $applied = 0;
    $flagged = 0;
    foreach ($recurring_items as $i => $recurring_item) {
        $line = $lines[intval($recurring_item['item_id'])] ?? null;
        if (!$line || !isset($invoice_items[$i])) {
            continue;
        }
        $line_id = intval($line['line_id']);
        $invoice_item_id = intval($invoice_items[$i]['item_id']);
        $base = round(floatval($recurring_item['item_quantity']), 2);
        $count = mitUsageCountAt($client_id, $line['line_metric'], $snapshot);
        // Last month = what the last invoice still standing billed on this line (as it is now - hand edits count; cancelled ones don't)
        $previous = mysqli_fetch_row(mysqli_query($mysqli, "SELECT invoice_items.item_quantity FROM mit_usage_applied a
            JOIN invoice_items ON invoice_items.item_id = a.invoice_item_id JOIN invoices ON invoices.invoice_id = a.invoice_id
            WHERE a.line_id = $line_id AND a.invoice_id != $invoice_id AND invoices.invoice_status NOT IN ('Cancelled', 'Non-Billable')
                AND invoices.invoice_archived_at IS NULL
            ORDER BY a.applied_id DESC LIMIT 1"));
        $previous = $previous ? round(floatval($previous[0]), 2) : null;
        $flags = [];
        $qty = $count ? round(floatval($count['quantity']), 2) : $base;
        if (!$count) {
            $flags[] = 'missing';
        } elseif ($count['as_of'] < date('Y-m-d', strtotime("$snapshot -{$settings['stale_days']} days"))) {
            $flags[] = 'stale';
        }
        if ($line['line_min_qty'] !== null && $qty < floatval($line['line_min_qty'])) {
            $qty = round(floatval($line['line_min_qty']), 2);
            $flags[] = 'minimum';
        }
        $compare = $previous ?? $base;
        if (abs($qty - $compare) > 0.004 && ($compare == 0 || abs($qty - $compare) / $compare * 100 > $settings['change_pct'])) {
            $flags[] = 'big_change';
        }
        mitUsageSetItemQty($invoice_item_id, $qty);
        mysqli_query($mysqli, "INSERT INTO mit_usage_applied SET invoice_id = $invoice_id, invoice_item_id = $invoice_item_id, line_id = $line_id,
            metric = '" . escapeSql($line['line_metric']) . "', snapshot_date = '$snapshot', count_id = " . ($count ? intval($count['count_id']) : 'NULL') . ",
            count_as_of = " . ($count ? "'" . escapeSql($count['as_of']) . "'" : 'NULL') . ", count_source = " . ($count ? "'" . escapeSql($count['count_source']) . "'" : 'NULL') . ",
            base_qty = $base, previous_qty = " . ($previous === null ? 'NULL' : $previous) . ", applied_qty = $qty, flags = '" . implode(',', $flags) . "'");
        $applied++;
        $flagged += $flags ? 1 : 0;
    }
    mitUsageRecalcInvoice($invoice_id);
    mysqli_query($mysqli, "INSERT INTO history SET history_status = 'Draft', history_description = '" . escapeSql("Usage quantities set from counts as of $snapshot ($applied line"
        . ($applied === 1 ? '' : 's') . ($flagged ? ", $flagged to review" : '') . ")") . "', history_invoice_id = $invoice_id");
    return true;
}

/** Change a generated usage line's quantity by hand, with a reason (Draft invoices not in a batch only). Returns '' or the problem. */
function mitUsageOverride($applied_id, $qty, $reason) {
    global $mysqli, $session_user_id, $session_name;
    $applied_id = intval($applied_id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT a.*, invoice_status, invoice_archived_at, invoice_client_id, item_name FROM mit_usage_applied a
        JOIN invoices ON invoices.invoice_id = a.invoice_id JOIN invoice_items ON invoice_items.item_id = a.invoice_item_id
        WHERE a.applied_id = $applied_id" . clientScopeSql('invoices.invoice_client_id')));
    if (!$row) {
        return 'That usage line no longer exists.';
    }
    if ($row['invoice_status'] !== 'Draft' || $row['invoice_archived_at'] !== null) {
        return 'The invoice is no longer a Draft.';
    }
    if (function_exists('mitQboInvoiceLock') && ($why = mitQboInvoiceLock($row['invoice_id'])) !== '') {
        return $why;
    }
    if (!is_numeric($qty) || floatval($qty) < 0 || floatval($qty) >= 1e9) {
        return 'The quantity must be a number from 0 up.';
    }
    $reason = trim((string) $reason);
    if ($reason === '') {
        return 'Say why the quantity is changed.';
    }
    $qty = round(floatval($qty), 2);
    mitUsageSetItemQty($row['invoice_item_id'], $qty);
    mitUsageRecalcInvoice($row['invoice_id']);
    mysqli_query($mysqli, "UPDATE mit_usage_applied SET override_qty = $qty, override_reason = '" . escapeSql(mb_substr($reason, 0, 255)) . "', override_by = " . intval($session_user_id) . ",
        override_at = NOW() WHERE applied_id = $applied_id");
    mysqli_query($mysqli, "INSERT INTO history SET history_status = 'Draft', history_description = '" . escapeSql(mb_substr('Usage quantity of "' . $row['item_name'] . '" changed from '
        . (float) $row['applied_qty'] . " to $qty by " . stripslashes($session_name) . ": $reason", 0, 500)) . "', history_invoice_id = " . intval($row['invoice_id']));
    return '';
}

/** Snapshot days that have generated usage, newest first. */
function mitUsageSnapshots() {
    global $mysqli;
    return array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT DISTINCT snapshot_date FROM mit_usage_applied ORDER BY snapshot_date DESC LIMIT 24"), MYSQLI_ASSOC), 'snapshot_date');
}

/** One snapshot's usage lines, by invoice (client scope applies). */
function mitUsageReviewRows($snapshot) {
    global $mysqli;
    $rows = mysqli_fetch_all(mysqli_query($mysqli, "SELECT a.*, invoices.invoice_id, invoice_prefix, invoice_number, invoice_status, invoice_amount, invoice_currency_code,
            client_id, client_name, item_name, item_price, item_quantity, l.line_source, u.user_name AS override_name
        FROM mit_usage_applied a JOIN invoices ON invoices.invoice_id = a.invoice_id JOIN clients ON client_id = invoice_client_id
        JOIN invoice_items ON invoice_items.item_id = a.invoice_item_id LEFT JOIN mit_usage_lines l ON l.line_id = a.line_id LEFT JOIN users u ON u.user_id = a.override_by
        WHERE a.snapshot_date = '" . escapeSql($snapshot) . "' AND invoices.invoice_archived_at IS NULL" . clientScopeSql('invoices.invoice_client_id') . "
        ORDER BY client_name, invoice_number, a.invoice_item_id"), MYSQLI_ASSOC);
    $invoices = [];
    foreach ($rows as $row) {
        $invoices[intval($row['invoice_id'])][] = $row;
    }
    return $invoices;
}

/** A client's usage lines (on active recurring invoices) with their latest count - lines counted by an integration in ITFlow's
 * "Quantity from" (line_source 'usage'), and any left from the old Usage billing card. */
function mitUsageClientLines($client_id) {
    global $mysqli;
    if (!mitUsageReady()) {
        return [];
    }
    $client_id = intval($client_id);
    $rows = mysqli_fetch_all(mysqli_query($mysqli, "SELECT l.recurring_item_id, l.recurring_invoice_id, l.line_source, l.line_metric, l.line_tag_id, l.line_min_qty,
            item_name, item_quantity, recurring_invoice_prefix, recurring_invoice_number, recurring_invoice_status, tag_name
        FROM mit_usage_lines l JOIN recurring_invoice_items ON recurring_invoice_items.item_id = l.recurring_item_id
        JOIN recurring_invoices ON recurring_invoices.recurring_invoice_id = l.recurring_invoice_id LEFT JOIN tags ON tags.tag_id = l.line_tag_id AND l.line_source = 'tag'
        WHERE recurring_invoice_client_id = $client_id AND recurring_invoice_archived_at IS NULL ORDER BY item_name"), MYSQLI_ASSOC);
    if (mitColumnReady('recurring_invoice_items', 'item_seat_source')) {
        $result = mysqli_query($mysqli, "SELECT item_id, item_recurring_invoice_id, item_seat_filter, item_seat_minimum, item_name, item_quantity,
                recurring_invoice_prefix, recurring_invoice_number, recurring_invoice_status
            FROM recurring_invoice_items JOIN recurring_invoices ON recurring_invoices.recurring_invoice_id = item_recurring_invoice_id
            WHERE item_seat_source = 'usage' AND recurring_invoice_client_id = $client_id AND recurring_invoice_archived_at IS NULL ORDER BY item_name");
        while ($row = mysqli_fetch_assoc($result)) {
            $filter = mitUsageSeatFilter((array) json_decode((string) $row['item_seat_filter'], true));
            $rows[] = ['recurring_item_id' => $row['item_id'], 'recurring_invoice_id' => $row['item_recurring_invoice_id'], 'line_source' => 'usage',
                'line_metric' => $filter['metric'] ?? '', 'line_tag_id' => 0, 'line_min_qty' => $row['item_seat_minimum'], 'item_name' => $row['item_name'],
                'item_quantity' => $row['item_quantity'], 'recurring_invoice_prefix' => $row['recurring_invoice_prefix'],
                'recurring_invoice_number' => $row['recurring_invoice_number'], 'recurring_invoice_status' => $row['recurring_invoice_status'], 'tag_name' => null];
        }
    }
    foreach ($rows as &$row) {
        $row['latest'] = mitUsageCountAt($client_id, $row['line_metric'], date('Y-m-d', strtotime('+1 day')));
        if ($row['line_source'] === 'tag') {
            $row['live'] = mitUsageTagCount($client_id, $row['line_tag_id']);
        }
    }
    return $rows;
}

/** Draft invoices with usage flags not approved yet (side-menu count). */
function mitUsageNavCount() {
    global $mysqli;
    if (!mitUsageReady()) {
        return 0;
    }
    $ids = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT DISTINCT a.invoice_id FROM mit_usage_applied a
        JOIN invoices ON invoices.invoice_id = a.invoice_id
        WHERE a.flags != '' AND a.override_at IS NULL AND invoice_status = 'Draft' AND invoice_archived_at IS NULL" . clientScopeSql('invoices.invoice_client_id')), MYSQLI_ASSOC), 'invoice_id'));
    if (!$ids || !function_exists('mitInvoiceApprovalStates') || !mitQboReady()) {
        return count($ids);
    }
    // Approved and unchanged since - reviewed; changed since approval - to review again
    return count(array_filter(mitInvoiceApprovalStates($ids), fn($state) => $state !== 'approved'));
}

/** Flag badges for a usage row. */
function mitUsageFlagBadges($flags) {
    $html = '';
    foreach (array_filter(explode(',', (string) $flags)) as $flag) {
        [$label, $class, $title] = MIT_USAGE_FLAGS[$flag] ?? [$flag, 'text-bg-secondary', ''];
        $html .= '<span class="badge ' . $class . ' me-1" title="' . escapeHtml($title) . '" data-flag="' . escapeHtml($flag) . '">' . $label . '</span>';
    }
    return $html;
}

/** A quantity without needless decimals: 6, 2.5 */
function mitUsageQty($qty) {
    return rtrim(rtrim(number_format(floatval($qty), 2, '.', ''), '0'), '.');
}

/*
 * ---------- counts in ITFlow's own "Quantity from" (26.11.3.7, the user's choice) ----------
 *
 * A recurring line's quantity can come from ITFlow's seat counts (Users / Devices / License seats - functions/billing.php). Ours join that
 * list as one more source, 'usage' - "Counted by an integration": the latest count recorded for the client under a usage name
 * (mit_usage_counts - Bitwarden, NinjaOne, Ingram through the connectors, or anything through api/v1/mit_usage/create.php). ITFlow then
 * treats it as its own: counted again each time the invoice is made, on the Seats card with "now N" and Update now, changes in the history.
 * No review hold (the user's choice) - the invoice follows the recurring invoice's own send setting. The filter is {"metric": "<name>"} in
 * ITFlow's item_seat_filter; a name with no count yet counts as nothing (the line keeps its quantity, as ITFlow does for a gone license).
 * Hooks, one guarded line each, in functions/billing.php (recurringSeatSources, recurringSeatFilter, recurringSeatCount,
 * recurringSeatDescribe, billingItemSave's filter), agent/ajax.php (recurring_seat_count) and agent/modals/billing/billing_item.php
 * (mitUsageSeatOption, mitUsageSeatField). M058 moved the old Usage billing card's lines here (tag lines to ITFlow's Users tagged ...).
 */

/** Our seat source, for ITFlow's recurringSeatSources(). */
function mitUsageSeatSources() {
    if (!mitUsageReady()) {
        return [];
    }
    return ['usage' => ['label' => 'Counted by an integration', 'unit' => 'count', 'icon' => 'fa-plug']];
}

/** A usage seat line's filter, cleaned: ['metric' => name] (tag: names are ITFlow's Users tagged now). */
function mitUsageSeatFilter($filter) {
    $metric = mitUsageCleanMetric((string) ($filter['metric'] ?? ''));
    return $metric !== '' && stripos($metric, 'tag:') !== 0 ? ['metric' => $metric] : [];
}

/** The latest count recorded for the client under the line's usage name, or null when there is none. */
function mitUsageSeatCount($client_id, array $filter) {
    if (!mitUsageReady() || empty($filter['metric'])) {
        return null;
    }
    $count = mitUsageCountAt($client_id, $filter['metric'], date('Y-m-d'));
    return $count ? floatval($count['quantity']) : null;
}

/** A usage name in words: "Bitwarden users", "NinjaOne workstations", an Ingram product's name, else the name itself. */
function mitUsageMetricLabel($metric, $note = '') {
    $known = [
        'bitwarden:users' => 'Bitwarden users', 'bitwarden:confirmed' => 'Bitwarden users (confirmed)',
        'ninjaone:workstations' => 'NinjaOne workstations', 'ninjaone:servers' => 'NinjaOne servers',
        'ninjaone:devices' => 'NinjaOne workstations and servers', 'ninjaone:all' => 'NinjaOne devices (all)',
    ];
    if (isset($known[$metric])) {
        return $known[$metric];
    }
    if (stripos($metric, 'ingram:') === 0) {
        return 'Ingram Micro' . ($note !== '' ? ' - ' . $note : ' - ' . substr($metric, 7));
    }
    return $metric;
}

/** What a usage seat line counts, for ITFlow's recurringSeatDescribe(). */
function mitUsageSeatDescribe(array $filter) {
    $metric = (string) ($filter['metric'] ?? '');
    return $metric === '' ? 'A count - pick one' : mitUsageMetricLabel($metric, mitUsageMetricNote($metric));
}

/** The note kept with a usage name's latest count anywhere (an Ingram product's name). */
function mitUsageMetricNote($metric) {
    global $mysqli;
    if (!mitUsageReady() || stripos($metric, 'ingram:') !== 0) {
        return '';
    }
    return (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT count_note FROM mit_usage_counts WHERE metric = '" . escapeSql($metric) . "' AND count_note <> '' ORDER BY count_id DESC LIMIT 1"))[0] ?? '');
}

/** The counts a client has: usage name => ['label', 'quantity', 'as_of', 'source']. Not tag: counts. */
function mitUsageClientCounts($client_id) {
    global $mysqli;
    $counts = [];
    if (!mitUsageReady()) {
        return $counts;
    }
    $client_id = intval($client_id);
    $today = date('Y-m-d');
    $result = mysqli_query($mysqli, "SELECT c.metric, c.quantity, c.as_of, c.count_source, c.count_note FROM mit_usage_counts c
        JOIN (SELECT metric, MAX(CONCAT(as_of, LPAD(count_id, 12, '0'))) AS k FROM mit_usage_counts WHERE client_id = $client_id AND as_of <= '$today' GROUP BY metric) latest
            ON latest.metric = c.metric AND CONCAT(c.as_of, LPAD(c.count_id, 12, '0')) = latest.k
        WHERE c.client_id = $client_id ORDER BY c.metric");
    while ($row = mysqli_fetch_assoc($result)) {
        if (stripos($row['metric'], 'tag:') === 0) {
            continue;
        }
        $counts[$row['metric']] = ['label' => mitUsageMetricLabel($row['metric'], (string) $row['count_note']), 'quantity' => floatval($row['quantity']),
            'as_of' => $row['as_of'], 'source' => $row['count_source']];
    }
    return $counts;
}

/** The <option> for ITFlow's "Quantity from" picker in the Edit Line window. */
function mitUsageSeatOption($client_id, $seat_source) {
    if (!mitUsageReady()) {
        return '';
    }
    $n = count(mitUsageClientCounts($client_id));
    return '<option value="usage"' . ($seat_source === 'usage' ? ' selected' : '') . ($n || $seat_source === 'usage' ? '' : ' disabled') . ' data-mit-usage-option>'
        . 'Counted by an integration - Bitwarden, NinjaOne, Ingram, the API' . ($n ? '' : ' (none reported for this client yet)') . '</option>';
}

/** The "Which count" picker shown under "Quantity from" when it is ours. */
function mitUsageSeatField($client_id, $seat_source, array $seat_filter) {
    if (!mitUsageReady()) {
        return '';
    }
    $counts = mitUsageClientCounts($client_id);
    $current = $seat_source === 'usage' ? (string) ($seat_filter['metric'] ?? '') : '';
    $options = '';
    if ($current !== '' && !isset($counts[$current])) {
        $options .= '<option value="' . escapeHtml($current) . '" selected>' . escapeHtml(mitUsageMetricLabel($current)) . ' (no count now)</option>';
    }
    foreach ($counts as $metric => $count) {
        $options .= '<option value="' . escapeHtml($metric) . '"' . ($metric === $current ? ' selected' : '') . '>' . escapeHtml($count['label'])
            . ' (' . mitUsageQty($count['quantity']) . ' now)</option>';
    }
    return '<div class="col-12" data-seat-for="usage" data-mit-usage-seat hidden>'
        . '<label class="form-label">Which count</label>'
        . '<div class="input-group"><span class="input-group-text"><i class="fa fa-fw fa-plug"></i></span>'
        . '<select class="form-select" name="seat_metric" data-seat-input>' . ($options ?: '<option value="">None reported for this client yet</option>') . '</select></div>'
        . '<div class="form-text">The latest count for this client - Bitwarden, NinjaOne and Ingram Micro report nightly (Admin &gt; Integrations &gt; Usage connectors), '
        . 'anything else through the API (usage name). Counted again each time an invoice is made.</div></div>';
}

/*
 * "Not tagged ..." on ITFlow's Users / Devices seat lines (26.11.3.8, the user's request: placeholder contacts - shared mailboxes,
 * scanners - tagged so they are not billed as users). The same tag picker in the Edit Line window offers "Not tagged X" as the tag's id
 * negated; the line's filter keeps it as {"not_tag": id}. "Not tagged at all" (26.11.3.8) - value 'untagged', kept as {"untagged": 1} -
 * counts only the ones with no (unarchived) tag. Hooks, one guarded line each, in functions/billing.php: recurringSeatFilter
 * (mitSeatNotTagFilter), recurringSeatCount's contacts / assets queries (mitSeatNotTagSql), recurringSeatDescribe (mitSeatNotTagDescribe);
 * agent/modals/billing/billing_item.php (mitSeatNotTagOptions, both tag pickers). Stock ITFlow ignores not_tag and counts every one.
 */

/** Keep "not tagged" in a cleaned Users / Devices filter: a negative tag from the picker, or not_tag as stored. */
function mitSeatNotTagFilter($source, array $filter, array $clean) {
    if (!in_array($source, ['contacts', 'assets'], true)) {
        return $clean;
    }
    if (($filter['tag'] ?? '') === 'untagged' || !empty($filter['untagged'])) {
        unset($clean['tag'], $clean['not_tag']);
        $clean['untagged'] = 1;
        return $clean;
    }
    $tag = intval($filter['tag'] ?? 0);
    $not = $tag < 0 ? -$tag : intval($filter['not_tag'] ?? 0);
    if ($tag < 0) {
        unset($clean['tag']);
    }
    if ($not > 0 && empty($clean['tag'])) {
        $clean['not_tag'] = $not;
    }
    return $clean;
}

/** The SQL that leaves out contacts / devices with the line's "not tagged" tag. */
function mitSeatNotTagSql($source, array $filter) {
    if (!empty($filter['untagged'])) { // no tag at all (archived tags don't count)
        if ($source === 'contacts') {
            return " AND contact_id NOT IN (SELECT ct.contact_id FROM contact_tags ct JOIN tags t ON t.tag_id = ct.tag_id WHERE t.tag_archived_at IS NULL)";
        }
        if ($source === 'assets') {
            return " AND asset_id NOT IN (SELECT at.asset_tag_asset_id FROM asset_tags at JOIN tags t ON t.tag_id = at.asset_tag_tag_id WHERE t.tag_archived_at IS NULL)";
        }
        return '';
    }
    $not = intval($filter['not_tag'] ?? 0);
    if ($not <= 0) {
        return '';
    }
    if ($source === 'contacts') {
        return " AND contact_id NOT IN (SELECT contact_id FROM contact_tags WHERE tag_id = $not)";
    }
    if ($source === 'assets') {
        return " AND asset_id NOT IN (SELECT asset_tag_asset_id FROM asset_tags WHERE asset_tag_tag_id = $not)";
    }
    return '';
}

/** "Contacts not tagged Placeholder", "Desktop and Laptop not tagged Spare in use" - or null when the line has no "not tagged". */
function mitSeatNotTagDescribe($source, array $filter) {
    if (!empty($filter['untagged']) && in_array($source, ['contacts', 'assets'], true)) {
        unset($filter['untagged']);
        return $source === 'contacts' ? 'Contacts with no tags' : preg_replace('/ in use$/', '', recurringSeatDescribe('assets', $filter)) . ' with no tags in use';
    }
    $not = intval($filter['not_tag'] ?? 0);
    if ($not <= 0 || !in_array($source, ['contacts', 'assets'], true)) {
        return null;
    }
    $name = (string) getFieldById('tags', $not, 'tag_name');
    $name = $name !== '' ? $name : 'a tag that no longer exists';
    unset($filter['not_tag']);
    if ($source === 'contacts') {
        return "Contacts not tagged $name";
    }
    $base = recurringSeatDescribe('assets', $filter);
    return preg_replace('/ in use$/', '', $base) . " not tagged $name in use";
}

/** "Not tagged X" choices for a tag picker in the Edit Line window ($tags = ITFlow's seat options for that kind). */
function mitSeatNotTagOptions($client_id, $kind, $seat_source, array $seat_filter, array $tags) {
    $current = $seat_source === $kind ? intval($seat_filter['not_tag'] ?? 0) : 0;
    if ($current && !isset($tags[$current]) && ($row = tagRow($current))) {
        $tags[$current] = ['tag_id' => $current, 'tag_name' => $row['tag_name'] . ($row['tag_archived_at'] ? ' (archived)' : ''), 'n' => 0];
    }
    $untagged = $seat_source === $kind && !empty($seat_filter['untagged']);
    $html = '<optgroup label="Leave out tagged ones" data-mit-seat-not-tag>'
        . '<option value="untagged"' . ($untagged ? ' selected' : '') . '>Not tagged at all'
        . ($kind === 'contacts' ? ' (' . intval(recurringSeatCount($client_id, 'contacts', ['untagged' => 1])) . ')' : '') . '</option>';
    foreach ($tags as $tag_id => $tag) {
        $tag_id = intval($tag_id);
        $n = $kind === 'contacts' ? ' (' . intval(recurringSeatCount($client_id, 'contacts', ['not_tag' => $tag_id])) . ')' : '';
        $html .= '<option value="-' . $tag_id . '"' . ($current === $tag_id ? ' selected' : '') . '>Not tagged ' . escapeHtml($tag['tag_name']) . $n . '</option>';
    }
    return $html . '</optgroup>';
}

/** The Usage card on a client's overview (agent/client_overview.php, one line): usage lines, latest counts, typing a count. */
function mitUsageClientCard($client_id) {
    if (!mitUsageReady() || !mitUsageCan(1)) {
        return '';
    }
    $lines = mitUsageClientLines($client_id);
    if (!$lines) {
        return '';
    }
    $can = mitUsageCan(2);
    $html = '<div class="card mb-3" data-mit-usage-client><div class="card-header"><h3 class="card-title"><i class="fas fa-fw fa-tachometer-alt me-2"></i>Usage</h3></div>'
        . '<div class="card-body p-2"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Line</th><th>From</th><th class="text-end">Latest count</th>'
        . ($can ? '<th>Enter a count</th>' : '') . '</tr></thead><tbody>';
    foreach ($lines as $line) {
        $latest = $line['latest'];
        $from = $line['line_source'] === 'tag' ? 'Tag ' . escapeHtml((string) $line['tag_name']) . ' <span class="text-muted">(now ' . intval($line['live']) . ')</span>'
            : ($line['line_source'] === 'usage' ? escapeHtml(mitUsageMetricLabel($line['line_metric'], mitUsageMetricNote($line['line_metric'])))
                : escapeHtml(MIT_USAGE_SOURCES[$line['line_source']] ?? $line['line_source'])) . '<div class="small text-muted">' . escapeHtml($line['line_metric']) . '</div>';
        $html .= '<tr data-metric="' . escapeHtml($line['line_metric']) . '"><td>' . escapeHtml($line['item_name']) . '<div class="small text-muted">'
            . escapeHtml($line['recurring_invoice_prefix'] . $line['recurring_invoice_number']) . (intval($line['recurring_invoice_status']) ? '' : ' (inactive)') . '</div></td>'
            . '<td class="small">' . $from . '</td><td class="text-end">' . ($latest ? '<strong>' . mitUsageQty($latest['quantity']) . '</strong><div class="small text-muted">'
                . escapeHtml($latest['as_of']) . ' · ' . escapeHtml($latest['count_source']) . '</div>' : '<span class="text-muted">none yet</span>') . '</td>';
        if ($can) {
            $html .= '<td>' . ($line['line_source'] === 'tag' ? '<span class="small text-muted">counted daily</span>'
                : '<form action="post.php" method="post" class="d-flex gap-1" style="min-width: 15rem"><input type="hidden" name="csrf_token" value="' . escapeHtml($_SESSION['csrf_token']) . '">'
                . '<input type="hidden" name="client_id" value="' . intval($client_id) . '"><input type="hidden" name="metric" value="' . escapeHtml($line['line_metric']) . '">'
                . '<input type="number" min="0" step="0.01" class="form-control form-control-sm" name="quantity" placeholder="Qty" required style="max-width: 6rem">'
                . '<input type="date" class="form-control form-control-sm" name="as_of" value="' . date('Y-m-d') . '" max="' . date('Y-m-d') . '" style="max-width: 9.5rem">'
                . '<button type="submit" name="mit_usage_count" class="btn btn-outline-primary btn-sm">Save</button></form>') . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div></div></div>';
}
