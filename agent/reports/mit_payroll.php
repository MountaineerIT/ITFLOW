<?php

/*
 * Mountaineer IT - Timesheets & Payroll (functions/mit_timesheets.php): each person's hours over a
 * pay period (or any dates) by charge code - billable and other ticket time, admin and other work,
 * lost time and leave - with paid and unpaid hours for payroll, and whether the timesheets are
 * approved. Exports: the summary (a row per person) and the detail (a row per person, day and code).
 */

require_once "includes/inc_report.php";
reportEnforce('mit_payroll');

if (!mitTsReady()) {
    exit('Timesheets need the database update - Admin > Update.');
}

// Default to the last finished pay period
if (!isset($_GET['period'])) {
    $last = mitTsPeriodShift(mitTsPeriodFor(), -1);
    $_GET['period'] = 'custom';
    $_GET['from'] = $last['start'];
    $_GET['to'] = $last['end'];
}
$period = reportPeriod('custom');
$agent_id = reportAgentId();
$from = $period['start'];
$to = $period['end'];

// Everyone who keeps a timesheet, and anyone who has left (or was left out) but has hours in these dates
$people = mitTsUsers(mitTsUsersWithActivity($from, $to));
if ($agent_id) {
    $people = array_intersect_key(mitTsUsers([$agent_id]), [$agent_id => true]);
}
$codes = mitTsCodes(true);
$banks = mitLeaveReady() ? mitLeaveBanks() : [];
$totals = mitTsTotals(array_keys($people), $from, $to);
$reimb = mitReimbOn() ? mitReimbTotals(array_keys($people), $from, $to) : [];
$balances = $banks ? mitLeaveBalancesAt(array_keys($people), $to) : [];
$settings = mitTsSettings();
$approval = $settings['approval'];
$overtime = $settings['ot_day'] || $settings['ot_week'];
$with_ids = count(array_filter(array_keys($people), function ($user_id) {
    return mitTsPerson($user_id)['employee_id'] !== '';
})) > 0;

