<?php

/*
 * Mountaineer IT - California time records for non-exempt people (database update M018; required
 * from functions/mit_timesheets.php). Who: Admin > Timesheets > People, "California non-exempt" -
 * on for hourly people unless turned off (mit_timesheet_people.ca_rules: 1 / 0 / NULL = by pay type).
 *
 * For them the timesheet also records, each day, when work starts and ends and when each meal period
 * starts and ends (Labor Code 1174, IWC wage orders section 7), to the minute and never rounded -
 * typed by the person, every change kept (mit_ts_changes) with who made it and why; a change made by
 * someone else waits for the person to acknowledge it. Those times are the hours worked: charge codes
 * and ticket time say what the time was spent on, and time on neither is "unallocated" work, still paid.
 *
 * Meal periods (Labor Code 512, wage order section 11): a 30-minute meal period starting by the end of
 * the 5th hour worked when the day is over 5 hours, a second by the end of the 10th when it is over 10.
 * A day whose times show one missing, late or short asks the person why: they chose to skip, shorten
 * or delay it (or waived it), or they were not able to take it - then one hour of premium pay is owed
 * (Labor Code 226.7, at most one meal premium a day). Rest breaks are paid and not recorded; a day the
 * person says they could not take one owes a rest premium hour. Unanswered counts as owed.
 *
 * Overtime (Labor Code 510): over 8 hours a day 1.5x, over 12 a day 2x, over 40 regular hours a week
 * 1.5x, and the 7th consecutive day worked in the workweek 1.5x for the first 8 hours, 2x after.
 * Premium hours are paid on top and are not hours worked.
 *
 * Submitting a period takes a signed attestation (mit_ts_attestations): the statement word for word,
 * the typed name, when, from where, and a copy of the times signed for. Taking it back, sending it
 * back or reopening it sets that signature aside, and the next submit signs again.
 *
 * This is the record-keeping side; it is not legal advice - the rules and the wording of the
 * statement should be confirmed with an employment lawyer.
 */

const MIT_TS_CA_ATTEST_VERSION = '2026-10';
const MIT_TS_CA_ANSWERS = ['chose' => 'I chose to skip, shorten or delay it', 'not_provided' => 'I was not able to take it (premium hour owed)'];

