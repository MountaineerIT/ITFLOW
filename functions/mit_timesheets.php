<?php

/*
 * Mountaineer IT - timesheets (database updates M012, M013).
 *
 * Every agent (unless left out on the People tab) keeps a timesheet per pay period (semi-monthly by default - 1st to 15th, 16th to the
 * end). Everything is set in Admin > Timesheets (admin/mit_timesheets.php): company settings in
 * mit_settings (ts_*, mitTsSettings), charge codes, and each person's schedule, approver, employee ID
 * and pay type (mit_timesheet_people); personal choices in mit_user_preferences (timesheet.*).
 * PTO / sick balances are in functions/mit_leave.php, mileage and purchases to pay back in
 * functions/mit_reimbursements.php, California time records (start / meal / end times, meal premiums, California
 * overtime, signed attestations) in functions/mit_ts_california.php (all required at the end).
 * A day's hours are:
 *   - ticket time, filled in on its own from the time logged on ticket replies that day - billable
 *     when the ticket is billable, the rest "other ticket time";
 *   - hours put against charge codes (mit_charge_codes): work (Admin, Training ...), lost time,
 *     leave (PTO, Holiday, Sick ...) and breaks (Lunch - logged by the person; not worked, unpaid unless
 *     the code is paid), each paid or unpaid;
 *   - paid short breaks, worked out from the hours worked (Admin > Timesheets, e.g. 15 minutes for every
 *     4 hours; a person can have their own rule) - nothing to log; they count as paid time and toward
 *     overtime, not toward the billable share.
 * The person submits the period, someone with Full Timesheets access approves it (or sends it
 * back), and an approved period is locked. Approving copies the period's ticket time and unaccounted
 * time in as entries (source ticket_billable / ticket_other / auto_lost, code 0), so payroll does not
 * move if a reply or a schedule changes later. Overtime is worked out from those with the current
 * overtime settings.
 *
 * Permission module_timesheets: anyone can keep their own; View 1 sees everyone's and the payroll
 * report (agent/reports/mit_payroll.php), Edit 2 corrects other people's, Full 3 approves (and may
 * change their own after submitting). Admins are Full.
 *
 * A person's own approver (People tab) can see and approve their timesheets too.
 * Optional: overtime over a day / week, unaccounted hours as lost time (unpaid unless set to paid),
 * reminders (cron job mit_timesheets, cron/mit_timesheets.php), notes on hours (required on some
 * codes), codes for some roles only. People can be left out of timesheets (People tab, "Keeps a
 * timesheet" - database update M015).
 *
 * Pages: agent/mit_timesheet.php (one person's period), agent/mit_timesheets.php (everyone, one
 * period), agent/modals/mit_timesheet/day.php (a day's tickets), handlers agent/post/mit_timesheets.php,
 * side menu agent/includes/mit_timesheet_nav.php, Admin > Timesheets (admin/mit_timesheets.php),
 * grid script agent/js/mit_timesheet.js.
 */

// break: lunch and the like - accounted for (not lost) but not worked; unpaid unless the code says paid (M016)
const MIT_TS_KINDS = ['work' => 'Work', 'lost' => 'Lost time', 'leave' => 'Leave', 'break' => 'Break / lunch (not worked)'];
const MIT_TS_PERIODS = ['semi_monthly' => 'Semi-monthly (1st-15th, 16th-end)', 'monthly' => 'Monthly', 'biweekly' => 'Every two weeks', 'weekly' => 'Weekly'];
const MIT_TS_PAY_TYPES = ['hourly' => 'Hourly', 'salary' => 'Salaried (no overtime)', 'contractor' => 'Contractor (no overtime, no California rules)'];
const MIT_TS_PAY_TYPE_SHORT = ['hourly' => 'Hourly', 'salary' => 'Salaried', 'contractor' => 'Contractor'];
const MIT_TS_WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
const MIT_TS_STATUSES = [
    'open' => ['Open', 'text-bg-light border'],
    'submitted' => ['Submitted', 'text-bg-warning'],
    'returned' => ['Sent back', 'text-bg-danger'],
    'approved' => ['Approved', 'text-bg-success'],
];

/** Has database update M012 run? */
function mitTsReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_timesheets'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Are timesheets in use: database update M012 run, and switched on (Admin > Settings > Modules)? */
function mitTsOn() {
    return mitTsReady() && mitModuleOn('timesheets');
}

/** The signed-in agent's Timesheets level (0 = own timesheet only). */
function mitTsLevel() {
    return mitTsReady() ? intval(lookupUserPermission('module_timesheets')) : 0;
}

/**
 * The report's entry in ITFlow's report catalogue (functions/reports.php). Switched off (Admin > Settings >
 * Modules), it names a feature reportFeatureOn() does not know, so the catalogue hides it and reportEnforce() refuses it.
 */
function mitTsReportEntry() {
    return [
        'title' => 'Timesheets & Payroll', 'icon' => 'fa-business-time', 'module' => 'module_timesheets', 'feature' => mitModuleOn('timesheets') ? [] : ['mit_timesheets'],
        'description' => 'Hours for each person by charge code - billable, other ticket time, admin, lost time and leave - added up for payroll.',
    ];
}

/*
 * ---------- settings ----------
 */

/**
 * The company's timesheet settings, with today's behavior as the defaults:
 *   week_start 1 (Mon) | 7 (Sun); approval 1/0; future all | leave | none (days after today);
 *   autolock_days (0 = never - otherwise a period locks that many days after it ends);
 *   remind_due 1/0 (on a period's last day); remind_late_days (0 = off); format decimal | hm;
 *   rounding 0 | 6 | 15 | 30 (ticket time per day); ticket_time 1/0; ticket_internal 1/0 (internal
 *   notes' time counts); billable all | flag | client (what timesheets count as billable); schedule '' (from Admin > SLA) or minutes Mon..Sun;
 *   ot_day / ot_week minutes (0 = off); auto_lost 1/0; auto_lost_paid 1/0 (unaccounted time is
 *   unpaid unless this is on); break_minutes / break_per - paid breaks, break_minutes for every break_per
 *   minutes worked (0 = off; a person can have their own - mitTsBreakRule); unallocated_code - the charge code that
 *   time worked but not on a code goes on (California days, mitTsUnallocatedCode; 0 = left as "Not on a code").
 */
function mitTsSettings() {
    static $settings = null;
    if ($settings === null) {
        $defaults = ['week_start' => 1, 'approval' => 1, 'future' => 'all', 'autolock_days' => 0, 'remind_due' => 0, 'remind_late_days' => 0,
            'format' => 'decimal', 'rounding' => 0, 'ticket_time' => 1, 'ticket_internal' => 1, 'billable' => 'all', 'schedule' => '',
            'ot_day' => 0, 'ot_week' => 0, 'auto_lost' => 0, 'auto_lost_paid' => 0, 'break_minutes' => 0, 'break_per' => 240, 'unallocated_code' => 0];
        $settings = [];
        foreach ($defaults as $key => $default) {
            $value = mitSetting("ts_$key", $default);
            $settings[$key] = is_int($default) ? intval($value) : (string) $value;
        }
        $settings['week_start'] = $settings['week_start'] === 7 ? 7 : 1;
        $settings['future'] = in_array($settings['future'], ['all', 'leave', 'none'], true) ? $settings['future'] : 'all';
        $settings['format'] = $settings['format'] === 'hm' ? 'hm' : 'decimal';
        $settings['rounding'] = in_array($settings['rounding'], [0, 6, 15, 30], true) ? $settings['rounding'] : 0;
        // What counts as billable on timesheets (not invoicing): all ticket time, tickets marked billable, or every ticket for a client
        $settings['billable'] = in_array($settings['billable'], ['all', 'flag', 'client'], true) ? $settings['billable'] : 'all';
        $settings['schedule'] = mitTsScheduleClean($settings['schedule']) ?? '';
    }
    return $settings;
}

/** "480,480,480,480,480,0,0" if it is a valid week of minutes, else null. */
function mitTsScheduleClean($text) {
    $parts = explode(',', (string) $text);
    if (count($parts) !== 7) {
        return null;
    }
    foreach ($parts as $part) {
        if (!preg_match('/^\d{1,4}$/', trim($part)) || intval($part) > 1440) {
            return null;
        }
    }
    return implode(',', array_map('intval', $parts));
}

/** Hours a day from a form's schedule[1..7] - "480,480,..." , '' when all empty, or null when one cannot be read. */
function mitTsScheduleFromPost($input) {
    $minutes = [];
    $any = false;
    foreach (range(1, 7) as $n) {
        $text = trim((string) ($input[$n] ?? ''));
        $any = $any || $text !== '';
        $m = mitTsParseHours($text);
        if ($m === null || $m > 1440) {
            return null;
        }
        $minutes[] = $m;
    }
    return $any ? implode(',', $minutes) : '';
}

/** The company's normal week, [1..7 => minutes]: the setting, or the business days and hours in Admin > SLA. */
function mitTsCompanySchedule() {
    $schedule = mitTsSettings()['schedule'];
    if ($schedule !== '') {
        return array_combine(range(1, 7), array_map('intval', explode(',', $schedule)));
    }
    $sla = getSlaSettings();
    $days = $sla['business_days'] ?: [1, 2, 3, 4, 5];
    $minutes = (int) round(reportWorkdayHours() * 60);
    $week = [];
    foreach (range(1, 7) as $day) {
        $week[$day] = in_array($day, $days, true) ? $minutes : 0;
    }
    return $week;
}

/** Each person's timesheet details (People tab): user_id => [schedule, approver_id, employee_id, pay_type]. */
function mitTsPeople() {
    global $mysqli;
    static $people = null;
    if ($people === null) {
        $people = [];
        try {
            // Every column on purpose: later updates add to this small table (M014 hire_date) and it must read before they run
            $result = mysqli_query($mysqli, "SELECT * FROM mit_timesheet_people");
            while ($result && $row = mysqli_fetch_assoc($result)) {
                $people[intval($row['user_id'])] = $row;
            }
        } catch (Throwable $e) {
            // database update M013 not run yet
        }
    }
    return $people;
}

/** One person's details, with the defaults. */
function mitTsPerson($user_id) {
    $row = mitTsPeople()[intval($user_id)] ?? [];
    return [
        'schedule' => mitTsScheduleClean($row['schedule'] ?? '') ?? '',
        'approver_id' => intval($row['approver_id'] ?? 0),
        'employee_id' => (string) ($row['employee_id'] ?? ''),
        'pay_type' => isset(MIT_TS_PAY_TYPES[$row['pay_type'] ?? '']) ? $row['pay_type'] : 'hourly',
        // Their own paid-break rule (M016) - null = the company's
        'break_minutes' => isset($row['break_minutes']) ? intval($row['break_minutes']) : null,
        'break_per' => isset($row['break_per']) ? intval($row['break_per']) : null,
        // California non-exempt rules (M018): 1 / 0, null = by pay type - see mitTsCaOn()
        'ca_rules' => isset($row['ca_rules']) && $row['ca_rules'] !== '' ? intval($row['ca_rules']) : null,
    ];
}

/** Admin > Users > New / Edit: how they are paid - overtime and the California rules follow it. Empty before update M013. */
function mitTsPayTypeField($user_id, $col = 'col-12') {
    if (!mitTsReady()) {
        return '';
    }
    $current = mitTsPerson($user_id)['pay_type'];
    $html = '<div class="' . $col . '"><label class="form-label">Employment</label><div class="input-group">'
        . '<span class="input-group-text"><i class="fas fa-fw fa-id-badge"></i></span><select class="form-select" name="mit_pay_type">';
    foreach (MIT_TS_PAY_TYPES as $key => $label) {
        $html .= '<option value="' . $key . '"' . ($key === $current ? ' selected' : '') . '>' . escapeHtml($label) . '</option>';
    }
    return $html . '</select></div><div class="form-text">Timesheets: salaried and contractors get no overtime; contractors are never under the California rules.</div></div>';
}

/** Save the Employment field (a no-op when the form did not have it). */
function mitTsSavePayType($user_id, array $post) {
    global $mysqli;
    $user_id = intval($user_id);
    if (!$user_id || !isset($post['mit_pay_type']) || !isset(MIT_TS_PAY_TYPES[$post['mit_pay_type']]) || !mitTsReady()) {
        return;
    }
    $pay_type = $post['mit_pay_type'];
    mysqli_query($mysqli, "INSERT INTO mit_timesheet_people SET user_id = $user_id, pay_type = '$pay_type' ON DUPLICATE KEY UPDATE pay_type = '$pay_type'");
}

/** A person's paid-break rule: [minutes, for every so many minutes worked] - their own, else the company's. [0, x] = none. */
function mitTsBreakRule($user_id) {
    $person = mitTsPerson($user_id);
    $settings = mitTsSettings();
    $minutes = $person['break_minutes'] ?? $settings['break_minutes'];
    $per = $person['break_per'] ?? $settings['break_per'];
    return [max(0, intval($minutes)), max(30, intval($per))];
}

/** Paid break minutes earned by a day's worked minutes under a rule, e.g. 15 for every 240 -> 30 for 8 hours. */
function mitTsBreakFor($worked, array $rule) {
    [$minutes, $per] = $rule;
    return $minutes > 0 && $worked > 0 ? intdiv(intval($worked), $per) * $minutes : 0;
}

/*
 * Time zones (M023): ITFlow runs the whole request in the company's time zone (Admin > Settings > Localization) and
 * stores ticket replies in it. A remote person can have their own (People tab, or Account > Preferences): their
 * timesheet's "today", future days and the day their ticket time falls on follow their clock. Typed times stay as typed.
 */

