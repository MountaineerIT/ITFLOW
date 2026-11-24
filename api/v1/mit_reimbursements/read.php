<?php

/*
 * API - Mileage and purchases - Read (Mountaineer IT, functions/mit_reimbursements.php mitReimbItems - the list behind the
 * timesheet's "Mileage and purchases" box and the payroll report)
 * GET /api/v1/mit_reimbursements/read.php
 *
 * What people paid for themselves in the dates and are owed: own-vehicle trips (mileage) and purchases, with where each is in being
 * paid back (through payroll, or in ITFlow's Trips / Expenses). A ticket of a client the key's user can't see shows its number only.
 * Who: the key's user's own, the people they approve, or everyone with Timesheets view.
 *
 * Parameters (GET):
 *   from, to   optional - YYYY-MM-DD (default: the last finished pay period)
 *   period     optional - Any date in a pay period - that period
 *   user_id    optional - Only this person
 */

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

mitApiTsRequire();
if (!mitReimbOn()) {
    mitApiFail(503, 'Mileage and purchases are switched off here (Admin > Timesheets), or need the database update.');
}
[$from, $to] = mitApiTsRange();
$people = mitApiTsPeople($from, $to);
$rows = [];
foreach (mitReimbItems(array_keys($people), $from, $to) as $user_id => $items) {
    foreach ($items as $item) {
        $rows[] = [
            'user_id' => intval($user_id), 'user_name' => $people[$user_id]['user_name'] ?? null, 'date' => $item['date'], 'kind' => $item['kind'],
            'what' => $item['what'], 'where' => $item['where'], 'ticket_id' => intval($item['ticket_id']) ?: null,
            'miles' => $item['kind'] === 'mileage' ? round((float) $item['miles'], 1) : null, 'amount' => round((float) $item['amount'], 2),
            'trip_id' => intval($item['trip_id']) ?: null, 'expense_id' => intval($item['expense_id']) ?: null,
            'owed' => (bool) $item['owed'], 'paid_on' => $item['paid'] !== '' ? $item['paid'] : null, 'paid_through' => $item['via'] !== '' ? $item['via'] : null,
            'state' => $item['state'][0],
        ];
    }
}
mitApiList($rows);
