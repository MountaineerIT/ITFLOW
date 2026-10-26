<?php

/*
 * Mountaineer IT - reimbursements on tickets (database update M017), required from functions/mit_timesheets.php.
 *
 * An agent logs, on the ticket they worked:
 *   - mileage: from, to, miles, round trip - an ordinary ITFlow trip is made for it (Trips, the Mileage
 *     report), and it is reimbursed at the company's rate per mile, kept on the entry as it was that day;
 *   - a purchase they paid for: what, where, the price and a receipt - added to the ticket's Parts (to bill
 *     the customer, the price paid as our cost) when they tick it and procurement is on.
 * Reimbursements belong to the pay period of their date: they show on the person's timesheet and the payroll
 * report, and are locked with the timesheet. Approving it records each purchase as an ITFlow expense (with the
 * receipt) when a category and account are set (Admin > Timesheets > Settings - ts_mileage_rate,
 * ts_reimb_category, ts_reimb_account).
 *
 * Paid back through ITFlow (26.11, mitReimbItflowPaysBack): ITFlow's own "paid personally" expenses and Reimburse step pay
 * people back. Our mileage trips are marked as driven in their own vehicle (trip_own_vehicle), so they wait in Trips' pay-back
 * list at ITFlow's mileage rate (which then is ours too); approved purchases become expenses paid by the person
 * (expense_paid_by, on no account, vendor matched by name or "Staff purchases"), waiting in Expenses' Reimburse list. An entry
 * whose trip was claimed or whose expense was paid back is locked here.
 *
 * Who: anyone who can edit the ticket logs their own; the person, or someone who may correct their timesheet,
 * changes or removes one while the period is open (mitTsWhyLocked). Follows the Timesheets switch.
 * Pages: the Reimbursements box on the ticket (mitReimbTicketCard, layout box "reimburse", icon fa-receipt),
 * modal agent/modals/mit_reimburse/reimburse.php, handlers agent/post/mit_reimbursements.php, receipts served
 * by agent/mit_receipt.php from uploads/mit_receipts/.
 */

const MIT_REIMB_KINDS = ['mileage' => 'Mileage', 'purchase' => 'Purchase'];
const MIT_REIMB_RECEIPT_DIR = 'uploads/mit_receipts';

/** Has database update M017 run? */
function mitReimbReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_reimbursements'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Are reimbursements in use? They come with timesheets (and their on/off switch). */
function mitReimbOn() {
    return mitReimbReady() && mitTsOn();
}

/** Does ITFlow pay people back itself (26.11: expenses.expense_paid_by, trips.trip_own_vehicle)? */
function mitReimbItflowPaysBack() {
    global $mysqli;
    static $yes = null;
    if ($yes === null) {
        try {
            $yes = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM expenses LIKE 'expense_paid_by'")) === 1
                && mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM trips LIKE 'trip_own_vehicle'")) === 1;
        } catch (Throwable $e) {
            $yes = false;
        }
    }
    return $yes;
}

/**
 * Paid back with payroll (mit_settings ts_payback 'payroll', the default) rather than through ITFlow's own pay-back screens ('itflow').
 * Either way the entries are ITFlow trips / paid-personally expenses; with payroll, approving the period closes them off as paid
 * through payroll (mitReimbPayrollClose) so they never wait in Trips > To pay back or Expenses > Reimburse.
 */
function mitReimbViaPayroll() {
    return mitReimbItflowPaysBack() && (string) mitSetting('ts_payback', 'payroll') !== 'itflow';
}

/** The reference a payroll close-off writes on ITFlow's reimbursement - found again by reopen. */
function mitReimbPayrollReference($sheet_id, array $period) {
    return 'Payroll - timesheet ' . intval($sheet_id) . ' (' . $period['label'] . ')';
}

/**
 * Approving a period (or, with approval off, once it has settled): the person's own-vehicle trips and paid-personally expenses in
 * it are paid through payroll - the trips claimed as mileage expenses at the rate (ITFlow's tripClaim), then everything reimbursed
 * in one go from the payroll account (ITFlow's expenseReimburse, reference mitReimbPayrollReference). Returns a line for the
 * message ('' when there was nothing).
 */
function mitReimbPayrollClose($user_id, array $period, $sheet_id) {
    global $mysqli;
    $user_id = intval($user_id);
    if (!mitReimbViaPayroll() || !function_exists('tripClaim') || !function_exists('expenseReimburse')) {
        return '';
    }
    $settings = mitReimbSettings();
    $from = escapeSql($period['start']);
    $to = escapeSql($period['end']);
    $by = mitTsWho();
    $note = [];
    // Mileage: the period's own-vehicle trips not paid yet become one mileage expense per client
    $trip_ids = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT trip_id FROM trips WHERE " . tripClaimSql() . "
        AND trips.trip_user_id = $user_id AND trips.trip_date BETWEEN '$from' AND '$to'")), 0));
    if ($trip_ids) {
        if ($settings['rate'] <= 0) {
            $note[] = 'mileage not paid - no mileage rate set';
        } else {
            $error = '';
            if (!tripClaim($user_id, $trip_ids, ['rate' => $settings['rate'], 'date' => $period['end'], 'by' => $by], $error)) {
                $note[] = "mileage not paid - $error";
            }
        }
    }
    // Everything they paid for themselves in the period (mileage just claimed included), reimbursed through payroll
    $expense_ids = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT expense_id FROM expenses WHERE " . expenseToReimburseSql() . "
        AND expense_paid_by = $user_id AND expense_date BETWEEN '$from' AND '$to'")), 0));
    if (!$expense_ids) {
        return $note ? 'Reimbursements: ' . implode('; ', $note) : '';
    }
    if (!$settings['account_id']) {
        return 'Reimbursements not marked paid - pick the account payroll pays from (Admin > Timesheets > Reimbursements).';
    }
    $error = '';
    $done = expenseReimburse($user_id, $expense_ids, ['account_id' => $settings['account_id'], 'date' => date('Y-m-d'),
        'reference' => mitReimbPayrollReference($sheet_id, $period), 'by' => $by], $error);
    if (!$done) {
        return "Reimbursements not marked paid - $error";
    }
    return 'Reimbursements ' . billingMoney($done['amount'], $done['currency']) . ' paid through payroll' . ($note ? ' (' . implode('; ', $note) . ')' : '');
}