/** Has database update M023 added the time zone column yet? */
function mitTsZoneColumn() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM mit_timesheet_people LIKE 'time_zone'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The person's own time zone, or '' for the company's. */
function mitTsOwnZone($user_id) {
    $zone = (string) (mitTsPeople()[intval($user_id)]['time_zone'] ?? '');
    return $zone !== '' && $zone !== date_default_timezone_get() && in_array($zone, timezone_identifiers_list(), true) ? $zone : '';
}

/** The time zone a person's timesheet days follow. */
function mitTsZone($user_id) {
    return mitTsOwnZone($user_id) ?: date_default_timezone_get();
}

/** Today's date where the person is. */
function mitTsToday($user_id) {
    $zone = mitTsOwnZone($user_id);
    return $zone ? (new DateTime('now', new DateTimeZone($zone)))->format('Y-m-d') : date('Y-m-d');
}

/** Has database update M024 added the record of time zone changes yet? */
function mitTsZoneChangesReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ts_zone_changes'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * A person's time zone changes, oldest first: [['at' => company-time 'Y-m-d H:i:s' it counts from, 'zone' => zone or ''
 * for the company's]]. $forget = true drops what was read (after a change).
 */
function mitTsZoneChanges($user_id, $forget = false) {
    global $mysqli;
    static $cache = [];
    $user_id = intval($user_id);
    if ($forget) {
        unset($cache[$user_id]);
        return [];
    }
    if (!isset($cache[$user_id])) {
        $cache[$user_id] = [];
        if (mitTsZoneChangesReady()) {
            $result = mysqli_query($mysqli, "SELECT effective_at, time_zone FROM mit_ts_zone_changes WHERE user_id = $user_id ORDER BY effective_at, change_id");
            while ($row = mysqli_fetch_assoc($result)) {
                $zone = (string) $row['time_zone'];
                $cache[$user_id][] = ['at' => $row['effective_at'], 'zone' => in_array($zone, timezone_identifiers_list(), true) ? $zone : ''];
            }
        }
    }
    return $cache[$user_id];
}

/**
 * The zone that was in force for a person at a company-time moment: [zone or '' (the company's), first date it counts
 * on there or null]. Ticket time logged before a change stays where it was; with no record of changes (before database
 * update M024) it is their zone now.
 */
function mitTsZoneAt($user_id, $datetime) {
    $changes = mitTsZoneChanges($user_id);
    if (!$changes) {
        return [mitTsOwnZone($user_id), null];
    }
    $found = ['', null];
    foreach ($changes as $change) {
        if ($change['at'] > $datetime) {
            break;
        }
        $zone = $change['zone'] === date_default_timezone_get() ? '' : $change['zone'];
        // The first day it counts on is the day the change came in where they were before - after the timesheets handed in
        $from = (new DateTime($change['at']))->setTimezone(new DateTimeZone($found[0] ?: date_default_timezone_get()))->format('Y-m-d');
        $found = [$zone, $from];
    }
    return $found;
}

/**
 * A date and time as ITFlow stores them (company time) -> the date it was where the person was then (the zone in force
 * at that moment). Never earlier than the day a later zone started counting, so a change can't move time back into a
 * period that was already submitted or approved.
 */
function mitTsLocalDate($user_id, $datetime) {
    [$zone, $from] = mitTsZoneAt($user_id, (string) $datetime);
    $date = $zone ? (new DateTime((string) $datetime))->setTimezone(new DateTimeZone($zone))->format('Y-m-d') : substr((string) $datetime, 0, 10);
    return $from !== null && $date < $from ? $from : $date;
}

/** A zone for people to read, e.g. "America/Los Angeles (UTC-07)". */
function mitTsZoneLabel($zone) {
    $offset = (new DateTime('now', new DateTimeZone($zone)))->format('P');
    return str_replace('_', ' ', $zone) . ' (UTC' . ($offset === '+00:00' ? '' : preg_replace('/:00$/', '', $offset)) . ')';
}

/** A <select> of time zones; '' = the company's. */
function mitTsZoneSelect($name, $value, $id = '') {
    $company = date_default_timezone_get();
    $html = '<select class="form-select select2" name="' . escapeHtml($name) . '"' . ($id ? ' id="' . escapeHtml($id) . '"' : '') . '>'
        . '<option value="">The company\'s - ' . escapeHtml(mitTsZoneLabel($company)) . '</option>';
    foreach (timezone_identifiers_list() as $zone) {
        $html .= '<option value="' . escapeHtml($zone) . '"' . ($zone === $value && $zone !== $company ? ' selected' : '') . '>' . escapeHtml(mitTsZoneLabel($zone)) . '</option>';
    }
    return $html . '</select>';
}

/**
 * Save a person's time zone ('' or an unknown one = the company's) and log it. The change counts from now - or, when
 * they have a submitted or approved timesheet that ends later, from the day after it - so hours already handed in stay
 * as they were. Returns the company-time moment it counts from, or null when nothing changed.
 */
function mitTsSaveZone($user_id, $zone) {
    global $mysqli, $session_user_id;
    if (!mitTsZoneColumn()) {
        return null;
    }
    $user_id = intval($user_id);
    $zone = (string) $zone;
    $zone = in_array($zone, timezone_identifiers_list(), true) && $zone !== date_default_timezone_get() ? $zone : '';
    $old = mitTsOwnZone($user_id);
    if ($zone === $old) {
        return null;
    }
    $company = new DateTimeZone(date_default_timezone_get());
    $effective = date('Y-m-d H:i:s');
    $locked = mysqli_fetch_row(mysqli_query($mysqli, "SELECT MAX(period_end) FROM mit_timesheets WHERE user_id = $user_id AND status IN ('submitted', 'approved')"))[0] ?? null;
    if ($locked) {
        $after = (new DateTime(date('Y-m-d', strtotime("$locked +1 day")) . ' 00:00:00', new DateTimeZone($old ?: date_default_timezone_get())))->setTimezone($company)->format('Y-m-d H:i:s');
        $effective = max($effective, $after);
    }
    $sql = $zone === '' ? 'NULL' : "'" . escapeSql($zone) . "'";
    if (mitTsZoneChangesReady()) {
        mysqli_query($mysqli, "INSERT INTO mit_ts_zone_changes SET user_id = $user_id, time_zone = $sql, effective_at = '$effective',
            changed_by = " . intval($session_user_id ?? 0) . ", changed_by_name = '" . escapeSql(mb_substr(mitTsWho(), 0, 200)) . "'");
        mitTsZoneChanges($user_id, true);
    }
    mysqli_query($mysqli, "INSERT INTO mit_timesheet_people SET user_id = $user_id, time_zone = $sql ON DUPLICATE KEY UPDATE time_zone = $sql");
    mitTsForget();
    logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . ' changed the timesheet time zone of user ' . $user_id . ' from ' . ($old ?: 'the company\'s')
        . ' to ' . ($zone ?: 'the company\'s') . ', counting from ' . $effective), 0, $user_id);
    return $effective;
}

/** Does this person keep a timesheet? (People tab - on unless turned off; M015.) */
function mitTsKeepsTimesheet($user_id) {
    $row = mitTsPeople()[intval($user_id)] ?? [];
    return !isset($row['keeps_timesheet']) || intval($row['keeps_timesheet']) === 1;
}

/** Has database update M015 added the "keeps a timesheet" column yet? */
function mitTsKeepsColumn() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM mit_timesheet_people LIKE 'keeps_timesheet'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Has database update M016 added the per-person break columns yet? */
function mitTsBreakColumns() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM mit_timesheet_people LIKE 'break_per'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The first day a person can owe hours: their hire date, else the day their ITFlow login was made. */
function mitTsStartDate($user_id) {
    global $mysqli;
    static $created = null;
    $hire = (string) (mitTsPeople()[intval($user_id)]['hire_date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire)) {
        return $hire;
    }
    if ($created === null) {
        $created = [];
        $result = mysqli_query($mysqli, "SELECT user_id, DATE(user_created_at) AS d FROM users WHERE user_type = 1");
        while ($row = mysqli_fetch_assoc($result)) {
            $created[intval($row['user_id'])] = (string) $row['d'];
        }
    }
    return $created[intval($user_id)] ?? '';
}

/** A person's normal week, [1..7 => minutes]. */
function mitTsSchedule($user_id) {
    $own = mitTsPerson($user_id)['schedule'];
    return $own !== '' ? array_combine(range(1, 7), array_map('intval', explode(',', $own))) : mitTsCompanySchedule();
}

/** The minutes a person is expected to work on a date - none on closure days. */
function mitTsExpected($user_id, $date) {
    if (mitTsClosure($date) !== '') {
        return 0;
    }
    return mitTsSchedule($user_id)[intval(date('N', strtotime($date)))] ?? 0;
}

/** The hour format for this person: their choice, else the company's - decimal | hm. */
function mitTsFormat($user_id = null) {
    global $session_user_id;
    $mine = (string) mitUserPreference($user_id ?? $session_user_id, 'timesheet.format', '');
    return in_array($mine, ['decimal', 'hm'], true) ? $mine : mitTsSettings()['format'];
}

/*
 * ---------- pay periods ----------
 */

/** [type, anchor] - the anchor is a first day, for every-two-weeks. */
function mitTsPeriodSettings() {
    $type = (string) mitSetting('timesheet_period', 'semi_monthly');
    $anchor = (string) mitSetting('timesheet_anchor', '2026-01-05');
    return [isset(MIT_TS_PERIODS[$type]) ? $type : 'semi_monthly', preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor) ? $anchor : '2026-01-05'];
}

/** The pay period a date falls in: ['start', 'end', 'label']. */
function mitTsPeriodFor($date = null) {
    [$type, $anchor] = mitTsPeriodSettings();
    $time = strtotime($date ?: date('Y-m-d')) ?: time();
    $day = intval(date('j', $time));
    switch ($type) {
        case 'monthly':
            $start = date('Y-m-01', $time);
            $end = date('Y-m-t', $time);
            break;
        case 'weekly':
            $start = mitTsWeekStart(date('Y-m-d', $time));
            $end = date('Y-m-d', strtotime("$start +6 days"));
            break;
        case 'biweekly':
            $length = 14;
            $anchor_time = strtotime($anchor);
            $days = (int) round(($time - $anchor_time) / 86400);
            $offset = (($days % $length) + $length) % $length;
            $start = date('Y-m-d', strtotime("-$offset days", $time));
            $end = date('Y-m-d', strtotime('+' . ($length - 1) . ' days', strtotime($start)));
            break;
        default:
            $start = $day <= 15 ? date('Y-m-01', $time) : date('Y-m-16', $time);
            $end = $day <= 15 ? date('Y-m-15', $time) : date('Y-m-t', $time);
    }
    return ['start' => $start, 'end' => $end, 'label' => mitTsRangeText($start, $end)];
}

/** "Oct 1 - 15, 2026" */
function mitTsRangeText($start, $end) {
    $s = strtotime($start);
    $e = strtotime($end);
    if (date('Y-m', $s) === date('Y-m', $e)) {
        return date('M j', $s) . ' - ' . date('j, Y', $e);
    }
    return date('M j', $s) . ' - ' . date('M j, Y', $e);
}

/** The period before (-1) or after (+1) this one. */
function mitTsPeriodShift(array $period, $step) {
    return $step < 0 ? mitTsPeriodFor(date('Y-m-d', strtotime($period['start'] . ' -1 day'))) : mitTsPeriodFor(date('Y-m-d', strtotime($period['end'] . ' +1 day')));
}

/** The period starting on ?start= (any date in it will do), or the current one (where $user_id is, when given). */
function mitTsPeriodFromRequest($value, $user_id = 0) {
    $value = (string) $value;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? mitTsPeriodFor($value) : mitTsPeriodFor($user_id ? mitTsToday($user_id) : null);
}

/** The dates of a period, Y-m-d. */
function mitTsDates(array $period) {
    $dates = [];
    for ($t = strtotime($period['start']), $guard = 0; $t <= strtotime($period['end']) && $guard < 40; $t = strtotime('+1 day', $t), $guard++) {
        $dates[] = date('Y-m-d', $t);
    }
    return $dates;
}

/** Every date from $from to $to, Y-m-d - for reports over any dates (mitTsDates stops at a pay period's length). */
function mitTsDateRange($from, $to) {
    $dates = [];
    for ($t = strtotime($from), $end = strtotime($to), $guard = 0; $t && $t <= $end && $guard < 4000; $t = strtotime('+1 day', $t), $guard++) {
        $dates[] = date('Y-m-d', $t);
    }
    return $dates;
}

/** The first day of the week a date is in (weeks start on Monday or Sunday - a setting). */
function mitTsWeekStart($date) {
    $offset = (intval(date('N', strtotime($date))) - mitTsSettings()['week_start'] + 7) % 7;
    return date('Y-m-d', strtotime("$date -$offset days"));
}

/** The period's dates in weeks, for the grid. */
function mitTsWeeks(array $period) {
    $weeks = [];
    foreach (mitTsDates($period) as $date) {
        $weeks[mitTsWeekStart($date)][] = $date;
    }
    return array_values($weeks);
}

/** A closure day's name (Admin > SLA), or ''. */
function mitTsClosure($date) {
    return (string) (getBusinessHolidays()[$date] ?? '');
}

/*
 * ---------- hours ----------
 */

/** Minutes as hours for people: 450 -> "7.5" (or "7:30" in the hours-and-minutes format), 0 -> "". */
function mitTsHours($minutes, $blank_zero = true, $format = null) {
    $minutes = intval($minutes);
    if ($minutes === 0 && $blank_zero) {
        return '';
    }
    if (($format ?? mitTsFormat()) === 'hm') {
        return ($minutes < 0 ? '-' : '') . intdiv(abs($minutes), 60) . ':' . sprintf('%02d', abs($minutes) % 60);
    }
    return rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');
}

/** "7.5", "7:30", "450m", "7h30" -> minutes; '' -> 0; null when it cannot be read. */
function mitTsParseHours($text) {
    $text = strtolower(trim(str_replace(',', '.', (string) $text)));
    if ($text === '') {
        return 0;
    }
    // Hours and minutes: 7:30, 7h30, 7h 30m - the minutes must be under 60
    if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $text, $m) || preg_match('/^(\d{1,2})h\s*(\d{1,2})?m?$/', $text, $m)) {
        return intval($m[2] ?? 0) < 60 ? intval($m[1]) * 60 + intval($m[2] ?? 0) : null;
    }
    if (preg_match('/^(\d{1,4})\s*m(in)?$/', $text, $m)) {
        return intval($m[1]);
    }
    if (preg_match('/^\d{0,2}(\.\d{1,2})?$/', $text) && $text !== '.') {
        return (int) round((float) $text * 60);
    }
    return null;
}

