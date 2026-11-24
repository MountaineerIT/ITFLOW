<?php

/*
 * API - Timesheets - Read (Mountaineer IT, functions/mit_timesheets.php, functions/mit_api.php)
 * GET /api/v1/mit_timesheets/read.php
 *
 * One row per person and pay period in the dates: the timesheet's status (open, submitted, returned, approved), who decided it and
 * when, and the period's hours (as the Timesheets & Payroll report adds them up). Approved periods keep the figures they were
 * approved with. Read only - submitting and approving stay on the timesheet page.
 * Who: the key's user's own timesheets, the people they approve (Admin > Timesheets > People), or everyone with Timesheets view.
 *
 * Parameters (GET):
 *   from, to   optional - YYYY-MM-DD; every pay period touching them (default: the last finished pay period)
 *   period     optional - Any date in a pay period - that period only
 *   user_id    optional - Only this person
 *   status     optional - Only timesheets in this status: open | submitted | returned | approved
 */

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

mitApiTsRequire();
[$from, $to] = mitApiTsRange();
$status = (string) ($_GET['status'] ?? '');
if ($status !== '' && !isset(MIT_TS_STATUSES[$status])) {
    mitApiFail(400, 'status must be open, submitted, returned or approved.');
}

$people = mitApiTsPeople($from, $to);
$codes = mitTsCodes(true);
$rows = [];
for ($p = mitTsPeriodFor($from), $guard = 0; $p['start'] <= $to && $guard < 60; $p = mitTsPeriodShift($p, 1), $guard++) {
    $totals = $people ? mitTsTotals(array_keys($people), $p['start'], $p['end']) : [];
    foreach ($people as $user_id => $user) {
        if ($user_id && mitTsStartDate($user_id) > $p['end']) {
            continue; // not started yet
        }
        $sheet = mitTsSheet($user_id, $p);
        if ($status !== '' && $sheet['status'] !== $status) {
            continue;
        }
        $rows[] = array_merge(mitApiTsPerson($user_id, $user), [
            'timesheet_id' => intval($sheet['sheet_id']) ?: null,
            'period_start' => $p['start'],
            'period_end' => $p['end'],
            'status' => $sheet['status'],
            'submitted_at' => $sheet['submitted_at'],
            'decided_by_user_id' => intval($sheet['decided_by']) ?: null,
            'decided_by_name' => $sheet['decided_by_name'],
            'decided_at' => $sheet['decided_at'],
            'decision_note' => $sheet['decision_note'],
        ], mitApiTsHours($totals[$user_id], $codes));
    }
}
mitApiList($rows);
