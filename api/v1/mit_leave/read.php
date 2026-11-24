<?php

/*
 * API - Leave balances - Read (Mountaineer IT, functions/mit_leave.php)
 * GET /api/v1/mit_leave/read.php
 *
 * Each person's balance on each leave bank (vacation, sick ...) at the end of a date: what the bank's ledger credits less the
 * hours on its codes. Who: the key's user's own, the people they approve, or everyone with Timesheets view.
 *
 * Parameters (GET):
 *   on        optional - YYYY-MM-DD (default today)
 *   user_id   optional - Only this person
 *   bank_id   optional - Only this bank
 */

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

mitApiTsRequire();
if (!mitLeaveReady()) {
    mitApiFail(503, 'Leave balances need the database update.');
}
$on = mitApiDate('on', date('Y-m-d'));
$banks = mitLeaveBanks(true);
$people = mitApiTsPeople($on, $on);
$rows = [];
foreach (mitLeaveBalancesAt(array_keys($people), $on) as $user_id => $by_bank) {
    foreach ($by_bank as $bank_id => $minutes) {
        if (isset($_GET['bank_id']) && intval($_GET['bank_id']) !== $bank_id) {
            continue;
        }
        $rows[] = array_merge(mitApiTsPerson($user_id, $people[$user_id]), [
            'bank_id' => $bank_id, 'bank_name' => $banks[$bank_id]['bank_name'] ?? "Bank $bank_id", 'on' => $on, 'balance_hours' => mitApiHours($minutes),
        ]);
    }
}
mitApiList($rows);