/** Has database update M018 run? */
function mitTsCaReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ts_attestations'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The first day the rules apply (mit_settings ts_ca_start - the day database update M018 ran). */
function mitTsCaStart() {
    $start = (string) mitSetting('ts_ca_start', '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : '2000-01-01';
}

/**
 * Is this person on California non-exempt rules? The People tab setting, else on for hourly people. With a date
 * (a day, or a period's last day): only from the day the rules started - earlier days keep their hours as they were.
 */
function mitTsCaOn($user_id, $date = null) {
    if (!mitTsCaReady() || ($date !== null && $date < mitTsCaStart())) {
        return false;
    }
    // Contractors are not employees - never under the wage-order rules, whatever their own switch says
    if (mitTsPerson($user_id)['pay_type'] === 'contractor') {
        return false;
    }
    $row = mitTsPeople()[intval($user_id)] ?? [];
    if (isset($row['ca_rules']) && $row['ca_rules'] !== null && $row['ca_rules'] !== '') {
        return intval($row['ca_rules']) === 1;
    }
    return mitTsPerson($user_id)['pay_type'] === 'hourly';
}

/*
 * ---------- reading the records ----------
 */

/**
 * Work periods: [user_id][date] => [['start' => 'Y-m-d H:i:s', 'end' => ..., 'gap' => 'meal' | 'off'], ...] in order -
 * 'gap' is the time before the period (a meal break, or off between the shifts of a split shift; '' on the first).
 */
function mitTsCaPeriods(array $user_ids, $from, $to) {
    global $mysqli;
    $user_ids = array_filter(array_map('intval', $user_ids));
    $periods = [];
    if ($user_ids && mitTsCaReady()) {
        $gap = mitTsCaSplitReady() ? 'gap_before' : 'NULL AS gap_before';
        $result = mysqli_query($mysqli, "SELECT user_id, work_date, start_at, end_at, $gap FROM mit_ts_work_periods WHERE user_id IN (" . implode(',', $user_ids) . ")
            AND work_date BETWEEN '" . escapeSql($from) . "' AND '" . escapeSql($to) . "' ORDER BY start_at");
        while ($row = mysqli_fetch_assoc($result)) {
            $list = &$periods[intval($row['user_id'])][$row['work_date']];
            $list[] = ['start' => $row['start_at'], 'end' => $row['end_at'], 'gap' => $list ? ($row['gap_before'] === 'off' ? 'off' : 'meal') : ''];
            unset($list);
        }
    }
    return $periods;
}

/** Split shifts can be stored (database update M022). */
function mitTsCaSplitReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM mit_ts_work_periods LIKE 'gap_before'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The person's answers: [user_id][date] => ['meal1', 'meal2', 'rest_missed']. */
function mitTsCaAnswers(array $user_ids, $from, $to) {
    global $mysqli;
    $user_ids = array_filter(array_map('intval', $user_ids));
    $answers = [];
    if ($user_ids && mitTsCaReady()) {
        $result = mysqli_query($mysqli, "SELECT user_id, work_date, meal1, meal2, rest_missed FROM mit_ts_days WHERE user_id IN (" . implode(',', $user_ids) . ")
            AND work_date BETWEEN '" . escapeSql($from) . "' AND '" . escapeSql($to) . "'");
        while ($row = mysqli_fetch_assoc($result)) {
            $answers[intval($row['user_id'])][$row['work_date']] = ['meal1' => (string) $row['meal1'], 'meal2' => (string) $row['meal2'], 'rest_missed' => intval($row['rest_missed'])];
        }
    }
    return $answers;
}

/*
 * ---------- the rules ----------
 */

/**
 * A day's work periods against the meal rules: ['worked' => minutes, 'meals' => [1 => status, 2 => status]]
 * - status null when that meal is not due, else ok | missing | late | short; and 'gaps' (minutes between periods).
 * Time off between the shifts of a split shift counts like a meal break here: 30 minutes or more off duty is a meal period.
 */
function mitTsCaAnalyze(array $periods) {
    $worked = 0;
    $gaps = [];  // [worked before it, minutes, meal | off]
    $last_end = null;
    foreach ($periods as $p) {
        $start = strtotime($p['start']);
        $end = strtotime($p['end']);
        if ($last_end !== null) {
            $gaps[] = ['before' => $worked, 'minutes' => intdiv(max(0, $start - $last_end), 60), 'kind' => ($p['gap'] ?? '') === 'off' ? 'off' : 'meal'];
        }
        $worked += intdiv(max(0, $end - $start), 60);
        $last_end = $end;
    }
    $meals = [1 => null, 2 => null];
    $used = -1;
    foreach ([1 => 300, 2 => 600] as $n => $by) {
        if ($worked <= $by) {
            continue;
        }
        $status = 'missing';
        foreach ($gaps as $i => $gap) {
            if ($i <= $used) {
                continue;
            }
            if ($gap['minutes'] >= 30 && $gap['before'] <= $by) {
                $status = 'ok';
                $used = $i;
                break;
            }
        }
        if ($status !== 'ok') {
            // Not met: say how, and let a later or shorter break stand in for this meal so the next one is judged on its own
            foreach ($gaps as $i => $gap) {
                if ($i > $used) {
                    $status = $gap['before'] <= $by ? 'short' : 'late';
                    $used = $i;
                    break;
                }
            }
        }
        $meals[$n] = $status;
    }
    return ['worked' => $worked, 'meals' => $meals, 'gaps' => $gaps];
}

/** "No 2nd meal period", "1st meal period started after the 5th hour", ... */
function mitTsCaIssueText($n, $status) {
    $which = $n === 1 ? '1st' : '2nd';
    $by = $n === 1 ? '5th' : '10th';
    switch ($status) {
        case 'missing':
            return "No $which meal period (due for a day over " . ($n === 1 ? '5' : '10') . ' hours)';
        case 'late':
            return "$which meal period started after the end of the $by hour";
        case 'short':
            return "$which meal period was under 30 minutes";
    }
    return '';
}

/**
 * California days for people on the rules: [user_id][date] => ['clocked', 'meals', 'issues' => [n => status],
 * 'unanswered' => [n...], 'meal_premium', 'rest_premium' (minutes), 'periods']. On a day approved since the pay figures
 * were stored (mitTsStoredRules) whether it is a California day and its premiums are as they were approved; other days
 * follow the person's setting now.
 */
function mitTsCaDays(array $user_ids, $from, $to) {
    $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
    sort($user_ids);
    return mitTsMemo('ca_days', [$user_ids, $from, $to], function () use ($user_ids, $from, $to) {
        return mitTsCaDaysRead($user_ids, $from, $to);
    });
}

/** mitTsCaDays() worked out. */
function mitTsCaDaysRead(array $user_ids, $from, $to) {
    $stored = mitTsStoredRules($user_ids, $from, $to);
    $live = array_filter($user_ids, 'mitTsCaOn');
    // On the rules now, or with approved California days in these dates
    $ca_users = array_values(array_filter($user_ids, function ($user_id) use ($live, $stored) {
        return in_array($user_id, $live, true) || in_array(true, array_column($stored[$user_id] ?? [], 'ca'), true);
    }));
    $days = [];
    if (!$ca_users) {
        return $days;
    }
    $periods = mitTsCaPeriods($ca_users, $from, $to);
    $answers = mitTsCaAnswers($ca_users, $from, $to);
    $first = mitTsCaStart();
    foreach ($ca_users as $user_id) {
        foreach (mitTsDateRange($from, $to) as $date) {
            $kept = $stored[$user_id][$date] ?? null;
            if ($kept ? !$kept['ca'] : (!in_array($user_id, $live, true) || $date < $first)) {
                continue;
            }
            $day_periods = $periods[$user_id][$date] ?? [];
            $a = mitTsCaAnalyze($day_periods);
            $answer = $answers[$user_id][$date] ?? ['meal1' => '', 'meal2' => '', 'rest_missed' => 0];
            $issues = array_filter($a['meals'], function ($s) {
                return $s !== null && $s !== 'ok';
            });
            $unanswered = [];
            $premium = false;
            foreach ($issues as $n => $status) {
                $given = $answer["meal$n"];
                if (!isset(MIT_TS_CA_ANSWERS[$given])) {
                    $unanswered[] = $n;
                }
                // Unanswered counts as owed until the person says otherwise
                $premium = $premium || $given !== 'chose';
            }
            $days[$user_id][$date] = ['clocked' => $a['worked'], 'meals' => $a['meals'], 'issues' => $issues, 'unanswered' => $unanswered,
                'answers' => $answer, 'meal_premium' => $kept ? $kept['meal_premium'] : ($premium ? 60 : 0),
                'rest_premium' => $kept ? $kept['rest_premium'] : ($answer['rest_missed'] ? 60 : 0), 'periods' => $day_periods];
        }
    }
    return $days;
}

/**
 * California overtime over days of hours worked (date => minutes, from the start of a workweek, every date):
 * date => ['regular', 'overtime', 'double'].
 */
function mitTsCaOvertime(array $worked) {
    ksort($worked);
    $out = [];
    $week_regular = [];
    foreach ($worked as $date => $w) {
        $w = max(0, intval($w));
        $week = mitTsWeekStart($date);
        // The 7th consecutive day of the workweek: every day of the week so far worked
        $index = (int) round((strtotime($date) - strtotime($week)) / 86400);
        $seventh = $index === 6 && $w > 0;
        for ($i = 0; $seventh && $i < 6; $i++) {
            $seventh = intval($worked[date('Y-m-d', strtotime("$week +$i days"))] ?? 0) > 0;
        }
        if ($seventh) {
            $regular = 0;
            $overtime = min($w, 480);
            $double = max(0, $w - 480);
        } else {
            $regular = min($w, 480);
            $double = max(0, $w - 720);
            $overtime = $w - $regular - $double;
        }
        // Over 40 regular hours in the workweek: the rest of the regular hours are overtime
        $so_far = $week_regular[$week] ?? 0;
        $past = max(0, $so_far + $regular - 2400) - max(0, $so_far - 2400);
        $week_regular[$week] = $so_far + $regular;
        $out[$date] = ['regular' => $regular - $past, 'overtime' => $overtime + $past, 'double' => $double];
    }
    return $out;
}

/*
 * ---------- showing times ----------
 */

/** "8:00 AM - 12:00 PM, 12:30 PM - 5:00 PM", with "off" between the shifts of a split shift (or "no times"). */
function mitTsCaPeriodsText(array $periods) {
    if (!$periods) {
        return 'no times';
    }
    $text = '';
    foreach (array_values($periods) as $i => $p) {
        $text .= ($i ? (($p['gap'] ?? '') === 'off' ? ' / off / ' : ', ') : '')
            . date('g:i A', strtotime($p['start'])) . ' - ' . date('g:i A', strtotime($p['end'])) . (substr($p['end'], 0, 10) > substr($p['start'], 0, 10) ? ' (+1 day)' : '');
    }
    return $text;
}

/** The day's blocks for the timesheet page: [['start' => 'H:i', 'end' => 'H:i', 'gap' => '' | meal | off]]. */
function mitTsCaBlocks(array $periods) {
    return array_map(function ($p) {
        return ['start' => date('H:i', strtotime($p['start'])), 'end' => date('H:i', strtotime($p['end'])), 'gap' => (string) ($p['gap'] ?? '')];
    }, array_values($periods));
}

/** Most blocks of work in one day (the page's "Add another block" stops there too). */
const MIT_TS_CA_MAX_BLOCKS = 12;

/**
 * Turn a day's blocks (start, end, and the gap before each one: meal | off) into work periods: [periods, error].
 * Empty rows are skipped. Blocks go in time order: a block whose end is earlier than its start runs past midnight, and
 * a block that starts earlier than the one before it ended is taken as after midnight only when that leaves at most
 * 12 hours between them - otherwise it is out of order and refused. A block cannot start and end at the same time,
 * and the day may span at most 24 hours. Times are read in the request's (company) time zone.
 */
function mitTsCaParseBlocks($date, array $blocks) {
    $rows = [];
    foreach (array_values($blocks) as $block) {
        if (!is_array($block)) {
            continue;
        }
        $start = trim((string) ($block['start'] ?? ''));
        $end = trim((string) ($block['end'] ?? ''));
        if ($start === '' && $end === '') {
            continue;
        }
        foreach ([$start, $end] as $time) {
            if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time)) {
                return [[], $time === '' ? 'each block needs a start and an end' : 'a time could not be read'];
            }
        }
        $rows[] = [$start, $end, ($block['gap'] ?? '') === 'off' ? 'off' : 'meal'];
    }
    if (!$rows) {
        return [[], ''];
    }
    if (count($rows) > MIT_TS_CA_MAX_BLOCKS) {
        return [[], 'at most ' . MIT_TS_CA_MAX_BLOCKS . ' blocks a day'];
    }
    $periods = [];
    $day = 0;
    $first = null;
    $last_end = null;
    // A wall-clock time on the day, or a calendar day or more later - never 86400 seconds, which is an hour off on the
    // nights the clocks change
    $at = function ($time, $days) use ($date) {
        return strtotime("$date $time" . ($days ? " +$days day" : ''));
    };
    foreach ($rows as $i => [$start_time, $end_time, $gap]) {
        $start = $at($start_time, $day);
        if ($last_end !== null && $start < $last_end) {
            // After midnight, or typed out of order
            $day++;
            $start = $at($start_time, $day);
            if ($start - $last_end > 12 * 3600) {
                return [[], 'blocks go in time order - ' . date('g:i A', $start) . ' starts before the block above it ends'];
            }
        }
        $end = $at($end_time, $day);
        if ($end === $start) {
            return [[], 'a block cannot start and end at the same time'];
        }
        if ($end < $start) {
            $day++;
            $end = $at($end_time, $day);
        }
        $first = $first ?? $start;
        // At most 24 hours by the clock: up to the first start's time on the next day
        if ($end > strtotime(date('Y-m-d H:i:s', $first) . ' +1 day') || $day > 1) {
            return [[], 'spans more than 24 hours'];
        }
        $periods[] = ['start' => date('Y-m-d H:i:s', $start), 'end' => date('Y-m-d H:i:s', $end), 'gap' => $i ? $gap : ''];
        $last_end = $end;
    }
    return [$periods, ''];
}

/*
 * ---------- saving ----------
 */

/**
 * Save the times and answers from the timesheet page: $times[date]['blocks'][i][start|end|gap] and
 * $answers[date][meal1|meal2|rest_missed].
 * Only the person answers their own meal questions. Someone else changing the times must say why, and the
 * person is asked to acknowledge it. Returns [ok, message, changed days].
 */
function mitTsCaSave(array $sheet, array $period, array $times, array $answers, $reason) {
    global $mysqli, $session_user_id;
    $user_id = intval($sheet['user_id']);
    $own = $user_id === intval($session_user_id);
    $reason = trim((string) $reason);
    // Days another timesheet holds that is submitted or approved (from before a pay period change) are left as they are
    $dates = array_diff_key(array_flip(mitTsDates($period)), mitTsLockedDates($user_id, $period['start'], $period['end'], ['submitted', 'approved'], $period['start']));
    $current = mitTsCaPeriods([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $wanted = [];
    $problems = [];
    foreach ($times as $date => $boxes) {
        if (!isset($dates[$date]) || !is_array($boxes)) {
            continue;
        }
        // Blocks from the timesheet page (any number - split shifts). Anything else is a page from before them: not
        // read as "no times", which would clear the day
        if (!isset($boxes['blocks'])) {
            $problems[] = date('D M j', strtotime($date)) . ': reload the page and try again';
            continue;
        }
        [$periods, $error] = mitTsCaParseBlocks($date, (array) $boxes['blocks']);
        if ($error !== '') {
            $problems[] = date('D M j', strtotime($date)) . ": $error";
            continue;
        }
        if ($periods && $date > mitTsToday($user_id)) {
            $problems[] = date('D M j', strtotime($date)) . ': times go on once the day has come';
            continue;
        }
        if (mitTsCaPeriodsText($periods) !== mitTsCaPeriodsText($current[$date] ?? [])) {
            $wanted[$date] = $periods;
        }
    }
    if ($problems) {
        return [false, 'Not saved - ' . implode('; ', array_slice($problems, 0, 4)) . '.', 0];
    }
    if ($wanted && !$own && $reason === '') {
        return [false, 'Not saved - say why you are changing their times (they see it and are asked to acknowledge it).', 0];
    }
    $who = mitTsWho();
    foreach ($wanted as $date => $periods) {
        $date_sql = escapeSql($date);
        mysqli_query($mysqli, "DELETE FROM mit_ts_work_periods WHERE user_id = $user_id AND work_date = '$date_sql'");
        foreach ($periods as $p) {
            mysqli_query($mysqli, "INSERT INTO mit_ts_work_periods SET user_id = $user_id, work_date = '$date_sql', start_at = '" . escapeSql($p['start']) . "',
                end_at = '" . escapeSql($p['end']) . "', updated_by = " . intval($session_user_id)
                . (mitTsCaSplitReady() ? ", gap_before = " . (($p['gap'] ?? '') === 'off' ? "'off'" : 'NULL') : ''));
        }
        mysqli_query($mysqli, "INSERT INTO mit_ts_changes SET user_id = $user_id, work_date = '$date_sql', changed_by = " . intval($session_user_id) . ",
            changed_by_name = '" . escapeSql(mb_substr($who, 0, 200)) . "', before_text = '" . escapeSql(mitTsCaPeriodsText($current[$date] ?? [])) . "',
            after_text = '" . escapeSql(mitTsCaPeriodsText($periods)) . "', reason = " . ($reason === '' ? 'NULL' : "'" . escapeSql(mb_substr($reason, 0, 255)) . "'") . ",
            acknowledged_at = " . ($own ? 'NOW()' : 'NULL'));
    }
    // The person's own answers about their meal periods and rest breaks
    $answered = 0;
    if ($own) {
        $had = mitTsCaAnswers([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
        foreach ($answers as $date => $a) {
            if (!isset($dates[$date]) || !is_array($a)) {
                continue;
            }
            $meal1 = isset(MIT_TS_CA_ANSWERS[$a['meal1'] ?? '']) ? $a['meal1'] : '';
            $meal2 = isset(MIT_TS_CA_ANSWERS[$a['meal2'] ?? '']) ? $a['meal2'] : '';
            $rest = empty($a['rest_missed']) ? 0 : 1;
            $before = $had[$date] ?? ['meal1' => '', 'meal2' => '', 'rest_missed' => 0];
            if ([$meal1, $meal2, $rest] === [$before['meal1'], $before['meal2'], $before['rest_missed']]) {
                continue;
            }
            $set = "meal1 = " . ($meal1 === '' ? 'NULL' : "'$meal1'") . ", meal2 = " . ($meal2 === '' ? 'NULL' : "'$meal2'") . ", rest_missed = $rest, updated_by = " . intval($session_user_id);
            mysqli_query($mysqli, "INSERT INTO mit_ts_days SET user_id = $user_id, work_date = '" . escapeSql($date) . "', $set ON DUPLICATE KEY UPDATE $set");
            $answered++;
        }
    }
    if ($wanted || $answered) {
        mitTsForget();
        // Changed after they signed: the signature no longer covers it - they sign again
        mitTsCaChangedAfterSigning($sheet, $period);
        mitTsSheetSaved($user_id, $period);
        if (!$own && $wanted) {
            logAudit('Timesheet', 'Edit', escapeSql("$who changed the work times of user $user_id on " . count($wanted) . ' day(s) for ' . $period['label'] . " - $reason"), 0, $user_id);
            mitTsNotify($user_id, "$who changed your work times on " . count($wanted) . ' day' . (count($wanted) === 1 ? '' : 's') . ' - please review them', mitTsLink($user_id, $period), intval($sheet['sheet_id']));
        }
    }
    return [true, '', count($wanted) + $answered];
}

/** Changes to a person's times in a period: rows, newest first. */
function mitTsCaChanges($user_id, array $period) {
    global $mysqli;
    if (!mitTsCaReady()) {
        return [];
    }
    $result = mysqli_query($mysqli, "SELECT change_id, work_date, changed_by, changed_by_name, changed_at, before_text, after_text, reason, acknowledged_at FROM mit_ts_changes
        WHERE user_id = " . intval($user_id) . " AND work_date BETWEEN '" . escapeSql($period['start']) . "' AND '" . escapeSql($period['end']) . "' ORDER BY change_id DESC");
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

/** Acknowledge the changes others made to the signed-in person's times in a period. Returns how many. */
function mitTsCaAcknowledge(array $period) {
    global $mysqli, $session_user_id;
    mysqli_query($mysqli, "UPDATE mit_ts_changes SET acknowledged_at = NOW() WHERE user_id = " . intval($session_user_id) . " AND acknowledged_at IS NULL
        AND work_date BETWEEN '" . escapeSql($period['start']) . "' AND '" . escapeSql($period['end']) . "'");
    return mysqli_affected_rows($mysqli);
}

/*
 * ---------- before submitting, and the attestation ----------
 */

/**
 * What stops a period being submitted: days with time on tickets or work codes but no start and end
 * times, and meal questions not answered. Returns a list of sentences.
 */
function mitTsCaProblems($user_id, array $period) {
    $user_id = intval($user_id);
    $ca = mitTsCaDays([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $days = mitTsDays([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $problems = [];
    foreach ($ca as $date => $c) {
        $label = date('D M j', strtotime($date));
        $d = $days[$date] ?? [];
        $on_codes = intval($d['billable'] ?? 0) + intval($d['other'] ?? 0) + intval($d['work'] ?? 0) + intval($d['lost'] ?? 0);
        if (!$c['clocked'] && $on_codes) {
            $problems[] = "$label: hours on tickets or charge codes, but no start and end times";
        }
        foreach ($c['unanswered'] as $n) {
            $problems[] = "$label: " . mitTsCaIssueText($n, $c['issues'][$n]) . ' - say why';
        }
    }
    return $problems;
}

/** The statement the person signs, with their name in it. */
function mitTsCaStatement($name, array $period) {
    return "I, $name, certify that this timesheet for " . $period['label'] . " is complete and accurate. It shows every start and end time "
        . "of the work I did and of my meal periods, including any work before or after my shift, and I did not work off the clock. "
        . "Where a meal period was missed, late or short, or I could not take a rest break, I have said so on that day. "
        . "I understand I am owed one hour of premium pay for each day I was not able to take a meal period, and one for each day I was not "
        . "able to take a rest break, and that I can raise any problem with my hours without retaliation.";
}

/** The current (not set aside) attestation of a timesheet, or null. */
function mitTsCaAttestation($sheet_id) {
    global $mysqli;
    if (!mitTsCaReady() || !intval($sheet_id)) {
        return null;
    }
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT attest_id, sheet_id, user_id, attested_at, signature_name, ip, text_version, statement, breaks_ok, exceptions, snapshot
        FROM mit_ts_attestations WHERE sheet_id = " . intval($sheet_id) . " AND superseded_at IS NULL ORDER BY attest_id DESC LIMIT 1")) ?: null;
}

/**
 * After a change to the times, answers or hours of a signed timesheet: the signature no longer covers what is there, so it
 * is set aside and the person signs again - a submitted one goes back to them (sent back, saying why).
 */
function mitTsCaChangedAfterSigning(array $sheet, array $period) {
    global $mysqli, $session_user_id;
    if (!intval($sheet['sheet_id']) || !mitTsCaAttestation($sheet['sheet_id'])) {
        return;
    }
    mitTsCaSupersede($sheet['sheet_id']);
    $note = 'Changed after it was signed by ' . mitTsWho() . ' - check it and sign it again';
    mysqli_query($mysqli, "UPDATE mit_timesheets SET status = 'returned', decided_by = " . intval($session_user_id) . ", decided_at = NOW(),
        decision_note = '" . escapeSql(mb_substr($note, 0, 255)) . "' WHERE sheet_id = " . intval($sheet['sheet_id']) . " AND status = 'submitted'");
    $returned = mysqli_affected_rows($mysqli) === 1;
    logAudit('Timesheet', 'Edit', escapeSql(mitTsWho() . ' changed the signed timesheet of user ' . intval($sheet['user_id']) . ' for ' . $period['label'] . ' - the signature was set aside'
        . ($returned ? ' and it went back to them' : '')), 0, intval($sheet['user_id']));
    if (intval($sheet['user_id']) !== intval($session_user_id)) {
        mitTsNotify($sheet['user_id'], 'Your timesheet for ' . $period['label'] . ' was changed after you signed it - please check it and sign it again', mitTsLink($sheet['user_id'], $period), $sheet['sheet_id'], true);
    }
}

/** Set a timesheet's signature aside (taken back, sent back, reopened). */
function mitTsCaSupersede($sheet_id) {
    global $mysqli;
    if (mitTsCaReady() && intval($sheet_id)) {
        mysqli_query($mysqli, "UPDATE mit_ts_attestations SET superseded_at = NOW() WHERE sheet_id = " . intval($sheet_id) . " AND superseded_at IS NULL");
    }
}

/**
 * Sign: $post has mit_ca_accurate, mit_ca_breaks (ok | exceptions), mit_ca_exceptions, mit_ca_signature. Only the
 * person signs their own. Stores the statement, their answers and a copy of the period's times and totals.
 * Returns [ok, message].
 */
function mitTsCaAttest(array $sheet, array $period, array $post) {
    global $mysqli, $session_user_id;
    $user_id = intval($sheet['user_id']);
    if ($user_id !== intval($session_user_id)) {
        return [false, 'Only they can sign their timesheet - ask them to submit it.'];
    }
    if ($problems = mitTsCaProblems($user_id, $period)) {
        return [false, 'Not submitted - ' . implode('; ', array_slice($problems, 0, 4)) . (count($problems) > 4 ? ' (and ' . (count($problems) - 4) . ' more)' : '') . '.'];
    }
    $user = mitTsUsers([$user_id])[$user_id] ?? null;
    $name = trim((string) ($post['mit_ca_signature'] ?? ''));
    $exceptions = trim((string) ($post['mit_ca_exceptions'] ?? ''));
    $breaks_ok = ($post['mit_ca_breaks'] ?? '') === 'ok';
    if (empty($post['mit_ca_accurate'])) {
        return [false, 'Not submitted - tick the box to certify your hours.'];
    }
    if (!$breaks_ok && ($post['mit_ca_breaks'] ?? '') !== 'exceptions') {
        return [false, 'Not submitted - say whether you had your meal periods and rest breaks.'];
    }
    if (!$breaks_ok && $exceptions === '') {
        return [false, 'Not submitted - describe the missed or short breaks.'];
    }
    if (!$user || mb_strtolower(preg_replace('/\s+/', ' ', $name)) !== mb_strtolower(preg_replace('/\s+/', ' ', trim($user['user_name'])))) {
        return [false, 'Not submitted - type your name exactly as ' . ($user['user_name'] ?? 'it is on your account') . ' to sign.'];
    }
    $sheet = mitTsSheetSaved($user_id, $period);
    $ca = mitTsCaDays([$user_id], $period['start'], $period['end'])[$user_id] ?? [];
    $totals = mitTsTotals([$user_id], $period['start'], $period['end'])[$user_id];
    $snapshot = ['days' => [], 'totals' => array_intersect_key($totals, array_flip(['worked', 'leave', 'paid', 'regular', 'overtime', 'double', 'meal_premium', 'rest_premium']))];
    foreach ($ca as $date => $c) {
        if ($c['periods'] || $c['issues'] || $c['answers']['rest_missed']) {
            $snapshot['days'][$date] = ['times' => mitTsCaPeriodsText($c['periods']), 'worked' => $c['clocked'],
                'meals' => array_map(function ($n) use ($c) {
                    return mitTsCaIssueText($n, $c['issues'][$n]) . ': ' . (MIT_TS_CA_ANSWERS[$c['answers']["meal$n"]] ?? 'not answered');
                }, array_keys($c['issues'])), 'rest_missed' => $c['answers']['rest_missed']];
        }
    }
    $statement = mitTsCaStatement($user['user_name'], $period);
    mitTsCaSupersede($sheet['sheet_id']);
    mysqli_query($mysqli, "INSERT INTO mit_ts_attestations SET sheet_id = " . intval($sheet['sheet_id']) . ", user_id = $user_id,
        period_start = '" . escapeSql($period['start']) . "', period_end = '" . escapeSql($period['end']) . "',
        signature_name = '" . escapeSql(mb_substr($name, 0, 200)) . "', ip = '" . escapeSql(mb_substr((string) getIP(), 0, 64)) . "',
        user_agent = '" . escapeSql(mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)) . "', text_version = '" . MIT_TS_CA_ATTEST_VERSION . "',
        statement = '" . mysqli_real_escape_string($mysqli, $statement) . "', breaks_ok = " . ($breaks_ok ? 1 : 0) . ",
        exceptions = " . ($breaks_ok ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, mb_substr($exceptions, 0, 2000)) . "'") . ",
        snapshot = '" . mysqli_real_escape_string($mysqli, json_encode($snapshot)) . "'");
    // Signing acknowledges the changes others made, which the statement covers
    mitTsCaAcknowledge($period);
    logAudit('Timesheet', 'Edit', escapeSql($user['user_name'] . ' signed the attestation for the timesheet of ' . $period['label']), 0, $user_id);
    return [true, 'Signed'];
}

/** Changes others made after the person signed, or not yet acknowledged - for the approver. */
function mitTsCaUnacknowledged($user_id, array $period) {
    return array_values(array_filter(mitTsCaChanges($user_id, $period), function ($c) {
        return $c['acknowledged_at'] === null;
    }));
}

/*
 * ---------- on the timesheet page (agent/mit_timesheet.php - the day rows and panel are agent/js/mit_timesheet.js) ----------
 */

/** Changes to the times: the person acknowledges those others made; everyone sees the history. */
function mitTsCaChangesBox($user_id, array $period, $own) {
    if (!mitTsCaOn($user_id, $period['end'])) {
        return '';
    }
    $changes = mitTsCaChanges($user_id, $period);
    $others = array_values(array_filter($changes, function ($c) use ($user_id) {
        return intval($c['changed_by']) !== intval($user_id);
    }));
    if (!$others) {
        return $changes ? '<details class="small text-muted mb-3"><summary>Change history (' . count($changes) . ')</summary>' . mitTsCaChangesTable($changes) . '</details>' : '';
    }
    $waiting = array_filter($others, function ($c) {
        return $c['acknowledged_at'] === null;
    });
    $html = '<div class="alert ' . ($waiting ? 'alert-warning' : 'alert-light border') . ' small mb-3"><div class="d-flex flex-wrap align-items-center gap-2 mb-2"><strong class="me-auto"><i class="fas fa-user-edit me-1"></i>'
        . ($waiting ? count($waiting) . ' change' . (count($waiting) === 1 ? '' : 's') . ' to ' . ($own ? 'your' : 'their') . ' times made by someone else - ' . ($own ? 'please review' : 'waiting for them to acknowledge') : 'Changes made by someone else (acknowledged)') . '</strong>';
    if ($own && $waiting) {
        $html .= '<a class="btn btn-sm btn-warning" href="post.php?mit_ca_acknowledge=' . $period['start'] . '&csrf_token=' . escapeHtml($_SESSION['csrf_token']) . '"><i class="fas fa-check me-1"></i>I have reviewed these</a>';
    }
    return $html . '</div>' . mitTsCaChangesTable($others) . (count($changes) > count($others) ? '<details class="mt-2"><summary>All changes (' . count($changes) . ')</summary>' . mitTsCaChangesTable($changes) . '</details>' : '') . '</div>';
}

function mitTsCaChangesTable(array $changes) {
    $html = '<div class="table-responsive"><table class="table table-sm mb-0 small"><thead><tr><th>Day</th><th>Changed</th><th>By</th><th>Before</th><th>After</th><th>Why</th><th>Acknowledged</th></tr></thead><tbody>';
    foreach ($changes as $c) {
        $html .= '<tr><td class="text-nowrap">' . date('D M j', strtotime($c['work_date'])) . '</td><td class="text-nowrap">' . date('M j, g:i A', strtotime($c['changed_at'])) . '</td><td>' . escapeHtml($c['changed_by_name'])
            . '</td><td>' . escapeHtml($c['before_text']) . '</td><td>' . escapeHtml($c['after_text']) . '</td><td>' . escapeHtml((string) $c['reason'])
            . '</td><td class="text-nowrap">' . ($c['acknowledged_at'] ? date('M j, g:i A', strtotime($c['acknowledged_at'])) : '<span class="text-warning-emphasis">Not yet</span>') . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

/** The signing window, inside the timesheet form - opened by the script when Submit is pressed. */
function mitTsCaSignModal($user_id, array $period) {
    global $session_name;
    $user = mitTsUsers([$user_id])[intval($user_id)] ?? ['user_name' => stripslashes((string) $session_name)];
    $problems = mitTsCaProblems($user_id, $period);
    return '<div class="modal fade" id="mitCaSign" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">'
        . '<div class="modal-header bg-dark"><h5 class="modal-title"><i class="fas fa-file-signature me-2"></i>Sign and submit your hours</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>'
        . '<div class="modal-body">'
        // Kept current by the page's script after each save
        . '<div data-mit-ca-problems>' . mitTsCaProblemsHtml($problems) . '</div>'
        . '<p class="border rounded p-3 bg-body-tertiary">' . escapeHtml(mitTsCaStatement($user['user_name'], $period)) . '</p>'
        . '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="mit_ca_accurate" value="1" id="mitCaAccurate"><label class="form-check-label" for="mitCaAccurate">'
        . 'I certify the times on this timesheet are complete and accurate, and I did not work off the clock.</label></div>'
        . '<div class="mb-2 fw-semibold">Meal periods and rest breaks</div>'
        . '<div class="form-check"><input class="form-check-input" type="radio" name="mit_ca_breaks" value="ok" id="mitCaBreaksOk"><label class="form-check-label" for="mitCaBreaksOk">'
        . 'I was able to take every meal period and rest break I was entitled to, except where I said otherwise on the day.</label></div>'
        . '<div class="form-check mb-2"><input class="form-check-input" type="radio" name="mit_ca_breaks" value="exceptions" id="mitCaBreaksNo"><label class="form-check-label" for="mitCaBreaksNo">'
        . 'Something else to report about my breaks or hours:</label></div>'
        . '<textarea class="form-control mb-3" name="mit_ca_exceptions" rows="2" maxlength="2000" placeholder="e.g. On Tuesday I could not take my afternoon rest break; I answered a call during lunch on the 9th"></textarea>'
        . '<label class="form-label">Type your full name to sign <span class="text-muted small">(' . escapeHtml($user['user_name']) . ')</span></label>'
        . '<input type="text" class="form-control" name="mit_ca_signature" maxlength="200" autocomplete="off">'
        . '<div class="form-text">Signed electronically with the date, time and where it was sent from. Your manager is told about anything you report.</div>'
        . '</div><div class="modal-footer"><button type="submit" name="mit_ts_save" value="submit" class="btn btn-success"><i class="fas fa-file-signature me-2"></i>Sign and submit</button>'
        . '<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button></div></div></div></div>';
}

/** The "before you can submit" list for the signing window ('' when there is nothing). */
function mitTsCaProblemsHtml(array $problems) {
    return $problems ? '<div class="alert alert-warning small"><strong>Before you can submit:</strong><ul class="mb-0">' . implode('', array_map(function ($p) {
        return '<li>' . escapeHtml($p) . '</li>';
    }, $problems)) . '</ul></div>' : '';
}

/** What the approver sees about the signature, in the Approve window. */
function mitTsCaApproverNote($user_id, array $period, array $sheet) {
    if (!mitTsCaOn($user_id, $period['end'])) {
        return '';
    }
    $attest = mitTsCaAttestation($sheet['sheet_id']);
    $t = mitTsTotals([$user_id], $period['start'], $period['end'])[intval($user_id)];
    $html = '<div class="border rounded p-2 small mb-3"><div class="fw-semibold mb-1"><i class="fas fa-user-clock me-1"></i>California non-exempt</div>'
        . mitTsHours($t['worked'], false) . ' h worked · ' . mitTsHours($t['overtime'], false) . ' h overtime · ' . mitTsHours($t['double'], false) . ' h double time'
        . ($t['meal_premium'] ? ' · <span class="text-danger">' . intdiv($t['meal_premium'], 60) . ' meal premium hour(s)</span>' : '')
        . ($t['rest_premium'] ? ' · <span class="text-danger">' . intdiv($t['rest_premium'], 60) . ' rest premium hour(s)</span>' : '') . '<br>';
    if ($attest) {
        $html .= '<i class="fas fa-file-signature text-success me-1"></i>Signed by ' . escapeHtml($attest['signature_name']) . ' on ' . date('M j, g:i A', strtotime($attest['attested_at']));
        if (!intval($attest['breaks_ok'])) {
            $html .= '<div class="alert alert-warning py-1 px-2 mt-1 mb-0"><strong>They reported:</strong> ' . nl2br(escapeHtml((string) $attest['exceptions'])) . '</div>';
        }
    } else {
        $html .= '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Not signed by them yet - it cannot be approved until they submit it.</span>';
    }
    $waiting = mitTsCaUnacknowledged($user_id, $period);
    if ($waiting) {
        $html .= '<div class="text-warning-emphasis mt-1"><i class="fas fa-user-edit me-1"></i>' . count($waiting) . ' change(s) to their times they have not acknowledged yet.</div>';
    }
    return $html . '</div>';
}

/**
 * "I could not take a rest break this day" on a day not under California rules (26.10.151): a record for the person's own day in
 * mit_ts_days (the same row California days use), shown on the timesheet and in the payroll detail - no premium. Only the person
 * answers it, as on California days.
 */
function mitTsRestMissedSave($user_id, $date, $missed) {
    global $mysqli, $session_user_id;
    $user_id = intval($user_id);
    if (!mitTsCaReady() || $user_id !== intval($session_user_id) || !calendarIsDate($date) || $date > mitTsToday($user_id)) {
        return;
    }
    $rest = $missed ? 1 : 0;
    $had = mitTsCaAnswers([$user_id], $date, $date)[$user_id][$date]['rest_missed'] ?? 0;
    if (intval($had) === $rest) {
        return;
    }
    $set = "rest_missed = $rest, updated_by = " . intval($session_user_id);
    mysqli_query($mysqli, "INSERT INTO mit_ts_days SET user_id = $user_id, work_date = '" . escapeSql($date) . "', $set ON DUPLICATE KEY UPDATE $set");
    mitTsForget();
}