/** Hours of any size for settings ("80", "3.33", "7:30") -> minutes; '' -> 0; null when it cannot be read. */
function mitTsParseHoursAny($text) {
    $text = trim(str_replace(',', '.', (string) $text));
    if ($text === '') {
        return 0;
    }
    if (is_numeric($text) && (float) $text >= 0 && (float) $text <= 10000) {
        return (int) round((float) $text * 60);
    }
    return mitTsParseHours($text);
}

/*
 * ---------- charge codes ----------
 */

/** code_id => row, in order. Archived ones too when asked (an old period can still have them). */
function mitTsCodes($with_archived = false) {
    global $mysqli;
    static $cache = [];
    $key = $with_archived ? 'all' : 'active';
    if (!isset($cache[$key])) {
        $cache[$key] = [];
        if (mitTsReady()) {
            // Every column on purpose: M013 added columns, and the codes must still read before it has run
            $result = mysqli_query($mysqli, "SELECT * FROM mit_charge_codes" . ($with_archived ? '' : ' WHERE code_archived_at IS NULL') . " ORDER BY code_sort, code_name");
            while ($row = mysqli_fetch_assoc($result)) {
                $cache[$key][intval($row['code_id'])] = $row;
            }
        }
    }
    return $cache[$key];
}

/** Codes that can take time worked but not on a code: active, paid, work or lost time. */
function mitTsUnallocatedCodes() {
    return array_filter(mitTsCodes(), function ($code) {
        return in_array($code['code_kind'], ['work', 'lost'], true) && intval($code['code_paid']);
    });
}

/** The code that time worked but not on a code goes on (Admin > Timesheets), or null. */
function mitTsUnallocatedCode() {
    return mitTsUnallocatedCodes()[mitTsSettings()['unallocated_code']] ?? null;
}

/** The active codes a person may use - all, or those given to their role. */
function mitTsCodesFor($user_id) {
    global $mysqli;
    static $roles = [];
    $user_id = intval($user_id);
    if (!isset($roles[$user_id])) {
        $roles[$user_id] = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_role_id FROM users WHERE user_id = $user_id"))[0] ?? 0);
    }
    $role = $roles[$user_id];
    return array_filter(mitTsCodes(), function ($code) use ($role) {
        $roles = array_filter(array_map('intval', explode(',', (string) ($code['code_roles'] ?? ''))));
        return !$roles || in_array($role, $roles, true);
    });
}

/** A small color dot for a code. */
function mitTsCodeDot(array $code) {
    $color = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $code['code_color']) ? $code['code_color'] : '#6c757d';
    return '<span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background: ' . $color . '"></span>';
}

/*
 * ---------- people and timesheets ----------
 */

/**
 * Agents who keep timesheets: active ones not left out on the People tab, plus anyone named in $also
 * (someone who has left, or was left out, but has hours in the dates being looked at). $everyone: the
 * people left out too (Admin > Timesheets > People). user_id => row
 */
function mitTsUsers(array $also = [], $everyone = false) {
    global $mysqli;
    static $cache = [];
    $also = array_values(array_unique(array_filter(array_map('intval', $also))));
    sort($also);
    // Read once per request for the same question (pages and reports ask it for each person)
    $key = implode(',', $also) . ($everyone ? '+all' : '');
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    // Asked for people who keep a timesheet anyway: the same list as with nobody named
    if ($also && !$everyone && !array_diff($also, array_keys(mitTsUsers()))) {
        return $cache[$key] = mitTsUsers();
    }
    $result = mysqli_query($mysqli, "SELECT user_id, user_name, user_email, user_avatar, user_status FROM users WHERE user_type = 1
        AND ((user_status = 1 AND user_archived_at IS NULL)" . ($also ? ' OR user_id IN (' . implode(',', $also) . ')' : '') . ") ORDER BY user_name");
    $users = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $user_id = intval($row['user_id']);
        if ($everyone || mitTsKeepsTimesheet($user_id) || in_array($user_id, $also, true)) {
            $users[$user_id] = $row;
        }
    }
    return $cache[$key] = $users;
}

/** People with anything on a timesheet in these dates (hours, or a stored timesheet) - so someone who has left still shows. */
function mitTsUsersWithActivity($from, $to) {
    global $mysqli;
    $ids = [];
    if (!mitTsReady()) {
        return $ids;
    }
    $from = escapeSql($from);
    $to = escapeSql($to);
    $result = mysqli_query($mysqli, "SELECT DISTINCT user_id FROM mit_time_entries WHERE entry_date BETWEEN '$from' AND '$to' AND minutes > 0
        UNION SELECT user_id FROM mit_timesheets WHERE period_end >= '$from' AND period_start <= '$to' AND status <> 'open'"
        . (mitReimbReady() ? " UNION SELECT user_id FROM mit_reimbursements WHERE entry_date BETWEEN '$from' AND '$to'" : ''));
    while ($row = mysqli_fetch_row($result)) {
        $ids[] = intval($row[0]);
    }
    return $ids;
}

/** The person's timesheet for a period - a stored row, or an open one not saved yet (sheet_id 0). */
function mitTsSheet($user_id, array $period) {
    global $mysqli;
    // s.* on purpose: M013 added the reminder columns, and a timesheet must still read before it has run
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT s.*, u.user_name AS decided_by_name FROM mit_timesheets s LEFT JOIN users u ON u.user_id = s.decided_by
        WHERE s.user_id = " . intval($user_id) . " AND s.period_start = '" . escapeSql($period['start']) . "'"));
    return $row ?: ['sheet_id' => 0, 'user_id' => intval($user_id), 'period_start' => $period['start'], 'period_end' => $period['end'], 'status' => 'open',
        'submitted_at' => null, 'decided_by' => 0, 'decided_by_name' => null, 'decided_at' => null, 'decision_note' => null, 'reminded_due_at' => null, 'reminded_late_at' => null];
}

/** The stored row, made if it is not there yet. */
function mitTsSheetSaved($user_id, array $period) {
    global $mysqli;
    mysqli_query($mysqli, "INSERT IGNORE INTO mit_timesheets SET user_id = " . intval($user_id) . ", period_start = '" . escapeSql($period['start']) . "', period_end = '" . escapeSql($period['end']) . "'");
    return mitTsSheet($user_id, $period);
}

/** Timesheets of several people for one period: user_id => row (only stored ones). */
function mitTsSheets(array $user_ids, array $period) {
    global $mysqli;
    $user_ids = array_filter(array_map('intval', $user_ids));
    $sheets = [];
    if ($user_ids) {
        $result = mysqli_query($mysqli, "SELECT sheet_id, user_id, period_start, period_end, status FROM mit_timesheets WHERE period_start = '" . escapeSql($period['start']) . "' AND user_id IN (" . implode(',', $user_ids) . ")");
        while ($row = mysqli_fetch_assoc($result)) {
            $sheets[intval($row['user_id'])] = $row;
        }
    }
    return $sheets;
}

/** A status badge. */
function mitTsBadge($status) {
    [$label, $class] = MIT_TS_STATUSES[$status] ?? MIT_TS_STATUSES['open'];
    return '<span class="badge ' . $class . '">' . $label . '</span>';
}

/** The people whose own approver (People tab) is the signed-in agent. */
function mitTsMyReports() {
    global $session_user_id;
    $ids = [];
    foreach (mitTsPeople() as $user_id => $row) {
        if (intval($row['approver_id']) === intval($session_user_id) && $user_id !== intval($session_user_id)) {
            $ids[] = $user_id;
        }
    }
    return $ids;
}

/** May the signed-in agent see this person's timesheets? Their own, Timesheets View, or their approver. */
function mitTsMaySee($user_id) {
    global $session_user_id;
    return intval($user_id) === intval($session_user_id) || mitTsLevel() >= 1 || in_array(intval($user_id), mitTsMyReports(), true);
}

/** May the signed-in agent approve this person's timesheets? Full access, or their approver. */
function mitTsMayApprove($user_id) {
    return mitTsLevel() >= 3 || in_array(intval($user_id), mitTsMyReports(), true);
}

/** May the signed-in agent correct this person's hours? Timesheets Edit, or their approver. */
function mitTsMayCorrect($user_id) {
    return mitTsLevel() >= 2 || in_array(intval($user_id), mitTsMyReports(), true);
}

/**
 * The people the signed-in agent may look at (Everyone page, person picker, payroll report): everyone
 * with Timesheets View, else the people they approve. With a period, people who have left or were
 * left out but have hours in it are included. user_id => row
 */
function mitTsVisibleUsers(?array $period = null) {
    $users = mitTsUsers($period ? mitTsUsersWithActivity($period['start'], $period['end']) : []);
    if (mitTsLevel() >= 1) {
        return $users;
    }
    return array_intersect_key($users, array_flip(mitTsMyReports()));
}

/** Has the period locked itself (a set number of days after it ended)? */
function mitTsAutoLocked(array $sheet) {
    $days = mitTsSettings()['autolock_days'];
    return $days > 0 && $sheet['status'] !== 'approved' && mitTsToday($sheet['user_id']) > date('Y-m-d', strtotime($sheet['period_end'] . " +$days days"));
}

/**
 * Days from $start to $end that sit in an approved timesheet of this person: date => true. Normally none for an open
 * period, but after the pay period setting changes a new period can overlap one approved under the old setting - those
 * days stay locked.
 */
function mitTsApprovedDates($user_id, $start, $end) {
    return array_fill_keys(array_keys(mitTsLockedDates($user_id, $start, $end, ['approved'])), true);
}

/**
 * Days from $start to $end that sit in a timesheet of this person with one of these statuses: date => status (approved
 * before submitted). $except_start leaves out the timesheet starting that day (the one being worked on) - so after a pay
 * period change the days of an older timesheet that is submitted or approved are not changed from a newer one.
 */
function mitTsLockedDates($user_id, $start, $end, array $statuses, $except_start = null) {
    global $mysqli;
    $locked = [];
    $statuses = array_intersect($statuses, array_keys(MIT_TS_STATUSES));
    if (!$statuses || !mitTsReady()) {
        return $locked;
    }
    $result = mysqli_query($mysqli, "SELECT period_start, period_end, status FROM mit_timesheets WHERE user_id = " . intval($user_id) . "
        AND status IN ('" . implode("', '", $statuses) . "') AND period_start <= '" . escapeSql($end) . "' AND period_end >= '" . escapeSql($start) . "'"
        . ($except_start !== null ? " AND period_start <> '" . escapeSql($except_start) . "'" : '') . " ORDER BY status = 'approved'");
    while ($row = mysqli_fetch_assoc($result)) {
        for ($day = max($row['period_start'], $start); $day <= min($row['period_end'], $end); $day = date('Y-m-d', strtotime("$day +1 day"))) {
            $locked[$day] = $row['status'];
        }
    }
    return $locked;
}

/**
 * Can time on $date change for this person from outside their timesheet (a ticket note's time, a note archived, a ticket
 * deleted)? '' or why not: 'submitted' / 'approved' (a timesheet holding the day is), or 'closed' (its period locked itself
 * and the signed-in agent may not correct their timesheet - the same rule as mitTsWhyLocked).
 */
function mitTsDayLock($user_id, $date) {
    global $session_user_id;
    if (!mitTsReady() || !mitModuleOn('timesheets') || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
        return '';
    }
    $user_id = intval($user_id);
    $held = mitTsLockedDates($user_id, $date, $date, ['submitted', 'approved']);
    if ($held) {
        return $held[$date];
    }
    $correct = $user_id === intval($session_user_id) ? mitTsLevel() >= 3 : mitTsMayCorrect($user_id);
    return !$correct && mitTsAutoLocked(mitTsSheet($user_id, mitTsPeriodFor($date))) ? 'closed' : '';
}

/** Words for mitTsDayLock(): "is on a submitted timesheet" ... */
function mitTsDayLockText($why) {
    return [
        'submitted' => 'is on a submitted timesheet',
        'approved' => 'is on an approved timesheet',
        'closed' => 'is on a timesheet that has closed (' . mitTsSettings()['autolock_days'] . ' days after its period ended)',
    ][$why] ?? 'is locked';
}

/** What to do about it, for messages. */
function mitTsDayLockHelp($why) {
    return [
        'submitted' => 'Take the timesheet back (or send it back) first.',
        'approved' => 'Reopen the timesheet first.',
        'closed' => 'Ask someone who corrects timesheets.',
    ][$why] ?? '';
}

