<?php
/*
 * A two-week pay period worked by hand for a new hourly (non-California) person, every total checked against arithmetic done
 * here: ticket time by work date (rounding, internal notes left out, a note dated by its typed times), codes (work, lost, leave
 * paid / unpaid, an unpaid break), auto lost time, daily + weekly overtime, paid / regular hours; then approve (stored figures,
 * leave accrual, payroll unchanged by later reply edits), reopen (live again, accrual gone), and the code rules on save
 * (daily max, note required, role-limited code, leave below zero).
 * Expects the install's settings: week starts Sunday, rounding 15, ticket_internal 0, ot_day 8h, ot_week 40h, auto lost on and unpaid,
 * approval on, PTO bank (period, codes PTO/Vacation).
 */
require __DIR__ . '/bootstrap.php';

$s = mitTsSettings();
if ($s['week_start'] !== 7 || $s['rounding'] !== 15 || $s['ticket_internal'] !== 0 || $s['ot_day'] !== 480 || $s['ot_week'] !== 2400 || !$s['auto_lost'] || $s['auto_lost_paid'] || !$s['approval']) {
    echo "  skip - settings differ from what this scenario expects: " . json_encode($s) . "\n";
    exit(0);
}
$code = function ($name) use ($mysqli) {
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT code_id FROM mit_charge_codes WHERE code_name = '" . escapeSql($name) . "' AND code_archived_at IS NULL"))[0] ?? 0);
};
$ADMIN = $code('Admin'); $TRAIN = $code('Training'); $PTO = $code('PTO'); $UNPAID = $code('Unpaid leave'); $LUNCH = $code('Lunch'); $HOLIDAY = $code('Holiday');
$ONCALL = $code('On call'); $LOST = $code('Lost time');
$pto_bank = null;
foreach (mitLeaveBanks() as $b) {
    if (in_array($PTO, $b['codes'], true)) {
        $pto_bank = $b;
    }
}
if (!$ADMIN || !$TRAIN || !$PTO || !$UNPAID || !$LUNCH || !$pto_bank) {
    echo "  skip - charge codes / PTO bank missing\n";
    exit(0);
}

// --- a new person (Technician role), hourly, not on California rules, Mon-Fri 8h, hired Jul 1 ---
mysqli_query($mysqli, "INSERT INTO users SET user_name = 'ZZ Scenario Hourly', user_email = 'zz-hourly@example.invalid', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 2");
$U = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_timesheet_people SET user_id = $U, schedule = '480,480,480,480,480,0,0', pay_type = 'hourly', hire_date = '2026-07-01', ca_rules = 0, keeps_timesheet = 1");
$client = scenarioClient();
mysqli_query($mysqli, "INSERT INTO tickets SET ticket_number = 990001, ticket_prefix = 'ZZ-', ticket_subject = 'ZZ scenario hourly', ticket_details = '', ticket_status = 1, ticket_created_by = $U, ticket_client_id = $client, ticket_billable = 1");
$T = mysqli_insert_id($mysqli);
$reply = function ($posted, $time, $type = 'Public') use ($mysqli, $U, $T) {
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'zz', ticket_reply_type = '$type', ticket_reply_by = $U, ticket_reply_ticket_id = $T,
        ticket_reply_time_worked = '$time', ticket_reply_created_at = '$posted'");
    return mysqli_insert_id($mysqli);
};
$period = ['start' => '2026-08-01', 'end' => '2026-08-15', 'label' => mitTsRangeText('2026-08-01', '2026-08-15')];
$h = function ($x) { return (int) round($x * 60); };

