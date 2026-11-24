<?php

/*
 * API - Payroll - Read (Mountaineer IT - the Timesheets & Payroll report's summary, agent/reports/mit_payroll.php)
 * GET /api/v1/mit_payroll/read.php
 *
 * A row per person over the dates, for a payroll provider: hours by kind (regular, overtime, double time, leave, paid / unpaid,
 * meal and rest premium hours under California rules), hours on each charge code, leave balances at the end of the dates, mileage
 * and purchases to pay back, and how many of the pay periods are approved. Needs Timesheets view (as the report does).
 *
 * Parameters (GET):
 *   from, to   optional - YYYY-MM-DD (default: the last finished pay period)
 *   period     optional - Any date in a pay period - that period
 *   user_id    optional - Only this person
 */

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

mitApiTsRequire();
[$from, $to] = mitApiTsRange();
$people = mitApiTsPeople($from, $to);
$codes = mitTsCodes(true);
$totals = $people ? mitTsTotals(array_keys($people), $from, $to) : [];
$reimb = $people && mitReimbOn() ? mitReimbTotals(array_keys($people), $from, $to) : [];
$banks = mitLeaveReady() ? mitLeaveBanks(true) : [];
$balances = $people && $banks ? mitLeaveBalancesAt(array_keys($people), $to) : [];

$periods = [];
for ($p = mitTsPeriodFor($from), $guard = 0; $p['start'] <= $to && $guard < 60; $p = mitTsPeriodShift($p, 1), $guard++) {
    $periods[] = $p;
}
$approved = [];
if ($people && $periods) {
    $result = mysqli_query($mysqli, "SELECT user_id, COUNT(*) FROM mit_timesheets WHERE status = 'approved' AND user_id IN (" . implode(',', array_keys($people)) . ")
        AND period_start >= '" . escapeSql($periods[0]['start']) . "' AND period_start <= '" . escapeSql($to) . "' GROUP BY user_id");
    while ($row = mysqli_fetch_row($result)) {
        $approved[intval($row[0])] = intval($row[1]);
    }
}

$rows = [];
foreach ($people as $user_id => $user) {
    $t = $totals[$user_id];
    $r = $reimb[$user_id] ?? ['miles' => 0, 'mileage' => 0, 'purchases' => 0, 'total' => 0, 'count' => 0, 'elsewhere' => 0];
    if (!$t['total'] && !$t['not_clocked'] && !$r['count'] && !$r['elsewhere'] && empty($_GET['user_id'])) {
        continue; // nothing in these dates
    }
    $leave = [];
    foreach ($balances[$user_id] ?? [] as $bank_id => $minutes) {
        $leave[] = ['bank_id' => $bank_id, 'bank_name' => $banks[$bank_id]['bank_name'] ?? "Bank $bank_id", 'balance_hours' => mitApiHours($minutes)];
    }
    $rows[] = array_merge(mitApiTsPerson($user_id, $user), ['from' => $from, 'to' => $to], mitApiTsHours($t, $codes), [
        'periods' => count($periods),
        'periods_approved' => $approved[$user_id] ?? 0,
        'leave_balances' => $leave,
        'reimburse_miles' => round($r['miles'], 1),
        'reimburse_mileage' => round($r['mileage'], 2),
        'reimburse_purchases' => round($r['purchases'], 2),
        'reimburse_total' => round($r['total'], 2),
        'reimburse_paid_elsewhere' => round($r['elsewhere'], 2),
        'reimburse_through' => mitReimbOn() ? (mitReimbViaPayroll() ? 'payroll' : 'itflow') : null,
    ]);
}
mitApiList($rows);