/**
 * Hook in ITFlow's reply edit (agent/post/ticket.php edit_ticket_reply) and reply archive (archive_ticket_reply, with
 * $new_time '00:00:00'): the time on a reply counts on its author's timesheet, so once that day is on a submitted or
 * approved timesheet (or one that has closed itself) its time stays as it was - refused with a message (and nothing
 * saved) when it would change. The words can still be edited with the time left alone.
 */
function mitTsReplyTimeGuard($ticket_reply_id, $new_time) {
    global $mysqli;
    if (!mitTsReady() || !mitModuleOn('timesheets') || !mitTsSettings()['ticket_time']) {
        return;
    }
    $reply = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_reply_by, ticket_reply_type, ticket_reply_time_worked, TIME_TO_SEC(ticket_reply_time_worked) AS seconds, ticket_reply_created_at,
        ticket_reply_archived_at FROM ticket_replies WHERE ticket_reply_id = " . intval($ticket_reply_id)));
    if (!$reply || $reply['ticket_reply_archived_at'] !== null || (string) $reply['ticket_reply_time_worked'] === (string) $new_time
        || (intval($reply['seconds']) === 0 && mitReplyTimesSeconds($new_time) === 0)
        || (!mitTsSettings()['ticket_internal'] && $reply['ticket_reply_type'] === 'Internal')) {
        return;
    }
    // The day it was worked (its times, functions/mit_reply_times.php), else where the person was when it was posted
    $times = function_exists('mitReplyTimesOf') ? mitReplyTimesOf($ticket_reply_id) : null;
    $date = $times ? substr($times['work_start'], 0, 10) : mitTsLocalDate($reply['ticket_reply_by'], $reply['ticket_reply_created_at']);
    $why = mitTsDayLock($reply['ticket_reply_by'], $date);
    if ($why !== '') {
        flashAlert('The time on this note stays ' . escapeHtml($reply['ticket_reply_time_worked']) . ': ' . date('M j', strtotime($date)) . ' ' . escapeHtml(mitTsDayLockText($why)) . '. ' . mitTsDayLockHelp($why), 'error');
        redirect();
    }
}

/**
 * Hook before a ticket is deleted (functions/ticket.php ticketDeleteForGood) or all of a client's tickets are
 * (agent/post/client.php delete_client, $client_id): ticket time on a submitted timesheet is still worked out from the
 * notes, so deleting them would change hours waiting for approval - refused with a message. (An approved period keeps
 * its own copy of its ticket time, so it does not change.)
 */
