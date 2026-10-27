<?php
/*
 * Mileage and purchases paid with payroll (ts_payback payroll): our mileage form and purchase form on a ticket, an own-vehicle trip
 * logged in ITFlow's Trips and an expense paid personally entered in ITFlow's Expenses - the timesheet totals, then approval (one
 * mileage expense at the rate, one reimbursement of everything, reference "Payroll - timesheet ..."), the locks in ITFlow's Trips /
 * Expenses / reimbursements, reopen (all undone, trips waiting again), approve again (nothing recorded twice).
 */
require __DIR__ . '/bootstrap.php';
if (!mitReimbViaPayroll() || !mitReimbSettings()['account_id'] || mitReimbSettings()['rate'] <= 0) {
    echo "  skip - pay back is not set to payroll with an account and a rate\n";
    exit(0);
}
$rate = mitReimbSettings()['rate'];
$admin_id = $GLOBALS['session_user_id'];
mysqli_query($mysqli, "INSERT INTO users SET user_name = 'ZZ Scenario Driver', user_email = 'zz-driver@example.invalid', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 2");
$U = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_timesheet_people SET user_id = $U, schedule = '0,0,0,0,0,0,0', pay_type = 'hourly', hire_date = '2026-01-01', ca_rules = 0, keeps_timesheet = 1");
$client = scenarioClient();
mysqli_query($mysqli, "INSERT INTO tickets SET ticket_number = 990003, ticket_prefix = 'ZZ-', ticket_subject = 'ZZ scenario driver', ticket_details = '', ticket_status = 1, ticket_created_by = $U, ticket_client_id = $client, ticket_billable = 1");
$T = mysqli_insert_id($mysqli);
$ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $T"));
mysqli_query($mysqli, "INSERT INTO vendors SET vendor_name = 'ZZ Scenario Shop', vendor_client_id = 0");
$V = mysqli_insert_id($mysqli);
// The current pay period (an ended one has locked itself for the person after a few days)
$period = mitTsPeriodFor();
$day = function ($n) use ($period) { return date('Y-m-d', strtotime($period['start'] . " +$n days")); };
if ($day(6) > date('Y-m-d')) {
    echo "  skip - too early in the pay period
";
    exit(0);
}
$as = function ($who) use ($U, $admin_id) {
    $GLOBALS['session_user_id'] = $who === 'person' ? $U : $admin_id;
    $GLOBALS['session_is_admin'] = $who !== 'person';
    $GLOBALS['session_user_role'] = $who === 'person' ? 2 : 3;
    $GLOBALS['session_name'] = $who === 'person' ? 'ZZ Scenario Driver' : 'Scenario check';
};
$q1 = function ($sql) use ($mysqli) {
    return mysqli_fetch_row(mysqli_query($mysqli, $sql))[0] ?? null;
};
$made_expenses = [];
try {
    $as('person');
    [$ok, $msg] = mitReimbSave($ticket, null, ['kind' => 'mileage', 'date' => $day(0), 'miles' => '40', 'source' => 'Office', 'destination' => 'Client'], []);
    scenarioCheck("mileage logged on the ticket ($msg)", $ok, true);
    [$ok, $msg] = mitReimbSave($ticket, null, ['kind' => 'purchase', 'date' => $day(1), 'description' => 'ZZ cable', 'vendor' => 'ZZ Scenario Shop', 'amount' => '12.50'], []);
    scenarioCheck("purchase logged on the ticket ($msg)", $ok, true);
    $purchase_id = intval($q1("SELECT reimb_id FROM mit_reimbursements WHERE user_id = $U AND kind = 'purchase'"));
    // In ITFlow itself: an own-vehicle trip and an expense they paid personally
    mysqli_query($mysqli, "INSERT INTO trips SET trip_date = '" . $day(2) . "', trip_purpose = 'ZZ errand', trip_source = 'A', trip_destination = 'B', trip_miles = 10, round_trip = 0,
        trip_user_id = $U, trip_client_id = $client, trip_own_vehicle = 1");
    $itflow_trip = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO expenses SET expense_date = '" . $day(3) . "', expense_amount = 30, expense_currency_code = 'USD', expense_vendor_id = $V, expense_category_id = 0,
        expense_account_id = 0, expense_paid_by = $U, expense_client_id = 0, expense_description = 'ZZ toner', expense_created_by = $admin_id");
    $itflow_expense = mysqli_insert_id($mysqli);

    $as('admin');
    $tot = mitReimbTotals([$U], $period['start'], $period['end'])[$U];
    scenarioCheck('miles 50', $tot['miles'], 50.0);
    scenarioCheck('mileage at the rate', $tot['mileage'], round(50 * $rate, 2));
    scenarioCheck('purchases 42.50 (ticket + Expenses)', $tot['purchases'], 42.5);
    scenarioCheck('four items to pay', $tot['count'], 4);
    scenarioCheck('the person shows on the payroll report (activity)', in_array($U, mitTsUsersWithActivity($period['start'], $period['end']), true), true);

    // Approve: paid with payroll
    [$ok, $msg] = mitTsDecide(mitTsSheet($U, $period), $period, 'approve');
    scenarioCheck("approved ($msg)", $ok, true);
    $sheet = mitTsSheet($U, $period);
    $reference = mitReimbPayrollReference($sheet['sheet_id'], $period);
    $reimb = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM reimbursements WHERE reimbursement_user_id = $U"));
    scenarioCheck('one reimbursement with the payroll reference', (string) ($reimb['reimbursement_reference'] ?? ''), $reference);
    scenarioCheck('reimbursement = everything', round((float) ($reimb['reimbursement_amount'] ?? 0), 2), round(50 * $rate + 42.5, 2));
    scenarioCheck('both trips claimed', intval($q1("SELECT COUNT(*) FROM trips WHERE trip_user_id = $U AND trip_expense_id > 0")), 2);
    $mileage_expense = intval($q1("SELECT trip_expense_id FROM trips WHERE trip_id = $itflow_trip"));
    scenarioCheck('one mileage expense at the rate', round((float) $q1("SELECT expense_amount FROM expenses WHERE expense_id = $mileage_expense"), 2), round(50 * $rate, 2));
    $purchase_expense = intval($q1("SELECT expense_id FROM mit_reimbursements WHERE reimb_id = $purchase_id"));
    scenarioCheck('the purchase became an expense paid by them', intval($q1("SELECT expense_paid_by FROM expenses WHERE expense_id = $purchase_expense")), $U);
    scenarioCheck('nothing left owed after approval', array_sum(array_map(function ($i) { return $i['paid'] === '' ? 1 : 0; }, mitReimbItems([$U], $period['start'], $period['end'])[$U])), 0);
    $tot = mitReimbTotals([$U], $period['start'], $period['end'])[$U];
    scenarioCheck('payroll report still shows 77.50 for the period', round($tot['total'], 2), round(50 * $rate + 42.5, 2));

    // Locks in ITFlow
    scenarioCheck('Trips: a paid trip is locked', mitReimbTripWhyLocked($itflow_trip, ['date' => $day(2), 'driver' => $U, 'own_vehicle' => 1]) !== '', true);
    scenarioCheck('Trips: a new own-vehicle trip on an approved day refused', mitReimbTripWhyLocked(0, ['date' => $day(5), 'driver' => $U, 'own_vehicle' => 1]) !== '', true);
    scenarioCheck('Trips: a company-vehicle trip that day is fine', mitReimbTripWhyLocked(0, ['date' => $day(5), 'driver' => $U, 'own_vehicle' => 0]), '');
    $err = '';
    $res = expenseDelete($itflow_expense, $err);
    scenarioCheck("Expenses: deleting a payroll-paid expense refused ($err)", (bool) $res, false);
    $err = '';
    $res = reimbursementDelete(intval($reimb['reimbursement_id']), $err);
    scenarioCheck("Expenses: undoing the payroll reimbursement in ITFlow refused ($err)", (bool) $res, false);
    scenarioCheck('Expenses: a new paid-personally expense on an approved day refused', mitReimbExpenseWhyLocked([], ['account' => 'personal', 'paid_by' => $U, 'date' => $day(4)]) !== '', true);
    $as('person');
    [$ok, $msg] = mitReimbSave($ticket, null, ['kind' => 'purchase', 'date' => $day(4), 'description' => 'late', 'amount' => '5'], []);
    scenarioCheck("ticket: a purchase on an approved day refused ($msg)", $ok, false);
    [$ok, $msg] = mitReimbRow($purchase_id) ? mitReimbDelete(mitReimbRow($purchase_id)) : [null, 'no row'];
    scenarioCheck('ticket: removing a paid purchase refused', $ok, false);

    // Reopen: undone
    $as('admin');
    [$ok, $msg] = mitTsReopen($sheet, $period);
    scenarioCheck("reopened ($msg)", $ok, true);
    scenarioCheck('reimbursement removed', intval($q1("SELECT COUNT(*) FROM reimbursements WHERE reimbursement_user_id = $U")), 0);
    scenarioCheck('mileage expense removed', intval($q1("SELECT COUNT(*) FROM expenses WHERE expense_id = $mileage_expense")), 0);
    scenarioCheck('trips waiting again', intval($q1("SELECT COUNT(*) FROM trips WHERE trip_user_id = $U AND trip_expense_id = 0")), 2);
    scenarioCheck('expenses entered in ITFlow still there, unpaid', intval($q1("SELECT expense_reimbursement_id FROM expenses WHERE expense_id = $itflow_expense")), 0);
    $tot = mitReimbTotals([$U], $period['start'], $period['end'])[$U];
    scenarioCheck('owed again 77.50', round($tot['total'], 2), round(50 * $rate + 42.5, 2));

    // Approve again: nothing recorded twice
    [$ok, $msg] = mitTsDecide(mitTsSheet($U, $period), $period, 'approve');
    scenarioCheck("approved again ($msg)", $ok, true);
    scenarioCheck('the purchase is still one expense', intval($q1("SELECT COUNT(*) FROM expenses WHERE expense_reference = 'Ticket purchase $purchase_id'")), 1);
    scenarioCheck('one reimbursement again, same amount', round((float) $q1("SELECT SUM(reimbursement_amount) FROM reimbursements WHERE reimbursement_user_id = $U"), 2), round(50 * $rate + 42.5, 2));
    [$ok, $msg] = mitTsReopen(mitTsSheet($U, $period), $period);
    scenarioCheck('reopened again for clean-up', $ok, true);
} finally {
    $GLOBALS['mit_reimb_undoing'] = true;
    mysqli_query($mysqli, "DELETE FROM expenses WHERE expense_id IN (SELECT reimbursement_expense_id FROM reimbursements WHERE reimbursement_user_id = $U)");
    mysqli_query($mysqli, "DELETE FROM reimbursements WHERE reimbursement_user_id = $U");
    mysqli_query($mysqli, "DELETE FROM expenses WHERE expense_paid_by = $U");
    mysqli_query($mysqli, "DELETE FROM trips WHERE trip_user_id = $U");
    mysqli_query($mysqli, "DELETE FROM mit_reimbursements WHERE user_id = $U");
    mysqli_query($mysqli, "DELETE FROM ticket_history WHERE ticket_history_ticket_id = $T");
    mysqli_query($mysqli, "DELETE FROM tickets WHERE ticket_id = $T");
    mysqli_query($mysqli, "DELETE FROM vendors WHERE vendor_id = $V");
    foreach (['mit_time_entries', 'mit_timesheets', 'mit_leave_ledger', 'mit_timesheet_people'] as $table) {
        mysqli_query($mysqli, "DELETE FROM $table WHERE user_id = $U");
    }
    mysqli_query($mysqli, "DELETE FROM notifications WHERE notification_user_id = $U");
    mysqli_query($mysqli, "DELETE FROM users WHERE user_id = $U");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