// Pay periods inside the dates, and how many of each person's are approved
$periods = [];
for ($p = mitTsPeriodFor($from), $guard = 0; $p['start'] <= $to && $guard < 120; $p = mitTsPeriodShift($p, 1), $guard++) {
    $periods[] = $p;
}
$approved = [];
if ($people) {
    $result = mysqli_query($mysqli, "SELECT user_id, COUNT(*) AS n FROM mit_timesheets WHERE status = 'approved' AND user_id IN (" . implode(',', array_keys($people)) . ")
        AND period_start >= '" . escapeSql($periods[0]['start'] ?? $from) . "' AND period_start <= '" . escapeSql($to) . "' GROUP BY user_id");
    while ($row = mysqli_fetch_assoc($result)) {
        $approved[intval($row['user_id'])] = intval($row['n']);
    }
}

// Codes with hours in the range get a column
$used = [];
foreach ($totals as $t) {
    foreach ($t['codes'] as $code_id => $m) {
        if ($m) {
            $used[$code_id] = true;
        }
    }
}
$code_columns = array_intersect_key($codes, $used);

$rows = [];
$sum = ['billable' => 0, 'other' => 0, 'work' => 0, 'lost' => 0, 'auto_lost' => 0, 'break_paid' => 0, 'leave' => 0, 'leave_paid' => 0, 'unpaid' => 0, 'worked' => 0, 'paid' => 0, 'total' => 0, 'overtime' => 0, 'regular' => 0,
    'double' => 0, 'meal_premium' => 0, 'rest_premium' => 0, 'accounted' => 0, 'unallocated' => 0, 'not_clocked' => 0];
// California non-exempt people (functions/mit_ts_california.php): double time, premium hours, and whether each period was signed
$any_ca = count(array_filter($totals, function ($t) {
    return !empty($t['ca']);
})) > 0;
foreach ($people as $user_id => $person) {
    $t = $totals[$user_id];
    $r = $reimb[$user_id] ?? ['miles' => 0, 'mileage' => 0, 'purchases' => 0, 'total' => 0, 'count' => 0, 'elsewhere' => 0];
    if (!$t['total'] && !$t['not_clocked'] && !$r['count'] && !$r['elsewhere'] && !$agent_id) {
        continue;
    }
    $details = mitTsPerson($user_id);
    $row = ['user_id' => $user_id, 'person' => $person['user_name'], 'email' => $person['user_email'], 'avatar' => $person['user_avatar'] ?? '',
        'employee_id' => $details['employee_id'], 'pay_type' => MIT_TS_PAY_TYPE_SHORT[$details['pay_type']] ?? 'Hourly',
        'regular' => $t['regular'] * 60, 'overtime' => $t['overtime'] * 60, 'auto_lost' => $t['auto_lost'] * 60, 'break_paid' => $t['break_paid'] * 60,
        'miles' => $r['miles'], 'mileage' => $r['mileage'], 'purchases' => $r['purchases'], 'reimburse' => $r['total'], 'elsewhere' => $r['elsewhere'],
        'billable' => $t['billable'] * 60, 'other' => $t['other'] * 60, 'work' => $t['work'] * 60, 'worked' => $t['worked'] * 60, 'leave' => $t['leave'] * 60,
        'unpaid' => $t['unpaid'] * 60, 'paid' => $t['paid'] * 60, 'total' => $t['total'] * 60, 'lost' => $t['lost'] * 60,
        'share' => $t['accounted'] ? round($t['billable'] / $t['accounted'] * 100, 1) : null,
        'unallocated' => $t['unallocated'] * 60, 'not_clocked' => $t['not_clocked'] * 60,
        'approved' => ($approved[$user_id] ?? 0) . ' of ' . count($periods),
        'double' => $t['double'] * 60, 'meal_premium' => $t['meal_premium'] / 60, 'rest_premium' => $t['rest_premium'] / 60, 'ca' => $t['ca'] ? 'Yes' : 'No'];
    foreach ($code_columns as $code_id => $code) {
        $row["code_$code_id"] = ($t['codes'][$code_id] ?? 0) * 60;
    }
    foreach ($banks as $bank_id => $bank) {
        $row["bank_$bank_id"] = isset($balances[$user_id][$bank_id]) ? $balances[$user_id][$bank_id] * 60 : null;
    }
    foreach ($sum as $key => $value) {
        $sum[$key] += $t[$key];
    }
    $rows[] = $row;
}

$columns = [
    'person' => ['label' => 'Person', 'html' => function ($r) {
        return reportAgentCell($r['user_id'], $r['person'], $r['avatar']);
    }],
    'employee_id' => ['label' => 'Employee ID', 'screen' => $with_ids],
    'billable' => ['label' => 'Billable', 'type' => 'hours', 'decimals' => 2, 'title' => 'Ticket time on billable tickets'],
    'other' => ['label' => 'Other tickets', 'type' => 'hours', 'decimals' => 2, 'title' => 'Ticket time on tickets that are not billable'],
];
foreach ($code_columns as $code_id => $code) {
    $columns["code_$code_id"] = ['label' => $code['code_name'], 'type' => 'hours', 'decimals' => 2,
        'title' => MIT_TS_KINDS[$code['code_kind']] . (intval($code['code_paid']) ? ', paid' : ', unpaid')];
}
$columns += [
    'auto_lost' => ['label' => 'Unaccounted', 'type' => 'hours', 'decimals' => 2, 'screen' => (bool) $settings['auto_lost'], 'csv' => $settings['auto_lost'] ? null : false,
        'title' => 'Normal hours nothing accounted for - counted as lost time' . ($settings['auto_lost_paid'] ? ', and paid' : ', not paid')],
    'break_paid' => ['label' => 'Paid breaks', 'type' => 'hours', 'decimals' => 2, 'screen' => $sum['break_paid'] > 0,
        'title' => 'Short breaks, paid - worked out from the hours worked (Admin > Timesheets)'],
    'unallocated' => ['label' => 'Not on a code', 'type' => 'hours', 'decimals' => 2, 'screen' => $sum['unallocated'] > 0, 'csv' => $any_ca ? null : false,
        'title' => 'California rules: clocked time (the typed start / end times) not on a ticket or a code - worked and paid'],
    'not_clocked' => ['label' => 'Not clocked', 'type' => 'hours', 'decimals' => 2, 'screen' => $sum['not_clocked'] > 0, 'csv' => $any_ca ? null : false, 'class' => 'text-warning-emphasis',
        'title' => 'California rules: ticket time and hours on codes outside the typed start / end times (or on a day with none typed) - not in Worked or paid until the times are put right'],
    'worked' => ['label' => 'Worked', 'type' => 'hours', 'decimals' => 2, 'title' => 'Ticket time, admin and other work, and lost time - breaks and lunch not included'],
    'leave' => ['label' => 'Leave', 'type' => 'hours', 'decimals' => 2],
    'unpaid' => ['label' => 'Unpaid', 'type' => 'hours', 'decimals' => 2],
    'paid' => ['label' => 'Paid hours', 'type' => 'hours', 'decimals' => 2, 'class' => 'fw-semibold', 'title' => 'What payroll pays: worked, paid breaks and paid leave, less unpaid codes' . ($settings['auto_lost_paid'] ? '' : ' and unaccounted time')],
    'regular' => ['label' => 'Regular', 'type' => 'hours', 'decimals' => 2, 'screen' => $overtime, 'title' => 'Paid hours less overtime'],
    'overtime' => ['label' => 'Overtime', 'type' => 'hours', 'decimals' => 2, 'screen' => $overtime || $any_ca, 'title' => 'Over the daily / weekly limit set in Admin > Timesheets (hourly people); California non-exempt: over 8 a day, 40 a week, and the first 8 of a 7th day in a row'],
    'double' => ['label' => 'Double time', 'type' => 'hours', 'decimals' => 2, 'screen' => $any_ca, 'csv' => $any_ca ? null : false, 'title' => 'California non-exempt: over 12 hours a day, and over 8 on a 7th day in a row'],
    'meal_premium' => ['label' => 'Meal premium', 'type' => 'decimal', 'decimals' => 0, 'screen' => $any_ca, 'csv' => $any_ca ? null : false, 'title' => 'Hours of premium pay owed for days a meal period could not be taken (at the regular rate)'],
    'rest_premium' => ['label' => 'Rest premium', 'type' => 'decimal', 'decimals' => 0, 'screen' => $any_ca, 'csv' => $any_ca ? null : false, 'title' => 'Hours of premium pay owed for days a rest break could not be taken (at the regular rate)'],
    'share' => ['label' => 'Billable %', 'type' => 'percent'],
];
// Mileage and purchases people paid for themselves (functions/mit_reimbursements.php, mitReimbItems) - shown when there are any
$any_reimb = array_sum(array_column($reimb, 'count')) > 0 || array_sum(array_column($reimb, 'elsewhere')) > 0;
$reimb_itflow = mitReimbItflowPaysBack() && !mitReimbViaPayroll(); // paid back in ITFlow, not with payroll: shown for reference
$columns += [
    'miles' => ['label' => 'Miles', 'type' => 'decimal', 'decimals' => 1, 'screen' => $any_reimb, 'title' => 'Miles driven in their own vehicle (trips, and mileage logged on tickets)'],
    'mileage' => ['label' => 'Mileage', 'type' => 'money', 'screen' => $any_reimb, 'title' => 'The miles at the mileage rate - or what was paid, once paid'],
    'purchases' => ['label' => 'Purchases', 'type' => 'money', 'screen' => $any_reimb, 'title' => 'What they paid for themselves - purchases on tickets and expenses paid personally'],
    'reimburse' => $reimb_itflow
        ? ['label' => 'To pay back in ITFlow', 'type' => 'money', 'screen' => $any_reimb, 'title' => 'Mileage plus purchases - paid back in ITFlow (Trips > To pay back, Expenses > To reimburse), not with this payroll']
        : ['label' => 'Reimburse', 'type' => 'money', 'screen' => $any_reimb, 'class' => 'fw-semibold', 'title' => 'Mileage plus purchases - to pay with this payroll (anything already paid back in ITFlow is left out)'],
    'elsewhere' => ['label' => 'Paid in ITFlow', 'type' => 'money', 'screen' => !$reimb_itflow && array_sum(array_column($reimb, 'elsewhere')) > 0,
        'title' => 'Already paid back in ITFlow (Trips or Expenses) before payroll - not in Reimburse, not to pay again'],
];
foreach ($banks as $bank_id => $bank) {
    $columns["bank_$bank_id"] = ['label' => $bank['bank_name'] . ' left', 'type' => 'hours', 'decimals' => 2, 'title' => $bank['bank_name'] . ' balance at the end of these dates'];
}
$columns += [
    'approved' => ['label' => 'Approved', 'title' => 'Pay periods approved', 'screen' => (bool) $approval, 'html' => function ($r) use ($periods) {
        $all = intval($r['approved']) === count($periods);
        return '<span class="badge ' . ($all ? 'text-bg-success' : 'text-bg-warning') . '">' . escapeHtml($r['approved']) . '</span>';
    }],
];
$table_totals = ['person' => 'Total'];
foreach ($columns as $key => $column) {
    if ($key !== 'person' && reportColumnIsNumber($column) && $key !== 'share' && strpos($key, 'bank_') !== 0) {
        $table_totals[$key] = array_sum(array_column($rows, $key));
    }
}
$table_totals['share'] = $sum['accounted'] ? round($sum['billable'] / $sum['accounted'] * 100, 1) : null;

if (reportExporting('detail')) {
    $detail = [];
    // The days carry ticket and unaccounted time; the entries are read again only for their notes
    $all_days = mitTsDays(array_keys($people), $from, $to);
    $entries = mitTsEntries(array_keys($people), $from, $to);
    $reimb_items = mitReimbOn() ? mitReimbItems(array_keys($people), $from, $to) : [];
    $all_ca_days = mitTsCaDays(array_keys($people), $from, $to);
    foreach ($people as $user_id => $person) {
        $days = [];
        // California non-exempt: the day's start, meal and end times, and the answers about its breaks
        $ca_days = $all_ca_days[$user_id] ?? [];
        foreach ($ca_days as $date => $c) {
            if (!$c['periods'] && !$c['issues'] && !$c['answers']['rest_missed']) {
                continue;
            }
            $notes = [mitTsCaPeriodsText($c['periods'])];
            foreach ($c['issues'] as $n => $status) {
                $notes[] = mitTsCaIssueText($n, $status) . ': ' . (MIT_TS_CA_ANSWERS[$c['answers']["meal$n"]] ?? 'not answered (premium owed)');
            }
            if ($c['answers']['rest_missed']) {
                $notes[] = 'Could not take a rest break (premium owed)';
            }
            // Hours left empty: the record of the day, not more hours - the code rows below add up to the time worked
            $days[] = ['date' => $date, 'code' => 'Work times', 'kind' => 'Time record', 'paid' => '', 'hours' => null,
                'note' => mitTsHours($c['clocked'], false, 'decimal') . ' h worked: ' . implode('; ', $notes)];
        }
        foreach ($all_days[$user_id] ?? [] as $date => $d) {
            foreach ($d['filled'] ?? [] as $code_id => $m) {
                $code = $codes[$code_id] ?? ['code_name' => "Code $code_id", 'code_kind' => 'work', 'code_paid' => 1];
                $days[] = ['date' => $date, 'code' => $code['code_name'], 'kind' => MIT_TS_KINDS[$code['code_kind']] ?? $code['code_kind'],
                    'paid' => 'Yes', 'hours' => $m * 60, 'note' => 'Time not on a code (default code)'];
            }
            if (!empty($d['unallocated'])) {
                $days[] = ['date' => $date, 'code' => 'Work - not on a code', 'kind' => 'Work', 'paid' => 'Yes', 'hours' => $d['unallocated'] * 60];
            }
            if ($d['auto_lost']) {
                $days[] = ['date' => $date, 'code' => 'Unaccounted (lost)', 'kind' => 'Lost time', 'paid' => $settings['auto_lost_paid'] ? 'Yes' : 'No', 'hours' => $d['auto_lost'] * 60];
            }
            if ($d['break_paid']) {
                $days[] = ['date' => $date, 'code' => 'Breaks (paid, auto)', 'kind' => 'Break', 'paid' => 'Yes', 'hours' => $d['break_paid'] * 60];
            }
            foreach (['billable' => 'Ticket time - billable', 'other' => 'Ticket time - other'] as $kind => $label) {
                if ($d[$kind]) {
                    $days[] = ['date' => $date, 'code' => $label, 'kind' => 'Work', 'paid' => 'Yes', 'hours' => $d[$kind] * 60];
                }
            }
        }
        foreach ($entries[$user_id] ?? [] as $date => $by_code) {
            foreach ($by_code as $code_id => $entry) {
                if (!$entry['minutes']) {
                    continue;  // a cleared closure day
                }
                $code = $codes[$code_id] ?? ['code_name' => "Code $code_id", 'code_kind' => 'work', 'code_paid' => 1];
                $days[] = ['date' => $date, 'code' => $code['code_name'], 'kind' => MIT_TS_KINDS[$code['code_kind']] ?? $code['code_kind'],
                    'paid' => intval($code['code_paid']) ? 'Yes' : 'No', 'hours' => $entry['minutes'] * 60, 'note' => (string) $entry['note']];
            }
        }
        foreach ($reimb_items[$user_id] ?? [] as $item) {
            $days[] = ['date' => $item['date'], 'code' => ($item['kind'] === 'mileage' ? 'Mileage' : 'Purchase') . ' - ' . $item['where'], 'kind' => 'Reimbursement',
                'paid' => !$item['owed'] ? 'Paid in ITFlow' : ($reimb_itflow && $item['source'] !== 'entry' ? 'In ITFlow' : 'Payroll'), 'hours' => null,
                'amount' => $item['owed'] ? $item['amount'] : 0.0, 'note' => $item['what'] . ' - ' . $item['state'][0]];
        }
        usort($days, function ($a, $b) {
            return [$a['date'], $a['code']] <=> [$b['date'], $b['code']];
        });
        foreach ($days as $day) {
            $detail[] = ['person' => $person['user_name'], 'employee_id' => mitTsPerson($user_id)['employee_id'], 'email' => $person['user_email']] + $day;
        }
    }
    reportCsv('Timesheet detail', [
        'person' => ['label' => 'Person'], 'employee_id' => ['label' => 'Employee ID'], 'email' => ['label' => 'Email'], 'date' => ['label' => 'Date'], 'code' => ['label' => 'Charge code'],
        'kind' => ['label' => 'Kind'], 'paid' => ['label' => 'Paid'], 'hours' => ['label' => 'Hours', 'type' => 'hours'], 'amount' => ['label' => 'Amount', 'type' => 'money'], 'note' => ['label' => 'Note'],
    ], $detail);
}
if (reportExporting()) {
    $csv_columns = $columns;
    unset($csv_columns['employee_id']);
    $csv_columns['person'] = ['label' => 'Person'];
    $csv_columns = ['person' => $csv_columns['person'], 'employee_id' => ['label' => 'Employee ID'], 'email' => ['label' => 'Email'], 'pay_type' => ['label' => 'Pay type']] + $csv_columns;
    if ($approval) {
        $csv_columns['approved'] = ['label' => 'Pay periods approved'];
    } else {
        unset($csv_columns['approved']);
    }
    reportCsv('Payroll', $csv_columns, $rows, $table_totals + ['email' => '', 'employee_id' => '', 'pay_type' => '']);
}

require_once "includes/inc_report_layout.php";

$pay_options = '<div class="col-sm-6 col-lg-auto"><div class="input-group"><span class="input-group-text"><i class="fas fa-fw fa-money-check-alt"></i></span>'
    . '<select class="form-select" aria-label="Pay period" onchange="if (this.value) { location.href = this.value; }"><option value="">Pay period...</option>';
for ($p = mitTsPeriodFor(), $i = 0; $i < 12; $p = mitTsPeriodShift($p, -1), $i++) {
    $selected = $p['start'] === $from && $p['end'] === $to;
    $pay_options .= '<option value="' . escapeHtml(reportUrl(['period' => 'custom', 'from' => $p['start'], 'to' => $p['end'], 'export' => null, 'table' => null])) . '"' . ($selected ? ' selected' : '') . '>' . escapeHtml($p['label']) . '</option>';
}
$pay_options .= '</select></div></div>';

$not_approved = count(array_filter($rows, function ($r) use ($periods) {
    return intval($r['approved']) < count($periods);
}));
$with_hours = array_values(array_filter($rows, function ($r) {
    return $r['total'] > 0;
}));

?>

<div class="itflow-report">

    <?php reportHeader('mit_payroll', [
        'period' => $period,
        'agent' => $agent_id,
        'controls' => $pay_options,
        'export' => ['summary' => 'Payroll summary (a row per person)', 'detail' => 'Detail (a row per person, day and code)'],
    ]); ?>

    <?= reportStats([
        ['label' => 'Paid hours', 'value' => reportHours($sum['paid'] * 60, 2) . ' h', 'sub' => '<span class="text-body-tertiary">' . count($rows) . ' ' . (count($rows) === 1 ? 'person' : 'people') . '</span>'],
        ['label' => 'Billable', 'value' => reportPercent($table_totals['share'] === null ? null : round($table_totals['share'])), 'sub' => escapeHtml(reportHours($sum['billable'] * 60, 2)) . ' h <span class="text-body-tertiary">of ' . escapeHtml(reportHours($sum['accounted'] * 60, 2)) . ' h worked</span>'],
        ['label' => 'Lost time', 'value' => reportHours($sum['lost'] * 60, 2) . ' h', 'tone' => $sum['lost'] ? 'danger' : '', 'sub' => '<span class="text-body-tertiary">' . ($sum['accounted'] ? round($sum['lost'] / $sum['accounted'] * 100) : 0) . '% of hours worked</span>'],
        ['label' => 'Leave', 'value' => reportHours($sum['leave'] * 60, 2) . ' h', 'sub' => $sum['leave'] > $sum['leave_paid'] ? escapeHtml(reportHours(($sum['leave'] - $sum['leave_paid']) * 60, 2)) . ' h <span class="text-body-tertiary">unpaid</span>' : '<span class="text-body-tertiary">All paid</span>'],
    ] + ($overtime ? [5 => ['label' => 'Overtime', 'value' => reportHours($sum['overtime'] * 60, 2) . ' h', 'tone' => $sum['overtime'] ? 'warning' : '',
            'sub' => '<span class="text-body-tertiary">' . escapeHtml(reportHours($sum['regular'] * 60, 2)) . ' h regular</span>']] : [])
        + ($approval ? [6 => ['label' => 'Not approved', 'value' => reportCount($not_approved), 'tone' => $not_approved ? 'warning' : 'success',
            'sub' => '<span class="text-body-tertiary">' . ($not_approved ? 'people with a period still open' : 'Every timesheet approved') . '</span>', 'url' => '/agent/mit_timesheets.php?start=' . $from]] : [])
        + ($any_reimb ? [7 => ['label' => $reimb_itflow ? 'To pay back in ITFlow' : 'Reimburse', 'value' => reportMoney(array_sum(array_column($rows, 'reimburse'))),
            'sub' => escapeHtml(rtrim(rtrim(number_format(array_sum(array_column($rows, 'miles')), 1), '0'), '.')) . ' <span class="text-body-tertiary">miles, plus purchases</span>']] : [])) ?>

    <?php if ($with_hours) { ?>
        <?= reportSectionOpen('Where the hours went', 'Each person') ?>
            <div class="card-body">
                <?= reportChart([
                    'type' => 'bar', 'horizontal' => true, 'stacked' => true,
                    'labels' => array_column($with_hours, 'person'),
                    'series' => [
                        ['label' => 'Billable', 'data' => array_map(function ($r) { return round($r['billable'] / 3600, 2); }, $with_hours), 'tone' => 'success', 'stack' => 'h'],
                        ['label' => 'Other tickets', 'data' => array_map(function ($r) { return round($r['other'] / 3600, 2); }, $with_hours), 'tone' => 'accent', 'stack' => 'h'],
                        ['label' => 'Admin & work', 'data' => array_map(function ($r) { return round($r['work'] / 3600, 2); }, $with_hours), 'tone' => 'purple', 'stack' => 'h'],
                        ['label' => 'Lost', 'data' => array_map(function ($r) { return round($r['lost'] / 3600, 2); }, $with_hours), 'tone' => 'danger', 'stack' => 'h'],
                        ['label' => 'Leave', 'data' => array_map(function ($r) { return round($r['leave'] / 3600, 2); }, $with_hours), 'tone' => 'warning', 'stack' => 'h'],
                    ],
                    'format' => 'hours', 'legend' => true,
                ], '', 'Hours per person by kind') ?>
            </div>
        </div>
    <?php } ?>

    <?= reportSectionOpen('Payroll', count($periods) . ' pay period' . (count($periods) === 1 ? '' : 's') . ' in these dates') ?>
        <?= reportTable($columns, $rows, ['empty' => 'No hours in these dates.', 'totals' => $table_totals,
            'totals_html' => ['approved' => '', 'share' => $table_totals['share'] === null ? '' : reportPercent(round($table_totals['share']))]]) ?>
        <div class="card-footer small text-body-secondary">
            Ticket time is the time logged on ticket replies each day (billable when the ticket is billable) - as it was when the period was approved,
            or as it is now for periods not approved yet. Paid hours are hours worked plus paid breaks and paid leave, less hours on unpaid codes;
            lunch and other break codes are not hours worked.
            <?= $sum['auto_lost'] ? 'Lost time includes ' . escapeHtml(reportHours($sum['auto_lost'] * 60, 2)) . ' h of normal hours nothing accounted for' . ($settings['auto_lost_paid'] ? '' : ' (not paid)') . '. ' : '' ?>
            <?= $overtime ? 'Overtime is hours over ' . implode(' and then over ', array_filter([$settings['ot_day'] ? mitTsHours($settings['ot_day'], true, 'decimal') . ' h a day' : '', $settings['ot_week'] ? mitTsHours($settings['ot_week'], true, 'decimal') . ' h a week' : ''])) . ', for hourly people, on the day it is worked. ' : '' ?>
            Charge codes, overtime and each person's schedule are set in Admin > Timesheets.
        </div>
    </div>

</div>

<?php require_once "../../includes/footer.php"; ?>
<script src="/js/reports.js"></script>