function mitTsDeleteGuard($ticket_id, $client_id = 0) {
    global $mysqli;
    if (!mitTsReady() || !mitModuleOn('timesheets') || !mitTsSettings()['ticket_time']) {
        return;
    }
    $which = intval($client_id) ? 'ticket_client_id = ' . intval($client_id) : 'ticket_id = ' . intval($ticket_id);
    $submitted = [];
    $result = mysqli_query($mysqli, "SELECT user_id, period_start, period_end FROM mit_timesheets WHERE status = 'submitted'");
    while ($row = mysqli_fetch_assoc($result)) {
        $submitted[intval($row['user_id'])][] = $row;
    }
    if (!$submitted) {
        return;
    }
    $ready = function_exists('mitReplyTimesReady') && mitReplyTimesReady();
    $result = mysqli_query($mysqli, "SELECT ticket_reply_by, ticket_reply_created_at, ticket_prefix, ticket_number, " . ($ready ? 'mrt.work_start' : 'NULL AS work_start') . "
        FROM ticket_replies JOIN tickets ON ticket_id = ticket_reply_ticket_id " . ($ready ? 'LEFT JOIN mit_reply_times mrt ON mrt.reply_id = ticket_reply_id' : '') . "
        WHERE $which AND ticket_reply_archived_at IS NULL AND ticket_reply_time_worked > '00:00:00'
        AND ticket_reply_by IN (" . implode(',', array_keys($submitted)) . ")" . (mitTsSettings()['ticket_internal'] ? '' : " AND ticket_reply_type <> 'Internal'"));
    while ($row = mysqli_fetch_assoc($result)) {
        $user_id = intval($row['ticket_reply_by']);
        $date = $row['work_start'] ? substr($row['work_start'], 0, 10) : mitTsLocalDate($user_id, $row['ticket_reply_created_at']);
        foreach ($submitted[$user_id] as $sheet) {
            if ($date >= $sheet['period_start'] && $date <= $sheet['period_end']) {
                $who = mitTsUsers([$user_id])[$user_id]['user_name'] ?? "user $user_id";
                flashAlert('Not deleted: ticket ' . escapeHtml(ticketReference($row)) . ' has time on ' . escapeHtml($who) . "'s timesheet for "
                    . escapeHtml(mitTsRangeText($sheet['period_start'], $sheet['period_end'])) . ', which is waiting for approval. Approve it or send it back first.', 'error');
                redirect();
            }
        }
    }
}

/** May the signed-in agent change this timesheet's hours? Returns '' or why not. */
function mitTsWhyLocked(array $sheet) {
    global $session_user_id;
    $own = intval($sheet['user_id']) === intval($session_user_id);
    // Edit access corrects other people's timesheets; on your own it takes Full access to get past the locks
    $correct = $own ? mitTsLevel() >= 3 : mitTsMayCorrect($sheet['user_id']);
    if ($sheet['status'] === 'approved') {
        return 'This period is approved and locked.' . (mitTsMayApprove($sheet['user_id']) ? ' Reopen it to change it.' : '');
    }
    if (!$own && !$correct) {
        return 'You can see this timesheet but not change it.';
    }
    if ($own && !$correct && $sheet['status'] === 'submitted') {
        return 'Submitted for approval - take it back to change it.';
    }
    if (!$correct && mitTsAutoLocked($sheet)) {
        return 'This period closed ' . mitTsSettings()['autolock_days'] . ' days after it ended - ask your manager to change it.';
    }
    return '';
}

/** mitTsWhyLocked() for one day: also locked when another timesheet holds it that is submitted or approved (from before a pay period change). */
function mitTsWhyDayLocked($user_id, $date) {
    $period = mitTsPeriodFor($date);
    $held = mitTsLockedDates($user_id, $date, $date, ['submitted', 'approved'], $period['start']);
    if ($held) {
        return 'This day is on another timesheet that is ' . $held[$date] . ' and locked.';
    }
    return mitTsWhyLocked(mitTsSheet($user_id, $period));
}

/** Can hours go on this date with this code? '' or why not (future days, a setting - "today" where the person is). */
function mitTsFutureBlock($date, array $code, $user_id = 0) {
    $future = mitTsSettings()['future'];
    if ($date <= ($user_id ? mitTsToday($user_id) : date('Y-m-d')) || $future === 'all' || ($future === 'leave' && $code['code_kind'] === 'leave')) {
        return '';
    }
    return $future === 'leave' ? 'Only leave can go on days still to come.' : 'Hours go on once the day has come.';
}

/*
 * ---------- what is on a timesheet ----------
 */

/**
 * Per-request memory for what one page or save works out several times (ticket notes, days, stored figures): the first
 * call for a kind and arguments runs $make, later ones get its answer. mitTsForget() drops it all - after anything is
 * written that these read.
 */
function mitTsMemo($kind, array $args, ?callable $make = null) {
    static $memo = [];
    if ($make === null) {
        $memo = [];
        return null;
    }
    $key = $kind . ':' . json_encode($args);
    if (!array_key_exists($key, $memo)) {
        $memo[$key] = $make();
    }
    return $memo[$key];
}

/** Forget what mitTsMemo() remembered (after a write). */
function mitTsForget() {
    mitTsMemo('', []);
}

/**
 * Entries copied in when a period is approved (code 0 unless said), so payroll does not move afterwards: ticket time
 * (ticket_billable / ticket_other), unaccounted time (auto_lost - a 0 row marks a period stored this way), paid breaks
 * (break_paid), time not on a code put on the default code (unallocated, code_id = that code), and the pay figures as
 * they were worked out then - pay_rules (one row every day: minutes 1 = a California non-exempt day, 0 = not; it marks
 * the day as stored), overtime, double, meal_premium, rest_premium (minutes, only when there are some).
 */
const MIT_TS_STORED_SOURCES = ['ticket_billable', 'ticket_other', 'auto_lost', 'break_paid', 'unallocated', 'pay_rules', 'overtime', 'double', 'meal_premium', 'rest_premium'];

/**
 * The pay figures stored on approval (MIT_TS_STORED_SOURCES): [user_id][date] => ['ca' => bool, 'overtime', 'double',
 * 'meal_premium', 'rest_premium'] for days approved since they were stored. Other days are worked out live.
 */
function mitTsStoredRules(array $user_ids, $from, $to) {
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
    sort($user_ids);
    if (!$user_ids || !mitTsReady()) {
        return [];
    }
    return mitTsMemo('rules', [$user_ids, $from, $to], function () use ($user_ids, $from, $to) {
        global $mysqli;
        $rules = [];
        $values = [];
        $result = mysqli_query($mysqli, "SELECT user_id, entry_date, source, minutes FROM mit_time_entries WHERE user_id IN (" . implode(',', $user_ids) . ")
            AND source IN ('pay_rules', 'overtime', 'double', 'meal_premium', 'rest_premium') AND entry_date BETWEEN '" . escapeSql($from) . "' AND '" . escapeSql($to) . "'");
        while ($row = mysqli_fetch_assoc($result)) {
            $values[intval($row['user_id'])][$row['entry_date']][$row['source']] = intval($row['minutes']);
        }
        foreach ($values as $user_id => $dates) {
            foreach ($dates as $date => $v) {
                if (isset($v['pay_rules'])) {
                    $rules[$user_id][$date] = ['ca' => $v['pay_rules'] === 1, 'overtime' => $v['overtime'] ?? 0, 'double' => $v['double'] ?? 0,
                        'meal_premium' => $v['meal_premium'] ?? 0, 'rest_premium' => $v['rest_premium'] ?? 0];
                }
            }
        }
        return $rules;
    });
}

/** Hours put against charge codes: [user_id][date][code_id] => ['minutes', 'note']. */
function mitTsEntries(array $user_ids, $from, $to) {
    global $mysqli;
    $user_ids = array_filter(array_map('intval', $user_ids));
    $entries = [];
    if ($user_ids && mitTsReady()) {
        $result = mysqli_query($mysqli, "SELECT user_id, entry_date, code_id, minutes, note FROM mit_time_entries
            WHERE source = 'manual' AND user_id IN (" . implode(',', $user_ids) . ") AND entry_date BETWEEN '" . escapeSql($from) . "' AND '" . escapeSql($to) . "'");
        while ($row = mysqli_fetch_assoc($result)) {
            $entries[intval($row['user_id'])][$row['entry_date']][intval($row['code_id'])] = ['minutes' => intval($row['minutes']), 'note' => $row['note']];
        }
    }
    return $entries;
}

/**
 * Ticket time from the replies, as it is now: [user_id][date] => ['billable', 'other'] minutes - on the day the work was
 * done (a note's times, functions/mit_reply_times.php), else the day it was posted where the person was.
 */
function mitTsTicketMinutesLive(array $user_ids, $from, $to) {
    $user_ids = array_filter(array_map('intval', $user_ids));
    $minutes = [];
    if (!$user_ids || !mitTsSettings()['ticket_time']) {
        return $minutes;
    }
    $seconds = [];
    foreach (mitTsReplyWorkRows($user_ids, $from, $to) as $row) {
        $key = $row['billable'] ? 'b' : 'o';
        $seconds[$row['user_id']][$row['date']][$key] = ($seconds[$row['user_id']][$row['date']][$key] ?? 0) + $row['seconds'];
    }
    foreach ($seconds as $user_id => $days) {
        foreach ($days as $date => $sum) {
            $minutes[$user_id][$date] = ['billable' => mitTsRound(($sum['b'] ?? 0) / 60), 'other' => mitTsRound(($sum['o'] ?? 0) / 60)];
        }
    }
    return $minutes;
}

/** Minutes rounded to the nearest 6, 15 or 30 when set (ticket time per day). */
function mitTsRound($minutes) {
    $step = mitTsSettings()['rounding'];
    return $step ? (int) (round($minutes / $step) * $step) : (int) round($minutes);
}

/**
 * Ticket time for the timesheets: what was copied in when the period was approved, and the
 * replies as they are now for periods not approved. [user_id][date] => ['billable', 'other'], and
 * on approved days also 'approved' => true and the stored 'auto_lost' (when it was stored).
 */
function mitTsTicketMinutes(array $user_ids, $from, $to) {
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
    sort($user_ids);
    return mitTsMemo('tickets', [$user_ids, $from, $to], function () use ($user_ids, $from, $to) {
        global $mysqli;
        $minutes = mitTsTicketMinutesLive($user_ids, $from, $to);
        if (!$user_ids || !mitTsReady()) {
            return $minutes;
        }
        // Approved periods overlapping these dates: their stored ticket time replaces the live figures. Unaccounted time
        // was stored on approval from its update on (at least a 0 row in the period); a period approved before that has
        // none, and keeps having it worked out live
        $result = mysqli_query($mysqli, "SELECT s.user_id, s.period_start, s.period_end, EXISTS (SELECT 1 FROM mit_time_entries e WHERE e.user_id = s.user_id
                AND e.source = 'auto_lost' AND e.entry_date BETWEEN s.period_start AND s.period_end) AS lost_stored
            FROM mit_timesheets s WHERE s.status = 'approved' AND s.user_id IN (" . implode(',', $user_ids) . ") AND s.period_end >= '" . escapeSql($from) . "' AND s.period_start <= '" . escapeSql($to) . "'");
        $approved = [];  // user_id => date => true
        while ($sheet = mysqli_fetch_assoc($result)) {
            $u = intval($sheet['user_id']);
            foreach (mitTsDates(['start' => max($from, $sheet['period_start']), 'end' => min($to, $sheet['period_end'])]) as $date) {
                // Paid breaks: as stored on approval - none for periods approved before they existed
                $minutes[$u][$date] = ['billable' => 0, 'other' => 0, 'break_paid' => 0, 'approved' => true] + ($sheet['lost_stored'] ? ['auto_lost' => 0] : []);
                $approved[$u][$date] = true;
            }
        }
        if (!$approved) {
            return $minutes;
        }
        $stored = mysqli_query($mysqli, "SELECT user_id, entry_date, source, code_id, minutes FROM mit_time_entries WHERE user_id IN (" . implode(',', array_keys($approved)) . ")
            AND source IN ('ticket_billable', 'ticket_other', 'auto_lost', 'break_paid', 'unallocated') AND entry_date BETWEEN '" . escapeSql($from) . "' AND '" . escapeSql($to) . "'");
        $keys = ['ticket_billable' => 'billable', 'ticket_other' => 'other', 'auto_lost' => 'auto_lost', 'break_paid' => 'break_paid'];
        while ($row = mysqli_fetch_assoc($stored)) {
            $u = intval($row['user_id']);
            if (!isset($approved[$u][$row['entry_date']])) {
                continue;
            }
            if ($row['source'] === 'unallocated') {
                // Time not on a code that went on the default code: [code_id => minutes]
                $minutes[$u][$row['entry_date']]['unallocated'][intval($row['code_id'])] = intval($row['minutes']);
                continue;
            }
            $minutes[$u][$row['entry_date']][$keys[$row['source']]] = intval($row['minutes']);
        }
        return $minutes;
    });
}

/**
 * Each person's days: [user_id][date] => ['billable', 'other', 'work', 'lost', 'auto_lost', 'leave_paid', 'leave_unpaid',
 * 'unpaid_work', 'codes' => [code_id => minutes]] in minutes. auto_lost (when the setting is on) is the part of a
 * finished day's expected hours that nothing accounts for - not before the person started, and as stored on an
 * approved period. It counts as lost time, and as unpaid unless the "paid" setting is on.
 */
function mitTsDays(array $user_ids, $from, $to) {
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
    sort($user_ids);
    return mitTsMemo('days', [$user_ids, $from, $to], function () use ($user_ids, $from, $to) {
        return mitTsDaysRead($user_ids, $from, $to);
    });
}

/** mitTsDays() worked out. */
function mitTsDaysRead(array $user_ids, $from, $to) {
    $codes = mitTsCodes(true);
    $entries = mitTsEntries($user_ids, $from, $to);
    $tickets = mitTsTicketMinutes($user_ids, $from, $to);
    $auto = mitTsSettings()['auto_lost'];
    $auto_paid = mitTsSettings()['auto_lost_paid'];
    $blank = ['billable' => 0, 'other' => 0, 'work' => 0, 'lost' => 0, 'auto_lost' => 0, 'leave_paid' => 0, 'leave_unpaid' => 0, 'unpaid_work' => 0,
        'break' => 0, 'break_code_paid' => 0, 'break_paid' => 0, 'codes' => [],
        // California non-exempt (functions/mit_ts_california.php): the typed start / end times are the hours worked
        'ca' => false, 'clocked' => 0, 'unallocated' => 0, 'meal_premium' => 0, 'rest_premium' => 0,
        // Of the time not on a code, what went on the default code (Admin > Timesheets): code_id => minutes
        'filled' => []];
    $fill_code = mitTsUnallocatedCode();
    $ca_days = mitTsCaDays($user_ids, $from, $to);
    $days = [];
    foreach ($user_ids as $user_id) {
        $user_id = intval($user_id);
        $days[$user_id] = [];
        $started = mitTsStartDate($user_id);
        $today = mitTsToday($user_id);
        $break_rule = mitTsBreakRule($user_id);
        foreach (mitTsDateRange($from, $to) as $date) {
            $d = $blank;
            $d['billable'] = $tickets[$user_id][$date]['billable'] ?? 0;
            $d['other'] = $tickets[$user_id][$date]['other'] ?? 0;
            foreach ($entries[$user_id][$date] ?? [] as $code_id => $entry) {
                $m = $entry['minutes'];
                $code = $codes[$code_id] ?? ['code_kind' => 'work', 'code_paid' => 1];
                $d['codes'][$code_id] = $m;
                if ($code['code_kind'] === 'leave') {
                    $d[intval($code['code_paid']) ? 'leave_paid' : 'leave_unpaid'] += $m;
                } elseif ($code['code_kind'] === 'break') {
                    $d['break'] += $m;
                    if (intval($code['code_paid'])) {
                        $d['break_code_paid'] += $m;
                    }
                } else {
                    $d[$code['code_kind'] === 'lost' ? 'lost' : 'work'] += $m;
                    if (!intval($code['code_paid'])) {
                        $d['unpaid_work'] += $m;
                    }
                }
            }
            // Paid breaks for the hours worked that day (as stored, on an approved period)
            if (isset($tickets[$user_id][$date]['break_paid'])) {
                $d['break_paid'] = $tickets[$user_id][$date]['break_paid'];
            } else {
                $d['break_paid'] = mitTsBreakFor($d['billable'] + $d['other'] + $d['work'] + $d['lost'] - $d['unpaid_work'], $break_rule);
            }
            if (isset($tickets[$user_id][$date]['auto_lost'])) {
                $d['auto_lost'] = $tickets[$user_id][$date]['auto_lost'];
            } elseif ($auto && $date < $today && $date >= $started) {
                // Lunch and breaks are accounted for - they are not lost time
                $accounted = $d['billable'] + $d['other'] + $d['work'] + $d['lost'] + $d['leave_paid'] + $d['leave_unpaid'] + $d['break'] + $d['break_paid'];
                $d['auto_lost'] = max(0, mitTsExpected($user_id, $date) - $accounted);
            }
            $d['lost'] += $d['auto_lost'];
            if (!$auto_paid) {
                $d['unpaid_work'] += $d['auto_lost'];
            }
            if (isset($ca_days[$user_id][$date])) {
                // Every minute between start and end is worked and paid: no unaccounted (lost) time, no unpaid work codes,
                // and rest breaks are inside those times rather than added on
                $c = $ca_days[$user_id][$date];
                $d['lost'] -= $d['auto_lost'];
                $d['auto_lost'] = 0;
                $d['unpaid_work'] = 0;
                $d['break_paid'] = 0;
                $d['ca'] = true;
                $d['clocked'] = $c['clocked'];
                $d['unallocated'] = max(0, $c['clocked'] - ($d['billable'] + $d['other'] + $d['work'] + $d['lost']));
                // Onto the default code: as stored on an approved day, else the code set now
                $fills = !empty($tickets[$user_id][$date]['approved']) ? ($tickets[$user_id][$date]['unallocated'] ?? [])
                    : ($fill_code ? [intval($fill_code['code_id']) => $d['unallocated']] : []);
                $approved_day = !empty($tickets[$user_id][$date]['approved']);
                foreach ($fills as $code_id => $m) {
                    $m = min($m, $d['unallocated']);
                    // Not past the code's daily limit (hours typed on it count) - the rest stays "Not on a code"
                    $max = intval($codes[$code_id]['code_daily_max'] ?? 0);
                    if ($max > 0 && !$approved_day) {
                        $m = min($m, max(0, $max - ($d['codes'][$code_id] ?? 0)));
                    }
                    if ($m <= 0) {
                        continue;
                    }
                    $kind = ($codes[$code_id]['code_kind'] ?? 'work') === 'lost' ? 'lost' : 'work';
                    $d[$kind] += $m;
                    $d['codes'][$code_id] = ($d['codes'][$code_id] ?? 0) + $m;
                    $d['filled'][$code_id] = $m;
                    $d['unallocated'] -= $m;
                }
                $d['meal_premium'] = $c['meal_premium'];
                $d['rest_premium'] = $c['rest_premium'];
            }
            $days[$user_id][$date] = $d;
        }
    }
    return $days;
}

/** Hours worked that are paid, for a day from mitTsDays() - paid breaks count (short breaks are work time for overtime). */
function mitTsDayWorked(array $d) {
    if (!empty($d['ca'])) {
        return $d['clocked'];
    }
    return $d['billable'] + $d['other'] + $d['work'] + $d['lost'] - $d['unpaid_work'] + $d['break_paid'] + $d['break_code_paid'];
}

/**
 * Totals for people over dates: user_id => ['billable', 'other', 'codes' => [code_id => minutes], 'work', 'lost', 'auto_lost',
 * 'leave_paid', 'unpaid', 'worked', 'leave', 'break' (lunch etc. logged), 'break_paid' (paid breaks, automatic and on paid break
 * codes), 'paid', 'total', 'overtime', 'regular'] - all minutes. Leave is paid and unpaid. Worked (and the billable share) leaves
 * breaks out; paid hours and the total count paid breaks.
 * Overtime (hourly people, when set): over the daily limit on each day, plus, going through each week day by day,
 * the hours past the weekly limit - so a week that crosses into the next pay period splits its overtime by date.
 * Approved days keep the overtime, double time and premiums they were approved with (mitTsStoredRules).
 */
function mitTsTotals(array $user_ids, $from, $to) {
    $week_from = mitTsWeekStart($from);
    $all_days = mitTsDays($user_ids, $week_from, $to);
    $stored = mitTsStoredRules($user_ids, $from, $to);
    $totals = [];
    foreach ($user_ids as $user_id) {
        $user_id = intval($user_id);
        $t = ['billable' => 0, 'other' => 0, 'codes' => [], 'work' => 0, 'lost' => 0, 'auto_lost' => 0, 'leave_paid' => 0, 'unpaid' => 0, 'overtime' => 0,
            'break' => 0, 'break_paid' => 0, 'double' => 0, 'clocked' => 0, 'unallocated' => 0, 'meal_premium' => 0, 'rest_premium' => 0, 'ca' => false];
        $ca_adjust = 0;  // California days: their hours worked are the typed times, not what is on codes
        $leave_unpaid = 0;
        $unpaid_work = 0;
        foreach ($all_days[$user_id] as $date => $d) {
            if ($date < $from) {
                continue;
            }
            foreach (['billable', 'other', 'work', 'lost', 'auto_lost', 'leave_paid', 'clocked', 'unallocated', 'meal_premium', 'rest_premium'] as $key) {
                $t[$key] += $d[$key];
            }
            foreach ($d['codes'] as $code_id => $m) {
                $t['codes'][$code_id] = ($t['codes'][$code_id] ?? 0) + $m;
            }
            $leave_unpaid += $d['leave_unpaid'];
            $unpaid_work += $d['unpaid_work'];
            $t['break'] += $d['break'];
            $t['break_paid'] += $d['break_paid'] + $d['break_code_paid'];
            if ($d['ca']) {
                $ca_adjust += $d['clocked'] - ($d['billable'] + $d['other'] + $d['work'] + $d['lost']);
                // On California rules (now, or on days approved that way)
                $t['ca'] = true;
            }
        }
        $t['unpaid'] = $leave_unpaid + $unpaid_work;
        $t['worked'] = $t['billable'] + $t['other'] + $t['work'] + $t['lost'] + $ca_adjust;
        $t['leave'] = $t['leave_paid'] + $leave_unpaid;
        $t['paid'] = $t['worked'] - $unpaid_work + $t['leave_paid'] + $t['break_paid'];
        $t['total'] = $t['worked'] + $t['leave'] + $t['break_paid'];

        $split = mitTsOvertimeSplit($user_id, $all_days[$user_id], mitTsCaOn($user_id, $to));
        foreach ($all_days[$user_id] as $date => $d) {
            if ($date >= $from) {
                $day = $stored[$user_id][$date] ?? $split[$date] ?? ['overtime' => 0, 'double' => 0];
                $t['overtime'] += $day['overtime'];
                $t['double'] += $day['double'];
            }
        }
        $t['regular'] = $t['paid'] - $t['overtime'] - $t['double'];
        $totals[$user_id] = $t;
    }
    return $totals;
}

/**
 * Overtime and double time each day as worked out now: date => ['overtime', 'double'] minutes, for a person's days from
 * mitTsDays() starting on the first day of a week. $ca: on California rules - California's overtime, double time and 7th
 * day on the hours worked (the typed times - or, on days before the rules started, the hours as they were); otherwise the
 * daily / weekly limits (Admin > Timesheets), for hourly people only.
 */
function mitTsOvertimeSplit($user_id, array $days, $ca) {
    $settings = mitTsSettings();
    $split = [];
    if ($ca) {
        foreach (mitTsCaOvertime(array_map('mitTsDayWorked', $days)) as $date => $day) {
            $split[$date] = ['overtime' => $day['overtime'], 'double' => $day['double']];
        }
    } elseif (($settings['ot_day'] || $settings['ot_week']) && mitTsPerson($user_id)['pay_type'] === 'hourly') {
        $week_regular = [];  // week start => regular minutes so far that week
        foreach ($days as $date => $d) {
            $worked = mitTsDayWorked($d);
            $over = $settings['ot_day'] ? max(0, $worked - $settings['ot_day']) : 0;
            $regular = $worked - $over;
            if ($settings['ot_week']) {
                $week = mitTsWeekStart($date);
                $so_far = $week_regular[$week] ?? 0;
                $past_limit = max(0, $so_far + $regular - $settings['ot_week']) - max(0, $so_far - $settings['ot_week']);
                $week_regular[$week] = $so_far + $regular;
                $over += $past_limit;
            }
            $split[$date] = ['overtime' => $over, 'double' => 0];
        }
    }
    return $split;
}

/** SQL for "this ticket's time is billable" on timesheets: all ticket time, tickets marked billable, or tickets for a client. */
function mitTsBillableSql($rule) {
    return $rule === 'flag' ? 'ticket_billable = 1' : ($rule === 'client' ? 'ticket_client_id > 0' : '1 = 1');
}

/** "62%" of worked hours on billable tickets, or '-'. */
function mitTsBillableShare(array $t) {
    return $t['worked'] > 0 ? round($t['billable'] / $t['worked'] * 100) . '%' : '-';
}

/** A day's tickets with time on them (worked that day): ticket rows with 'minutes' and 'ticket_billable'. */
function mitTsDayTickets($user_id, $date) {
    $tickets = [];
    foreach (mitTsReplyWorkRows([$user_id], $date, $date) as $row) {
        $id = $row['ticket_id'];
        $tickets[$id] = [
            'seconds' => $row['seconds'] + intval($tickets[$id]['seconds'] ?? 0),
            'ticket_id' => $id, 'ticket_prefix' => $row['ticket_prefix'], 'ticket_number' => $row['ticket_number'], 'ticket_subject' => $row['subject'],
            'ticket_billable' => $row['billable'], 'ticket_client_id' => $row['client_id'], 'client_name' => $row['client_name'],
        ];
    }
    usort($tickets, function ($a, $b) {
        return $b['seconds'] <=> $a['seconds'];
    });
    foreach ($tickets as &$row) {
        $row['minutes'] = (int) round($row['seconds'] / 60);
    }
    unset($row);
    return $tickets;
}

/*
 * ---------- changing a timesheet ----------
 */

/** Who is doing it, for logs (session name is stored SQL-escaped). */
function mitTsWho() {
    global $session_name;
    return stripslashes((string) $session_name);
}

/**
 * Save the grid: $cells[date][code_id] = hours text, $notes[date][code_id] = a note. Returns [ok, message].
 * Checks: dates in the period, codes the person may use (or already has hours on), days up to 24 hours with the
 * ticket time, a code's daily limit, a note where the code needs one, and the days-to-come setting.
 */
function mitTsSaveCells(array $sheet, array $period, array $cells, array $notes = []) {
    global $mysqli, $session_user_id;
    $user_id = intval($sheet['user_id']);
    $codes = mitTsCodes(true);
    $allowed = mitTsCodesFor($user_id);
    // Days another timesheet holds that is submitted or approved (from before a pay period change) are left as they are
    $dates = array_diff_key(array_flip(mitTsDates($period)), mitTsLockedDates($user_id, $period['start'], $period['end'], ['submitted', 'approved'], $period['start']));
    $tickets = mitTsTicketMinutes([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $current = mitTsEntries([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $wanted = [];
    $problems = [];
    foreach ($cells as $date => $by_code) {
        if (!isset($dates[$date]) || !is_array($by_code)) {
            continue;
        }
        foreach ($by_code as $code_id => $text) {
            $code_id = intval($code_id);
            $had = $current[$date][$code_id] ?? null;
            if (!isset($codes[$code_id]) || (!isset($allowed[$code_id]) && !$had)) {
                continue;
            }
            $code = $codes[$code_id];
            $label = date('M j', strtotime($date)) . ' ' . $code['code_name'];
            $minutes = mitTsParseHours($text);
            $note = trim((string) ($notes[$date][$code_id] ?? ($had['note'] ?? '')));
            if ($minutes === null || $minutes > 1440) {
                $problems[] = "$label: use e.g. 7.5 or 7:30";
                continue;
            }
            $was = intval($had['minutes'] ?? 0);
            // An archived code (or one no longer given to their role) keeps what it had, but takes nothing new
            if (($code['code_archived_at'] !== null || !isset($allowed[$code_id])) && $minutes > $was) {
                continue;
            }
            if ($minutes > $was && ($why = mitTsFutureBlock($date, $code, $user_id)) !== '') {
                $problems[] = "$label: $why";
                continue;
            }
            if ($minutes && intval($code['code_daily_max'] ?? 0) > 0 && $minutes > intval($code['code_daily_max'] ?? 0)) {
                $problems[] = "$label: at most " . mitTsHours($code['code_daily_max'], false) . ' hours a day';
                continue;
            }
            if ($minutes && intval($code['code_note_required'] ?? 0) && $note === '') {
                $problems[] = "$label: needs a note";
                continue;
            }
            $wanted[$date][$code_id] = [$minutes, mb_substr($note, 0, 255)];
        }
    }
    if ($problems) {
        return [false, 'Not saved - ' . implode('; ', array_slice($problems, 0, 4)) . (count($problems) > 4 ? ' (and ' . (count($problems) - 4) . ' more)' : '') . '.'];
    }
    $leave_wanted = [];
    foreach ($wanted as $date => $by_code) {
        foreach ($by_code as $code_id => [$minutes]) {
            $leave_wanted[$date][$code_id] = $minutes;
        }
    }
    if (($short = mitLeaveCheckSave($user_id, $leave_wanted, $current)) !== '') {
        return [false, "Not saved - $short."];
    }
    foreach ($wanted as $date => $by_code) {
        $day = array_sum(array_column($by_code, 0)) + ($tickets[$date]['billable'] ?? 0) + ($tickets[$date]['other'] ?? 0);
        foreach ($current[$date] ?? [] as $code_id => $entry) {
            if (!isset($by_code[$code_id])) {
                $day += $entry['minutes'];
            }
        }
        if ($day > 1440) {
            return [false, 'Not saved - ' . date('M j', strtotime($date)) . ' adds up to more than 24 hours.'];
        }
    }
    $changed = 0;
    $closure_code = mitTsClosureCode($user_id);
    foreach ($wanted as $date => $by_code) {
        foreach ($by_code as $code_id => [$minutes, $note]) {
            $had = $current[$date][$code_id] ?? null;
            if (intval($had['minutes'] ?? 0) === $minutes && (!$minutes || (string) ($had['note'] ?? '') === $note)) {
                continue;
            }
            $note_sql = $note === '' ? 'NULL' : "'" . escapeSql($note) . "'";
            // A closure day's filled-in hours that are cleared stay as a 0 row, so mitTsFillClosures leaves the day alone
            $keep_cleared = $minutes === 0 && $had && $closure_code && intval($closure_code['code_id']) === $code_id && mitTsClosure($date) !== '';
            if ($keep_cleared) {
                mysqli_query($mysqli, "UPDATE mit_time_entries SET minutes = 0, note = $note_sql, updated_by = " . intval($session_user_id) . "
                    WHERE user_id = $user_id AND entry_date = '" . escapeSql($date) . "' AND code_id = $code_id AND source = 'manual'");
            } elseif ($minutes === 0) {
                mysqli_query($mysqli, "DELETE FROM mit_time_entries WHERE user_id = $user_id AND entry_date = '" . escapeSql($date) . "' AND code_id = $code_id AND source = 'manual'");
            } else {
                mysqli_query($mysqli, "INSERT INTO mit_time_entries SET user_id = $user_id, entry_date = '" . escapeSql($date) . "', code_id = $code_id, source = 'manual',
                    minutes = $minutes, note = $note_sql, updated_by = " . intval($session_user_id) . "
                    ON DUPLICATE KEY UPDATE minutes = $minutes, note = $note_sql, updated_by = " . intval($session_user_id));
            }
            $changed++;
        }
    }
    if ($changed) {
        mitTsForget();
        // Signed (California): the signature no longer covers these hours - they sign again
        mitTsCaChangedAfterSigning($sheet, $period);
    }
    $typed = $changed; // by hand - closure days filled in below are not someone's correction
    $changed += mitTsFillClosures($user_id, $period);
    if ($changed) {
        mitTsSheetSaved($user_id, $period);
        if ($user_id !== intval($session_user_id)) {
            logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . " changed $changed entr" . ($changed === 1 ? 'y' : 'ies') . " on the timesheet of user $user_id for " . $period['label']), 0, $user_id);
            // The person is told (once an hour while someone works on their sheet - the day panel saves as it is typed)
            $text = mitTsWho() . ' changed hours on your timesheet for ' . $period['label'];
            $recent = mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM notifications WHERE notification_user_id = $user_id AND notification_type = 'Timesheet'
                AND notification = '" . escapeSql($text) . "' AND notification_timestamp > NOW() - INTERVAL 1 HOUR LIMIT 1"));
            if ($typed && intval($session_user_id) && !$recent) {
                mitTsNotify($user_id, $text, mitTsLink($user_id, $period), intval($sheet['sheet_id'] ?? 0));
            }
        }
    }
    return [true, $changed ? 'Timesheet saved' : 'Nothing changed'];
}

/** The code closure days are filled in with (the first one set to do it that the person may use), or null. */
function mitTsClosureCode($user_id) {
    foreach (mitTsCodesFor($user_id) as $code_id => $code) {
        if (intval($code['code_closure_fill'] ?? 0)) {
            return $code;
        }
    }
    return null;
}

/**
 * Closure days (Admin > SLA) up to today with nothing on that code: filled in with the person's normal hours for that
 * weekday. A day whose filled-in hours were cleared keeps a 0 row and is not filled again. Runs when the timesheet is
 * saved, submitted or approved, and from the cron job for the period just ended. Returns how many days were filled.
 */
function mitTsFillClosures($user_id, array $period) {
    global $mysqli, $session_user_id;
    $code = mitTsClosureCode($user_id);
    if (!$code) {
        return 0;
    }
    $user_id = intval($user_id);
    $code_id = intval($code['code_id']);
    $schedule = mitTsSchedule($user_id);
    $current = mitTsEntries([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $filled = 0;
    $started = mitTsStartDate($user_id);
    $today = mitTsToday($user_id);
    // Days another submitted or approved timesheet holds (from before a pay period change) are left as they are
    $held = mitTsLockedDates($user_id, $period['start'], $period['end'], ['submitted', 'approved'], $period['start']);
    foreach (mitTsDates($period) as $date) {
        $minutes = $schedule[intval(date('N', strtotime($date)))] ?? 0;
        if ($date > $today || $date < $started || mitTsClosure($date) === '' || !$minutes || isset($current[$date][$code_id]) || isset($held[$date])) {
            continue;
        }
        mysqli_query($mysqli, "INSERT IGNORE INTO mit_time_entries SET user_id = $user_id, entry_date = '$date', code_id = $code_id, source = 'manual',
            minutes = $minutes, note = '" . escapeSql(mb_substr(mitTsClosure($date), 0, 255)) . "', updated_by = " . intval($session_user_id ?? 0));
        $filled += mysqli_affected_rows($mysqli) > 0 ? 1 : 0;
    }
    if ($filled) {
        mitTsForget();
    }
    return $filled;
}

/** For a handler: the stored timesheet by id, if the agent may see it, with its period - or back with an error. */
function mitTsSheetForRequest($sheet_id) {
    global $mysqli;
    $row = mitTsReady() ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT sheet_id, user_id, period_start, period_end FROM mit_timesheets WHERE sheet_id = " . intval($sheet_id))) : null;
    if (!$row || !mitTsMaySee($row['user_id'])) {
        flashAlert('That timesheet does not exist, or it is not yours.', 'error');
        redirect();
    }
    // Its own dates - the pay period setting may have changed since it was kept
    $period = ['start' => $row['period_start'], 'end' => $row['period_end'], 'label' => mitTsRangeText($row['period_start'], $row['period_end'])];
    return [mitTsSheet($row['user_id'], $period), $period];
}

/** The people who approve timesheets (Full Timesheets access, or admins). user_id => row */
function mitTsApprovers() {
    global $mysqli;
    $result = mysqli_query($mysqli, "SELECT users.user_id, user_name, user_email FROM users
        JOIN user_roles ON role_id = users.user_role_id
        LEFT JOIN user_role_permissions urp ON urp.user_role_id = role_id
            AND urp.module_id = (SELECT module_id FROM modules WHERE module_name = 'module_timesheets' LIMIT 1)
        WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL
        AND (role_is_admin = 1 OR urp.user_role_permission_level >= 3)");
    $approvers = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $approvers[intval($row['user_id'])] = $row;
    }
    return $approvers;
}

/** Who approves this person: their own approver (People tab) if set and active, else everyone with Full access. */
function mitTsApproversFor($user_id) {
    $approver_id = mitTsPerson($user_id)['approver_id'];
    if ($approver_id && $approver_id !== intval($user_id)) {
        $approver = mitTsUsers([], true)[$approver_id] ?? null; // any active agent, timesheet or not
        if ($approver) {
            return [$approver_id => $approver];
        }
    }
    return mitTsApprovers();
}

/** An app notification for someone, and an email when they want reminders by email ($email true). */
function mitTsNotify($user_id, $text, $link, $entity_id, $email = false) {
    global $mysqli, $config_base_url, $config_smtp_provider, $config_mail_from_email, $config_mail_from_name;
    if (mitNotifyOn('timesheet_app')) {
        mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Timesheet', notification = '" . escapeSql($text) . "',
            notification_action = '" . escapeSql($link) . "', notification_user_id = " . intval($user_id) . ", notification_entity_id = " . intval($entity_id));
    }
    $user = $email && mitNotifyOn('timesheet_email') ? (mitTsUsers([$user_id])[intval($user_id)] ?? null) : null;
    if ($user && !empty($config_smtp_provider) && filter_var($user['user_email'], FILTER_VALIDATE_EMAIL)) {
        addToMailQueue([['from' => escapeSql(mitMailFrom()['email']), 'from_name' => escapeSql(mitMailFrom()['name']),
            'recipient' => escapeSql($user['user_email']), 'recipient_name' => escapeSql($user['user_name']), 'subject' => escapeSql($text),
            // mitMailBody, not escapeSql: escapeSql strips tags and would take the link out
            'body' => mitMailBody('Hello ' . escapeHtml($user['user_name']) . ',<br><br>' . escapeHtml($text) . '.<br><br><a href="https://' . escapeHtml($config_base_url . $link) . '">Open your timesheet</a>')]]);
    }
}

/** The timesheet page's link. */
function mitTsLink($user_id, array $period) {
    return '/agent/mit_timesheet.php?user_id=' . intval($user_id) . '&start=' . $period['start'];
}

/** Submit a period for approval. Returns [ok, message]. */
function mitTsSubmit(array $sheet, array $period) {
    // The same lock as approving: a submit and a decision (or a second submit) one at a time, on the state now
    $lock = 'ts_sheet_' . intval($sheet['user_id']) . '_' . $period['start'];
    if (!mitLock($lock)) {
        return [false, 'Someone else is working on this timesheet right now - try again.'];
    }
    try {
        return mitTsSubmitNow(mitTsSheet($sheet['user_id'], $period), $period);
    } finally {
        mitUnlock($lock);
    }
}

/** mitTsSubmit() under its lock. */
function mitTsSubmitNow(array $sheet, array $period) {
    global $mysqli, $session_user_id;
    if (!in_array($sheet['status'], ['open', 'returned'], true)) {
        return [false, 'This timesheet is already ' . strtolower(MIT_TS_STATUSES[$sheet['status']][0]) . '.'];
    }
    if (!mitTsSettings()['approval']) {
        return [false, 'Timesheets are not approved here (Admin > Timesheets) - saving is enough.'];
    }
    mitTsFillClosures($sheet['user_id'], $period);
    $sheet = mitTsSheetSaved($sheet['user_id'], $period);
    // California non-exempt: only once they have signed (mitTsCaAttest, from the submit form)
    if (mitTsCaOn($sheet['user_id'], $period['end']) && !mitTsCaAttestation($sheet['sheet_id'])) {
        return [false, 'It needs their signed attestation - they submit it themselves.'];
    }
    mysqli_query($mysqli, "UPDATE mit_timesheets SET status = 'submitted', submitted_at = NOW(), decision_note = NULL WHERE sheet_id = " . intval($sheet['sheet_id']) . " AND status IN ('open', 'returned')");
    if (mysqli_affected_rows($mysqli) !== 1) {
        return [false, 'This timesheet changed in the meantime - reload it and try again.'];
    }
    mitTsForget();
    $user = mitTsUsers([$sheet['user_id']])[intval($sheet['user_id'])] ?? ['user_name' => 'Someone'];
    foreach (mitTsApproversFor($sheet['user_id']) as $approver_id => $approver) {
        if ($approver_id !== intval($session_user_id)) {
            mitTsNotify($approver_id, $user['user_name'] . "'s timesheet for " . $period['label'] . ' is waiting for approval', mitTsLink($sheet['user_id'], $period), $sheet['sheet_id']);
        }
    }
    logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . ' submitted the timesheet of ' . $user['user_name'] . ' for ' . $period['label']), 0, intval($sheet['user_id']));
    return [true, 'Submitted for approval'];
}

/** Take a submitted period back (its owner, before it is decided). */
function mitTsWithdraw(array $sheet) {
    // Its own dates - the pay period setting may have changed since it was kept
    $period = ['start' => $sheet['period_start'], 'end' => $sheet['period_end'], 'label' => mitTsRangeText($sheet['period_start'], $sheet['period_end'])];
    $lock = 'ts_sheet_' . intval($sheet['user_id']) . '_' . $period['start'];
    if (!mitLock($lock)) {
        return [false, 'Someone else is working on this timesheet right now - try again.'];
    }
    try {
        return mitTsWithdrawNow(mitTsSheet($sheet['user_id'], $period), $period);
    } finally {
        mitUnlock($lock);
    }
}

/** mitTsWithdraw() under its lock. */
function mitTsWithdrawNow(array $sheet, array $period) {
    global $mysqli;
    if ($sheet['status'] !== 'submitted') {
        return [false, 'Only a submitted timesheet can be taken back.'];
    }
    mysqli_query($mysqli, "UPDATE mit_timesheets SET status = 'open', submitted_at = NULL WHERE sheet_id = " . intval($sheet['sheet_id']) . " AND status = 'submitted'");
    if (mysqli_affected_rows($mysqli) !== 1) {
        return [false, 'This timesheet changed in the meantime - reload it and try again.'];
    }
    mitTsCaSupersede($sheet['sheet_id']);
    mitTsForget();
    logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . ' took back the timesheet for ' . $period['label']), 0, intval($sheet['user_id']));
    return [true, 'Taken back - you can change it and submit it again'];
}

/**
 * Copy the period's ticket time, unaccounted time, paid breaks, time not on a code put on the default code, and each day's
 * pay figures (California or not, overtime, double time, meal / rest premiums) in as entries (MIT_TS_STORED_SOURCES - on
 * approval, before the status changes, so they are still worked out live), replacing any copied before. Days that sit in
 * another approved timesheet (from before a pay period change) keep that one's copy.
 */
function mitTsSnapshotTickets($user_id, array $period) {
    global $mysqli, $session_user_id;
    $user_id = intval($user_id);
    $others = mitTsLockedDates($user_id, $period['start'], $period['end'], ['approved'], $period['start']);
    $dates = array_values(array_diff(mitTsDates($period), array_keys($others)));
    if (!$dates) {
        return;
    }
    // Any copy left over goes first, so everything below is worked out live
    mysqli_query($mysqli, "DELETE FROM mit_time_entries WHERE user_id = $user_id AND source IN ('" . implode("', '", MIT_TS_STORED_SOURCES) . "')
        AND entry_date IN ('" . implode("', '", $dates) . "')");
    mitTsForget();
    $week_days = mitTsDays([$user_id], mitTsWeekStart($period['start']), $period['end'])[$user_id] ?? [];
    $split = mitTsOvertimeSplit($user_id, $week_days, mitTsCaOn($user_id, $period['end']));
    $live = mitTsTicketMinutesLive([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $add = function ($date, $source, $minutes, $code_id = 0) use ($mysqli, $user_id, $session_user_id) {
        mysqli_query($mysqli, "INSERT INTO mit_time_entries SET user_id = $user_id, entry_date = '" . escapeSql($date) . "', code_id = " . intval($code_id) . ", source = '$source',
            minutes = " . intval($minutes) . ", updated_by = " . intval($session_user_id ?? 0));
    };
    foreach ($dates as $i => $date) {
        $d = $week_days[$date];
        foreach ($d['filled'] as $code_id => $m) {
            $add($date, 'unallocated', $m, $code_id);
        }
        if ($d['break_paid'] > 0) {
            $add($date, 'break_paid', $d['break_paid']);
        }
        // Every day with some, and the first day always (a 0 there marks the period as stored - see mitTsTicketMinutes)
        if ($d['auto_lost'] > 0 || $i === 0) {
            $add($date, 'auto_lost', $d['auto_lost']);
        }
        foreach (['ticket_billable' => $live[$date]['billable'] ?? 0, 'ticket_other' => $live[$date]['other'] ?? 0] as $source => $minutes) {
            if ($minutes > 0) {
                $add($date, $source, $minutes);
            }
        }
        // The pay figures: every day (marks it stored), then what there is of the rest
        $add($date, 'pay_rules', $d['ca'] ? 1 : 0);
        foreach (['overtime' => $split[$date]['overtime'] ?? 0, 'double' => $split[$date]['double'] ?? 0, 'meal_premium' => $d['meal_premium'], 'rest_premium' => $d['rest_premium']] as $source => $minutes) {
            if ($minutes > 0) {
                $add($date, $source, $minutes);
            }
        }
    }
    mitTsForget();
}

/** Approve or send back a timesheet (Full access, or the person's approver). $decision 'approve' | 'return'. Returns [ok, message]. */
function mitTsDecide(array $sheet, array $period, $decision, $note = '') {
    // One decision at a time per timesheet (a double click, bulk approve and an approver at once), on its state now
    $lock = 'ts_sheet_' . intval($sheet['user_id']) . '_' . $period['start'];
    if (!mitLock($lock)) {
        return [false, 'Someone else is working on this timesheet right now - try again.'];
    }
    try {
        return mitTsDecideNow(mitTsSheet($sheet['user_id'], $period), $period, $decision, $note);
    } finally {
        mitUnlock($lock);
    }
}

/** mitTsDecide() under its lock. */
function mitTsDecideNow(array $sheet, array $period, $decision, $note = '') {
    global $mysqli, $session_user_id;
    $note = trim((string) $note);
    if ($decision === 'approve') {
        if (!in_array($sheet['status'], ['submitted', 'open', 'returned'], true)) {
            return [false, 'This timesheet is already approved.'];
        }
        if (mitTsCaOn($sheet['user_id'], $period['end']) && mitTsSettings()['approval'] && !mitTsCaAttestation($sheet['sheet_id'])) {
            return [false, 'It needs their signed attestation first - they sign it when they submit it.'];
        }
        // Times someone else changed after they signed: they acknowledge the change before it is approved
        if (mitTsCaOn($sheet['user_id'], $period['end']) && ($waiting = count(mitTsCaUnacknowledged($sheet['user_id'], $period)))) {
            return [false, "They have $waiting change" . ($waiting === 1 ? '' : 's') . ' to their times to acknowledge first - it shows on their timesheet.'];
        }
        // After a pay period change: an older timesheet holding some of these days and waiting for approval is decided first
        $waiting = mitTsLockedDates($sheet['user_id'], $period['start'], $period['end'], ['submitted'], $period['start']);
        if ($waiting) {
            return [false, 'Another of their timesheets (from before the pay period change) holds ' . date('M j', strtotime(array_key_first($waiting))) . ' and is waiting for approval - approve it or send it back first.'];
        }
        mitTsFillClosures($sheet['user_id'], $period);
        $sheet = mitTsSheetSaved($sheet['user_id'], $period);
        mitTsSnapshotTickets($sheet['user_id'], $period);
        mitLeaveAccruePeriod($sheet['user_id'], $period);
        mitReimbRecordExpenses($sheet['user_id'], $period);
        $reimb_note = mitReimbPayrollClose($sheet['user_id'], $period, $sheet['sheet_id']); // mileage and purchases paid with payroll
        mysqli_query($mysqli, "UPDATE mit_timesheets SET status = 'approved', decided_by = " . intval($session_user_id) . ", decided_at = NOW(),
            decision_note = " . ($note === '' ? 'NULL' : "'" . escapeSql(mb_substr($note, 0, 255)) . "'") . " WHERE sheet_id = " . intval($sheet['sheet_id']));
        $text = 'approved';
    } else {
        if ($sheet['status'] !== 'submitted' || $note === '') {
            return [false, $note === '' ? 'Say why it is being sent back.' : 'Only a submitted timesheet can be sent back.'];
        }
        mysqli_query($mysqli, "UPDATE mit_timesheets SET status = 'returned', decided_by = " . intval($session_user_id) . ", decided_at = NOW(),
            decision_note = '" . escapeSql(mb_substr($note, 0, 255)) . "' WHERE sheet_id = " . intval($sheet['sheet_id']));
        mitTsCaSupersede($sheet['sheet_id']);
        $text = 'sent back';
    }
    mitTsForget();
    if (intval($sheet['user_id']) !== intval($session_user_id)) {
        // Sent back: by email too, so they know to fix it
        mitTsNotify($sheet['user_id'], 'Your timesheet for ' . $period['label'] . " was $text by " . mitTsWho() . ($note !== '' ? " - $note" : ''), mitTsLink($sheet['user_id'], $period), $sheet['sheet_id'], $decision !== 'approve');
    }
    $user = mitTsUsers([$sheet['user_id']])[intval($sheet['user_id'])] ?? ['user_name' => 'user ' . intval($sheet['user_id'])];
    logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . " $text the timesheet of " . $user['user_name'] . ' for ' . $period['label']), 0, intval($sheet['user_id']));
    return [true, 'Timesheet ' . $text . (!empty($reimb_note) ? '. ' . $reimb_note : '')];
}

/** Reopen an approved period (Full access, or the person's approver) - ticket and unaccounted time are live again until it is approved again. */
function mitTsReopen(array $sheet, array $period) {
    $lock = 'ts_sheet_' . intval($sheet['user_id']) . '_' . $period['start'];
    if (!mitLock($lock)) {
        return [false, 'Someone else is working on this timesheet right now - try again.'];
    }
    try {
        return mitTsReopenNow(mitTsSheet($sheet['user_id'], $period), $period);
    } finally {
        mitUnlock($lock);
    }
}

/** mitTsReopen() under its lock. */
function mitTsReopenNow(array $sheet, array $period) {
    global $mysqli;
    if ($sheet['status'] !== 'approved') {
        return [false, 'Only an approved timesheet can be reopened.'];
    }
    // The copies go (live again) - except on days another approved timesheet holds (from before a pay period change)
    $others = mitTsLockedDates($sheet['user_id'], $period['start'], $period['end'], ['approved'], $period['start']);
    $dates = array_values(array_diff(mitTsDates($period), array_keys($others)));
    if ($dates) {
        mysqli_query($mysqli, "DELETE FROM mit_time_entries WHERE user_id = " . intval($sheet['user_id']) . " AND source IN ('" . implode("', '", MIT_TS_STORED_SOURCES) . "')
            AND entry_date IN ('" . implode("', '", $dates) . "')");
    }
    mysqli_query($mysqli, "UPDATE mit_timesheets SET status = 'open', submitted_at = NULL, decided_by = 0, decided_at = NULL, decision_note = NULL WHERE sheet_id = " . intval($sheet['sheet_id']));
    mitTsForget();
    mitTsCaSupersede($sheet['sheet_id']);
    mitLeaveUnaccruePeriod($sheet['user_id'], $period);
    $reimb_note = mitReimbPayrollUndo($sheet['user_id'], $period, $sheet['sheet_id']);
    $user = mitTsUsers([$sheet['user_id']])[intval($sheet['user_id'])] ?? ['user_name' => 'user ' . intval($sheet['user_id'])];
    logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . ' reopened the approved timesheet of ' . $user['user_name'] . ' for ' . $period['label']), 0, intval($sheet['user_id']));
    return [true, 'Reopened - approve it again when it is right' . ($reimb_note !== '' ? '; ' . $reimb_note : '')];
}

/** Submitted timesheets the signed-in agent can approve (side menu badge). */
function mitTsWaitingCount() {
    global $mysqli, $session_user_id;
    if (!mitTsOn() || !mitTsSettings()['approval']) {
        return 0;
    }
    // Full access approves anyone's; otherwise only the people this agent approves (People tab)
    if (mitTsLevel() >= 3) {
        $who = '';
    } else {
        $reports = mitTsMyReports();
        if (!$reports) {
            return 0;
        }
        $who = ' AND user_id IN (' . implode(',', array_map('intval', $reports)) . ')';
    }
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_timesheets WHERE status = 'submitted' AND user_id <> " . intval($session_user_id) . $who))[0]);
}

/*
 * ---------- personal choices (Account > Preferences) ----------
 */

/** Charge codes this person has hidden from their timesheet (still shown on a period that has hours on them). */
function mitTsHiddenCodes($user_id) {
    return array_filter(array_map('intval', explode(',', (string) mitUserPreference($user_id, 'timesheet.hidden_codes', ''))));
}

/** Does the person want timesheet reminders? (on unless they turned them off) */
function mitTsRemindersOn($user_id) {
    return mitUserPreference($user_id, 'timesheet.reminders', '1') !== '0';
}

/** The Timesheet part of Account > Preferences (agent/user/user_preferences.php). */
function mitTsPreferenceFields($user_id) {
    if (!mitTsOn()) {
        return '';
    }
    $user_id = intval($user_id);
    $format = (string) mitUserPreference($user_id, 'timesheet.format', '');
    $view = (string) mitUserPreference($user_id, 'timesheet.view', 'period');
    $hidden = mitTsHiddenCodes($user_id);
    $settings = mitTsSettings();
    $html = '<div class="col-12"><hr><h6 class="mb-3"><i class="fas fa-fw fa-business-time me-2"></i>Timesheet</h6><div class="row g-3">'
        . '<div class="col-md-4"><label class="form-label">Hours shown as</label><select class="form-select" name="mit_ts_format">'
        . '<option value=""' . ($format === '' ? ' selected' : '') . '>Company default (' . ($settings['format'] === 'hm' ? '7:30' : '7.5') . ')</option>'
        . '<option value="decimal"' . ($format === 'decimal' ? ' selected' : '') . '>Decimal - 7.5</option>'
        . '<option value="hm"' . ($format === 'hm' ? ' selected' : '') . '>Hours and minutes - 7:30</option></select></div>'
        . '<div class="col-md-4"><label class="form-label">Open on</label><select class="form-select" name="mit_ts_view">'
        . '<option value="period"' . ($view !== 'week' ? ' selected' : '') . '>The whole pay period</option>'
        . '<option value="week"' . ($view === 'week' ? ' selected' : '') . '>This week (others folded)</option></select></div>'
        . '<div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="mit_ts_reminders" value="1" id="mitTsReminders"'
        . (mitTsRemindersOn($user_id) ? ' checked' : '') . '><label class="form-check-label" for="mitTsReminders">Remind me to fill it in</label></div></div>'
        // Admins set their own pay type here - Admin > Users does not edit your own account
        . (!empty($GLOBALS['session_is_admin']) ? mitTsPayTypeField($user_id, 'col-md-8') : '')
        . (mitTsZoneColumn() ? '<div class="col-md-8"><label class="form-label" for="mitTsTimeZone">My time zone</label>' . mitTsZoneSelect('mit_ts_time_zone', mitTsOwnZone($user_id), 'mitTsTimeZone')
            . '<div class="form-text">Working remotely? Your timesheet days and the day your ticket time falls on follow this. A change counts from now - after any timesheet you have already submitted - and is logged.</div></div>' : '')
        . '<div class="col-12"><label class="form-label">Charge codes I use</label><div class="d-flex flex-wrap gap-3">';
    foreach (mitTsCodesFor($user_id) as $code_id => $code) {
        $html .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="mit_ts_codes[]" value="' . intval($code_id) . '" id="mitTsCode' . intval($code_id) . '"'
            . (in_array(intval($code_id), $hidden, true) ? '' : ' checked') . '><label class="form-check-label" for="mitTsCode' . intval($code_id) . '">' . mitTsCodeDot($code) . escapeHtml($code['code_name']) . '</label></div>';
    }
    return $html . '<input type="hidden" name="mit_ts_prefs" value="1"></div><div class="form-text">Unticked ones are left off your timesheet.</div></div></div></div>';
}

/** Save those (a no-op when the form did not have them). */
function mitTsSavePreferences($user_id, array $post) {
    if (empty($post['mit_ts_prefs']) || !mitTsOn()) {
        return;
    }
    $format = (string) ($post['mit_ts_format'] ?? '');
    mitSetUserPreference($user_id, 'timesheet.format', in_array($format, ['decimal', 'hm'], true) ? $format : '');
    mitSetUserPreference($user_id, 'timesheet.view', ($post['mit_ts_view'] ?? '') === 'week' ? 'week' : 'period');
    mitSetUserPreference($user_id, 'timesheet.reminders', empty($post['mit_ts_reminders']) ? '0' : '1');
    if (!empty($GLOBALS['session_is_admin']) && isset($post['mit_pay_type']) && $post['mit_pay_type'] !== mitTsPerson($user_id)['pay_type']) {
        mitTsSavePayType($user_id, $post);
        logAudit('User', 'Edit', escapeSql(($GLOBALS['session_name'] ?? '') . ' set their own employment to ' . (MIT_TS_PAY_TYPES[$post['mit_pay_type']] ?? '')));
    }
    if (isset($post['mit_ts_time_zone']) && mitTsOwnZone($user_id) !== (string) $post['mit_ts_time_zone']) {
        mitTsSaveZone($user_id, $post['mit_ts_time_zone']);
    }
    $shown = array_map('intval', (array) ($post['mit_ts_codes'] ?? []));
    $hidden = array_diff(array_keys(mitTsCodesFor($user_id)), $shown);
    mitSetUserPreference($user_id, 'timesheet.hidden_codes', implode(',', $hidden));
}

/*
 * ---------- the cron job (cron/mit_timesheets.php) ----------
 */

/** Fill closure days on everyone's period that just ended (not submitted or approved). Returns lines for the log. */
function mitTsFillEndedClosures() {
    $log = [];
    $last = mitTsPeriodShift(mitTsPeriodFor(), -1);
    foreach (mitTsUsers() as $user_id => $user) {
        if (in_array(mitTsSheet($user_id, $last)['status'], ['submitted', 'approved'], true)) {
            continue;
        }
        if (($filled = mitTsFillClosures($user_id, $last)) > 0) {
            mitTsSheetSaved($user_id, $last);
            $log[] = "{$user['user_name']}: $filled closure day(s) filled in for {$last['label']}";
        }
    }
    return $log;
}

/** Send the reminders that are due: on a period's last day, and when the last one is late. Returns lines for the log. */
function mitTsSendReminders() {
    global $mysqli;
    $settings = mitTsSettings();
    $log = [];
    foreach (mitTsUsers() as $user_id => $user) {
        if (!mitTsRemindersOn($user_id)) {
            continue;
        }
        // Their own day and period - a remote person is reminded on their last day, not the office's
        $today = mitTsToday($user_id);
        $current = mitTsPeriodFor($today);
        $last = mitTsPeriodShift($current, -1);
        if ($settings['remind_due'] && $current['end'] === $today) {
            $sheet = mitTsSheet($user_id, $current);
            if (in_array($sheet['status'], ['open', 'returned'], true) && empty($sheet['reminded_due_at'])) {
                $sheet = mitTsSheetSaved($user_id, $current);
                mysqli_query($mysqli, "UPDATE mit_timesheets SET reminded_due_at = NOW() WHERE sheet_id = " . intval($sheet['sheet_id']));
                mitTsNotify($user_id, 'Your timesheet for ' . $current['label'] . ' ends today - check your hours' . ($settings['approval'] ? ' and submit it' : ''),
                    mitTsLink($user_id, $current), $sheet['sheet_id'], true);
                $log[] = "Reminded {$user['user_name']}: period ends today";
            }
        }
        $late = $settings['remind_late_days'];
        if ($settings['approval'] && $late && $today >= date('Y-m-d', strtotime($last['end'] . " +$late days"))) {
            $sheet = mitTsSheet($user_id, $last);
            if (in_array($sheet['status'], ['open', 'returned'], true) && empty($sheet['reminded_late_at'])) {
                $sheet = mitTsSheetSaved($user_id, $last);
                mysqli_query($mysqli, "UPDATE mit_timesheets SET reminded_late_at = NOW() WHERE sheet_id = " . intval($sheet['sheet_id']));
                mitTsNotify($user_id, 'Your timesheet for ' . $last['label'] . ' has not been submitted yet', mitTsLink($user_id, $last), $sheet['sheet_id'], true);
                $log[] = "Reminded {$user['user_name']}: last period not submitted";
            }
        }
    }
    return $log;
}

/** Periods looked back over for "behind on timesheets" (the team page and its Remind buttons). */
const MIT_TS_BEHIND_PERIODS = 6;

/**
 * Who is behind: people (of $user_ids) with ended pay periods not submitted - open, or sent back and not sent again - in
 * the last MIT_TS_BEHIND_PERIODS periods, from when they started. Only with approval on. user_id => [periods => [period,
 * ...] oldest first, reminded_at => when the newest reminder went (automatic or by hand), or ''].
 */
function mitTsBehind(array $user_ids) {
    global $mysqli;
    if (!mitTsSettings()['approval'] || !$user_ids) {
        return [];
    }
    $periods = [];
    $period = mitTsPeriodShift(mitTsPeriodFor(), -1);
    for ($i = 0; $i < MIT_TS_BEHIND_PERIODS; $i++) {
        array_unshift($periods, $period);
        $period = mitTsPeriodShift($period, -1);
    }
    $ids = implode(',', array_map('intval', $user_ids));
    $stored = [];
    $result = mysqli_query($mysqli, "SELECT user_id, period_start, status, GREATEST(COALESCE(reminded_due_at, '1000-01-01'), COALESCE(reminded_late_at, '1000-01-01')) AS reminded
        FROM mit_timesheets WHERE user_id IN ($ids) AND period_start >= '" . escapeSql($periods[0]['start']) . "'");
    while ($row = mysqli_fetch_assoc($result)) {
        $stored[intval($row['user_id'])][$row['period_start']] = $row;
    }
    $behind = [];
    foreach (array_map('intval', $user_ids) as $user_id) {
        $start = mitTsStartDate($user_id);
        foreach ($periods as $p) {
            $row = $stored[$user_id][$p['start']] ?? null;
            if (($start !== '' && $p['end'] < $start) || ($row && !in_array($row['status'], ['open', 'returned'], true))) {
                continue;
            }
            $behind[$user_id]['periods'][] = $p;
            $reminded = $row && $row['reminded'] > '1000-01-01' ? $row['reminded'] : '';
            $behind[$user_id]['reminded_at'] = max($behind[$user_id]['reminded_at'] ?? '', $reminded);
        }
    }
    return $behind;
}

/**
 * A reminder by hand from the team page: an app notification and an email to someone behind on their timesheets
 * (sent whatever their own "remind me" choice - that is for the automatic ones). Not again within an hour.
 * Returns [ok, message].
 */
function mitTsRemind($user_id, array $behind) {
    global $mysqli;
    $user_id = intval($user_id);
    $periods = $behind['periods'] ?? [];
    if (!$periods) {
        return [false, 'They are up to date.'];
    }
    if (($behind['reminded_at'] ?? '') !== '' && strtotime($behind['reminded_at']) > time() - 3600) {
        return [false, 'They were reminded in the last hour.'];
    }
    $oldest = $periods[0];
    $sheet = mitTsSheetSaved($user_id, $oldest);
    mysqli_query($mysqli, "UPDATE mit_timesheets SET reminded_late_at = NOW() WHERE sheet_id = " . intval($sheet['sheet_id']));
    $more = count($periods) - 1;
    mitTsNotify($user_id, 'Please submit your timesheet for ' . $oldest['label'] . ($more ? " and $more more" : '') . ' - ' . mitTsWho() . ' sent a reminder',
        mitTsLink($user_id, $oldest), $sheet['sheet_id'], true);
    $user = mitTsUsers([$user_id])[$user_id] ?? ['user_name' => "user $user_id"];
    logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . ' reminded ' . $user['user_name'] . ' to submit their timesheet'), 0, $user_id);
    return [true, $user['user_name']];
}

require_once __DIR__ . '/mit_leave.php';
require_once __DIR__ . '/mit_ts_holidays.php';
require_once __DIR__ . '/mit_reimbursements.php';
require_once __DIR__ . '/mit_ts_california.php';
require_once __DIR__ . '/mit_timesheet_view.php';
require_once __DIR__ . '/mit_reply_times.php'; // times on ticket notes
