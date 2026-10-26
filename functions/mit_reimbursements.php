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
 * Pages: the Mileage & purchases box on the ticket (mitReimbTicketCard, layout box "reimburse", icon fa-receipt - joined into ITFlow's Trips box),
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
 * through payroll (mitReimbPayrollClose) so they never wait in Trips > To pay back or Expenses > To reimburse.
 */
function mitReimbViaPayroll() {
    return mitReimbItflowPaysBack() && (string) mitSetting('ts_payback', 'payroll') !== 'itflow';
}

/** The reference a payroll close-off writes on ITFlow's reimbursement - found again by reopen. */
function mitReimbPayrollReference($sheet_id, array $period) {
    return 'Payroll - timesheet ' . intval($sheet_id) . ' (' . $period['label'] . ')';
}

/**
 * Run $fn with the client and Financial limits of the signed-in agent lifted: paying a period back must cover everything in it
 * (ITFlow's tripClaim / expenseReimburse read through the agent's trip and client scope - an approver who can't see every
 * client, or cron with no session, would quietly pay part of it).
 */
function mitReimbAsAdmin(callable $fn) {
    global $session_is_admin;
    $was = $session_is_admin ?? null;
    $session_is_admin = true;
    try {
        return $fn();
    } finally {
        $session_is_admin = $was;
    }
}

/**
 * Why approving this period can't mark its mileage and purchases paid through payroll - '' when it can (or there is nothing,
 * or payroll doesn't pay them). Asked before anything is written, so a period is never left half paid.
 */
function mitReimbPayrollProblem($user_id, array $period) {
    if (!mitReimbViaPayroll()) {
        return '';
    }
    $items = mitReimbItems([$user_id], $period['start'], $period['end'])[intval($user_id)] ?? [];
    // What approval will pay: owed and not paid, in ITFlow's paying back (trips, expenses, purchases about to be recorded)
    $unpaid = array_filter($items, function ($item) {
        return $item['owed'] && $item['paid'] === '' && ($item['source'] !== 'entry' || $item['kind'] === 'purchase');
    });
    if (!$unpaid) {
        return '';
    }
    $settings = mitReimbSettings();
    if (!$settings['account_id'] || !isset(mitReimbAsAdmin('paymentAccounts')[$settings['account_id']])) {
        return 'This timesheet has mileage or purchases to pay with payroll - pick the account payroll pays from first (Admin > Timesheets > Settings, Reimbursements).';
    }
    foreach ($unpaid as $item) {
        if ($item['source'] === 'trip' && !$item['claimed'] && $settings['rate'] <= 0) {
            return 'This timesheet has trips to pay with payroll - set the mileage rate first (Admin > Timesheets > Settings, Reimbursements).';
        }
    }
    return '';
}

/**
 * Approving a period (or, with approval off, once it has settled): what the person paid personally in it is paid through payroll -
 * exactly the list on their timesheet and the payroll report (mitReimbItems): their own-vehicle trips claimed as mileage expenses
 * at the rate (ITFlow's tripClaim), then those and their paid-personally expenses reimbursed in one go from the payroll account
 * (ITFlow's expenseReimburse, reference mitReimbPayrollReference). The mileage expenses made here are remembered (mit_settings
 * ts_payroll_made_<reimbursement>) so reopening removes only those. Returns a line for the message ('' when there was nothing).
 */
function mitReimbPayrollClose($user_id, array $period, $sheet_id) {
    $user_id = intval($user_id);
    if (!mitReimbViaPayroll() || !function_exists('tripClaim') || !function_exists('expenseReimburse')) {
        return '';
    }
    return mitReimbAsAdmin(function () use ($user_id, $period, $sheet_id) {
        $settings = mitReimbSettings();
        $by = mitTsWho();
        $items = mitReimbItems([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
        $note = [];
        $made = [];
        // Mileage: the period's own-vehicle trips not paid yet become one mileage expense per client, dated the period's end
        $trip_ids = [];
        foreach ($items as $item) {
            if ($item['source'] === 'trip' && !$item['claimed']) {
                $trip_ids[] = $item['trip_id'];
            }
        }
        if ($trip_ids) {
            $error = '';
            $claim = $settings['rate'] > 0 ? tripClaim($user_id, $trip_ids, ['rate' => $settings['rate'], 'date' => $period['end'], 'by' => $by], $error) : null;
            if ($claim) {
                $made = array_map('intval', $claim['expense_ids']);
            } else {
                $note[] = 'mileage not paid - ' . ($settings['rate'] > 0 ? $error : 'no mileage rate set');
            }
        }
        // Everything they paid personally in the period and not paid back yet (the mileage just claimed included)
        $expense_ids = [];
        foreach (mitReimbItems([$user_id], $period['start'], $period['end'])[$user_id] ?? [] as $item) {
            if ($item['owed'] && $item['paid'] === '' && $item['expense_id']) {
                $expense_ids[] = $item['expense_id'];
            }
        }
        $expense_ids = array_values(array_unique($expense_ids));
        if (!$expense_ids) {
            return $note ? 'Reimbursements: ' . implode('; ', $note) : '';
        }
        $error = '';
        $done = expenseReimburse($user_id, $expense_ids, ['account_id' => $settings['account_id'], 'date' => date('Y-m-d'),
            'reference' => mitReimbPayrollReference($sheet_id, $period), 'by' => $by], $error);
        if (!$done) {
            return "Reimbursements not marked paid - $error";
        }
        if ($made) {
            mitSetSetting('ts_payroll_made_' . intval($done['reimbursement_id']), json_encode($made));
        }
        return 'Reimbursements ' . billingMoney($done['amount'], $done['currency']) . ' paid through payroll' . ($note ? ' (' . implode('; ', $note) . ')' : '');
    });
}

/** The payroll reimbursements of a period (newest first) - reimbursement rows. */
function mitReimbPayrollRows($user_id, array $period, $sheet_id) {
    global $mysqli;
    return mysqli_fetch_all(mysqli_query($mysqli, "SELECT reimbursement_id, reimbursement_expense_id FROM reimbursements WHERE reimbursement_user_id = " . intval($user_id) . "
        AND reimbursement_reference = '" . escapeSql(mitReimbPayrollReference($sheet_id, $period)) . "' ORDER BY reimbursement_id DESC"), MYSQLI_ASSOC);
}

/** Why reopening can't undo the period's payroll payment - '' when it can (its money out is on a reconciled statement). */
function mitReimbPayrollUndoProblem($user_id, array $period, $sheet_id) {
    if (!mitReimbItflowPaysBack() || !function_exists('reconcileCheckDelete')) {
        return '';
    }
    foreach (mitReimbPayrollRows($user_id, $period, $sheet_id) as $row) {
        $error = '';
        if (!reconcileCheckDelete('expense', $row['reimbursement_expense_id'], $error)) {
            return 'Its mileage and purchases were paid through payroll and that payment is on a reconciled statement - it can\'t be reopened. (' . $error . ')';
        }
    }
    return '';
}

/**
 * Reopening a period: its payroll payment undone - the reimbursement removed (ITFlow's reimbursementDelete), the mileage expenses
 * that approval made deleted (their trips wait again); expenses made otherwise (an accountant's Pay Mileage) stay, unpaid.
 */
function mitReimbPayrollUndo($user_id, array $period, $sheet_id) {
    global $mysqli;
    if (!mitReimbItflowPaysBack() || !function_exists('reimbursementDelete')) {
        return '';
    }
    $rows = mitReimbPayrollRows($user_id, $period, $sheet_id);
    if (!$rows) {
        return '';
    }
    $GLOBALS['mit_reimb_undoing'] = true;
    try {
        return mitReimbAsAdmin(function () use ($rows, $mysqli) {
            foreach ($rows as $row) {
                $id = intval($row['reimbursement_id']);
                $expense_ids = array_map('intval', array_column(reimbursementExpenses($id), 'expense_id'));
                $made = json_decode((string) mitSetting("ts_payroll_made_$id", ''), true);
                $error = '';
                if (!reimbursementDelete($id, $error)) {
                    return "Its payroll reimbursement stays - $error";
                }
                mitSetSetting("ts_payroll_made_$id", '');
                // Paid before 26.10.143, when the made ones were not remembered: the mileage expenses with trips on them, as then
                if (!is_array($made)) {
                    $made = array_values(array_filter($expense_ids, function ($expense_id) use ($mysqli) {
                        return mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM trips WHERE trip_expense_id = $expense_id")) > 0;
                    }));
                }
                foreach (array_intersect(array_map('intval', $made), $expense_ids) as $expense_id) {
                    $deleted = expenseDelete($expense_id, $error);
                    if ($deleted) {
                        logAudit('Expense', 'Delete', escapeSql(mitTsWho() . ' reopened a timesheet - its mileage expense ' . expenseLabel($deleted) . ' was removed and the trips wait again'), intval($deleted['expense_client_id']));
                    }
                }
            }
            return 'its mileage and purchases are waiting for payroll again';
        });
    } finally {
        unset($GLOBALS['mit_reimb_undoing']);
    }
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

/**
 * Everything people paid personally in dates and are paid back for - one list for the timesheet, the payroll report, the ticket
 * badges and approval (mitReimbPayrollClose pays exactly what is owed here): user_id => [items, by date]. Each item:
 *   source  'trip' - an own-vehicle trip in ITFlow (logged there with Log a trip, or by our mileage form); valued at the amount its
 *                    mileage expense paid once claimed, else miles x the rate (mitReimbSettings);
 *           'expense' - an ITFlow expense paid personally by them (expense_paid_by, with a vendor - what ITFlow can pay back): our
 *                    purchases once recorded, or one an accountant entered;
 *           'entry' - one of our entries not in ITFlow's paying back: a purchase not recorded as an expense yet (before approval),
 *                    or mileage from before 26.11 (no own-vehicle trip) - paid with payroll as it always was.
 *   kind 'mileage' | 'purchase', date, what, where, miles, amount, trip_id, expense_id, claimed, entry (our row or null),
 *   paid (date, or ''), via ('payroll' | 'itflow' | ''), owed (counts in the totals - false when ITFlow paid it back outside
 *   payroll, in payroll mode), state [label, tone].
 */
function mitReimbItems(array $user_ids, $from, $to) {
    global $mysqli;
    $user_ids = array_values(array_filter(array_map('intval', $user_ids)));
    $out = [];
    if (!$user_ids || !mitReimbReady()) {
        return $out;
    }
    $in = implode(',', $user_ids);
    $range = "BETWEEN '" . escapeSql($from) . "' AND '" . escapeSql($to) . "'";
    $itflow = mitReimbItflowPaysBack();
    $rate = mitReimbSettings()['rate'];
    $entries = mitReimbForUsers($user_ids, $from, $to);
    $by_trip = [];
    $by_expense = [];
    // Our entries whose trip or expense falls in other dates still belong to that trip / expense
    $linked = mysqli_query($mysqli, "SELECT " . MIT_REIMB_COLUMNS . ", ticket_prefix, ticket_number, ticket_subject FROM mit_reimbursements r
        LEFT JOIN tickets ON tickets.ticket_id = r.ticket_id WHERE r.user_id IN ($in) AND (r.trip_id IS NOT NULL OR r.expense_id IS NOT NULL)");
    while ($row = mysqli_fetch_assoc($linked)) {
        if (intval($row['trip_id'])) {
            $by_trip[intval($row['trip_id'])] = $row;
        }
        if (intval($row['expense_id'])) {
            $by_expense[intval($row['expense_id'])] = $row;
        }
    }
    $seen_trips = [];
    $seen_expenses = [];
    if ($itflow) {
        $trips = mysqli_query($mysqli, "SELECT t.trip_id, t.trip_date, t.trip_purpose, t.trip_source, t.trip_destination, t.trip_miles, t.round_trip, t.trip_user_id,
                t.trip_expense_id, tk.ticket_id, tk.ticket_prefix, tk.ticket_number, tk.ticket_subject, claim.expense_id AS claim_id, claim.expense_amount AS claim_amount,
                (SELECT SUM(t2.trip_miles) FROM trips t2 WHERE t2.trip_expense_id = t.trip_expense_id AND t.trip_expense_id > 0) AS claim_miles,
                r.reimbursement_date, r.reimbursement_reference
            FROM trips t LEFT JOIN tickets tk ON tk.ticket_id = t.trip_ticket_id
            LEFT JOIN expenses claim ON t.trip_expense_id > 0 AND claim.expense_id = t.trip_expense_id
            LEFT JOIN reimbursements r ON r.reimbursement_id = claim.expense_reimbursement_id
            WHERE t.trip_own_vehicle = 1 AND t.trip_archived_at IS NULL AND t.trip_miles > 0 AND t.trip_user_id IN ($in) AND t.trip_date $range");
        while ($t = mysqli_fetch_assoc($trips)) {
            $claimed = $t['claim_id'] !== null;
            $miles = (float) $t['trip_miles'];
            $amount = $claimed && (float) $t['claim_miles'] > 0 ? round((float) $t['claim_amount'] * $miles / (float) $t['claim_miles'], 2) : round($miles * $rate, 2);
            $entry = $by_trip[intval($t['trip_id'])] ?? null;
            $seen_trips[intval($t['trip_id'])] = true;
            $out[intval($t['trip_user_id'])][] = mitReimbItemFinish([
                'source' => 'trip', 'kind' => 'mileage', 'date' => $t['trip_date'], 'user_id' => intval($t['trip_user_id']),
                'what' => rtrim(rtrim(number_format($miles, 1), '0'), '.') . ' mi' . (intval($t['round_trip']) ? ' round trip' : '')
                    . (trim($t['trip_source'] . $t['trip_destination']) !== '' ? ', ' . trim($t['trip_source'] . ' - ' . $t['trip_destination'], ' -') : ''),
                'where' => $t['ticket_number'] !== null ? ticketReference($t) . ' · ' . $t['ticket_subject'] : (trim((string) $t['trip_purpose']) ?: 'Trips'),
                'ticket_id' => intval($t['ticket_id']), 'miles' => $miles, 'amount' => $amount, 'trip_id' => intval($t['trip_id']),
                'expense_id' => $claimed ? intval($t['claim_id']) : 0, 'claimed' => $claimed, 'entry' => $entry,
                'paid' => (string) $t['reimbursement_date'], 'reference' => (string) $t['reimbursement_reference'],
            ]);
        }
        $expenses = mysqli_query($mysqli, "SELECT e.expense_id, e.expense_date, e.expense_amount, e.expense_description, e.expense_paid_by, e.expense_client_id,
                v.vendor_name, r.reimbursement_date, r.reimbursement_reference
            FROM expenses e LEFT JOIN vendors v ON v.vendor_id = e.expense_vendor_id LEFT JOIN reimbursements r ON r.reimbursement_id = e.expense_reimbursement_id
            WHERE e.expense_paid_by IN ($in) AND e.expense_vendor_id > 0 AND e.expense_archived_at IS NULL AND e.expense_date $range
                AND NOT EXISTS (SELECT 1 FROM trips WHERE trips.trip_expense_id = e.expense_id)");
        while ($e = mysqli_fetch_assoc($expenses)) {
            $entry = $by_expense[intval($e['expense_id'])] ?? null;
            $seen_expenses[intval($e['expense_id'])] = true;
            $out[intval($e['expense_paid_by'])][] = mitReimbItemFinish([
                'source' => 'expense', 'kind' => 'purchase', 'date' => $e['expense_date'], 'user_id' => intval($e['expense_paid_by']),
                'what' => $entry ? mitReimbWhat($entry) : trim(strtok((string) $e['expense_description'], "\n")) . ($e['vendor_name'] ? ' (' . $e['vendor_name'] . ')' : ''),
                'where' => $entry ? mitReimbTicketText($entry) . (($entry['ticket_subject'] ?? '') !== '' ? ' · ' . $entry['ticket_subject'] : '') : 'Entered in Expenses',
                'ticket_id' => $entry ? intval($entry['ticket_id']) : 0, 'miles' => 0.0, 'amount' => round((float) $e['expense_amount'], 2), 'trip_id' => 0,
                'expense_id' => intval($e['expense_id']), 'claimed' => true, 'entry' => $entry,
                'paid' => (string) $e['reimbursement_date'], 'reference' => (string) $e['reimbursement_reference'],
            ]);
        }
    }
    // Our entries not in ITFlow's paying back: purchases not recorded yet, mileage with no own-vehicle trip (from before 26.11)
    foreach ($entries as $user_id => $rows) {
        foreach ($rows as $row) {
            if (($row['kind'] === 'mileage' && isset($seen_trips[intval($row['trip_id'])])) || ($row['kind'] === 'purchase' && isset($seen_expenses[intval($row['expense_id'])]))) {
                continue;
            }
            // A purchase whose expense exists but is not one ITFlow pays back (no vendor, archived) - shown, as an entry
            $out[$user_id][] = mitReimbItemFinish([
                'source' => 'entry', 'kind' => $row['kind'], 'date' => $row['entry_date'], 'user_id' => intval($user_id), 'what' => mitReimbWhat($row),
                'where' => mitReimbTicketText($row) . (($row['ticket_subject'] ?? '') !== '' ? ' · ' . $row['ticket_subject'] : ''),
                'ticket_id' => intval($row['ticket_id']), 'miles' => $row['kind'] === 'mileage' ? (float) $row['miles'] : 0.0, 'amount' => round((float) $row['amount'], 2),
                'trip_id' => intval($row['trip_id']), 'expense_id' => 0, 'claimed' => false, 'entry' => $row, 'paid' => '', 'reference' => '',
            ]);
        }
    }
    foreach ($out as &$items) {
        usort($items, function ($a, $b) {
            return [$a['date'], $a['kind'], $a['what']] <=> [$b['date'], $b['kind'], $b['what']];
        });
    }
    unset($items);
    return $out;
}

/** An item's state: who pays it and whether it is paid - [label, tone] in 'state', and 'owed' / 'via'. */
function mitReimbItemFinish(array $item) {
    $payroll = mitReimbViaPayroll();
    $paid_date = $item['paid'] !== '' ? date('M j', strtotime($item['paid'])) : '';
    $by_payroll = $item['paid'] !== '' && strpos($item['reference'], 'Payroll') === 0;
    $item['via'] = $item['paid'] === '' ? '' : ($by_payroll ? 'payroll' : 'itflow');
    $item['owed'] = true;
    if ($item['paid'] !== '') {
        // Paid back in ITFlow outside payroll: not payroll's to pay (in payroll mode it is left out of the totals)
        $item['owed'] = !$payroll || $by_payroll;
        $item['state'] = $by_payroll ? ["Paid through payroll $paid_date", 'success'] : ["Paid back in ITFlow $paid_date", 'success'];
        return $item;
    }
    $approved = mitReimbSheetStatusOn($item['user_id'], $item['date']) === 'approved';
    if ($item['source'] === 'entry' && ($item['kind'] === 'mileage' || !mitReimbItflowPaysBack())) {
        // Not in ITFlow's paying back - paid with payroll, as before 26.11
        $item['state'] = $approved ? ['Paid with payroll', 'success'] : ['Goes to payroll', 'secondary'];
    } elseif ($payroll) {
        $item['state'] = $approved ? ['Approved - not marked paid', 'warning'] : ['Goes to payroll', 'secondary'];
    } elseif ($item['source'] === 'trip' && !$item['claimed']) {
        $item['state'] = ['Waiting in Trips > To pay back', 'warning'];
    } elseif ($item['source'] === 'entry') {
        $item['state'] = [mitTsSettings()['approval'] ? 'An expense once approved' : 'An expense once the period ends', 'secondary'];
    } else {
        $item['state'] = ['Waiting in Expenses > To reimburse', 'warning'];
    }
    return $item;
}

/** The status of the timesheet holding a person's day ('' when none) - read once per person and dates. */
function mitReimbSheetStatusOn($user_id, $date) {
    static $cache = [];
    $key = intval($user_id) . '|' . substr((string) $date, 0, 7);
    if (!isset($cache[$key])) {
        $start = substr((string) $date, 0, 7) . '-01';
        $cache[$key] = mitTsLockedDates($user_id, date('Y-m-d', strtotime("$start -1 month")), date('Y-m-t', strtotime("$start +1 month")), ['open', 'submitted', 'returned', 'approved']);
    }
    return $cache[$key][$date] ?? '';
}

/** Totals for people in dates: user_id => [miles, mileage, purchases, total, count, elsewhere] - what is owed (mitReimbItems). */
function mitReimbTotals(array $user_ids, $from, $to) {
    $totals = [];
    foreach (array_filter(array_map('intval', $user_ids)) as $user_id) {
        $totals[$user_id] = ['miles' => 0.0, 'mileage' => 0.0, 'purchases' => 0.0, 'total' => 0.0, 'count' => 0, 'elsewhere' => 0.0];
    }
    foreach (mitReimbItems($user_ids, $from, $to) as $user_id => $items) {
        foreach ($items as $item) {
            $t = &$totals[$user_id];
            if (!$item['owed']) {
                $t['elsewhere'] += $item['amount'];
                unset($t);
                continue;
            }
            if ($item['kind'] === 'mileage') {
                $t['miles'] += $item['miles'];
                $t['mileage'] += $item['amount'];
            } else {
                $t['purchases'] += $item['amount'];
            }
            $t['total'] += $item['amount'];
            $t['count']++;
            unset($t);
        }
    }
    return $totals;
}

/** Where one of our entries is in being paid back ([label, tone]) - the same as its line on the timesheet. */
function mitReimbState(array $row) {
    $date = (string) $row['entry_date'];
    foreach (mitReimbItems([intval($row['user_id'])], $date, $date)[intval($row['user_id'])] ?? [] as $item) {
        if (($item['entry']['reimb_id'] ?? 0) == $row['reimb_id']) {
            return $item['state'];
        }
    }
    // Its trip or expense is dated elsewhere - read from that
    foreach (mitReimbItems([intval($row['user_id'])], '1970-01-01', '2999-12-31')[intval($row['user_id'])] ?? [] as $item) {
        if (($item['entry']['reimb_id'] ?? 0) == $row['reimb_id']) {
            return $item['state'];
        }
    }
    return null;
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

/** '' or why an entry is settled: its trip claimed for mileage, or its expense paid back (through payroll or in ITFlow). */
function mitReimbPaidBack(array $row) {
    global $mysqli;
    if (!mitReimbItflowPaysBack()) {
        return '';
    }
    if (intval($row['trip_id'] ?? 0) && intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT trip_expense_id FROM trips WHERE trip_id = " . intval($row['trip_id'])))[0] ?? 0)) {
        return 'Its mileage has been paid (Trips) - it can no longer change.';
    }
    if (intval($row['expense_id'] ?? 0) && intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT expense_reimbursement_id FROM expenses WHERE expense_id = " . intval($row['expense_id'])))[0] ?? 0)) {
        return 'It has been paid back - it can no longer change.';
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

/**
 * Why ITFlow may not change or delete this trip now (agent/post/trip.php) - '' when it may. An own-vehicle trip is money owed on
 * the driver's timesheet: its day on a submitted or approved timesheet is locked (before and after the change - date, driver,
 * own vehicle), and one paid through payroll stays as it was paid. $new: the posted trip (date, driver, own_vehicle), or [] to
 * delete. $trip_id 0 = a new trip.
 */
function mitReimbTripWhyLocked($trip_id, array $new = []) {
    global $mysqli;
    if (!mitReimbReady() || !mitReimbItflowPaysBack()) {
        return '';
    }
    $old = intval($trip_id) ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT t.trip_date, t.trip_user_id, t.trip_own_vehicle, r.reimbursement_reference
        FROM trips t LEFT JOIN expenses e ON t.trip_expense_id > 0 AND e.expense_id = t.trip_expense_id LEFT JOIN reimbursements r ON r.reimbursement_id = e.expense_reimbursement_id
        WHERE t.trip_id = " . intval($trip_id))) : null;
    $checks = [];
    if ($old && intval($old['trip_own_vehicle'])) {
        if (strpos((string) $old['reimbursement_reference'], 'Payroll') === 0) {
            return 'This trip was paid through payroll with a timesheet - reopen that timesheet to change it.';
        }
        $checks[] = [intval($old['trip_user_id']), $old['trip_date']];
    }
    if ($new) {
        $own = isset($new['own_vehicle']) ? !empty($new['own_vehicle']) : ($old ? intval($old['trip_own_vehicle']) === 1 : false);
        if ($own) {
            $checks[] = [intval($new['driver'] ?? ($old['trip_user_id'] ?? 0)), (string) ($new['date'] ?? ($old['trip_date'] ?? ''))];
        }
    }
    foreach ($checks as [$user_id, $date]) {
        if ($user_id && calendarIsDate($date) && ($held = mitTsLockedDates($user_id, $date, $date, ['submitted', 'approved']))) {
            $name = (string) getFieldById('users', $user_id, 'user_name');
            return 'Driving in their own vehicle on ' . date('M j', strtotime($date)) . ' is on ' . ($name !== '' ? "$name's" : 'their') . ' timesheet, which is '
                . $held[$date] . ' - ' . ($held[$date] === 'approved' ? 'reopen it' : 'send it back') . ' to change trips on that day.';
        }
    }
    return '';
}

/** Why ITFlow may not change or delete this expense (functions/expenses.php expenseSave / expenseDelete): paid through payroll. */
function mitReimbExpenseWhyLocked(array $expense) {
    global $mysqli;
    if (!empty($GLOBALS['mit_reimb_undoing']) || !intval($expense['expense_reimbursement_id'] ?? 0)) {
        return '';
    }
    $reference = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT reimbursement_reference FROM reimbursements WHERE reimbursement_id = " . intval($expense['expense_reimbursement_id'])))[0] ?? '');
    return strpos($reference, 'Payroll') === 0 ? 'It was paid through payroll with a timesheet (' . $reference . ') - reopen that timesheet to change it.' : '';
}

/** Why ITFlow may not undo this reimbursement (functions/expenses.php reimbursementDelete): a timesheet's payroll payment is undone by reopening it. */
function mitReimbReimbursementWhyLocked(array $row) {
    if (!empty($GLOBALS['mit_reimb_undoing']) || strpos((string) ($row['reimbursement_reference'] ?? ''), 'Payroll') !== 0) {
        return '';
    }
    return 'This was paid through payroll with a timesheet - reopen that timesheet (Timesheets) to undo it.';
}

/** The words an expense recorded from a purchase carries. */
function mitReimbExpenseDescription(array $row) {
    $user_name = (string) getFieldById('users', intval($row['user_id']), 'user_name');
    return (mitReimbItflowPaysBack() ? "Paid by $user_name: " : "Reimbursed to $user_name: ") . $row['description'] . ($row['vendor'] ? ' (' . $row['vendor'] . ')' : '') . ' - ticket ' . mitReimbTicketText($row);
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
            // No approval step: a settled period's mileage and purchases are paid with payroll here (sheet 0) - once; anything
            // dated into it later waits for the person's next timesheet to show it (it stays "Goes to payroll" there)
            $done_key = 'ts_payroll_closed_' . intval($user_id) . '_' . $period['start'];
            if (!mitReimbViaPayroll() || mitSetting($done_key, '') !== '') {
                continue;
            }
            if (($why = mitReimbPayrollProblem($user_id, $period)) !== '') {
                if (mitSetting("{$done_key}_problem", '') !== date('Y-m-d')) {
                    mitSetSetting("{$done_key}_problem", date('Y-m-d'));
                    $log[] = "{$user['user_name']}, {$period['label']}: $why";
                }
                continue;
            }
            mitSetSetting($done_key, date('Y-m-d'));
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
    // Paid back through ITFlow (payroll or its own To reimburse) every purchase becomes an expense - that is what is paid back, so a
    // category is not needed (none = uncategorised) and the Accounting switch only hides the pages. Otherwise only as set.
    if (!mitReimbReady() || (!$paid_back && (intval($config_module_enable_accounting ?? 0) !== 1 || !$settings['category_id'] || !$settings['account_id']))) {
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
            expense_description = '" . escapeSql($description) . "', expense_reference = 'Ticket purchase " . intval($row['reimb_id']) . "'"
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
        $html .= '<div class="list-group-item px-3 py-2"' . (intval($row['trip_id'] ?? 0) ? ' data-mit-reimb-trip="' . intval($row['trip_id']) . '"' : '') . '><div class="d-flex align-items-start gap-2"><i class="fas fa-fw ' . $icon . ' text-muted mt-1"></i>'
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

/**
 * The ticket's Mileage & purchases box (agent/ticket.php, side column under Parts). When mileage is ITFlow's (Log a trip) and the
 * ticket has ITFlow's Trips box, agent/js/mit_ticket_layout.js moves the purchases into that box ([data-mit-reimb-card] ->
 * .itflow-ticket-trips) - one box for the ticket's driving and purchases. With nothing logged there is no box: purchases are
 * added from the ticket's ⋮ menu (mitReimbMenuItem).
 */
function mitReimbTicketCard(array $ticket, $can_edit) {
    if (!mitReimbOn()) {
        return '';
    }
    $ticket_id = intval($ticket['ticket_id']);
    $rows = mitReimbForTicket($ticket_id);
    // Paid back through ITFlow: mileage is logged with ITFlow's own "Log a trip" (its Trips box); the timesheet counts those drives
    $itflow_trips = mitReimbItflowPaysBack() && intval($ticket['ticket_client_id'] ?? 0) > 0;
    if (!$rows && !$can_edit) {
        return '';
    }
    $add = $can_edit ? '<button type="button" class="btn btn-tool ajax-modal" title="Add a purchase" data-mit-reimb-add data-modal-url="/agent/modals/mit_reimburse/reimburse.php?ticket_id=' . $ticket_id . '&kind=purchase"><i class="fas fa-shopping-bag"></i></button>' : '';
    if (!$rows && $itflow_trips) {
        // Nothing yet: no box of its own - only the button, which the layout script puts in ITFlow's Trips box (if shown)
        return '<div hidden data-mit-reimb-card>' . $add . '</div>';
    }
    $html = '<div class="card mb-3" data-mit-reimb-card><div class="card-header px-3 py-2"><h5 class="card-title mt-1"><i class="fas fa-fw fa-receipt me-2"></i>Mileage &amp; purchases'
        . ($rows ? ' <span class="badge text-bg-light border ms-1">' . count($rows) . '</span>' : '') . '</h5><div class="card-tools">';
    if ($can_edit && !$itflow_trips) {
        $html .= '<button type="button" class="btn btn-tool ajax-modal" title="Log mileage" data-modal-url="/agent/modals/mit_reimburse/reimburse.php?ticket_id=' . $ticket_id . '&kind=mileage"><i class="fas fa-car"></i></button>';
    }
    $html .= $add . '</div></div><div class="card-body p-0" data-mit-reimb-body>';
    if (!$rows) {
        return $html . '<div class="px-3 py-3 small text-muted">Drove to the client, or paid for something? Log mileage or a purchase to be paid back.</div></div></div>';
    }
    $html .= mitReimbListHtml($rows, 'agent');
    $total = array_sum(array_column($rows, 'amount'));
    return $html . '<div class="px-3 py-2 border-top small text-muted d-flex"><span>' . ($itflow_trips ? (in_array('mileage', array_column($rows, 'kind'), true) ? 'Logged for pay back' : 'Purchases') : 'Total') . '</span><span class="ms-auto fw-semibold">' . mitMoney($total) . '</span></div></div></div>';
}

/** One line of the list (timesheet): what, when, where it came from, its state and amount - and Change for our own entries. */
function mitReimbItemHtml(array $item) {
    $entry = $item['entry'];
    $icon = $item['kind'] === 'mileage' ? ($item['source'] === 'trip' ? 'fa-route' : 'fa-car') : 'fa-shopping-bag';
    $from = $item['source'] === 'trip' && !$entry ? ' · logged in Trips' : '';
    $html = '<div class="list-group-item px-3 py-2' . ($item['owed'] ? '' : ' text-body-secondary') . '" data-mit-reimb-item="' . $item['source'] . '"><div class="d-flex align-items-start gap-2">'
        . '<i class="fas fa-fw ' . $icon . ' text-muted mt-1"></i><div class="flex-grow-1" style="min-width:0">'
        . '<div class="text-truncate" title="' . escapeHtml($item['what']) . '">' . escapeHtml($item['what']) . '</div>'
        . '<div class="small text-muted">' . escapeHtml(date('M j', strtotime($item['date']))) . ' · ' . escapeHtml($item['where']) . $from
        . ($entry && !empty($entry['receipt']) && mitReimbMaySeeReceipt($entry) ? ' · <a href="/agent/mit_receipt.php?id=' . intval($entry['reimb_id']) . '" target="_blank" rel="noopener"><i class="fas fa-paperclip me-1"></i>receipt</a>' : '')
        . ' · <span class="badge text-bg-' . $item['state'][1] . '-subtle text-' . $item['state'][1] . '-emphasis border" data-mit-reimb-state>' . escapeHtml($item['state'][0]) . '</span>'
        . '</div></div><div class="text-end small text-nowrap fw-semibold">' . ($item['owed'] ? '' : '<s>') . mitMoney($item['amount']) . ($item['owed'] ? '' : '</s>') . '</div>';
    if ($entry && mitReimbWhyLocked($entry) === '') {
        $html .= '<button type="button" class="btn btn-sm btn-light ajax-modal" title="Change" data-modal-url="/agent/modals/mit_reimburse/reimburse.php?id=' . intval($entry['reimb_id']) . '"><i class="fas fa-pen"></i></button>';
    }
    return $html . '</div></div>';
}

/** The person's mileage and purchases on their timesheet (agent/mit_timesheet.php): the period's list (mitReimbItems) and what is owed. */
function mitReimbTimesheetBox($user_id, array $period) {
    if (!mitReimbOn()) {
        return '';
    }
    $items = mitReimbItems([$user_id], $period['start'], $period['end'])[intval($user_id)] ?? [];
    if (!$items) {
        return '';
    }
    $t = mitReimbTotals([$user_id], $period['start'], $period['end'])[intval($user_id)];
    $payroll = mitReimbViaPayroll();
    $approval = mitTsSettings()['approval'];
    $html = '';
    foreach ($items as $item) {
        $html .= mitReimbItemHtml($item);
    }
    if ($payroll) {
        $footer = 'Paid with payroll for this period - marked paid when ' . ($approval ? 'it is approved' : 'the period has ended') . ', and locked with it.'
            . ($t['elsewhere'] > 0 ? ' Crossed out: already paid back in ITFlow, so not in the total.' : '');
    } elseif (mitReimbItflowPaysBack()) {
        $footer = 'Paid back through ITFlow - mileage from Trips > To pay back, purchases from Expenses > To reimburse - not with payroll. Locked when the period is approved.';
    } else {
        $footer = 'Logged on tickets. Paid with this period and locked when it is approved.';
    }
    return '<div class="card mb-3"><div class="card-header px-3 py-2"><h5 class="card-title mt-1"><i class="fas fa-fw fa-receipt me-2"></i>Mileage and purchases</h5>'
        . '<div class="card-tools small text-muted pt-1">' . ($t['miles'] ? rtrim(rtrim(number_format($t['miles'], 1), '0'), '.') . ' mi · ' : '')
        . '<strong data-mit-reimb-total>' . mitMoney($t['total']) . '</strong></div></div>'
        . '<div class="card-body p-0"><div class="list-group list-group-flush">' . $html . '</div></div>'
        . '<div class="card-footer small text-muted py-2" data-mit-reimb-footer>' . $footer . '</div></div>';
}
