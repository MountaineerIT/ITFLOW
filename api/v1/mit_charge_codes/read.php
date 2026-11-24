<?php

/*
 * API - Charge codes - Read (Mountaineer IT, functions/mit_timesheets.php - Admin > Timesheets > Charge codes)
 * GET /api/v1/mit_charge_codes/read.php
 *
 * The codes hours go on in timesheets - kind work | lost | leave | break, paid or not - to match the code_hours of
 * mit_timesheets / mit_payroll to a payroll provider's earning codes. Anyone with a key can read them (every agent uses them).
 *
 * Parameters (GET):
 *   archived  optional - 1 = archived codes too
 */

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

mitApiTsRequire();
$rows = [];
foreach (mitTsCodes(!empty($_GET['archived'])) as $code_id => $code) {
    $roles = array_values(array_filter(array_map('intval', explode(',', (string) ($code['code_roles'] ?? '')))));
    $rows[] = [
        'code_id' => intval($code_id), 'code_name' => $code['code_name'], 'code_kind' => $code['code_kind'], 'code_paid' => (bool) intval($code['code_paid']),
        'code_color' => $code['code_color'], 'role_ids' => $roles, 'note_required' => (bool) intval($code['code_note_required'] ?? 0),
        'daily_max_hours' => intval($code['code_daily_max'] ?? 0) ? mitApiHours($code['code_daily_max']) : null,
        'fills_closure_days' => (bool) intval($code['code_closure_fill'] ?? 0), 'code_archived_at' => $code['code_archived_at'],
    ];
}
mitApiList($rows);