/** Reopening a period: its payroll close-off undone - the reimbursement removed, the mileage expenses gone and their trips waiting again. */
function mitReimbPayrollUndo($user_id, array $period, $sheet_id) {
    global $mysqli;
    if (!mitReimbItflowPaysBack() || !function_exists('reimbursementDelete')) {
        return '';
    }
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT reimbursement_id FROM reimbursements WHERE reimbursement_user_id = " . intval($user_id) . "
        AND reimbursement_reference = '" . escapeSql(mitReimbPayrollReference($sheet_id, $period)) . "' ORDER BY reimbursement_id DESC LIMIT 1"));
    if (!$row) {
        return '';
    }
    $expense_ids = array_map('intval', array_column(reimbursementExpenses($row[0]), 'expense_id'));
    $error = '';
    if (!reimbursementDelete($row[0], $error)) {
        return "Its payroll reimbursement stays - $error";
    }
    // The mileage expenses the close-off made go, and their trips wait to be paid again
    foreach ($expense_ids as $expense_id) {
        if (mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM trips WHERE trip_expense_id = $expense_id"))) {
            mysqli_query($mysqli, "UPDATE trips SET trip_expense_id = 0 WHERE trip_expense_id = $expense_id");
            mysqli_query($mysqli, "DELETE FROM expenses WHERE expense_id = $expense_id AND expense_reimbursement_id IS NULL");
        }
    }
    return 'its reimbursements are waiting for payroll again';
}

/** The ticket's ⋮ menu (agent/ticket.php, after ITFlow's Log a trip): Add a purchase paid for personally. */
function mitReimbMenuItem(array $ticket, $can_edit) {
    if (!$can_edit || !mitReimbOn()) {
        return '';
    }
    return '<a class="dropdown-item ajax-modal" href="#" data-mit-reimb-menu data-modal-url="/agent/modals/mit_reimburse/reimburse.php?ticket_id=' . intval($ticket['ticket_id'])
        . '&kind=purchase"><i class="fas fa-fw fa-shopping-bag me-2"></i>Add a purchase</a>';
}

/** ITFlow's own mileage rate (Admin > Trips), 0 when not set or not there. */
function mitReimbItflowRate() {
    return mitReimbItflowPaysBack() && function_exists('tripRules') ? (float) tripRules()['rate'] : 0.0;
}

/**
 * The settings: rate per mile (0 = not set - ITFlow's own rate when it has one, so the timesheet shows what ITFlow will pay),
 * and the expense category and account purchases are recorded under (no account once ITFlow pays back - paid personally).
 */
function mitReimbSettings() {
    $itflow_rate = mitReimbItflowRate();
    return [
        'rate' => $itflow_rate > 0 ? round($itflow_rate, 4) : max(0, round((float) mitSetting('ts_mileage_rate', '0'), 4)),
        'category_id' => intval(mitSetting('ts_reimb_category', '0')),
        'account_id' => intval(mitSetting('ts_reimb_account', '0')),
    ];
}

/** A money value posted from a form ("1,234.50", "$12"), or null when it cannot be read. */
function mitReimbAmount($text) {
    $text = trim(str_replace([',', '$'], '', (string) $text));
    // Plain money only: no 1e20 (which overflowed the column), and nothing past a million
    if (!preg_match('/^-?\d{1,7}(\.\d{0,2})?$/', $text) || abs((float) $text) > 1000000) {
        return null;
    }
    return round((float) $text, 2);
}

/*
 * ---------- reading ----------
 */

const MIT_REIMB_COLUMNS = "r.reimb_id, r.ticket_id, r.client_id, r.user_id, r.entry_date, r.kind, r.description, r.vendor, r.source, r.destination,
    r.miles, r.round_trip, r.rate, r.amount, r.trip_id, r.part_id, r.receipt, r.receipt_name, r.expense_id, r.created_by, r.created_at";

/** One entry with its ticket's reference and the agent's name, or null. */
function mitReimbRow($reimb_id) {
    global $mysqli;
    if (!mitReimbReady()) {
        return null;
    }
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT " . MIT_REIMB_COLUMNS . ", ticket_prefix, ticket_number, ticket_subject, user_name
        FROM mit_reimbursements r LEFT JOIN tickets ON tickets.ticket_id = r.ticket_id LEFT JOIN users ON users.user_id = r.user_id
        WHERE r.reimb_id = " . intval($reimb_id))) ?: null;
}