try {
    // Ticket time
    $reply('2026-08-03 15:00:00', '05:00:00');             // Mon 5h billable
    $reply('2026-08-11 11:00:00', '03:00:00', 'Internal'); // internal note: not counted (ticket_internal 0)
    $reply('2026-08-11 16:00:00', '02:07:00');             // 2:07 -> rounded to 2:00
    $r_late = $reply('2026-08-13 10:00:00', '01:00:00');   // posted Thu, worked Wed evening
    mysqli_query($mysqli, "INSERT INTO mit_reply_times SET reply_id = $r_late, work_start = '2026-08-12 20:00:00', work_end = '2026-08-12 21:00:00'");
    // Leave to spend: a 10 h adjustment
    mitLeavePost($U, $pto_bank['bank_id'], '2026-07-01', 600, 'adjust', null, 'ZZ scenario');

    $sheet = mitTsSheetSaved($U, $period);
    $cells = [
        '2026-08-03' => [$ADMIN => '4'],
        '2026-08-04' => [$ADMIN => '8'],
        '2026-08-05' => [$ADMIN => '8'],
        '2026-08-06' => [$ADMIN => '8', $TRAIN => '2'],
        '2026-08-07' => [$ADMIN => '6'],
        '2026-08-08' => [$ADMIN => '4'],
        '2026-08-10' => [$PTO => '8'],
        '2026-08-11' => [$ADMIN => '6'],
        '2026-08-12' => [$ADMIN => '7', $LUNCH => '1'],
        '2026-08-13' => [$UNPAID => '8'],
    ];
    [$ok, $msg] = mitTsSaveCells($sheet, $period, $cells);
    scenarioCheck("grid saved ($msg)", $ok, true);

    $days = mitTsDays([$U], '2026-08-01', '2026-08-15')[$U];
    scenarioCheck('Aug 3 ticket time 5h billable', $days['2026-08-03']['billable'], 300);
    scenarioCheck('Aug 11 ticket time rounded to 2h, internal note left out', $days['2026-08-11']['billable'], 120);
    scenarioCheck('Aug 12 counts the note worked that evening (typed times)', $days['2026-08-12']['billable'], 60);
    scenarioCheck('Aug 13 does not count the note posted that day', $days['2026-08-13']['billable'], 0);
    scenarioCheck('Aug 7 auto lost 2h', $days['2026-08-07']['auto_lost'], 120);
    scenarioCheck('Aug 12 lunch accounts for the day (no auto lost)', $days['2026-08-12']['auto_lost'], 0);
    scenarioCheck('Aug 14 empty weekday: 8h auto lost', $days['2026-08-14']['auto_lost'], 480);
    scenarioCheck('Aug 15 Saturday: nothing expected', $days['2026-08-15']['auto_lost'], 0);

    $t = mitTsTotals([$U], '2026-08-01', '2026-08-15')[$U];
    scenarioCheck('billable 8h', $t['billable'], $h(8));
    scenarioCheck('work codes 53h', $t['work'], $h(53));
    scenarioCheck('lost 10h (all auto)', $t['lost'], $h(10));
    scenarioCheck('auto lost 10h', $t['auto_lost'], $h(10));
    scenarioCheck('worked 71h', $t['worked'], $h(71));
    scenarioCheck('leave 16h', $t['leave'], $h(16));
    scenarioCheck('paid leave 8h', $t['leave_paid'], $h(8));
    scenarioCheck('unpaid 18h (unpaid leave + unaccounted)', $t['unpaid'], $h(18));
    scenarioCheck('lunch 1h logged as break', $t['break'], $h(1));
    scenarioCheck('paid 69h', $t['paid'], $h(69));
    scenarioCheck('total 87h', $t['total'], $h(87));
    scenarioCheck('overtime 5h (1 Mon + 2 Thu daily, 2 Sat weekly)', $t['overtime'], $h(5));
    scenarioCheck('regular 64h', $t['regular'], $h(64));
    scenarioCheck('billable share 11%', mitTsBillableShare($t), '11%');

    // Code rules on save
    [$ok, $msg] = mitTsSaveCells($sheet, $period, ['2026-08-14' => [$HOLIDAY => '9']]);
    scenarioCheck('daily max refuses 9h on Holiday', $ok, false);
    if ($LOST) {
        [$ok, $msg] = mitTsSaveCells($sheet, $period, ['2026-08-14' => [$LOST => '1']]);
        scenarioCheck('note required on Lost time', $ok, false);
        [$ok, $msg] = mitTsSaveCells($sheet, $period, ['2026-08-14' => [$LOST => '1']], ['2026-08-14' => [$LOST => 'printer']]);
        scenarioCheck('... saved with a note', $ok, true);
        mitTsSaveCells($sheet, $period, ['2026-08-14' => [$LOST => '0']]);
    }
    [$ok, $msg] = mitTsSaveCells($sheet, $period, ['2026-08-14' => [$PTO => '3']]);
    scenarioCheck("PTO beyond the balance refused ($msg)", $ok, false);
    [$ok, $msg] = mitTsSaveCells($sheet, $period, ['2026-08-14' => [$ADMIN => '20']]);
    scenarioCheck('a day over 24h refused (20 + 0 ticket... is fine) - 20h allowed', $ok, true);
    [$ok, $msg] = mitTsSaveCells($sheet, $period, ['2026-08-03' => [$ADMIN => '20']]);
    scenarioCheck('Aug 3: 20h + 5h ticket = 25h refused', $ok, false);
    mitTsSaveCells($sheet, $period, ['2026-08-14' => [$ADMIN => '0']]);

    // --- submit / approve ---
    [$ok, $msg] = mitTsSubmit($sheet, $period);
    scenarioCheck("submitted ($msg)", $ok, true);
    [$ok, $msg] = mitTsSaveCells(mitTsSheet($U, $period), $period, ['2026-08-14' => [$ADMIN => '1']]);
    $after = mitTsEntries([$U], '2026-08-14', '2026-08-14')[$U]['2026-08-14'][$ADMIN]['minutes'] ?? 0;
    echo "  info submitted sheet: mitTsSaveCells itself returned " . var_export($ok, true) . " (the handler is what refuses: " . json_encode(mitTsWhyLocked(mitTsSheet($U, $period))) . ")\n";
    mitTsSaveCells(mitTsSheet($U, $period), $period, ['2026-08-14' => [$ADMIN => '0']]);
    [$ok, $msg] = mitTsDecide(mitTsSheet($U, $period), $period, 'approve');
    scenarioCheck("approved ($msg)", $ok, true);
    $stored = function ($source) use ($mysqli, $U) {
        return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(minutes), 0) FROM mit_time_entries WHERE user_id = $U AND source = '$source' AND entry_date BETWEEN '2026-08-01' AND '2026-08-15'"))[0]);
    };
    scenarioCheck('stored ticket_billable 8h', $stored('ticket_billable'), $h(8));
    scenarioCheck('stored auto_lost 10h', $stored('auto_lost'), $h(10));
    scenarioCheck('stored overtime 5h', $stored('overtime'), $h(5));
    scenarioCheck('stored pay_rules: 15 days, none California', intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_time_entries WHERE user_id = $U AND source = 'pay_rules' AND minutes = 0"))[0]), 15);
    $accrual = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(minutes), 0) FROM mit_leave_ledger WHERE user_id = $U AND bank_id = {$pto_bank['bank_id']} AND kind = 'accrual' AND ref = '2026-08-01'"))[0]);
    scenarioCheck('PTO earned for the period = bank rate', $accrual, intval($pto_bank['bank_rate']));
    mitTsForget();
    $bal = mitLeaveBalance($U, $pto_bank, '2026-08-15')['now'];
    scenarioCheck('PTO balance at period end = 10h + accrual - 8h', $bal, 600 + intval($pto_bank['bank_rate']) - 480);

    // A reply added later on an approved day: payroll does not move
    $r_after = $reply('2026-08-04 12:00:00', '02:00:00');
    mitTsForget();
    $t2 = mitTsTotals([$U], '2026-08-01', '2026-08-15')[$U];
    scenarioCheck('approved: a later reply changes nothing (paid)', $t2['paid'], $h(69));
    scenarioCheck('approved: overtime still 5h', $t2['overtime'], $h(5));
    scenarioCheck('day lock on an approved day', mitTsDayLock($U, '2026-08-04') !== '', true);

    // Reopen: live again (Aug 4 now 10h worked -> 2h more daily overtime... and weekly limit shifts)
    [$ok, $msg] = mitTsReopen(mitTsSheet($U, $period), $period);
    scenarioCheck("reopened ($msg)", $ok, true);
    mitTsForget();
    scenarioCheck('reopen: stored copies gone', $stored('ticket_billable') + $stored('overtime') + $stored('pay_rules'), 0);
    scenarioCheck('reopen: accrual taken back', intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_leave_ledger WHERE user_id = $U AND kind = 'accrual'"))[0]), 0);
    $t3 = mitTsTotals([$U], '2026-08-01', '2026-08-15')[$U];
    scenarioCheck('reopened: the later reply counts (billable 10h)', $t3['billable'], $h(10));
    // Week 2 now: Mon 9 (1 OT), Tue 10 (2 OT), Wed 8, Thu 10 (2 OT), Fri 6, Sat 4 -> regular 8+8+8+8+6 = 38, Sat +2 over 40 -> 7h
    scenarioCheck('reopened: overtime 7h', $t3['overtime'], $h(7));
    scenarioCheck('reopened: paid 71h', $t3['paid'], $h(71));
} finally {
    $sheet_ids = [];
    $res = mysqli_query($mysqli, "SELECT sheet_id FROM mit_timesheets WHERE user_id = $U");
    while ($row = mysqli_fetch_row($res)) { $sheet_ids[] = intval($row[0]); }
    mysqli_query($mysqli, "DELETE FROM mit_reply_times WHERE reply_id IN (SELECT ticket_reply_id FROM ticket_replies WHERE ticket_reply_ticket_id = $T)");
    mysqli_query($mysqli, "DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = $T");
    mysqli_query($mysqli, "DELETE FROM tickets WHERE ticket_id = $T");
    foreach (['mit_time_entries', 'mit_timesheets', 'mit_leave_ledger', 'mit_timesheet_people', 'mit_ts_days', 'mit_ts_work_periods'] as $table) {
        mysqli_query($mysqli, "DELETE FROM $table WHERE user_id = $U");
    }
    mysqli_query($mysqli, "DELETE FROM notifications WHERE notification_user_id = $U");
    mysqli_query($mysqli, "DELETE FROM users WHERE user_id = $U");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