/** A ticket's entries, newest first. */
function mitReimbForTicket($ticket_id) {
    global $mysqli;
    if (!mitReimbReady()) {
        return [];
    }
    return mysqli_fetch_all(mysqli_query($mysqli, "SELECT " . MIT_REIMB_COLUMNS . ", user_name FROM mit_reimbursements r LEFT JOIN users ON users.user_id = r.user_id
        WHERE r.ticket_id = " . intval($ticket_id) . " ORDER BY r.entry_date DESC, r.reimb_id DESC"), MYSQLI_ASSOC);
}

/** People's entries in dates: user_id => [rows, oldest first], with the ticket's reference. */
function mitReimbForUsers(array $user_ids, $from, $to) {
    global $mysqli;
    $user_ids = array_filter(array_map('intval', $user_ids));
    $out = [];
    if (!$user_ids || !mitReimbReady()) {
        return $out;
    }
    $result = mysqli_query($mysqli, "SELECT " . MIT_REIMB_COLUMNS . ", ticket_prefix, ticket_number, ticket_subject, client_name
        FROM mit_reimbursements r LEFT JOIN tickets ON tickets.ticket_id = r.ticket_id LEFT JOIN clients ON clients.client_id = r.client_id
        WHERE r.user_id IN (" . implode(',', $user_ids) . ") AND r.entry_date BETWEEN '" . escapeSql($from) . "' AND '" . escapeSql($to) . "'
        ORDER BY r.entry_date, r.reimb_id");
    while ($row = mysqli_fetch_assoc($result)) {
        $out[intval($row['user_id'])][] = $row;
    }
    return $out;
}

/** Totals for people in dates: user_id => [miles, mileage, purchases, total, count] (money in the company currency). */
function mitReimbTotals(array $user_ids, $from, $to) {
    $totals = [];
    foreach (array_filter(array_map('intval', $user_ids)) as $user_id) {
        $totals[$user_id] = ['miles' => 0.0, 'mileage' => 0.0, 'purchases' => 0.0, 'total' => 0.0, 'count' => 0];
    }
    foreach (mitReimbForUsers($user_ids, $from, $to) as $user_id => $rows) {
        foreach ($rows as $row) {
            $t = &$totals[$user_id];
            if ($row['kind'] === 'mileage') {
                $t['miles'] += (float) $row['miles'];
                $t['mileage'] += (float) $row['amount'];
            } else {
                $t['purchases'] += (float) $row['amount'];
            }
            $t['total'] += (float) $row['amount'];
            $t['count']++;
            unset($t);
        }
    }
    // Paid back through ITFlow: drives logged in ITFlow's own Trips (own vehicle) count on the timesheet too
    foreach (mitReimbItflowTrips($user_ids, $from, $to) as $user_id => $trips) {
        foreach ($trips as $trip) {
            $t = &$totals[$user_id];
            $t['miles'] += (float) $trip['trip_miles'];
            $t['mileage'] += (float) $trip['value'];
            $t['total'] += (float) $trip['value'];
            $t['count']++;
            unset($t);
        }
    }
    return $totals;
}

/**
 * Drives in their own vehicle logged in ITFlow's Trips (not by our Reimbursements box) by people in dates - user_id => [trip rows
 * with 'value' at ITFlow's mileage rate]. Only while ITFlow pays people back.
 */
function mitReimbItflowTrips(array $user_ids, $from, $to) {
    global $mysqli;
    $user_ids = array_filter(array_map('intval', $user_ids));
    $out = [];
    if (!$user_ids || !mitReimbItflowPaysBack()) {
        return $out;
    }
    $rate = mitReimbItflowRate();
    $ours = mitReimbReady() ? " AND trips.trip_id NOT IN (SELECT trip_id FROM mit_reimbursements WHERE trip_id IS NOT NULL)" : '';
    $result = mysqli_query($mysqli, "SELECT trips.trip_id, trips.trip_date, trips.trip_purpose, trips.trip_source, trips.trip_destination, trips.trip_miles,
            trips.round_trip, trips.trip_user_id, trips.trip_expense_id, trips.trip_ticket_id
        FROM trips WHERE trips.trip_own_vehicle = 1 AND trips.trip_archived_at IS NULL AND trips.trip_user_id IN (" . implode(',', $user_ids) . ")
            AND trips.trip_date BETWEEN '" . escapeSql($from) . "' AND '" . escapeSql($to) . "'$ours ORDER BY trips.trip_date, trips.trip_id");
    while ($row = mysqli_fetch_assoc($result)) {
        $row['value'] = round((float) $row['trip_miles'] * $rate, 2);
        $out[intval($row['trip_user_id'])][] = $row;
    }
    return $out;
}

/**
 * Where an entry is in ITFlow's paying back ([label, tone]), or null while ITFlow doesn't pay back: mileage waits in Trips > To pay
 * back until claimed, purchases become an expense paid personally on approval and wait in Expenses > Reimburse.
 */
function mitReimbState(array $row) {
    global $mysqli;
    if (!mitReimbItflowPaysBack()) {
        return null;
    }
    $expense_id = 0;
    if ($row['kind'] === 'mileage') {
        $expense_id = intval($row['trip_id'] ?? 0) ? intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT trip_expense_id FROM trips WHERE trip_id = " . intval($row['trip_id'])))[0] ?? 0) : 0;
        if (!$expense_id) {
            return [mitReimbViaPayroll() ? 'Goes to payroll' : 'Waiting in Trips', 'secondary'];
        }
    } else {
        $expense_id = intval($row['expense_id'] ?? 0);
        if (!$expense_id) {
            return [mitReimbViaPayroll() ? 'Goes to payroll' : 'An expense once approved', 'secondary'];
        }
    }
    $paid = mysqli_fetch_row(mysqli_query($mysqli, "SELECT reimbursement_date, reimbursement_reference FROM expenses JOIN reimbursements ON reimbursement_id = expense_reimbursement_id WHERE expense_id = $expense_id"));
    if ($paid) {
        return [(strpos((string) $paid[1], 'Payroll') === 0 ? 'Paid through payroll ' : 'Paid back ') . date('M j', strtotime($paid[0])), 'success'];
    }
    return mitReimbViaPayroll() ? ['Goes to payroll', 'secondary'] : ['Waiting in Expenses > Reimburse', 'warning'];
}

/** A ticket's reference for an entry ("TCK-12"), or "a deleted ticket". */
function mitReimbTicketText(array $row) {
    return $row['ticket_number'] !== null && $row['ticket_number'] !== '' ? ticketReference($row) : 'a deleted ticket';
}

/** What an entry is, in a line: "42 mi round trip, Office - Acme" or "Cable kit at Best Buy". */
function mitReimbWhat(array $row) {
    if ($row['kind'] === 'mileage') {
        return rtrim(rtrim(number_format((float) $row['miles'], 1), '0'), '.') . ' mi' . (intval($row['round_trip']) ? ' round trip' : '')
            . ($row['source'] || $row['destination'] ? ', ' . trim($row['source'] . ' - ' . $row['destination'], ' -') : '');
    }
    return $row['description'] . ($row['vendor'] ? ' at ' . $row['vendor'] : '');
}

/*
 * ---------- who may change one ----------
 */

/** Why the signed-in agent may not change or remove an entry - '' when they may (the timesheet rules for its date). */
function mitReimbWhyLocked(array $row) {
    global $session_user_id;
    $user_id = intval($row['user_id']);
    if ($user_id !== intval($session_user_id) && !mitTsMayCorrect($user_id)) {
        return 'Only ' . ($row['user_name'] ?? 'they') . ', or whoever corrects their timesheet, can change it.';
    }
    if ($paid = mitReimbPaidBack($row)) {
        return $paid;
    }
    return mitTsWhyDayLocked($user_id, $row['entry_date']);
}

/** '' or why an entry is settled in ITFlow: its trip claimed for mileage, or its expense paid back. */
function mitReimbPaidBack(array $row) {
    global $mysqli;
    if (!mitReimbItflowPaysBack()) {
        return '';
    }
    if (intval($row['trip_id'] ?? 0) && intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT trip_expense_id FROM trips WHERE trip_id = " . intval($row['trip_id'])))[0] ?? 0)) {
        return 'Its mileage has been paid back in ITFlow (Trips) - it can no longer change.';
    }
    if (intval($row['expense_id'] ?? 0) && intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT expense_reimbursement_id FROM expenses WHERE expense_id = " . intval($row['expense_id'])))[0] ?? 0)) {
        return 'It has been paid back in ITFlow (Expenses > Reimburse) - it can no longer change.';
    }
    return '';
}

/** The vendor a paid-personally purchase goes under: our own vendor of that name, else "Staff purchases" (made once). */
function mitReimbVendorId($name) {
    global $mysqli;
    $name = trim((string) $name);
    if ($name !== '') {
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL
            AND LOWER(vendor_name) = LOWER('" . escapeSql($name) . "') LIMIT 1"));
        if ($row) {
            return intval($row[0]);
        }
    }
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_name = 'Staff purchases'
        ORDER BY vendor_archived_at IS NULL DESC, vendor_id LIMIT 1"));
    if ($row) {
        return intval($row[0]);
    }
    mysqli_query($mysqli, "INSERT INTO vendors SET vendor_name = 'Staff purchases', vendor_description = 'Things agents bought themselves, paid back (timesheet reimbursements)', vendor_client_id = 0");
    return intval(mysqli_insert_id($mysqli));
}

/*
 * ---------- saving ----------
 */

/**
 * Add or change an entry from the modal. $ticket is the ticket row (ticketRow), $row the entry being changed or null.
 * Mileage makes or updates its ITFlow trip; a purchase can add a ticket part and keeps its receipt.
 * Returns [ok, message - plain text].
 */
function mitReimbSave(array $ticket, ?array $row, array $post, array $files) {
    global $mysqli, $session_user_id, $session_name;
    $kind = $row ? $row['kind'] : (($post['kind'] ?? '') === 'purchase' ? 'purchase' : 'mileage');
    $ticket_id = intval($ticket['ticket_id']);
    $client_id = intval($ticket['ticket_client_id']);
    $user_id = $row ? intval($row['user_id']) : intval($session_user_id);
    $reference = ticketReference($ticket);
    $date = (string) ($post['date'] ?? '');
    if (!calendarIsDate($date)) {
        return [false, 'Pick the date.'];
    }
    // The period the entry lands in must be open to this agent - and the one it leaves, when the date moves
    $why = mitTsWhyDayLocked($user_id, $date);
    if ($why === '' && $row) {
        $why = mitReimbWhyLocked($row);
    }
    if ($why !== '') {
        return [false, $why];
    }
    $who = stripslashes((string) $session_name);

    if ($kind === 'mileage') {
        $miles = round((float) str_replace(',', '', (string) ($post['miles'] ?? '0')), 1);
        $source = trim((string) ($post['source'] ?? ''));
        $destination = trim((string) ($post['destination'] ?? ''));
        $round_trip = empty($post['round_trip']) ? 0 : 1;
        if ($miles <= 0 || $miles > 5000) {
            return [false, 'Enter the miles driven.'];
        }
        $rate = $row ? (float) $row['rate'] : mitReimbSettings()['rate'];
        if (!$row && $rate <= 0) {
            return [false, 'No rate per mile is set yet - ask an admin (Admin > Timesheets > Settings).'];
        }
        $amount = round($miles * $rate, 2);
        $purpose = mb_substr("$reference - " . $ticket['ticket_subject'], 0, 200);
        $trip_set = "trip_date = '$date', trip_purpose = '" . escapeSql($purpose) . "', trip_source = '" . escapeSql(mb_substr($source, 0, 200)) . "',
            trip_destination = '" . escapeSql(mb_substr($destination, 0, 200)) . "', trip_miles = $miles, round_trip = $round_trip, trip_user_id = $user_id, trip_client_id = $client_id"
            // ITFlow 26.11 pays back trips driven in one's own vehicle (Trips > pay back) - ours are
            . (mitReimbItflowPaysBack() ? ", trip_own_vehicle = 1, trip_ticket_id = " . intval($ticket['ticket_id']) : '');
        $trip_id = intval($row['trip_id'] ?? 0);
        if ($trip_id && mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM trips WHERE trip_id = $trip_id"))) {
            mysqli_query($mysqli, "UPDATE trips SET $trip_set WHERE trip_id = $trip_id");
        } else {
            mysqli_query($mysqli, "INSERT INTO trips SET $trip_set");
            $trip_id = mysqli_insert_id($mysqli);
            logAudit('Trip', 'Create', escapeSql("$who logged trip from $source to $destination for ticket $reference"), $client_id, $trip_id);
        }
        $set = "entry_date = '$date', description = '" . escapeSql(mb_substr("Mileage for $reference", 0, 255)) . "', source = '" . escapeSql(mb_substr($source, 0, 200)) . "',
            destination = '" . escapeSql(mb_substr($destination, 0, 200)) . "', miles = $miles, round_trip = $round_trip, rate = $rate, amount = $amount, trip_id = $trip_id";
        $what = rtrim(rtrim(number_format($miles, 1), '0'), '.') . ' mi (' . mitMoney($amount) . ')';
    } else {
        $description = trim((string) ($post['description'] ?? ''));
        $vendor = trim((string) ($post['vendor'] ?? ''));
        $amount = mitReimbAmount($post['amount'] ?? '');
        if ($description === '' || $amount === null || $amount <= 0) {
            return [false, 'Say what was bought and what it cost.'];
        }
        $set = "entry_date = '$date', description = '" . escapeSql(mb_substr($description, 0, 255)) . "', vendor = " . ($vendor === '' ? 'NULL' : "'" . escapeSql(mb_substr($vendor, 0, 200)) . "'") . ", amount = $amount";
        // A receipt - an image or a PDF, kept under an unguessable name
        $file = $files['receipt'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (intval($file['size'] ?? 0) > 20 * 1024 * 1024) {
                return [false, 'That receipt is over 20 MB - use a smaller picture or PDF.'];
            }
            $stored = checkFileUpload($file, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'heic']);
            $dir = dirname(__DIR__) . '/' . MIT_REIMB_RECEIPT_DIR;
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            // Receipts are only handed out by agent/mit_receipt.php, which checks who is asking - kept so even when the
            // folder came from elsewhere (a copy or a restore without its dotfiles)
            if (is_dir($dir) && !is_file("$dir/.htaccess")) {
                @file_put_contents("$dir/.htaccess", "Require all denied\n");
            }
            if (!$stored || !is_dir($dir) || !move_uploaded_file($file['tmp_name'], "$dir/$stored")) {
                return [false, 'The receipt could not be saved - use a picture or a PDF.'];
            }
            if (!empty($row['receipt'])) {
                @unlink("$dir/" . basename($row['receipt']));
            }
            $set .= ", receipt = '" . escapeSql($stored) . "', receipt_name = '" . escapeSql(mb_substr(basename((string) $file['name']), 0, 255)) . "'";
        }
        $what = "$description (" . mitMoney($amount) . ')';
    }

    if ($row) {
        mysqli_query($mysqli, "UPDATE mit_reimbursements SET $set WHERE reimb_id = " . intval($row['reimb_id']));
        $reimb_id = intval($row['reimb_id']);
        // Already recorded as an ITFlow expense (approved, then reopened): the expense follows the change
        if ($kind === 'purchase' && intval($row['expense_id'])) {
            mitReimbSyncExpense(array_merge($row, ['entry_date' => $date, 'description' => $description, 'vendor' => $vendor === '' ? null : $vendor, 'amount' => $amount]));
        }
    } else {
        mysqli_query($mysqli, "INSERT INTO mit_reimbursements SET ticket_id = $ticket_id, client_id = $client_id, user_id = $user_id, kind = '$kind', $set, created_by = " . intval($session_user_id));
        $reimb_id = mysqli_insert_id($mysqli);
    }

    // A purchase on the ticket's Parts, to bill: added once, and kept in step while it is not billed
    $note = '';
    if ($kind === 'purchase' && function_exists('mitProcOn') && mitProcOn()) {
        $part = !empty($row['part_id']) ? (mitTicketParts($ticket_id)[intval($row['part_id'])] ?? null) : null;
        if ($part && !intval($part['invoice_id'])) {
            mysqli_query($mysqli, "UPDATE mit_ticket_parts SET name = '" . escapeSql(mb_substr($description, 0, 255)) . "', unit_cost = $amount WHERE part_id = " . intval($part['part_id']));
        } elseif (!$row && !empty($post['add_part'])) {
            $price = mitReimbAmount($post['sell_price'] ?? '') ?? $amount;
            [$ok, , $part_id] = mitTicketPartSave($ticket, ['name' => $description, 'quantity' => 1, 'unit_cost' => $amount, 'unit_price' => max(0, $price), 'source' => 'other',
                'description' => 'Bought' . ($vendor !== '' ? " at $vendor" : '') . " by " . (getFieldById('users', $user_id, 'user_name') ?: 'the agent') . ' - reimbursed']) + [2 => 0];
            if ($ok && $part_id) {
                mysqli_query($mysqli, "UPDATE mit_reimbursements SET part_id = " . intval($part_id) . " WHERE reimb_id = $reimb_id");
                $note = ' It is on the ticket\'s Parts to bill.';
            }
        }
    }

    $label = $kind === 'mileage' ? 'mileage' : 'a purchase';
    logTicketHistory($ticket_id, escapeSql("$who " . ($row ? "changed $label for reimbursement: " : "logged $label for reimbursement: ") . $what));
    logAudit('Ticket', 'Edit', escapeSql("$who " . ($row ? 'changed' : 'logged') . " $label for reimbursement on ticket $reference: $what"), $client_id, $ticket_id);
    return [true, ($row ? 'Saved' : ($kind === 'mileage' ? 'Mileage logged' : 'Purchase logged')) . " - $what." . $note];
}

/** Remove an entry: its trip goes too (it was made for it); a part added to the ticket stays, to bill or remove there. */
function mitReimbDelete(array $row) {
    global $mysqli, $session_name;
    $why = mitReimbWhyLocked($row);
    if ($why !== '') {
        return [false, $why];
    }
    if (intval($row['trip_id'])) {
        mysqli_query($mysqli, "DELETE FROM trips WHERE trip_id = " . intval($row['trip_id']));
    }
    if (!empty($row['receipt'])) {
        @unlink(dirname(__DIR__) . '/' . MIT_REIMB_RECEIPT_DIR . '/' . basename($row['receipt']));
    }
    // Already recorded as an ITFlow expense (approved, then reopened): that expense goes too, with its copy of the receipt
    if (intval($row['expense_id'])) {
        $expense = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT expense_receipt FROM expenses WHERE expense_id = " . intval($row['expense_id'])));
        if ($expense) {
            if (!empty($expense['expense_receipt'])) {
                @unlink(dirname(__DIR__) . '/uploads/expenses/' . basename($expense['expense_receipt']));
            }
            mysqli_query($mysqli, "DELETE FROM expenses WHERE expense_id = " . intval($row['expense_id']));
            logAudit('Expense', 'Delete', escapeSql(mitTsWho() . ' removed the expense of a reimbursed purchase that was taken off a timesheet'), intval($row['client_id']), intval($row['expense_id']));
        }
    }
    mysqli_query($mysqli, "DELETE FROM mit_reimbursements WHERE reimb_id = " . intval($row['reimb_id']));
    $text = stripslashes((string) $session_name) . ' removed ' . ($row['kind'] === 'mileage' ? 'mileage' : 'a purchase') . ' for reimbursement: ' . mitReimbWhat($row) . ' (' . mitMoney($row['amount']) . ')';
    if (intval($row['ticket_id'])) {
        logTicketHistory(intval($row['ticket_id']), escapeSql($text));
    }
    logAudit('Ticket', 'Edit', escapeSql($text), intval($row['client_id']), intval($row['ticket_id']));
    return [true, 'Removed' . (intval($row['part_id']) ? ' - the part it added is still on the ticket.' : '.')];
}

/** Is this ITFlow trip one a reimbursement made (agent/post/trip.php refuses to change or delete it there)? */
function mitReimbTripLocked($trip_id) {
    global $mysqli;
    if (!intval($trip_id) || !mitReimbReady()) {
        return false;
    }
    return mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM mit_reimbursements WHERE trip_id = " . intval($trip_id) . " LIMIT 1")) > 0;
}

/** The words an expense recorded from a purchase carries. */
function mitReimbExpenseDescription(array $row) {
    $user_name = (string) getFieldById('users', intval($row['user_id']), 'user_name');
    return "Reimbursed to $user_name: " . $row['description'] . ($row['vendor'] ? ' (' . $row['vendor'] . ')' : '') . ' - ticket ' . mitReimbTicketText($row);
}

/** A purchase already recorded as an ITFlow expense was changed: the expense's date, amount and words follow it. */
function mitReimbSyncExpense(array $row) {
    global $mysqli;
    mysqli_query($mysqli, "UPDATE expenses SET expense_date = '" . escapeSql($row['entry_date']) . "', expense_amount = " . round((float) $row['amount'], 2) . ",
        expense_description = '" . escapeSql(mitReimbExpenseDescription($row)) . "' WHERE expense_id = " . intval($row['expense_id']));
    logAudit('Expense', 'Edit', escapeSql(mitTsWho() . ' changed a reimbursed purchase - its expense was updated to match'), intval($row['client_id']), intval($row['expense_id']));
}

/**
 * Without timesheet approval, purchases become ITFlow expenses once their period has settled (cron, like leave earned -
 * mitLeaveAccrueEnded): when it locks, or else 7 days after it ends. Returns lines for the log.
 */
function mitReimbRecordEnded() {
    $log = [];
    if (!mitReimbReady() || mitTsSettings()['approval']) {
        return $log;
    }
    $wait = mitTsSettings()['autolock_days'] ?: 7;
    $period = mitTsPeriodShift(mitTsPeriodFor(), -1);
    for ($i = 0; $i < 4; $i++, $period = mitTsPeriodShift($period, -1)) {
        if (date('Y-m-d') <= date('Y-m-d', strtotime($period['end'] . " +$wait days"))) {
            continue;  // still settling
        }
        foreach (mitTsUsers() as $user_id => $user) {
            if (($done = mitReimbRecordExpenses($user_id, $period)) > 0) {
                $log[] = "{$user['user_name']}: $done purchase(s) recorded as expenses for {$period['label']}";
            }
            // No approval step: a settled period's mileage and purchases are paid with payroll here (sheet 0)
            if (($paid = mitReimbPayrollClose($user_id, $period, 0)) !== '') {
                $log[] = "{$user['user_name']}, {$period['label']}: $paid";
            }
        }
    }
    return $log;
}

/**
 * Hook when a timesheet is approved (mitTsDecide): each purchase in the period not recorded yet becomes an ITFlow
 * expense, with a copy of its receipt - when invoicing is on and a category and account are set. Returns how many.
 */
function mitReimbRecordExpenses($user_id, array $period) {
    global $mysqli, $config_module_enable_accounting, $session_company_currency;
    $settings = mitReimbSettings();
    $paid_back = mitReimbItflowPaysBack();
    if (!mitReimbReady() || intval($config_module_enable_accounting ?? 0) !== 1 || !$settings['category_id'] || (!$paid_back && !$settings['account_id'])) {
        return 0;
    }
    $currency = $session_company_currency ?: (string) mysqli_fetch_row(mysqli_query($mysqli, "SELECT company_currency FROM companies WHERE company_id = 1"))[0];
    $done = 0;
    foreach (mitReimbForUsers([$user_id], $period['start'], $period['end'])[intval($user_id)] ?? [] as $row) {
        if ($row['kind'] !== 'purchase') {
            continue;
        }
        // Already an expense - unless that expense was deleted in ITFlow (Expenses), then it is recorded again
        if (intval($row['expense_id']) && mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM expenses WHERE expense_id = " . intval($row['expense_id'])))) {
            continue;
        }
        $receipt = '';
        if (!empty($row['receipt'])) {
            $from = dirname(__DIR__) . '/' . MIT_REIMB_RECEIPT_DIR . '/' . basename($row['receipt']);
            $copy = randomString(32) . '.' . strtolower(pathinfo($row['receipt'], PATHINFO_EXTENSION));
            $receipt = is_file($from) && @copy($from, dirname(__DIR__) . "/uploads/expenses/$copy") ? $copy : '';
        }
        $description = mitReimbExpenseDescription($row);
        mysqli_query($mysqli, "INSERT INTO expenses SET expense_date = '" . escapeSql($row['entry_date']) . "', expense_amount = " . round((float) $row['amount'], 2) . ",
            expense_currency_code = '" . escapeSql($currency) . "', "
            // Paid personally: on no account until ITFlow's Reimburse pays the person back (which needs a vendor)
            . ($paid_back ? "expense_account_id = 0, expense_paid_by = " . intval($user_id) . ", expense_vendor_id = " . mitReimbVendorId($row['vendor'] ?? '') . ","
                : "expense_account_id = " . intval($settings['account_id']) . ", expense_vendor_id = 0,") . "
            expense_client_id = " . intval($row['client_id']) . ", expense_category_id = " . intval($settings['category_id']) . ",
            expense_description = '" . escapeSql($description) . "', expense_reference = 'Reimbursement " . intval($row['reimb_id']) . "'"
            . ($receipt !== '' ? ", expense_receipt = '" . escapeSql($receipt) . "'" : ''));
        $expense_id = mysqli_insert_id($mysqli);
        mysqli_query($mysqli, "UPDATE mit_reimbursements SET expense_id = $expense_id WHERE reimb_id = " . intval($row['reimb_id']));
        logAudit('Expense', 'Create', escapeSql(mitTsWho() . " recorded expense $description from an approved timesheet"), intval($row['client_id']), $expense_id);
        $done++;
    }
    return $done;
}

/*
 * ---------- on the page ----------
 */

/**
 * May the signed-in agent open this entry's receipt (it can show card details)? The person who paid, whoever sees their
 * timesheet (approver, Timesheets view), or someone with Financial access to the client - not everyone on the ticket.
 */
function mitReimbMaySeeReceipt(array $row) {
    global $session_user_id;
    return intval($row['user_id']) === intval($session_user_id) || mitTsMaySee($row['user_id'])
        || (lookupUserPermission('module_financial') >= 1 && (!intval($row['client_id']) || hasClientAccess($row['client_id'])));
}

/** A list of entries as rows (for the ticket box and the timesheet). $show: 'ticket' | 'agent' - what to name besides. */
function mitReimbListHtml(array $rows, $show, $can_change = null) {
    $html = '<div class="list-group list-group-flush">';
    foreach ($rows as $row) {
        $icon = $row['kind'] === 'mileage' ? 'fa-car' : 'fa-shopping-bag';
        // The ticket's subject only for someone who may see that client's tickets (a timesheet viewer may not)
        $subject = ($row['ticket_subject'] ?? '') !== '' && lookupUserPermission('module_support') >= 1 && (!intval($row['client_id']) || hasClientAccess($row['client_id']));
        $by = $show === 'ticket' ? escapeHtml(mitReimbTicketText($row)) . ($subject ? ' · ' . escapeHtml($row['ticket_subject']) : '')
            : escapeHtml($row['user_name'] ?? '');
        $change = $can_change === null ? mitReimbWhyLocked($row) === '' : $can_change;
        $html .= '<div class="list-group-item px-3 py-2"><div class="d-flex align-items-start gap-2"><i class="fas fa-fw ' . $icon . ' text-muted mt-1"></i>'
            . '<div class="flex-grow-1" style="min-width:0"><div class="text-truncate" title="' . escapeHtml(mitReimbWhat($row)) . '">' . escapeHtml(mitReimbWhat($row)) . '</div>'
            . '<div class="small text-muted">' . escapeHtml(date('M j', strtotime($row['entry_date']))) . ' · ' . $by
            . ($row['kind'] === 'mileage' && $row['rate'] !== null ? ' · ' . mitMoney($row['rate']) . '/mi' : '')
            . (!empty($row['receipt']) && mitReimbMaySeeReceipt($row) ? ' · <a href="/agent/mit_receipt.php?id=' . intval($row['reimb_id']) . '" target="_blank" rel="noopener"><i class="fas fa-paperclip me-1"></i>receipt</a>' : '')
            . (($state = mitReimbState($row)) ? ' · <span class="badge text-bg-' . $state[1] . '-subtle text-' . $state[1] . '-emphasis border" data-mit-reimb-state>' . escapeHtml($state[0]) . '</span>'
                : (intval($row['expense_id']) ? ' · <span class="badge text-bg-light border">Expense recorded</span>' : ''))
            . '</div></div><div class="text-end small text-nowrap fw-semibold">' . mitMoney($row['amount']) . '</div>';
        if ($change) {
            $html .= '<button type="button" class="btn btn-sm btn-light ajax-modal" title="Change" data-modal-url="/agent/modals/mit_reimburse/reimburse.php?id=' . intval($row['reimb_id']) . '"><i class="fas fa-pen"></i></button>';
        }
        $html .= '</div></div>';
    }
    return $html . '</div>';
}

/** The ticket's Reimbursements box (agent/ticket.php, side column under Parts). */
function mitReimbTicketCard(array $ticket, $can_edit) {
    if (!mitReimbOn()) {
        return '';
    }
    $ticket_id = intval($ticket['ticket_id']);
    $rows = mitReimbForTicket($ticket_id);
    if (!$rows && !$can_edit) {
        return '';
    }
    $html = '<div class="card mb-3"><div class="card-header px-3 py-2"><h5 class="card-title mt-1"><i class="fas fa-fw fa-receipt me-2"></i>Reimbursements'
        . ($rows ? ' <span class="badge text-bg-light border ms-1">' . count($rows) . '</span>' : '') . '</h5><div class="card-tools">';
    // Paid back through ITFlow: mileage is logged with ITFlow's own "Log a trip" (its Trips box); the timesheet counts those drives
    $itflow_trips = mitReimbItflowPaysBack() && intval($ticket['ticket_client_id'] ?? 0) > 0;
    if ($can_edit) {
        $html .= ($itflow_trips ? '' : '<button type="button" class="btn btn-tool ajax-modal" title="Log mileage" data-modal-url="/agent/modals/mit_reimburse/reimburse.php?ticket_id=' . $ticket_id . '&kind=mileage"><i class="fas fa-car"></i></button>')
            . '<button type="button" class="btn btn-tool ajax-modal" title="Add a purchase" data-modal-url="/agent/modals/mit_reimburse/reimburse.php?ticket_id=' . $ticket_id . '&kind=purchase"><i class="fas fa-shopping-bag"></i></button>';
    }
    $html .= '</div></div><div class="card-body p-0">';
    if (!$rows) {
        return $html . '<div class="px-3 py-3 small text-muted">' . ($itflow_trips ? 'Paid for something for this ticket? Add the purchase to be paid back. Driving goes in Trips (Log a trip, own vehicle).'
            : 'Drove to the client, or paid for something? Log mileage or a purchase to be paid back.') . '</div></div></div>';
    }
    $html .= mitReimbListHtml($rows, 'agent');
    $total = array_sum(array_column($rows, 'amount'));
    return $html . '<div class="px-3 py-2 border-top small text-muted d-flex"><span>Total</span><span class="ms-auto fw-semibold">' . mitMoney($total) . '</span></div></div></div>';
}

/** The person's reimbursements on their timesheet (agent/mit_timesheet.php), with the period's total. */
function mitReimbTimesheetBox($user_id, array $period) {
    if (!mitReimbOn()) {
        return '';
    }
    $rows = mitReimbForUsers([$user_id], $period['start'], $period['end'])[intval($user_id)] ?? [];
    $trips = mitReimbItflowTrips([$user_id], $period['start'], $period['end'])[intval($user_id)] ?? [];
    if (!$rows && !$trips) {
        return '';
    }
    $t = mitReimbTotals([$user_id], $period['start'], $period['end'])[intval($user_id)];
    $itflow = mitReimbItflowPaysBack();
    $trip_html = '';
    foreach ($trips as $trip) {
        $state = mitReimbState(['kind' => 'mileage', 'trip_id' => $trip['trip_id']]);
        $trip_html .= '<div class="list-group-item px-3 py-2" data-mit-itflow-trip><div class="d-flex align-items-start gap-2"><i class="fas fa-fw fa-route text-muted mt-1"></i>'
            . '<div class="flex-grow-1" style="min-width:0"><div class="text-truncate">' . escapeHtml(rtrim(rtrim(number_format((float) $trip['trip_miles'], 1), '0'), '.') . ' mi'
                . (trim((string) $trip['trip_purpose']) !== '' ? ' - ' . $trip['trip_purpose'] : '')) . '</div>'
            . '<div class="small text-muted">' . escapeHtml(date('M j', strtotime($trip['trip_date']))) . ' · logged in Trips · <span class="badge text-bg-' . $state[1] . '-subtle text-' . $state[1] . '-emphasis border">' . escapeHtml($state[0]) . '</span></div></div>'
            . '<div class="text-end small text-nowrap fw-semibold">' . mitMoney($trip['value']) . '</div></div></div>';
    }
    return '<div class="card mb-3"><div class="card-header px-3 py-2"><h5 class="card-title mt-1"><i class="fas fa-fw fa-receipt me-2"></i>Reimbursements</h5>'
        . '<div class="card-tools small text-muted pt-1">' . ($t['miles'] ? rtrim(rtrim(number_format($t['miles'], 1), '0'), '.') . ' mi · ' : '')
        . '<strong>' . mitMoney($t['total']) . '</strong></div></div>'
        . '<div class="card-body p-0">' . ($rows ? mitReimbListHtml($rows, 'ticket') : '') . ($trip_html !== '' ? '<div class="list-group list-group-flush">' . $trip_html . '</div>' : '') . '</div>'
        . '<div class="card-footer small text-muted py-2" data-mit-reimb-footer>' . (mitReimbViaPayroll()
            ? 'Paid with payroll for this period - marked paid when it is approved, and locked with it.'
            : ($itflow ? 'Paid back through ITFlow - mileage from Trips > To pay back, purchases from Expenses > Reimburse - not with payroll. Locked when the period is approved.'
            : 'Logged on tickets. Paid with this period and locked when it is approved.')) . '</div></div>';
}
